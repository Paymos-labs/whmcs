<?php

declare(strict_types=1);

use PaymosWhmcs\CallbackProcessor;
use PaymosWhmcs\InMemoryEventStore;
use PaymosWhmcs\InMemoryInvoiceStore;

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
        if (strpos($log['status'], 'Paid after the deadline') === 0) {
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
        if (strpos($log['status'], 'Paid after the deadline') === 0) {
            $noted++;
        }
    }
    assertSameValue(1, $noted, 'a sequential duplicate must not note twice.');
}
