<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private $store;
    private $params;
    private $clientFactory;
    private $native;
    private $configProvider;
    private $authorize;

    public function __construct(array $params, ?RefundIntentStore $store = null, ?callable $clientFactory = null,
        ?callable $native = null, ?callable $configProvider = null, ?callable $authorize = null)
    {
        $this->params = $params;
        $this->store = $store ?: new RefundIntentStore();
        $this->clientFactory = $clientFactory;
        $this->native = $native;
        $this->configProvider = $configProvider ?: static function ($environment) {
            if (!method_exists(CredentialStore::class, 'refundConfig')) {
                throw new \RuntimeException('refund_reconnect_capability_unavailable');
            }
            return CredentialStore::refundConfig($environment);
        };
        $this->authorize = $authorize ?: array(self::class, 'authorizeAdmin');
    }

    public static function authorizeAdmin()
    {
        $adminId = class_exists('WHMCS\\Session') ? \WHMCS\Session::get('adminid') : ($_SESSION['adminid'] ?? null);
        if (!$adminId || !function_exists('checkPermission') || checkPermission('Refund Invoice Payments', true) !== true
            || !function_exists('check_token') || check_token('WHMCS.admin.default') === false) {
            throw new \RuntimeException('refund_admin_access_denied');
        }
    }

    public function submit(array $input): RefundIntent
    {
        call_user_func($this->authorize);
        $paymentKey = isset($input['invoiceid']) && is_scalar($input['invoiceid']) ? (string) $input['invoiceid'] : '';
        $token = isset($input['request_token']) && is_string($input['request_token']) ? $input['request_token'] : '';
        $mode = isset($input['mode']) && is_string($input['mode']) ? $input['mode'] : '';
        if (!preg_match('/^[a-f0-9-]{36}$/D', $token) || !in_array($mode, array('full', 'overpayment'), true)) {
            throw new \RuntimeException('refund_durable_token_required');
        }
        foreach (array('address', 'destination_address', 'network', 'parts', 'crypto_amount') as $field) {
            if (array_key_exists($field, $input)) { throw new \RuntimeException('refund_managed_input_only'); }
        }
        return $this->store->withPaymentLock($paymentKey, function () use ($input, $paymentKey, $token, $mode) {
            $installation = $this->store->installationId();
            $existing = $this->store->findByRequestToken($installation, $token);
            if ($existing !== null) {
                if ($existing->paymentKey() !== $paymentKey || $existing->mode() !== $mode) { throw new \RuntimeException('refund_request_conflict'); }
                $this->assertRequest($input, $existing->fullDraft());
                $intent = $existing;
            } else {
                $snapshot = $this->native('snapshot', array('invoiceid' => (int) $paymentKey));
                $this->assertOriginalPaid($snapshot);
                $draft = $mode === RefundStates::FULL ? $this->draft($snapshot) : null;
                $this->assertRequest($input, $draft);
                $mapping = $snapshot['mapping'];
                $intent = new RefundIntent(array('operation_id' => RefundIntentStore::uuid(), 'request_token' => $token,
                    'installation_id' => $installation, 'platform' => 'whmcs', 'native_payment_key' => $paymentKey,
                    'invoice_id' => $mapping['paymos_invoice_id'], 'project_id' => $mapping['project_id'],
                    'environment' => $mapping['environment'], 'mode' => $mode, 'full_draft' => $draft));
                $reason = $this->preflight($intent);
                if ($reason !== null) { throw new \RuntimeException($reason); }
                $intent = $this->store->createIntent($intent);
            }
            $this->coordinator($intent)->submit($intent->operationId());
            $this->store->assertOwned();
            $this->reconcile($intent->operationId());
            return $this->store->getIntent($intent->operationId());
        });
    }

    public function nativeRefund(array $params): array
    {
        try {
            $input = array('invoiceid' => $params['invoiceid'] ?? '', 'mode' => RefundStates::FULL,
                'request_token' => $params['request_token'] ?? ($_POST['paymos_refund_token'] ?? ''),
                'amount' => $params['amount'] ?? '', 'currency' => $params['currency'] ?? '', 'transid' => $params['transid'] ?? '');
            $intent = $this->submit($input);
            // WHMCS would book another amountout after a gateway success.
            return array('status' => 'error', 'rawdata' => array('message' => 'WHMCS has no status for a refund in progress, so it reports this one as failed. Track it in the Paymos refunds panel on the invoice; the module records the refund in WHMCS once it completes.', 'operation_id' => $intent->operationId(),
                'cash_state' => $intent->cashState(), 'projection_state' => $intent->projectionState()));
        } catch (\Throwable $error) {
            return array('status' => 'error', 'rawdata' => array('message' => 'Refund request failed; see reason below. If it is refund_reconnect_required, refunds through Paymos are not available on this connection: reconnect the module with Connect Paymos on the Payment Gateways page.', 'reason' => $error->getMessage()));
        }
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $snapshot = $this->native('snapshot', array('invoiceid' => (int) $intent->paymentKey()));
            $this->assertMapping($snapshot, $intent);
            $this->assertOriginalPaid($snapshot);
            if ($intent->mode() === RefundStates::FULL && $this->draft($snapshot) !== $intent->fullDraft()) {
                throw new \RuntimeException('refund_native_draft_changed');
            }
            $config = call_user_func($this->configProvider, $intent->environment());
            if (!isset($config['capabilities'], $config['refund_contract_version'], $config['refunds_enabled'])
                || !is_array($config['capabilities']) || !in_array('invoice_refunds_v1', $config['capabilities'], true)
                || $config['refund_contract_version'] !== 1 || $config['refunds_enabled'] !== true) {
                throw new \RuntimeException('refund_reconnect_required');
            }
            $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            $this->store->assertOwned();
            $this->assertInvoice($invoice, $snapshot, $intent);
            $summary = $invoice['refund_summary'] ?? array();
            $field = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            $amount = $intent->mode() === RefundStates::FULL ? 'full_refund_amount' : 'overpayment_refund_amount';
            if (($summary[$field] ?? false) !== true || !isset($summary[$amount]) || !is_string($summary[$amount])
                || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $summary[$amount])
                || AmountGuard::normalizeDecimal($summary[$amount]) === '0') {
                throw new \RuntimeException('refund_mode_unavailable');
            }
            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $snapshot = $this->native('snapshot', array('invoiceid' => (int) $intent->paymentKey()));
        try { $this->assertMapping($snapshot, $intent); } catch (\RuntimeException $error) {
            return $this->ambiguous($intent, $error->getMessage());
        }
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $projection = $intent->toArray()['projection'];
            return $projection === null ? new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode())
                : NativeProjectionResult::fromStorage($projection);
        }
        $rows = $intent->withdrawalId() === null ? array()
            : $this->native('marker', array('transid' => 'paymos_refund_' . $intent->withdrawalId()));
        if (count($rows) > 1) { return $this->ambiguous($intent, 'refund_native_marker_ambiguous'); }
        if (!$rows) {
            try {
                $this->assertOriginalPaid($snapshot);
                if ($this->draft($snapshot) !== $intent->fullDraft()) { throw new \RuntimeException('refund_native_draft_changed'); }
            } catch (\RuntimeException $error) { return $this->ambiguous($intent, $error->getMessage()); }
            return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode());
        }
        $row = $rows[0];
        $draft = $intent->fullDraft();
        if ((string) $row['invoiceid'] !== $intent->paymentKey() || (string) $row['userid'] !== $draft['client_id']
            || $row['gateway'] !== 'paymosgateway' || !AmountGuard::amountsEqual((string) $row['amountin'], '0')
            || !AmountGuard::amountsEqual((string) $row['amountout'], $draft['amount_decimal'])
            || $snapshot['currency'] !== $draft['currency'] || (string) $snapshot['invoice']['userid'] !== $draft['client_id']
            || !AmountGuard::amountsEqual((string) $snapshot['invoice']['total'], $draft['amount_decimal'])
            || !AmountGuard::amountsEqual((string) $snapshot['invoice']['credit'], '0')
            || count($snapshot['accounts']) !== 2 || !in_array($snapshot['invoice']['status'], array('Paid', 'Unpaid', 'Refunded'), true)
            || count(array_filter($snapshot['accounts'], static function ($account) use ($row) {
                return (string) $account['id'] === (string) $row['id'] && $account === $row;
            })) !== 1
            || !$this->hasOriginalTransaction($snapshot, $draft)) {
            return $this->ambiguous($intent, 'refund_native_marker_conflict');
        }
        return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, RefundStates::FULL,
            (string) $row['id'], $draft['amount_minor'], $draft['currency']);
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertOwned();
        if ($refund->status() !== 'completed' || $refund->withdrawalId() !== $intent->withdrawalId()
            || $refund->invoiceId() !== $intent->invoiceId() || $refund->mode() !== $intent->mode()) {
            return $this->ambiguous($intent, 'refund_completion_required');
        }
        $inspection = $this->inspect($intent->operationId());
        if ($inspection->kind() !== NativeProjectionResult::ABSENT) { return $inspection; }
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, RefundStates::OVERPAYMENT,
                'paymos_overpayment_' . $intent->operationId(), null, null,
                $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
        }
        $draft = $intent->fullDraft();
        $this->store->outsideTransaction(function () use ($intent, $draft) {
            $this->native('AddTransaction', array('invoiceid' => (int) $intent->paymentKey(), 'userid' => (int) $draft['client_id'],
                'paymentmethod' => 'paymosgateway', 'transid' => 'paymos_refund_' . $intent->withdrawalId(),
                'amountout' => $draft['amount_decimal'], 'amountin' => '0', 'fees' => '0', 'credit' => false,
                'allowduplicatetransid' => false, 'description' => 'Full refund through Paymos'));
        });
        // A success response alone cannot prove a native row was committed.
        return $this->inspect($intent->operationId());
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            $intent = $this->store->getIntent($operationId);
            $action = $this->coordinator($intent)->reconcile($operationId);
            $this->store->assertOwned();
            $intent = $this->store->getIntent($operationId);
            if ($intent->mode() === RefundStates::FULL && $intent->cashState() === RefundStates::COMPLETED
                && $intent->projectionState() === RefundStates::PROJECTION_APPLIED) {
                $this->finishNative($intent);
            }
            return $action;
        });
    }

    private function finishNative(RefundIntent $intent)
    {
        if ($this->inspect($intent->operationId())->kind() !== NativeProjectionResult::EXACT_MATCH) {
            $this->store->recordState($intent->operationId(), RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_native_marker_conflict');
            return;
        }
        $snapshot = $this->native('snapshot', array('invoiceid' => (int) $intent->paymentKey()));
        if ($snapshot['invoice']['status'] !== 'Refunded') {
            $this->store->outsideTransaction(function () use ($intent) {
                $response = $this->native('UpdateInvoice', array('invoiceid' => (int) $intent->paymentKey(), 'status' => 'Refunded'));
                if (($response['result'] ?? '') !== 'success') { throw new \RuntimeException('refund_native_status_unknown'); }
            });
        }
        // Customer confirmation is disabled until its native template/copy is supplied.
        if ($this->store->claimEffect($intent->operationId(), 'confirmation', 1)->kind() === 'claimed') {
            $this->store->recordEffectOutcome($intent->operationId(), 'confirmation', 1, RefundStates::EFFECT_SKIPPED);
        }
    }

    public function callback(WebhookEvent $event, string $environment): void
    {
        $wire = $event->toArray()['data'] ?? array();
        if (($wire['type'] ?? '') !== 'refund' || !isset($wire['withdrawal_id'], $wire['invoice_id'], $wire['project_id'], $wire['is_test'])) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }
        $intent = $this->store->findByWithdrawalId((string) $wire['withdrawal_id']);
        if ($intent === null && isset($wire['external_order_id']) && is_string($wire['external_order_id'])) {
            $intent = $this->store->findByExternalId($wire['external_order_id']);
        }
        if ($intent === null) { throw new \RuntimeException('refund_unbound_review'); }
        if ($intent->environment() !== $environment || $wire['is_test'] !== ($environment === 'sandbox')
            || $wire['invoice_id'] !== $intent->invoiceId() || $wire['project_id'] !== $intent->projectId()
            || (isset($wire['mode']) && $wire['mode'] !== $intent->mode())
            || (isset($wire['refund_mode']) && $wire['refund_mode'] !== $intent->mode())
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $wire['withdrawal_id'])) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        // Binding and money come exclusively from the coordinator's signed GETs.
        $this->reconcile($intent->operationId());
    }

    public function run(?int $now = null): int
    {
        $now = $now === null ? time() : $now;
        $page = $this->store->listDue(new \DateTimeImmutable('@' . $now), 100, null);
        $count = 0;
        foreach ($page->items() as $intent) {
            try {
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED && $intent->projectionState() !== RefundStates::NATIVE_REVIEW) {
                    $this->coordinator($intent)->submit($intent->operationId());
                }
                $this->reconcile($intent->operationId());
                $count++;
            } catch (\Throwable $error) {
                if (function_exists('logTransaction')) { logTransaction('paymosgateway', array('operation_id' => $intent->operationId(), 'reason' => $error->getMessage()), 'Refund check failed (retrying later)'); }
            } finally {
                try {
                    $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, $now) { $this->store->defer($intent->operationId(), $now); });
                } catch (\Throwable $error) {
                    if (function_exists('logTransaction')) { logTransaction('paymosgateway', array('operation_id' => $intent->operationId(), 'reason' => $error->getMessage()), 'Refund check not rescheduled (checked again on next cron run)'); }
                }
            }
        }
        return $count;
    }

    public function panel(int $invoiceId): string
    {
        call_user_func($this->authorize);
        return $this->store->withPaymentLock((string) $invoiceId, function () use ($invoiceId) {
            $html = '<div id="paymos-refunds"><strong>Paymos refunds</strong>';
            $tokens = array('full' => RefundIntentStore::uuid(), 'overpayment' => RefundIntentStore::uuid());
            foreach ($this->store->forPayment((string) $invoiceId) as $intent) {
                if ($this->store->blocksNewIntent($intent)) { $tokens[$intent->mode()] = $intent->toArray()['request_token']; }
                $observation = $intent->observation();
                $values = array($intent->operationId(), $intent->mode(), $intent->cashState(), $intent->projectionState(),
                    $intent->toArray()['review_reason'], $observation === null ? '' : $observation->principalDecimal() . ' ' . $observation->currency() . '/' . $observation->network());
                $labels = array('Operation', 'Type', 'Cash status', 'WHMCS record', 'Review reason', 'Amount');
                foreach ($values as $i => $value) { $html .= '<p>' . $labels[$i] . ': <code>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</code></p>'; }
            }
            foreach ($tokens as $mode => $token) {
                $label = $mode === 'full' ? 'Refund in full' : 'Refund overpayment';
                $html .= '<button type="button" class="btn btn-default paymos-refund" data-mode="' . $mode . '" data-token="' . $token . '">' . $label . '</button>';
            }
            return $html . '</div><script>jQuery(function($){$(".paymos-refund").on("click",function(){var b=$(this);b.prop("disabled",true);'
                . '$.post(location.href,{paymos_refund_action:b.data("mode"),request_token:b.data("token"),invoiceid:' . $invoiceId . ',token:$("input[name=token]").first().val()}'
                . ').done(function(){location.reload();}).fail(function(){b.prop("disabled",false);});});});</script>';
        });
    }

    private function assertRequest(array $input, ?array $draft)
    {
        if ($draft === null) {
            if (isset($input['amount']) || isset($input['currency']) || isset($input['transid'])) { throw new \RuntimeException('refund_cash_only_input'); }
            return;
        }
        foreach (array('amount' => 'amount_decimal', 'currency' => 'currency', 'transid' => 'original_transid') as $inputKey => $draftKey) {
            if (array_key_exists($inputKey, $input) && (!is_string($input[$inputKey])
                || ($inputKey === 'amount' ? !AmountGuard::amountsEqual($input[$inputKey], $draft[$draftKey]) : $input[$inputKey] !== $draft[$draftKey]))) {
                throw new \RuntimeException('refund_partial_or_changed_payment');
            }
        }
    }

    private function assertMapping(array $snapshot, RefundIntent $intent)
    {
        $mapping = $snapshot['mapping'] ?? array();
        $config = call_user_func($this->configProvider, $intent->environment());
        if (($snapshot['invoice']['paymentmethod'] ?? '') !== 'paymosgateway'
            || (string) ($snapshot['invoice']['id'] ?? '') !== $intent->paymentKey()
            || ($mapping['paymos_invoice_id'] ?? '') !== $intent->invoiceId()
            || ($mapping['project_id'] ?? '') !== $intent->projectId() || ($config['project_id'] ?? '') !== $intent->projectId()
            || ($mapping['environment'] ?? '') !== $intent->environment() || empty($mapping['external_order_id'])) {
            throw new \RuntimeException('refund_payment_mapping_conflict');
        }
    }

    private function assertOriginalPaid(array $snapshot)
    {
        $invoice = $snapshot['invoice'] ?? array();
        $mapping = $snapshot['mapping'] ?? array();
        $accounts = $snapshot['accounts'] ?? array();
        if (($invoice['status'] ?? '') !== 'Paid' || ($invoice['paymentmethod'] ?? '') !== 'paymosgateway'
            || !isset($invoice['total'], $invoice['credit'], $snapshot['currency'], $mapping['amount'], $mapping['currency'])
            || !AmountGuard::amountsEqual((string) $invoice['credit'], '0') || count($accounts) !== 1
            || !in_array($mapping['status'] ?? '', array('paid', 'paid_over'), true)
            || !AmountGuard::amountsEqual((string) $invoice['total'], (string) $mapping['amount'])
            || $snapshot['currency'] !== $mapping['currency']) {
            throw new \RuntimeException('refund_original_full_paid_required');
        }
        $draft = $this->draft($snapshot);
        if (!$this->hasOriginalTransaction($snapshot, $draft)) { throw new \RuntimeException('refund_original_payment_ambiguous'); }
    }

    private function hasOriginalTransaction(array $snapshot, array $draft): bool
    {
        $matches = 0;
        foreach ($snapshot['accounts'] as $row) {
            if ((string) $row['id'] === $draft['original_transaction_id'] && $row['transid'] === $draft['original_transid']
                && $row['gateway'] === 'paymosgateway' && (string) $row['invoiceid'] === (string) $snapshot['invoice']['id']
                && (string) $row['userid'] === $draft['client_id'] && strpos($row['transid'], 'paymos_' . $snapshot['mapping']['paymos_invoice_id'] . '_') === 0
                && AmountGuard::amountsEqual((string) $row['amountin'], $draft['amount_decimal'])
                && AmountGuard::amountsEqual((string) $row['amountout'], '0')) { $matches++; }
        }
        return $matches === 1;
    }

    private function draft(array $snapshot): array
    {
        $total = AmountGuard::normalizeDecimal((string) $snapshot['invoice']['total']);
        if ($total === null || $total === '0' || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $total)
            || empty($snapshot['invoice']['userid']) || empty($snapshot['accounts'][0]['transid'])) { throw new \RuntimeException('refund_native_precision_invalid'); }
        $parts = explode('.', $total, 2);
        $minor = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        $row = $snapshot['accounts'][0];
        return array('amount_decimal' => $total, 'amount_minor' => $minor, 'currency' => $snapshot['currency'],
            'client_id' => (string) $snapshot['invoice']['userid'], 'original_transaction_id' => (string) $row['id'],
            'original_transid' => $row['transid'], 'send_email' => false);
    }

    private function assertInvoice(array $invoice, array $snapshot, RefundIntent $intent)
    {
        if (($invoice['invoice_id'] ?? '') !== $intent->invoiceId() || ($invoice['project_id'] ?? '') !== $intent->projectId()
            || ($invoice['is_test'] ?? null) !== ($intent->environment() === 'sandbox')
            || ($invoice['order']['external_id'] ?? '') !== $snapshot['mapping']['external_order_id']
            || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $snapshot['mapping']['amount'])
            || ($invoice['order']['currency'] ?? '') !== $snapshot['mapping']['currency']) {
            throw new \RuntimeException('refund_invoice_scope_conflict');
        }
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, $reason);
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator($this->client($intent->environment()), $this->store, $this);
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) { return call_user_func($this->clientFactory, $environment); }
        return new Client(Config::fromParams(array_merge($this->params, array('mode' => $environment)))->clientConfigForEnvironment($environment));
    }

    private function native(string $command, array $input): array
    {
        $this->store->assertOwned();
        if (in_array($command, array('AddTransaction', 'UpdateInvoice'), true)) {
            return RefundWriteGuard::withProjection($command, $input, function () use ($command, $input) {
                return $this->nativeCall($command, $input);
            });
        }
        return $this->nativeCall($command, $input);
    }

    private function nativeCall(string $command, array $input): array
    {
        if ($this->native !== null) { return call_user_func($this->native, $command, $input); }
        if ($command === 'snapshot') {
            $id = $input['invoiceid'];
            $invoices = $this->store->rows('SELECT i.*, c.code AS currency FROM tblinvoices i
                JOIN tblclients u ON u.id = i.userid JOIN tblcurrencies c ON c.id = u.currency WHERE i.id = ?', array($id));
            $mapping = $this->store->rows('SELECT * FROM mod_paymos_invoices WHERE whmcs_invoice_id = ? ORDER BY id DESC', array($id));
            if (!$invoices || !$mapping) { throw new \RuntimeException('refund_payment_mapping_missing'); }
            return array('invoice' => $invoices[0], 'currency' => $invoices[0]['currency'], 'mapping' => $mapping[0],
                'accounts' => $this->store->rows('SELECT * FROM tblaccounts WHERE invoiceid = ? ORDER BY id', array($id)));
        }
        if ($command === 'marker') { return $this->store->rows('SELECT * FROM tblaccounts WHERE transid = ?', array($input['transid'])); }
        if (!function_exists('localAPI')) { throw new \RuntimeException('refund_native_api_unavailable'); }
        $response = localAPI($command, $input);
        if (!is_array($response)) { throw new \RuntimeException('refund_native_response_unknown'); }
        return $response;
    }
}
