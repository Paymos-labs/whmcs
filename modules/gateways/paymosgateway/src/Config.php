<?php

declare(strict_types=1);

namespace PaymosWhmcs;

use Paymos\ClientConfig;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /** @var array<string, mixed>|null */
    private static $generated;

    /** @var array<string, mixed> */
    private $params;

    /**
     * @param array<string, mixed> $params
     */
    private function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function moduleConfig()
    {
        $base = array(
            'FriendlyName' => array(
                'Type' => 'System',
                'Value' => 'Paymos',
            ),
            'mode' => array(
                'FriendlyName' => 'Mode',
                'Type' => 'dropdown',
                'Options' => array(
                    'sandbox' => 'Sandbox',
                    'live' => 'Live',
                ),
                'Default' => 'sandbox',
                'Description' => 'Use Sandbox for test payments, then switch to Live when you are ready to process real orders.',
            ),
        );

        if (self::hasGeneratedConfig()) {
            return array_merge($base, array(
                'generatedConfig' => array(
                    'FriendlyName' => 'Credentials',
                    'Type' => 'System',
                    'Value' => 'Loaded from Paymos dashboard ZIP',
                ),
                'buttonText' => self::buttonTextField(),
            ));
        }

        return array_merge($base, array(
            'sandboxApiKey' => array(
                'FriendlyName' => 'Sandbox API Key',
                'Type' => 'password',
                'Size' => '45',
                'Default' => '',
                'Description' => 'Paymos sandbox public API key, starting with pk_test_.',
            ),
            'sandboxApiSecret' => array(
                'FriendlyName' => 'Sandbox API Secret',
                'Type' => 'password',
                'Size' => '45',
                'Default' => '',
                'Description' => 'Paymos sandbox API signing secret, starting with sk_test_.',
            ),
            'sandboxProjectId' => array(
                'FriendlyName' => 'Sandbox Project ID',
                'Type' => 'text',
                'Size' => '35',
                'Default' => '',
                'Description' => 'Paymos sandbox project id.',
            ),
            'sandboxWebhookSecret' => array(
                'FriendlyName' => 'Sandbox Webhook Secret',
                'Type' => 'password',
                'Size' => '45',
                'Default' => '',
                'Description' => 'Paymos webhook secret for sandbox events.',
            ),
            'liveApiKey' => array(
                'FriendlyName' => 'Live API Key',
                'Type' => 'password',
                'Size' => '45',
                'Default' => '',
                'Description' => 'Paymos live public API key, starting with pk_live_.',
            ),
            'liveApiSecret' => array(
                'FriendlyName' => 'Live API Secret',
                'Type' => 'password',
                'Size' => '45',
                'Default' => '',
                'Description' => 'Paymos live API signing secret, starting with sk_live_.',
            ),
            'liveProjectId' => array(
                'FriendlyName' => 'Live Project ID',
                'Type' => 'text',
                'Size' => '35',
                'Default' => '',
                'Description' => 'Paymos live project id.',
            ),
            'liveWebhookSecret' => array(
                'FriendlyName' => 'Live Webhook Secret',
                'Type' => 'password',
                'Size' => '45',
                'Default' => '',
                'Description' => 'Paymos webhook secret for live events.',
            ),
            'buttonText' => self::buttonTextField(),
            'apiBaseUrl' => array(
                'FriendlyName' => 'API Base URL',
                'Type' => 'text',
                'Size' => '60',
                'Default' => self::DEFAULT_BASE_URL,
                'Description' => 'Production merchants should not change this unless Paymos support instructs them to.',
            ),
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params)
    {
        $config = new self($params);
        $environment = $config->environment();

        $config->assertEnvironmentConfigured($environment);
        $secrets = $config->webhookSecrets();
        if (count($secrets) === 0) {
            throw new \InvalidArgumentException('At least one Paymos webhook secret is required.');
        }
        if (!isset($secrets[$environment])) {
            throw new \InvalidArgumentException('Paymos ' . $environment . ' webhook secret is required for the selected mode.');
        }

        return $config;
    }

    public function clientConfig()
    {
        return $this->clientConfigForEnvironment($this->environment());
    }

    public function clientConfigForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $this->assertEnvironmentConfigured($environment);

        return new ClientConfig(
            $this->apiKey($environment),
            $this->apiSecret($environment),
            $this->apiBaseUrlForEnvironment($environment),
            30
        );
    }

    public function apiKey($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'ApiKey', 'apiKey');
    }

    public function apiSecret($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'ApiSecret', 'apiSecret');
    }

    public function apiBaseUrl()
    {
        return $this->apiBaseUrlForEnvironment($this->environment());
    }

    public function apiBaseUrlForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $config = self::generatedEnvironment($environment);
        if (isset($config['base_url']) && is_scalar($config['base_url']) && trim((string) $config['base_url']) !== '') {
            return rtrim((string) $config['base_url'], '/');
        }

        $baseUrl = self::stringValue($this->params, 'apiBaseUrl');
        return $baseUrl === '' ? self::DEFAULT_BASE_URL : rtrim($baseUrl, '/');
    }

    public function projectId($environment = null)
    {
        return $this->projectIdForEnvironment($environment === null ? $this->environment() : $environment);
    }

    public function projectIdForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'ProjectId', 'projectId');
    }

    public function environment()
    {
        $mode = strtolower(self::stringValue($this->params, 'mode'));
        if (in_array($mode, array('sandbox', 'live'), true)) {
            return $mode;
        }

        $legacyKey = self::stringValue($this->params, 'apiKey');
        if (strpos($legacyKey, 'pk_live_') === 0) {
            return 'live';
        }

        return 'sandbox';
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets()
    {
        $secrets = array();
        $sandbox = $this->environmentValue('sandbox', 'WebhookSecret', 'webhookSecretSandbox');
        $live = $this->environmentValue('live', 'WebhookSecret', 'webhookSecretLive');

        if ($sandbox !== '') {
            $secrets['sandbox'] = $sandbox;
        }
        if ($live !== '') {
            $secrets['live'] = $live;
        }

        return $secrets;
    }

    public function buttonText()
    {
        $buttonText = self::stringValue($this->params, 'buttonText');
        return $buttonText === '' ? 'Pay with Paymos' : $buttonText;
    }

    private function assertEnvironmentConfigured($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        $fields = array(
            'api key' => array($environment . 'ApiKey', 'apiKey'),
            'api secret' => array($environment . 'ApiSecret', 'apiSecret'),
            'project id' => array($environment . 'ProjectId', 'projectId'),
            'webhook secret' => array($environment . 'WebhookSecret', $environment === 'sandbox' ? 'webhookSecretSandbox' : 'webhookSecretLive'),
        );

        foreach ($fields as $label => $keys) {
            if ($this->environmentValue($environment, substr($keys[0], strlen($environment)), $keys[1]) === '') {
                throw new \InvalidArgumentException('Paymos WHMCS config is missing ' . $keys[0] . ' (' . $environment . ' ' . $label . ').');
            }
        }

        $this->assertApiKeyMatchesEnvironment($environment);
        $this->assertApiSecretMatchesEnvironment($environment);
    }

    private function assertApiKeyMatchesEnvironment($environment)
    {
        $apiKey = $this->apiKey($environment);
        if ($environment === 'sandbox' && strpos($apiKey, 'pk_test_') !== 0) {
            throw new \InvalidArgumentException('Paymos sandboxApiKey must start with pk_test_.');
        }

        if ($environment === 'live' && strpos($apiKey, 'pk_live_') !== 0) {
            throw new \InvalidArgumentException('Paymos liveApiKey must start with pk_live_.');
        }
    }

    private function assertApiSecretMatchesEnvironment($environment)
    {
        $apiSecret = $this->apiSecret($environment);
        if ($environment === 'sandbox' && strpos($apiSecret, 'sk_test_') !== 0) {
            throw new \InvalidArgumentException('Paymos sandbox API secret (sandboxApiSecret) must start with sk_test_.');
        }

        if ($environment === 'live' && strpos($apiSecret, 'sk_live_') !== 0) {
            throw new \InvalidArgumentException('Paymos live API secret (liveApiSecret) must start with sk_live_.');
        }
    }

    private function environmentValue($environment, $suffix, $legacyKey)
    {
        $environment = $this->normalizeEnvironment($environment);
        $generated = self::generatedEnvironment($environment);
        $generatedKey = self::generatedKeyForSuffix($suffix);
        if ($generatedKey !== '' && isset($generated[$generatedKey]) && is_scalar($generated[$generatedKey]) && trim((string) $generated[$generatedKey]) !== '') {
            return trim((string) $generated[$generatedKey]);
        }

        $key = $environment . $suffix;
        $value = self::stringValue($this->params, $key);
        if ($value !== '') {
            return $value;
        }

        if ($legacyKey !== '') {
            return self::stringValue($this->params, $legacyKey);
        }

        return '';
    }

    private function normalizeEnvironment($environment)
    {
        $environment = strtolower(trim((string) $environment));
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('Paymos environment must be sandbox or live.');
        }

        return $environment;
    }

    public static function resetForTests()
    {
        self::$generated = null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buttonTextField()
    {
        return array(
            'FriendlyName' => 'Button Text',
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'Pay with Paymos',
            'Description' => 'Text shown on the WHMCS invoice payment button.',
        );
    }

    private static function hasGeneratedConfig()
    {
        $generated = self::generated();
        return isset($generated['environments']) && is_array($generated['environments']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function generatedEnvironment($environment)
    {
        $generated = self::generated();
        if (!isset($generated['environments']) || !is_array($generated['environments'])) {
            return array();
        }

        $environments = $generated['environments'];
        return isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();
    }

    /**
     * @return array<string, mixed>
     */
    private static function generated()
    {
        if (self::$generated !== null) {
            return self::$generated;
        }

        $file = dirname(__DIR__) . '/paymos-config.php';
        if (!is_readable($file)) {
            self::$generated = array();
            return self::$generated;
        }

        $config = require $file;
        self::$generated = is_array($config) ? $config : array();
        return self::$generated;
    }

    private static function generatedKeyForSuffix($suffix)
    {
        switch ($suffix) {
            case 'ApiKey':
                return 'api_key';
            case 'ApiSecret':
                return 'api_secret';
            case 'ProjectId':
                return 'project_id';
            case 'WebhookSecret':
                return 'webhook_secret';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function stringValue(array $params, $key)
    {
        return isset($params[$key]) && is_scalar($params[$key]) ? trim((string) $params[$key]) : '';
    }
}
