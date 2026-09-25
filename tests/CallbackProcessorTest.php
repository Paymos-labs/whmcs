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
                'currency' => 'USDT',
                'exchange_rate' => '1',
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
    assertSameValue('1.25', $adapter->payments[0]['fee'], 'payment fee must come from data.payment.fee, converted at the applied rate.');
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

function test_whmcs_callback_converts_token_fee_into_invoice_currency()
{
    // BUG-136: data.payment.fee is denominated in the paid token (USDT), while
    // addInvoicePayment books the fee in the WHMCS invoice currency. A 9000 RUB
    // invoice paid with 100 USDT at 90 RUB/USDT and a 1 USDT fee must book ~90 RUB,
    // never "1.00" RUB.
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '9000.00',
        'currency' => 'RUB',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42] = array('invoiceid' => '42', 'total' => '9000.00', 'balance' => '9000.00', 'status' => 'Unpaid');
    $adapter->invoiceCurrencies[42] = 'RUB';

    $payment = array('currency' => 'USDT', 'network' => 'TRC20', 'expected' => '100', 'exchange_rate' => '90', 'fee' => '1');
    $apiInvoice = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array('external_id' => 'whmcs_42_0', 'amount' => '9000.00', 'currency' => 'RUB'),
        'payment' => $payment,
    );
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode($apiInvoice), array()),
    )));
    $body = json_encode(whmcs_invoice_event('evt_paid_rub', 'invoice.paid', 'paid', array(
        'data' => array(
            'order' => array('amount' => '9000.00', 'currency' => 'RUB'),
            'payment' => $payment,
        ),
    )));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params(array('currency' => 'RUB')));

    assertSameValue(200, $result->statusCode(), 'paid RUB webhook must be accepted.');
    assertSameValue(1, count($adapter->payments), 'paid RUB webhook must add one payment.');
    assertSameValue('90.00', $adapter->payments[0]['fee'], 'a token-denominated fee must be converted into the invoice currency.');
}

function test_whmcs_callback_books_zero_fee_when_it_cannot_be_converted()
{
    // No exchange rate and a token that differs from the invoice currency: the
    // fee cannot be expressed in the invoice currency, so book 0.00 rather than
    // a token amount labelled as fiat.
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $apiInvoice = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
    );
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode($apiInvoice), array()),
    )));
    $body = json_encode(whmcs_invoice_event('evt_paid_norate', 'invoice.paid', 'paid', array(
        'data' => array('payment' => array('currency' => 'USDC', 'fee' => '1.25')),
    )));

    (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue('0.00', $adapter->payments[0]['fee'], 'an unconvertible token fee must be booked as 0.00.');
}

function test_whmcs_callback_books_a_paid_balance_after_a_partial_payment()
{
    // BUG-132: total 100.00, 40.00 paid earlier, Paymos invoice cut for the
    // 60.00 balance and paid in full. The invoice total did not change, so this
    // is a clean payment of 60.00 — not "Manual review".
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '60.00',
        'invoice_total' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['balance'] = '60.00';
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '60', 'currency' => 'USD'),
        )), array()),
    )));
    $body = json_encode(whmcs_invoice_event('evt_paid_balance', 'invoice.paid', 'paid', array(
        'data' => array('order' => array('amount' => '60')),
    )));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(200, $result->statusCode(), 'the paid balance must be accepted.');
    assertSameValue(1, count($adapter->payments), 'the paid balance must be booked, not sent to manual review.');
    assertSameValue('60.00', $adapter->payments[0]['amount'], 'WHMCS must be credited with what the Paymos invoice charged.');
}

function test_whmcs_callback_holds_payment_when_the_invoice_total_changed_since_the_snapshot()
{
    // The WHMCS invoice grew from 100.00 to 120.00 after the balance invoice was
    // cut: the payment no longer settles what it was meant to. Manual review.
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '60.00',
        'invoice_total' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['total'] = '120.00';
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '60', 'currency' => 'USD'),
        )), array()),
    )));
    $body = json_encode(whmcs_invoice_event('evt_paid_grown', 'invoice.paid', 'paid', array(
        'data' => array('order' => array('amount' => '60')),
    )));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(200, $result->statusCode(), 'a changed invoice is acknowledged, not retried.');
    assertSameValue(0, count($adapter->payments), 'a changed invoice must not be credited automatically.');
    assertContainsValue('Manual review', $adapter->logs[count($adapter->logs) - 1]['status'], 'a changed invoice must be flagged for manual review.');
}

function test_whmcs_callback_ignores_a_stale_event_after_a_final_status()
{
    // BUG-135: the Paymos invoice already ended underpaid. A delayed
    // underpaid_waiting must not reopen the row (which would put it back into
    // the reconciler's window) or log a status the invoice left long ago.
    $store = whmcs_store_with_invoice();
    $store->updateStatus('inv_123', 'underpaid');
    $adapter = new FakeWhmcsAdapter();
    $body = json_encode(whmcs_invoice_event('evt_stale', 'invoice.underpaid_waiting', 'underpaid_waiting'));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore()))
        ->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(200, $result->statusCode(), 'a stale event is acknowledged, not retried.');
    assertSameValue('underpaid', $store->findByWhmcsInvoiceId(42)['status'], 'the final status must stay recorded.');
    assertSameValue('Ignored (stale status after a final one)', $adapter->logs[count($adapter->logs) - 1]['status'], 'the stale event must be logged as ignored.');
}

function test_whmcs_callback_answers_409_while_the_event_is_still_being_processed()
{
    // BUG-103: another delivery of this event holds the lock and has not
    // finished. A 200 "duplicate" would mark it delivered — lost if that
    // delivery then fails. Answer 409 and leave the lock alone.
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $events = new InMemoryEventStore();
    assertTrueValue($events->remember('evt_inflight', 604800), 'the first delivery holds the lock.');

    $body = json_encode(whmcs_invoice_event('evt_inflight', 'invoice.paid', 'paid'));
    $result = (new CallbackProcessor($adapter, $store, $events))
        ->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params());

    assertSameValue(409, $result->statusCode(), 'an event still in flight must be answered non-2xx so the server retries.');
    assertFalseValue($events->remember('evt_inflight', 604800), 'the retry must not release the lock the first delivery still holds.');
    assertSameValue(0, count($adapter->payments), 'nothing may be applied while the event is in flight.');
}

function test_whmcs_event_store_without_capsule_tells_a_locked_event_from_a_committed_one()
{
    $store = new PaymosWhmcs\EventStore();
    assertTrueValue($store->remember('evt_mem', 604800), 'first delivery takes the lock.');
    assertFalseValue($store->isCommitted('evt_mem'), 'locked only: not committed.');
    $store->commit();
    assertTrueValue($store->isCommitted('evt_mem'), 'committed.');
}

/**
 * BUG-158 fixture: a 100.00 EUR WHMCS invoice whose gateway has "Convert To For
 * Processing" set to USD. WHMCS hands _link the converted figure, so the Paymos
 * invoice was cut for 108.00 USD, while GetInvoice still reports the EUR total
 * (and, like the real API, no currency at all).
 *
 * @return array{0: InMemoryInvoiceStore, 1: FakeWhmcsAdapter, 2: array<string, mixed>}
 */
function whmcs_converted_currency_fixture()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '108.00',
        'invoice_total' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ));
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoiceCurrencies[42] = 'EUR';
    $apiInvoice = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array('external_id' => 'whmcs_42_0', 'amount' => '108', 'currency' => 'USD'),
        'payment' => array('currency' => 'USDT', 'network' => 'TRC20', 'exchange_rate' => '1', 'fee' => '1'),
    );

    return array($store, $adapter, $apiInvoice);
}

function test_whmcs_callback_holds_a_payment_processed_in_another_currency()
{
    // BUG-158: 108.00 USD must never be credited to a EUR invoice as 108.00 EUR,
    // nor its fee converted into USD and booked as EUR. Hold for manual review.
    list($store, $adapter, $apiInvoice) = whmcs_converted_currency_fixture();
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode($apiInvoice), array()),
    )));
    $body = json_encode(whmcs_invoice_event('evt_paid_converted', 'invoice.paid', 'paid', array(
        'data' => array('order' => $apiInvoice['order'], 'payment' => $apiInvoice['payment']),
    )));

    $result = (new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use ($client) {
        return $client;
    }))->handle($body, whmcs_signed_header('whsec_sandbox', $body, time()), whmcs_gateway_params(array('amount' => '108.00')));

    assertSameValue(200, $result->statusCode(), 'a currency mismatch is acknowledged, not retried.');
    assertSameValue(0, count($adapter->payments), 'a USD payment must not be credited to a EUR invoice.');
    $last = $adapter->logs[count($adapter->logs) - 1]['status'];
    assertContainsValue('Manual review', $last, 'a currency mismatch must be flagged for manual review.');
    assertContainsValue('EUR', $last, 'the review note must name the WHMCS invoice currency.');
}

function test_whmcs_reconciler_holds_a_payment_processed_in_another_currency()
{
    // BUG-158, cron path: the reconciler books through the same code and must
    // stop at the same place.
    list($store, $adapter, $apiInvoice) = whmcs_converted_currency_fixture();
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        new HttpResponse(200, json_encode($apiInvoice), array()),
    )));

    $count = (new PaymosWhmcs\Reconciler($store, $adapter, static function () use ($client) {
        return $client;
    }))->run(whmcs_gateway_params(), 1709000000);

    assertSameValue(0, $count, 'a currency mismatch is not a reconciled payment.');
    assertSameValue(0, count($adapter->payments), 'the reconciler must not credit a USD payment to a EUR invoice.');
    assertContainsValue('Manual review', $adapter->logs[count($adapter->logs) - 1]['status'], 'the reconciler must flag it for manual review.');
}

function test_whmcs_callback_retries_when_the_invoice_currency_is_unknown()
{
    // Fail closed without losing the payment: when WHMCS cannot say what
    // currency the invoice is in, nothing is booked and the row is left open,
    // so the redelivery (or the next cron run) books it once WHMCS answers.
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    unset($adapter->invoiceCurrencies[42]);
    $apiInvoice = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
    );
    $calls = 0;
    $processor = new CallbackProcessor($adapter, $store, new InMemoryEventStore(), static function () use (&$calls, $apiInvoice) {
        $calls++;
        return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
            new HttpResponse(200, json_encode($apiInvoice), array()),
        )));
    });
    $body = json_encode(whmcs_invoice_event('evt_paid_unknown_currency', 'invoice.paid', 'paid'));
    $signature = whmcs_signed_header('whsec_sandbox', $body, time());

    $first = $processor->handle($body, $signature, whmcs_gateway_params());

    assertSameValue(400, $first->statusCode(), 'an unknown invoice currency must be retried, not acknowledged.');
    assertSameValue(0, count($adapter->payments), 'nothing may be booked while the invoice currency is unknown.');
    assertSameValue('created', $store->findByWhmcsInvoiceId(42)['status'], 'the row must stay open for the retry.');

    $adapter->invoiceCurrencies[42] = 'USD';
    $second = $processor->handle($body, $signature, whmcs_gateway_params());

    assertSameValue(200, $second->statusCode(), 'the retry must go through once WHMCS answers.');
    assertSameValue(1, count($adapter->payments), 'the retry must book the payment exactly once.');
}

function test_whmcs_adapter_reads_the_invoice_currency_from_the_client()
{
    // GetInvoice has no currency field; the invoice is in its client's currency,
    // which GetClientsDetails reports as client.currency_code.
    $calls = array();
    $GLOBALS['paymos_whmcs_local_api'] = static function ($command, array $values) use (&$calls) {
        $calls[] = $command;
        if ($command === 'GetInvoice') {
            return array('result' => 'success', 'invoiceid' => 42, 'userid' => 77, 'total' => '100.00', 'status' => 'Unpaid');
        }
        if ($command === 'GetClientsDetails' && (int) $values['clientid'] === 77) {
            return array('result' => 'success', 'client' => array('id' => 77, 'currency' => 2, 'currency_code' => 'eur'));
        }

        return array('result' => 'error', 'message' => 'unexpected ' . $command);
    };

    assertSameValue('EUR', (new PaymosWhmcs\WhmcsAdapter())->invoiceCurrency(42), 'the invoice currency is the client currency code, upper-cased.');
    assertSameValue(array('GetInvoice', 'GetClientsDetails'), $calls, 'the client is found through the invoice.');
}

function test_whmcs_adapter_refuses_to_guess_the_invoice_currency()
{
    $GLOBALS['paymos_whmcs_local_api'] = static function ($command, array $values) {
        if ($command === 'GetInvoice') {
            return array('result' => 'success', 'invoiceid' => 42, 'userid' => 77, 'total' => '100.00', 'status' => 'Unpaid');
        }

        return array('result' => 'error', 'message' => 'Client Not Found');
    };

    $threw = false;
    try {
        (new PaymosWhmcs\WhmcsAdapter())->invoiceCurrency(42);
    } catch (RuntimeException $e) {
        $threw = true;
        assertContainsValue('Client Not Found', $e->getMessage(), 'the WHMCS error must reach the log.');
    }
    assertTrueValue($threw, 'an unknown client currency must throw, never fall back to the Paymos order currency.');
}
