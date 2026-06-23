<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;

final class GatewayLink
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, callable $clientFactory = null)
    {
        $this->store = $store;
        $this->clientFactory = $clientFactory;
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

        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)) {
            return $this->button($existing['payment_url'], $config->buttonText());
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
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            'status' => $this->responseField($response, array('status')) ?: 'created',
            'renew_count' => $renewCount,
        ));

        return $this->button($paymentUrl, $config->buttonText());
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

    private function button($url, $text)
    {
        $url = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');

        return '<form method="get" action="' . $url . '">'
            . '<button type="submit" class="btn btn-success">' . $text . '</button>'
            . '</form>';
    }

    private function client(Config $config)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config);
        }

        return new Client($config->clientConfig());
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
