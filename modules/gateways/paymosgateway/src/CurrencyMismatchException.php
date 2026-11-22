<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class CurrencyMismatchException extends \RuntimeException
{
    /** @var string */
    private $invoiceCurrency;

    /** @var string */
    private $processingCurrency;

    public function __construct(string $invoiceCurrency, string $processingCurrency)
    {
        $this->invoiceCurrency = $invoiceCurrency;
        $this->processingCurrency = $processingCurrency;
        parent::__construct(
            'Paymos does not support WHMCS Convert To For Processing ('
            . $invoiceCurrency
            . ' -> '
            . $processingCurrency
            . ').'
        );
    }

    public function invoiceCurrency(): string
    {
        return $this->invoiceCurrency;
    }

    public function processingCurrency(): string
    {
        return $this->processingCurrency;
    }
}
