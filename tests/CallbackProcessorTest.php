<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosWhmcs\CallbackProcessor;
use PaymosWhmcs\InMemoryEventStore;
use PaymosWhmcs\InMemoryInvoiceStore;

function test_whmcs_callback_rejects_paid_webhook_when_reverse_api_status_differs()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $eventStore = new InMemoryEventStore();

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(whmcs_invoice_event('evt_reverse_bad', 'invoice.paid', 'paid'));

    $result = (new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(400, $result->statusCode(), 'reverse status mismatch must reject paid webhook.');
    assertSameValue(0, count($adapter->payments), 'reverse mismatch must not add WHMCS payment.');
}

function test_whmcs_callback_reports_config_errors_without_fatal()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $eventStore = new InMemoryEventStore();
    $body = json_encode(whmcs_invoice_event('evt_bad_config', 'invoice.paid', 'paid'));

    $result = (new CallbackProcessor($adapter, $store, $eventStore))->handle(
        $body,
        whmcs_signed_header('whsec_sandbox', $body, time()),
        whmcs_gateway_params(array('sandboxProjectId' => '', 'projectId' => ''))
    );

    assertSameValue(500, $result->statusCode(), 'callback config errors must return controlled 500 response.');
    assertSameValue(1, count($adapter->logs), 'callback config errors must be logged for the admin.');
}

function test_whmcs_callback_applies_paid_webhook_once()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $eventStore = new InMemoryEventStore();

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(whmcs_invoice_event('evt_paid_once', 'invoice.paid', 'paid'));
    $signature = whmcs_signed_header('whsec_sandbox', $body, time());

    $processor = new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    });

    $first = $processor->handle($body, $signature, whmcs_gateway_params());
    $second = $processor->handle($body, $signature, whmcs_gateway_params());

    assertSameValue(200, $first->statusCode(), 'paid webhook must be accepted.');
    assertSameValue(200, $second->statusCode(), 'duplicate webhook must return OK for gateway retry safety.');
    assertSameValue(true, $second->isDuplicate(), 'duplicate webhook must be reported as duplicate.');
    assertSameValue(1, count($adapter->payments), 'paid webhook must add exactly one WHMCS payment.');
    assertSameValue('paymos_inv_123_evt_paid_once', $adapter->payments[0]['transaction_id'], 'transaction id must combine invoice and event id.');
    assertSameValue('100.00', $adapter->payments[0]['amount'], 'paid webhook must credit the original invoice total.');
}

function test_whmcs_callback_does_not_double_pay_on_second_complete_event()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $eventStore = new InMemoryEventStore();

    // Two DISTINCT complete-class events (paid, then paid_over) — different event
    // ids, so EventStore + checkCbTransID both let them through. Once WHMCS marks
    // the invoice Paid, the second must NOT book another payment (overpayment credit).
    $paidResponse = static function ($status) {
        return new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array());
    };
    $transport = new MockTransport(array($paidResponse('paid'), $paidResponse('paid_over')));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $processor = new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    });

    $body1 = json_encode(whmcs_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $first = $processor->handle($body1, whmcs_signed_header('whsec_sandbox', $body1, time()), whmcs_gateway_params());

    // WHMCS auto-marks the invoice Paid once its balance hits zero.
    $adapter->invoices[42]['status'] = 'Paid';

    $body2 = json_encode(whmcs_invoice_event('evt_paid_over', 'invoice.paid_over', 'paid_over'));
    $second = $processor->handle($body2, whmcs_signed_header('whsec_sandbox', $body2, time()), whmcs_gateway_params());

    assertSameValue(200, $first->statusCode(), 'first complete event must be accepted.');
    assertSameValue(200, $second->statusCode(), 'second complete event must acknowledge (200), not error.');
    assertSameValue(1, count($adapter->payments), 'an already-paid invoice must never receive a second payment.');
}

function test_whmcs_callback_does_not_downgrade_paid_invoice_on_late_cancel()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    // The WHMCS invoice is already Paid.
    $adapter->invoices[42]['status'] = 'Paid';
    $eventStore = new InMemoryEventStore();

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'cancelled',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(whmcs_invoice_event('evt_late_cancel', 'invoice.cancelled', 'cancelled'));

    $result = (new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(200, $result->statusCode(), 'late cancel on a paid invoice must acknowledge (200).');
    assertSameValue(0, count($adapter->payments), 'late cancel must not touch payments.');
    // The logged status must be the "ignored" note, never a contradicting "cancelled".
    assertSameValue('Ignored (invoice already paid)', $adapter->logs[count($adapter->logs) - 1]['status'], 'a late downgrade on a paid invoice must not log a contradicting status.');
}

function test_whmcs_callback_uses_total_currencycode_and_webhook_fee()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42] = array(
        'invoiceid' => '42',
        'total' => '100.00',
        'balance' => '40.00',
        'currencycode' => 'USD',
        'status' => 'Unpaid',
    );
    $eventStore = new InMemoryEventStore();

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $body = json_encode(whmcs_invoice_event('evt_paid_fee', 'invoice.paid', 'paid', array(
        'data' => array(
            'payment' => array(
                'fee' => '1.25',
            ),
        ),
    )));
    $signature = whmcs_signed_header('whsec_sandbox', $body, time());

    $result = (new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    }))->handle($body, $signature, whmcs_gateway_params());

    assertSameValue(200, $result->statusCode(), 'paid webhook with partial WHMCS balance must be accepted.');
    assertSameValue(1, count($adapter->payments), 'paid webhook must add one WHMCS payment.');
    assertSameValue('100.00', $adapter->payments[0]['amount'], 'payment amount must use invoice total, not the reduced balance.');
    assertSameValue('1.25', $adapter->payments[0]['fee'], 'payment fee must come from data.payment.fee.');
}

function test_whmcs_callback_does_not_commit_event_id_when_processing_fails()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $eventStore = new InMemoryEventStore();

    $badTransport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $goodTransport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));

    $body = json_encode(whmcs_invoice_event('evt_retryable', 'invoice.paid', 'paid'));
    $signature = whmcs_signed_header('whsec_sandbox', $body, time());
    $calls = 0;
    $processor = new CallbackProcessor($adapter, $store, $eventStore, static function () use (&$calls, $badTransport, $goodTransport) {
        $calls++;
        $transport = $calls === 1 ? $badTransport : $goodTransport;
        return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    });

    $first = $processor->handle($body, $signature, whmcs_gateway_params());
    $second = $processor->handle($body, $signature, whmcs_gateway_params());

    assertSameValue(400, $first->statusCode(), 'first attempt must fail reverse verification.');
    assertSameValue(200, $second->statusCode(), 'second attempt with same event id must be retryable.');
    assertSameValue(1, count($adapter->payments), 'retry must be able to add payment once.');
}

function test_whmcs_callback_logs_confirming_without_payment()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $eventStore = new InMemoryEventStore();
    $body = json_encode(whmcs_invoice_event('evt_confirming', 'invoice.confirming', 'confirming'));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array()));

    $result = (new CallbackProcessor($adapter, $store, $eventStore, static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(200, $result->statusCode(), 'confirming webhook must be accepted.');
    assertSameValue(0, count($adapter->payments), 'confirming webhook must not add payment.');
    assertSameValue('confirming', $store->findByWhmcsInvoiceId(42)['status'], 'confirming webhook must update stored status.');
}

function whmcs_store_with_invoice()
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
        'status' => 'created',
        'renew_count' => 0,
    ));

    return $store;
}
