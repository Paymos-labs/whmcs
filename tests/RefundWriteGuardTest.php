<?php

declare(strict_types=1);

function test_whmcs_refund_write_guards_before_model_writes()
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/refund-guards.php');
    $output = array();
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    assertSameValue(0, $status, implode(PHP_EOL, $output));
    assertContainsValue('OK refund model guards', implode(PHP_EOL, $output), 'model guard scenarios must execute.');
}

function test_whmcs_refund_write_guard_and_projection_on_whmcs91_payment_transaction()
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/refund-whmcs91.php');
    $output = array();
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    assertSameValue(0, $status, implode(PHP_EOL, $output));
    assertContainsValue('OK refund whmcs91', implode(PHP_EOL, $output), 'WHMCS 9.1 Payment\\Transaction guard and projection scenarios must execute.');
}
