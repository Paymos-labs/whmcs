<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class InMemoryInvoiceStore implements InvoiceStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private $rowsByExternalOrderId = array();

    /** @var array<int, string> */
    private $externalOrderIdByWhmcsInvoiceId = array();

    public function findByWhmcsInvoiceId($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if (!isset($this->externalOrderIdByWhmcsInvoiceId[$invoiceId])) {
            return null;
        }

        $externalOrderId = $this->externalOrderIdByWhmcsInvoiceId[$invoiceId];
        return isset($this->rowsByExternalOrderId[$externalOrderId]) ? $this->rowsByExternalOrderId[$externalOrderId] : null;
    }

    public function findByExternalOrderId($externalOrderId)
    {
        $externalOrderId = (string) $externalOrderId;
        return isset($this->rowsByExternalOrderId[$externalOrderId]) ? $this->rowsByExternalOrderId[$externalOrderId] : null;
    }

    public function save(array $row)
    {
        $row = $this->normalize($row);
        $this->rowsByExternalOrderId[$row['external_order_id']] = $row;
        $this->externalOrderIdByWhmcsInvoiceId[(int) $row['whmcs_invoice_id']] = $row['external_order_id'];
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        foreach ($this->rowsByExternalOrderId as $externalOrderId => $row) {
            if ((string) $row['paymos_invoice_id'] === (string) $paymosInvoiceId) {
                $row['status'] = (string) $status;
                $this->rowsByExternalOrderId[$externalOrderId] = $row;
                return;
            }
        }
    }

    public function findUnpaidRecent($limit, $sinceTimestamp)
    {
        $rows = array_values($this->rowsByExternalOrderId);
        $result = array();
        foreach ($rows as $row) {
            if (in_array((string) $row['status'], array('paid', 'paid_over'), true)) {
                continue;
            }

            $result[] = $row;
            if (count($result) >= (int) $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row)
    {
        $defaults = array(
            'whmcs_invoice_id' => 0,
            'paymos_invoice_id' => '',
            'external_order_id' => '',
            'environment' => '',
            'project_id' => '',
            'amount' => '',
            'currency' => '',
            'payment_url' => '',
            'status' => '',
            'renew_count' => 0,
        );

        return array_merge($defaults, $row);
    }
}
