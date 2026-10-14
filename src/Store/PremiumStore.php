<?php

namespace VeronaLabs\WpPremiumSdk\Store;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

/**
 * Single-option storage for SDK state, split into named sections.
 *
 * All plugin data lives in one wp_options row (keyed by ClientConfig::optionKey())
 * with sections "license" and "account". A short-lived transient handles OAuth
 * CSRF state tokens and the PKCE code verifier kept with each. Loaded once per
 * request and memoized.
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
     * `expires_at`, `tier_slug`, `features`, `max_activations`, this site's
     * standing (`site.active`, `site.is_counted`; null when Nexus did not say) and
     * `error_code` (a refusal such as a domain not allowed). Nothing that moves
     * when other sites activate or leave (`activation_count`, `sites`), so another
     * site's activation does not flush this site's caches. Null when there is no
     * license.
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
        $site = is_array($license['site'] ?? null) ? $license['site'] : [];
        sort($features);

        return [
            'license_id' => $license['license_id'] ?? null,
            'activated_at' => $license['activated_at'] ?? null,
            'status' => (string) ($license['status'] ?? ''),
            'expires_at' => (string) ($license['expires_at'] ?? ''),
            'tier_slug' => (string) ($license['tier_slug'] ?? ''),
            'features' => $features,
            'max_activations' => (int) ($license['max_activations'] ?? 0),
            'site' => [
                'active' => self::nullableBool($site['active'] ?? null),
                'is_counted' => self::nullableBool($site['is_counted'] ?? null),
            ],
            'error_code' => (string) ($license['error_code'] ?? ''),
        ];
    }

    /**
     * @param  mixed  $value
     */
    private static function nullableBool($value): ?bool
    {
        return $value === null ? null : (bool) $value;
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

    /**
     * Keep a one-time OAuth CSRF state token for 10 minutes, with the PKCE code
     * verifier of the sign-in it belongs to. Both live only on this server.
     */
    public function setOAuthState(string $state, string $codeVerifier = ''): void
    {
        set_transient($this->config->oauthStatePrefix().$state, ['code_verifier' => $codeVerifier], self::OAUTH_STATE_TTL);
    }

    /**
     * Verify and consume a one-time OAuth CSRF state token.
     */
    public function verifyOAuthState(string $state): bool
    {
        return $this->consumeOAuthState($state) !== null;
    }

    /**
     * Verify and consume a one-time OAuth CSRF state token, returning the PKCE code
     * verifier kept with it: null when the state is unknown or expired, and an empty
     * string for a state kept without one (started before PKCE). The state and its
     * verifier are deleted either way, so neither can be used twice.
     */
    public function consumeOAuthState(string $state): ?string
    {
        $key = $this->config->oauthStatePrefix().$state;
        $stored = get_transient($key);

        if (! $stored) {
            return null;
        }

        delete_transient($key);

        return is_array($stored) && is_string($stored['code_verifier'] ?? null) ? $stored['code_verifier'] : '';
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
