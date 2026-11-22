<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\MockTransport;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\InvoiceReplacementResult;
use PaymosWhmcs\CallbackProcessor;
use PaymosWhmcs\GatewayLink;
use PaymosWhmcs\InMemoryEventStore;
use PaymosWhmcs\InMemoryInvoiceStore;
use PaymosWhmcs\Translation;

// invoice-late-payment: a paid_late event on an invoice whose row already holds a FINAL status
// (expired) leaves the WHMCS invoice untouched — one gateway log entry plus a per-invoice marker,
// never an addInvoicePayment. A sequential duplicate (distinct event id) stays silent.

function whmcs_late_store()
{
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
        'status' => 'expired',
        'renew_count' => 0,
    ));

    return $store;
}

function test_whmcs_paid_late_notes_once_and_never_pays()
{
    $store = whmcs_late_store();
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42] = array('invoiceid' => '42', 'total' => '100.00', 'balance' => '100.00', 'status' => 'Unpaid');
    $eventStore = new InMemoryEventStore();

    $body = json_encode(whmcs_invoice_event('evt_late_1', 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
    $processor = new CallbackProcessor($adapter, $store, $eventStore);

    $result = $processor->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(0, count($adapter->payments), 'paid_late must never add a WHMCS payment.');
    $noted = 0;
    foreach ($adapter->logs as $log) {
        if (strpos($log['status'], 'Paymos: the customer finished paying for this order after the invoice closed.') === 0) {
            $noted++;
        }
    }
    assertSameValue(1, $noted, 'paid_late must log exactly one late-fact entry.');
    assertSameValue(1, count($store->findByWhmcsInvoiceId(42)) ? 1 : 1, 'store keeps the row.');
    assertSameValue('evt_late_1', $store->findByWhmcsInvoiceId(42)['paid_late_noted'], 'marker records the event id.');
    assertSameValue('expired', $store->findByWhmcsInvoiceId(42)['status'], 'row status stays final.');
    assertTrueValue($result->statusCode() === 200 || $result->statusCode() === 204, 'the note outcome acknowledges.');
}

function test_whmcs_paid_late_sequential_duplicate_is_silent()
{
    $store = whmcs_late_store();
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42] = array('invoiceid' => '42', 'total' => '100.00', 'balance' => '100.00', 'status' => 'Unpaid');
    $eventStore = new InMemoryEventStore();
    $processor = new CallbackProcessor($adapter, $store, $eventStore);

    foreach (array('evt_late_1', 'evt_late_2') as $eventId) {
        $body = json_encode(whmcs_invoice_event($eventId, 'invoice.paid_late', 'expired', array(
            'data' => array('paid_late_at' => 1777978800),
        )));
        $processor->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());
    }

    assertSameValue(0, count($adapter->payments), 'duplicates never pay.');
    $noted = 0;
    foreach ($adapter->logs as $log) {
        if (strpos($log['status'], 'Paymos: the customer finished paying for this order after the invoice closed.') === 0) {
            $noted++;
        }
    }
    assertSameValue(1, $noted, 'a sequential duplicate must not note twice.');
}

function test_whmcs_paid_late_callback_prevents_gateway_link_from_issuing_second_invoice()
{
    $store = whmcs_late_store();
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42] = array('invoiceid' => '42', 'total' => '100.00', 'balance' => '100.00', 'status' => 'Unpaid');
    $eventStore = new InMemoryEventStore();
    $params = whmcs_gateway_params();

    $body = json_encode(whmcs_invoice_event('evt_late_end_to_end', 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
    $processor = new CallbackProcessor($adapter, $store, $eventStore);
    $callbackResult = $processor->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), $params);

    assertSameValue(200, $callbackResult->statusCode(), 'paid_late webhook must be acknowledged.');
    assertSameValue('evt_late_end_to_end', $store->findByWhmcsInvoiceId(42)['paid_late_noted'], 'callback must persist paid_late_noted marker.');

    $transport = new MockTransport(array());
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $blocked = null;
    try {
        (new GatewayLink($store, static function () use ($client) {
            return $client;
        }, $adapter))->render($params);
    } catch (InvoiceReplacementBlockedException $e) {
        $blocked = $e;
    }

    assertTrueValue($blocked instanceof InvoiceReplacementBlockedException, 'GatewayLink::render() must throw InvoiceReplacementBlockedException after paid_late webhook.');
    assertSameValue(InvoiceReplacementResult::REASON_PAID, $blocked->result()->reason(), 'blocked reason must be REASON_PAID.');
    assertSameValue(0, count($transport->requests()), 'GatewayLink::render() must not issue any Paymos API request after paid_late.');
    assertSameValue(null, $store->findByExternalOrderId('whmcs_42_1'), 'GatewayLink::render() must not create a second invoice.');
    assertSameValue('inv_123', $store->findByWhmcsInvoiceId(42)['paymos_invoice_id'], 'original Paymos invoice must remain bound.');
    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars(Translation::text('replacement_blocked', $params), ENT_QUOTES, 'UTF-8') . '</div>',
        GatewayLink::failureNotice($blocked, $params),
        'failureNotice() must return replacement_blocked alert.'
    );
}
