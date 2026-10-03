<?php
declare(strict_types=1);

function test_whmcs_callback_and_cron_share_invoice_payment_lock()
{
    $store = whmcs_store_with_invoice();
    $adapter = new FakeWhmcsAdapter();
    $processor = new PaymosWhmcs\CallbackProcessor($adapter, $store, new PaymosWhmcs\InMemoryEventStore());
    $invoice = whmcs_invoice_event('evt_callback', 'invoice.paid', 'paid')['data'];
    $row = $store->findByExternalOrderId('whmcs_42_0');
    $blocked = false;
    $adapter->beforePayment = function () use ($processor, $invoice, $row, &$blocked) {
        try {
            $processor->applyTrustedInvoice($invoice, $row, whmcs_gateway_params(), time());
        } catch (RuntimeException $error) {
            $blocked = true;
        }
    };
    $processor->applyTrustedInvoice($invoice, $row, whmcs_gateway_params(), time());
    assertSameValue(true, $blocked, 'An overlapping payment cannot pass the invoice lock.');
    $processor->applyTrustedInvoice($invoice, $row, whmcs_gateway_params(), time());
    assertSameValue(1, count($adapter->payments), 'Retry rechecks paid state and cannot credit twice.');
}

function test_whmcs_paid_recovers_before_and_after_cms_commit()
{
    foreach (array('failBeforePayment', 'failAfterPayment') as $fault) {
        $store = whmcs_store_with_invoice();
        $adapter = new FakeWhmcsAdapter();
        $adapter->$fault = true;
        $processor = new PaymosWhmcs\CallbackProcessor($adapter, $store,
            new PaymosWhmcs\InMemoryEventStore(), static function () {
                return new Paymos\Client(new Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'),
                    new Paymos\Http\MockTransport(array(new Paymos\Http\HttpResponse(200, json_encode(array(
                        'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'status' => 'paid',
                        'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
                    )), array()))));
            });
        $body = json_encode(whmcs_invoice_event('evt_crash_' . $fault, 'invoice.paid', 'paid'));
        $sig = whmcs_signed_header('whsec_sandbox', $body, time());
        $first = $processor->handle($body, $sig, whmcs_gateway_params());
        assertSameValue(false, $first->statusCode() === 200, 'Fault must remain retriable.');
        assertSameValue('created', $store->findByExternalOrderId('whmcs_42_0')['status'], 'No premature paid snapshot.');
        $second = $processor->handle($body, $sig, whmcs_gateway_params());
        assertSameValue(200, $second->statusCode(), 'Retry recovers.');
        assertSameValue('paid', $store->findByExternalOrderId('whmcs_42_0')['status'], 'Retry finalizes snapshot.');
        assertSameValue(1, count($adapter->payments), 'CMS payment happens once.');
    }
}
