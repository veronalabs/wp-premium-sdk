<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;

/**
 * Registers the account AJAX endpoints and catches the OAuth callback on
 * admin page load. Callback detection looks for the plugin's configured
 * code/state query params (e.g. wps_oauth_code/wps_oauth_state).
 */
class AccountBootstrap
{
    private ClientConfig $config;
    private AccountManager $manager;
    private LicenseManager $licenseManager;
    private AccountEndpoints $endpoints;

    public function __construct(ClientConfig $config, AccountManager $manager, LicenseManager $licenseManager, AccountEndpoints $endpoints)
    {
        $this->config = $config;
        $this->manager = $manager;
        $this->licenseManager = $licenseManager;
        $this->endpoints = $endpoints;
    }

    public function register(): void
    {
        $this->endpoints->register();
        add_action('admin_init', [$this, 'handleOAuthCallback']);
    }

    public function handleOAuthCallback(): void
    {
        if (! is_admin()) {
            return;
        }

        $params = $this->config->oauthCallbackParams();
        $codeKey = $params['code'];
        $stateKey = $params['state'];

        if (! isset($_GET[$codeKey], $_GET[$stateKey])) {
            return;
        }

        $code = sanitize_text_field(wp_unslash($_GET[$codeKey]));
        $state = sanitize_text_field(wp_unslash($_GET[$stateKey]));

        try {
            $this->manager->handleOAuthCallback($code, $state, $this->licenseManager);
        } catch (Exception $e) {
            $this->manager->setFlashError($e->getMessage());
        }

        // Redirect to a clean URL so the code/state don't linger.
        wp_safe_redirect(remove_query_arg([$codeKey, $stateKey]));
        exit;
    }
}
