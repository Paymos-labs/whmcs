<?php

declare(strict_types=1);

namespace PaymosWhmcs;

final class Translation
{
    /**
     * @param array<string, mixed> $params
     */
    public static function text($key, array $params)
    {
        $catalog = self::catalog(self::language($params));
        if (isset($catalog[$key]) && is_scalar($catalog[$key])) {
            return (string) $catalog[$key];
        }

        $fallback = self::catalog('english');
        return isset($fallback[$key]) && is_scalar($fallback[$key]) ? (string) $fallback[$key] : (string) $key;
    }

    /**
     * Whether the store reads the English catalogue — either because that is its
     * language or because we ship none for it. The caller uses this to decide
     * whether an English error detail from the API would look out of place.
     *
     * @param array<string, mixed> $params
     */
    public static function isEnglish(array $params)
    {
        return self::language($params) === 'english';
    }

    /**
     * Language params for WHMCS admin UI (config screen, Connect UI, refund panel).
     * Prefers the logged-in administrator's language and falls back to the WHMCS
     * store default language when the admin profile has none configured.
     *
     * @return array<string, string>
     */
    public static function adminLanguageParams(): array
    {
        $adminId = null;
        if (class_exists('WHMCS\\Session') && is_callable(array('WHMCS\\Session', 'get'))) {
            try {
                $adminId = \WHMCS\Session::get('adminid');
            } catch (\Throwable $e) {
                $adminId = null;
            }
        }
        if (empty($adminId) && isset($_SESSION['adminid'])) {
            $adminId = $_SESSION['adminid'];
        }

        if (!empty($adminId) && class_exists('WHMCS\\User\\Admin')
            && is_callable(array('WHMCS\\User\\Admin', 'find'))) {
            try {
                $admin = \WHMCS\User\Admin::find($adminId);
                if (is_object($admin) && isset($admin->language) && is_scalar($admin->language)) {
                    $language = trim((string) $admin->language);
                    if ($language !== '') {
                        return array('language' => $language);
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to the store default language.
            }
        }

        $default = self::storeDefaultLanguage();
        return $default !== '' ? array('language' => $default) : array();
    }

    /**
     * WHMCS names a language by its English word ("german"), but a client record
     * can carry an ISO code depending on where it was written, so both are
     * accepted. Anything we ship no catalogue for reads English.
     *
     * @var array<string, string>
     */
    private static $languages = array(
        'russian' => 'russian', 'ru' => 'russian', 'ru-ru' => 'russian',
        'german' => 'german', 'de' => 'german', 'de-de' => 'german', 'deutsch' => 'german',
        'spanish' => 'spanish', 'es' => 'spanish', 'es-es' => 'spanish', 'espanol' => 'spanish',
        'turkish' => 'turkish', 'tr' => 'turkish', 'tr-tr' => 'turkish',
        'chinese' => 'chinese', 'zh' => 'chinese', 'zh-cn' => 'chinese', 'zh-hans' => 'chinese',
        'english' => 'english', 'en' => 'english', 'en-gb' => 'english', 'en-us' => 'english',
    );

    /**
     * @param array<string, mixed> $params
     */
    private static function language(array $params)
    {
        $candidates = array(
            isset($params['language']) && is_scalar($params['language']) ? (string) $params['language'] : null,
            isset($params['clientdetails']) && is_array($params['clientdetails'])
                && isset($params['clientdetails']['language']) && is_scalar($params['clientdetails']['language'])
                ? (string) $params['clientdetails']['language']
                : null,
        );
        if (isset($params['clientdetails']) && is_array($params['clientdetails'])
            && isset($params['clientdetails']['model']) && is_object($params['clientdetails']['model'])) {
            $candidates[] = isset($params['clientdetails']['model']->language)
                && is_scalar($params['clientdetails']['model']->language)
                ? (string) $params['clientdetails']['model']->language
                : null;
        }
        if (isset($_SESSION['Language']) && is_scalar($_SESSION['Language'])) {
            $candidates[] = (string) $_SESSION['Language'];
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $normalized = strtolower(str_replace('_', '-', trim($candidate)));
            if ($normalized === '') {
                continue;
            }
            return isset(self::$languages[$normalized]) ? self::$languages[$normalized] : 'english';
        }

        $storeDefault = self::storeDefaultLanguage();
        if ($storeDefault !== '') {
            $normalized = strtolower(str_replace('_', '-', trim($storeDefault)));
            if (isset(self::$languages[$normalized])) {
                return self::$languages[$normalized];
            }
        }

        return 'english';
    }

    private static function storeDefaultLanguage(): string
    {
        if (class_exists('WHMCS\\Config\\Setting')
            && is_callable(array('WHMCS\\Config\\Setting', 'getValue'))) {
            try {
                $value = \WHMCS\Config\Setting::getValue('Language');
                if (is_scalar($value)) {
                    $trimmed = trim((string) $value);
                    if ($trimmed !== '') {
                        return $trimmed;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to Capsule lookup.
            }
        }

        if (class_exists('WHMCS\\Database\\Capsule')) {
            try {
                $query = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'Language');
                $value = null;
                if (is_object($query) && method_exists($query, 'value')) {
                    $value = $query->value('value');
                } elseif (is_object($query) && method_exists($query, 'first')) {
                    $row = $query->first();
                    if (is_object($row) && isset($row->value)) {
                        $value = $row->value;
                    } elseif (is_array($row) && isset($row['value'])) {
                        $value = $row['value'];
                    }
                }

                if (is_scalar($value)) {
                    return trim((string) $value);
                }
            } catch (\Throwable $e) {
                return '';
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private static function catalog($language)
    {
        $path = dirname(__DIR__) . '/lang/' . $language . '.php';
        $catalog = is_file($path) ? require $path : array();
        return is_array($catalog) ? $catalog : array();
    }
}
