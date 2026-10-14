<?php

namespace VeronaLabs\WpPremiumSdk\Update;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\License\KeySource;
use VeronaLabs\WpPremiumSdk\License\LicenseActionException;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * Bridges Nexus' unified manifest into WordPress' native plugin update flow.
 *
 * Hooks `pre_set_site_transient_update_plugins` so "check for updates" in
 * wp-admin surfaces the plugin update returned by /api/v1/{product}/update/manifest.
 * Also exposes the manifest itself so the admin UI can drive module updates.
 *
 * On a network-activated plugin the update check is network-wide, like the plugin
 * files and the update transient: it uses the network key and counts as licensed
 * when the main site's activation is valid, whichever subsite triggers it — so a
 * subsite without a seat cannot make the update disappear for the whole network.
 *
 * The cached manifest belongs to the key that fetched it, and to the subsite when
 * the plugin is activated site by site: another key or another subsite asks Nexus
 * for its own. The `wp_premium_sdk/update_allowed` filter lets the host hold an
 * update back, and a site that is not licensed (or held back) gets its offer
 * withdrawn on the next update-check pass, as is an offer for a version that is
 * already installed. `plugins_api` answers "View details" for this plugin's slug
 * itself (from the cached manifest, else the plugin header), so WordPress never
 * asks wordpress.org about it. The cached manifest is dropped once WordPress
 * upgrades this plugin.
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
    private ?KeySource $keySource;

    public function __construct(ClientConfig $config, LicenseClient $client, LicenseManager $license, string $pluginBasename, ?KeySource $keySource = null)
    {
        $this->config = $config;
        $this->client = $client;
        $this->license = $license;
        $this->pluginBasename = $pluginBasename;
        $this->keySource = $keySource;
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectPluginUpdate']);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_action('wp_premium_sdk/license_changed', [$this, 'onLicenseChanged'], 10, 1);
        add_action('upgrader_process_complete', [$this, 'onUpgraderProcessComplete'], 10, 2);
    }

    /**
     * Offer the update from the manifest, or withdraw an offer made earlier.
     *
     * WordPress runs this filter over the transient it already holds before it asks
     * wordpress.org, so an offer made while the license was valid must be taken
     * back here: when this install is not licensed for updates, the host's
     * `wp_premium_sdk/update_allowed` filter says no, Nexus says there is no
     * update, or the manifest's version is already installed (a manifest cached
     * before an upgrade), the plugin's row moves from `response` to `no_update`.
     * A failed fetch leaves the transient as it was.
     *
     * @param  object|false  $transient  The site transient value
     * @return object|false
     */
    public function injectPluginUpdate($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        // Don't offer an update to a site whose license isn't currently valid
        // (deactivated/expired/revoked); take back one offered before.
        if ($this->updateKey() === null) {
            return $this->withdrawUpdate($transient);
        }

        $manifest = $this->fetchManifest();

        if (! $manifest) {
            return $transient;
        }

        if (empty($manifest['update_available']) || empty($manifest['manifest']['plugin'])) {
            return $this->withdrawUpdate($transient);
        }

        // The cached answer can predate an upgrade: never offer what is installed.
        if (version_compare($this->config->currentVersion(), (string) ($manifest['manifest']['version'] ?? ''), '>=')) {
            return $this->withdrawUpdate($transient);
        }

        /**
         * Whether to offer the update in the manifest. Return false to hold it back
         * (the host's own entitlement rules, a tier it won't install, ...); an
         * update already offered is withdrawn.
         *
         * @param  bool  $allowed  True by default.
         * @param  string  $productSlug  The product the manifest is for.
         * @param  array<string, mixed>  $manifest  The manifest from Nexus.
         */
        if (! apply_filters('wp_premium_sdk/update_allowed', true, $this->config->productSlug(), $manifest)) {
            return $this->withdrawUpdate($transient);
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

        if (isset($transient->no_update) && is_array($transient->no_update)) {
            unset($transient->no_update[$this->pluginBasename]);
        }

        return $transient;
    }

    /**
     * Answer `plugins_api('plugin_information')` for this plugin — the "View
     * details" modal — so WordPress never asks wordpress.org about the slug (the
     * premium slug is not there, or is someone else's plugin).
     *
     * With a manifest cached for the key updates run on, the answer describes the
     * manifest's version (requirements, changelog) and carries its package as
     * `download_link` when an update is available, so the modal offers "Install
     * Update Now". Otherwise (nothing cached, not licensed) it describes the
     * installed version from the plugin header, without a download link. Never
     * fetches. `external` keeps the modal from linking to wordpress.org.
     *
     * @param  false|object|array<string, mixed>  $result
     * @param  string  $action
     * @param  object|array<string, mixed>  $args
     * @return false|object|array<string, mixed>
     */
    public function pluginInformation($result, $action = '', $args = null)
    {
        $slug = is_object($args) ? ($args->slug ?? '') : (is_array($args) ? ($args['slug'] ?? '') : '');

        if ($action !== 'plugin_information' || $slug !== dirname($this->pluginBasename)) {
            return $result;
        }

        $manifest = $this->cachedManifest();
        $payload = is_array($manifest['manifest'] ?? null) ? $manifest['manifest'] : [];

        if (empty($payload['version'])) {
            $payload = [];
        }

        $header = [];

        if (function_exists('get_plugins')) {
            $header = get_plugins()[$this->pluginBasename] ?? [];
        }

        $sections = [];

        if (! empty($payload['changelog'])) {
            $sections['changelog'] = nl2br(esc_html((string) $payload['changelog']));
        } elseif (! empty($header['Description'])) {
            $sections['description'] = esc_html((string) $header['Description']);
        }

        $version = (string) ($payload['version'] ?? '');

        if ($version === '') {
            $version = (string) ($header['Version'] ?? '');
        }

        if ($version === '') {
            $version = $this->config->currentVersion();
        }

        $info = [
            'name' => (string) ($header['Name'] ?? $payload['plugin']['name'] ?? $slug),
            'slug' => $slug,
            'version' => $version,
            'author' => (string) ($header['Author'] ?? ''),
            'homepage' => (string) ($header['PluginURI'] ?? ''),
            'requires' => (string) ($payload['requires'] ?? $header['RequiresWP'] ?? ''),
            'requires_php' => (string) ($payload['requires_php'] ?? $header['RequiresPHP'] ?? ''),
            'tested' => (string) ($payload['tested'] ?? ''),
            'last_updated' => (string) ($payload['released_at'] ?? ''),
            'sections' => $sections,
            'external' => true,
        ];

        $package = (string) ($payload['plugin']['url'] ?? '');

        if ($package !== '' && ! empty($manifest['update_available'])
            && version_compare($this->config->currentVersion(), $version, '<')) {
            $info['download_link'] = $package;
        }

        return (object) $info;
    }

    /**
     * Listener for `wp_premium_sdk/license_changed`: the license this product's
     * updates depend on changed, so drop the cached manifest and any backoff.
     */
    public function onLicenseChanged(string $productSlug): void
    {
        if ($productSlug === $this->config->productSlug()) {
            $this->flush();
        }
    }

    /**
     * Listener for `upgrader_process_complete`: when WordPress has just updated
     * (or replaced) this plugin, drop the cached manifest, which still describes
     * the version that was installed before and would offer it again.
     *
     * @param  mixed  $upgrader
     * @param  mixed  $hookExtra
     */
    public function onUpgraderProcessComplete($upgrader, $hookExtra = []): void
    {
        if (! is_array($hookExtra) || ($hookExtra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = (array) ($hookExtra['plugins'] ?? []);

        if (isset($hookExtra['plugin'])) {
            $plugins[] = $hookExtra['plugin'];
        }

        $ours = in_array($this->pluginBasename, $plugins, true);

        // "Replace current with uploaded" is an install over the same folder.
        if (! $ours && ($hookExtra['action'] ?? '') === 'install' && is_object($upgrader)) {
            $result = $upgrader->result ?? null;
            $ours = is_array($result) && ($result['destination_name'] ?? '') === dirname($this->pluginBasename);
        }

        if ($ours) {
            delete_site_transient($this->cacheKey());
        }
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
            $cached = $this->cachedManifest();

            if ($cached !== null) {
                return $cached;
            }

            $failure = get_site_transient($this->failureCacheKey());

            if (is_array($failure) && (int) ($failure['retry_at'] ?? 0) > time()) {
                return null;
            }
        }

        $licenseKey = $this->updateKey();

        if (! $licenseKey) {
            return null;
        }

        try {
            $manifest = $this->client->fetchManifest($licenseKey, $this->config->currentVersion(), $this->updateDomain());
        } catch (Exception $e) {
            $this->recordFailure($e);

            return null;
        }

        set_site_transient($cacheKey, ['key_hash' => self::keyHash($licenseKey), 'manifest' => $manifest], self::SUCCESS_TTL);
        delete_site_transient($this->failureCacheKey());

        return $manifest;
    }

    /**
     * The manifest from the last successful fetch, without asking Nexus. Null when
     * none is cached, or when the cached one was fetched with another key than the
     * one updates now run on (or with none valid).
     *
     * @return array<string, mixed>|null
     */
    public function cachedManifest(): ?array
    {
        $cached = get_site_transient($this->cacheKey());
        $licenseKey = $this->updateKey();

        if (! is_array($cached) || ! is_array($cached['manifest'] ?? null) || $licenseKey === null) {
            return null;
        }

        return hash_equals((string) ($cached['key_hash'] ?? ''), self::keyHash($licenseKey)) ? $cached['manifest'] : null;
    }

    /**
     * Ask Nexus for the licensed package as it stands, whatever version is
     * installed: the request leaves out `current_version`, so Nexus answers with the
     * full manifest (package URL included) even when the version is current. Used to
     * install the licensed tier's build over another tier of the same version.
     *
     * Nothing is cached — the answer does not describe the installed version — but
     * the outcome is recorded like any check: a failure feeds the backoff, a success
     * clears it. A wait the server asked for (rate limited, Retry-After) is honoured;
     * the other backoff waits are not, since a person asked for this.
     *
     * @throws ApiException When Nexus refuses or cannot be reached, or is still
     *                      rate limiting this site (`rate_limited`, with retry_after).
     * @throws LicenseActionException When no valid license is stored.
     *
     * @return array<string, mixed>
     */
    public function fetchPackageManifest(): array
    {
        $licenseKey = $this->updateKey();

        if (! $licenseKey) {
            throw new LicenseActionException(LicenseErrorCode::NOT_ACTIVATED, 'No valid license is activated on this site.');
        }

        $wait = $this->rateLimitedFor();

        if ($wait !== null) {
            throw new ApiException('Too many requests.', LicenseErrorCode::RATE_LIMITED, [], 429, null, $wait);
        }

        try {
            $manifest = $this->client->fetchManifest($licenseKey, null, $this->updateDomain());
        } catch (Exception $e) {
            $this->recordFailure($e);

            throw $e;
        }

        delete_site_transient($this->failureCacheKey());

        return $manifest;
    }

    /**
     * Seconds left before Nexus wants to hear from this site again, when the last
     * failure was a rate limit. Null otherwise.
     */
    public function rateLimitedFor(): ?int
    {
        $failure = get_site_transient($this->failureCacheKey());

        if (! is_array($failure) || ($failure['error_code'] ?? '') !== LicenseErrorCode::RATE_LIMITED) {
            return null;
        }

        $left = (int) ($failure['retry_at'] ?? 0) - time();

        return $left > 0 ? $left : null;
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

    /**
     * The key update checks run on, or null when this install is not licensed for
     * updates. Network-activated: the network (or wp-config) key, when the main
     * site's activation is valid. Otherwise: this site's key, when it is valid.
     */
    private function updateKey(): ?string
    {
        if ($this->isNetworkWide()) {
            $provided = $this->keySource->provided();

            if ($provided === null || ! LicenseManager::isValidLicenseData($this->keySource->network()->mainSiteLicense())) {
                return null;
            }

            return $provided['key'];
        }

        return $this->license->isValid() ? $this->license->getLicenseKey() : null;
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

    /**
     * Take this plugin's update offer out of `response`, leaving a `no_update`
     * entry so the row reads "up to date" instead of offering a package.
     *
     * @param  object  $transient
     * @return object
     */
    private function withdrawUpdate($transient)
    {
        if (isset($transient->response) && is_array($transient->response)) {
            unset($transient->response[$this->pluginBasename]);
        }

        $transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
        $transient->no_update[$this->pluginBasename] = (object) [
            'slug' => dirname($this->pluginBasename),
            'plugin' => $this->pluginBasename,
            'new_version' => $this->config->currentVersion(),
            'url' => '',
            'package' => '',
        ];

        return $transient;
    }

    /**
     * What the cache remembers about the key a manifest was fetched with: enough to
     * tell keys apart, never the key itself.
     */
    private static function keyHash(string $licenseKey): string
    {
        return hash('sha256', $licenseKey);
    }

    /**
     * `wp_premium_sdk_manifest_{product_slug}`, plus `_{blog_id}` on a multisite
     * where the plugin is activated site by site (each subsite has its own key and
     * answer). A network-activated plugin shares one network-wide entry, like its
     * update check.
     */
    private function cacheKey(): string
    {
        $key = self::MANIFEST_CACHE_KEY_PREFIX.$this->config->productSlug();

        if (is_multisite() && ! $this->isNetworkWide()) {
            $key .= '_'.get_current_blog_id();
        }

        return $key;
    }

    /**
     * The domain the manifest request names, so Nexus checks the seat the update
     * key stands for: this site's own, or on a network-wide check the main site's
     * (its activated domain, else its home address), since that is the activation
     * the network key's updates rely on. Null lets the client use this site's.
     */
    private function updateDomain(): ?string
    {
        if (! $this->isNetworkWide()) {
            return null;
        }

        $mainLicense = $this->keySource->network()->mainSiteLicense();
        $activated = is_array($mainLicense) ? (string) ($mainLicense['activated_domain'] ?? '') : '';

        return $activated !== '' ? $activated : Request::normaliseDomain(get_home_url((int) get_main_site_id()));
    }

    /**
     * Whether updates run network-wide on the network key (see updateKey()).
     */
    private function isNetworkWide(): bool
    {
        return $this->keySource !== null && $this->keySource->isNetworkManaged() && $this->keySource->network() !== null;
    }

    private function failureCacheKey(): string
    {
        return $this->cacheKey().self::FAILURE_CACHE_KEY_SUFFIX;
    }
}
