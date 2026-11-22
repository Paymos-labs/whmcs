<?php

declare(strict_types=1);

namespace WHMCS\User {

    if (!class_exists('WHMCS\User\Admin', false)) {
        final class Admin
        {
            /** @var array<int|string, object> */
            public static $records = array();

            public static function find($id)
            {
                return isset(self::$records[$id]) ? self::$records[$id] : null;
            }

            public static function reset()
            {
                self::$records = array();
            }
        }
    }
}

namespace {

    use PaymosWhmcs\Translation;

    function test_whmcs_translation_uses_russian_client_language()
    {
        $params = whmcs_gateway_params(array(
            'clientdetails' => array('language' => 'russian'),
        ));

        assertSameValue('Оплатить через Paymos', Translation::text('pay_button', $params), 'Russian client must receive Russian payment text.');
        assertSameValue('Paymos временно недоступен. Обратитесь в поддержку.', Translation::text('payment_unavailable', $params), 'Russian errors must use the Russian catalog.');
    }

    function test_whmcs_translation_falls_back_to_english()
    {
        assertSameValue('Pay with Paymos', Translation::text('pay_button', whmcs_gateway_params()), 'Unknown or absent language must fall back to English.');
    }

    // The plugin ships six catalogues. Four of them were unreachable: the resolver
    // only ever answered "russian" or "english", so a German store read the English
    // file and the other three .php files were dead weight on disk.
    function test_whmcs_translation_reaches_every_shipped_catalog()
    {
        $expected = array(
            'german' => 'Mit Paymos bezahlen',
            'spanish' => 'Pagar con Paymos',
            'turkish' => 'Paymos ile öde',
            'chinese' => '通过 Paymos 付款',
            'russian' => 'Оплатить через Paymos',
            'english' => 'Pay with Paymos',
        );

        foreach ($expected as $language => $button) {
            $params = whmcs_gateway_params(array('clientdetails' => array('language' => $language)));
            assertSameValue($button, Translation::text('pay_button', $params), $language . ' client must read the ' . $language . ' catalog.');
        }
    }

    // WHMCS reports the language by its English name, but a client record can carry
    // an ISO code instead depending on where it came from.
    function test_whmcs_translation_accepts_iso_codes()
    {
        $codes = array(
            'de' => 'Mit Paymos bezahlen',
            'de-DE' => 'Mit Paymos bezahlen',
            'es' => 'Pagar con Paymos',
            'tr_TR' => 'Paymos ile öde',
            'zh-Hans' => '通过 Paymos 付款',
            'ru' => 'Оплатить через Paymos',
        );

        foreach ($codes as $code => $button) {
            $params = whmcs_gateway_params(array('clientdetails' => array('language' => $code)));
            assertSameValue($button, Translation::text('pay_button', $params), $code . ' must resolve to its catalog.');
        }
    }

    // A language we ship no catalogue for must read English, not a raw key.
    function test_whmcs_translation_unknown_language_reads_english()
    {
        $params = whmcs_gateway_params(array('clientdetails' => array('language' => 'portuguese')));
        assertSameValue('Pay with Paymos', Translation::text('pay_button', $params), 'An unshipped language must fall back to English.');
    }

    function test_whmcs_connect_catalogs_cover_every_ui_label()
    {
        foreach (array('english', 'russian', 'german', 'spanish', 'turkish', 'chinese') as $language) {
            $catalog = require PAYMOS_WHMCS_MODULE_DIR . 'lang/' . $language . '.php';
            foreach (array('button', 'starting', 'waiting', 'open', 'blocked', 'code', 'failed') as $key) {
                assertTrueValue(isset($catalog['connect_' . $key]) && $catalog['connect_' . $key] !== '', $language . ' must translate ' . $key);
                assertSameValue($catalog['connect_' . $key], Translation::text('connect_' . $key, array('language' => $language)), 'Connect must resolve its admin language.');
            }
        }
    }

    function test_whmcs_connect_requires_gateway_configuration_permission()
    {
        $_SESSION['adminid'] = 42;
        $GLOBALS['paymos_connect_permission'] = false;
        try {
            paymosgateway_connect_authorize();
            throw new RuntimeException('A restricted admin must not start or poll Connect.');
        } catch (RuntimeException $error) {
            assertSameValue('paymos_connect_access_denied', $error->getMessage(), 'Restricted admin must be denied.');
        }
        $GLOBALS['paymos_connect_permission'] = true;
        paymosgateway_connect_authorize();
        unset($_SESSION['adminid'], $GLOBALS['paymos_connect_permission']);
    }

    function test_whmcs_connect_entry_denies_both_device_actions_before_platform_access()
    {
        foreach (array('start', 'poll') as $action) {
            $output = array();
            $status = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/connect-entry.php') . ' ' . escapeshellarg($action) . ' 2>&1', $output, $status);
            assertSameValue(0, $status, implode(PHP_EOL, $output));
            assertContainsValue('OK denied', implode(PHP_EOL, $output), 'Both device actions must fail closed without gateway permission.');
        }
    }

    // The gateway decides whether to surface the API's English error detail. That
    // decision used to be made by comparing a button label against its English text,
    // which couples an unrelated string to error handling.
    function test_whmcs_translation_reports_whether_the_store_reads_english()
    {
        assertSameValue(true, Translation::isEnglish(whmcs_gateway_params()), 'A store with no language set reads English.');
        assertSameValue(
            false,
            Translation::isEnglish(whmcs_gateway_params(array('clientdetails' => array('language' => 'german')))),
            'A German store does not read English.'
        );
    }

    function test_whmcs_translation_falls_back_to_session_and_store_default_language()
    {
        $hadSessionLanguage = isset($_SESSION['Language']);
        $previousSessionLanguage = $hadSessionLanguage ? $_SESSION['Language'] : null;

        try {
            FakeWhmcsCapsuleState::reset();
            $emptyClientParams = whmcs_gateway_params(array(
                'clientdetails' => array('language' => ''),
            ));

            $_SESSION['Language'] = 'russian';
            assertSameValue(
                'Оплатить через Paymos',
                Translation::text('pay_button', $emptyClientParams),
                'Empty client language must fall back to $_SESSION["Language"].'
            );
            assertSameValue(
                false,
                Translation::isEnglish($emptyClientParams),
                'Session Russian language must report isEnglish() === false.'
            );

            $germanClientParams = whmcs_gateway_params(array(
                'clientdetails' => array('language' => 'german'),
            ));
            assertSameValue(
                'Mit Paymos bezahlen',
                Translation::text('pay_button', $germanClientParams),
                'Explicit client language must take precedence over $_SESSION["Language"].'
            );

            unset($_SESSION['Language']);
            FakeWhmcsCapsuleState::$tables['tblconfiguration'] = array(
                array('setting' => 'Language', 'value' => 'russian'),
            );
            assertSameValue(
                'Оплатить через Paymos',
                Translation::text('pay_button', $emptyClientParams),
                'Empty client and session language must fall back to tblconfiguration.Language.'
            );
            assertSameValue(
                false,
                Translation::isEnglish($emptyClientParams),
                'Store default Russian language must report isEnglish() === false.'
            );
            assertSameValue(
                'Paymos временно недоступен. Обратитесь в поддержку.',
                Translation::text('payment_unavailable', $emptyClientParams),
                'Error notices must resolve from tblconfiguration.Language when client language is empty.'
            );

            $_SESSION['Language'] = 'german';
            assertSameValue(
                'Mit Paymos bezahlen',
                Translation::text('pay_button', $emptyClientParams),
                '$_SESSION["Language"] must take precedence over tblconfiguration.Language.'
            );

            // An explicitly chosen unshipped language must read English rather than
            // leaking the session or store default non-English catalogue.
            FakeWhmcsCapsuleState::$tables['tblconfiguration'] = array(
                array('setting' => 'Language', 'value' => 'chinese'),
            );
            $_SESSION['Language'] = 'russian';
            $portugueseClientParams = whmcs_gateway_params(array(
                'clientdetails' => array('language' => 'portuguese'),
            ));
            assertSameValue(
                'Pay with Paymos',
                Translation::text('pay_button', $portugueseClientParams),
                'Unsupported explicit client language must read English instead of session or store default.'
            );
            assertSameValue(
                true,
                Translation::isEnglish($portugueseClientParams),
                'Unsupported explicit client language must report isEnglish() === true.'
            );

            $_SESSION['Language'] = 'french';
            assertSameValue(
                'Pay with Paymos',
                Translation::text('pay_button', $emptyClientParams),
                'Unsupported explicit session language must read English instead of store default.'
            );
            assertSameValue(
                true,
                Translation::isEnglish($emptyClientParams),
                'Unsupported explicit session language must report isEnglish() === true.'
            );
        } finally {
            if ($hadSessionLanguage) {
                $_SESSION['Language'] = $previousSessionLanguage;
            } else {
                unset($_SESSION['Language']);
            }
            FakeWhmcsCapsuleState::reset();
        }

        if (!$hadSessionLanguage) {
            assertFalseValue(isset($_SESSION['Language']), 'Session language must not leak after test.');
        }
    }

    function test_whmcs_translation_admin_language_params_and_all_six_catalogs_cover_admin_and_refund_keys()
    {
        $hadAdminId = isset($_SESSION['adminid']);
        $previousAdminId = $hadAdminId ? $_SESSION['adminid'] : null;
        $hadSessionLanguage = isset($_SESSION['Language']);
        $previousSessionLanguage = $hadSessionLanguage ? $_SESSION['Language'] : null;

        try {
            unset($_SESSION['adminid'], $_SESSION['Language']);
            FakeWhmcsCapsuleState::reset();
            \WHMCS\User\Admin::reset();

            assertSameValue(
                array(),
                Translation::adminLanguageParams(),
                'Without admin session or store default language, adminLanguageParams() must return an empty array.'
            );

            FakeWhmcsCapsuleState::$tables['tblconfiguration'] = array(
                array('setting' => 'Language', 'value' => 'russian'),
            );
            assertSameValue(
                array('language' => 'russian'),
                Translation::adminLanguageParams(),
                'Without admin session, adminLanguageParams() must fall back to tblconfiguration.Language.'
            );
            assertSameValue(
                'Режим',
                Translation::text('config_mode_label', Translation::adminLanguageParams()),
                'Admin text must resolve through the Russian store default language.'
            );

            $_SESSION['adminid'] = 15;
            \WHMCS\User\Admin::$records[15] = (object) array('language' => 'german');
            assertSameValue(
                array('language' => 'german'),
                Translation::adminLanguageParams(),
                'Logged-in admin profile language must take precedence over tblconfiguration.Language.'
            );
            assertSameValue(
                'Modus',
                Translation::text('config_mode_label', Translation::adminLanguageParams()),
                'German admin must receive German admin settings copy.'
            );

            $_SESSION['adminid'] = 16;
            \WHMCS\User\Admin::$records[16] = (object) array('language' => '');
            assertSameValue(
                array('language' => 'russian'),
                Translation::adminLanguageParams(),
                'Admin profile with empty language must fall back to tblconfiguration.Language.'
            );

            $_SESSION['adminid'] = 999;
            assertSameValue(
                array('language' => 'russian'),
                Translation::adminLanguageParams(),
                'Unknown admin id must fall back to tblconfiguration.Language.'
            );

            FakeWhmcsCapsuleState::$tables['tblconfiguration'] = array(
                array('setting' => 'Language', 'value' => 'chinese'),
            );
            $_SESSION['adminid'] = 17;
            \WHMCS\User\Admin::$records[17] = (object) array('language' => 'french');
            $frenchAdminParams = Translation::adminLanguageParams();
            assertSameValue(
                array('language' => 'french'),
                $frenchAdminParams,
                'Admin profile with unsupported language must still carry its explicit language.'
            );
            assertSameValue(
                'Mode',
                Translation::text('config_mode_label', $frenchAdminParams),
                'Unsupported admin language must read English rather than Chinese store default.'
            );
            assertSameValue(
                true,
                Translation::isEnglish($frenchAdminParams),
                'Unsupported admin language must report isEnglish() === true.'
            );
        } finally {
            if ($hadAdminId) {
                $_SESSION['adminid'] = $previousAdminId;
            } else {
                unset($_SESSION['adminid']);
            }
            if ($hadSessionLanguage) {
                $_SESSION['Language'] = $previousSessionLanguage;
            } else {
                unset($_SESSION['Language']);
            }
            FakeWhmcsCapsuleState::reset();
            \WHMCS\User\Admin::reset();
        }

        if (!$hadAdminId) {
            assertFalseValue(isset($_SESSION['adminid']), 'Admin session id must not leak after test.');
        }

        $english = require PAYMOS_WHMCS_MODULE_DIR . 'lang/english.php';
        $englishKeys = array_keys($english);
        sort($englishKeys);
        assertSameValue(33, count($englishKeys), 'English catalog must define all 33 keys.');

        $newKeys = array(
            'config_mode_label',
            'config_mode_sandbox',
            'config_mode_live',
            'config_mode_description',
            'config_connection_label',
            'config_connection_connected',
            'config_connection_disconnected',
            'config_button_text_label',
            'config_button_text_description',
            'refund_panel_title',
            'refund_label_operation',
            'refund_label_type',
            'refund_label_cash_status',
            'refund_label_whmcs_record',
            'refund_label_review_reason',
            'refund_label_amount',
            'refund_button_full',
            'refund_button_overpayment',
            'refund_failed',
            'refund_reconnect_hint',
            'refund_native_in_progress',
            'refund_native_failed',
        );
        foreach ($newKeys as $requiredKey) {
            assertTrueValue(isset($english[$requiredKey]), 'English catalog must define ' . $requiredKey . '.');
        }

        $allowEnglishMatch = array('config_mode_sandbox', 'config_mode_live', 'connect_code');
        foreach (array('english', 'russian', 'german', 'spanish', 'turkish', 'chinese') as $language) {
            $catalog = require PAYMOS_WHMCS_MODULE_DIR . 'lang/' . $language . '.php';
            $keys = array_keys($catalog);
            sort($keys);
            assertSameValue($englishKeys, $keys, $language . ' catalog must define the exact same 33 keys as english.php.');

            foreach ($englishKeys as $key) {
                assertTrueValue(
                    isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '',
                    $language . ' must define non-empty string for ' . $key . '.'
                );
                assertTrueValue(
                    $catalog[$key] !== $key,
                    $language . ' must not return raw key name for ' . $key . '.'
                );
                assertSameValue(
                    $catalog[$key],
                    Translation::text($key, array('language' => $language)),
                    'Translation::text() must return ' . $language . ' catalog entry for ' . $key . '.'
                );
                if ($language !== 'english' && !in_array($key, $allowEnglishMatch, true)) {
                    assertTrueValue(
                        $catalog[$key] !== $english[$key],
                        $language . ' must translate ' . $key . ' instead of repeating English.'
                    );
                }
            }
        }

        assertSameValue(
            'Not connected — click Connect Paymos and approve this installation',
            Translation::text('config_connection_disconnected', array('language' => 'english')),
            'English disconnected status must match canonical copy.'
        );
        assertSameValue('Режим', Translation::text('config_mode_label', array('language' => 'russian')), 'Russian mode label must match canonical copy.');
        assertSameValue('Песочница', Translation::text('config_mode_sandbox', array('language' => 'russian')), 'Russian sandbox label must match canonical copy.');
        assertSameValue('Боевой', Translation::text('config_mode_live', array('language' => 'russian')), 'Russian live label must match canonical copy.');
        assertSameValue('Подключение', Translation::text('config_connection_label', array('language' => 'russian')), 'Russian connection label must match canonical copy.');
        assertSameValue('Текст кнопки', Translation::text('config_button_text_label', array('language' => 'russian')), 'Russian button text label must match canonical copy.');
        assertSameValue('Возвраты Paymos', Translation::text('refund_panel_title', array('language' => 'russian')), 'Russian refund panel title must match canonical copy.');
    }
}
