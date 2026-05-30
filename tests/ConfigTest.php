<?php

declare(strict_types=1);

use PaymosWhmcs\Config;

function test_whmcs_config_builds_client_config_and_secret_map()
{
    $config = Config::fromParams(whmcs_gateway_params());

    assertSameValue('sandbox', $config->environment(), 'mode dropdown must select sandbox environment.');
    assertSameValue('prj_123', $config->projectId(), 'sandbox project id must come from active WHMCS params.');
    assertSameValue('https://api.paymos.test', $config->clientConfig()->baseUrl(), 'base URL must be normalized into SDK config.');
    assertSameValue('pk_test_123', $config->clientConfig()->apiKey(), 'sandbox mode must use sandbox API key.');
    assertSameValue(array('sandbox' => 'whsec_sandbox', 'live' => 'whsec_live'), $config->webhookSecrets(), 'both configured webhook secrets should be available for callback verification.');
    assertSameValue(43200, $config->invoiceLifetimeSeconds(), '12 hour invoice lifetime must be seconds.');
}

function test_whmcs_config_switches_to_live_without_retyping_credentials()
{
    $config = Config::fromParams(whmcs_gateway_params(array('mode' => 'live')));

    assertSameValue('live', $config->environment(), 'live mode must select live environment.');
    assertSameValue('pk_live_123', $config->clientConfig()->apiKey(), 'live mode must use live API key.');
    assertSameValue('sk_live_123', $config->clientConfig()->apiSecret(), 'live mode must use live API secret.');
    assertSameValue('prj_live_123', $config->projectId(), 'live mode must use live project id.');
}

function test_whmcs_config_can_build_client_for_webhook_environment_independent_of_active_mode()
{
    $config = Config::fromParams(whmcs_gateway_params(array('mode' => 'sandbox')));

    assertSameValue('pk_live_123', $config->clientConfigForEnvironment('live')->apiKey(), 'callback must be able to reverse-verify live events even when admin mode is sandbox.');
    assertSameValue('prj_live_123', $config->projectIdForEnvironment('live'), 'callback must use live project snapshot for live events.');
}

function test_whmcs_generated_config_supplies_read_only_credentials_and_hides_admin_credential_fields()
{
    paymos_whmcs_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_test_zip',
                'api_secret' => 'sk_test_zip',
                'project_id' => 'prj_zip_sandbox',
                'webhook_secret' => 'whsec_zip_sandbox',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_zip',
                'api_secret' => 'sk_live_zip',
                'project_id' => 'prj_zip_live',
                'webhook_secret' => 'whsec_zip_live',
            ),
        ),
    )");

    $fields = Config::moduleConfig();
    assertSameValue(false, array_key_exists('sandboxApiKey', $fields), 'dashboard ZIP credentials must not be editable in WHMCS admin.');
    assertSameValue(false, array_key_exists('liveApiKey', $fields), 'dashboard ZIP credentials must not be editable in WHMCS admin.');
    assertSameValue(false, array_key_exists('apiBaseUrl', $fields), 'dashboard ZIP API base URL must not be editable in WHMCS admin.');
    assertSameValue('dropdown', $fields['mode']['Type'], 'mode switch must remain available when generated credentials exist.');

    $config = Config::fromParams(array(
        'paymentmethod' => 'paymosgateway',
        'mode' => 'sandbox',
        'buttonText' => 'Pay with Paymos',
        'invoiceLifetime' => '12',
    ));

    assertSameValue('pk_test_zip', $config->clientConfig()->apiKey(), 'sandbox API key must come from generated config.');
    assertSameValue('prj_zip_sandbox', $config->projectId(), 'sandbox project id must come from generated config.');
    assertSameValue(array('sandbox' => 'whsec_zip_sandbox', 'live' => 'whsec_zip_live'), $config->webhookSecrets(), 'generated config must provide both webhook secrets.');
}

function test_whmcs_generated_config_switches_to_live_without_exposing_credentials()
{
    paymos_whmcs_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_zip',
                'api_secret' => 'sk_test_zip',
                'project_id' => 'prj_zip_sandbox',
                'webhook_secret' => 'whsec_zip_sandbox',
            ),
            'live' => array(
                'api_key' => 'pk_live_zip',
                'api_secret' => 'sk_live_zip',
                'project_id' => 'prj_zip_live',
                'webhook_secret' => 'whsec_zip_live',
            ),
        ),
    )");

    $config = Config::fromParams(array('mode' => 'live'));

    assertSameValue('live', $config->environment(), 'generated config must still honor admin mode switch.');
    assertSameValue('pk_live_zip', $config->clientConfig()->apiKey(), 'live API key must come from generated config.');
    assertSameValue('sk_live_zip', $config->clientConfig()->apiSecret(), 'live API secret must come from generated config.');
    assertSameValue('prj_zip_live', $config->projectId(), 'live project id must come from generated config.');
}

function test_whmcs_config_rejects_missing_required_values()
{
    $missingProject = whmcs_gateway_params(array('sandboxProjectId' => '', 'projectId' => ''));
    try {
        Config::fromParams($missingProject);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('sandboxProjectId', $e->getMessage(), 'missing project id error must identify the field.');
        return;
    }

    throw new RuntimeException('Config must reject missing project id.');
}

function test_whmcs_config_requires_at_least_one_webhook_secret()
{
    $params = whmcs_gateway_params(array(
        'sandboxWebhookSecret' => '',
        'liveWebhookSecret' => '',
        'webhookSecretSandbox' => '',
        'webhookSecretLive' => '',
    ));

    try {
        Config::fromParams($params);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('webhook secret', strtolower($e->getMessage()), 'missing webhook secret error must be explicit.');
        return;
    }

    throw new RuntimeException('Config must reject missing webhook secrets.');
}

function test_whmcs_config_requires_webhook_secret_for_selected_environment()
{
    $params = whmcs_gateway_params(array(
        'mode' => 'live',
        'liveWebhookSecret' => '',
        'webhookSecretLive' => '',
    ));

    try {
        Config::fromParams($params);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('live webhook secret', strtolower($e->getMessage()), 'missing selected environment webhook secret must be explicit.');
        return;
    }

    throw new RuntimeException('Config must reject live mode without live webhook secret.');
}

function test_whmcs_config_rejects_mismatched_api_secret_environment()
{
    $params = whmcs_gateway_params(array(
        'mode' => 'live',
        'liveApiSecret' => 'sk_test_123',
    ));

    try {
        Config::fromParams($params);
    } catch (InvalidArgumentException $e) {
        assertContainsValue('api secret', strtolower($e->getMessage()), 'mismatched API secret error must identify the field.');
        return;
    }

    throw new RuntimeException('Config must reject API key/API secret environment mismatch.');
}

function test_whmcs_module_config_does_not_expose_manual_test_mode()
{
    $fields = Config::moduleConfig();

    assertSameValue(false, array_key_exists('testMode', $fields), 'WHMCS config must not expose a manual test mode toggle.');
    assertSameValue('dropdown', $fields['mode']['Type'], 'WHMCS config must expose a Sandbox/Live mode dropdown.');
    assertSameValue(false, array_key_exists('apiKey', $fields), 'new WHMCS config must not expose only one generic API key.');
    assertSameValue('password', $fields['sandboxApiSecret']['Type'], 'sandbox API secret must be a password field.');
    assertSameValue('password', $fields['liveApiSecret']['Type'], 'live API secret must be a password field.');
    assertSameValue('password', $fields['sandboxWebhookSecret']['Type'], 'sandbox webhook secret must be a password field.');
    assertSameValue('password', $fields['liveWebhookSecret']['Type'], 'live webhook secret must be a password field.');
}
