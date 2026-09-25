<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/paymosgateway/src/Autoloader.php';
\PaymosWhmcs\Autoloader::register();

function paymosgateway_MetaData()
{
    return array(
        'DisplayName' => 'Paymos',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function paymosgateway_config()
{
    return \PaymosWhmcs\Config::moduleConfig();
}

function paymosgateway_link($params)
{
    try {
        \PaymosWhmcs\Migrations::ensure();

        return (new \PaymosWhmcs\GatewayLink(new \PaymosWhmcs\InvoiceStore()))->render($params);
    } catch (\Throwable $e) {
        return \PaymosWhmcs\GatewayLink::failureNotice($e, $params);
    }
}
