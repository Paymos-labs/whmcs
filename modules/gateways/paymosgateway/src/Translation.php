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
            isset($params['language']) ? $params['language'] : null,
            isset($params['clientdetails']) && is_array($params['clientdetails']) && isset($params['clientdetails']['language'])
                ? $params['clientdetails']['language']
                : null,
        );
        if (isset($params['clientdetails']) && is_array($params['clientdetails'])
            && isset($params['clientdetails']['model']) && is_object($params['clientdetails']['model'])) {
            $candidates[] = isset($params['clientdetails']['model']->language) ? $params['clientdetails']['model']->language : null;
        }

        foreach ($candidates as $candidate) {
            $normalized = strtolower(str_replace('_', '-', trim((string) $candidate)));
            if (isset(self::$languages[$normalized])) {
                return self::$languages[$normalized];
            }
        }

        return 'english';
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
