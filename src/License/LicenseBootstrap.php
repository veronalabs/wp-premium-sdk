<?php

namespace VeronaLabs\WpPremiumSdk\License;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * Registers the license AJAX endpoints and plugin-updater filter.
 */
class LicenseBootstrap
{
    private ClientConfig $config;
    private LicenseEndpoints $endpoints;
    private PluginUpdater $updater;

    public function __construct(ClientConfig $config, LicenseEndpoints $endpoints, PluginUpdater $updater)
    {
        $this->config = $config;
        $this->endpoints = $endpoints;
        $this->updater = $updater;
    }

    public function register(): void
    {
        $this->endpoints->register();
        $this->updater->register();
    }
}
