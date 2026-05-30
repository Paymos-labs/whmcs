<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class InvoiceStore implements InvoiceStoreInterface
{
    /** @var InMemoryInvoiceStore|null */
    private $fallback;

    public function findByWhmcsInvoiceId($invoiceId)
    {
        if (!$this->hasCapsule()) {
            return $this->fallback()->findByWhmcsInvoiceId($invoiceId);
        }

        $row = \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)
            ->where('whmcs_invoice_id', (int) $invoiceId)
            ->orderBy('id', 'desc')
            ->first();

        return $this->toArray($row);
    }

    public function findByExternalOrderId($externalOrderId)
    {
        if (!$this->hasCapsule()) {
            return $this->fallback()->findByExternalOrderId($externalOrderId);
        }

        $row = \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)
            ->where('external_order_id', (string) $externalOrderId)
            ->first();

        return $this->toArray($row);
    }

    public function save(array $row)
    {
        if (!$this->hasCapsule()) {
            $this->fallback()->save($row);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $data = array(
            'whmcs_invoice_id' => (int) $row['whmcs_invoice_id'],
            'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
            'external_order_id' => (string) $row['external_order_id'],
            'environment' => (string) $row['environment'],
            'project_id' => (string) $row['project_id'],
            'amount' => (string) $row['amount'],
            'currency' => strtoupper((string) $row['currency']),
            'payment_url' => (string) $row['payment_url'],
            'status' => (string) $row['status'],
            'renew_count' => isset($row['renew_count']) ? (int) $row['renew_count'] : 0,
            'updated_at' => $now,
        );

        $existing = \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)
            ->where('external_order_id', $data['external_order_id'])
            ->first();

        if ($existing) {
            \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)
                ->where('external_order_id', $data['external_order_id'])
                ->update($data);
            return;
        }

        $data['created_at'] = $now;
        \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)->insert($data);
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        if (!$this->hasCapsule()) {
            $this->fallback()->updateStatus($paymosInvoiceId, $status);
            return;
        }

        \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)
            ->where('paymos_invoice_id', (string) $paymosInvoiceId)
            ->update(array(
                'status' => (string) $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ));
    }

    public function findUnpaidRecent($limit, $sinceTimestamp)
    {
        if (!$this->hasCapsule()) {
            return $this->fallback()->findUnpaidRecent($limit, $sinceTimestamp);
        }

        $terminal = array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled');
        $rows = \WHMCS\Database\Capsule::table(Migrations::INVOICES_TABLE)
            ->whereNotIn('status', $terminal)
            ->where('created_at', '>=', date('Y-m-d H:i:s', (int) $sinceTimestamp))
            ->orderBy('id', 'desc')
            ->limit((int) $limit)
            ->get();

        $result = array();
        foreach ($rows as $row) {
            $array = $this->toArray($row);
            if ($array !== null) {
                $result[] = $array;
            }
        }

        return $result;
    }

    private function hasCapsule()
    {
        return class_exists('\\WHMCS\\Database\\Capsule');
    }

    private function fallback()
    {
        if ($this->fallback === null) {
            $this->fallback = new InMemoryInvoiceStore();
        }

        return $this->fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toArray($row)
    {
        if (!$row) {
            return null;
        }

        return is_array($row) ? $row : get_object_vars($row);
    }
}
