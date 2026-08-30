<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

final class CallbackProcessor
{
    /** @var WhmcsAdapterInterface */
    private $whmcs;

    /** @var InvoiceStoreInterface */
    private $invoiceStore;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        WhmcsAdapterInterface $whmcs,
        InvoiceStoreInterface $invoiceStore,
        EventStoreInterface $eventStore,
        ?callable $clientFactory = null
    ) {
        $this->whmcs = $whmcs;
        $this->invoiceStore = $invoiceStore;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $gatewayParams
     */
    public function handle($rawBody, $signatureHeader, array $gatewayParams, $now = null)
    {
        try {
            $config = Config::fromParams($gatewayParams);
            $verified = (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent();
                return new CallbackResult(200, 'OK');
            }

            $this->assertPayloadEnvironment($event, $environment);
            $this->applyVerifiedEvent($event, $environment, $gatewayParams, true);
            $this->commitEvent();

            return new CallbackResult(200, 'OK');
        } catch (DuplicateEventException $e) {
            $this->whmcs->logTransaction($this->gatewayName($gatewayParams), array('duplicate' => true), 'Duplicate');
            return new CallbackResult(200, 'OK', true);
        } catch (SignatureMismatchException $e) {
            return new CallbackResult(401, 'Bad signature');
        } catch (TimestampSkewException $e) {
            return new CallbackResult(401, 'Bad timestamp');
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent();
            $this->whmcs->logTransaction($this->gatewayName($gatewayParams), array('error' => $e->getMessage()), 'Configuration error');
            return new CallbackResult(500, 'Configuration error');
        } catch (\RuntimeException $e) {
            $this->releaseEvent();
            $this->whmcs->logTransaction($this->gatewayName($gatewayParams), array('error' => $e->getMessage()), 'Error');
            return new CallbackResult(400, 'Processing failed');
        }
    }

    /**
     * @param array<string, mixed> $gatewayParams
     * @param array<string, mixed> $invoice
     */
    public function applyTrustedInvoice(array $invoice, array $row, array $gatewayParams, $now)
    {
        $invoiceId = $this->field($invoice, array('invoice_id'));
        $status = $this->field($invoice, array('status'));
        $event = new WebhookEvent(array(
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => $this->eventTypeForStatus($status),
            'occurred_at' => (int) $now,
            'data' => $invoice,
        ));

        return $this->applyEventToWhmcs($event, (string) $row['environment'], $row, $gatewayParams, false);
    }

    /**
     * @param array<string, mixed> $gatewayParams
     */
    private function applyVerifiedEvent(WebhookEvent $event, $environment, array $gatewayParams, $reverseVerify)
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing external order id.');
        }

        $row = $this->invoiceStore->findByExternalOrderId($externalOrderId);
        if (!is_array($row)) {
            throw new \RuntimeException('Paymos WHMCS invoice snapshot was not found.');
        }

        return $this->applyEventToWhmcs($event, $environment, $row, $gatewayParams, $reverseVerify);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $gatewayParams
     */
    private function applyEventToWhmcs(WebhookEvent $event, $environment, array $row, array $gatewayParams, $reverseVerify)
    {
        $gatewayName = $this->gatewayName($gatewayParams);
        $whmcsInvoiceId = $this->whmcs->checkInvoiceId((int) $row['whmcs_invoice_id'], $gatewayName);
        $this->assertRowMatchesEvent($row, $event, $environment);

        if ($reverseVerify && $this->requiresReverseVerify($event)) {
            $result = (new InvoiceReverseVerifier($this->client($environment, $gatewayParams)))->verify($event, array(
                'project_id' => (string) $row['project_id'],
                'external_order_id' => (string) $row['external_order_id'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
            ));

            if (!$result->isVerified()) {
                throw new \RuntimeException('Paymos reverse verification failed: ' . $result->reason());
            }
        }

        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        $currentInvoice = $this->whmcs->getInvoice($whmcsInvoiceId);

        // Roll-back / double-payment guard. WHMCS marks an invoice "Paid" once its
        // balance hits zero; a second PAYMENT_COMPLETE event (paid then paid_over,
        // or a reorg awaiting_payment → re-paid) carries a DISTINCT event id, so the
        // tx id differs and checkCbTransID does NOT dedup it — a second
        // addInvoicePayment would book an overpayment credit on the client. A late
        // downgrade (cancelled/expired/underpaid after paid) must likewise not
        // rewrite the snapshot or log a contradicting transaction. So once the WHMCS
        // invoice is already Paid, ignore any further complete-or-downgrade event.
        if ($this->invoiceIsPaid($currentInvoice) && $this->isCompleteOrDowngrade($action)) {
            $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());
            $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Ignored (invoice already paid)');
            return false;
        }

        $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());
        $this->whmcs->logTransaction($gatewayName, $event->toArray(), $event->status() === '' ? $action : $event->status());

        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            return false;
        }

        $currentAmount = $this->currentInvoiceAmount($currentInvoice, $row);
        $currentCurrency = $this->currentInvoiceCurrency($currentInvoice, $row);

        if (!AmountGuard::isSafeToComplete(
            $row['amount'],
            $row['currency'],
            $currentAmount,
            $currentCurrency,
            $event->orderAmount(),
            $event->orderCurrency()
        )) {
            // An amount mismatch on a reverse-verified paid invoice is not a
            // transient failure — the figures won't change on redelivery. Do not
            // throw into the retry path (that 400s and the server retries forever);
            // hold the invoice for manual review and acknowledge the webhook (200).
            $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Manual review: ' . AmountGuard::mismatchSummary(
                $row['amount'],
                $row['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            ));
            return false;
        }

        $transactionId = $this->transactionId($event);
        $this->whmcs->checkTransactionId($transactionId);
        $this->whmcs->addInvoicePayment($whmcsInvoiceId, $transactionId, $currentAmount, $this->paymentFee($event), $gatewayName);
        return true;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function invoiceIsPaid(array $invoice)
    {
        return isset($invoice['status']) && is_scalar($invoice['status'])
            && strtolower(trim((string) $invoice['status'])) === 'paid';
    }

    private function isCompleteOrDowngrade($action)
    {
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * The gateway fee WHMCS should record for this payment. The webhook carries
     * it at data.payment.fee (a decimal string, merchant-only — present on
     * webhook payloads). Falls back to '0.00' when absent (e.g. sandbox events,
     * which omit the payment object). Recording the real fee keeps WHMCS gateway
     * accounting accurate instead of always reporting a zero fee.
     */
    private function paymentFee(WebhookEvent $event)
    {
        $fee = $this->field($event->toArray(), array('data', 'payment', 'fee'));
        if ($fee === '' || !is_numeric($fee)) {
            return '0.00';
        }

        return number_format((float) $fee, 2, '.', '');
    }

    private function transactionId(WebhookEvent $event)
    {
        return 'paymos_' . $event->invoiceId() . '_' . $event->id();
    }

    private function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRowMatchesEvent(array $row, WebhookEvent $event, $environment)
    {
        if ((string) $row['environment'] !== (string) $environment) {
            throw new \RuntimeException('Paymos event environment does not match WHMCS invoice snapshot.');
        }
        if ((string) $row['project_id'] !== '' && $event->projectId() !== '' && (string) $row['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match WHMCS invoice snapshot.');
        }
        if ((string) $row['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $row['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match WHMCS invoice snapshot.');
        }
        if ((string) $row['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $row['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match WHMCS invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }

        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    /**
     * @param array<string, mixed> $gatewayParams
     */
    private function client($environment, array $gatewayParams)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        $config = Config::fromParams($gatewayParams);
        return new Client($config->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $gatewayParams
     */
    private function gatewayName(array $gatewayParams)
    {
        return isset($gatewayParams['paymentmethod']) && is_scalar($gatewayParams['paymentmethod'])
            ? (string) $gatewayParams['paymentmethod']
            : 'paymosgateway';
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $row
     */
    private function currentInvoiceAmount(array $invoice, array $row)
    {
        // Compare against `total`, NOT `balance`. The stored snapshot row['amount']
        // is the full invoice total captured when the Paymos invoice was created.
        // `balance` shrinks with partial payments / applied account credit, so a
        // legitimate full crypto payment would look like an amount mismatch
        // (row.amount != balance) and AmountGuard would reject it — bouncing a
        // real payment into manual review. `total` stays consistent with the snapshot.
        foreach (array('total', 'amount', 'balance') as $key) {
            if (isset($invoice[$key]) && is_scalar($invoice[$key]) && trim((string) $invoice[$key]) !== '') {
                return number_format((float) $invoice[$key], 2, '.', '');
            }
        }

        return (string) $row['amount'];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $row
     */
    private function currentInvoiceCurrency(array $invoice, array $row)
    {
        // The WHMCS GetInvoice API response carries no currency field, so this
        // fallback to the snapshot currency is the real source. We still try
        // `currencycode`/`currency` first for the DB-shaped rows some call sites
        // pass in — never let a missing field flip the AmountGuard currency check.
        foreach (array('currencycode', 'currency') as $key) {
            if (isset($invoice[$key]) && is_scalar($invoice[$key]) && trim((string) $invoice[$key]) !== '') {
                return strtoupper(trim((string) $invoice[$key]));
            }
        }

        return strtoupper((string) $row['currency']);
    }

    private function eventTypeForStatus($status)
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return ((string) $status === 'paid_over') ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return ((string) $status === 'expired') ? 'invoice.expired' : 'invoice.cancelled';
        }

        return 'invoice.updated';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }

    private function commitEvent()
    {
        if (method_exists($this->eventStore, 'commit')) {
            $this->eventStore->commit();
        }
    }

    private function releaseEvent()
    {
        if (method_exists($this->eventStore, 'release')) {
            $this->eventStore->release();
        }
    }
}
