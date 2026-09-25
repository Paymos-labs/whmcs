<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosWhmcs\InMemoryInvoiceStore;
use PaymosWhmcs\Reconciler;

function test_whmcs_reconciler_applies_missed_paid_invoice()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            // Server trims trailing zeros: snapshot stored "100.00", API returns "100".
            // The reconciler must treat these as equal (decimal-safe), not skip the
            // invoice on a raw string mismatch.
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(whmcs_gateway_params(), 1709000000);

    assertSameValue(1, $count, 'reconciler must apply one missed paid invoice.');
    assertSameValue(1, count($adapter->payments), 'reconciler must add WHMCS payment.');
    assertSameValue('paymos_inv_123_reconcile_inv_123_paid', $adapter->payments[0]['transaction_id'], 'reconcile transaction id must be deterministic.');
    assertSameValue('paid', $store->findByWhmcsInvoiceId(42)['status'], 'reconciler must update stored status.');
}

function test_whmcs_reconciler_skips_snapshot_mismatch()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '80.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(whmcs_gateway_params(), 1709000000);

    assertSameValue(0, $count, 'reconciler must skip invoice when API snapshot mismatches local snapshot.');
    assertSameValue(0, count($adapter->payments), 'snapshot mismatch must not add WHMCS payment.');
}

function test_whmcs_reconciler_never_calls_the_dying_callback_helpers()
{
    // BUG-104: WHMCS invoice #42 was deleted after its Paymos invoice was
    // created. The reconciler runs inside AfterCronJob, where checkCbInvoiceID()
    // would die() and take the whole cron (and every later hook) with it. It
    // must look the invoice up without the callback helpers, close the orphaned
    // row, and go on to the next row.
    $store = new InMemoryInvoiceStore();
    foreach (array(42 => 'inv_deleted', 43 => 'inv_live') as $whmcsId => $paymosId) {
        $store->save(array(
            'whmcs_invoice_id' => $whmcsId,
            'paymos_invoice_id' => $paymosId,
            'external_order_id' => 'whmcs_' . $whmcsId . '_0',
            'environment' => 'sandbox',
            'project_id' => 'prj_123',
            'amount' => '100.00',
            'currency' => 'USD',
            'payment_url' => 'https://checkout.paymos.test/' . $paymosId,
            'status' => 'awaiting_client',
            'renew_count' => 0,
        ));
    }
    $adapter = new FakeWhmcsAdapter();
    unset($adapter->invoices[42]);
    $adapter->invoices[43] = array('invoiceid' => '43', 'total' => '100.00', 'balance' => '100.00', 'status' => 'Unpaid');
    $adapter->callbackHelpersDie = true;

    $apiInvoice = static function ($invoiceId, $whmcsId) {
        return new HttpResponse(200, json_encode(array(
            'invoice_id' => $invoiceId,
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_' . $whmcsId . '_0', 'amount' => '100', 'currency' => 'USD'),
        )), array());
    };
    $transport = new MockTransport(array($apiInvoice('inv_deleted', 42), $apiInvoice('inv_live', 43)));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(whmcs_gateway_params(), 1709000000);

    assertSameValue(1, $count, 'the live invoice after the orphan must still be reconciled.');
    assertSameValue(1, count($adapter->payments), 'only the live invoice may receive a payment.');
    assertSameValue(43, $adapter->payments[0]['invoice_id'], 'the payment must land on the live invoice.');
    assertSameValue(
        PaymosWhmcs\InvoiceStore::STATUS_WHMCS_INVOICE_MISSING,
        $store->findByWhmcsInvoiceId(42)['status'],
        'the orphaned row must be closed so the next cron run skips it.'
    );
    assertSameValue(0, count($store->findUnpaidRecent(50, 0)), 'closed and paid rows must drop out of the reconcile window.');
}

function test_whmcs_reconciler_skips_a_payment_whmcs_already_booked()
{
    // The transaction id is already in tblaccounts (a webhook booked it). The
    // reconciler must not add it twice — and must learn that without
    // checkCbTransID(), which dies on a known id.
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $adapter->callbackHelpersDie = true;
    $adapter->transactions['paymos_inv_123_reconcile_inv_123_paid'] = true;
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
        )), array()),
    )));

    $count = (new Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(whmcs_gateway_params(), 1709000000);

    assertSameValue(0, $count, 'an already-booked transaction is not a new reconcile.');
    assertSameValue(0, count($adapter->payments), 'an already-booked transaction must not be added again.');
}

/**
 * BUG-159: run the reconciler against the REAL WhmcsAdapter, with localAPI
 * answering GetInvoice the way $getInvoice says.
 */
function whmcs_reconcile_with_local_api(callable $getInvoice)
{
    $GLOBALS['paymos_whmcs_local_api'] = static function ($command, array $values) use ($getInvoice) {
        return $command === 'GetInvoice'
            ? $getInvoice((int) $values['invoiceid'])
            : array('result' => 'error', 'message' => 'unexpected ' . $command);
    };
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
        )), array()),
    )));

    (new Reconciler($store, new PaymosWhmcs\WhmcsAdapter(), static function () use ($client) {
        return $client;
    }))->run(whmcs_gateway_params(), 1709000000);

    return $store;
}

function test_whmcs_reconciler_keeps_the_row_when_get_invoice_fails()
{
    // BUG-159: a GetInvoice failure that is not "Invoice ID Not Found" says
    // nothing about the invoice. The row must stay for the next cron run, not be
    // closed for good as a deleted invoice.
    $store = whmcs_reconcile_with_local_api(static function ($invoiceId) {
        return array('result' => 'error', 'message' => 'SQLSTATE[HY000] [2002] Connection refused');
    });

    assertSameValue('awaiting_client', $store->findByWhmcsInvoiceId(42)['status'], 'a transient GetInvoice error must not close the row.');
    assertSameValue(1, count($store->findUnpaidRecent(50, 0)), 'the row must stay in the reconcile window.');
}

function test_whmcs_reconciler_closes_the_row_only_on_invoice_id_not_found()
{
    // The one documented GetInvoice error: the invoice is gone.
    $store = whmcs_reconcile_with_local_api(static function ($invoiceId) {
        return array('result' => 'error', 'message' => 'Invoice ID Not Found');
    });

    assertSameValue(
        PaymosWhmcs\InvoiceStore::STATUS_WHMCS_INVOICE_MISSING,
        $store->findByWhmcsInvoiceId(42)['status'],
        'a deleted WHMCS invoice must still close the row.'
    );
}

function test_whmcs_adapter_get_invoice_tells_not_found_from_a_failure()
{
    $responses = array(
        42 => array('result' => 'success', 'invoiceid' => 42, 'userid' => 77, 'total' => '100.00', 'status' => 'Unpaid'),
        43 => array('result' => 'error', 'message' => 'Invoice ID Not Found'),
        44 => array('result' => 'error', 'message' => 'Unable to connect to database'),
        45 => array('result' => 'error'),
    );
    $GLOBALS['paymos_whmcs_local_api'] = static function ($command, array $values) use ($responses) {
        return $responses[(int) $values['invoiceid']];
    };
    $adapter = new PaymosWhmcs\WhmcsAdapter();

    assertSameValue('100.00', $adapter->getInvoice(42)['total'], 'a found invoice is returned as is.');
    assertSameValue(array(), $adapter->getInvoice(43), '"Invoice ID Not Found" means no such invoice.');
    foreach (array(44, 45) as $invoiceId) {
        $threw = false;
        try {
            $adapter->getInvoice($invoiceId);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assertTrueValue($threw, 'any other GetInvoice error must throw, never read as a deleted invoice (#' . $invoiceId . ').');
    }
}
