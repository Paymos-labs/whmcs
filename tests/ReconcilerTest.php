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
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD'),
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
