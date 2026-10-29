<?php

declare(strict_types=1);

namespace {
    function add_hook($name, $priority, $callback)
    {
        $GLOBALS['refund_hooks'][$name][$priority] = $callback;
    }

    function checkPermission($permission, $return = false)
    {
        return $permission === 'Refund Invoice Payments' && !empty($GLOBALS['refund_permission']);
    }

    function check_token($name = 'token', $form = '')
    {
        return $name === 'WHMCS.admin.default' && !empty($GLOBALS['refund_csrf']);
    }

    function getGatewayVariables($name)
    {
        return $GLOBALS['refund_gateway_params'];
    }

    require_once dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/RefundAdapterTest.php';
}

namespace Paymos\Http {
    // Isolate the default transport at the process boundary; no network runs.
    final class CurlTransport implements TransportInterface
    {
        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            return $GLOBALS['refund_fixture']->server->request($method, $url, $headers, $body, $timeoutSeconds);
        }
    }
}

namespace WHMCS\Database {
    final class Capsule
    {
        public static $settings = array();

        public static function table($table)
        {
            if ($table !== 'tblconfiguration') { throw new \RuntimeException('Unexpected Capsule table.'); }
            return new \RefundSettingsQuery();
        }

        public static function schema()
        {
            return new \RefundExistingSchema();
        }

        public static function connection()
        {
            return new \RefundEntryConnection();
        }
    }
}

namespace {
    use Paymos\Plugin\CredentialSet;
    use PaymosWhmcs\CallbackProcessor;
    use PaymosWhmcs\Config;
    use PaymosWhmcs\CredentialStore;
    use PaymosWhmcs\InMemoryEventStore;
    use PaymosWhmcs\InMemoryInvoiceStore;
    use WHMCS\Database\Capsule;

    final class RefundSettingsQuery
    {
        private $setting;

        public function where($column, $value) { $this->setting = $value; return $this; }
        public function value($column) { return Capsule::$settings[$this->setting] ?? null; }
        public function delete() { unset(Capsule::$settings[$this->setting]); }
        public function updateOrInsert(array $where, array $values)
        {
            Capsule::$settings[$where['setting']] = $values['value'];
        }
    }

    final class RefundExistingSchema
    {
        public function hasTable($table) { return true; }
        public function hasColumn($table, $column) { return true; }
    }

    final class RefundEntryConnection
    {
        public function getPdo() { return new RefundEntryDatabase(); }
    }

    final class RefundEntryDatabase
    {
        public function inTransaction() { return $GLOBALS['refund_fixture']->db->inTransaction(); }
        public function beginTransaction() { return $GLOBALS['refund_fixture']->db->beginTransaction(); }
        public function commit() { return $GLOBALS['refund_fixture']->db->commit(); }
        public function rollBack() { return $GLOBALS['refund_fixture']->db->rollBack(); }
        public function prepare($sql)
        {
            $sql = preg_replace('/\s+/', ' ', trim($sql));
            if (strpos($sql, 'SELECT GET_LOCK') === 0 && !empty($GLOBALS['refund_lock_busy'])) {
                return new RefundBusyStatement();
            }
            if (strpos($sql, 'SELECT i.*, c.code AS currency') === 0
                || strpos($sql, 'SELECT * FROM mod_paymos_invoices') === 0
                || strpos($sql, 'SELECT * FROM tblaccounts') === 0) {
                return new RefundEntryRows($sql);
            }
            return $GLOBALS['refund_fixture']->db->prepare($sql);
        }
    }

    final class RefundBusyStatement
    {
        public function execute(array $values) { return true; }
        public function fetchColumn() { return 0; }
    }

    final class RefundEntryRows
    {
        private $sql;
        private $rows;

        public function __construct($sql) { $this->sql = $sql; }
        public function fetchAll($mode) { return $this->rows; }
        public function execute(array $values)
        {
            $snapshot = $GLOBALS['refund_fixture']->native->snapshot;
            if (strpos($this->sql, 'SELECT i.*') === 0) {
                $this->rows = (int) $values[0] === (int) $snapshot['invoice']['id']
                    ? array($snapshot['invoice'] + array('currency' => $snapshot['currency'])) : array();
            } elseif (strpos($this->sql, 'SELECT * FROM mod_paymos_invoices') === 0) {
                $this->rows = (int) $values[0] === (int) $snapshot['invoice']['id'] ? array($snapshot['mapping']) : array();
            } elseif (strpos($this->sql, 'WHERE transid = ?') !== false) {
                $this->rows = array_values(array_filter($snapshot['accounts'], static function ($row) use ($values) {
                    return $row['transid'] === $values[0];
                }));
            } else {
                $this->rows = array_values(array_filter($snapshot['accounts'], static function ($row) use ($values) {
                    return (int) $row['invoiceid'] === (int) $values[0];
                }));
            }
            return true;
        }
    }

    function refund_entry_credentials(array $overrides = array())
    {
        return array('sandbox' => array_replace(array('api_key' => 'pk_test_entry', 'api_secret' => 'sk_test_entry',
            'project_id' => 'prj_123', 'webhook_secret' => 'whsec_entry', 'base_url' => 'https://api.paymos.io',
            'capabilities' => array('invoice_refunds_v1'), 'refund_contract_version' => 1, 'refunds_enabled' => true), $overrides));
    }

    function refund_entry_save(array $overrides = array())
    {
        CredentialStore::saveCredentials(refund_entry_credentials($overrides));
        Config::resetForTests();
    }

    function refund_entry_callback($id, array $data, ?InMemoryEventStore $events = null)
    {
        $events = $events ?: new InMemoryEventStore();
        $whmcs = new FakeWhmcsAdapter();
        $body = json_encode(array('event_id' => $id, 'event_type' => 'withdrawal.completed',
            'version' => 1, 'occurred_at' => time(), 'data' => $data));
        $processor = new CallbackProcessor($whmcs, new InMemoryInvoiceStore(), $events);
        $result = $processor->handle($body, whmcs_signed_header('whsec_entry', $body, time()), $GLOBALS['refund_gateway_params']);
        assertSameValue(0, count($whmcs->payments), 'refund delivery must not record an invoice payment.');
        return $result;
    }

    function refund_entry_post_count($f)
    {
        return count(array_filter($f->server->requests, static function ($request) { return $request['method'] === 'POST'; }));
    }

    $GLOBALS['refund_fixture'] = $f = whmcs_refund_fixture();
    $GLOBALS['refund_permission'] = true;
    $GLOBALS['refund_csrf'] = true;
    $GLOBALS['refund_gateway_params'] = array('type' => 'Invoices', 'mode' => 'sandbox', 'paymentmethod' => 'paymosgateway');
    $GLOBALS['cc_encryption_hash'] = 'unit-installation-encryption-key';
    $GLOBALS['paymos_whmcs_local_api'] = $f->native;
    $_SESSION['adminid'] = 1;
    refund_entry_save();

    require_once PAYMOS_WHMCS_PLUGIN_DIR . 'modules/gateways/paymosgateway.php';
    require_once PAYMOS_WHMCS_PLUGIN_DIR . 'includes/hooks/paymosgateway_refunds.php';
    require_once PAYMOS_WHMCS_PLUGIN_DIR . 'includes/hooks/paymosgateway_reconcile.php';

    $scenario = $argv[1] ?? '';
    if ($scenario === 'capability') {
        $saved = CredentialStore::loadCredentials();
        assertTrueValue(CredentialSet::hasCapability($saved['sandbox'], 'invoice_refunds_v1'), 'Connect capability survives encryption.');
        assertTrueValue(CredentialSet::refundsEnabled($saved['sandbox']), 'Connect enablement survives encryption.');
        assertSameValue(1, CredentialStore::refundConfig('sandbox')['refund_contract_version'], 'contract version survives encryption.');
        assertSameValue(array(), CredentialStore::refundConfig('live')['capabilities'], 'capability cannot cross environments.');
        assertFalseValue(strpos(implode('', Capsule::$settings), 'pk_test_entry') !== false, 'credentials are encrypted at rest.');
        foreach (array(array('capabilities' => array()), array('refund_contract_version' => '1'), array('refunds_enabled' => 'true')) as $changes) {
            refund_entry_save($changes);
            $result = paymosgateway_refund(array('invoiceid' => 42, 'request_token' => PaymosWhmcs\RefundIntentStore::uuid(),
                'amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
            assertSameValue('error', $result['status'], 'unsupported entry fails closed.');
            assertSameValue('refund_reconnect_required', $result['rawdata']['reason'], 'saved handshake is mandatory.');
            assertSameValue(0, count($f->server->requests), 'entry rejects before all HTTP.');
            assertSameValue(0, count($f->db->intents), 'rejected entry does not persist an intent.');
        }
    } elseif ($scenario === 'unbound') {
        $data = array('type' => 'refund', 'withdrawal_id' => 'wd_external', 'invoice_id' => 'inv_123', 'project_id' => 'prj_123',
            'is_test' => true, 'external_order_id' => 'foreign_refund', 'mode' => 'full');
        $events = new InMemoryEventStore();
        $first = refund_entry_callback('evt_unbound', $data, $events);
        $second = refund_entry_callback('evt_unbound', $data, $events);
        assertSameValue(409, $first->statusCode(), 'unbound delivery requires manual review.');
        assertSameValue(409, $second->statusCode(), 'unbound delivery is released for retry.');
        assertFalseValue($second->isDuplicate(), 'unbound event must not be committed.');
        assertSameValue(0, count($f->server->requests), 'unbound delivery performs no HTTP.');
        assertSameValue(0, count($f->db->intents), 'unbound delivery cannot create an intent.');
        assertSameValue(0, count($f->native->commands), 'unbound delivery cannot initiate native money.');
        $ordinary = refund_entry_callback('evt_payout', array('type' => 'ordinary', 'withdrawal_id' => 'wd_payout', 'is_test' => true));
        assertSameValue(200, $ordinary->statusCode(), 'ordinary payouts retain their existing ignored behavior.');
    } elseif ($scenario === 'lifecycle') {
        $overResult = paymosgateway_refund_action(array('invoiceid' => 42, 'paymos_refund_action' => 'overpayment',
            'request_token' => PaymosWhmcs\RefundIntentStore::uuid()));
        $over = $f->store->getIntent($overResult['operation_id']);
        assertSameValue('created', $overResult['cash_state'], 'managed entry returns pending.');
        assertSameValue(0, count($f->native->commands), 'pending has no native effects.');
        $f->server->refunds[$over->externalOrderId()]['status'] = 'completed';
        refund_entry_save(array('refunds_enabled' => false));
        $data = $f->server->refunds[$over->externalOrderId()] + array('type' => 'refund');
        $events = new InMemoryEventStore();
        $GLOBALS['refund_lock_busy'] = true;
        $requests = count($f->server->requests);
        assertSameValue(409, refund_entry_callback('evt_over_completed', $data, $events)->statusCode(), 'busy payment lock requires retry.');
        assertSameValue($requests, count($f->server->requests), 'busy payment lock performs no HTTP.');
        $GLOBALS['refund_lock_busy'] = false;
        $completed = refund_entry_callback('evt_over_completed', $data, $events);
        assertSameValue(200, $completed->statusCode(), 'bound callback works while new refunds are disabled.');
        assertFalseValue($completed->isDuplicate(), 'busy callback releases only its own event reservation.');
        $over = $f->store->getIntent($over->operationId());
        assertSameValue('20', $over->toArray()['projection']['cryptoObservationAmount'], 'overpayment records cash20.');
        assertSameValue(null, $over->toArray()['projection']['nativeAmountMinor'], 'overpayment has no native refund amount.');
        assertSameValue('Paid', $f->native->snapshot['invoice']['status'], 'overpayment leaves the invoice Paid.');
        assertSameValue(1, count($f->native->snapshot['accounts']), 'overpayment leaves native refunded total zero.');
        refund_entry_save();
        $_POST['paymos_refund_token'] = PaymosWhmcs\RefundIntentStore::uuid();
        $fullResult = paymosgateway_refund(array('invoiceid' => 42, 'amount' => '100.00', 'currency' => 'USD', 'transid' => 'paymos_inv_123_evt_paid'));
        assertSameValue('error', $fullResult['status'], 'native entry must prevent WHMCS from booking a second refund.');
        assertSameValue('created', $fullResult['rawdata']['cash_state'], 'native entry accepts full refund as pending.');
        $full = $f->store->getIntent($fullResult['rawdata']['operation_id']);
        assertSameValue($_POST['paymos_refund_token'], $full->toArray()['request_token'], 'native entry consumes the panel token.');
        assertSameValue(2, refund_entry_post_count($f), 'one POST per explicit action.');
        $f->server->refunds[$full->externalOrderId()]['status'] = 'completed';
        $f->db->intents[$full->operationId()]['next_check'] = 0;
        refund_entry_save(array('refunds_enabled' => false));
        call_user_func($GLOBALS['refund_hooks']['AfterCronJob'][2]);
        $full = $f->store->getIntent($full->operationId());
        assertSameValue('100', $full->observation()->principalDecimal(), 'server returns full cash100 after over20.');
        assertSameValue('10000', $full->toArray()['projection']['nativeAmountMinor'], 'one full native100 is projected.');
        assertSameValue('Refunded', $f->native->snapshot['invoice']['status'], 'cron finishes the native status.');
        assertSameValue(2, count($f->native->snapshot['accounts']), 'exactly one native outgoing transaction.');
        $f->db->intents[$full->operationId()]['next_check'] = 0;
        call_user_func($GLOBALS['refund_hooks']['AfterCronJob'][2]);
        $data = $f->server->refunds[$full->externalOrderId()] + array('type' => 'refund');
        assertSameValue(200, refund_entry_callback('evt_full_completed', $data)->statusCode(), 'callback after cron is idempotent.');
        assertSameValue(2, count($f->native->snapshot['accounts']), 'replayed completion cannot duplicate the native document.');
        assertSameValue(2, refund_entry_post_count($f), 'callback and cron do not initiate another refund.');
    } elseif ($scenario === 'admin') {
        $panel = $GLOBALS['refund_hooks']['AdminInvoicesControlsOutput'][1];
        $vars = array('paymentmethod' => 'paymosgateway', 'invoiceid' => 42);
        $GLOBALS['refund_csrf'] = false;
        $html = $panel($vars);
        assertContainsValue('id="paymos-refunds"', $html, 'GET renders the protected panel without a CSRF POST.');
        assertContainsValue('name:"paymos_refund_token"', $html, 'panel binds a token to native forms.');
        assertContainsValue('data-mode="overpayment"', $html, 'managed overpayment action is present.');
        whmcs_refund_throws(static function () {
            paymosgateway_refund_action(array('invoiceid' => 42, 'paymos_refund_action' => 'overpayment',
                'request_token' => PaymosWhmcs\RefundIntentStore::uuid()));
        }, 'refund_admin_access_denied');
        $GLOBALS['refund_csrf'] = true;
        $GLOBALS['refund_permission'] = false;
        assertSameValue('', $panel($vars), 'unauthorized admin cannot see the panel.');
        $result = paymosgateway_refund(array('invoiceid' => 42, 'request_token' => PaymosWhmcs\RefundIntentStore::uuid()));
        assertSameValue('refund_admin_access_denied', $result['rawdata']['reason'], 'native entry checks refund permission.');
        assertSameValue(0, count($f->server->requests), 'denied admin requests perform no HTTP.');
        assertSameValue(0, count($f->db->intents), 'denied admin requests do not persist intents.');
    } else {
        throw new RuntimeException('Unknown wiring scenario.');
    }

    echo 'OK ' . $scenario . PHP_EOL;
}
