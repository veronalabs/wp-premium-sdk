<?php

namespace VeronaLabs\WpPremiumSdk\Tests;

/**
 * In-memory WordPress function stubs for SDK unit tests.
 *
 * Provides enough of the wp_* / WP_Error surface for SDK classes to run
 * without WordPress. State lives in static properties so tests can reset
 * between runs via WpStub::reset().
 */
class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];

    /** The address `home_url()` answers with. */
    public static string $homeUrl = 'https://example.com';

    /** The address `network_home_url()` answers with. */
    public static string $networkHomeUrl = 'https://example.com';

    /** Whether this install is a network at all. */
    public static bool $isMultisite = false;

    /** Plugin files activated across the whole network. */
    public static array $networkActivatedPlugins = [];

    /** @var array<string, mixed> */
    public static array $transients = [];

    /** @var array<string, mixed> */
    public static array $siteTransients = [];

    /**
     * Next wp_remote_* response(s). Each tuple: [statusCode, body|array, headers].
     *
     * @var array<int, array{int, mixed, array<string, string>}>
     */
    public static array $responseQueue = [];

    /**
     * Log of every outgoing HTTP call made through wp_remote_*.
     *
     * @var array<int, array{method: string, url: string, args: array<string, mixed>}>
     */
    public static array $requestLog = [];

    /**
     * Registered filters, keyed by tag then priority.
     *
     * @var array<string, array<int, array<int, callable>>>
     */
    public static array $filters = [];

    /**
     * Every wp_send_json_* payload, in order: ['success' => bool, 'data' => mixed, 'status' => int|null].
     *
     * @var array<int, array{success: bool, data: mixed, status: int|null}>
     */
    public static array $jsonResponses = [];

    public static function bootstrap(): void
    {
        if (defined('WP_PREMIUM_SDK_TESTS_BOOTSTRAPPED')) {
            return;
        }
        define('WP_PREMIUM_SDK_TESTS_BOOTSTRAPPED', true);

        require_once __DIR__.'/wp-functions.php';
    }

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$siteTransients = [];
        self::$responseQueue = [];
        self::$requestLog = [];
        self::$filters = [];
        self::$jsonResponses = [];
        self::$homeUrl = 'https://example.com';
        self::$networkHomeUrl = 'https://example.com';
        self::$isMultisite = false;
        self::$networkActivatedPlugins = [];
    }

    /**
     * Queue a successful JSON response.
     *
     * @param  array<string, mixed>  $body
     */
    public static function queueJson(int $status, array $body, array $headers = []): void
    {
        self::$responseQueue[] = [$status, wp_json_encode($body), $headers];
    }

    /**
     * The last JSON payload an AJAX handler sent.
     *
     * @return array{success: bool, data: mixed, status: int|null}|null
     */
    public static function lastJson(): ?array
    {
        return self::$jsonResponses ? self::$jsonResponses[count(self::$jsonResponses) - 1] : null;
    }

    /**
     * Queue a transport error — translates into a WP_Error on wp_remote_*.
     */
    public static function queueError(string $message): void
    {
        self::$responseQueue[] = [0, new \WP_Error('http_request_failed', $message), []];
    }
}
