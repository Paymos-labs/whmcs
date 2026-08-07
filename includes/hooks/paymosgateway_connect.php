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
        . 'var b=document.getElementById("paymos-connect-button"),s=document.getElementById("paymos-connect-status"),manual=false;'
        . 'function post(a,r){var o={paymos_connect_action:a,token:' . json_encode($token) . '};if(r)o.paymos_return_url=r;var d=new URLSearchParams(o);return fetch(location.href,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d.toString()}).then(function(r2){return r2.json();});}'
        . 'function say(t){if(!manual)s.textContent=t;}'
        /* The tab is opened synchronously here: browsers only honour window.open for a
           few seconds after the click, so opening it once the start request resolves is
           blocked on slow connections. No feature string — that would ask for a popup. */
        . 'b.onclick=function(){b.disabled=true;manual=false;s.textContent="Starting…";'
        . 'var t=window.open("","_blank");if(t){try{t.opener=null;}catch(e){}}'
        . 'post("start",location.href).then(function(j){if(j.error)throw new Error(j.error);'
        . 'if(t&&!t.closed){t.location=j.verification_url;s.textContent=" Waiting for approval. Code: "+j.user_code;}'
        . 'else{manual=true;s.textContent="";var a=document.createElement("a");a.href=j.verification_url;a.target="_blank";a.rel="noopener noreferrer";a.textContent="Open the approval page";'
        . 's.appendChild(document.createTextNode("Your browser blocked the approval tab. "));s.appendChild(a);s.appendChild(document.createTextNode(" Code: "+j.user_code));}'
        . 'var i=Math.max(1,Number(j.interval||5))*1000;setTimeout(function p(){post("poll").then(function(x){if(x.error)throw new Error(x.error);if(x.status==="connected"){location.reload();return;}setTimeout(p,x.status==="slow_down"?i+5000:i);}).catch(function(e){say(e.message);b.disabled=false;});},i);'
        . '}).catch(function(e){if(t&&!t.closed)t.close();s.textContent=e.message;b.disabled=false;});};})();</script>';
});
