<?php

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/RefundAdapterTest.php';

    final class Whmcs91Tables
    {
        public static $rows = array();
        public static $events = array();
    }

    final class Whmcs91Query
    {
        private $table;
        private $conditions = array();

        public function __construct($table) { $this->table = $table; }

        public function where($field, $operator, $value = null)
        {
            $this->conditions[] = array($field, func_num_args() === 2 ? '=' : $operator,
                func_num_args() === 2 ? $operator : $value);
            return $this;
        }

        public function first()
        {
            foreach (Whmcs91Tables::$rows[$this->table] ?? array() as $row) {
                foreach ($this->conditions as $condition) {
                    list($field, $operator, $value) = $condition;
                    if (!array_key_exists($field, $row)) {
                        continue 2;
                    }
                    if ($operator === '>' ? (float) $row[$field] <= $value : (string) $row[$field] !== (string) $value) {
                        continue 2;
                    }
                }
                return (object) $row;
            }
            return null;
        }

        public function value($column)
        {
            $row = $this->first();
            return ($row !== null && isset($row->{$column})) ? $row->{$column} : null;
        }
    }

    final class Whmcs91Schema
    {
        public function hasTable($table) { return isset(Whmcs91Tables::$rows[$table]); }
        public function hasColumn($table, $column) { return true; }
    }

    final class Whmcs91Pdo
    {
        public function inTransaction() { return $GLOBALS['whmcs91_db']->inTransaction(); }
        public function beginTransaction() { return $GLOBALS['whmcs91_db']->beginTransaction(); }
        public function commit() { return $GLOBALS['whmcs91_db']->commit(); }
        public function rollBack() { return $GLOBALS['whmcs91_db']->rollBack(); }

        public function prepare($sql)
        {
            $normalized = preg_replace('/\s+/', ' ', trim($sql));
            if (strpos($normalized, 'SELECT i.*, c.code AS currency') === 0
                || strpos($normalized, 'SELECT * FROM mod_paymos_invoices') === 0
                || strpos($normalized, 'SELECT * FROM tblaccounts') === 0) {
                return new Whmcs91RowsStatement($normalized);
            }
            return $GLOBALS['whmcs91_db']->prepare($normalized);
        }
    }

    final class Whmcs91RowsStatement
    {
        private $sql;
        private $rows = array();

        public function __construct($sql) { $this->sql = $sql; }
        public function fetchAll($mode) { return $this->rows; }

        public function execute(array $values)
        {
            $this->rows = array();
            if (strpos($this->sql, 'SELECT i.*') === 0) {
                $id = (int) $values[0];
                if (isset(Whmcs91Tables::$rows['tblinvoices'][$id])) {
                    $this->rows = array(Whmcs91Tables::$rows['tblinvoices'][$id] + array('currency' => 'USD'));
                }
            } elseif (strpos($this->sql, 'SELECT * FROM mod_paymos_invoices') === 0) {
                $id = (int) $values[0];
                foreach (Whmcs91Tables::$rows['mod_paymos_invoices'] ?? array() as $row) {
                    if ((int) $row['whmcs_invoice_id'] === $id) {
                        $this->rows[] = $row;
                    }
                }
            } elseif (strpos($this->sql, 'WHERE transid = ?') !== false) {
                $transId = (string) $values[0];
                foreach (Whmcs91Tables::$rows['tblaccounts'] ?? array() as $row) {
                    if ((string) ($row['transid'] ?? '') === $transId) {
                        $this->rows[] = $row;
                    }
                }
            } else {
                $id = (int) $values[0];
                foreach (Whmcs91Tables::$rows['tblaccounts'] ?? array() as $row) {
                    if ((int) ($row['invoiceid'] ?? 0) === $id) {
                        $this->rows[] = $row;
                    }
                }
            }
            return true;
        }
    }

    final class Whmcs91Connection
    {
        public function getPdo() { return new Whmcs91Pdo(); }
    }

    abstract class Whmcs91Model
    {
        private $attributes;
        private $original;

        public function __construct(array $attributes, array $original = array())
        {
            $this->attributes = $attributes;
            $this->original = $original;
        }

        public static function saving($callback) { Whmcs91Tables::$events[static::class]['saving'][] = $callback; }
        public static function deleting($callback) { Whmcs91Tables::$events[static::class]['deleting'][] = $callback; }

        public static function load($id)
        {
            $row = Whmcs91Tables::$rows[static::TABLE][$id];
            return new static($row, $row);
        }

        public function set($field, $value) { $this->attributes[$field] = $value; return $this; }
        public function getAttributes() { return $this->attributes; }
        public function getOriginal($field) { return $this->original[$field] ?? null; }

        public function save()
        {
            foreach (Whmcs91Tables::$events[static::class]['saving'] ?? array() as $callback) { $callback($this); }
            $id = $this->attributes['id'] ?? (count(Whmcs91Tables::$rows[static::TABLE]) + 1);
            $this->attributes['id'] = $id;
            Whmcs91Tables::$rows[static::TABLE][$id] = $this->attributes;
            $this->original = $this->attributes;
        }

        public function delete()
        {
            foreach (Whmcs91Tables::$events[static::class]['deleting'] ?? array() as $callback) { $callback($this); }
            unset(Whmcs91Tables::$rows[static::TABLE][$this->attributes['id']]);
        }
    }
}

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table($table) { return new \Whmcs91Query($table); }
        public static function schema() { return new \Whmcs91Schema(); }
        public static function connection() { return new \Whmcs91Connection(); }
    }
}

namespace WHMCS\Billing {
    final class Invoice extends \Whmcs91Model { public const TABLE = 'tblinvoices'; }
}

namespace WHMCS\Billing\Payment {
    // Real WHMCS 8.13-9.1 transaction model (WHMCS\Billing\Transaction is deliberately absent here).
    final class Transaction extends \Whmcs91Model { public const TABLE = 'tblaccounts'; }
}

namespace WHMCS\Order {
    final class Order extends \Whmcs91Model { public const TABLE = 'tblorders'; }
}

namespace {
    use Paymos\Client;
    use Paymos\ClientConfig;
    use Paymos\Plugin\RefundStates;
    use PaymosWhmcs\RefundAdapter;
    use PaymosWhmcs\RefundIntentStore;
    use PaymosWhmcs\RefundWriteGuard;
    use WHMCS\Billing\Invoice;
    use WHMCS\Billing\Payment\Transaction;
    use WHMCS\Order\Order;

    function whmcs91_reset($signal = 'gateway')
    {
        $db = new WhmcsRefundDatabase();
        $GLOBALS['whmcs91_db'] = $db;
        $GLOBALS['whmcs91_refund_calls'] = array();
        $GLOBALS['whmcs91_local_api_calls'] = array();
        $GLOBALS['whmcs91_simulate_status_failure'] = false;
        $GLOBALS['whmcs91_simulate_three_row_status_failure'] = false;
        $GLOBALS['whmcs91_simulate_refund_error'] = false;

        $invoice = array(
            'id' => 42,
            'userid' => 77,
            'paymentmethod' => $signal === 'gateway' ? 'paymosgateway' : 'other',
            'status' => 'Paid',
            'total' => '100.00',
            'credit' => '0.00',
        );
        $account = array(
            'id' => 1,
            'userid' => 77,
            'invoiceid' => 42,
            'gateway' => ($signal === 'transaction' || $signal === 'gateway') ? 'paymosgateway' : 'other',
            'transid' => 'paymos_inv_123_evt_paid',
            'amountin' => '100.00',
            'amountout' => '0.00',
            'fees' => '1.00',
            'type' => 'gateway_funds_in',
            'billingnoteid' => 0,
        );
        $mapping = array(
            'id' => 1,
            'whmcs_invoice_id' => 42,
            'paymos_invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'environment' => 'sandbox',
            'external_order_id' => 'whmcs_42_0',
            'amount' => '100.00',
            'currency' => 'USD',
            'status' => 'paid_over',
        );
        Whmcs91Tables::$rows = array(
            'tblinvoices' => array(42 => $invoice),
            'tblaccounts' => array(1 => $account),
            'tblorders' => array(1 => array('id' => 1, 'invoiceid' => 42, 'status' => 'Active')),
            'mod_paymos_invoices' => ($signal === 'mapping' || $signal === 'gateway') ? array($mapping) : array(),
            'tblconfiguration' => array(),
        );

        $store = new RefundIntentStore(new Whmcs91Pdo());
        $server = new WhmcsRefundServer($db);
        $config = array(
            'capabilities' => array('invoice_refunds_v1'),
            'refund_contract_version' => 1,
            'refunds_enabled' => true,
            'project_id' => 'prj_123',
        );
        $client = new Client(new ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $server);
        // Pass $native = null to exercise the exact WHMCS 9.1 production code path in RefundAdapter.
        $adapter = new RefundAdapter(
            array(),
            $store,
            static function ($environment) use ($client) {
                assertSameValue('sandbox', $environment, 'saved environment is used.');
                return $client;
            },
            null,
            static function () use ($config) { return $config; },
            static function () {}
        );
        return (object) compact('db', 'store', 'server', 'adapter');
    }

    function whmcs91_denied(callable $action, $message)
    {
        $before = Whmcs91Tables::$rows;
        whmcs_refund_throws($action, 'refund_partial_or_changed_payment');
        assertSameValue($before, Whmcs91Tables::$rows, $message . ': no table row may change.');
    }

    function whmcs91_local_api($command, $input)
    {
        $GLOBALS['whmcs91_local_api_calls'][] = array($command, $input);
        if ($command === 'AddTransaction') {
            $row = $input;
            $row['gateway'] = $row['paymentmethod'];
            unset($row['paymentmethod']);
            (new Transaction($row))->save();
            Invoice::load((int) $input['invoiceid'])->set('status', 'Unpaid')->save();
            return array('result' => 'success');
        }
        if ($command === 'UpdateInvoice') {
            if (($input['status'] ?? '') === 'Refunded') {
                $hasCreditNote = false;
                foreach (Whmcs91Tables::$rows['tblaccounts'] ?? array() as $row) {
                    if ((int) ($row['invoiceid'] ?? 0) === (int) $input['invoiceid']
                        && (string) ($row['type'] ?? '') === 'invoice_billing_adjustment_credit'
                        && (int) ($row['billingnoteid'] ?? 0) > 0) {
                        $hasCreditNote = true;
                        break;
                    }
                }
                if (!$hasCreditNote) {
                    return array(
                        'result' => 'error',
                        'message' => 'Cannot change invoice status to Refunded without appropriate billing records',
                    );
                }
            }
            Invoice::load((int) $input['invoiceid'])->set('status', $input['status'])->save();
            return array('result' => 'success');
        }
        throw new RuntimeException('Unexpected localAPI command: ' . $command);
    }

    function refundInvoicePayment(
        $transactionId,
        $amount = '',
        $sendToGateway = false,
        $addAsCredit = false,
        $sendEmail = true,
        $refundTransId = '',
        $reverse = false
    ) {
        assertFalseValue($GLOBALS['whmcs91_db']->inTransaction(), 'refundInvoicePayment must run outside module DB transaction.');
        assertFalseValue($sendToGateway, 'refundInvoicePayment must pass sendToGateway=false.');
        assertFalseValue($addAsCredit, 'refundInvoicePayment must pass addAsCredit=false.');
        assertFalseValue($sendEmail, 'refundInvoicePayment must pass sendEmail=false.');
        assertFalseValue($reverse, 'refundInvoicePayment must pass reverse=false.');

        $GLOBALS['whmcs91_refund_calls'][] = array(
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'refund_trans_id' => $refundTransId,
        );

        if (!empty($GLOBALS['whmcs91_simulate_refund_error'])) {
            return 'error';
        }

        $original = Whmcs91Tables::$rows['tblaccounts'][(int) $transactionId] ?? null;
        if ($original === null) {
            return 'notfound';
        }
        $invoiceId = (int) $original['invoiceid'];
        $userId = (int) $original['userid'];
        $refundAmount = $amount !== '' ? (string) $amount : (string) $original['amountin'];

        // 1) Outgoing refund transaction via WHMCS\Billing\Payment\Transaction.
        // Real WHMCS 9.1 reverses original payment fees proportionally (-original.fees on full refund).
        $origFees = (float) ($original['fees'] ?? 0);
        $refundFees = $origFees > 0 ? number_format(-1 * $origFees, 2, '.', '') : '0.00';
        (new Transaction(array(
            'invoiceid' => $invoiceId,
            'userid' => $userId,
            'gateway' => (string) $original['gateway'],
            'transid' => (string) $refundTransId,
            'amountin' => '0.00',
            'amountout' => $refundAmount,
            'fees' => $refundFees,
            'type' => 'gateway_funds_out',
            'billingnoteid' => 0,
        )))->save();

        if (!empty($GLOBALS['whmcs91_simulate_status_failure'])) {
            // 2-row ledger still in Paid status (never allowed to become projection_state=applied).
            return 'manual';
        }

        // 2) WHMCS 9.1 InvoiceImmutability=full Credit Note adjustment row.
        (new Transaction(array(
            'invoiceid' => $invoiceId,
            'userid' => $userId,
            'gateway' => '',
            'transid' => '',
            'amountin' => $refundAmount,
            'amountout' => '0.00',
            'fees' => '0.00',
            'type' => 'invoice_billing_adjustment_credit',
            'billingnoteid' => 101,
        )))->save();

        if (!empty($GLOBALS['whmcs91_simulate_three_row_status_failure'])) {
            // 3-row ledger still in Paid status (must fail closed with refund_native_status_not_refunded).
            return 'manual';
        }

        // 3) Transition invoice status to Refunded inside the authorized RefundInvoicePayment grant.
        Invoice::load($invoiceId)->set('status', 'Refunded')->save();
        return 'manual';
    }

    assertFalseValue(class_exists('WHMCS\\Billing\\Transaction', false), 'legacy WHMCS\\Billing\\Transaction must not exist in WHMCS 9.1 fixture.');
    assertTrueValue(class_exists('WHMCS\\Billing\\Payment\\Transaction', false), 'real WHMCS\\Billing\\Payment\\Transaction must exist.');

    require_once PAYMOS_WHMCS_PLUGIN_DIR . 'includes/hooks/paymosgateway_refunds.php';
    RefundWriteGuard::register();
    $GLOBALS['paymos_whmcs_local_api'] = 'whmcs91_local_api';

    // A) Unauthorized Payment\Transaction writes must be blocked on Paymos invoices and allowed on non-Paymos invoices.
    $operations = array(
        'localAPI AddTransaction amountout>0' => static function () {
            localAPI('AddTransaction', array(
                'invoiceid' => 42,
                'userid' => 77,
                'paymentmethod' => 'paymosgateway',
                'transid' => 'paymos_refund_unauth',
                'amountin' => '0',
                'amountout' => '100',
                'fees' => '0',
            ));
        },
        'negative amountin on Payment\\Transaction' => static function () {
            (new Transaction(array(
                'invoiceid' => 42,
                'userid' => 77,
                'gateway' => 'paymosgateway',
                'transid' => 'neg_tx',
                'amountin' => '-100',
                'amountout' => '0',
                'fees' => '0',
            )))->save();
        },
        'edit amountin on Payment\\Transaction' => static function () {
            Transaction::load(1)->set('amountin', '50.00')->save();
        },
        'delete Payment\\Transaction' => static function () {
            Transaction::load(1)->delete();
        },
        'cancel order' => static function () {
            Order::load(1)->set('status', 'Cancelled')->save();
        },
    );
    foreach ($operations as $name => $action) {
        foreach (array('gateway', 'mapping', 'transaction') as $signal) {
            whmcs91_reset($signal);
            whmcs91_denied($action, $name . ' protected via ' . $signal);
        }
        whmcs91_reset('other');
        $before = Whmcs91Tables::$rows;
        $action();
        assertFalseValue($before === Whmcs91Tables::$rows, $name . ' must remain unrestricted for non-Paymos invoices.');
    }

    // B) Full refund production path ($native = null) through refundInvoicePayment() under WHMCS 9.1 InvoiceImmutability=full.
    $f = whmcs91_reset('gateway');
    $full = $f->adapter->submit(whmcs_refund_input('full'));
    assertSameValue(RefundStates::CREATED, $full->cashState(), 'full refund intent is created.');
    assertSameValue(0, count($GLOBALS['whmcs91_refund_calls']), 'no native refund call while pending.');
    assertSameValue(0, count($GLOBALS['whmcs91_local_api_calls']), 'no localAPI call while pending.');

    $f->server->refunds[$full->externalOrderId()]['status'] = 'completed';
    $action = $f->adapter->reconcile($full->operationId());
    assertSameValue(RefundStates::APPLY_COMPLETED, $action, 'completed full refund projects cleanly through refundInvoicePayment.');
    assertSameValue(1, count($GLOBALS['whmcs91_refund_calls']), 'exactly one refundInvoicePayment call.');
    assertSameValue(1, $GLOBALS['whmcs91_refund_calls'][0]['transaction_id'], 'original transaction id is passed to refundInvoicePayment.');
    assertSameValue('100', $GLOBALS['whmcs91_refund_calls'][0]['amount'], 'exact invoice amount is passed to refundInvoicePayment.');
    assertSameValue('paymos_refund_' . $full->withdrawalId(), $GLOBALS['whmcs91_refund_calls'][0]['refund_trans_id'], 'deterministic marker transid is passed.');
    assertSameValue(0, count($GLOBALS['whmcs91_local_api_calls']), 'AddTransaction and UpdateInvoice are not called when refundInvoicePayment handles projection.');
    assertSameValue('Refunded', Whmcs91Tables::$rows['tblinvoices'][42]['status'], 'invoice transitions to Refunded.');
    assertSameValue(3, count(Whmcs91Tables::$rows['tblaccounts']), 'WHMCS 9.1 ledger contains original, refund, and Credit Note rows.');

    $applied = $f->store->getIntent($full->operationId());
    assertSameValue(RefundStates::COMPLETED, $applied->cashState(), 'cash_state is completed.');
    assertSameValue(RefundStates::PROJECTION_APPLIED, $applied->projectionState(), 'projection_state is applied once Refunded.');
    assertSameValue('10000', $applied->toArray()['projection']['nativeAmountMinor'], 'native minor amount matches full invoice.');
    assertSameValue('skipped', $applied->toArray()['effects']['confirmation:1'], 'confirmation effect is marked skipped.');

    // Idempotent second reconcile() over the 3-row WHMCS 9.1 ledger.
    $secondAction = $f->adapter->reconcile($full->operationId());
    assertSameValue(RefundStates::RESUME_RECOVERY, $secondAction, 'second reconcile recovers exact 3-row match idempotently.');
    assertSameValue(1, count($GLOBALS['whmcs91_refund_calls']), 'idempotent reconcile never repeats refundInvoicePayment.');
    assertSameValue(0, count($GLOBALS['whmcs91_local_api_calls']), 'idempotent reconcile never calls localAPI.');
    assertSameValue(3, count(Whmcs91Tables::$rows['tblaccounts']), 'idempotent reconcile preserves exact 3-row ledger.');

    // Grant must not leak after RefundInvoicePayment completes.
    whmcs91_denied($operations['localAPI AddTransaction amountout>0'], 'RefundInvoicePayment grant does not leak after reconcile');

    // C) Invariant spec.md:62: if invoice remains Paid after refundInvoicePayment (2-row or 3-row), projection_state is never applied.
    foreach (array('whmcs91_simulate_status_failure', 'whmcs91_simulate_three_row_status_failure') as $flag) {
        $fFail = whmcs91_reset('gateway');
        $GLOBALS[$flag] = true;
        $intentFail = $fFail->adapter->submit(whmcs_refund_input('full'));
        $fFail->server->refunds[$intentFail->externalOrderId()]['status'] = 'completed';
        $failAction = $fFail->adapter->reconcile($intentFail->operationId());
        assertSameValue(RefundStates::SHOW_REVIEW, $failAction, $flag . ' must route to review instead of applied.');
        $storedFail = $fFail->store->getIntent($intentFail->operationId());
        assertTrueValue($storedFail->projectionState() !== RefundStates::PROJECTION_APPLIED, $flag . ' must never record projection_state=applied while Paid.');
        assertSameValue(RefundStates::NATIVE_REVIEW, $storedFail->projectionState(), $flag . ' sets projection_state to native_review.');
        assertSameValue('refund_native_status_not_refunded', $storedFail->toArray()['review_reason'], $flag . ' records refund_native_status_not_refunded.');
        assertSameValue('Paid', Whmcs91Tables::$rows['tblinvoices'][42]['status'], $flag . ' keeps invoice Paid.');
        assertSameValue(0, count($GLOBALS['whmcs91_local_api_calls']), $flag . ' must not fall back to UpdateInvoice.');
    }

    // D) Non-manual/non-success return from refundInvoicePayment fails closed into review.
    $fErr = whmcs91_reset('gateway');
    $GLOBALS['whmcs91_simulate_refund_error'] = true;
    $intentErr = $fErr->adapter->submit(whmcs_refund_input('full'));
    $fErr->server->refunds[$intentErr->externalOrderId()]['status'] = 'completed';
    assertSameValue(RefundStates::SHOW_REVIEW, $fErr->adapter->reconcile($intentErr->operationId()), 'error return from refundInvoicePayment routes to review.');
    $storedErr = $fErr->store->getIntent($intentErr->operationId());
    assertSameValue(RefundStates::NATIVE_REVIEW, $storedErr->projectionState(), 'error return sets native_review.');
    assertSameValue('native_projection_unknown', $storedErr->toArray()['review_reason'], 'error return records native_projection_unknown.');

    echo 'OK refund whmcs91' . PHP_EOL;
}
