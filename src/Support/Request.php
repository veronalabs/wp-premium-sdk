<?php

namespace VeronaLabs\WpPremiumSdk\Support;

/**
 * Tiny helper for sanitized $_GET/$_POST access inside AJAX handlers.
 */
class Request
{
    public static function get(string $key, mixed $default = ''): mixed
    {
        if (isset($_POST[$key])) {
            return is_array($_POST[$key])
                ? array_map('sanitize_text_field', wp_unslash($_POST[$key]))
                : sanitize_text_field(wp_unslash($_POST[$key]));
        }

        if (isset($_GET[$key])) {
            return is_array($_GET[$key])
                ? array_map('sanitize_text_field', wp_unslash($_GET[$key]))
                : sanitize_text_field(wp_unslash($_GET[$key]));
        }

        return $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function getArray(string $key): array
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? [];

        return is_array($value) ? wp_unslash($value) : [];
    }

    public static function currentDomain(): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        if (! $host) {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
