<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once dirname(__DIR__, 2) . '/modules/gateways/paymosgateway/src/Autoloader.php';
\PaymosWhmcs\Autoloader::register();
\PaymosWhmcs\RefundWriteGuard::register();

function paymosgateway_refund_action(array $input)
{
    \PaymosWhmcs\RefundAdapter::authorizeAdmin();
    $mode = $input['paymos_refund_action'] ?? '';
    if (!is_string($mode) || !in_array($mode, array('full', 'overpayment'), true)) {
        throw new \RuntimeException('refund_action_invalid');
    }
    $params = function_exists('getGatewayVariables') ? getGatewayVariables('paymosgateway') : array();
    if (!is_array($params) || empty($params['type'])) {
        throw new \RuntimeException('refund_module_inactive');
    }
    \PaymosWhmcs\Migrations::ensure();
    $store = new \PaymosWhmcs\RefundIntentStore();
    $store->install();
    $input['mode'] = $mode;
    $intent = (new \PaymosWhmcs\RefundAdapter($params, $store))->submit($input);
    return array('operation_id' => $intent->operationId(), 'cash_state' => $intent->cashState(),
        'projection_state' => $intent->projectionState());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['paymos_refund_action'])) {
    header('Content-Type: application/json');
    try {
        echo json_encode(paymosgateway_refund_action($_POST));
    } catch (\Throwable $error) {
        http_response_code(409);
        echo json_encode(array('message' => 'Refund request failed; see reason. If it is refund_reconnect_required, refunds through Paymos are not available on this connection: reconnect the module with Connect Paymos on the Payment Gateways page.', 'reason' => $error->getMessage()));
    }
    exit;
}

if (function_exists('add_hook')) {
    add_hook('AdminInvoicesControlsOutput', 1, static function ($vars) {
        if (($vars['paymentmethod'] ?? '') !== 'paymosgateway' || empty($vars['invoiceid'])) {
            return '';
        }
        $authorize = static function () {
            $adminId = class_exists('WHMCS\Session') ? \WHMCS\Session::get('adminid') : ($_SESSION['adminid'] ?? null);
            if (!$adminId || !function_exists('checkPermission') || checkPermission('Refund Invoice Payments', true) !== true) {
                throw new \RuntimeException('refund_admin_access_denied');
            }
        };
        try {
            $authorize();
            $params = function_exists('getGatewayVariables') ? getGatewayVariables('paymosgateway') : array();
            if (!is_array($params) || empty($params['type'])) {
                return '';
            }
            \PaymosWhmcs\Migrations::ensure();
            $store = new \PaymosWhmcs\RefundIntentStore();
            $store->install();
            $html = (new \PaymosWhmcs\RefundAdapter($params, $store, null, null, null, $authorize))->panel((int) $vars['invoiceid']);
            // The native gateway entry needs the same durable full token.
            return $html . '<script>jQuery(function($){var token=$("#paymos-refunds .paymos-refund[data-mode=full]").attr("data-token");'
                . 'function bind(form){var input=$(form).find("input[name=paymos_refund_token]");'
                . 'if(!input.length)input=$("<input>",{type:"hidden",name:"paymos_refund_token"}).appendTo(form);input.val(token);}'
                . '$("form").each(function(){bind(this);});$(document).on("submit","form",function(){bind(this);});});</script>';
        } catch (\Throwable $error) {
            return '';
        }
    });
}
