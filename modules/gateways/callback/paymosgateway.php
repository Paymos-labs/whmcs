<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../paymosgateway/src/Autoloader.php';

\PaymosWhmcs\Autoloader::register();

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!is_array($gatewayParams) || !isset($gatewayParams['type'])) {
    http_response_code(403);
    echo 'Module not activated';
    exit;
}

\PaymosWhmcs\Migrations::ensure();

$rawBody = file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) ? (string) $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] : '';
$result = (new \PaymosWhmcs\CallbackProcessor(
    new \PaymosWhmcs\WhmcsAdapter(),
    new \PaymosWhmcs\InvoiceStore(),
    new \PaymosWhmcs\EventStore()
))->handle($rawBody === false ? '' : $rawBody, $signature, $gatewayParams);

http_response_code($result->statusCode());
echo $result->body();
