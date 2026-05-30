<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class CallbackResult
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $body;

    /** @var bool */
    private $duplicate;

    public function __construct($statusCode, $body = 'OK', $duplicate = false)
    {
        $this->statusCode = (int) $statusCode;
        $this->body = (string) $body;
        $this->duplicate = (bool) $duplicate;
    }

    public function statusCode()
    {
        return $this->statusCode;
    }

    public function body()
    {
        return $this->body;
    }

    public function isDuplicate()
    {
        return $this->duplicate;
    }
}
