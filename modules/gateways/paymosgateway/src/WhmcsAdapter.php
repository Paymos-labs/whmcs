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
        if (function_exists('localAPI')) {
            $response = localAPI('GetInvoice', array('invoiceid' => (int) $invoiceId));
            if (is_array($response) && (!isset($response['result']) || $response['result'] === 'success')) {
                return $response;
            }
        }

        return array();
    }
}
