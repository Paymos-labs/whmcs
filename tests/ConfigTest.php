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
    assertSameValue(false, array_key_exists('apiKey', $fields), 'WHMCS config must not expose a generic API key.');
    assertSameValue(false, array_key_exists('sandboxApiSecret', $fields), 'WHMCS config must not expose a sandbox API secret.');
    assertSameValue(false, array_key_exists('liveApiSecret', $fields), 'WHMCS config must not expose a live API secret.');
    assertSameValue(false, array_key_exists('sandboxWebhookSecret', $fields), 'WHMCS config must not expose a sandbox webhook secret.');
    assertSameValue(false, array_key_exists('liveWebhookSecret', $fields), 'WHMCS config must not expose a live webhook secret.');
    assertContainsValue('Not connected', $fields['generatedConfig']['Value'], 'WHMCS config must direct a new installation to Connect Paymos.');
}

function test_whmcs_module_config_renders_visible_connection_row_and_localizes_for_admin()
{
    $hadAdminId = isset($_SESSION['adminid']);
    $previousAdminId = $hadAdminId ? $_SESSION['adminid'] : null;
    $hadSessionLanguage = isset($_SESSION['Language']);
    $previousSessionLanguage = $hadSessionLanguage ? $_SESSION['Language'] : null;

    try {
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['adminid'], $_SESSION['Language']);
        }
        Config::resetForTests();
        if (class_exists('FakeWhmcsCapsuleState')) {
            FakeWhmcsCapsuleState::reset();
        }
        if (class_exists('WHMCS\\User\\Admin')) {
            \WHMCS\User\Admin::reset();
        }

        // Default English state before Connect.
        $disconnected = Config::moduleConfig();
        assertSameValue('System', $disconnected['FriendlyName']['Type'], 'FriendlyName row must remain Type=System.');
        assertSameValue('Paymos', $disconnected['FriendlyName']['Value'], 'FriendlyName must stay Paymos.');
        assertSameValue('Mode', $disconnected['mode']['FriendlyName'], 'Default English mode label must be Mode.');
        assertSameValue('Sandbox', $disconnected['mode']['Options']['sandbox'], 'Default English sandbox option must be Sandbox.');
        assertSameValue('Live', $disconnected['mode']['Options']['live'], 'Default English live option must be Live.');
        assertSameValue('Connection', $disconnected['generatedConfig']['FriendlyName'], 'Default English Connection row label must be Connection.');
        assertSameValue('', $disconnected['generatedConfig']['Type'], 'Connection row must use empty Type so WHMCS displays it visibly.');
        assertSameValue(
            'Not connected — click Connect Paymos and approve this installation',
            $disconnected['generatedConfig']['Description'],
            'Disconnected Connection row Description must describe how to connect.'
        );
        assertSameValue(
            $disconnected['generatedConfig']['Description'],
            $disconnected['generatedConfig']['Value'],
            'Connection row Value must match Description for backwards compatibility.'
        );
        assertSameValue('Button Text', $disconnected['buttonText']['FriendlyName'], 'Default English button text label must be Button Text.');
        assertSameValue('Pay with Paymos', $disconnected['buttonText']['Default'], 'Button text Default must remain Pay with Paymos.');

        // Connected state updates both Description and Value.
        Config::useConfigForTests(array(
            'environments' => array(
                'sandbox' => array(
                    'api_key' => 'pk_test_connected',
                    'api_secret' => 'sk_test_connected',
                    'project_id' => 'prj_connected',
                    'webhook_secret' => 'whsec_connected',
                ),
            ),
        ));
        $connected = Config::moduleConfig();
        assertSameValue('', $connected['generatedConfig']['Type'], 'Connected Connection row must keep empty Type.');
        assertSameValue(
            'Connected — credentials are encrypted in this WHMCS installation',
            $connected['generatedConfig']['Description'],
            'Connected Connection row Description must reflect encrypted credentials.'
        );
        assertSameValue(
            $connected['generatedConfig']['Description'],
            $connected['generatedConfig']['Value'],
            'Connected Connection row Value must match Description.'
        );

        // Russian administrator sees all visible settings localized while keeping Default button text.
        Config::resetForTests();
        $_SESSION['adminid'] = 77;
        \WHMCS\User\Admin::$records[77] = (object) array('language' => 'russian');

        $ruDisconnected = Config::moduleConfig();
        assertSameValue('Режим', $ruDisconnected['mode']['FriendlyName'], 'Russian admin must see localized mode label.');
        assertSameValue('Песочница', $ruDisconnected['mode']['Options']['sandbox'], 'Russian admin must see localized Sandbox option.');
        assertSameValue('Боевой', $ruDisconnected['mode']['Options']['live'], 'Russian admin must see localized Live option.');
        assertSameValue(
            'Сначала проверьте тестовые оплаты в песочнице, затем переключитесь на боевой режим для реальных заказов.',
            $ruDisconnected['mode']['Description'],
            'Russian admin must see localized mode description.'
        );
        assertSameValue('Подключение', $ruDisconnected['generatedConfig']['FriendlyName'], 'Russian admin must see localized Connection label.');
        assertSameValue('', $ruDisconnected['generatedConfig']['Type'], 'Localized Connection row must use empty Type.');
        assertSameValue(
            'Не подключено — нажмите «Подключить Paymos» и подтвердите установку',
            $ruDisconnected['generatedConfig']['Description'],
            'Russian admin must see localized disconnected status in Description.'
        );
        assertSameValue(
            $ruDisconnected['generatedConfig']['Description'],
            $ruDisconnected['generatedConfig']['Value'],
            'Russian admin Connection row Value must match Description.'
        );
        assertSameValue('Текст кнопки', $ruDisconnected['buttonText']['FriendlyName'], 'Russian admin must see localized Button Text label.');
        assertSameValue(
            'Подпись на кнопке оплаты в счёте WHMCS.',
            $ruDisconnected['buttonText']['Description'],
            'Russian admin must see localized Button Text description.'
        );
        assertSameValue('Pay with Paymos', $ruDisconnected['buttonText']['Default'], 'Button text Default must remain English for default-override detection.');

        // Connected state for Russian admin.
        Config::useConfigForTests(array(
            'environments' => array(
                'sandbox' => array(
                    'api_key' => 'pk_test_ru',
                    'api_secret' => 'sk_test_ru',
                    'project_id' => 'prj_ru',
                    'webhook_secret' => 'whsec_ru',
                ),
            ),
        ));
        $ruConnected = Config::moduleConfig();
        assertSameValue(
            'Подключено — ключи зашифрованы в этой установке WHMCS',
            $ruConnected['generatedConfig']['Description'],
            'Russian admin must see localized connected status in Description.'
        );
        assertSameValue(
            $ruConnected['generatedConfig']['Description'],
            $ruConnected['generatedConfig']['Value'],
            'Russian admin connected Value must match Description.'
        );

        // Fallback to WHMCS store default language when admin language is absent.
        unset($_SESSION['adminid']);
        \WHMCS\User\Admin::reset();
        Config::resetForTests();
        FakeWhmcsCapsuleState::$tables['tblconfiguration'] = array(
            array('setting' => 'Language', 'value' => 'russian'),
        );
        $ruStoreDefault = Config::moduleConfig();
        assertSameValue('Режим', $ruStoreDefault['mode']['FriendlyName'], 'Store default Russian language must localize moduleConfig().');
        assertSameValue('Подключение', $ruStoreDefault['generatedConfig']['FriendlyName'], 'Store default Russian language must localize Connection label.');
    } finally {
        if ($hadAdminId) {
            $_SESSION['adminid'] = $previousAdminId;
        } elseif (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['adminid']);
        }
        if ($hadSessionLanguage) {
            $_SESSION['Language'] = $previousSessionLanguage;
        } elseif (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION['Language']);
        }
        Config::resetForTests();
        if (class_exists('FakeWhmcsCapsuleState')) {
            FakeWhmcsCapsuleState::reset();
        }
        if (class_exists('WHMCS\\User\\Admin')) {
            \WHMCS\User\Admin::reset();
        }
    }
}

function test_whmcs_manifest_points_to_valid_png_logo()
{
    $manifestPath = PAYMOS_WHMCS_MODULE_DIR . 'whmcs.json';
    assertTrueValue(is_file($manifestPath), 'whmcs.json must exist in the gateway module directory.');

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    assertTrueValue(is_array($manifest), 'whmcs.json must contain valid JSON.');
    assertTrueValue(isset($manifest['logo']['filename']), 'whmcs.json must declare logo.filename.');
    assertSameValue('logo.png', $manifest['logo']['filename'], 'whmcs.json must point to raster logo.png instead of SVG.');

    $logoPath = PAYMOS_WHMCS_MODULE_DIR . $manifest['logo']['filename'];
    assertTrueValue(is_file($logoPath), 'logo.png referenced by whmcs.json must exist.');

    $bytes = file_get_contents($logoPath);
    assertTrueValue(is_string($bytes) && strlen($bytes) >= 24, 'logo.png must be readable and contain a complete PNG header.');
    assertSameValue("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8), 'logo.png must start with the 8-byte PNG signature.');
    assertSameValue('IHDR', substr($bytes, 12, 4), 'logo.png must start with an IHDR chunk.');

    $dimensions = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
    assertTrueValue(is_array($dimensions), 'logo.png IHDR dimensions must unpack cleanly.');
    assertSameValue(400, (int) $dimensions['width'], 'logo.png width must be 400px.');
    assertSameValue(80, (int) $dimensions['height'], 'logo.png height must be 80px.');
}

function test_whmcs_connect_footer_targets_paymos_form_and_resolves_csrf_token()
{
    $output = array();
    $status = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/connect-footer.php') . ' 2>&1',
        $output,
        $status
    );
    $joined = implode(PHP_EOL, $output);

    assertSameValue(0, $status, $joined);
    assertContainsValue('OK connect-footer', $joined, 'Connect footer fixture must verify #configpaymosgatewayForm targeting and CSRF resolution.');
}
