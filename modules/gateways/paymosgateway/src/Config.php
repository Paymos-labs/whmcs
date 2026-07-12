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

        return array_merge($base, array(
            'generatedConfig' => array(
                'FriendlyName' => 'Connection',
                'Type' => 'System',
                'Value' => self::hasGeneratedConfig()
                    ? 'Connected — credentials are encrypted in this WHMCS installation'
                    : 'Not connected — click Connect Paymos and approve this installation',
            ),
            'buttonText' => self::buttonTextField(),
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
        return $this->environmentValue($environment, 'ApiKey');
    }

    public function apiSecret($environment = null)
    {
        $environment = $environment === null ? $this->environment() : $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'ApiSecret');
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

        return self::DEFAULT_BASE_URL;
    }

    public function projectId($environment = null)
    {
        return $this->projectIdForEnvironment($environment === null ? $this->environment() : $environment);
    }

    public function projectIdForEnvironment($environment)
    {
        $environment = $this->normalizeEnvironment($environment);
        return $this->environmentValue($environment, 'ProjectId');
    }

    public function environment()
    {
        $mode = strtolower(self::stringValue($this->params, 'mode'));
        if (in_array($mode, array('sandbox', 'live'), true)) {
            return $mode;
        }

        return 'sandbox';
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets()
    {
        $secrets = array();
        $sandbox = $this->environmentValue('sandbox', 'WebhookSecret');
        $live = $this->environmentValue('live', 'WebhookSecret');

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
            'api key' => 'ApiKey',
            'api secret' => 'ApiSecret',
            'project id' => 'ProjectId',
            'webhook secret' => 'WebhookSecret',
        );

        foreach ($fields as $label => $suffix) {
            if ($this->environmentValue($environment, $suffix) === '') {
                throw new \InvalidArgumentException('Paymos WHMCS config is missing ' . $environment . $suffix . ' (' . $environment . ' ' . $label . ').');
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

    private function environmentValue($environment, $suffix)
    {
        $environment = $this->normalizeEnvironment($environment);
        $generated = self::generatedEnvironment($environment);
        $generatedKey = self::generatedKeyForSuffix($suffix);
        if ($generatedKey !== '' && isset($generated[$generatedKey]) && is_scalar($generated[$generatedKey]) && trim((string) $generated[$generatedKey]) !== '') {
            return trim((string) $generated[$generatedKey]);
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

    /** @param array<string, mixed> $config */
    public static function useConfigForTests(array $config)
    {
        self::$generated = $config;
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

        if (class_exists('WHMCS\\Database\\Capsule')) {
            try {
                $stored = CredentialStore::loadCredentials();
                if (count($stored) > 0) {
                    self::$generated = array('environments' => $stored);
                    return self::$generated;
                }
            } catch (\Throwable $exception) {
                self::$generated = array();
                return self::$generated;
            }
        }

        self::$generated = array();
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
