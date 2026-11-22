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

function test_whmcs_gateway_link_single_service_invoice_and_currency_guard()
{
    $store = new InMemoryInvoiceStore();
    $GLOBALS['paymos_whmcs_local_api'] = static function ($command, array $values) {
        if ($command === 'GetInvoice') {
            return array(
                'result' => 'success',
                'invoiceid' => 42,
                'userid' => 77,
                'total' => '47.00',
                'items' => array(
                    'item' => array(
                        array(
                            'id' => '101',
                            'type' => 'Hosting',
                            'relid' => '55',
                            'description' => "<b>Managed VPS</b> &amp; Support\0\n<p>4 vCPU</p><p>8 GB RAM</p>",
                            'amount' => '24.50',
                            'taxed' => '1',
                        ),
                    ),
                ),
            );
        }
        if ($command === 'GetClientsDetails') {
            return array(
                'result' => 'success',
                'client' => array('currency_code' => 'USD'),
            );
        }
        return array('result' => 'error', 'message' => 'Unexpected command ' . $command);
    };

    $transport = new MockTransport(array(whmcs_created_response('inv_whmcs_single')));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayLink($store, static function () use ($client) {
        return $client;
    }, new PaymosWhmcs\WhmcsAdapter()))->render(whmcs_gateway_params(array('amount' => '47.00')));

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('47.00', $payload['amount'], 'native WHMCS amount must be kept.');
    assertSameValue(
        array(
            array(
                'title' => 'Managed VPS & Support',
                'quantity' => 1,
                'unit_amount' => '24.50',
                'description' => "4 vCPU\n8 GB RAM",
            ),
        ),
        $payload['products'],
        'multiline WHMCS GetInvoice line item must map title + plain-text description.'
    );

    // Single-line item without optional description must succeed.
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['items'] = array(
        'item' => array(
            array('id' => '102', 'type' => 'DomainRegister', 'description' => 'example.com registration', 'amount' => '12.00'),
        ),
    );
    $storeMin = new InMemoryInvoiceStore();
    $transportMin = new MockTransport(array(whmcs_created_response('inv_whmcs_min')));
    $clientMin = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transportMin);

    (new GatewayLink($storeMin, static function () use ($clientMin) {
        return $clientMin;
    }, $adapter))->render(whmcs_gateway_params(array('amount' => '12.00')));

    $minPayload = json_decode($transportMin->requests()[0]['body'], true);
    assertSameValue(
        array(array('title' => 'example.com registration', 'quantity' => 1, 'unit_amount' => '12.00')),
        $minPayload['products'],
        'single-line WHMCS service item without description must still be sent.'
    );

    // Convert To For Processing (client currency != processing currency) must fail closed with 0 HTTP requests.
    $adapter->invoiceCurrencies[42] = 'EUR';
    $adapter->logs = array();
    $storeConverted = new InMemoryInvoiceStore();
    $transportConverted = new MockTransport(array());
    $clientConverted = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transportConverted);
    $convertedParams = whmcs_gateway_params(array('amount' => '13.50', 'currency' => 'USD'));

    $mismatch = null;
    try {
        (new GatewayLink($storeConverted, static function () use ($clientConverted) {
            return $clientConverted;
        }, $adapter))->render($convertedParams);
    } catch (\PaymosWhmcs\CurrencyMismatchException $e) {
        $mismatch = $e;
    }

    assertTrueValue($mismatch instanceof \PaymosWhmcs\CurrencyMismatchException, 'Convert To For Processing currency mismatch must throw CurrencyMismatchException.');
    assertSameValue('EUR', $mismatch->invoiceCurrency(), 'exception must report native WHMCS invoice currency.');
    assertSameValue('USD', $mismatch->processingCurrency(), 'exception must report gateway processing currency.');
    assertSameValue(0, count($transportConverted->requests()), 'Convert To For Processing currency mismatch must fail closed with 0 HTTP requests.');
    assertSameValue(null, $storeConverted->findByWhmcsInvoiceId(42), 'currency mismatch must not store a Paymos invoice snapshot.');
    assertSameValue(1, count($adapter->logs), 'currency mismatch must log a gateway entry for the administrator.');
    assertContainsValue('Currency mismatch: disable Convert To For Processing', $adapter->logs[0]['status'], 'gateway log must instruct admin to disable Convert To For Processing.');
    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars(\PaymosWhmcs\Translation::text('payment_cannot_be_used', $convertedParams), ENT_QUOTES, 'UTF-8') . '</div>',
        GatewayLink::failureNotice($mismatch, $convertedParams),
        'failureNotice must render payment_cannot_be_used for CurrencyMismatchException.'
    );
    $ruConvertedParams = whmcs_gateway_params(array(
        'amount' => '13.50',
        'currency' => 'USD',
        'clientdetails' => array('language' => 'russian'),
    ));
    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars(\PaymosWhmcs\Translation::text('payment_cannot_be_used', $ruConvertedParams), ENT_QUOTES, 'UTF-8') . '</div>',
        GatewayLink::failureNotice($mismatch, $ruConvertedParams),
        'failureNotice must localize payment_cannot_be_used for non-English clients.'
    );

    // Even when an existing snapshot is present, currency mismatch must fail closed before lookup/cancel/create.
    $storeExistingMismatch = whmcs_store_with_existing_link('awaiting_client');
    $transportExistingMismatch = new MockTransport(array());
    $clientExistingMismatch = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transportExistingMismatch);
    $existingMismatch = null;
    try {
        (new GatewayLink($storeExistingMismatch, static function () use ($clientExistingMismatch) {
            return $clientExistingMismatch;
        }, $adapter))->render($convertedParams);
    } catch (\PaymosWhmcs\CurrencyMismatchException $e) {
        $existingMismatch = $e;
    }
    assertTrueValue($existingMismatch instanceof \PaymosWhmcs\CurrencyMismatchException, 'currency mismatch with existing row must throw CurrencyMismatchException.');
    assertSameValue(0, count($transportExistingMismatch->requests()), 'currency mismatch with existing row must make 0 HTTP requests.');
}

function test_whmcs_gateway_link_cart_excludes_promos_fees_and_applies_all_or_nothing_fallback()
{
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['total'] = '42.50';
    $adapter->invoices[42]['items'] = array(
        'item' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => "Canvas · Blue\n<p>Giclée print</p>", 'amount' => '15.00', 'quantity' => 2),
            array('id' => '2', 'type' => 'PromoHosting', 'description' => 'Promo Code: SAVE5', 'amount' => '-5.00'),
            array('id' => '3', 'type' => 'GroupDiscount', 'description' => 'VIP Discount', 'amount' => '-2.50'),
            array('id' => '4', 'type' => 'LateFee', 'description' => 'Late Fee', 'amount' => '2.50'),
            array('id' => '5', 'type' => 'Tax', 'description' => 'VAT', 'amount' => '5.00'),
            array('id' => '6', 'type' => 'Shipping', 'description' => 'Courier', 'amount' => '5.00'),
            array('id' => '7', 'type' => 'Addon', 'description' => 'Notebook', 'amount' => '12.50'),
        ),
    );
    $store = new InMemoryInvoiceStore();
    $transport = new MockTransport(array(whmcs_created_response('inv_whmcs_cart')));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    (new GatewayLink($store, static function () use ($client) {
        return $client;
    }, $adapter))->render(whmcs_gateway_params(array('amount' => '42.50')));

    $payload = json_decode($transport->requests()[0]['body'], true);
    assertSameValue('42.50', $payload['amount'], 'WHMCS native grand total must be preserved.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas · Blue',
                'quantity' => 2,
                'unit_amount' => '15.00',
                'description' => 'Giclée print',
            ),
            array(
                'title' => 'Notebook',
                'quantity' => 1,
                'unit_amount' => '12.50',
            ),
        ),
        $payload['products'],
        'WHMCS cart must exclude promo/discount/fee/tax/shipping rows and keep service items.'
    );

    // 50 items succeed; 51 items, fractional qty, blank title, and negative price fall back all-or-nothing.
    $fifty = array();
    for ($i = 1; $i <= 50; $i++) {
        $fifty[] = array('id' => (string) $i, 'type' => 'Hosting', 'description' => 'Service ' . $i, 'amount' => '1.00');
    }
    $fiftyOne = $fifty;
    $fiftyOne[] = array('id' => '51', 'type' => 'Hosting', 'description' => 'Service 51', 'amount' => '1.00');

    $maxAdapter = new FakeWhmcsAdapter();
    $maxAdapter->invoices[42]['items'] = array('item' => $fifty);
    $maxTransport = new MockTransport(array(whmcs_created_response('inv_50')));
    $maxClient = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $maxTransport);
    (new GatewayLink(new InMemoryInvoiceStore(), static function () use ($maxClient) {
        return $maxClient;
    }, $maxAdapter))->render(whmcs_gateway_params(array('amount' => '50.00')));
    $maxPayload = json_decode($maxTransport->requests()[0]['body'], true);
    assertSameValue(50, count($maxPayload['products']), 'exactly 50 WHMCS items must be sent.');

    $invalidCases = array(
        'fractional_qty' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => 'Valid', 'amount' => '20.00', 'quantity' => 1),
            array('id' => '2', 'type' => 'Hosting', 'description' => 'Hours', 'amount' => '10.00', 'quantity' => 1.5),
        ),
        'blank_description' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => 'Valid', 'amount' => '20.00'),
            array('id' => '2', 'type' => '', 'description' => '<b>&nbsp;</b>', 'amount' => '5.00'),
        ),
        'negative_item_amount' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => 'Valid', 'amount' => '20.00'),
            array('id' => '2', 'type' => 'Hosting', 'description' => 'Invalid Negative', 'amount' => '-5.00'),
        ),
        'over_50' => $fiftyOne,
    );

    foreach ($invalidCases as $label => $items) {
        $caseAdapter = new FakeWhmcsAdapter();
        $caseAdapter->invoices[42]['items'] = array('item' => $items);
        $caseTransport = new MockTransport(array(whmcs_created_response('inv_' . $label)));
        $caseClient = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $caseTransport);

        (new GatewayLink(new InMemoryInvoiceStore(), static function () use ($caseClient) {
            return $caseClient;
        }, $caseAdapter))->render(whmcs_gateway_params(array('amount' => '42.50')));

        $casePayload = json_decode($caseTransport->requests()[0]['body'], true);
        assertSameValue('42.50', $casePayload['amount'], $label . ': fallback preserves amount.');
        assertFalseValue(array_key_exists('products', $casePayload), $label . ': invalid WHMCS cart reverts to amount-only.');
    }
}

function test_whmcs_gateway_link_retry_and_metadata_change_preserve_money_guards()
{
    $store = new InMemoryInvoiceStore();
    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['items'] = array(
        'item' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => 'Plan A', 'amount' => '100.00'),
        ),
    );
    $transport = new MockTransport(array(
        whmcs_created_response('inv_existing'),
        whmcs_live_invoice_response('inv_existing', 'awaiting_client', time() + 600),
        whmcs_live_invoice_response('inv_existing', 'cancelled', time() + 600),
        whmcs_created_response('inv_new'),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);
    $factory = static function () use ($client) { return $client; };

    (new GatewayLink($store, $factory, $adapter))->render(whmcs_gateway_params());

    // Changing only line item presentation metadata with matching amount/currency reuses existing invoice.
    $adapter->invoices[42]['items'] = array(
        'item' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => 'Plan A Renamed', 'amount' => '100.00'),
        ),
    );
    $reusedHtml = (new GatewayLink($store, $factory, $adapter))->render(whmcs_gateway_params());
    assertContainsValue('https://checkout.paymos.test/inv_existing', $reusedHtml, 'line item text change alone must reuse matching financial invoice.');

    // Changing amount due replaces old invoice and includes updated items under bumped key.
    $adapter->invoices[42]['items'] = array(
        'item' => array(
            array('id' => '1', 'type' => 'Hosting', 'description' => 'Plan B', 'amount' => '120.00'),
        ),
    );
    $replacedHtml = (new GatewayLink($store, $factory, $adapter))->render(whmcs_gateway_params(array('amount' => '120.00')));
    assertContainsValue('https://checkout.paymos.test/inv_new', $replacedHtml, 'amount change must create new invoice.');
    $requests = $transport->requests();
    assertSameValue(array('POST', 'GET', 'POST', 'POST'), array_column($requests, 'method'), 'create, reuse check, cancel, new create.');
    $newPayload = json_decode($requests[3]['body'], true);
    assertSameValue('whmcs_42_1', $newPayload['external_order_id'], 'bumped key must be used.');
    assertSameValue('Plan B', $newPayload['products'][0]['title'], 'new generation must carry current WHMCS items.');
}

function test_whmcs_gateway_link_formats_float_unit_amounts_locale_independently()
{
    // BUG-1467: PHP 7.4 LC_NUMERIC comma-decimal locales must never turn float unit_amount into "24,5".
    $previousLocale = setlocale(LC_NUMERIC, '0');
    setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German_Germany.1252', 'fr_FR.UTF-8', 'French_France.1252');
    try {
        $store = new InMemoryInvoiceStore();
        $adapter = new FakeWhmcsAdapter();
        $adapter->invoices[42]['items'] = array(
            'item' => array(
                array('id' => '1', 'type' => 'Hosting', 'description' => 'VPS Plan', 'amount' => 24.5),
                array('id' => '2', 'type' => 'Hosting', 'description' => 'Add-on', 'amount' => 19.99),
                array('id' => '3', 'type' => 'Hosting', 'description' => 'Micro', 'amount' => 1e-9),
                array('id' => '4', 'type' => 'Hosting', 'description' => 'Free', 'amount' => 0.0),
            ),
        );
        $transport = new MockTransport(array(whmcs_created_response('inv_whmcs_locale')));
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

        (new GatewayLink($store, static function () use ($client) {
            return $client;
        }, $adapter))->render(whmcs_gateway_params(array('amount' => '44.49')));

        $payload = json_decode($transport->requests()[0]['body'], true);
        assertSameValue('24.5', $payload['products'][0]['unit_amount'], 'Float 24.5 formats dot-decimal.');
        assertSameValue('19.99', $payload['products'][1]['unit_amount'], 'Float 19.99 keeps exact cents.');
        assertSameValue('0.000000001', $payload['products'][2]['unit_amount'], 'Scientific float formats dot-decimal.');
        assertSameValue('0', $payload['products'][3]['unit_amount'], 'Zero float formats cleanly.');
    } finally {
        if (is_string($previousLocale)) {
            setlocale(LC_NUMERIC, $previousLocale);
        }
    }
}

function test_whmcs_gateway_link_blocks_renewal_after_paid_late_marker_and_remote_paid_late_at()
{
    // 1. Local paid_late_noted marker on an expired row blocks immediately with 0 HTTP requests.
    $markedStore = new InMemoryInvoiceStore();
    $markedStore->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'inv_late_local',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://checkout.paymos.test/inv_late_local',
        'status' => 'expired',
        'renew_count' => 0,
        'paid_late_noted' => 'evt_late_local',
    ));
    $markedAdapter = new FakeWhmcsAdapter();
    $markedTransport = new MockTransport(array());
    $markedClient = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $markedTransport);

    $blockedLocal = whmcs_render_expecting_block(new GatewayLink($markedStore, static function () use ($markedClient) {
        return $markedClient;
    }, $markedAdapter), whmcs_gateway_params());

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_PAID, $blockedLocal->result()->reason(), 'local paid_late_noted must block with REASON_PAID.');
    assertSameValue(0, count($markedTransport->requests()), 'local paid_late_noted must not call Paymos API.');
    assertSameValue(null, $markedStore->findByExternalOrderId('whmcs_42_1'), 'local paid_late_noted must never create a replacement invoice.');
    assertSameValue(1, count($markedAdapter->logs), 'local paid_late_noted must log manual review.');
    assertContainsValue('Manual review', $markedAdapter->logs[0]['status'], 'local paid_late_noted log must say Manual review.');
    assertSameValue(
        '<div class="alert alert-danger">' . htmlspecialchars(\PaymosWhmcs\Translation::text('replacement_blocked', whmcs_gateway_params()), ENT_QUOTES, 'UTF-8') . '</div>',
        GatewayLink::failureNotice($blockedLocal, whmcs_gateway_params()),
        'failureNotice must render replacement_blocked for paid_late block.'
    );

    // 2. Remote GET /v1/invoices/{id} inside keepsExistingInvoice() returning paid_late_at
    //    records paid_late_noted and blocks renewal without cancelling or creating a second invoice.
    $keepStore = whmcs_store_with_existing_link('awaiting_client');
    $keepAdapter = new FakeWhmcsAdapter();
    $keepTransport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_existing',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'is_test' => true,
            'is_final' => true,
            'payment_url' => 'https://checkout.paymos.test/existing',
            'expires_at' => time() - 3600,
            'paid_late_at' => 1777978800,
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
        )), array()),
    ));
    $keepClient = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $keepTransport);
    $keepLink = new GatewayLink($keepStore, static function () use ($keepClient) {
        return $keepClient;
    }, $keepAdapter);

    $blockedKeep = whmcs_render_expecting_block($keepLink, whmcs_gateway_params());

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_PAID, $blockedKeep->result()->reason(), 'remote paid_late_at in keepsExistingInvoice must block with REASON_PAID.');
    assertSameValue(array('GET'), array_column($keepTransport->requests(), 'method'), 'keepsExistingInvoice must stop after the single GET without cancel or create.');
    assertSameValue('1777978800', $keepStore->findByWhmcsInvoiceId(42)['paid_late_noted'], 'keepsExistingInvoice must persist paid_late_noted.');
    assertSameValue(null, $keepStore->findByExternalOrderId('whmcs_42_1'), 'remote paid_late_at must not issue a replacement invoice.');
    assertSameValue(1, count($keepAdapter->logs), 'keepsExistingInvoice paid_late must log manual review.');
    assertContainsValue('Manual review', $keepAdapter->logs[0]['status'], 'keepsExistingInvoice log must say Manual review.');

    // Subsequent render hits the persisted paid_late_noted marker with 0 extra HTTP requests.
    whmcs_render_expecting_block($keepLink, whmcs_gateway_params());
    assertSameValue(1, count($keepTransport->requests()), 'persisted paid_late_noted must block future renders with 0 extra HTTP requests.');

    // 3. Amount change routes through closeBeforeReplacing(): cancel returns 409 and GET returns
    //    status=expired with paid_late_at. The proxy intercepts paid_late_at with 0 extra HTTP
    //    requests beyond cancel+get and blocks replacement instead of creating whmcs_42_1.
    $replaceStore = whmcs_store_with_existing_link('awaiting_client');
    $replaceAdapter = new FakeWhmcsAdapter();
    $replaceTransport = new MockTransport(array(
        whmcs_problem(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status expired.'),
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_existing',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'is_test' => true,
            'is_final' => true,
            'payment_url' => 'https://checkout.paymos.test/existing',
            'expires_at' => time() - 3600,
            'paid_late_at' => 1777978800,
            'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
        )), array()),
    ));
    $replaceClient = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $replaceTransport);

    $blockedReplace = whmcs_render_expecting_block(new GatewayLink($replaceStore, static function () use ($replaceClient) {
        return $replaceClient;
    }, $replaceAdapter), whmcs_gateway_params(array('amount' => '120.00')));

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_PAID, $blockedReplace->result()->reason(), 'closeBeforeReplacing must block remote paid_late_at with REASON_PAID.');
    assertSameValue(array('POST', 'GET'), array_column($replaceTransport->requests(), 'method'), 'closeBeforeReplacing must make only cancel + get and 0 extra HTTP requests.');
    assertSameValue('1777978800', $replaceStore->findByWhmcsInvoiceId(42)['paid_late_noted'], 'closeBeforeReplacing must persist paid_late_noted.');
    assertSameValue('whmcs_42_0', $replaceStore->findByWhmcsInvoiceId(42)['external_order_id'], 'closeBeforeReplacing must keep the original external_order_id.');
    assertSameValue(null, $replaceStore->findByExternalOrderId('whmcs_42_1'), 'closeBeforeReplacing must not create whmcs_42_1 after paid_late.');
    assertSameValue(1, count($replaceAdapter->logs), 'closeBeforeReplacing paid_late must log manual review.');
    assertContainsValue('Manual review', $replaceAdapter->logs[0]['status'], 'closeBeforeReplacing log must say Manual review.');
}

function test_whmcs_gateway_link_recover_pending_intent_preserves_products()
{
    $store = new InMemoryInvoiceStore();
    $store->save(array(
        'whmcs_invoice_id' => 42,
        'paymos_invoice_id' => 'pending:whmcs_42_0',
        'external_order_id' => 'whmcs_42_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'invoice_total' => '100.00',
        'currency' => 'USD',
        'payment_url' => '',
        'status' => GatewayLink::STATUS_CREATING,
        'renew_count' => 0,
    ));

    $adapter = new FakeWhmcsAdapter();
    $adapter->invoices[42]['items'] = array(
        'item' => array(
            array(
                'id' => '1',
                'type' => 'Hosting',
                'description' => "Cloud VPS\n4 vCPU / 8 GB RAM",
                'amount' => '75.00',
            ),
            array(
                'id' => '2',
                'type' => 'Addon',
                'description' => 'Daily Backups',
                'amount' => '25.00',
            ),
        ),
    );

    $transport = new MockTransport(array(
        whmcs_created_response('inv_recovered_products'),
        whmcs_live_invoice_response('inv_recovered_products', 'awaiting_client', time() + 600),
    ));
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $transport);

    $html = (new GatewayLink($store, static function () use ($client) {
        return $client;
    }, $adapter))->render(whmcs_gateway_params());

    assertContainsValue('https://checkout.paymos.test/inv_recovered_products', $html, 'recovered invoice must render its checkout link.');
    $requests = $transport->requests();
    assertSameValue(array('POST', 'GET'), array_column($requests, 'method'), 'recovery replays POST /v1/invoices and verifies liveness.');
    $replayPayload = json_decode($requests[0]['body'], true);
    assertSameValue('whmcs_42_0', $replayPayload['external_order_id'], 'replay must keep original external_order_id.');
    assertSameValue(
        array(
            array(
                'title' => 'Cloud VPS',
                'quantity' => 1,
                'unit_amount' => '75.00',
                'description' => '4 vCPU / 8 GB RAM',
            ),
            array(
                'title' => 'Daily Backups',
                'quantity' => 1,
                'unit_amount' => '25.00',
            ),
        ),
        isset($replayPayload['products']) ? $replayPayload['products'] : null,
        'recoverPendingIntent() must preserve WHMCS invoice line items in products[].'
    );
}
