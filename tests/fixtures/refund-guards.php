<?php

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/RefundAdapterTest.php';

    // These doubles exercise Eloquent events, not WHMCS controller ordering.
    final class RefundGuardTables
    {
        public static $rows = array();
        public static $events = array();
    }

    final class RefundGuardQuery
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
            foreach (RefundGuardTables::$rows[$this->table] ?? array() as $row) {
                foreach ($this->conditions as $condition) {
                    list($field, $operator, $value) = $condition;
                    if ($operator === '>' ? (float) $row[$field] <= $value : (string) $row[$field] !== (string) $value) {
                        continue 2;
                    }
                }
                return (object) $row;
            }
            return null;
        }
    }

    final class RefundGuardSchema
    {
        public function hasTable($table) { return isset(RefundGuardTables::$rows[$table]); }
    }

    abstract class RefundGuardModel
    {
        private $attributes;
        private $original;

        public function __construct(array $attributes, array $original = array())
        {
            $this->attributes = $attributes;
            $this->original = $original;
        }
        public static function saving($callback) { RefundGuardTables::$events[static::class]['saving'][] = $callback; }
        public static function deleting($callback) { RefundGuardTables::$events[static::class]['deleting'][] = $callback; }
        public static function load($id)
        {
            $row = RefundGuardTables::$rows[static::TABLE][$id];
            return new static($row, $row);
        }
        public function set($field, $value) { $this->attributes[$field] = $value; return $this; }
        public function getAttributes() { return $this->attributes; }
        public function getOriginal($field) { return $this->original[$field] ?? null; }
        public function save()
        {
            foreach (RefundGuardTables::$events[static::class]['saving'] ?? array() as $callback) { $callback($this); }
            $id = $this->attributes['id'] ?? count(RefundGuardTables::$rows[static::TABLE]) + 1;
            $this->attributes['id'] = $id;
            RefundGuardTables::$rows[static::TABLE][$id] = $this->attributes;
            $this->original = $this->attributes;
        }
        public function delete()
        {
            foreach (RefundGuardTables::$events[static::class]['deleting'] ?? array() as $callback) { $callback($this); }
            unset(RefundGuardTables::$rows[static::TABLE][$this->attributes['id']]);
        }
    }
}

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table($table) { return new \RefundGuardQuery($table); }
        public static function schema() { return new \RefundGuardSchema(); }
    }
}

namespace WHMCS\Billing {
    final class Invoice extends \RefundGuardModel { public const TABLE = 'tblinvoices'; }
    final class Transaction extends \RefundGuardModel { public const TABLE = 'tblaccounts'; }
}

namespace WHMCS\Order {
    final class Order extends \RefundGuardModel { public const TABLE = 'tblorders'; }
}

namespace {
    use PaymosWhmcs\RefundAdapter;
    use PaymosWhmcs\RefundWriteGuard;
    use WHMCS\Billing\Invoice;
    use WHMCS\Billing\Transaction;
    use WHMCS\Order\Order;

    function refund_guard_reset($signal = 'gateway')
    {
        $f = whmcs_refund_fixture();
        $snapshot = $f->native->snapshot;
        $invoice = $snapshot['invoice'];
        $account = $snapshot['accounts'][0];
        $invoice['paymentmethod'] = $signal === 'gateway' ? 'paymosgateway' : 'other';
        $account['gateway'] = $signal === 'transaction' || $signal === 'gateway' ? 'paymosgateway' : 'other';
        RefundGuardTables::$rows = array('tblinvoices' => array(42 => $invoice), 'tblaccounts' => array(1 => $account),
            'tblorders' => array(1 => array('id' => 1, 'invoiceid' => 42, 'status' => 'Active')),
            'mod_paymos_invoices' => $signal === 'mapping' || $signal === 'gateway' ? array($snapshot['mapping']) : array());
        return $f;
    }

    function refund_guard_transaction(array $overrides = array())
    {
        return array_replace(array('invoiceid' => 42, 'userid' => 77, 'gateway' => 'paymosgateway',
            'transid' => 'paymos_refund_wd_1', 'amountin' => '0', 'amountout' => '100', 'fees' => '0'), $overrides);
    }

    function refund_guard_api($command, $input)
    {
        if ($command === 'AddTransaction') {
            $row = $input;
            $row['gateway'] = $row['paymentmethod'];
            unset($row['paymentmethod']);
            (new Transaction($row))->save();
            Invoice::load($input['invoiceid'])->set('status', 'Unpaid')->save();
        } elseif ($command === 'UpdateInvoice') {
            Invoice::load($input['invoiceid'])->set('status', $input['status'])->save();
        } else { throw new RuntimeException('Unexpected API command in guard fixture.'); }
        return array('result' => 'success');
    }

    function refund_guard_denied(callable $action, $message)
    {
        $before = RefundGuardTables::$rows;
        whmcs_refund_throws($action, 'refund_partial_or_changed_payment');
        assertSameValue($before, RefundGuardTables::$rows, $message . ': no transaction, status or other row may change.');
    }

    function addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gateway)
    {
        (new Transaction(array('invoiceid' => $invoiceId, 'userid' => 77, 'transid' => $transactionId,
            'gateway' => $gateway, 'amountin' => $amount, 'amountout' => '0', 'fees' => $fee)))->save();
        if (!empty($GLOBALS['refund_payment_failure'])) { throw new RuntimeException('Native acknowledgement lost'); }
        Invoice::load($invoiceId)->set('status', 'Paid')->save();
    }

    require_once PAYMOS_WHMCS_PLUGIN_DIR . 'includes/hooks/paymosgateway_refunds.php';
    RefundWriteGuard::register();
    $GLOBALS['paymos_whmcs_local_api'] = 'refund_guard_api';

    $operations = array(
        'outgoing transaction' => static function () {
            localAPI('AddTransaction', array('invoiceid' => 42, 'userid' => 77, 'paymentmethod' => 'paymosgateway',
                'transid' => 'paymos_refund_wd_1', 'amountin' => '0', 'amountout' => '100', 'fees' => '0',
                'paymos_internal' => true));
        },
        'refunded invoice' => static function () { localAPI('UpdateInvoice', array('invoiceid' => 42, 'status' => 'Refunded')); },
        'other invoice status' => static function () { Invoice::load(42)->set('status', 'Cancelled')->save(); },
        'invoice credit' => static function () { Invoice::load(42)->set('credit', '100.00')->save(); },
        'cancel order' => static function () {
            Order::load(1)->set('status', 'Cancelled')->save();
            (new Transaction(refund_guard_transaction()))->save();
            Invoice::load(42)->set('status', 'Refunded')->save();
        },
        'change gateway' => static function () { Invoice::load(42)->set('paymentmethod', 'third')->save(); },
        'change paid transaction' => static function () { Transaction::load(1)->set('amountin', '0')->save(); },
        'delete invoice' => static function () { Invoice::load(42)->delete(); },
        'delete transaction' => static function () { Transaction::load(1)->delete(); },
        'negative payment' => static function () { (new Transaction(refund_guard_transaction(array('amountin' => '-100', 'amountout' => '0'))))->save(); },
    );
    foreach ($operations as $name => $action) {
        foreach (array('gateway', 'mapping', 'transaction') as $signal) {
            refund_guard_reset($signal);
            refund_guard_denied($action, $name . ' protected by ' . $signal);
        }
        refund_guard_reset('other');
        $before = RefundGuardTables::$rows;
        $action();
        assertFalseValue($before === RefundGuardTables::$rows, $name . ' must keep native behavior for other gateways.');
    }

    refund_guard_reset();
    RefundGuardTables::$rows['tblinvoices'][42]['status'] = 'Unpaid';
    RefundGuardTables::$rows['tblaccounts'] = array();
    RefundGuardTables::$rows['mod_paymos_invoices'][0]['status'] = 'awaiting_payment';
    (new PaymosWhmcs\WhmcsAdapter())->addInvoicePayment(42, 'paymos_inv_123_evt_paid', '100.00', '0', 'paymosgateway');
    assertSameValue('Paid', RefundGuardTables::$rows['tblinvoices'][42]['status'], 'verified incoming Paymos payment can settle its invoice.');
    assertSameValue(1, count(RefundGuardTables::$rows['tblaccounts']), 'incoming payment records exactly one transaction.');
    refund_guard_denied($operations['refunded invoice'], 'settlement cannot authorize a later refund');

    refund_guard_reset();
    RefundGuardTables::$rows['tblinvoices'][42]['status'] = 'Unpaid';
    RefundGuardTables::$rows['tblaccounts'] = array();
    RefundGuardTables::$rows['mod_paymos_invoices'][0]['status'] = 'awaiting_payment';
    $GLOBALS['refund_payment_failure'] = true;
    whmcs_refund_throws(static function () {
        (new PaymosWhmcs\WhmcsAdapter())->addInvoicePayment(42, 'paymos_inv_123_evt_paid', '100.00', '0', 'paymosgateway');
    }, 'Native acknowledgement lost');
    unset($GLOBALS['refund_payment_failure']);
    refund_guard_denied(static function () { Invoice::load(42)->set('status', 'Paid')->save(); }, 'failed settlement revokes grant');

    refund_guard_reset();
    Invoice::load(42)->set('total', '100')->set('credit', '0')->save();
    $before = RefundGuardTables::$rows['tblaccounts'];
    (new Transaction(refund_guard_transaction(array('amountin' => '1', 'amountout' => '0'))))->save();
    assertSameValue(count($before) + 1, count(RefundGuardTables::$rows['tblaccounts']), 'incoming payments are unaffected.');

    refund_guard_reset();
    $input = refund_guard_transaction();
    $input['paymentmethod'] = $input['gateway'];
    unset($input['gateway']);
    RefundWriteGuard::withProjection('AddTransaction', $input, static function () use ($input) {
        refund_guard_denied(static function () { (new Transaction(refund_guard_transaction(array('amountout' => '99'))))->save(); }, 'grant requires exact amount');
        refund_guard_denied(static function () { Invoice::load(42)->set('status', 'Refunded')->save(); }, 'transaction grant cannot mark refunded');
        refund_guard_denied(static function () { Invoice::load(42)->set('credit', '100')->save(); }, 'projection cannot issue credit');
        refund_guard_api('AddTransaction', $input);
        refund_guard_denied(static function () { (new Transaction(refund_guard_transaction()))->save(); }, 'grant is consumed once');
    });
    refund_guard_denied($operations['outgoing transaction'], 'grant ends after the API call');
    try {
        RefundWriteGuard::withProjection('UpdateInvoice', array('invoiceid' => 42, 'status' => 'Refunded'), static function () {
            throw new RuntimeException('lost response');
        });
    } catch (RuntimeException $error) { assertSameValue('lost response', $error->getMessage(), 'fixture failure must propagate.'); }
    refund_guard_denied($operations['refunded invoice'], 'exception revokes grant');

    $f = refund_guard_reset();
    $client = new Paymos\Client(new Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test'), $f->server);
    $native = static function ($command, $input) use ($f) {
        $f->native->snapshot['invoice'] = RefundGuardTables::$rows['tblinvoices'][42];
        $f->native->snapshot['accounts'] = array_values(RefundGuardTables::$rows['tblaccounts']);
        if (in_array($command, array('AddTransaction', 'UpdateInvoice'), true)) { return localAPI($command, $input); }
        return call_user_func($f->native, $command, $input);
    };
    $f->adapter = new RefundAdapter(array(), $f->store, static function () use ($client) { return $client; }, $native,
        static function () use ($f) { return (array) $f->config; }, static function () {});
    $over = $f->adapter->submit(whmcs_refund_input('overpayment'));
    whmcs_refund_complete($f, $over);
    assertSameValue(1, count(RefundGuardTables::$rows['tblaccounts']), 'managed overpayment has no outgoing native entry.');
    $result = $f->adapter->nativeRefund(whmcs_refund_input() + array('amount' => '100.00', 'currency' => 'USD',
        'transid' => 'paymos_inv_123_evt_paid'));
    assertSameValue('created', $result['rawdata']['cash_state'], 'gateway entry remains available.');
    assertSameValue('error', $result['status'], 'WHMCS must not book an unconfirmed refund.');
    $full = $f->store->getIntent($result['rawdata']['operation_id']);
    whmcs_refund_complete($f, $full);
    assertSameValue('Refunded', RefundGuardTables::$rows['tblinvoices'][42]['status'], 'verified Paymos completion passes both guards.');
    assertSameValue(2, count(RefundGuardTables::$rows['tblaccounts']), 'verified completion records one outgoing transaction.');
    $f->adapter->reconcile($full->operationId());
    assertSameValue(2, count(RefundGuardTables::$rows['tblaccounts']), 'reconciliation is idempotent with guards enabled.');
    Transaction::load(2)->set('description', 'Full refund through Paymos')->save();
    assertSameValue(2, count(RefundGuardTables::$rows['tblaccounts']), 'nonfinancial edits do not duplicate or reject an existing refund.');
    refund_guard_denied($operations['outgoing transaction'], 'adapter does not leave a reusable grant');

    echo 'OK refund model guards' . PHP_EOL;
}
