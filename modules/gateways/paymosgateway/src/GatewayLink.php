<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\StatusMapper;

final class GatewayLink
{
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

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)
            && $this->keepsExistingInvoice($existing, $config)) {
            return $this->button($existing['payment_url'], $buttonText);
        }

        if (is_array($existing)) {
            $this->closeBeforeReplacing($existing, $config);
        }

        $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        $externalOrderId = 'whmcs_' . $invoiceId . '_' . $renewCount;
        $payload = $this->createPayload($params, $config, $amount, $currency, $externalOrderId);
        $response = $this->client($config)->invoices()->create($payload);
        $paymosInvoiceId = $this->responseField($response, array('invoice_id'));
        $paymentUrl = $this->responseField($response, array('payment_url'));
        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }

        $this->store->save(array(
            'whmcs_invoice_id' => $invoiceId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'invoice_total' => $this->invoiceTotal($invoiceId),
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            'status' => $this->responseField($response, array('status')) ?: 'created',
            'renew_count' => $renewCount,
        ));

        return $this->button($paymentUrl, $buttonText);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function createPayload(array $params, Config $config, $amount, $currency, $externalOrderId)
    {
        $payload = array(
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
            'allow_multiple_payments' => true,
        );

        $clientId = $this->clientId($params);
        if ($clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        return $payload;
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
        $environment = (string) $row['environment'];
        $recorded = isset($row['status']) ? (string) $row['status'] : '';
        $result = (new InvoiceReplacement(function () use ($config, $environment) {
            return $this->client($config, $environment);
        }))->close((string) $row['paymos_invoice_id'], $recorded);

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
    private function invoiceTotal($invoiceId)
    {
        try {
            $invoice = $this->whmcs->getInvoice($invoiceId);
        } catch (\RuntimeException $e) {
            return '';
        }
        if (isset($invoice['total']) && is_scalar($invoice['total']) && is_numeric(trim((string) $invoice['total']))) {
            return $this->amount($invoice['total']);
        }

        return '';
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
