<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$paymosAutoloader = dirname(__DIR__, 2) . '/modules/gateways/paymosgateway/src/Autoloader.php';
if (is_file($paymosAutoloader)) {
    require_once $paymosAutoloader;
    \PaymosWhmcs\Autoloader::register();
}

if (isset($_POST['paymos_connect_action'])) {
    header('Content-Type: application/json');
    try {
        if (empty($_SESSION['adminid'])) {
            throw new \RuntimeException('Access denied.');
        }
        if (function_exists('check_token')) {
            check_token('WHMCS.admin.default');
        }
        $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
        if ($systemUrl === '' || stripos($systemUrl, 'https://') !== 0) {
            throw new \RuntimeException('WHMCS System URL must use HTTPS.');
        }
        $client = new \Paymos\Connect\DeviceConnectClient('https://app.paymos.io');
        if ($_POST['paymos_connect_action'] === 'start') {
            $state = $client->start('whmcs', $systemUrl);
            \PaymosWhmcs\CredentialStore::saveState($state);
            echo json_encode(array(
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
            ));
            exit;
        }
        if ($_POST['paymos_connect_action'] !== 'poll') {
            throw new \RuntimeException('Invalid Paymos connection action.');
        }
        $state = \PaymosWhmcs\CredentialStore::loadState();
        if (!isset($state['device_code'])) {
            throw new \RuntimeException('No active Paymos connection request.');
        }
        $result = $client->poll((string) $state['device_code']);
        if ($result['status'] === 'connected') {
            if ($result['plugin'] !== 'whmcs' || rtrim((string) $result['source_url'], '/') !== $systemUrl) {
                throw new \RuntimeException('Paymos connection response does not match this WHMCS installation.');
            }
            \PaymosWhmcs\CredentialStore::saveCredentials($result['credentials']);
            \PaymosWhmcs\CredentialStore::clearState();
            \PaymosWhmcs\Config::resetForTests();
            echo json_encode(array('status' => 'connected'));
            exit;
        }
        if (in_array($result['status'], array('authorization_pending', 'slow_down'), true)) {
            echo json_encode(array('status' => $result['status']));
            exit;
        }
        \PaymosWhmcs\CredentialStore::clearState();
        throw new \RuntimeException('Paymos connection was denied or expired.');
    } catch (\Throwable $exception) {
        http_response_code(400);
        echo json_encode(array('error' => $exception->getMessage()));
        exit;
    }
}

add_hook('AdminAreaFooterOutput', 1, static function ($vars) {
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($requestUri, 'configgateways.php') === false) {
        return '';
    }
    $token = isset($vars['token']) ? (string) $vars['token'] : '';
    return '<script>(function(){var form=document.querySelector("form");if(!form||document.getElementById("paymos-connect-button"))return;'
        . 'var box=document.createElement("div");box.className="alert alert-info";box.innerHTML="<strong>Paymos</strong><br><button type=\\"button\\" class=\\"btn btn-primary\\" id=\\"paymos-connect-button\\">Connect Paymos</button> <span id=\\"paymos-connect-status\\"></span>";form.prepend(box);'
        . 'var b=document.getElementById("paymos-connect-button"),s=document.getElementById("paymos-connect-status");function post(a){var d=new URLSearchParams({paymos_connect_action:a,token:' . json_encode($token) . '});return fetch(location.href,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d.toString()}).then(function(r){return r.json();});}'
        . 'b.onclick=function(){b.disabled=true;s.textContent="Starting…";post("start").then(function(j){if(j.error)throw new Error(j.error);window.open(j.verification_url,"_blank","noopener,noreferrer");s.textContent=" Waiting for approval. Code: "+j.user_code;var i=Math.max(1,Number(j.interval||5))*1000;setTimeout(function p(){post("poll").then(function(x){if(x.error)throw new Error(x.error);if(x.status==="connected"){location.reload();return;}setTimeout(p,x.status==="slow_down"?i+5000:i);}).catch(function(e){s.textContent=e.message;b.disabled=false;});},i);}).catch(function(e){s.textContent=e.message;b.disabled=false;});};})();</script>';
});
