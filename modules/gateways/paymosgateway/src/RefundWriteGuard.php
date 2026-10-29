<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Plugin\AmountGuard;

final class RefundWriteGuard
{
    private static $registered = array();
    private static $projection;
    private static $payments = array();

    public static function register()
    {
        $guard = new self();
        $models = array(
            'WHMCS\\Billing\\Invoice' => 'invoiceSaving',
            'WHMCS\\Billing\\Transaction' => 'transactionSaving',
            'WHMCS\\Order\\Order' => 'orderSaving',
        );
        foreach ($models as $model => $method) {
            if (isset(self::$registered[$model]) || !class_exists($model) || !is_callable(array($model, 'saving'))) {
                continue;
            }
            $model::saving(array($guard, $method));
            if ($method !== 'orderSaving') {
                $model::deleting(array($guard, $method === 'invoiceSaving' ? 'invoiceDeleting' : 'transactionDeleting'));
            }
            self::$registered[$model] = true;
        }
    }

    public static function withProjection(string $command, array $input, callable $action)
    {
        if (self::$projection !== null || !in_array($command, array('AddTransaction', 'UpdateInvoice'), true)) {
            self::deny();
        }
        // The grant stays in this PHP call; request parameters cannot supply it.
        self::$projection = array('command' => $command, 'input' => $input, 'transaction_used' => false);
        try {
            return $action();
        } finally {
            self::$projection = null;
        }
    }

    public static function withPayment($invoiceId, callable $action)
    {
        $key = (string) $invoiceId;
        $previous = self::$payments[$key] ?? 0;
        self::$payments[$key] = $previous + 1;
        try {
            return $action();
        } finally {
            if ($previous) { self::$payments[$key] = $previous; } else { unset(self::$payments[$key]); }
        }
    }

    public function invoiceSaving($model)
    {
        $id = $model->getOriginal('id');
        if (!$id || !$this->isPaymosInvoice($id, true)) { return; }
        $attributes = $model->getAttributes();
        foreach (array('paymentmethod', 'credit') as $field) {
            if ($this->changed($model, $field, $attributes)) { self::deny(); }
        }
        if (!$this->changed($model, 'status', $attributes)) { return; }
        if (isset(self::$payments[(string) $id]) && $attributes['status'] === 'Paid'
            && $model->getOriginal('status') !== 'Refunded') { return; }
        $grant = self::$projection;
        if ($grant !== null && (string) $grant['input']['invoiceid'] === (string) $id) {
            if ($grant['command'] === 'UpdateInvoice' && $attributes['status'] === 'Refunded'
                && ($grant['input']['status'] ?? '') === 'Refunded') { return; }
            // WHMCS may recalculate the balance after the outgoing transaction.
            if ($grant['command'] === 'AddTransaction' && in_array($attributes['status'], array('Paid', 'Unpaid'), true)) { return; }
        }
        self::deny();
    }

    public function transactionSaving($model)
    {
        $attributes = $model->getAttributes();
        $originalInvoice = $model->getOriginal('invoiceid');
        if ($model->getOriginal('id') && $originalInvoice && $this->isPaymosInvoice($originalInvoice, false)) {
            foreach (array('invoiceid', 'userid', 'gateway', 'transid', 'amountin', 'amountout', 'fees') as $field) {
                if ($this->changed($model, $field, $attributes)) { self::deny(); }
            }
            return;
        }
        $invoiceId = $attributes['invoiceid'] ?? null;
        if (!$invoiceId || !$this->isPaymosInvoice($invoiceId, false)) { return; }
        $out = AmountGuard::normalizeDecimal((string) ($attributes['amountout'] ?? '0'));
        $in = (string) ($attributes['amountin'] ?? '0');
        if ($out === '0' && strpos($in, '-') !== 0) { return; }
        $grant = self::$projection;
        if (!$model->getOriginal('id') && $grant !== null && $grant['command'] === 'AddTransaction'
            && !$grant['transaction_used'] && $this->matchesProjection($attributes, $grant['input'])) {
            self::$projection['transaction_used'] = true;
            return;
        }
        self::deny();
    }

    public function orderSaving($model)
    {
        $attributes = $model->getAttributes();
        if (($attributes['status'] ?? '') !== 'Cancelled' || !$this->changed($model, 'status', $attributes)) { return; }
        foreach (array($model->getOriginal('invoiceid'), $attributes['invoiceid'] ?? null) as $id) {
            if ($id && $this->isPaymosInvoice($id, true)) { self::deny(); }
        }
    }

    public function invoiceDeleting($model)
    {
        if ($this->isPaymosInvoice($model->getOriginal('id'), true)) { self::deny(); }
    }

    public function transactionDeleting($model)
    {
        if ($this->isPaymosInvoice($model->getOriginal('invoiceid'), false)) { self::deny(); }
    }

    private function isPaymosInvoice($id, bool $paidOnly): bool
    {
        if (!$id) { return false; }
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', (int) $id)->first();
        if ($invoice === null) { return false; }
        $invoice = (array) $invoice;
        if (($invoice['paymentmethod'] ?? '') === 'paymosgateway'
            && (!$paidOnly || in_array($invoice['status'] ?? '', array('Paid', 'Refunded'), true))) { return true; }
        // Payment evidence survives a gateway change on the invoice.
        if (\WHMCS\Database\Capsule::table('tblaccounts')->where('invoiceid', (int) $id)
            ->where('gateway', 'paymosgateway')->where('amountin', '>', 0)->first() !== null) { return true; }
        if (!\WHMCS\Database\Capsule::schema()->hasTable(Migrations::INVOICES_TABLE)) { return false; }
        foreach (array('paid', 'paid_over') as $status) {
            if (\WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)->where('whmcs_invoice_id', (int) $id)
                ->where('status', $status)->first() !== null) { return true; }
        }
        return false;
    }

    private function matchesProjection(array $attributes, array $input): bool
    {
        foreach (array('invoiceid' => 'invoiceid', 'userid' => 'userid', 'gateway' => 'paymentmethod', 'transid' => 'transid') as $field => $key) {
            if (!isset($attributes[$field], $input[$key]) || (string) $attributes[$field] !== (string) $input[$key]) { return false; }
        }
        foreach (array('amountin', 'amountout', 'fees') as $field) {
            if (!AmountGuard::amountsEqual((string) ($attributes[$field] ?? '0'), (string) ($input[$field] ?? '0'))) { return false; }
        }
        return true;
    }

    private function changed($model, string $field, array $attributes): bool
    {
        if (!array_key_exists($field, $attributes)) { return false; }
        $original = $model->getOriginal($field);
        if (in_array($field, array('total', 'credit', 'amountin', 'amountout', 'fees'), true)
            && $original !== null && AmountGuard::amountsEqual((string) $original, (string) $attributes[$field])) { return false; }
        return (string) $original !== (string) $attributes[$field];
    }

    private static function deny()
    {
        throw new \RuntimeException('refund_partial_or_changed_payment');
    }
}
