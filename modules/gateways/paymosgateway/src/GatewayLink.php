<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\InvoicePresentation;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\InvoiceReplacementResult;
use Paymos\Plugin\StatusMapper;

final class GatewayLink
{
    /** Snapshot status of a create whose POST was sent but whose result is not bound yet. */
    const STATUS_CREATING = 'creating';

    /**
     * Snapshot status of a create the server refused for good (a 4xx other than
     * 408/429). The server made no invoice for it: the row is never replayed,
     * read or cancelled, and the next render cuts the next key.
     */
    const STATUS_CREATE_FAILED = 'create_failed';

    /** @var InvoiceStoreInterface */
    private $store;

    /** @var callable|null */
    private $clientFactory;

    /** @var WhmcsAdapterInterface */
    private $whmcs;

    public function __construct(InvoiceStoreInterface $store, ?callable $clientFactory = null, ?WhmcsAdapterInterface $whmcs = null)
    {
        $this->store = $store;
        $this->clientFactory = $clientFactory;
        $this->whmcs = $whmcs !== null ? $whmcs : new WhmcsAdapter();
    }

    /**
     * The notice paymosgateway_link() shows the client when render() throws.
     *
     * @param array<string, mixed> $params
     * @return string
     */
    public static function failureNotice(\Throwable $e, array $params)
    {
        if ($e instanceof InvoiceReplacementBlockedException) {
            // The old Paymos invoice may still be paid and closeBeforeReplacing()
            // already logged it for manual review. "Temporarily unavailable"
            // would send the client to another gateway on this invoice, so they
            // are asked to contact the store instead (BUG-180).
            return self::alert(Translation::text('replacement_blocked', $params));
        }

        if ($e instanceof CurrencyMismatchException) {
            // assertNoProcessingCurrencyMismatch() already logged the gateway
            // configuration mismatch for the administrator.
            return self::alert(Translation::text('payment_cannot_be_used', $params));
        }

        if ($e instanceof ApiException) {
            // A structured API error (e.g. an unsupported currency → 400 validation)
            // carries an actionable detail/field. Surface it instead of the generic
            // "temporarily unavailable" message, and log the specifics so the admin
            // can see exactly which field the server rejected.
            if (function_exists('logTransaction')) {
                logTransaction('paymosgateway', array(
                    'error' => $e->getMessage(),
                    'code' => $e->errorCode(),
                    'field' => $e->field(),
                    'detail' => $e->detail(),
                ), 'Error');
            }

            // The API's detail is English. On a store that reads another language it
            // would sit inside a translated page, so prefer our own localized line.
            $detail = $e->detail();
            if ($detail === null || $detail === '' || !Translation::isEnglish($params)) {
                $detail = Translation::text('payment_cannot_be_used', $params);
            }
            $field = $e->field();
            if ($field !== null && $field !== '') {
                $detail .= ' (' . $field . ')';
            }

            return self::alert($detail);
        }

        if (function_exists('logTransaction')) {
            logTransaction('paymosgateway', array('error' => $e->getMessage()), 'Error');
        }

        return self::alert(Translation::text('payment_unavailable', $params));
    }

    /**
     * @param string $text
     * @return string
     */
    private static function alert($text)
    {
        return '<div class="alert alert-danger">' . htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(array $params)
    {
        $config = Config::fromParams($params);
        $invoiceId = (int) $this->field($params, 'invoiceid');
        $amount = $this->amount($this->field($params, 'amount'));
        $currency = strtoupper($this->field($params, 'currency'));
        $existing = $this->store->findByWhmcsInvoiceId($invoiceId);
        $buttonText = $this->buttonText($params, $config);
        if (is_array($existing) && self::isPendingIntent($existing)) {
            // A previous create may have been accepted by the server while its
            // response or the local bind was lost (A46-01). Replay that exact
            // operation first; only the invoice it yields may be replaced.
            $existing = $this->recoverPendingIntent($params, $existing, $config);
        }

        // invoice.paid_late marks the row its event names, which may be an older
        // generation than the latest row: any marked row blocks the invoice.
        $paidLate = is_array($existing) && !empty($existing['paid_late_noted'])
            ? $existing
            : $this->store->findPaidLateByWhmcsInvoiceId($invoiceId);
        if (is_array($paidLate)) {
            $this->blockPaidLate($paidLate, 'Paymos invoice was paid late and requires manual review.');
        }

        $this->assertNoProcessingCurrencyMismatch($invoiceId, $currency);

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)
            && $this->keepsExistingInvoice($existing, $config)) {
            return $this->button($existing['payment_url'], $buttonText);
        }

        if (is_array($existing) && !self::isFailedCreate($existing)) {
            $this->closeBeforeReplacing($existing, $config);
        }

        $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        $externalOrderId = 'whmcs_' . $invoiceId . '_' . $renewCount;
        $whmcsInvoice = $this->fetchWhmcsInvoice($invoiceId);
        $payload = $this->createPayload($params, $config, $amount, $currency, $externalOrderId, $whmcsInvoice);

        // The operation identity (external_order_id) and every field the server
        // compares on a repeated key (project, amount, currency, environment) are
        // stored BEFORE the POST. If its result is lost, the next render replays
        // exactly this request instead of re-sending the key with a new amount.
        $intent = array(
            'whmcs_invoice_id' => $invoiceId,
            'paymos_invoice_id' => self::pendingInvoiceId($externalOrderId),
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'invoice_total' => $this->invoiceTotalFromInvoice($whmcsInvoice),
            'currency' => $currency,
            'payment_url' => '',
            'status' => self::STATUS_CREATING,
            'renew_count' => $renewCount,
        );
        $this->store->save($intent);

        try {
            $response = $this->client($config)->invoices()->create($payload);
        } catch (ApiException $e) {
            if (self::isDefinitiveRejection($e)) {
                $this->markCreateFailed($intent);
            }
            throw $e;
        }
        $row = $this->bindCreatedInvoice($intent, $response);

        return $this->button($row['payment_url'], $buttonText);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isFailedCreate(array $row)
    {
        return isset($row['status']) && (string) $row['status'] === self::STATUS_CREATE_FAILED;
    }

    /**
     * 400, 409, 410 and 422 refuse the invoice data itself: the server made no
     * invoice for it and the same request will be refused again. 401, 403 and
     * 404 point at the key or the project, which the merchant fixes without
     * touching the invoice, so they stay with the intent for the replay, like
     * 408, 429, 5xx and transport errors.
     */
    private static function isDefinitiveRejection(ApiException $e)
    {
        return in_array($e->statusCode(), array(400, 409, 410, 422), true);
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function markCreateFailed(array $intent)
    {
        $intent['status'] = self::STATUS_CREATE_FAILED;
        $this->store->save($intent);

        return $intent;
    }

    /**
     * Whether the row is a create intent stored before its POST and not yet
     * bound to the invoice the server returned. Such a row has no Paymos
     * invoice id: callbacks cannot match it and the reconciler skips it.
     *
     * @param array<string, mixed> $row
     */
    public static function isPendingIntent(array $row)
    {
        return isset($row['status']) && (string) $row['status'] === self::STATUS_CREATING;
    }

    /**
     * Placeholder for the unique paymos_invoice_id column while the intent is
     * pending: unique per external_order_id, never a real Paymos invoice id.
     */
    private static function pendingInvoiceId($externalOrderId)
    {
        return 'pending:' . $externalOrderId;
    }

    /**
     * Replays the stored create request: same project, external_order_id,
     * amount and currency, sent to the environment it was first sent to. The
     * server answers a repeated key with the invoice it already made, so this
     * learns the invoice the lost response carried. When the server refuses
     * the replay for good (see isDefinitiveRejection) it holds no invoice for
     * this key that the client could have reached: the intent is closed as
     * create_failed and the render goes on to the next key. On any other
     * failure the intent stays pending and the render fails; no new key is cut
     * over it.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function recoverPendingIntent(array $params, array $intent, Config $config)
    {
        $payload = array(
            'project_id' => (string) $intent['project_id'],
            'amount' => (string) $intent['amount'],
            'currency' => (string) $intent['currency'],
            'external_order_id' => (string) $intent['external_order_id'],
        );
        // Not compared by the server on a repeated key; derived from the WHMCS
        // client exactly as on the first attempt.
        $clientId = $this->clientId($params);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        $invoiceId = isset($intent['whmcs_invoice_id']) ? (int) $intent['whmcs_invoice_id'] : 0;
        $whmcsInvoice = $this->fetchWhmcsInvoice($invoiceId);
        $products = $this->extractInvoiceProducts($invoiceId, (string) $intent['currency'], $whmcsInvoice);
        if ($products !== null) {
            $payload['products'] = $products;
        }

        try {
            $response = $this->client($config, (string) $intent['environment'])->invoices()->create($payload);
        } catch (ApiException $e) {
            if (!self::isDefinitiveRejection($e)) {
                throw $e;
            }

            return $this->markCreateFailed($intent);
        }

        return $this->bindCreatedInvoice($intent, $response);
    }

    /**
     * Records the invoice the server returned for a stored intent.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function bindCreatedInvoice(array $intent, array $response)
    {
        $paymosInvoiceId = $this->responseField($response, array('invoice_id'));
        $paymentUrl = $this->responseField($response, array('payment_url'));
        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }
        $status = $this->responseField($response, array('status'));

        $row = $intent;
        $row['paymos_invoice_id'] = $paymosInvoiceId;
        $row['payment_url'] = $paymentUrl;
        // A recovered idempotent response can already be terminal without
        // its payment having been booked in WHMCS. Leave native apply open.
        $row['status'] = StatusMapper::isFinalStatus($status) ? 'created' : ($status ?: 'created');
        $this->store->save($row);

        return $row;
    }

    /**
     * @param array<string, mixed>      $params
     * @param array<string, mixed>|null $whmcsInvoice
     * @return array<string, mixed>
     */
    private function createPayload(array $params, Config $config, $amount, $currency, $externalOrderId, ?array $whmcsInvoice = null)
    {
        $payload = array(
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
        );

        $clientId = $this->clientId($params);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        $invoiceId = (int) $this->field($params, 'invoiceid');
        if ($whmcsInvoice === null) {
            $whmcsInvoice = $this->fetchWhmcsInvoice($invoiceId);
        }
        $products = $this->extractInvoiceProducts($invoiceId, (string) $currency, $whmcsInvoice);
        if ($products !== null) {
            $payload['products'] = $products;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<int, array<string, mixed>>|null
     */
    private function extractInvoiceProducts(int $invoiceId, string $currency, array $invoice): ?array
    {
        if ($invoiceId <= 0 || count($invoice) === 0 || !isset($invoice['items']) || !is_array($invoice['items'])) {
            return null;
        }

        try {
            $nativeCurrency = strtoupper(trim((string) $this->whmcs->invoiceCurrency($invoiceId)));
        } catch (\RuntimeException $e) {
            return null;
        }
        if ($nativeCurrency === '' || $nativeCurrency !== strtoupper(trim($currency))) {
            // Convert To For Processing is active: invoice line items use client currency,
            // while Paymos invoice amount uses the gateway processing currency.
            return null;
        }

        if (array_key_exists('item', $invoice['items'])) {
            if (!is_array($invoice['items']['item'])) {
                return null;
            }
            $rawItems = $invoice['items']['item'];
            if (isset($rawItems['id']) || isset($rawItems['description']) || isset($rawItems['type'])) {
                $rawItems = array($rawItems);
            }
        } else {
            $rawItems = $invoice['items'];
        }

        if (!is_array($rawItems) || count($rawItems) === 0) {
            return null;
        }

        $candidates = array();
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                return null;
            }
            if ($this->isExcludedLineItem($item)) {
                continue;
            }

            if (array_key_exists('title', $item)) {
                $title = is_string($item['title']) ? $item['title'] : null;
                $description = isset($item['description']) && is_string($item['description']) ? $item['description'] : null;
            } else {
                $rawDescription = isset($item['description']) && is_string($item['description']) ? $item['description'] : null;
                if ($rawDescription === null) {
                    $title = null;
                    $description = null;
                } else {
                    $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $rawDescription));
                    $title = array_shift($lines);
                    $rest = trim(implode("\n", $lines));
                    $description = $rest !== '' ? $rest : null;
                }
            }

            $quantity = array_key_exists('quantity', $item)
                ? $item['quantity']
                : (array_key_exists('qty', $item) ? $item['qty'] : 1);
            $rawUnitAmount = array_key_exists('unit_amount', $item)
                ? $item['unit_amount']
                : (array_key_exists('amount', $item) ? $item['amount'] : null);

            $candidates[] = array(
                'title' => $title,
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => self::formatUnitAmount($rawUnitAmount),
            );
        }

        return InvoicePresentation::normalizeProducts($candidates);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isExcludedLineItem(array $item): bool
    {
        $type = isset($item['type']) && is_scalar($item['type']) ? strtolower(trim((string) $item['type'])) : '';
        if ($type === '') {
            return false;
        }

        $excluded = array(
            'promohosting',
            'promodomain',
            'groupdiscount',
            'discount',
            'latefee',
            'domaingracefee',
            'domainredemptionfee',
            'fee',
            'paymentfee',
            'gatewayfee',
            'tax',
            'shipping',
        );
        if (in_array($type, $excluded, true)) {
            return true;
        }

        return strncmp($type, 'promo', 5) === 0 || substr($type, -8) === 'discount';
    }

    /**
     * @param mixed $raw
     */
    private static function formatUnitAmount($raw): ?string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }
        if (is_float($raw)) {
            if (!is_finite($raw)) {
                return null;
            }
            if ($raw < 0.0) {
                return '-1';
            }
            if ($raw == 0.0) {
                return '0';
            }
            $formatted = json_encode($raw);
            if (!is_string($formatted) || $formatted === '' || strpos($formatted, ',') !== false || stripos($formatted, 'e') !== false) {
                $formatted = rtrim(rtrim(sprintf('%.18F', $raw), '0'), '.');
            }
            if ($formatted === '' || $formatted === '0') {
                return null;
            }
            return $formatted;
        }
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/\A(0|[1-9][0-9]*)\.([0-9]+)\z/', $trimmed, $m) === 1) {
            $frac = rtrim($m[2], '0');
            if (strlen($m[2]) > 2 && strlen($frac) <= 2) {
                return $m[1] . '.' . str_pad($frac, 2, '0');
            }
        }

        return $trimmed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, $amount, $currency, Config $config)
    {
        return (string) $row['amount'] === (string) $amount
            && strtoupper((string) $row['currency']) === strtoupper((string) $currency)
            && (string) $row['project_id'] === $config->projectId()
            && (string) $row['environment'] === $config->environment()
            && trim((string) $row['payment_url']) !== '';
    }

    /**
     * Whether the Paymos invoice behind a matching snapshot is still the one to
     * send the buyer to.
     *
     * A matching amount is not enough: the server answers a repeated
     * external_order_id with the same invoice whatever became of it, and a buyer
     * returning after it ended would land on an expired checkout. Its deadline is
     * the server's, not a copy kept here: confirming a network moves expires_at to
     * now + InvoiceOptions.PaymentTtl and sends no webhook. So: a row that already
     * ended unpaid is renewed at once (that final status came from the server and
     * never changes again); a paid one is kept (a second invoice would invite a
     * second payment); anything else is read back from the server (one GET) and
     * renewed only if the server says it ended unpaid or was never started before
     * its deadline (InvoiceRenewal). An invoice the server holds open — network
     * picked, funds confirming, part paid — is kept. When the server cannot be
     * reached the existing link is kept — the checkout it leads to is down just the
     * same. A 404 is not proof the invoice is gone (see closeBeforeReplacing), so
     * it goes on to the replacement, which refuses it.
     *
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row, Config $config)
    {
        if (InvoiceRenewal::isRequired($row)) {
            return false;
        }
        if (StatusMapper::isFinalStatus(isset($row['status']) ? (string) $row['status'] : '')) {
            return true;
        }

        try {
            $invoice = $this->client($config)->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Exception $e) {
            return true;
        }

        if (is_array($invoice) && !empty($invoice['paid_late_at'])) {
            $row['paid_late_noted'] = is_scalar($invoice['paid_late_at'])
                ? (string) $invoice['paid_late_at']
                : '1';
            $this->store->save($row);
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if (!InvoiceRenewal::isRequired($invoice)) {
            return true;
        }

        $status = $this->responseField($invoice, array('status'));
        if ($status !== '') {
            $this->store->updateStatus((string) $row['paymos_invoice_id'], $status);
        }

        return false;
    }

    private function button($url, $text)
    {
        $url = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');

        return '<form method="get" action="' . $url . '">'
            . '<button type="submit" class="btn btn-success">' . $text . '</button>'
            . '</form>';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buttonText(array $params, Config $config)
    {
        $configured = $config->buttonText();
        if ($configured !== '' && $configured !== 'Pay with Paymos') {
            return $configured;
        }

        return Translation::text('pay_button', $params);
    }

    /**
     * The invoice's Paymos invoice is about to be replaced (the amount due
     * changed, or it can no longer be paid). Cancel it on the server first, or
     * the client could pay both (BUG-166): the SDK cancels it, or confirms from
     * the server that it ended unpaid. Anything else — paid, still payable,
     * 404, no answer — keeps the old invoice, writes a manual-review entry to
     * the gateway log and stops (paymosgateway_link shows the unavailable
     * notice).
     *
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing(array $row, Config $config)
    {
        if (!empty($row['paid_late_noted'])) {
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        $environment = (string) $row['environment'];
        $recorded = isset($row['status']) ? (string) $row['status'] : '';
        if (self::endedUnpaid($recorded)) {
            // The SDK closes a row recorded as ended unpaid without a request.
            // A payment that arrived after that is known to the server
            // (paid_late_at) before invoice.paid_late reaches the callback.
            $this->blockIfPaidLateOnServer($row, $config, $environment);
        }
        $observed = new \stdClass();
        $observed->invoice = null;
        $result = (new InvoiceReplacement(function () use ($config, $environment, $observed) {
            $client = $this->client($config, $environment);

            return new class($client, $observed) {
                /** @var object */
                private $client;

                /** @var \stdClass */
                private $observed;

                /**
                 * @param object $client
                 */
                public function __construct($client, \stdClass $observed)
                {
                    $this->client = $client;
                    $this->observed = $observed;
                }

                public function invoices()
                {
                    return new class($this->client->invoices(), $this->observed) {
                        /** @var object */
                        private $invoices;

                        /** @var \stdClass */
                        private $observed;

                        /**
                         * @param object $invoices
                         */
                        public function __construct($invoices, \stdClass $observed)
                        {
                            $this->invoices = $invoices;
                            $this->observed = $observed;
                        }

                        public function cancel($invoiceId, $reason)
                        {
                            $response = $this->invoices->cancel($invoiceId, $reason);
                            if (is_array($response)
                                && (!empty($response['paid_late_at'])
                                    || !is_array($this->observed->invoice)
                                    || empty($this->observed->invoice['paid_late_at']))) {
                                $this->observed->invoice = $response;
                            }

                            return $response;
                        }

                        public function get($invoiceId)
                        {
                            $response = $this->invoices->get($invoiceId);
                            if (is_array($response)
                                && (!empty($response['paid_late_at'])
                                    || !is_array($this->observed->invoice)
                                    || empty($this->observed->invoice['paid_late_at']))) {
                                $this->observed->invoice = $response;
                            }

                            return $response;
                        }
                    };
                }
            };
        }))->close((string) $row['paymos_invoice_id'], $recorded);

        if (is_array($observed->invoice) && !empty($observed->invoice['paid_late_at'])) {
            $row['paid_late_noted'] = is_scalar($observed->invoice['paid_late_at'])
                ? (string) $observed->invoice['paid_late_at']
                : '1';
            $this->store->save($row);
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if ($result->isClosed()) {
            // Record the final status before the new row exists, so the old
            // invoice's own webhook (invoice.cancelled after our cancel) finds
            // a final row and is ignored as stale.
            if ($result->status() !== '' && $result->status() !== $recorded) {
                $this->store->updateStatus((string) $row['paymos_invoice_id'], $result->status());
            }

            return;
        }

        $this->whmcs->logTransaction('paymosgateway', array(
            'whmcs_invoice_id' => isset($row['whmcs_invoice_id']) ? (string) $row['whmcs_invoice_id'] : '',
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
            'detail' => $result->detail(),
        ), 'Manual review: ' . $result->summary());

        throw new InvoiceReplacementBlockedException($result);
    }

    private static function endedUnpaid(string $status): bool
    {
        return in_array(
            StatusMapper::invoiceAction('', strtolower(trim($status))),
            array(StatusMapper::ACTION_CANCEL_ORDER, StatusMapper::ACTION_FAIL_ORDER),
            true
        );
    }

    /**
     * One read of the invoice before a row recorded as ended unpaid is
     * replaced. No answer is no evidence of a late payment: the recorded
     * final status decides, as it did without the read.
     *
     * @param array<string, mixed> $row
     */
    private function blockIfPaidLateOnServer(array $row, Config $config, string $environment): void
    {
        try {
            $invoice = $this->client($config, $environment)->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (\Exception $e) {
            return;
        }

        if (is_array($invoice) && !empty($invoice['paid_late_at'])) {
            $row['paid_late_noted'] = is_scalar($invoice['paid_late_at'])
                ? (string) $invoice['paid_late_at']
                : '1';
            $this->store->save($row);
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function blockPaidLate(array $row, string $detail): void
    {
        $result = InvoiceReplacementResult::blocked(
            isset($row['paymos_invoice_id']) ? (string) $row['paymos_invoice_id'] : '',
            isset($row['status']) ? (string) $row['status'] : '',
            InvoiceReplacementResult::REASON_PAID,
            $detail
        );

        $this->whmcs->logTransaction('paymosgateway', array(
            'whmcs_invoice_id' => isset($row['whmcs_invoice_id']) ? (string) $row['whmcs_invoice_id'] : '',
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
            'detail' => $result->detail(),
        ), 'Manual review: ' . $result->summary());

        throw new InvoiceReplacementBlockedException($result);
    }

    private function assertNoProcessingCurrencyMismatch(int $invoiceId, string $currency): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $processingCurrency = strtoupper(trim($currency));
        if ($processingCurrency === '') {
            return;
        }

        try {
            $nativeCurrency = strtoupper(trim((string) $this->whmcs->invoiceCurrency($invoiceId)));
        } catch (\RuntimeException $e) {
            return;
        }

        if ($nativeCurrency !== '' && $nativeCurrency !== $processingCurrency) {
            $this->whmcs->logTransaction('paymosgateway', array(
                'whmcs_invoice_id' => (string) $invoiceId,
                'invoice_currency' => $nativeCurrency,
                'processing_currency' => $processingCurrency,
            ), 'Currency mismatch: disable Convert To For Processing');

            throw new CurrencyMismatchException($nativeCurrency, $processingCurrency);
        }
    }

    /**
     * @param string|null $environment The environment the invoice lives in; the selected mode by default.
     */
    private function client(Config $config, $environment = null)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config, $environment);
        }

        return new Client($environment === null
            ? $config->clientConfig()
            : $config->clientConfigForEnvironment($environment));
    }

    /**
     * The WHMCS invoice total at the moment the Paymos invoice is cut.
     *
     * `$params['amount']` is the amount still DUE, not the total: after a
     * partial payment or applied credit it is smaller. The callback checks that
     * the invoice itself did not change by comparing this total with the
     * current one — comparing the amount due with the total would send every
     * paid balance to manual review. Empty when WHMCS cannot say (the callback
     * then falls back to comparing the amount due with the total).
     */
    /**
     * @return array<string, mixed>
     */
    private function fetchWhmcsInvoice(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return array();
        }
        try {
            $invoice = $this->whmcs->getInvoice($invoiceId);
            return is_array($invoice) ? $invoice : array();
        } catch (\RuntimeException $e) {
            return array();
        }
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function invoiceTotalFromInvoice(array $invoice): string
    {
        if (isset($invoice['total']) && is_scalar($invoice['total']) && is_numeric(trim((string) $invoice['total']))) {
            return $this->amount($invoice['total']);
        }

        return '';
    }

    private function invoiceTotal($invoiceId)
    {
        return $this->invoiceTotalFromInvoice($this->fetchWhmcsInvoice((int) $invoiceId));
    }

    private function amount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function clientId(array $params)
    {
        if (isset($params['clientdetails']) && is_array($params['clientdetails'])) {
            return $this->clientIdFromModel(isset($params['clientdetails']['model']) ? $params['clientdetails']['model'] : null);
        }

        return '';
    }

    private function clientIdFromModel($model)
    {
        if (!is_object($model)) {
            return '';
        }

        if (isset($model->id) && is_scalar($model->id)) {
            return $this->normalizedClientId($model->id);
        }

        return '';
    }

    private function normalizedClientId($value)
    {
        $value = trim((string) $value);
        return $value !== '' && $value !== '0' ? $value : '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function field(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function responseField(array $source, array $path)
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
