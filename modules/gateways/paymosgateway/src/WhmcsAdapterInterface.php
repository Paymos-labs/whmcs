<?php

declare(strict_types=1);

namespace PaymosWhmcs;

interface WhmcsAdapterInterface
{
    /** Serialize callback and cron against the same WHMCS invoice. */
    public function withInvoiceLock($invoiceId, callable $action);

    public function checkInvoiceId($invoiceId, $gatewayModuleName);

    public function checkTransactionId($transactionId);

    public function addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName);

    public function logTransaction($gatewayModuleName, array $data, $status);

    /**
     * @return array<string, mixed> Empty only when WHMCS answers "Invoice ID Not
     *                              Found" — the invoice was deleted.
     * @throws \RuntimeException on any other failure: it says nothing about
     *                           whether the invoice exists.
     */
    public function getInvoice($invoiceId);

    /**
     * The currency the WHMCS invoice is kept in, as an upper-case code.
     *
     * WHMCS has no per-invoice currency: an invoice is in its client's currency,
     * and the GetInvoice response carries no currency field. This is NOT
     * $params['currency'] — with "Convert To For Processing" set, that is the
     * gateway's processing currency, and so is the Paymos invoice.
     *
     * @return string
     * @throws \RuntimeException when WHMCS cannot say.
     */
    public function invoiceCurrency($invoiceId);

    /**
     * Whether WHMCS already booked a payment with this transaction id. Unlike
     * checkTransactionId() (checkCbTransID), it never ends the process, so it is
     * the one to use outside a gateway callback — in the cron reconciler.
     *
     * @return bool
     */
    public function transactionExists($transactionId);
}
