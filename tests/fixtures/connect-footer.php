<?php

declare(strict_types=1);

namespace WHMCS\User {
    final class Admin
    {
        /** @var array<int|string, object> */
        public static $records = array();

        public static function find($id)
        {
            return isset(self::$records[$id]) ? self::$records[$id] : null;
        }
    }
}

namespace WHMCS\Config {
    final class Setting
    {
        /** @var array<string, string> */
        public static $values = array();

        public static function getValue($key)
        {
            return isset(self::$values[$key]) ? self::$values[$key] : '';
        }
    }
}

namespace {
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    define('WHMCS', true);

    $GLOBALS['paymos_connect_hooks'] = array();
    $GLOBALS['paymos_connect_permission'] = true;
    $GLOBALS['paymos_generate_token_calls'] = array();
    $GLOBALS['paymos_generate_token_value'] = '';

    function add_hook($name, $priority, $callback)
    {
        $GLOBALS['paymos_connect_hooks'][$name][] = $callback;
    }

    function checkPermission($permission, $return = false)
    {
        return $permission === 'Configure Payment Gateways'
            && $return === true
            && !empty($GLOBALS['paymos_connect_permission']);
    }

    function check_token($name = 'token', $form = '')
    {
        return $name === 'WHMCS.admin.default';
    }

    function generate_token($type = 'default')
    {
        $GLOBALS['paymos_generate_token_calls'][] = $type;
        return $GLOBALS['paymos_generate_token_value'];
    }

    function connect_footer_assert_same($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function connect_footer_assert_contains($needle, $haystack, $message)
    {
        if (strpos((string) $haystack, (string) $needle) === false) {
            throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
        }
    }

    function connect_footer_assert_not_contains($needle, $haystack, $message)
    {
        if (strpos((string) $haystack, (string) $needle) !== false) {
            throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
        }
    }

    function connect_footer_extract_labels($html)
    {
        if (!preg_match('/var labels=(\{.*?\}),injectedToken=/', (string) $html, $matches)) {
            throw new RuntimeException('Unable to extract labels JSON from Connect footer output.');
        }
        $decoded = json_decode($matches[1], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Decoded labels payload must be a JSON object.');
        }
        return $decoded;
    }

    require dirname(__DIR__, 2) . '/includes/hooks/paymosgateway_connect.php';

    if (empty($GLOBALS['paymos_connect_hooks']['AdminAreaFooterOutput'])) {
        throw new RuntimeException('AdminAreaFooterOutput hook was not registered.');
    }
    $footerHook = $GLOBALS['paymos_connect_hooks']['AdminAreaFooterOutput'][0];

    $_SESSION['adminid'] = 7;
    \WHMCS\User\Admin::$records[7] = (object) array('language' => 'english');

    // Non-gateway admin pages must not receive the Connect script.
    $_SERVER['REQUEST_URI'] = '/admin/invoices.php';
    $GLOBALS['paymos_connect_permission'] = true;
    connect_footer_assert_same('', $footerHook(array('csrfToken' => str_repeat('a', 40))), 'Non-gateway page must not render Connect script.');

    // Unauthorized admins on configgateways.php must not receive the Connect script.
    $_SERVER['REQUEST_URI'] = '/admin/configgateways.php';
    $GLOBALS['paymos_connect_permission'] = false;
    connect_footer_assert_same('', $footerHook(array('csrfToken' => str_repeat('a', 40))), 'Unauthorized admin must not render Connect script.');

    // WHMCS 9.1 AdminAreaFooterOutput supplies csrfToken without token.
    $GLOBALS['paymos_connect_permission'] = true;
    $GLOBALS['paymos_generate_token_calls'] = array();
    $csrf40 = str_repeat('a1b2c3d4e5', 4);
    $html = $footerHook(array('csrfToken' => $csrf40));

    connect_footer_assert_contains('document.getElementById("configpaymosgatewayForm")', $html, 'Connect script must target #configpaymosgatewayForm directly.');
    connect_footer_assert_not_contains('document.querySelector("form")', $html, 'Connect script must not inject into the first page form (IntelliSearch / confirmpw).');
    connect_footer_assert_contains('form.prepend(box)', $html, 'Connect script must prepend its box inside #configpaymosgatewayForm.');
    connect_footer_assert_contains('form.querySelector(\'input[name="token"]\')', $html, 'Connect script must read input[name="token"] inside the Paymos form with server fallback.');
    connect_footer_assert_contains('injectedToken=' . json_encode($csrf40), $html, 'Connect script must inject $vars["csrfToken"] when present.');
    connect_footer_assert_same(array(), $GLOBALS['paymos_generate_token_calls'], 'generate_token() must not run when $vars["csrfToken"] is already provided.');
    connect_footer_assert_contains('window.open("","_blank")', $html, 'Connect script must keep synchronous blank tab opening without popup features.');
    connect_footer_assert_same(0, preg_match('/window\.open\([^)]*?,\s*[\'"]_blank[\'"]\s*,/', $html), 'Connect script must never pass popup features to window.open.');
    connect_footer_assert_contains('t.location=j.verification_url', $html, 'Connect script must navigate the opened blank tab to verification_url.');
    connect_footer_assert_contains('document.createElement("a")', $html, 'Connect script must keep clickable recovery anchor when browser blocks the tab.');

    // csrfToken takes precedence over legacy token.
    $GLOBALS['paymos_generate_token_calls'] = array();
    $priorityHtml = $footerHook(array('csrfToken' => $csrf40, 'token' => 'legacy_token_ignored'));
    connect_footer_assert_contains('injectedToken=' . json_encode($csrf40), $priorityHtml, 'csrfToken must take precedence over token.');
    connect_footer_assert_not_contains('legacy_token_ignored', $priorityHtml, 'Legacy token must not override csrfToken.');
    connect_footer_assert_same(array(), $GLOBALS['paymos_generate_token_calls'], 'generate_token() must not run when csrfToken is set.');

    // Legacy $vars['token'] works when csrfToken is empty or absent.
    $GLOBALS['paymos_generate_token_calls'] = array();
    $legacyToken = str_repeat('b2c3d4e5f6', 4);
    $legacyHtml = $footerHook(array('csrfToken' => '', 'token' => $legacyToken));
    connect_footer_assert_contains('injectedToken=' . json_encode($legacyToken), $legacyHtml, 'Legacy $vars["token"] must be used when csrfToken is empty.');
    connect_footer_assert_same(array(), $GLOBALS['paymos_generate_token_calls'], 'generate_token() must not run when $vars["token"] is provided.');

    // Fallback to generate_token('plain') when neither csrfToken nor token is present.
    $GLOBALS['paymos_generate_token_calls'] = array();
    $fallback40 = str_repeat('f9e8d7c6b5', 4);
    $GLOBALS['paymos_generate_token_value'] = $fallback40;
    $fallbackHtml = $footerHook(array());
    connect_footer_assert_same(array('plain'), $GLOBALS['paymos_generate_token_calls'], 'Missing hook tokens must fall back to generate_token("plain").');
    connect_footer_assert_contains('injectedToken=' . json_encode($fallback40), $fallbackHtml, 'Fallback token from generate_token("plain") must be injected into the script.');

    // Admin language resolves through Translation::adminLanguageParams() (profile -> store default).
    \WHMCS\User\Admin::$records[7] = (object) array('language' => 'russian');
    connect_footer_assert_same(array('language' => 'russian'), paymosgateway_connect_language(), 'Connect language must delegate to Translation::adminLanguageParams() for admin profile.');
    $ruLabels = connect_footer_extract_labels($footerHook(array('csrfToken' => $csrf40)));
    connect_footer_assert_same('Подключить Paymos', $ruLabels['button'], 'Russian admin must receive Russian Connect button label.');

    \WHMCS\User\Admin::$records[7] = (object) array('language' => '');
    \WHMCS\Config\Setting::$values['Language'] = 'german';
    connect_footer_assert_same(array('language' => 'german'), paymosgateway_connect_language(), 'Connect language must fall back to WHMCS store default language when admin language is empty.');
    $deLabels = connect_footer_extract_labels($footerHook(array('csrfToken' => $csrf40)));
    connect_footer_assert_same('Paymos verbinden', $deLabels['button'], 'Empty admin language must fall back to store default German Connect label.');

    echo "OK connect-footer\n";
}
