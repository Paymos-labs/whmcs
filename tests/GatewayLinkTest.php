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

function test_whmcs_gateway_recovered_paid_create_is_applied_by_callback_or_reconcile()
{
    foreach (array('paid', 'paid_over') as $status) {
        foreach (array('callback', 'reconcile') as $route) {
            $store = new InMemoryInvoiceStore();
            $adapter = new FakeWhmcsAdapter();
            $invoice = array('invoice_id' => 'inv_123', 'project_id' => 'prj_123',
                'payment_url' => 'https://checkout.paymos.test/inv_123', 'status' => $status, 'is_test' => true,
                'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'));
            $clientFactory = static function () use ($invoice) {
                return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'),
                    new MockTransport(array(new HttpResponse(200, json_encode($invoice), array()))));
            };
            (new GatewayLink($store, $clientFactory, $adapter))->render(whmcs_gateway_params());
            assertSameValue('created', $store->findByWhmcsInvoiceId(42)['status'], 'Recovered remote terminal status is not a native payment receipt.');
            assertSameValue(0, count($adapter->payments), 'Link rendering does not book native payment.');
            if ($route === 'callback') {
                $processor = new PaymosWhmcs\CallbackProcessor($adapter, $store, new PaymosWhmcs\InMemoryEventStore(), $clientFactory);
                $body = json_encode(whmcs_invoice_event('evt_recovered', 'invoice.' . $status, $status));
                $sig = whmcs_signed_header('whsec_sandbox', $body, time());
                assertSameValue(200, $processor->handle($body, $sig, whmcs_gateway_params())->statusCode(), 'Recovered paid callback applies.');
                assertSameValue(200, $processor->handle($body, $sig, whmcs_gateway_params())->statusCode(), 'Recovered callback duplicate is acknowledged.');
            } else {
                $reconciler = new PaymosWhmcs\Reconciler($store, $adapter, $clientFactory);
                assertSameValue(1, $reconciler->run(whmcs_gateway_params(), time()), 'Recovered payment remains in the reconciliation window.');
                assertSameValue(0, $reconciler->run(whmcs_gateway_params(), time()), 'Applied snapshot leaves the window.');
            }
            assertSameValue($status, $store->findByWhmcsInvoiceId(42)['status'], 'Successful native credit finalizes the snapshot.');
            assertSameValue(1, count($adapter->payments), 'Recovered payment is credited exactly once.');
            assertSameValue('100.00', $adapter->payments[0]['amount'], 'Recovered charge retains native amount formatting.');
        }
    }
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
        whmcs_live_invoice_response('inv_old', 'cancelled', time() + 600),
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
    // BUG-166: the old invoice is cancelled on the server first, or the client
    // could pay both.
    $requests = $transport->requests();
    assertSameValue(2, count($requests), 'the old invoice is cancelled, then the new one created.');
    assertSameValue('https://api.paymos.test/v1/invoices/inv_old/cancel', $requests[0]['url'], 'the old invoice is cancelled first.');
    assertSameValue('https://api.paymos.test/v1/invoices', $requests[1]['url'], 'the new invoice is created after the cancel.');
    assertSameValue('cancelled', $store->findByExternalOrderId('whmcs_42_0')['status'], 'the old row records the cancel, so its cancelled webhook is ignored as stale.');
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
        'is_test' => true,
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
        whmcs_live_invoice_response('inv_existing', 'cancelled', time() - 3 * 86400),
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
    // Still awaiting_client on the server (its expiry job has not run): it is
    // cancelled before the replacement.
    assertSameValue('https://api.paymos.test/v1/invoices/inv_existing/cancel', $transport->requests()[1]['url'], 'the stale invoice is cancelled before the replacement.');
    $payload = json_decode($transport->requests()[2]['body'], true);
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

function whmcs_problem($status, $code, $detail)
{
    return new HttpResponse($status, json_encode(array(
        'type' => 'https://paymos.io/errors/' . $code,
        'title' => 'Error',
        'status' => $status,
        'detail' => $detail,
        'code' => $code,
    )), array('Content-Type' => 'application/problem+json'));
}

function whmcs_render_expecting_block(GatewayLink $link, array $params)
{
    try {
        $link->render($params);
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('the link must refuse to replace an invoice it cannot prove closed.');
}

function test_whmcs_gateway_link_does_not_replace_an_invoice_the_client_can_still_pay()
{
    // BUG-166: the amount due changed while the client had already picked a
    // network on the old invoice. The server refuses the cancel (only
    // awaiting_client is cancellable) and the old invoice stays payable.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        $store = whmcs_store_with_existing_link('awaiting_client');
        $adapter = new FakeWhmcsAdapter();
        $transport = new MockTransport(array(
            whmcs_problem(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status ' . $status . '.'),
            whmcs_live_invoice_response('inv_existing', $status, time() + 600),
        ));
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

        $e = whmcs_render_expecting_block(new GatewayLink($store, static function () use ($client) {
            return $client;
        }, $adapter), whmcs_gateway_params(array('amount' => '120.00')));

        assertSameValue($status, $e->result()->status(), $status . ': the blocking status is reported.');
        assertSameValue(array('POST', 'GET'), array_column($transport->requests(), 'method'), $status . ': cancel, read, and no create.');
        assertSameValue('inv_existing', $store->findByWhmcsInvoiceId(42)['paymos_invoice_id'], $status . ': the old invoice stays.');
        assertSameValue('awaiting_client', $store->findByWhmcsInvoiceId(42)['status'], $status . ': an open or paid status read here is not recorded.');
        assertSameValue(1, count($adapter->logs), $status . ': the invoice is routed to manual review in the gateway log.');
        assertContainsValue('Manual review', $adapter->logs[0]['status'], $status . ': the log entry says manual review.');
        assertContainsValue('inv_existing', $adapter->logs[0]['status'], $status . ': the log entry names the old invoice.');
    }
}

function test_whmcs_gateway_link_does_not_replace_an_invoice_the_server_answers_404_for()
{
    $store = whmcs_store_with_existing_link('awaiting_client');
    $adapter = new FakeWhmcsAdapter();
    $transport = new MockTransport(array(whmcs_problem(404, 'not_found', 'Invoice not found.')));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $e = whmcs_render_expecting_block(new GatewayLink($store, static function () use ($client) {
        return $client;
    }, $adapter), whmcs_gateway_params(array('amount' => '120.00')));

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_NOT_FOUND, $e->result()->reason(), 'the 404 is the reason.');
    assertSameValue(1, count($transport->requests()), 'no invoice is created after a 404.');
}

function test_whmcs_gateway_link_does_not_renew_when_the_lookup_answers_404()
{
    // Same amount; before BUG-166 a 404 on the read cut a new invoice.
    $store = whmcs_store_with_existing_link('awaiting_client');
    $transport = new MockTransport(array(
        whmcs_problem(404, 'not_found', 'Invoice not found.'),
        whmcs_problem(404, 'not_found', 'Invoice not found.'),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    whmcs_render_expecting_block(new GatewayLink($store, static function () use ($client) {
        return $client;
    }, new FakeWhmcsAdapter()), whmcs_gateway_params());

    assertSameValue(array('GET', 'POST'), array_column($transport->requests(), 'method'), 'read, cancel attempt, and no create.');
    assertSameValue('whmcs_42_0', $store->findByWhmcsInvoiceId(42)['external_order_id'], 'no new external order id is cut.');
}

function test_whmcs_gateway_link_cancels_the_old_invoice_in_its_own_environment()
{
    $store = whmcs_store_with_existing_link('awaiting_client');
    $sandbox = new MockTransport(array(whmcs_live_invoice_response('inv_existing', 'cancelled', time() + 600)));
    $live = new MockTransport(array(new HttpResponse(201, json_encode(array(
        'invoice_id' => 'inv_live',
        'status' => 'awaiting_client',
        'payment_url' => 'https://checkout.paymos.test/live',
    )), array())));
    $clients = array(
        'sandbox' => new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $sandbox),
        'live' => new Client(new ClientConfig('pk_live_123', 'sk_live_123', 'https://api.paymos.test'), $live),
    );

    $html = (new GatewayLink($store, static function ($config, $environment = null) use ($clients) {
        return $clients[$environment === null ? $config->environment() : $environment];
    }, new FakeWhmcsAdapter()))->render(whmcs_gateway_params(array('mode' => 'live')));

    assertContainsValue('https://checkout.paymos.test/live', $html, 'the live invoice is issued.');
    assertSameValue(array('POST'), array_column($sandbox->requests(), 'method'), 'the sandbox invoice is cancelled with sandbox credentials.');
    assertSameValue(array('POST'), array_column($live->requests(), 'method'), 'only the create goes to live.');
}

function test_whmcs_gateway_link_asks_the_client_to_contact_the_store_when_the_old_invoice_may_still_be_paid()
{
    // BUG-180: "temporarily unavailable" sends the client to another gateway on
    // the same WHMCS invoice while the old Paymos invoice may still be paid.
    $blocked = new \Paymos\Plugin\InvoiceReplacementBlockedException(
        \Paymos\Plugin\InvoiceReplacementResult::blocked('inv_existing', 'awaiting_payment', \Paymos\Plugin\InvoiceReplacementResult::REASON_OPEN)
    );

    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars($blocked->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>',
        GatewayLink::failureNotice($blocked, whmcs_gateway_params()),
        'the client reads the SDK buyer message.'
    );
    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars(\PaymosWhmcs\Translation::text('replacement_blocked', whmcs_gateway_params(array('clientdetails' => array('language' => 'russian')))), ENT_QUOTES, 'UTF-8') . '</div>',
        GatewayLink::failureNotice($blocked, whmcs_gateway_params(array('clientdetails' => array('language' => 'russian')))),
        'the message comes from the language catalogue.'
    );
    assertSameValue($blocked->getMessage(), \PaymosWhmcs\Translation::text('replacement_blocked', whmcs_gateway_params()), 'the English catalogue carries the SDK text.');

    foreach (array('english', 'russian', 'german', 'spanish', 'turkish', 'chinese') as $language) {
        $catalog = require PAYMOS_WHMCS_PLUGIN_DIR . 'modules/gateways/paymosgateway/lang/' . $language . '.php';
        assertTrueValue(isset($catalog['replacement_blocked']) && $catalog['replacement_blocked'] !== '', $language . ': the message is defined.');
    }

    $entry = (string) file_get_contents(PAYMOS_WHMCS_PLUGIN_DIR . 'modules/gateways/paymosgateway.php');
    assertContainsValue('GatewayLink::failureNotice($e, $params)', $entry, 'paymosgateway_link renders every failure through failureNotice.');
}

function test_whmcs_gateway_link_failure_notice_keeps_the_other_messages()
{
    assertSameValue(
        '<div class="alert alert-danger">Paymos is temporarily unavailable. Please contact support.</div>',
        GatewayLink::failureNotice(new \RuntimeException('Paymos credentials are incomplete.'), whmcs_gateway_params()),
        'any other failure keeps the unavailable notice.'
    );

    $api = \Paymos\Exception\ApiException::fromResponse(400, json_encode(array(
        'type' => 'https://paymos.io/errors/validation_failed',
        'title' => 'Error',
        'status' => 400,
        'detail' => 'Currency is not supported.',
        'code' => 'validation_failed',
        'field' => 'currency',
    )), array());
    assertSameValue(
        '<div class="alert alert-danger">Currency is not supported. (currency)</div>',
        GatewayLink::failureNotice($api, whmcs_gateway_params()),
        'an English store sees the API detail and field.'
    );
    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars(\PaymosWhmcs\Translation::text('payment_cannot_be_used', whmcs_gateway_params(array('clientdetails' => array('language' => 'german')))), ENT_QUOTES, 'UTF-8') . ' (currency)</div>',
        GatewayLink::failureNotice($api, whmcs_gateway_params(array('clientdetails' => array('language' => 'german')))),
        'another language reads its own line instead of the English detail.'
    );
}

/**
 * Transport that records every request and answers from a script; a scripted
 * \Exception is thrown after the request is recorded: the server received the
 * POST, the module never learned its result.
 */
final class WhmcsScriptedTransport implements \Paymos\Http\TransportInterface
{
    /** @var array<int, HttpResponse|\Exception> */
    private $script;

    /** @var array<int, array<string, mixed>> */
    private $requests = array();

    public function __construct(array $script)
    {
        $this->script = array_values($script);
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->requests[] = array('method' => (string) $method, 'url' => (string) $url, 'body' => (string) $body);
        if (count($this->script) === 0) {
            throw new \RuntimeException('No scripted response for ' . $method . ' ' . $url);
        }
        $next = array_shift($this->script);
        if ($next instanceof \Exception) {
            throw $next;
        }

        return $next;
    }

    public function requests()
    {
        return $this->requests;
    }
}

/** Store whose next save() of a bound (non-intent) row fails once. */
final class WhmcsFailingBindStore implements PaymosWhmcs\InvoiceStoreInterface
{
    /** @var InMemoryInvoiceStore */
    public $inner;

    /** @var bool */
    public $failNextBind = false;

    public function __construct(InMemoryInvoiceStore $inner)
    {
        $this->inner = $inner;
    }

    public function findByWhmcsInvoiceId($invoiceId) { return $this->inner->findByWhmcsInvoiceId($invoiceId); }
    public function findByExternalOrderId($externalOrderId) { return $this->inner->findByExternalOrderId($externalOrderId); }
    public function updateStatus($paymosInvoiceId, $status) { $this->inner->updateStatus($paymosInvoiceId, $status); }
    public function findUnpaidRecent($limit, $sinceTimestamp) { return $this->inner->findUnpaidRecent($limit, $sinceTimestamp); }

    public function save(array $row)
    {
        if ($this->failNextBind && !GatewayLink::isPendingIntent($row)) {
            $this->failNextBind = false;
            throw new \RuntimeException('database write failed');
        }
        $this->inner->save($row);
    }
}

function whmcs_created_response($invoiceId, $status = 'awaiting_client')
{
    return new HttpResponse(201, json_encode(array(
        'invoice_id' => $invoiceId,
        'status' => $status,
        'payment_url' => 'https://checkout.paymos.test/' . $invoiceId,
    )), array());
}

function whmcs_render_expecting_failure(GatewayLink $link, array $params)
{
    try {
        $link->render($params);
    } catch (\Exception $e) {
        return $e;
    }

    throw new RuntimeException('render must fail while the create result is unknown.');
}

/**
 * A46-01: the first create reaches the server but its response is lost; the
 * amount due then changes. The next render must replay the stored request
 * (same key, same amount) to learn the invoice, close it, and only then cut
 * the next generation. It never re-sends whmcs_42_0 with the new amount.
 */
function test_whmcs_lost_create_response_recovers_original_payload_before_amount_change()
{
    $store = new InMemoryInvoiceStore();
    $transport = new WhmcsScriptedTransport(array(
        new \RuntimeException('timeout after the request was sent'),
        new \RuntimeException('still unreachable'),
        whmcs_created_response('inv_lost'),
        whmcs_live_invoice_response('inv_lost', 'cancelled', time() + 600),
        whmcs_created_response('inv_new'),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $factory = static function () use ($client) { return $client; };
    $adapter = new FakeWhmcsAdapter();

    whmcs_render_expecting_failure(new GatewayLink($store, $factory, $adapter), whmcs_gateway_params());
    $intent = $store->findByWhmcsInvoiceId(42);
    assertSameValue('creating', $intent['status'], 'the create intent is stored before the POST.');
    assertSameValue('whmcs_42_0', $intent['external_order_id'], 'the intent carries the idempotency key.');
    assertSameValue('100.00', $intent['amount'], 'the intent carries the original amount.');

    $changed = whmcs_gateway_params(array('amount' => '120.00'));

    // The replay itself fails: nothing new is created over the unknown invoice.
    whmcs_render_expecting_failure(new GatewayLink($store, $factory, $adapter), $changed);
    assertSameValue(2, count($transport->requests()), 'only the replay was attempted.');
    assertSameValue(null, $store->findByExternalOrderId('whmcs_42_1'), 'no next generation exists while the first result is unknown.');

    $html = (new GatewayLink($store, $factory, $adapter))->render($changed);

    $requests = $transport->requests();
    $original = json_decode($requests[0]['body'], true);
    assertSameValue(5, count($requests), 'create (lost), replay (failed), replay, cancel, create.');
    foreach (array(1, 2) as $replay) {
        assertSameValue($original, json_decode($requests[$replay]['body'], true), 'the replay sends exactly the original payload.');
    }
    assertSameValue('100.00', $original['amount'], 'the original payload kept the original amount.');
    assertSameValue('https://api.paymos.test/v1/invoices/inv_lost/cancel', $requests[3]['url'], 'the recovered invoice is closed before replacement.');
    $next = json_decode($requests[4]['body'], true);
    assertSameValue('whmcs_42_1', $next['external_order_id'], 'the new amount goes out under the next key.');
    assertSameValue('120.00', $next['amount'], 'the next generation carries the new amount.');

    assertSameValue('inv_lost', $store->findByExternalOrderId('whmcs_42_0')['paymos_invoice_id'], 'the recovered invoice is bound to its key.');
    assertSameValue('cancelled', $store->findByExternalOrderId('whmcs_42_0')['status'], 'the recovered invoice records its close.');
    assertContainsValue('https://checkout.paymos.test/inv_new', $html, 'the client is sent to the new invoice.');
}

/**
 * A46-01: the POST succeeds but the local bind fails. The next render replays
 * the identical request and binds the invoice the server already made.
 */
function test_whmcs_failed_bind_after_create_replays_the_same_request()
{
    $store = new WhmcsFailingBindStore(new InMemoryInvoiceStore());
    $store->failNextBind = true;
    $transport = new WhmcsScriptedTransport(array(
        whmcs_created_response('inv_123'),
        whmcs_created_response('inv_123'),
        whmcs_live_invoice_response('inv_123', 'awaiting_client', time() + 600),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $factory = static function () use ($client) { return $client; };
    $adapter = new FakeWhmcsAdapter();

    whmcs_render_expecting_failure(new GatewayLink($store, $factory, $adapter), whmcs_gateway_params());
    assertSameValue('creating', $store->findByWhmcsInvoiceId(42)['status'], 'the intent survives the failed bind.');

    $html = (new GatewayLink($store, $factory, $adapter))->render(whmcs_gateway_params());

    $requests = $transport->requests();
    assertSameValue(array('POST', 'POST', 'GET'), array_column($requests, 'method'), 'create, replay, liveness read.');
    assertSameValue($requests[0]['body'], $requests[1]['body'], 'the replay is byte-identical to the first create.');
    assertSameValue('inv_123', $store->findByWhmcsInvoiceId(42)['paymos_invoice_id'], 'the invoice is bound after the replay.');
    assertSameValue('whmcs_42_0', $store->findByWhmcsInvoiceId(42)['external_order_id'], 'no new key is cut.');
    assertContainsValue('https://checkout.paymos.test/inv_123', $html, 'the client gets the one invoice the server made.');
}

/** A46-01: the reconciler never reads a create intent that has no invoice id yet. */
function test_whmcs_reconciler_skips_a_pending_create_intent()
{
    $store = new InMemoryInvoiceStore();
    $transport = new WhmcsScriptedTransport(array(new \RuntimeException('lost')));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $factory = static function () use ($client) { return $client; };
    $adapter = new FakeWhmcsAdapter();
    whmcs_render_expecting_failure(new GatewayLink($store, $factory, $adapter), whmcs_gateway_params());

    assertSameValue(0, (new PaymosWhmcs\Reconciler($store, $adapter, $factory))->run(whmcs_gateway_params(), time()), 'nothing is applied.');
    assertSameValue(1, count($transport->requests()), 'no GET is sent for a placeholder id.');
}
