<?php

declare(strict_types=1);

/**
 * Run only on an approved WHMCS 8.13/9 stand, never with bootstrap.php doubles.
 * PAYMOS_WHMCS_REFUND_NATIVE_BOOTSTRAP must return:
 *   version: actual installed version; package_file: pinned native package file;
 *   package_sha256: independently pinned hash; run: callable(string scenario): array.
 * run must exercise installed platform APIs and return status=passed|failed|skipped,
 * evidence: nonempty existing file paths, reason: required for skipped/failed.
 * Credentials belong to the stand bootstrap, never this repository.
 */
function paymos_whmcs_refund_native_fixture(): array
{
    $scenarios = array(
        'protected_full_and_managed_overpayment_permission_csrf',
        'partial_manual_refund_rejected_before_http',
        'over20_paid_refunded0_then_full100_unique_amountout',
        'lost_post_response_restart_same_key_and_saved_environment',
        'scoped_signed_callback_before_binding_and_cron_recovery',
        'crash_after_addtransaction_exact_transid_recovery',
        'duplicate_transid_and_ambiguous_native_row',
        'reorg_recompletion_no_stock_provisioning_credit_or_mail',
        'install_upgrade_reinstall_preserves_intents_and_markers',
        'ordinary_gateway_payments_and_notifications_unchanged',
    );
    $path = getenv('PAYMOS_WHMCS_REFUND_NATIVE_BOOTSTRAP');
    if (!$path || !is_file($path)) {
        return array_fill_keys($scenarios, array('status' => 'skipped',
            'reason' => 'Approved native WHMCS 8.13/9 stand and package bootstrap are unavailable.', 'evidence' => array()));
    }
    $fixture = require $path;
    if (!is_array($fixture) || !isset($fixture['version'], $fixture['package_file'], $fixture['package_sha256'], $fixture['run'])
        || !preg_match('/^(?:8\.13\.|9\.)/', $fixture['version']) || !is_callable($fixture['run'])
        || !is_file($fixture['package_file']) || !hash_equals($fixture['package_sha256'], hash_file('sha256', $fixture['package_file']))
        || !class_exists('WHMCS\\Application') || !class_exists('WHMCS\\Database\\Capsule')) {
        throw new RuntimeException('Native WHMCS fixture requires a real supported installation and pinned package evidence.');
    }
    $results = array();
    foreach ($scenarios as $scenario) {
        $result = call_user_func($fixture['run'], $scenario);
        if (!is_array($result) || !in_array($result['status'] ?? '', array('passed', 'failed', 'skipped'), true)
            || !isset($result['evidence']) || !is_array($result['evidence'])
            || ($result['status'] === 'passed' && !$result['evidence'])
            || ($result['status'] !== 'passed' && empty($result['reason']))) {
            throw new RuntimeException('Native fixture returned no valid result/evidence for ' . $scenario);
        }
        foreach ($result['evidence'] as $file) {
            if (!is_string($file) || !is_file($file)) { throw new RuntimeException('Native evidence file is missing.'); }
        }
        $results[$scenario] = $result + array('version' => $fixture['version'], 'package_sha256' => $fixture['package_sha256']);
    }
    return $results;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $failed = false;
    foreach (paymos_whmcs_refund_native_fixture() as $scenario => $result) {
        echo strtoupper($result['status']) . ' ' . $scenario . ': ' . ($result['reason'] ?? 'evidence recorded') . PHP_EOL;
        $failed = $failed || $result['status'] === 'failed';
    }
    exit($failed ? 1 : 0);
}
