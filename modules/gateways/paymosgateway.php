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
    } catch (\Paymos\Exception\ApiException $e) {
        // A structured API error (e.g. an unsupported currency → 400 validation)
        // carries an actionable detail/field. Surface it instead of the generic
        // "temporarily unavailable" message, and log the specifics so the admin
        // can see exactly which field the server rejected.
        if (function_exists('logTransaction')) {
            logTransaction('paymosgateway', array(
                'error' => $e->getMessage(),
                'code' => $e->errorCode(),
                'field' => $e->field(),
                'detail' => $e->detail(),
            ), 'Error');
        }

        $detail = $e->detail();
        if ($detail === null || $detail === '') {
            $detail = 'This payment method cannot be used for this invoice.';
        }
        $field = $e->field();
        if ($field !== null && $field !== '') {
            $detail .= ' (' . $field . ')';
        }

        return '<div class="alert alert-danger">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</div>';
    } catch (\Throwable $e) {
        if (function_exists('logTransaction')) {
            logTransaction('paymosgateway', array('error' => $e->getMessage()), 'Error');
        }

        return '<div class="alert alert-danger">Paymos is temporarily unavailable. Please contact support.</div>';
    }
}
