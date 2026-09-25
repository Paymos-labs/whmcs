<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
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
        } catch (EventInProgressException $e) {
            // Another delivery of this event holds the lock and has not finished.
            // Not a duplicate: a 2xx would mark it delivered even if that delivery
            // then fails. 409 makes the server retry; the lock is not ours to drop.
            return new CallbackResult(409, 'In progress');
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

        return $this->applyEventToWhmcs($event, (string) $row['environment'], $row, $gatewayParams, false, true);
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

        return $this->applyEventToWhmcs($event, $environment, $row, $gatewayParams, $reverseVerify, false);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $gatewayParams
     */
    /**
     * @param bool $fromCron True on the reconciler path. checkCbInvoiceID() and
     *                       checkCbTransID() end the process with die() on an
     *                       unknown invoice or a known transaction — right for a
     *                       gateway callback, fatal inside AfterCronJob, where it
     *                       would stop every row and every later hook. The cron
     *                       path uses GetInvoice and a tblaccounts lookup instead.
     */
    private function applyEventToWhmcs(WebhookEvent $event, $environment, array $row, array $gatewayParams, $reverseVerify, $fromCron)
    {
        $gatewayName = $this->gatewayName($gatewayParams);
        if ($fromCron) {
            $whmcsInvoiceId = (int) $row['whmcs_invoice_id'];
            // getInvoice() is empty only on "Invoice ID Not Found"; any other
            // failure throws, and the reconciler leaves the row for the next run.
            if (count($this->whmcs->getInvoice($whmcsInvoiceId)) === 0) {
                // The WHMCS invoice was deleted. Nothing can be booked against it
                // any more; close the row so the next run does not fetch it again.
                $this->invoiceStore->updateStatus((string) $row['paymos_invoice_id'], InvoiceStore::STATUS_WHMCS_INVOICE_MISSING);
                $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Reconcile: WHMCS invoice not found');
                return false;
            }
        } else {
            $whmcsInvoiceId = $this->whmcs->checkInvoiceId((int) $row['whmcs_invoice_id'], $gatewayName);
        }
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
        $rowIsFinal = StatusMapper::isFinalStatus(isset($row['status']) ? (string) $row['status'] : '');
        if ($this->invoiceIsPaid($currentInvoice) && $this->isCompleteOrDowngrade($action)) {
            if (!$rowIsFinal) {
                $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());
            }
            $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Ignored (invoice already paid)');
            return false;
        }

        // Nothing leaves a final status on the server (Invoice.IsTerminal), so an
        // event that arrives after one is an out-of-order redelivery. Recording it
        // would reopen the row — putting it back into the reconciler's window —
        // and log a status the invoice left long ago.
        if ($rowIsFinal) {
            $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Ignored (stale status after a final one)');
            return false;
        }

        // The currency the WHMCS invoice is kept in. Asked before anything is
        // recorded: when WHMCS cannot answer, the RuntimeException leaves the
        // event for the redelivery (400) or the row for the next cron run —
        // a row already marked paid would make both of them ignore it.
        $invoiceCurrency = $action === StatusMapper::ACTION_PAYMENT_COMPLETE
            ? strtoupper(trim((string) $this->whmcs->invoiceCurrency($whmcsInvoiceId)))
            : '';

        $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());
        $this->whmcs->logTransaction($gatewayName, $event->toArray(), $event->status() === '' ? $action : $event->status());

        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            return false;
        }

        $currentAmount = $this->currentInvoiceAmount($currentInvoice, $row);

        // Three questions, three comparisons. The snapshot's `amount` is what the
        // Paymos invoice charged — the amount DUE when it was cut, which after a
        // partial payment is less than the WHMCS total. So: was it charged in the
        // currency the WHMCS invoice is kept in (with "Convert To For Processing"
        // it is not: WHMCS converted the amount before _link saw it, and 108.00
        // USD would be credited as 108.00 EUR), did the payment match the charge
        // (snapshot amount vs webhook order amount), and did the WHMCS invoice
        // stay the same (snapshot total vs current total)? A row written before
        // `invoice_total` existed has no total; it falls back to comparing the
        // charge with the current total, as before.
        $currencyMatches = $invoiceCurrency !== '' && strtoupper(trim((string) $row['currency'])) === $invoiceCurrency;
        $snapshotTotal = isset($row['invoice_total']) ? trim((string) $row['invoice_total']) : '';
        $expectedTotal = $snapshotTotal !== '' ? $snapshotTotal : (string) $row['amount'];
        $chargeMatches = AmountGuard::isSafeToComplete(
            $row['amount'],
            $row['currency'],
            $row['amount'],
            $row['currency'],
            $event->orderAmount(),
            $event->orderCurrency()
        );
        $invoiceUnchanged = AmountGuard::isSafeToComplete(
            $expectedTotal,
            $invoiceCurrency,
            $currentAmount,
            $invoiceCurrency
        );

        if (!$currencyMatches || !$chargeMatches || !$invoiceUnchanged) {
            // A currency or amount mismatch on a reverse-verified paid invoice is
            // not a transient failure — the figures won't change on redelivery. Do
            // not throw into the retry path (that 400s and the server retries
            // forever); hold the invoice for manual review and acknowledge (200).
            $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Manual review: ' . AmountGuard::mismatchSummary(
                $currencyMatches && $chargeMatches ? $expectedTotal : $row['amount'],
                $row['currency'],
                $currentAmount,
                $invoiceCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            ));
            return false;
        }

        $transactionId = $this->transactionId($event);
        if ($fromCron) {
            if ($this->whmcs->transactionExists($transactionId)) {
                $this->whmcs->logTransaction($gatewayName, $event->toArray(), 'Ignored (transaction already recorded)');
                return false;
            }
        } else {
            $this->whmcs->checkTransactionId($transactionId);
        }
        // Credit what the Paymos invoice charged (reverse-verified above), not the
        // WHMCS total: after a partial payment the total would over-credit.
        $this->whmcs->addInvoicePayment($whmcsInvoiceId, $transactionId, $this->amount($row['amount']), $this->paymentFee($event, $invoiceCurrency), $gatewayName);
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
     * The gateway fee WHMCS should record for this payment, in the WHMCS
     * invoice currency. The webhook carries it at data.payment.fee
     * (merchant-only) — but denominated in the PAID TOKEN
     * (data.payment.currency, e.g. USDT), while addInvoicePayment books the fee
     * in the WHMCS invoice currency ($invoiceCurrency, see
     * WhmcsAdapterInterface::invoiceCurrency — not the Paymos order currency,
     * which "Convert To For Processing" makes a different one). So:
     *   - token == invoice currency (a crypto-priced invoice) → the fee as is;
     *   - order currency != invoice currency → '0.00': the rate below converts
     *     into the ORDER currency, and nothing documented converts that back;
     *   - otherwise → fee × data.payment.exchange_rate (order currency per
     *     token, the rate the server applied when the buyer confirmed);
     *   - no usable rate → '0.00'. A token amount labelled as fiat (1 USDT
     *     booked as 1.00 RUB) is worse than no fee at all.
     * Sandbox events omit the payment object and also land on '0.00'.
     */
    private function paymentFee(WebhookEvent $event, $invoiceCurrency)
    {
        $payload = $event->toArray();
        $fee = $this->field($payload, array('data', 'payment', 'fee'));
        $invoiceCurrency = strtoupper(trim((string) $invoiceCurrency));
        if ($fee === '' || !is_numeric($fee) || $invoiceCurrency === '') {
            return '0.00';
        }

        $feeCurrency = strtoupper(trim($this->field($payload, array('data', 'payment', 'currency'))));
        if ($feeCurrency !== '' && $feeCurrency === $invoiceCurrency) {
            return number_format((float) $fee, 2, '.', '');
        }

        if (strtoupper(trim($event->orderCurrency())) !== $invoiceCurrency) {
            return '0.00';
        }

        $rate = $this->field($payload, array('data', 'payment', 'exchange_rate'));
        if ($rate === '' || !is_numeric($rate) || (float) $rate <= 0.0) {
            return '0.00';
        }

        return number_format((float) $fee * (float) $rate, 2, '.', '');
    }

    private function amount($value)
    {
        return number_format((float) $value, 2, '.', '');
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
        // The current WHMCS invoice TOTAL — compared with the snapshot's
        // `invoice_total`. Never `balance`: it shrinks as the invoice gets paid,
        // including by this very payment on a redelivery.
        foreach (array('total', 'amount', 'balance') as $key) {
            if (isset($invoice[$key]) && is_scalar($invoice[$key]) && trim((string) $invoice[$key]) !== '') {
                return number_format((float) $invoice[$key], 2, '.', '');
            }
        }

        return (string) $row['amount'];
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
