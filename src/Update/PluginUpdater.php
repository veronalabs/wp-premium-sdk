<?php

namespace VeronaLabs\WpPremiumSdk\Update;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
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
     * Fetch the manifest, using a short-lived transient cache to avoid hammering Nexus.
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
            return null;
        }

        set_site_transient($cacheKey, $manifest, HOUR_IN_SECONDS * 12);

        return $manifest;
    }

    public function flush(): void
    {
        delete_site_transient($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return self::MANIFEST_CACHE_KEY_PREFIX.$this->config->productSlug();
    }
}
