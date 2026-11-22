<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Webhook\WebhookEvent;
use PaymosWhmcs\RefundAdapter;
use PaymosWhmcs\RefundIntentStore;

if (!defined('PAYMOS_WHMCS_PLUGIN_DIR')) { require __DIR__ . '/bootstrap.php'; }

/** SQL-shaped unit double; this is not native MySQL/WHMCS evidence. */
final class WhmcsRefundDatabase
{
    public $intents = array();
    public $configuration = array('PaymosRefundInstallationV1' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    public $lock;
    public $owned = true;
    public $commits = 0;
    private $snapshot;

    public function prepare($sql) { return new WhmcsRefundStatement($this, preg_replace('/\s+/', ' ', trim($sql))); }
    public function inTransaction() { return $this->snapshot !== null; }
    public function beginTransaction() { $this->snapshot = $this->intents; return true; }
    public function commit() { $this->snapshot = null; $this->commits++; return true; }
    public function rollBack() { $this->intents = $this->snapshot; $this->snapshot = null; return true; }
}

final class WhmcsRefundStatement
{
    private $db;
    private $sql;
    private $rows = array();
    private $count = 0;

    public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
    public function fetchAll($mode) { return $this->rows; }
    public function rowCount() { return $this->count; }
    public function fetchColumn() { return $this->rows ? reset($this->rows[0]) : false; }

    public function execute($values)
    {
        $sql = $this->sql;
        $this->rows = array();
        $this->count = 0;
        if (strpos($sql, 'CREATE TABLE') === 0) { return true; }
        if (strpos($sql, 'SHOW TABLE STATUS') === 0) { $this->rows = array(array('Engine' => 'InnoDB')); return true; }
        if (strpos($sql, 'SELECT GET_LOCK') === 0) {
            $this->db->lock = $values[0]; $this->rows = array(array(1)); return true;
        }
        if (strpos($sql, 'SELECT RELEASE_LOCK') === 0) { $this->db->lock = null; return true; }
        if (strpos($sql, 'SELECT IS_USED_LOCK') === 0) {
            $this->rows = array(array((int) ($this->db->owned && $this->db->lock === $values[0]))); return true;
        }
        if (strpos($sql, 'SELECT value FROM tblconfiguration') === 0) {
            if (isset($this->db->configuration[$values[0]])) { $this->rows[] = array('value' => $this->db->configuration[$values[0]]); }
            return true;
        }
        if (strpos($sql, 'INSERT IGNORE INTO tblconfiguration') === 0) {
            if (!isset($this->db->configuration[$values[0]])) { $this->db->configuration[$values[0]] = $values[1]; }
            return true;
        }
        if (strpos($sql, 'INSERT INTO ' . RefundIntentStore::TABLE) === 0) {
            $row = array_combine(array('operation_id', 'installation_id', 'request_token', 'external_order_id',
                'native_payment_key', 'withdrawal_id', 'payload', 'version', 'created_at', 'next_check'), $values);
            foreach ($this->db->intents as $old) {
                if ($old['operation_id'] === $row['operation_id'] || $old['external_order_id'] === $row['external_order_id']
                    || ($old['installation_id'] === $row['installation_id'] && $old['request_token'] === $row['request_token'])) {
                    throw new RuntimeException('Duplicate entry');
                }
            }
            $this->db->intents[$row['operation_id']] = $row; $this->count = 1; return true;
        }
        if (strpos($sql, 'UPDATE ' . RefundIntentStore::TABLE) === 0) {
            $offset = strpos($sql, 'next_check = ?') !== false ? 4 : 3;
            $id = $values[$offset];
            if (isset($this->db->intents[$id]) && $this->db->intents[$id]['version'] === $values[$offset + 1]) {
                foreach ($this->db->intents as $old) {
                    if ($values[1] !== null && $old['withdrawal_id'] === $values[1] && $old['operation_id'] !== $id) {
                        throw new RuntimeException('Duplicate withdrawal');
                    }
                }
                $this->db->intents[$id]['payload'] = $values[0];
                $this->db->intents[$id]['withdrawal_id'] = $values[1];
                $this->db->intents[$id]['version'] = $values[2];
                if ($offset === 4) { $this->db->intents[$id]['next_check'] = $values[3]; }
                $this->count = 1;
            }
            return true;
        }
        if (strpos($sql, 'SELECT payload FROM ' . RefundIntentStore::TABLE) === 0) {
            $where = explode(' WHERE ', $sql, 2)[1];
            $conditions = preg_split('/ ORDER BY | LIMIT /', $where)[0];
            $matching = array();
            foreach ($this->db->intents as $row) {
                $match = true;
                foreach (explode(' AND ', $conditions) as $i => $condition) {
                    if (!preg_match('/^(\w+) (=|<=|>) \?$/', $condition, $m)) { throw new RuntimeException('Unexpected predicate: ' . $condition); }
                    $actual = $row[$m[1]];
                    $match = $match && ($m[2] === '=' ? $actual === $values[$i] : ($m[2] === '<=' ? $actual <= $values[$i] : $actual > $values[$i]));
                }
                if ($match) { $matching[] = $row; }
            }
            usort($matching, static function ($a, $b) { return strcmp($a['operation_id'], $b['operation_id']); });
            if (preg_match('/ LIMIT ([0-9]+)$/', $sql, $m)) { $matching = array_slice($matching, 0, (int) $m[1]); }
            $this->rows = $matching;
            return true;
        }
        throw new RuntimeException('Unexpected unit SQL: ' . $sql);
    }
}

final class WhmcsRefundNative
{
    public $snapshot;
    public $commands = array();
    public $crashAfterWrite = false;
    public $markerConflict = false;
    public $statusFailure = false;
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->snapshot = array('invoice' => array('id' => 42, 'userid' => 77, 'paymentmethod' => 'paymosgateway',
            'status' => 'Paid', 'total' => '100.00', 'credit' => '0.00'), 'currency' => 'USD',
            'mapping' => array('whmcs_invoice_id' => 42, 'paymos_invoice_id' => 'inv_123', 'project_id' => 'prj_123',
                'environment' => 'sandbox', 'external_order_id' => 'whmcs_42_0', 'amount' => '100.00', 'currency' => 'USD', 'status' => 'paid_over'),
            'accounts' => array(array('id' => 1, 'userid' => 77, 'invoiceid' => 42, 'gateway' => 'paymosgateway',
                'transid' => 'paymos_inv_123_evt_paid', 'amountin' => '100.00', 'amountout' => '0.00')));
    }

    public function __invoke($command, $input)
    {
        if ($command === 'snapshot') { return $this->snapshot; }
        if ($command === 'marker') {
            $rows = array_values(array_filter($this->snapshot['accounts'], static function ($row) use ($input) { return $row['transid'] === $input['transid']; }));
            if ($rows && $this->markerConflict) { $rows[0]['amountout'] = '1.00'; }
            return $rows;
        }
        assertFalseValue($this->db->inTransaction(), 'localAPI must not run in a module transaction.');
        $this->commands[] = array($command, $input);
        if ($command === 'AddTransaction') {
            foreach ($this->snapshot['accounts'] as $row) {
                if ($row['transid'] === $input['transid']) { return array('result' => 'error'); }
            }
            $this->snapshot['accounts'][] = array('id' => count($this->snapshot['accounts']) + 1, 'invoiceid' => $input['invoiceid'],
                'userid' => $input['userid'], 'transid' => $input['transid'], 'gateway' => $input['paymentmethod'],
                'amountin' => $input['amountin'], 'amountout' => $input['amountout']);
            if ($this->crashAfterWrite) { $this->crashAfterWrite = false; throw new RuntimeException('Native acknowledgement lost'); }
        } elseif ($command === 'UpdateInvoice') {
            if ($this->statusFailure) { $this->statusFailure = false; return array('result' => 'error'); }
            $this->snapshot['invoice']['status'] = $input['status'];
        } else { throw new RuntimeException('Unexpected native command: ' . $command); }
        return array('result' => 'success');
    }
}

final class WhmcsRefundServer implements TransportInterface
{
    public $refunds = array();
    public $requests = array();
    public $loseResponse = false;
    public $loseResponses = 0;
    public $refuse = false;
    public $scopeConflict = false;
    public $omitSummary = false;
    public $invoiceFailure = null;
    private $db;

    public function __construct($db) { $this->db = $db; }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertFalseValue($this->db->inTransaction(), 'provider HTTP must be outside the DB transaction.');
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        $path = parse_url($url, PHP_URL_PATH);
        if ($method === 'POST') {
            $input = json_decode($body, true);
            assertSameValue(array('invoice_id', 'mode', 'external_order_id'), array_keys($input), 'mode-only request contract.');
            $committed = false;
            foreach ($this->db->intents as $row) {
                $intent = json_decode($row['payload'], true);
                if ($intent['external_order_id'] === $input['external_order_id']) {
                    $committed = $intent['cash_state'] === RefundStates::UNKNOWN && $this->db->commits > 0;
                }
            }
            assertTrueValue($committed, 'mode-bound uncertainty is committed before HTTP.');
            if ($this->refuse) {
                return new HttpResponse(409, json_encode(array(
                    'type' => 'https://api.paymos.io/errors/conflict',
                    'title' => 'Conflict',
                    'status' => 409,
                    'detail' => 'Refused',
                    'code' => 'refund_not_eligible',
                )), array('content-type' => 'application/problem+json'));
            }
            $key = $input['external_order_id'];
            if (!isset($this->refunds[$key])) {
                $this->refunds[$key] = array('withdrawal_id' => 'wd_' . (count($this->refunds) + 1), 'invoice_id' => $input['invoice_id'],
                    'mode' => $input['mode'], 'external_order_id' => $key, 'status' => 'created', 'is_test' => true,
                    'project_id' => 'prj_123', 'amount' => $input['mode'] === 'overpayment' ? '20' : (count($this->refunds) ? '100' : '120'),
                    'currency' => 'USDT', 'network' => 'TRC20', 'first_sender' => 'TFirstSender', 'created_at' => time());
            }
            if ($this->loseResponse) { $this->loseResponse = false; throw new RuntimeException('Response lost after server commit'); }
            if ($this->loseResponses > 0) { $this->loseResponses--; throw new RuntimeException('Response lost after server commit'); }
            return new HttpResponse(201, json_encode($this->refunds[$key]), array());
        }
        if (strpos($path, '/v1/refunds/') === 0) {
            $id = basename($path);
            foreach ($this->refunds as $refund) {
                if ($refund['withdrawal_id'] === $id) { return new HttpResponse(200, json_encode($refund), array()); }
            }
            throw new RuntimeException('Unknown refund fixture');
        }
        if ($this->invoiceFailure !== null) {
            $failure = $this->invoiceFailure;
            $this->invoiceFailure = null;
            if ($failure instanceof Throwable) { throw $failure; }
            $status = (int) $failure;
            return new HttpResponse($status, json_encode(array(
                'type' => 'https://api.paymos.io/errors/' . ($status === 404 ? 'not-found' : 'validation'),
                'title' => $status === 404 ? 'Not Found' : 'Bad Request',
                'status' => $status,
                'detail' => 'Simulated invoice read failure',
                'code' => $status === 404 ? 'invoice_not_found' : 'refund_denied',
            )), array('content-type' => 'application/problem+json'));
        }
        $completed = false;
        foreach ($this->refunds as $refund) { $completed = $completed || ($refund['mode'] === 'overpayment' && $refund['status'] === 'completed'); }
        $invoice = array('invoice_id' => 'inv_123', 'project_id' => $this->scopeConflict ? 'prj_foreign' : 'prj_123',
            'is_test' => true, 'status' => 'paid_over', 'order' => array('external_id' => 'whmcs_42_0', 'amount' => '100', 'currency' => 'USD'),
            'payment' => array('paid' => '120', 'currency' => 'USDT', 'network' => 'TRC20'));
        if (!$this->omitSummary) {
            $invoice['refund_summary'] = array('can_refund_full' => true, 'can_refund_overpayment' => true,
                'full_refund_amount' => $completed ? '100' : '120', 'overpayment_refund_amount' => '20');
        }
        return new HttpResponse(200, json_encode($invoice), array());
    }
}

function whmcs_refund_fixture()
{
    $db = new WhmcsRefundDatabase();
    $store = new RefundIntentStore($db);
    $native = new WhmcsRefundNative($db);
    $server = new WhmcsRefundServer($db);
    $config = (object) array('capabilities' => array('invoice_refunds_v1'), 'refund_contract_version' => 1, 'refunds_enabled' => true, 'project_id' => 'prj_123');
    $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $server);
    $adapter = new RefundAdapter(array(), $store, static function ($environment) use ($client) {
        assertSameValue('sandbox', $environment, 'saved environment is used.'); return $client;
    }, $native, static function () use ($config) { return (array) $config; }, static function () {});
    return (object) compact('db', 'store', 'native', 'server', 'adapter', 'config');
}

function test_whmcs_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    $f = whmcs_refund_fixture();
    paymos_cms_ton_pin_contract($f->store, 'whmcs', $f->store->installationId(), '42');
}

function whmcs_refund_input($mode = 'full')
{
    return array('invoiceid' => 42, 'mode' => $mode, 'request_token' => RefundIntentStore::uuid());
}

function whmcs_refund_complete($f, RefundIntent $intent)
{
    $f->server->refunds[$intent->externalOrderId()]['status'] = 'completed';
    return $f->adapter->reconcile($intent->operationId());
}

function whmcs_refund_throws(callable $action, $reason)
{
    try { $action(); } catch (RuntimeException $error) { assertContainsValue($reason, $error->getMessage(), 'expected rejection.'); return; }
    throw new RuntimeException('Expected rejection: ' . $reason);
}

function test_whmcs_refund_over20_then_full100_projects_separate_cash_and_native_totals()
{
    $f = whmcs_refund_fixture();
    $over = $f->adapter->submit(whmcs_refund_input('overpayment'));
    assertSameValue('created', $over->cashState(), 'accepted is pending.');
    assertSameValue(0, count($f->native->commands), 'no native effects while pending.');
    whmcs_refund_complete($f, $over);
    $over = $f->store->getIntent($over->operationId());
    assertSameValue(null, $over->toArray()['projection']['nativeAmountMinor'], 'overpayment has no native amount.');
    assertSameValue('20', $over->toArray()['projection']['cryptoObservationAmount'], 'cash-only 20.');
    assertSameValue('Paid', $f->native->snapshot['invoice']['status'], 'invoice stays Paid.');
    assertSameValue(1, count($f->native->snapshot['accounts']), 'native refunded total remains zero.');
    $full = $f->adapter->submit(whmcs_refund_input());
    whmcs_refund_complete($f, $full);
    $full = $f->store->getIntent($full->operationId());
    assertSameValue('100', $full->observation()->principalDecimal(), 'server cash full100 after over20.');
    assertSameValue('10000', $full->toArray()['projection']['nativeAmountMinor'], 'full native original paid total.');
    assertSameValue('Refunded', $f->native->snapshot['invoice']['status'], 'fully returned invoice is Refunded.');
    assertSameValue(2, count($f->native->snapshot['accounts']), 'exactly one outgoing native transaction.');
    $command = $f->native->commands[0];
    assertSameValue('AddTransaction', $command[0], 'offline native financial entry.');
    assertSameValue('paymos_refund_' . $full->withdrawalId(), $command[1]['transid'], 'exact native marker.');
    assertSameValue(false, $command[1]['allowduplicatetransid'], 'native duplicate refusal.');
    assertSameValue(false, $command[1]['credit'], 'no store credit.');
    assertSameValue('skipped', $full->toArray()['effects']['confirmation:1'], 'no generic refund email.');
}

function test_whmcs_refund_pending_gateway_never_reports_native_success()
{
    $f = whmcs_refund_fixture();
    $input = whmcs_refund_input();
    $result = $f->adapter->nativeRefund($input + array('amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
    assertSameValue('error', $result['status'], 'WHMCS has no pending return.');
    assertSameValue('created', $result['rawdata']['cash_state'], 'operation is accepted.');
    assertSameValue(0, count($f->native->commands), 'pending creates no native transaction.');
}

function test_whmcs_refund_preflight_rejects_partial_manual_ambiguous_and_missing_capability_before_post()
{
    foreach (array('partial', 'manual', 'credit', 'capability', 'currency', 'mapping', 'total', 'scope', 'token') as $case) {
        $f = whmcs_refund_fixture(); $input = whmcs_refund_input();
        if ($case === 'partial') { $input['amount'] = '1.00'; }
        if ($case === 'manual') { $f->native->snapshot['accounts'][] = array('id' => 2); }
        if ($case === 'credit') { $f->native->snapshot['invoice']['credit'] = '1.00'; }
        if ($case === 'capability') { $f->config->capabilities = array(); }
        if ($case === 'currency') { $input['currency'] = 'EUR'; }
        if ($case === 'mapping') { $f->native->snapshot['mapping']['project_id'] = 'prj_other'; }
        if ($case === 'total') { $f->native->snapshot['invoice']['total'] = '99.00'; }
        if ($case === 'scope') { $f->server->scopeConflict = true; }
        if ($case === 'token') { unset($input['request_token']); }
        whmcs_refund_throws(static function () use ($f, $input) { $f->adapter->submit($input); }, 'refund_');
        assertSameValue(0, count($f->server->refunds), $case . ' must not POST money.');
    }
}

function test_whmcs_refund_unknown_restart_reuses_committed_key_even_when_new_gate_disabled()
{
    $f = whmcs_refund_fixture(); $input = whmcs_refund_input(); $f->server->loseResponses = 2;
    $intent = $f->adapter->submit($input);
    assertSameValue('unknown', $intent->cashState(), 'both lost responses leave durable unknown.');
    assertSameValue(null, $intent->withdrawalId(), 'binding cannot come from a lost response.');
    $f->config->refunds_enabled = false;
    $f->adapter = new RefundAdapter(array('mode' => 'live'), new RefundIntentStore($f->db), static function ($environment) use ($f) {
        assertSameValue('sandbox', $environment, 'mode switch cannot retarget recovery.');
        return new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $f->server);
    }, $f->native, static function () use ($f) { return (array) $f->config; }, static function () {});
    $replay = $f->adapter->submit($input);
    assertSameValue($intent->operationId(), $replay->operationId(), 'same durable operation survives restart.');
    assertSameValue(1, count($f->server->refunds), 'lost response never creates another refund.');
    whmcs_refund_complete($f, $replay);
    assertSameValue('Refunded', $f->native->snapshot['invoice']['status'], 'disabled new gate preserves recovery.');
}

function test_whmcs_refund_same_token_changed_mode_or_draft_and_new_unresolved_action_are_refused()
{
    $f = whmcs_refund_fixture(); $input = whmcs_refund_input(); $f->adapter->submit($input);
    foreach (array(array_merge($input, array('mode' => 'overpayment')), array_merge($input, array('amount' => '1')), whmcs_refund_input()) as $changed) {
        whmcs_refund_throws(static function () use ($f, $changed) { $f->adapter->submit($changed); }, 'refund_');
    }
    assertSameValue(1, count($f->server->refunds), 'all conflicts stop before POST.');
}

function test_whmcs_refund_crash_after_native_write_recovers_exact_transid_without_second_write()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input());
    $f->native->crashAfterWrite = true;
    assertSameValue('show_review', whmcs_refund_complete($f, $intent), 'lost native response is review.');
    assertSameValue(2, count($f->native->snapshot['accounts']), 'native commit survived module rollback.');
    assertSameValue('resume_recovery', $f->adapter->reconcile($intent->operationId()), 'exact row recovers module link.');
    assertSameValue(2, count($f->native->snapshot['accounts']), 'no duplicate native row.');
    assertSameValue(1, count(array_filter($f->native->commands, static function ($c) { return $c[0] === 'AddTransaction'; })), 'one financial call.');
}

function test_whmcs_refund_marker_conflict_never_repeats_financial_write()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input());
    whmcs_refund_complete($f, $intent); $f->native->markerConflict = true;
    assertSameValue('show_review', $f->adapter->reconcile($intent->operationId()), 'wrong marker amount is ambiguous.');
    assertSameValue(2, count($f->native->snapshot['accounts']), 'no new native write.');
}

function test_whmcs_refund_reorg_recompletion_preserves_native_marker_and_effects()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input()); whmcs_refund_complete($f, $intent);
    $before = $f->store->getIntent($intent->operationId())->toArray();
    $f->server->refunds[$intent->externalOrderId()]['status'] = 'signed';
    assertSameValue('show_review', $f->adapter->reconcile($intent->operationId()), 'cash rewind stays visible.');
    assertSameValue('Refunded', $f->native->snapshot['invoice']['status'], 'historical financial record remains.');
    $f->server->refunds[$intent->externalOrderId()]['status'] = 'completed'; $f->adapter->reconcile($intent->operationId());
    $after = $f->store->getIntent($intent->operationId())->toArray();
    assertSameValue($before['projection'], $after['projection'], 'same native document.');
    assertSameValue($before['effects'], $after['effects'], 'no repeated effects.');
    assertSameValue(2, count($f->native->commands), 'only original financial row and status command.');
}

function test_whmcs_refund_old_overpayment_rewind_never_overwrites_full_projection()
{
    $f = whmcs_refund_fixture(); $over = $f->adapter->submit(whmcs_refund_input('overpayment')); whmcs_refund_complete($f, $over);
    $full = $f->adapter->submit(whmcs_refund_input()); whmcs_refund_complete($f, $full);
    $before = $f->store->getIntent($full->operationId())->toArray();
    $f->server->refunds[$over->externalOrderId()]['status'] = 'signed'; $f->adapter->reconcile($over->operationId());
    assertSameValue($before, $f->store->getIntent($full->operationId())->toArray(), 'old callback changes only its own intent.');
    assertSameValue('Refunded', $f->native->snapshot['invoice']['status'], 'old overpayment cannot undo full accounting.');
}

function test_whmcs_refund_callback_uses_fresh_get_not_event_amount_and_checks_scope()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input('overpayment'));
    $wire = $f->server->refunds[$intent->externalOrderId()];
    $wire['type'] = 'refund'; $wire['amount'] = '999'; $wire['status'] = 'completed';
    $event = new WebhookEvent(array('event_type' => 'withdrawal.completed', 'data' => $wire));
    $f->adapter->callback($event, 'sandbox');
    assertSameValue(null, $f->store->getIntent($intent->operationId())->toArray()['projection'], 'forged Completed cannot project cash.');
    $f->server->refunds[$intent->externalOrderId()]['status'] = 'completed'; $f->adapter->callback($event, 'sandbox');
    assertSameValue('20', $f->store->getIntent($intent->operationId())->observation()->principalDecimal(), 'only GET money is used.');
    $wire['project_id'] = 'prj_other';
    whmcs_refund_throws(static function () use ($f, $wire) { $f->adapter->callback(new WebhookEvent(array('data' => $wire)), 'sandbox'); }, 'scope_conflict');
}

function test_whmcs_refund_store_binding_observation_and_effects_are_fenced()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input());
    $f->store->withPaymentLock('42', static function () use ($f, $intent) {
        assertSameValue('same', $f->store->bindWithdrawal($intent->operationId(), $intent->withdrawalId()), 'same binding is idempotent.');
        assertSameValue('conflict', $f->store->bindWithdrawal($intent->operationId(), 'wd_other'), 'write-once binding.');
        assertSameValue($intent->withdrawalId(), $f->store->getIntent($intent->operationId())->withdrawalId(), 'binding never overwritten.');
        assertSameValue('claimed', $f->store->claimEffect($intent->operationId(), 'mail', 1)->kind(), 'claim committed.');
        assertSameValue('already_started', $f->store->claimEffect($intent->operationId(), 'mail', 1)->kind(), 'lost mail ack never resends.');
        $f->store->recordEffectOutcome($intent->operationId(), 'mail', 1, 'unknown');
        assertSameValue('already_started', $f->store->claimEffect($intent->operationId(), 'mail', 1)->kind(), 'unknown cannot resend.');
    });
    $f->db->owned = false;
    whmcs_refund_throws(static function () use ($f) { $f->adapter->reconcile($f->store->forPayment('42')[0]->operationId()); }, 'lock_lost');
}

function test_whmcs_refund_pinned_principal_asset_recipient_and_native_manual_change_fail_closed()
{
    foreach (array('amount', 'currency', 'network', 'first_sender', 'manual') as $field) {
        $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input());
        $f->server->refunds[$intent->externalOrderId()]['status'] = 'completed';
        if ($field === 'manual') { $f->native->snapshot['accounts'][] = array('id' => 2); }
        else { $f->server->refunds[$intent->externalOrderId()][$field] = $field === 'amount' ? '121' : 'changed'; }
        assertSameValue('show_review', $f->adapter->reconcile($intent->operationId()), 'changed ' . $field . ' requires review.');
        assertSameValue(0, count($f->native->commands), 'no native financial effect.');
    }
}

function test_whmcs_refund_definitive_failure_requires_new_explicit_token()
{
    $f = whmcs_refund_fixture(); $input = whmcs_refund_input(); $f->server->refuse = true;
    $failed = $f->adapter->submit($input); assertSameValue('failed', $failed->cashState(), 'definitive server refusal.');
    $f->server->refuse = false;
    assertSameValue('failed', $f->adapter->submit($input)->cashState(), 'same intent stays failed.');
    assertSameValue(0, count($f->server->refunds), 'failed replay never sends new cash.');
    $new = $f->adapter->submit(whmcs_refund_input());
    assertTrueValue($new->operationId() !== $failed->operationId(), 'new explicit intent.');
    assertSameValue(1, count($f->server->refunds), 'only explicit new action sends money.');
}

function test_whmcs_refund_historical_and_unresolved_due_sweeps_are_bounded()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input());
    $created = $intent->toArray()['created_at'];
    assertSameValue(0, count($f->store->listDue(new DateTimeImmutable('@' . $created), 100, null)->items()), 'initial one-minute backoff.');
    $page = $f->store->listDue(new DateTimeImmutable('@' . ($created + 60)), 1, null);
    assertSameValue($intent->operationId(), $page->nextCursor(), 'bounded cursor.');
    assertSameValue(0, count($f->store->listDue(new DateTimeImmutable('@' . ($created + 60)), 1, $page->nextCursor())->items()), 'cursor progresses.');
    $f->store->withPaymentLock('42', static function () use ($f, $intent, $created) { $f->store->defer($intent->operationId(), $created + 86401); });
    assertSameValue('refund_unresolved_over_24h', $f->store->getIntent($intent->operationId())->toArray()['review_reason'], 'aged unresolved operation requires review.');
}

function test_whmcs_refund_schema_install_is_repeatable_and_preserves_history()
{
    $f = whmcs_refund_fixture();
    $f->store->install();
    $intent = $f->adapter->submit(whmcs_refund_input());
    $before = $f->store->getIntent($intent->operationId())->toArray();
    $f->store->install();
    assertSameValue($before, $f->store->getIntent($intent->operationId())->toArray(), 'installation must preserve existing operations.');
    whmcs_refund_throws(static function () use ($f) {
        $f->store->withPaymentLock('42', static function () use ($f) {
            $f->store->transaction(static function () use ($f) { $f->store->install(); });
        });
    }, 'schema_transaction_conflict');
}

function test_whmcs_refund_native_status_failure_retries_only_status_after_exact_marker()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input());
    $f->native->statusFailure = true;
    whmcs_refund_throws(static function () use ($f, $intent) { whmcs_refund_complete($f, $intent); }, 'native_status_unknown');
    $afterFailure = $f->store->getIntent($intent->operationId());
    assertTrueValue($afterFailure->projectionState() !== RefundStates::PROJECTION_APPLIED, 'failed UpdateInvoice must not leave projection_state applied while invoice is Paid.');
    assertSameValue(RefundStates::NATIVE_REVIEW, $afterFailure->projectionState(), 'failed UpdateInvoice moves intent to retryable native_review.');
    assertSameValue(RefundStates::REVIEW, $afterFailure->cashState(), 'failed UpdateInvoice moves cash state to review.');
    assertSameValue('native_projection_unknown', $afterFailure->toArray()['review_reason'], 'failed UpdateInvoice records retryable review reason.');
    assertSameValue('Paid', $f->native->snapshot['invoice']['status'], 'invoice remains Paid when UpdateInvoice fails.');
    $f->adapter->reconcile($intent->operationId());
    $afterRecovery = $f->store->getIntent($intent->operationId());
    assertSameValue(RefundStates::COMPLETED, $afterRecovery->cashState(), 'status retry restores completed cash state.');
    assertSameValue(RefundStates::PROJECTION_APPLIED, $afterRecovery->projectionState(), 'status retry marks projection applied once Refunded.');
    assertSameValue(2, count($f->native->snapshot['accounts']), 'status retry cannot repeat financial entry.');
    assertSameValue('Refunded', $f->native->snapshot['invoice']['status'], 'status recovers from exact outgoing row.');
}

function test_whmcs_refund_cash_projection_cannot_be_created_from_unverified_completion()
{
    $f = whmcs_refund_fixture(); $intent = $f->adapter->submit(whmcs_refund_input('overpayment'));
    $f->store->withPaymentLock('42', static function () use ($f, $intent) {
        $result = new NativeProjectionResult('exact_match', 'overpayment', 'fake_note', null, null, '20', 'USDT/TRC20');
        whmcs_refund_throws(static function () use ($f, $intent, $result) { $f->store->recordProjection($intent->operationId(), $result); }, 'projection_conflict');
    });
    assertSameValue(null, $f->store->getIntent($intent->operationId())->toArray()['projection'], 'unconfirmed cash cannot project.');
}

function test_whmcs_refund_unknown_token_without_admin_session_is_denied_before_database_or_http()
{
    $adapter = new RefundAdapter(array());
    $result = $adapter->nativeRefund(array('invoiceid' => 42, 'request_token' => RefundIntentStore::uuid(), 'amount' => '100.00'));
    assertSameValue('error', $result['status'], 'unprotected entry is refused.');
    assertContainsValue('refund_admin_access_denied', $result['rawdata']['reason'], 'authorization runs before storage or HTTP.');
}

function test_whmcs_refund_stale_connect_disabled_does_not_block_when_contract_valid()
{
    $f = whmcs_refund_fixture();
    $f->config->refunds_enabled = false;
    $intent = $f->adapter->submit(whmcs_refund_input('full'));
    assertSameValue(RefundStates::CREATED, $intent->cashState(), 'valid capability and contract v1 proceed despite stale refunds_enabled=false.');
}

function test_whmcs_refund_without_server_summary_allows_null_amounts_and_blocks_repeat_completed_overpayment()
{
    $f = whmcs_refund_fixture();
    $f->server->omitSummary = true;
    $method = new ReflectionMethod($f->adapter, 'invoiceSummary');
    if (\PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    $probe = new RefundIntent(array(
        'operation_id' => RefundIntentStore::uuid(),
        'request_token' => RefundIntentStore::uuid(),
        'installation_id' => $f->store->installationId(),
        'platform' => 'whmcs',
        'native_payment_key' => '42',
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'environment' => 'sandbox',
        'mode' => RefundStates::OVERPAYMENT,
    ));
    $fallback = $method->invoke($f->adapter, array('invoice_id' => 'inv_123', 'status' => 'paid_over',
        'payment' => array('paid' => '120', 'currency' => 'USDT', 'network' => 'TRC20')), $probe);
    assertSameValue(null, $fallback['full_refund_amount'], 'gross must not be treated as full refund balance.');
    assertSameValue(null, $fallback['overpayment_refund_amount'], 'gross must not be treated as overpayment refund balance.');
    assertSameValue(true, $fallback['can_refund_full'], 'paid_over enables full refund when summary is omitted.');
    assertSameValue(true, $fallback['can_refund_overpayment'], 'paid_over enables initial overpayment when summary is omitted.');

    $over = $f->adapter->submit(whmcs_refund_input('overpayment'));
    assertSameValue(RefundStates::CREATED, $over->cashState(), 'initial overpayment proceeds without refund_summary.');
    $f->server->refunds[$over->externalOrderId()]['status'] = 'completed';
    $f->adapter->reconcile($over->operationId());
    $postsBefore = count(array_filter($f->server->requests, static function ($r) { return $r['method'] === 'POST'; }));

    whmcs_refund_throws(static function () use ($f) {
        $f->adapter->submit(whmcs_refund_input('overpayment'));
    }, 'refund_mode_unavailable');
    $postsAfter = count(array_filter($f->server->requests, static function ($r) { return $r['method'] === 'POST'; }));
    assertSameValue($postsBefore, $postsAfter, 'completed overpayment blocks a second overpayment before POST when summary is omitted.');

    $full = $f->adapter->submit(whmcs_refund_input('full'));
    assertSameValue(RefundStates::CREATED, $full->cashState(), 'full refund remains eligible after completed overpayment when summary is omitted.');
}

function test_whmcs_refund_preflight_transient_failures_return_retry_and_deterministic_errors_preserve_code()
{
    foreach (array(429, 502, new RuntimeException('read timeout')) as $failure) {
        $f = whmcs_refund_fixture();
        $input = whmcs_refund_input('full');
        $draftMethod = new ReflectionMethod($f->adapter, 'draft');
        if (\PHP_VERSION_ID < 80100) {
            $draftMethod->setAccessible(true);
        }
        $draft = $draftMethod->invoke($f->adapter, $f->native->snapshot);
        $probe = new RefundIntent(array(
            'operation_id' => RefundIntentStore::uuid(),
            'request_token' => RefundIntentStore::uuid(),
            'installation_id' => $f->store->installationId(),
            'platform' => 'whmcs',
            'native_payment_key' => '42',
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'environment' => 'sandbox',
            'mode' => RefundStates::FULL,
            'full_draft' => $draft,
        ));
        $f->server->invoiceFailure = $failure;
        $reason = $f->store->withPaymentLock('42', static function () use ($f, $probe) {
            return $f->adapter->preflight($probe);
        });
        assertSameValue(\Paymos\Plugin\NativeRefundProjectorInterface::PREFLIGHT_RETRY, $reason, 'transient invoice read error returns PREFLIGHT_RETRY.');

        $f->server->invoiceFailure = $failure;
        whmcs_refund_throws(static function () use ($f, $input) {
            $f->adapter->submit($input);
        }, 'refund_preflight_unavailable');
        assertSameValue(0, count($f->db->intents), 'submit maps PREFLIGHT_RETRY to refund_preflight_unavailable without creating an intent.');
        assertSameValue(0, count($f->server->refunds), 'unavailable preflight sends no refund POST.');

        $recovered = $f->adapter->submit($input);
        assertSameValue(RefundStates::CREATED, $recovered->cashState(), 'submit succeeds after preflight read recovers.');

        $f2 = whmcs_refund_fixture();
        $savedToken = RefundIntentStore::uuid();
        $savedProbe = new RefundIntent(array(
            'operation_id' => RefundIntentStore::uuid(),
            'request_token' => $savedToken,
            'installation_id' => $f2->store->installationId(),
            'platform' => 'whmcs',
            'native_payment_key' => '42',
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'environment' => 'sandbox',
            'mode' => RefundStates::FULL,
            'full_draft' => $draft,
        ));
        $saved = $f2->store->withPaymentLock('42', static function () use ($f2, $savedProbe) {
            return $f2->store->createIntent($savedProbe);
        });
        $replayInput = whmcs_refund_input('full');
        $replayInput['request_token'] = $savedToken;
        $f2->server->invoiceFailure = $failure;
        $pending = $f2->adapter->submit($replayInput);
        assertSameValue(RefundStates::NOT_SUBMITTED, $pending->cashState(), 'coordinator keeps NOT_SUBMITTED on PREFLIGHT_RETRY.');
        assertSameValue(0, count($f2->server->refunds), 'no POST while preflight read is unavailable.');
        $done = $f2->adapter->submit($replayInput);
        assertSameValue(RefundStates::CREATED, $done->cashState(), 'submit replay advances NOT_SUBMITTED once preflight recovers.');
    }

    foreach (array(404 => 'invoice_not_found', 400 => 'refund_denied') as $status => $expectedCode) {
        $f = whmcs_refund_fixture();
        $input = whmcs_refund_input('full');
        $draftMethod = new ReflectionMethod($f->adapter, 'draft');
        if (\PHP_VERSION_ID < 80100) {
            $draftMethod->setAccessible(true);
        }
        $draft = $draftMethod->invoke($f->adapter, $f->native->snapshot);
        $f->server->invoiceFailure = $status;
        whmcs_refund_throws(static function () use ($f, $input) {
            $f->adapter->submit($input);
        }, $expectedCode);
        assertSameValue(0, count($f->db->intents), 'deterministic API refusal does not create an intent on submit.');

        $savedToken = RefundIntentStore::uuid();
        $saved = $f->store->withPaymentLock('42', static function () use ($f, $draft, $savedToken) {
            return $f->store->createIntent(new RefundIntent(array(
                'operation_id' => RefundIntentStore::uuid(),
                'request_token' => $savedToken,
                'installation_id' => $f->store->installationId(),
                'platform' => 'whmcs',
                'native_payment_key' => '42',
                'invoice_id' => 'inv_123',
                'project_id' => 'prj_123',
                'environment' => 'sandbox',
                'mode' => RefundStates::FULL,
                'full_draft' => $draft,
            )));
        });
        $replayInput = whmcs_refund_input('full');
        $replayInput['request_token'] = $savedToken;
        $f->server->invoiceFailure = $status;
        $after = $f->adapter->submit($replayInput);
        assertSameValue(RefundStates::REVIEW, $after->cashState(), 'deterministic API refusal moves saved intent to review.');
        assertSameValue($expectedCode, $after->toArray()['review_reason'], 'review preserves API errorCode.');
    }
}

function test_whmcs_refund_inspect_accepts_whmcs91_credit_note_and_rejects_tampered_third_row()
{
    $f = whmcs_refund_fixture();
    $intent = $f->adapter->submit(whmcs_refund_input());
    whmcs_refund_complete($f, $intent);

    $creditNote = array(
        'id' => 3,
        'userid' => 77,
        'invoiceid' => 42,
        'gateway' => '',
        'transid' => '',
        'amountin' => '100.00',
        'amountout' => '0.00',
        'fees' => '0.00',
        'type' => 'invoice_billing_adjustment_credit',
        'billingnoteid' => 15,
    );
    $f->native->snapshot['accounts'][] = $creditNote;
    $f->native->snapshot['invoice']['status'] = 'Refunded';

    $inspect = static function () use ($f, $intent) {
        return $f->store->withPaymentLock('42', static function () use ($f, $intent) {
            return $f->adapter->inspect($intent->operationId());
        });
    };

    $exact = $inspect();
    assertSameValue(NativeProjectionResult::EXACT_MATCH, $exact->kind(), 'valid WHMCS 9.1 3-row Credit Note projection must match.');
    assertSameValue('2', $exact->nativeId(), 'exact match retains the outgoing refund transaction id.');
    assertSameValue('10000', $exact->nativeAmountMinor(), 'exact match retains minor amount.');
    assertSameValue('USD', $exact->nativeCurrency(), 'exact match retains invoice currency.');

    // Stubs without a fees key on the Credit Note row must also pass safely.
    $withoutFees = $creditNote;
    unset($withoutFees['fees']);
    $f->native->snapshot['accounts'][2] = $withoutFees;
    assertSameValue(NativeProjectionResult::EXACT_MATCH, $inspect()->kind(), '3-row Credit Note without fees key defaults fees to 0.');

    // 3-row WHMCS 9.1 ledger must reject non-Refunded invoice status.
    $f->native->snapshot['accounts'][2] = $creditNote;
    foreach (array('Paid', 'Unpaid') as $badStatus) {
        $f->native->snapshot['invoice']['status'] = $badStatus;
        $notRefunded = $inspect();
        assertSameValue(NativeProjectionResult::AMBIGUOUS, $notRefunded->kind(), '3-row ledger with status ' . $badStatus . ' must be ambiguous.');
        assertSameValue('refund_native_status_not_refunded', $notRefunded->reason(), '3-row ledger with status ' . $badStatus . ' must report status not refunded.');
    }

    // Tampered 3rd row must fail closed with refund_native_marker_conflict.
    $f->native->snapshot['invoice']['status'] = 'Refunded';
    $tampers = array(
        'type' => array('type' => 'manual_credit'),
        'billingnoteid' => array('billingnoteid' => 0),
        'gateway' => array('gateway' => 'paymosgateway'),
        'transid' => array('transid' => 'fake_tx'),
        'invoiceid' => array('invoiceid' => 99),
        'userid' => array('userid' => 88),
        'amountin' => array('amountin' => '50.00'),
        'amountout' => array('amountout' => '1.00'),
        'fees' => array('fees' => '1.00'),
    );
    foreach ($tampers as $label => $override) {
        $f->native->snapshot['accounts'][2] = array_replace($creditNote, $override);
        $tampered = $inspect();
        assertSameValue(NativeProjectionResult::AMBIGUOUS, $tampered->kind(), 'tampered Credit Note (' . $label . ') must be ambiguous.');
        assertSameValue('refund_native_marker_conflict', $tampered->reason(), 'tampered Credit Note (' . $label . ') must report marker conflict.');
    }

    // Duplicate 4th Credit Note row must fail closed.
    $f->native->snapshot['accounts'][2] = $creditNote;
    $f->native->snapshot['accounts'][] = array_replace($creditNote, array('id' => 4));
    $duplicate = $inspect();
    assertSameValue(NativeProjectionResult::AMBIGUOUS, $duplicate->kind(), '4 account rows must be ambiguous.');
    assertSameValue('refund_native_marker_conflict', $duplicate->reason(), '4 account rows must report marker conflict.');
}

function test_whmcs_refund_panel_hides_irrelevant_buttons_localizes_and_surfaces_errors()
{
    $hadLanguage = isset($_SESSION['Language']);
    $previousLanguage = $hadLanguage ? $_SESSION['Language'] : null;
    $hadAdminId = isset($_SESSION['adminid']);
    $previousAdminId = $hadAdminId ? $_SESSION['adminid'] : null;

    try {
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['Language'], $_SESSION['adminid']);
        }
        if (class_exists('FakeWhmcsCapsuleState')) {
            FakeWhmcsCapsuleState::reset();
        }
        if (class_exists('WHMCS\\User\\Admin') && method_exists('WHMCS\\User\\Admin', 'reset')) {
            \WHMCS\User\Admin::reset();
        }

        // 1) Unpaid invoice shows no refund buttons.
        $f = whmcs_refund_fixture();
        $f->native->snapshot['invoice']['status'] = 'Unpaid';
        $unpaidHtml = $f->adapter->panel(42);
        assertContainsValue('id="paymos-refunds"', $unpaidHtml, 'panel container renders for Unpaid invoice.');
        assertFalseValue(strpos($unpaidHtml, 'data-mode="full"') !== false, 'Unpaid invoice must hide full button.');
        assertFalseValue(strpos($unpaidHtml, 'data-mode="overpayment"') !== false, 'Unpaid invoice must hide overpayment button.');

        // 2) Paid + mapping=paid shows only full (no overpayment).
        $f->native->snapshot['invoice']['status'] = 'Paid';
        $f->native->snapshot['mapping']['status'] = 'paid';
        $paidHtml = $f->adapter->panel(42);
        assertContainsValue('data-mode="full"', $paidHtml, 'Paid invoice shows full button.');
        assertFalseValue(strpos($paidHtml, 'data-mode="overpayment"') !== false, 'Exact paid invoice must hide overpayment button.');

        // 3) Paid + mapping=paid_over shows both buttons and XSS-safe JS error handling.
        $f->native->snapshot['mapping']['status'] = 'paid_over';
        $paidOverHtml = $f->adapter->panel(42);
        assertContainsValue('data-mode="full"', $paidOverHtml, 'paid_over shows full button.');
        assertContainsValue('data-mode="overpayment"', $paidOverHtml, 'paid_over shows overpayment button.');
        assertContainsValue('$("#paymos-refunds .paymos-refund-error").remove()', $paidOverHtml, 'panel JS clears previous errors before POST.');
        assertContainsValue('xhr.responseJSON', $paidOverHtml, 'panel JS reads xhr.responseJSON on fail.');
        assertContainsValue('JSON.parse(xhr.responseText)', $paidOverHtml, 'panel JS falls back to parsing xhr.responseText.');
        assertContainsValue('paymos-refund-error', $paidOverHtml, 'panel JS renders visible error banner.');
        assertContainsValue('.text(msg)', $paidOverHtml, 'panel JS inserts error message safely with .text().');
        assertFalseValue(strpos($paidOverHtml, '.html(') !== false, 'panel JS must not render error messages via .html().');

        // 4) After completed overpayment, overpayment button disappears while full remains and history renders.
        $over = $f->adapter->submit(whmcs_refund_input('overpayment'));
        whmcs_refund_complete($f, $over);
        $afterOverHtml = $f->adapter->panel(42);
        assertContainsValue($over->operationId(), $afterOverHtml, 'completed overpayment stays in panel history.');
        assertContainsValue('data-mode="full"', $afterOverHtml, 'full button remains available after completed overpayment.');
        assertFalseValue(strpos($afterOverHtml, 'data-mode="overpayment"') !== false, 'completed overpayment must hide overpayment button.');

        // 5) Missing snapshot hides all buttons while still rendering existing intent history.
        $missingAdapter = new RefundAdapter(array(), $f->store, null, static function ($command) {
            if ($command === 'snapshot') {
                throw new RuntimeException('refund_payment_mapping_missing');
            }
            return array();
        }, static function () use ($f) { return (array) $f->config; }, static function () {});
        $missingHtml = $missingAdapter->panel(42);
        assertContainsValue($over->operationId(), $missingHtml, 'existing history renders even when snapshot is missing.');
        assertFalseValue(strpos($missingHtml, 'data-mode="full"') !== false, 'missing snapshot hides full button.');
        assertFalseValue(strpos($missingHtml, 'data-mode="overpayment"') !== false, 'missing snapshot hides overpayment button.');

        // 6) Pending full intent reuses its durable request_token on the full button.
        $fullInput = whmcs_refund_input('full');
        $full = $f->adapter->submit($fullInput);
        $pendingFullHtml = $f->adapter->panel(42);
        assertContainsValue('data-mode="full" data-token="' . $fullInput['request_token'] . '"', $pendingFullHtml, 'pending full intent reuses its durable token.');

        // 7) Completed full hides both buttons (both on Refunded and even if invoice status were still Paid).
        whmcs_refund_complete($f, $full);
        $refundedHtml = $f->adapter->panel(42);
        assertContainsValue($full->operationId(), $refundedHtml, 'completed full stays in panel history.');
        assertFalseValue(strpos($refundedHtml, 'data-mode="full"') !== false, 'Refunded invoice must hide full button.');
        assertFalseValue(strpos($refundedHtml, 'data-mode="overpayment"') !== false, 'Refunded invoice must hide overpayment button.');

        $f->native->snapshot['invoice']['status'] = 'Paid';
        $completedFullPaidHtml = $f->adapter->panel(42);
        assertFalseValue(strpos($completedFullPaidHtml, 'data-mode="full"') !== false, 'completed full intent hides full button even if status is still Paid.');
        assertFalseValue(strpos($completedFullPaidHtml, 'data-mode="overpayment"') !== false, 'completed full intent hides overpayment button.');

        // 8) Russian localization of panel title, all 6 field labels, buttons, and fallback error string.
        $fRu = whmcs_refund_fixture();
        $fRu->adapter->submit(whmcs_refund_input('overpayment'));
        $_SESSION['Language'] = 'russian';
        $ruHtml = $fRu->adapter->panel(42);
        assertContainsValue('Возвраты Paymos', $ruHtml, 'Russian panel title must be localized.');
        assertContainsValue('Операция: <code>', $ruHtml, 'Russian Operation label must be localized.');
        assertContainsValue('Тип: <code>', $ruHtml, 'Russian Type label must be localized.');
        assertContainsValue('Статус выплаты: <code>', $ruHtml, 'Russian Cash status label must be localized.');
        assertContainsValue('Запись в WHMCS: <code>', $ruHtml, 'Russian WHMCS record label must be localized.');
        assertContainsValue('Причина проверки: <code>', $ruHtml, 'Russian Review reason label must be localized.');
        assertContainsValue('Сумма: <code>', $ruHtml, 'Russian Amount label must be localized.');
        assertContainsValue('Полный возврат', $ruHtml, 'Russian full button must be localized.');
        assertContainsValue('Вернуть переплату', $ruHtml, 'Russian overpayment button must be localized.');
        assertContainsValue('Не удалось оформить возврат.', $ruHtml, 'Russian fallback error must be localized.');
    } finally {
        if ($hadLanguage) {
            $_SESSION['Language'] = $previousLanguage;
        } elseif (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['Language']);
        }
        if ($hadAdminId) {
            $_SESSION['adminid'] = $previousAdminId;
        } elseif (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['adminid']);
        }
        if (class_exists('FakeWhmcsCapsuleState')) {
            FakeWhmcsCapsuleState::reset();
        }
        if (class_exists('WHMCS\\User\\Admin') && method_exists('WHMCS\\User\\Admin', 'reset')) {
            \WHMCS\User\Admin::reset();
        }
    }
}

function test_whmcs_refund_native_entry_logs_failure_reason_when_cash_failed()
{
    $hadLanguage = isset($_SESSION['Language']);
    $previousLanguage = $hadLanguage ? $_SESSION['Language'] : null;
    $hadAdminId = isset($_SESSION['adminid']);
    $previousAdminId = $hadAdminId ? $_SESSION['adminid'] : null;

    try {
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['Language'], $_SESSION['adminid']);
        }
        if (class_exists('FakeWhmcsCapsuleState')) {
            FakeWhmcsCapsuleState::reset();
        }
        if (class_exists('WHMCS\\User\\Admin') && method_exists('WHMCS\\User\\Admin', 'reset')) {
            \WHMCS\User\Admin::reset();
        }

        // 1) cash_state = failed
        $f = whmcs_refund_fixture();
        $f->server->refuse = true;
        $failed = $f->adapter->nativeRefund(whmcs_refund_input() + array('amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
        assertSameValue('error', $failed['status'], 'failed native refund returns error.');
        assertSameValue(RefundStates::FAILED, $failed['rawdata']['cash_state'], 'cash_state is failed.');
        assertSameValue('refund_not_eligible', $failed['rawdata']['reason'], 'failed native refund includes failure reason.');
        assertContainsValue('Paymos refund failed.', $failed['rawdata']['message'], 'failed native refund logs failure message.');
        assertFalseValue(strpos($failed['rawdata']['message'], 'in progress') !== false, 'failed native refund must not report refund in progress.');

        // 2) cash_state = cancelled
        $fCancelled = whmcs_refund_fixture();
        $cancelledInput = whmcs_refund_input();
        $intentCancelled = $fCancelled->adapter->submit($cancelledInput);
        $fCancelled->server->refunds[$intentCancelled->externalOrderId()]['status'] = 'cancelled';
        $cancelled = $fCancelled->adapter->nativeRefund($cancelledInput + array('amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
        assertSameValue('error', $cancelled['status'], 'cancelled native refund returns error.');
        assertSameValue(RefundStates::CANCELLED, $cancelled['rawdata']['cash_state'], 'cash_state is cancelled.');
        assertContainsValue('Paymos refund failed.', $cancelled['rawdata']['message'], 'cancelled native refund logs failure message.');
        assertTrueValue(array_key_exists('reason', $cancelled['rawdata']), 'cancelled native refund exposes reason key.');

        // 3) cash_state = review
        $fReview = whmcs_refund_fixture();
        $reviewInput = whmcs_refund_input();
        $intentReview = $fReview->adapter->submit($reviewInput);
        $fReview->server->refunds[$intentReview->externalOrderId()]['amount'] = '121';
        $review = $fReview->adapter->nativeRefund($reviewInput + array('amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
        assertSameValue('error', $review['status'], 'review native refund returns error.');
        assertSameValue(RefundStates::REVIEW, $review['rawdata']['cash_state'], 'cash_state is review.');
        assertSameValue('refund_binding_conflict', $review['rawdata']['reason'], 'review native refund logs review_reason.');
        assertContainsValue('Paymos refund failed.', $review['rawdata']['message'], 'review native refund logs failure message.');

        // 4) Russian localization for both failed and in-progress responses
        $_SESSION['Language'] = 'russian';
        $fRuFailed = whmcs_refund_fixture();
        $fRuFailed->server->refuse = true;
        $ruFailed = $fRuFailed->adapter->nativeRefund(whmcs_refund_input() + array('amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
        assertContainsValue('Возврат через Paymos не прошёл.', $ruFailed['rawdata']['message'], 'Russian admin receives localized failure message.');
        assertSameValue('refund_not_eligible', $ruFailed['rawdata']['reason'], 'Russian failed response preserves machine reason.');

        $fRuPending = whmcs_refund_fixture();
        $ruPending = $fRuPending->adapter->nativeRefund(whmcs_refund_input() + array('amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
        assertContainsValue('В WHMCS нет статуса', $ruPending['rawdata']['message'], 'Russian admin receives localized in-progress message.');
    } finally {
        if ($hadLanguage) {
            $_SESSION['Language'] = $previousLanguage;
        } elseif (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['Language']);
        }
        if ($hadAdminId) {
            $_SESSION['adminid'] = $previousAdminId;
        } elseif (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['adminid']);
        }
        if (class_exists('FakeWhmcsCapsuleState')) {
            FakeWhmcsCapsuleState::reset();
        }
        if (class_exists('WHMCS\\User\\Admin') && method_exists('WHMCS\\User\\Admin', 'reset')) {
            \WHMCS\User\Admin::reset();
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $count = 0;
    foreach (get_defined_functions()['user'] as $function) {
        if (strpos($function, 'test_whmcs_refund_') !== 0) { continue; }
        $function(); $count++; echo 'PASS ' . $function . PHP_EOL;
    }
    echo 'OK ' . $count . ' refund unit tests (WHMCS/DB doubles)' . PHP_EOL;
}
