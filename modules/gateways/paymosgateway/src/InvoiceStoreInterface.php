<?php

declare(strict_types=1);

namespace PaymosWhmcs;

interface InvoiceStoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByWhmcsInvoiceId($invoiceId);

    /**
     * A row of the WHMCS invoice, of any generation, that carries the
     * paid-late marker.
     *
     * @return array<string, mixed>|null
     */
    public function findPaidLateByWhmcsInvoiceId($invoiceId);

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId($externalOrderId);

    /**
     * @param array<string, mixed> $row
     */
    public function save(array $row);

    public function updateStatus($paymosInvoiceId, $status);

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent($limit, $sinceTimestamp);
}
