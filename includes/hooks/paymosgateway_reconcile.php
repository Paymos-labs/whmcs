<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (function_exists('add_hook')) {
    add_hook('AfterCronJob', 1, static function () {
        require_once dirname(__DIR__, 2) . '/modules/gateways/paymosgateway/src/Autoloader.php';
        \PaymosWhmcs\Autoloader::register();

        if (!function_exists('getGatewayVariables')) {
            return;
        }

        $params = getGatewayVariables('paymosgateway');
        if (!is_array($params) || !isset($params['type'])) {
            return;
        }

        if (!paymosgateway_reconcile_due()) {
            return;
        }

        \PaymosWhmcs\Migrations::ensure();
        (new \PaymosWhmcs\Reconciler(
            new \PaymosWhmcs\InvoiceStore(),
            new \PaymosWhmcs\WhmcsAdapter()
        ))->run($params);
    });
}

function paymosgateway_reconcile_due()
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return true;
    }

    $key = 'paymosgateway_reconcile_last_run';
    $now = time();
    $row = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', $key)->first();
    $lastRun = $row && isset($row->value) ? (int) $row->value : 0;

    if ($lastRun > 0 && ($now - $lastRun) < 600) {
        return false;
    }

    if ($row) {
        \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', $key)->update(array('value' => (string) $now));
    } else {
        \WHMCS\Database\Capsule::table('tblconfiguration')->insert(array('setting' => $key, 'value' => (string) $now));
    }

    return true;
}
