<?php

namespace VeronaLabs\WpPremiumSdk\Config;

use InvalidArgumentException;

/**
 * Immutable configuration for a plugin's SDK instance.
 *
 * Each plugin builds one of these at boot and passes it to PremiumServiceProvider.
 * Every generic class in the SDK pulls its plugin-specific values (product slug,
 * option keys, API URL, OAuth callback query params, text domain) from here.
 */
final class ClientConfig
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array{
     *     product_slug: string,
     *     option_key: string,
     *     oauth_state_prefix: string,
     *     oauth_callback_params: array{code: string, state: string},
     *     api_base_url: string,
     *     text_domain: string,
     *     current_version: string,
     *     ajax_action?: string,
     *     modules_path?: string,
     * } $data
     */
    public function __construct(array $data)
    {
        foreach (['product_slug', 'option_key', 'oauth_state_prefix', 'oauth_callback_params', 'api_base_url', 'text_domain', 'current_version'] as $required) {
            if (! isset($data[$required])) {
                throw new InvalidArgumentException("ClientConfig missing required key: {$required}");
            }
        }

        if (! isset($data['oauth_callback_params']['code'], $data['oauth_callback_params']['state'])) {
            throw new InvalidArgumentException('ClientConfig.oauth_callback_params must include "code" and "state" keys.');
        }

        $data['ajax_action'] = $data['ajax_action'] ?? $data['product_slug'];
        $data['modules_path'] = $data['modules_path'] ?? '';
        $data['nonce_action'] = $data['nonce_action'] ?? '';
        $data['nonce_param'] = $data['nonce_param'] ?? 'nonce';

        $this->data = $data;
    }

    public function productSlug(): string
    {
        return $this->data['product_slug'];
    }

    public function optionKey(): string
    {
        return $this->data['option_key'];
    }

    public function oauthStatePrefix(): string
    {
        return $this->data['oauth_state_prefix'];
    }

    /** @return array{code: string, state: string} */
    public function oauthCallbackParams(): array
    {
        return $this->data['oauth_callback_params'];
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->data['api_base_url'], '/');
    }

    public function textDomain(): string
    {
        return $this->data['text_domain'];
    }

    public function currentVersion(): string
    {
        return $this->data['current_version'];
    }

    /**
     * Prefix used for AJAX actions (e.g. "wp_statistics_license" → "{prefix}_license").
     */
    public function ajaxAction(): string
    {
        return $this->data['ajax_action'];
    }

    /**
     * Absolute path to the plugin's modules directory (pro/modules/).
     */
    public function modulesPath(): string
    {
        return $this->data['modules_path'];
    }

    /**
     * Nonce action passed to wp_create_nonce/check_ajax_referer. When empty,
     * each endpoint derives its own per-endpoint action from ajaxAction() +
     * action name. Plugins that share one nonce across endpoints (e.g. a
     * dashboard-wide nonce) should set this explicitly.
     */
    public function nonceAction(): string
    {
        return $this->data['nonce_action'];
    }

    /**
     * POST/GET parameter name the AJAX client sends the nonce under.
     * Defaults to "nonce" — override when the plugin's React client uses
     * a different key (e.g. "wp_statistics_nonce").
     */
    public function nonceParam(): string
    {
        return $this->data['nonce_param'];
    }
}
