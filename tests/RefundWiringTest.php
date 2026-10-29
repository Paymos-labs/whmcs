<?php

declare(strict_types=1);

function whmcs_refund_wiring_scenario($scenario)
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/refund-entry.php')
        . ' ' . escapeshellarg($scenario);
    $output = array();
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    assertSameValue(0, $status, implode(PHP_EOL, $output));
    assertContainsValue('OK ' . $scenario, implode(PHP_EOL, $output), 'wiring scenario must execute.');
}

function test_whmcs_refund_wiring_entry_requires_saved_capability_before_http()
{
    whmcs_refund_wiring_scenario('capability');
}

function test_whmcs_refund_wiring_unbound_callback_is_409_without_money()
{
    whmcs_refund_wiring_scenario('unbound');
}

function test_whmcs_refund_wiring_over20_then_full100_through_entries_callback_and_cron()
{
    whmcs_refund_wiring_scenario('lifecycle');
}

function test_whmcs_refund_wiring_admin_permissions_csrf_and_durable_tokens()
{
    whmcs_refund_wiring_scenario('admin');
}
