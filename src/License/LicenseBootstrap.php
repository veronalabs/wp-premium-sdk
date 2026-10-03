<?php

namespace VeronaLabs\WpPremiumSdk\License;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * Registers the license AJAX endpoints, the plugin-updater filter, the automatic
 * activation with a wp-config or network key, and the Network Admin endpoints.
 */
class LicenseBootstrap
{
    private ClientConfig $config;
    private LicenseEndpoints $endpoints;
    private PluginUpdater $updater;
    private ?AutoActivator $autoActivator;
    private ?NetworkLicenseEndpoints $networkEndpoints;

    public function __construct(
        ClientConfig $config,
        LicenseEndpoints $endpoints,
        PluginUpdater $updater,
        ?AutoActivator $autoActivator = null,
        ?NetworkLicenseEndpoints $networkEndpoints = null
    ) {
        $this->config = $config;
        $this->endpoints = $endpoints;
        $this->updater = $updater;
        $this->autoActivator = $autoActivator;
        $this->networkEndpoints = $networkEndpoints;
    }

    public function register(): void
    {
        $this->endpoints->register();
        $this->updater->register();

        if ($this->autoActivator !== null) {
            $this->autoActivator->register();
        }

        if ($this->networkEndpoints !== null && is_multisite()) {
            $this->networkEndpoints->register();
        }
    }
}
