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
            'payment_url' => 'https://checkout.paymos.test/inv_123',
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

function test_whmcs_gateway_link_localizes_default_button_for_russian_client()
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

    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), new MockTransport(array(
        whmcs_live_invoice_response('inv_existing', 'awaiting_client', time() + 600),
    )));
    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params(array(
        'clientdetails' => array('language' => 'ru-RU'),
    )));

    assertContainsValue('Оплатить через Paymos', $html, 'Default WHMCS button must follow the client language.');
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

    $transport = new MockTransport(array(
        whmcs_live_invoice_response('inv_existing', 'awaiting_client', time() + 600),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/existing', $html, 'matching existing invoice must be reused.');
    assertSameValue(1, count($transport->requests()), 'a reused invoice is checked against the server once.');
    assertSameValue('GET', $transport->requests()[0]['method'], 'the check is a read, never a second create.');
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

function test_whmcs_gateway_link_snapshots_the_invoice_total_next_to_the_amount_due()
{
    // BUG-132: WHMCS hands _link the amount still due. After a 40.00 partial
    // payment on a 100.00 invoice that is 60.00 — the Paymos invoice is for
    // 60.00, and the snapshot must also keep the WHMCS total it was cut from.
    $store = new InMemoryInvoiceStore();
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['balance'] = '60.00';
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/inv_123',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayLink($store, static function () use ($client) {
        return $client;
    }, $adapter))->render(whmcs_gateway_params(array('amount' => '60.00')));

    $row = $store->findByWhmcsInvoiceId(42);
    assertSameValue('60.00', $row['amount'], 'the Paymos invoice is for the amount still due.');
    assertSameValue('100.00', $row['invoice_total'], 'the snapshot must keep the WHMCS invoice total.');
}

function whmcs_live_invoice_response($invoiceId, $status, $expiresAt)
{
    return new HttpResponse(200, json_encode(array(
        'invoice_id' => $invoiceId,
        'project_id' => 'prj_123',
        'status' => $status,
        'is_final' => in_array($status, array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled'), true),
        'payment_url' => 'https://checkout.paymos.test/' . $invoiceId,
        'expires_at' => $expiresAt,
        'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
    )), array());
}

function whmcs_store_with_existing_link($status)
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
        'status' => $status,
        'renew_count' => 0,
    ));

    return $store;
}

function test_whmcs_gateway_link_renews_an_invoice_that_expired_on_the_server()
{
    // BUG-090: the client opened WHMCS invoice #42 at 10:00, the Paymos invoice
    // expired at 10:30, the webhook never arrived. Three days later the same
    // amount, currency, project and environment must not hand back the dead
    // link: the server says it is past its deadline, so a new invoice is cut.
    $store = whmcs_store_with_existing_link('awaiting_client');
    $transport = new MockTransport(array(
        whmcs_live_invoice_response('inv_existing', 'awaiting_client', time() - 3 * 86400),
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_fresh',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/fresh',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/fresh', $html, 'an expired invoice must be replaced by a fresh one.');
    $row = $store->findByWhmcsInvoiceId(42);
    assertSameValue('whmcs_42_1', $row['external_order_id'], 'the fresh invoice needs a new external order id — the old one keeps answering with the expired invoice.');
    $payload = json_decode($transport->requests()[1]['body'], true);
    assertSameValue('whmcs_42_1', $payload['external_order_id'], 'the create call must carry the bumped id.');
}

function test_whmcs_gateway_link_renews_without_a_lookup_when_the_invoice_is_already_final()
{
    // The webhook already recorded the invoice as expired: no need to ask.
    $store = whmcs_store_with_existing_link('expired');
    $transport = new MockTransport(array(
        new HttpResponse(201, json_encode(array(
            'invoice_id' => 'inv_fresh',
            'status' => 'awaiting_client',
            'payment_url' => 'https://checkout.paymos.test/fresh',
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/fresh', $html, 'a final invoice must be replaced by a fresh one.');
    assertSameValue(1, count($transport->requests()), 'a recorded final status needs no lookup, only the create.');
    assertSameValue('POST', $transport->requests()[0]['method'], 'the only call is the create.');
}

function test_whmcs_gateway_link_never_renews_a_paid_invoice()
{
    // The Paymos invoice is paid but WHMCS has not booked it yet (the webhook
    // is still on its way). A second invoice would invite a second payment:
    // keep the existing link, no lookup, no create.
    $store = whmcs_store_with_existing_link('paid');
    $transport = new MockTransport(array());
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/existing', $html, 'a paid invoice keeps its link.');
    assertSameValue(0, count($transport->requests()), 'a paid invoice needs neither a lookup nor a new invoice.');
}

function test_whmcs_gateway_link_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook, so the row still says awaiting_client. The server's answer
    // decides: a network picked, funds confirming or a part payment in keeps
    // the link — a second invoice next to it invites a second payment.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $store = whmcs_store_with_existing_link('awaiting_client');
        $transport = new MockTransport(array(
            whmcs_live_invoice_response('inv_existing', $status, time() - 3600),
        ));
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

        $html = (new GatewayLink($store, static function () use ($client) {
            return $client;
        }))->render(whmcs_gateway_params());

        assertContainsValue('https://checkout.paymos.test/existing', $html, $status . ': the open invoice keeps its link.');
        assertSameValue(1, count($transport->requests()), $status . ': one lookup and no new invoice.');
        assertSameValue('GET', $transport->requests()[0]['method'], $status . ': the only call is the lookup.');
        assertSameValue('whmcs_42_0', $store->findByWhmcsInvoiceId(42)['external_order_id'], $status . ': the external order id is not bumped.');
    }
}
