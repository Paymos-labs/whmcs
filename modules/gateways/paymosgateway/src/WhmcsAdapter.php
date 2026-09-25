<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class WhmcsAdapter implements WhmcsAdapterInterface
{
    public function checkInvoiceId($invoiceId, $gatewayModuleName)
    {
        if (!function_exists('checkCbInvoiceID')) {
            throw new \RuntimeException('WHMCS callback invoice helper is unavailable.');
        }

        return checkCbInvoiceID($invoiceId, $gatewayModuleName);
    }

    public function checkTransactionId($transactionId)
    {
        if (!function_exists('checkCbTransID')) {
            throw new \RuntimeException('WHMCS callback transaction helper is unavailable.');
        }

        return checkCbTransID($transactionId);
    }

    public function addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName)
    {
        if (!function_exists('addInvoicePayment')) {
            throw new \RuntimeException('WHMCS add invoice payment helper is unavailable.');
        }

        return addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);
    }

    public function logTransaction($gatewayModuleName, array $data, $status)
    {
        if (function_exists('logTransaction')) {
            logTransaction($gatewayModuleName, $data, $status);
        }
    }

    public function getInvoice($invoiceId)
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('WHMCS local API is unavailable.');
        }

        $response = localAPI('GetInvoice', array('invoiceid' => (int) $invoiceId));
        if (!is_array($response)) {
            throw new \RuntimeException('WHMCS GetInvoice returned no response.');
        }
        if (!isset($response['result']) || $response['result'] === 'success') {
            return $response;
        }

        // developers.whmcs.com/api-reference/getinvoice/ documents one error
        // response, "Invoice ID Not Found" — the only answer that says the
        // invoice is gone. A database or API failure says nothing about it.
        $message = isset($response['message']) && is_scalar($response['message']) ? trim((string) $response['message']) : '';
        if (strcasecmp($message, 'Invoice ID Not Found') === 0) {
            return array();
        }

        throw new \RuntimeException('WHMCS GetInvoice failed: ' . ($message !== '' ? $message : 'no message'));
    }

    public function invoiceCurrency($invoiceId)
    {
        // GetInvoice names the client (userid); GetClientsDetails names the
        // client's currency (client.currency_code). The invoice is kept in it.
        $invoice = $this->getInvoice($invoiceId);
        $clientId = isset($invoice['userid']) && is_scalar($invoice['userid']) ? (int) $invoice['userid'] : 0;
        if ($clientId <= 0) {
            throw new \RuntimeException('WHMCS invoice ' . (int) $invoiceId . ' could not be read to find its currency.');
        }
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('WHMCS local API is unavailable.');
        }

        $response = localAPI('GetClientsDetails', array('clientid' => $clientId));
        if (!is_array($response) || !isset($response['result']) || $response['result'] !== 'success') {
            $message = is_array($response) && isset($response['message']) && is_scalar($response['message'])
                ? (string) $response['message']
                : 'no response';
            throw new \RuntimeException('WHMCS GetClientsDetails failed: ' . $message);
        }

        $sources = array(
            isset($response['client']) && is_array($response['client']) ? $response['client'] : array(),
            $response,
        );
        foreach ($sources as $source) {
            if (isset($source['currency_code']) && is_scalar($source['currency_code']) && trim((string) $source['currency_code']) !== '') {
                return strtoupper(trim((string) $source['currency_code']));
            }
        }

        throw new \RuntimeException('WHMCS did not report the currency of client ' . $clientId . '.');
    }

    public function transactionExists($transactionId)
    {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            throw new \RuntimeException('WHMCS database is unavailable.');
        }

        // tblaccounts is where addInvoicePayment() books the transaction and
        // where checkCbTransID() looks for it.
        return \WHMCS\Database\Capsule::table('tblaccounts')
            ->where('transid', (string) $transactionId)
            ->exists();
    }
}
