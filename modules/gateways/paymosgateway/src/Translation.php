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
            if ($normalized === 'ru' || $normalized === 'ru-ru' || $normalized === 'russian') {
                return 'russian';
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
