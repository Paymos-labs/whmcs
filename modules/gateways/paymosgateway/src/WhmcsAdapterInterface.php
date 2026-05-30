<?php

declare(strict_types=1);

namespace PaymosWhmcs;

interface WhmcsAdapterInterface
{
    public function checkInvoiceId($invoiceId, $gatewayModuleName);

    public function checkTransactionId($transactionId);

    public function addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);

    public function logTransaction($gatewayModuleName, array $data, $status);

    /**
     * @return array<string, mixed>
     */
    public function getInvoice($invoiceId);
}
