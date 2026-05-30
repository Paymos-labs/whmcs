<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosWhmcs\GatewayLink;
use PaymosWhmcs\InMemoryInvoiceStore;

function test_whmcs_gateway_link_creates_paymos_invoice_and_stores_snapshot()
{
    $store = new InMemoryInvoiceStore();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'created',
            'checkout_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/inv_123', $html, 'payment button must redirect to Paymos checkout URL.');
    assertContainsValue('Pay with Paymos', $html, 'payment button must use configured text.');

    $row = $store->findByWhmcsInvoiceId(42);
    assertSameValue('inv_123', $row['paymos_invoice_id'], 'created Paymos invoice id must be stored.');
    assertSameValue('whmcs_42_0', $row['external_order_id'], 'first external order id must be deterministic.');
    assertSameValue('100.00', $row['amount'], 'invoice amount snapshot must be stored.');
    assertSameValue('USD', $row['currency'], 'invoice currency snapshot must be stored.');
    assertSameValue(1, count($transport->requests()), 'new invoice should call Paymos API once.');

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('prj_123', $payload['project_id'], 'Paymos create payload must include project id.');
    assertSameValue('whmcs_42_0', $payload['external_order_id'], 'Paymos create payload must use the Merchant API external_order_id field.');
    assertSameValue('77', $payload['client_id'], 'Paymos create payload must use the native WHMCS client id when available.');
    assertSameValue(false, isset($payload['order']), 'Paymos create payload must not use the webhook/read-model order object.');
}

function test_whmcs_gateway_link_does_not_use_email_as_client_id()
{
    $store = new InMemoryInvoiceStore();
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params(array(
        'clientdetails' => array(
            'email' => 'buyer@example.com',
            'firstname' => 'Buyer',
            'lastname' => 'Example',
        ),
    )));

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue(false, isset($payload['client_id']), 'Paymos create payload must not use email as client_id.');
}

function test_whmcs_gateway_link_reuses_existing_invoice_when_snapshot_matches()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_existing',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/existing',
        'status' => 'created',
        'renew_count' => 0,
    ));

    $transport = new MockTransport(array());
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/existing', $html, 'matching existing invoice must be reused.');
    assertSameValue(0, count($transport->requests()), 'reused invoice must not call Paymos API.');
}

function test_whmcs_gateway_link_renews_invoice_when_amount_changes()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_old',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '50.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/old',
        'status' => 'created',
        'renew_count' => 0,
    ));

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_new',
            'status' => 'created',
            'payment_url' => 'https://checkout.paymos.test/new',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    $row = $store->findByWhmcsInvoiceId(42);
    assertSameValue('inv_new', $row['paymos_invoice_id'], 'amount change must create a fresh Paymos invoice.');
    assertSameValue('whmcs_42_1', $row['external_order_id'], 'renewed invoice must increment external order id.');
    assertSameValue(1, count($transport->requests()), 'renewed invoice must call Paymos API.');
}
