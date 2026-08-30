<?php

declare(strict_types=1);

if (!function_exists('add_hook')) {
    function add_hook($hook, $priority, $function) {}
}
if (!function_exists('check_token')) {
    function check_token($name = 'token', $form = '') { return true; }
}
if (!function_exists('get_admin_url')) {
    function get_admin_url($path = '') { return 'https://whmcs.test/admin/' . $path; }
}

// WHMCS defines this before any module file loads; the compile-all gate
// requires the guarded entry files exactly like the platform does.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

define('PAYMOS_WHMCS_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// Any deprecation, notice or warning inside plugin code must fail the run:
// platform installers (Magento DI compile above all) escalate PHP 8.4+
// deprecations to fatals, and a silent one here is how rejections slip through.
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('PAYMOS_WHMCS_MODULE_DIR', PAYMOS_WHMCS_PLUGIN_DIR . 'modules/gateways/paymosgateway/');

spl_autoload_register(static function ($class) {
    $prefix = 'PaymosWhmcs\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $path = PAYMOS_WHMCS_MODULE_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }

    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_WHMCS_MODULE_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            getenv('PAYMOS_SDK_SRC')
                ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
                : null,
            dirname(rtrim(PAYMOS_WHMCS_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                require $candidate;
                return;
            }
        }
    }
});

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function whmcs_gateway_params(array $overrides = array())
{
    $client = new stdClass();
    $client->id = 77;

    $params = array_merge(array(
        'paymentmethod' => 'paymosgateway',
        'name' => 'Paymos',
        'mode' => 'sandbox',
        'apiKey' => 'pk_test_123',
        'apiSecret' => 'sk_test_123',
        'projectId' => 'prj_123',
        'webhookSecretSandbox' => 'whsec_sandbox',
        'webhookSecretLive' => '',
        'sandboxApiKey' => 'pk_test_123',
        'sandboxApiSecret' => 'sk_test_123',
        'sandboxProjectId' => 'prj_123',
        'sandboxWebhookSecret' => 'whsec_sandbox',
        'liveApiKey' => 'pk_live_123',
        'liveApiSecret' => 'sk_live_123',
        'liveProjectId' => 'prj_live_123',
        'liveWebhookSecret' => 'whsec_live',
        'apiBaseUrl' => 'https://api.paymos.test',
        'buttonText' => 'Pay with Paymos',
        'invoiceid' => 42,
        'amount' => '100.00',
        'currency' => 'USD',
        'description' => 'Invoice #42',
        'returnurl' => 'https://billing.example.com/viewinvoice.php?id=42',
        'systemurl' => 'https://billing.example.com/',
        'clientdetails' => array(
            'model' => $client,
            'email' => 'buyer@example.com',
            'firstname' => 'Buyer',
            'lastname' => 'Example',
        ),
    ), $overrides);

    PaymosWhmcs\Config::useConfigForTests(array(
        'environments' => array(
            'sandbox' => array(
                'base_url' => (string) $params['apiBaseUrl'],
                'api_key' => (string) $params['sandboxApiKey'],
                'api_secret' => (string) $params['sandboxApiSecret'],
                'project_id' => (string) $params['sandboxProjectId'],
                'webhook_secret' => (string) $params['sandboxWebhookSecret'],
            ),
            'live' => array(
                'base_url' => (string) $params['apiBaseUrl'],
                'api_key' => (string) $params['liveApiKey'],
                'api_secret' => (string) $params['liveApiSecret'],
                'project_id' => (string) $params['liveProjectId'],
                'webhook_secret' => (string) $params['liveWebhookSecret'],
            ),
        ),
    ));

    return $params;
}

function whmcs_signed_header($secret, $body, $timestamp)
{
    return 't=' . (int) $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

function paymos_whmcs_reset_test_state()
{
    if (class_exists('PaymosWhmcs\\Config') && method_exists('PaymosWhmcs\\Config', 'resetForTests')) {
        PaymosWhmcs\Config::resetForTests();
    }
}

function paymos_whmcs_write_generated_config($php)
{
    $config = eval('return ' . $php . ';');
    PaymosWhmcs\Config::useConfigForTests(is_array($config) ? $config : array());
}

function whmcs_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => 1709000000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => 'whmcs_42_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

final class FakeWhmcsAdapter implements PaymosWhmcs\WhmcsAdapterInterface
{
    /** @var array<int, array<string, string>> */
    public $invoices = array();

    /** @var array<int, array<string, string>> */
    public $payments = array();

    /** @var array<int, array<string, mixed>> */
    public $logs = array();

    /** @var array<string, bool> */
    public $transactions = array();

    /** @var bool */
    public $failNextTransactionCheck = false;

    public function __construct()
    {
        $this->invoices[42] = array(
            'invoiceid' => '42',
            'total' => '100.00',
            'balance' => '100.00',
            'currency' => 'USD',
            'status' => 'Unpaid',
        );
    }

    public function checkInvoiceId($invoiceId, $gatewayModuleName)
    {
        $invoiceId = (int) $invoiceId;
        if (!isset($this->invoices[$invoiceId])) {
            throw new RuntimeException('Invalid invoice id.');
        }

        return $invoiceId;
    }

    public function checkTransactionId($transactionId)
    {
        $transactionId = (string) $transactionId;
        if ($this->failNextTransactionCheck || isset($this->transactions[$transactionId])) {
            $this->failNextTransactionCheck = false;
            throw new RuntimeException('Duplicate transaction id.');
        }

        $this->transactions[$transactionId] = true;
    }

    public function addInvoicePayment($invoiceId, $transactionId, $paymentAmount, $paymentFee, $gatewayModuleName)
    {
        $this->payments[] = array(
            'invoice_id' => (int) $invoiceId,
            'transaction_id' => (string) $transactionId,
            'amount' => (string) $paymentAmount,
            'fee' => (string) $paymentFee,
            'gateway' => (string) $gatewayModuleName,
        );
    }

    public function logTransaction($gatewayModuleName, array $data, $status)
    {
        $this->logs[] = array(
            'gateway' => (string) $gatewayModuleName,
            'data' => $data,
            'status' => (string) $status,
        );
    }

    public function getInvoice($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        return isset($this->invoices[$invoiceId]) ? $this->invoices[$invoiceId] : array();
    }
}
