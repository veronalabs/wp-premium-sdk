<?php

namespace VeronaLabs\WpPremiumSdk\License;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * Registers the license AJAX endpoints and plugin-updater filter.
 */
class LicenseBootstrap
{
    public function __construct(
        private ClientConfig $config,
        private LicenseEndpoints $endpoints,
        private PluginUpdater $updater,
    ) {}

    public function register(): void
    {
        $this->endpoints->register();
        $this->updater->register();
    }
}
