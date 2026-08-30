<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;

final class Reconciler
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var WhmcsAdapterInterface */
    private $whmcs;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, WhmcsAdapterInterface $whmcs, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->whmcs = $whmcs;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $gatewayParams
     */
    public function run(array $gatewayParams, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $count = 0;

        foreach ($this->store->findUnpaidRecent(50, $now - 86400) as $row) {
            try {
                $invoice = $this->client((string) $row['environment'], $gatewayParams)->invoices()->get((string) $row['paymos_invoice_id']);
                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->whmcs->logTransaction('paymosgateway', array('invoice' => $invoice), 'Snapshot mismatch');
                    continue;
                }

                $applied = (new CallbackProcessor($this->whmcs, $this->store, new InMemoryEventStore(), $this->clientFactory))
                    ->applyTrustedInvoice($invoice, $row, $gatewayParams, $now);
                if ($applied) {
                    $count++;
                }
            } catch (\Exception $e) {
                $this->whmcs->logTransaction('paymosgateway', array('error' => $e->getMessage(), 'row' => $row), 'Reconcile error');
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice)
    {
        return $this->matches((string) $row['project_id'], $this->field($invoice, array('project_id')))
            && $this->matches((string) $row['external_order_id'], $this->field($invoice, array('order', 'external_id')))
            && $this->amountMatches((string) $row['amount'], $this->field($invoice, array('order', 'amount')))
            && $this->matches(strtoupper((string) $row['currency']), strtoupper($this->field($invoice, array('order', 'currency'))))
            && StatusMapper::invoiceAction('', $this->field($invoice, array('status'))) !== StatusMapper::ACTION_IGNORE;
    }

    private function matches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        return $expected === '' || $actual === '' || $expected === $actual;
    }

    /**
     * Amount equality must be decimal-safe: the server trims trailing zeros, so a
     * stored snapshot "100.00" and the API's "100" are the same amount. A raw
     * string === would treat them as different and silently skip every paid invoice
     * during reconciliation — route the comparison through the SDK guard instead.
     */
    private function amountMatches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        return $expected === '' || $actual === '' || AmountGuard::amountsEqual($expected, $actual);
    }

    /**
     * @param array<string, mixed> $gatewayParams
     */
    private function client($environment, array $gatewayParams)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client(Config::fromParams($gatewayParams)->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
