<?php

namespace VeronaLabs\WpPremiumSdk\Store;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

/**
 * Single-option storage for SDK state, split into named sections.
 *
 * All plugin data lives in one wp_options row (keyed by ClientConfig::optionKey())
 * with sections "license" and "account". A short-lived transient handles OAuth
 * CSRF state tokens. Loaded once per request and memoized.
 *
 * Every write of the license section goes through here, so this is where a change
 * to what the site is entitled to is noticed: when the key (activation), status,
 * expiry, tier, features or seat counts differ, the `wp_premium_sdk/license_changed`
 * action fires with key-free snapshots. A refresh that changes nothing stays silent.
 */
class PremiumStore
{
    private const OAUTH_STATE_TTL = 600;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    private ClientConfig $config;

    public function __construct(ClientConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<string, mixed>|null Null if the section is empty/absent.
     */
    public function get(string $section): ?array
    {
        $data = $this->load()[$section] ?? [];

        return ! empty($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function set(string $section, array $data): void
    {
        $all = $this->load();
        $before = $all['license'] ?? null;
        $all[$section] = $data;
        $this->save($all);
        $this->announceLicenseChange($before);
    }

    public function delete(string $section): void
    {
        $all = $this->load();
        $before = $all['license'] ?? null;
        unset($all[$section]);

        if (empty($all)) {
            delete_option($this->config->optionKey());
            $this->cache = [];
            $this->announceLicenseChange($before);

            return;
        }

        $this->save($all);
        $this->announceLicenseChange($before);
    }

    public function clear(): void
    {
        $before = $this->load()['license'] ?? null;
        delete_option($this->config->optionKey());
        $this->cache = [];
        $this->announceLicenseChange($before);
    }

    /**
     * What entitlement depends on in a stored license section, without the key:
     * `license_id` and `activated_at` (a new key or activation), `status`,
     * `expires_at`, `tier_slug`, `features`, `max_activations` and
     * `activation_count`. Null when there is no license.
     *
     * @param  mixed  $license
     * @return array<string, mixed>|null
     */
    public static function licenseSnapshot($license): ?array
    {
        if (! is_array($license) || empty($license)) {
            return null;
        }

        $features = array_values(array_filter((array) ($license['features'] ?? []), 'is_string'));
        sort($features);

        return [
            'license_id' => $license['license_id'] ?? null,
            'activated_at' => $license['activated_at'] ?? null,
            'status' => (string) ($license['status'] ?? ''),
            'expires_at' => (string) ($license['expires_at'] ?? ''),
            'tier_slug' => (string) ($license['tier_slug'] ?? ''),
            'features' => $features,
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'activation_count' => (int) ($license['activation_count'] ?? 0),
        ];
    }

    /**
     * Fire `wp_premium_sdk/license_changed` when the license section now differs
     * from `$before` on anything entitlement depends on.
     *
     * @param  mixed  $before  The license section before the write.
     */
    private function announceLicenseChange($before): void
    {
        $old = self::licenseSnapshot($before);
        $new = self::licenseSnapshot($this->load()['license'] ?? null);

        if ($old === $new) {
            return;
        }

        /**
         * The stored license changed in a way that affects what this site is
         * entitled to (activated, deactivated, renewed, expired, refused, tier or
         * seats changed). The SDK flushes its manifest cache here; hosts can clear
         * their own caches. Snapshots never contain the key.
         *
         * @param  string  $productSlug
         * @param  array<string, mixed>|null  $old  Null when there was no license.
         * @param  array<string, mixed>|null  $new  Null when the license was removed.
         */
        do_action('wp_premium_sdk/license_changed', $this->config->productSlug(), $old, $new);
    }

    public function setOAuthState(string $state): void
    {
        set_transient($this->config->oauthStatePrefix().$state, true, self::OAUTH_STATE_TTL);
    }

    /**
     * Verify and consume a one-time OAuth CSRF state token.
     */
    public function verifyOAuthState(string $state): bool
    {
        $key = $this->config->oauthStatePrefix().$state;

        if (! get_transient($key)) {
            return false;
        }

        delete_transient($key);

        return true;
    }

    public function resetCache(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $raw = get_option($this->config->optionKey(), []);

        return $this->cache = is_array($raw) ? $raw : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $data
     */
    private function save(array $data): void
    {
        update_option($this->config->optionKey(), $data, false);
        $this->cache = $data;
    }
}
