<?php

namespace VeronaLabs\WpPremiumSdk\Update;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;

/**
 * Bridges Nexus' unified manifest into WordPress' native plugin update flow.
 *
 * Hooks `pre_set_site_transient_update_plugins` so "check for updates" in
 * wp-admin surfaces the plugin update returned by /api/v1/{product}/update/manifest.
 * Also exposes the manifest itself so the admin UI can drive module updates.
 */
class PluginUpdater
{
    public const MANIFEST_CACHE_KEY_PREFIX = 'wp_premium_sdk_manifest_';

    /** Suffix of the site transient that remembers failed manifest fetches. */
    public const FAILURE_CACHE_KEY_SUFFIX = '_failure';

    /** How long a fetched manifest is reused. */
    public const SUCCESS_TTL = 12 * 3600;

    /**
     * Wait after the 1st, 2nd, 3rd and 4th+ failure in a row before asking again:
     * 1h, 3h, 6h, then 12h at most.
     */
    public const FAILURE_BACKOFF = [3600, 3 * 3600, 6 * 3600, 12 * 3600];

    /**
     * How long the failure record itself lives. Longer than the longest wait, so the
     * count survives between attempts and the wait keeps growing; a day with no
     * attempt at all starts the count again.
     */
    public const FAILURE_RECORD_TTL = 24 * 3600;

    private ClientConfig $config;
    private LicenseClient $client;
    private LicenseManager $license;
    private string $pluginBasename;

    public function __construct(ClientConfig $config, LicenseClient $client, LicenseManager $license, string $pluginBasename)
    {
        $this->config = $config;
        $this->client = $client;
        $this->license = $license;
        $this->pluginBasename = $pluginBasename;
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectPluginUpdate']);
    }

    /**
     * @param  object|false  $transient  The site transient value
     * @return object|false
     */
    public function injectPluginUpdate($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        // Don't offer an update to a site whose license isn't currently valid
        // (deactivated/expired) — fetchManifest() already short-circuits on an
        // invalid license, but a stale manifest cache could still be injected.
        if (! $this->license->isValid()) {
            return $transient;
        }

        $manifest = $this->fetchManifest();

        if (! $manifest || empty($manifest['update_available']) || empty($manifest['manifest']['plugin'])) {
            return $transient;
        }

        $payload = $manifest['manifest'];
        $plugin = $payload['plugin'];

        $transient->response = $transient->response ?? [];
        $transient->response[$this->pluginBasename] = (object) [
            'slug' => dirname($this->pluginBasename),
            'plugin' => $this->pluginBasename,
            'new_version' => $payload['version'],
            'url' => '',
            'package' => $plugin['url'] ?? '',
            'tested' => $payload['tested'] ?? '',
            'requires' => $payload['requires'] ?? '',
            'requires_php' => $payload['requires_php'] ?? '',
        ];

        return $transient;
    }

    /**
     * Fetch the manifest, using a transient cache to avoid hammering Nexus.
     *
     * A success is reused for 12 hours. A failure is remembered too, so the next
     * update check does not repeat it straight away: the site waits 1h, then 3h,
     * 6h and at most 12h between attempts while the failures continue (longer if
     * the server sent a longer Retry-After). `$force` (the "check for updates"
     * button) skips both waits but still records the outcome.
     *
     * @return array<string, mixed>|null
     */
    public function fetchManifest(bool $force = false): ?array
    {
        $cacheKey = $this->cacheKey();

        if (! $force) {
            $cached = get_site_transient($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }

            $failure = get_site_transient($this->failureCacheKey());

            if (is_array($failure) && (int) ($failure['retry_at'] ?? 0) > time()) {
                return null;
            }
        }

        if (! $this->license->isValid()) {
            return null;
        }

        $licenseKey = $this->license->getLicenseKey();

        if (! $licenseKey) {
            return null;
        }

        try {
            $manifest = $this->client->fetchManifest($licenseKey, $this->config->currentVersion());
        } catch (Exception $e) {
            $this->recordFailure($e);

            return null;
        }

        set_site_transient($cacheKey, $manifest, self::SUCCESS_TTL);
        delete_site_transient($this->failureCacheKey());

        return $manifest;
    }

    /**
     * Forget the cached manifest and any failure backoff — the license changed,
     * so the next check should ask straight away.
     */
    public function flush(): void
    {
        delete_site_transient($this->cacheKey());
        delete_site_transient($this->failureCacheKey());
    }

    /**
     * The site transient keys this updater writes, for uninstall.
     *
     * @return array<int, string>
     */
    public function cacheKeys(): array
    {
        return [$this->cacheKey(), $this->failureCacheKey()];
    }

    private function recordFailure(Exception $e): void
    {
        $previous = get_site_transient($this->failureCacheKey());
        $failures = (is_array($previous) ? (int) ($previous['failures'] ?? 0) : 0) + 1;

        $wait = self::FAILURE_BACKOFF[min($failures, count(self::FAILURE_BACKOFF)) - 1];

        if ($e instanceof ApiException && $e->getRetryAfter() !== null) {
            $wait = min(max($wait, $e->getRetryAfter()), self::FAILURE_RECORD_TTL);
        }

        set_site_transient($this->failureCacheKey(), [
            'failures' => $failures,
            'retry_at' => time() + $wait,
            'error_code' => $e instanceof ApiException ? $e->getErrorCode() : '',
        ], self::FAILURE_RECORD_TTL);
    }

    private function cacheKey(): string
    {
        return self::MANIFEST_CACHE_KEY_PREFIX.$this->config->productSlug();
    }

    private function failureCacheKey(): string
    {
        return $this->cacheKey().self::FAILURE_CACHE_KEY_SUFFIX;
    }
}
