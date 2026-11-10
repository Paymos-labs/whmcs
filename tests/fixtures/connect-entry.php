<?php

declare(strict_types=1);

define('WHMCS', true);
$_SESSION['adminid'] = 42;
$_POST['paymos_connect_action'] = $argv[1] ?? 'start';

function checkPermission($permission, $return = false)
{
    if ($permission !== 'Configure Payment Gateways' || $return !== true) {
        throw new RuntimeException('Wrong permission requested.');
    }
    return false;
}

function check_token($name)
{
    throw new RuntimeException('Denied admin must not reach CSRF or any device operation.');
}

ob_start();
register_shutdown_function(static function () {
    $body = ob_get_clean();
    if (http_response_code() !== 403 || !is_array(json_decode($body, true))) {
        fwrite(STDERR, 'Denied Connect action reached the platform/device path: ' . $body);
        exit(1);
    }
    echo 'OK denied';
});
require dirname(__DIR__, 2) . '/includes/hooks/paymosgateway_connect.php';
