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

function paymosgateway_connect_authorize($csrf = true)
{
    $adminId = class_exists('\WHMCS\Session') ? \WHMCS\Session::get('adminid') : ($_SESSION['adminid'] ?? null);
    if (empty($adminId) || !function_exists('checkPermission')
        || checkPermission('Configure Payment Gateways', true) !== true) {
        throw new \RuntimeException('paymos_connect_access_denied');
    }
    if ($csrf) {
        if (!function_exists('check_token')) {
            throw new \RuntimeException('paymos_connect_csrf_unavailable');
        }
        if (check_token('WHMCS.admin.default') === false) {
            throw new \RuntimeException('paymos_connect_csrf_invalid');
        }
    }
}

function paymosgateway_connect_language()
{
    $adminId = class_exists('\WHMCS\Session') ? \WHMCS\Session::get('adminid') : ($_SESSION['adminid'] ?? null);
    if ($adminId && class_exists('\WHMCS\User\Admin')) {
        $admin = \WHMCS\User\Admin::find($adminId);
        if ($admin) {
            return array('language' => $admin->language);
        }
    }
    return array();
}

if (isset($_POST['paymos_connect_action'])) {
    header('Content-Type: application/json');
    try {
        // Authorize both start and poll before touching the device state or credentials.
        paymosgateway_connect_authorize();
        $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
        if ($systemUrl === '' || stripos($systemUrl, 'https://') !== 0) {
            throw new \RuntimeException('WHMCS System URL must use HTTPS.');
        }
        $client = new \Paymos\Connect\DeviceConnectClient('https://app.paymos.io');
        if ($_POST['paymos_connect_action'] === 'start') {
            // The admin page posts its own URL so approval can return the merchant to it.
            // Paymos drops it unless it shares an origin with the System URL above.
            $returnUrl = isset($_POST['paymos_return_url']) ? (string) $_POST['paymos_return_url'] : '';
            $state = $client->start('whmcs', $systemUrl, $returnUrl);
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
        http_response_code($exception->getMessage() === 'paymos_connect_access_denied' ? 403 : 400);
        echo json_encode(array('error' => \PaymosWhmcs\Translation::text('connect_failed', paymosgateway_connect_language())));
        exit;
    }
}

add_hook('AdminAreaFooterOutput', 1, static function ($vars) {
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($requestUri, 'configgateways.php') === false) {
        return '';
    }
    try {
        paymosgateway_connect_authorize(false);
    } catch (\Throwable $error) {
        return '';
    }
    $language = paymosgateway_connect_language();
    $labels = array(
        'button' => \PaymosWhmcs\Translation::text('connect_button', $language),
        'starting' => \PaymosWhmcs\Translation::text('connect_starting', $language),
        'waiting' => \PaymosWhmcs\Translation::text('connect_waiting', $language),
        'open' => \PaymosWhmcs\Translation::text('connect_open', $language),
        'blocked' => \PaymosWhmcs\Translation::text('connect_blocked', $language),
        'code' => \PaymosWhmcs\Translation::text('connect_code', $language),
        'failed' => \PaymosWhmcs\Translation::text('connect_failed', $language),
    );
    $token = isset($vars['token']) ? (string) $vars['token'] : '';
    return '<script>(function(){var labels=' . json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';var form=document.querySelector("form");if(!form||document.getElementById("paymos-connect-button"))return;'
        . 'var box=document.createElement("div");box.className="alert alert-info";box.innerHTML="<strong>Paymos</strong><br><button type=\\"button\\" class=\\"btn btn-primary\\" id=\\"paymos-connect-button\\"></button> <span id=\\"paymos-connect-status\\"></span>";form.prepend(box);'
        . 'var b=document.getElementById("paymos-connect-button"),s=document.getElementById("paymos-connect-status"),manual=false;'
        . 'b.textContent=labels.button;'
        . 'function post(a,r){var o={paymos_connect_action:a,token:' . json_encode($token) . '};if(r)o.paymos_return_url=r;var d=new URLSearchParams(o);return fetch(location.href,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d.toString()}).then(function(r2){return r2.json();});}'
        . 'function say(t){if(!manual)s.textContent=t;}'
        /* The tab is opened synchronously here: browsers only honour window.open for a
           few seconds after the click, so opening it once the start request resolves is
           blocked on slow connections. No feature string — that would ask for a popup. */
        . 'b.onclick=function(){b.disabled=true;manual=false;s.textContent=labels.starting;'
        . 'var t=window.open("","_blank");if(t){try{t.opener=null;}catch(e){}}'
        . 'post("start",location.href).then(function(j){if(j.error)throw new Error(j.error);'
        . 'if(t&&!t.closed){t.location=j.verification_url;s.textContent=labels.waiting+" "+labels.code+" "+j.user_code;}'
        . 'else{manual=true;s.textContent="";var a=document.createElement("a");a.href=j.verification_url;a.target="_blank";a.rel="noopener noreferrer";a.textContent=labels.open;'
        . 's.appendChild(document.createTextNode(labels.blocked+" "));s.appendChild(a);s.appendChild(document.createTextNode(" "+labels.code+" "+j.user_code));}'
        . 'var i=Math.max(1,Number(j.interval||5))*1000;setTimeout(function p(){post("poll").then(function(x){if(x.error)throw new Error(x.error);if(x.status==="connected"){location.reload();return;}setTimeout(p,x.status==="slow_down"?i+5000:i);}).catch(function(e){say(labels.failed);b.disabled=false;});},i);'
        . '}).catch(function(e){if(t&&!t.closed)t.close();s.textContent=labels.failed;b.disabled=false;});};})();</script>';
});
