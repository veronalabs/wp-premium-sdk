<?php

namespace VeronaLabs\WpPremiumSdk\Store;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

/**
 * Single-option storage for SDK state, split into named sections.
 *
 * All plugin data lives in one wp_options row (keyed by ClientConfig::optionKey())
 * with sections "license" and "account". A short-lived transient handles OAuth
 * CSRF state tokens. Loaded once per request and memoized.
 */
class PremiumStore
{
    private const OAUTH_STATE_TTL = 600;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private ClientConfig $config) {}

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
        $all[$section] = $data;
        $this->save($all);
    }

    public function delete(string $section): void
    {
        $all = $this->load();
        unset($all[$section]);

        if (empty($all)) {
            delete_option($this->config->optionKey());
            $this->cache = [];

            return;
        }

        $this->save($all);
    }

    public function clear(): void
    {
        delete_option($this->config->optionKey());
        $this->cache = [];
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
