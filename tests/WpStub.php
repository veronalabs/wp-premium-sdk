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

    /** Network-wide options (get_site_option and friends). @var array<string, mixed> */
    public static array $siteOptions = [];

    /** Whether the current site is the network's main site. */
    public static bool $isMainSite = true;

    /** Other subsites' option rows, keyed by blog id then option name. @var array<int, array<string, mixed>> */
    public static array $blogOptions = [];

    /** Subsite home addresses for get_home_url(), keyed by blog id. @var array<int, string> */
    public static array $blogHomeUrls = [];

    /** The logged-in WordPress user. */
    public static int $currentUserId = 1;

    /** The blog being served; blog 1 is the main site. */
    public static int $currentBlogId = 1;

    /** Saved state for restore_current_blog(). @var array<int, array<string, mixed>> */
    public static array $blogStack = [];

    /** Capabilities current_user_can() says no to. @var array<int, string> */
    public static array $deniedCapabilities = [];

    /** What wp_is_file_mod_allowed() answers. */
    public static bool $fileModsAllowed = true;

    /** What get_filesystem_method() answers. */
    public static string $filesystemMethod = 'direct';

    /** What request_filesystem_credentials() answers (false: none stored). @var array<string, string>|false */
    public static $filesystemCredentials = false;

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
        self::$siteOptions = [];
        self::$isMainSite = true;
        self::$blogOptions = [];
        self::$blogHomeUrls = [];
        self::$deniedCapabilities = [];
        self::$currentUserId = 1;
        self::$currentBlogId = 1;
        self::$blogStack = [];
        self::$fileModsAllowed = true;
        self::$filesystemMethod = 'direct';
        self::$filesystemCredentials = false;
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

    /**
     * Serve another blog from now on: its own options table and address. The
     * current blog's options are kept in $blogOptions.
     */
    public static function switchBlog(int $blogId, bool $remember = false): void
    {
        if ($remember) {
            self::$blogStack[] = ['blog' => self::$currentBlogId, 'home' => self::$homeUrl, 'main' => self::$isMainSite];
        }

        self::$blogOptions[self::$currentBlogId] = self::$options;
        self::$options = self::$blogOptions[$blogId] ?? [];
        self::$homeUrl = self::$blogHomeUrls[$blogId] ?? self::$homeUrl;
        self::$isMainSite = $blogId === 1;
        self::$currentBlogId = $blogId;
    }
}
