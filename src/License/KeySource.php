<?php

namespace VeronaLabs\WpPremiumSdk\License;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

/**
 * Where this site's license key comes from, and whether the license page may
 * change it.
 *
 * - `constant`: a wp-config constant named by ClientConfig `license_key_constant`.
 *   Wins over everything else, since wp-config is the site owner's own file.
 * - `network`: the plugin is network-activated, so the network admin enters the
 *   key once in Network Admin and every subsite uses it.
 * - `manual`: entered on this site's license page.
 *
 * Never logs or returns the key anywhere but to the caller that activates it.
 */
class KeySource
{
    public const CONSTANT = 'constant';

    public const NETWORK = 'network';

    public const MANUAL = 'manual';

    private ClientConfig $config;

    private ?NetworkLicense $network;

    public function __construct(ClientConfig $config, ?NetworkLicense $network = null)
    {
        $this->config = $config;
        $this->network = $network;
    }

    /**
     * The key set in wp-config, or null when the plugin names no constant or it is
     * not defined (or empty).
     */
    public function constantKey(): ?string
    {
        $name = $this->config->licenseKeyConstant();

        if ($name === '' || ! defined($name)) {
            return null;
        }

        $value = constant($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * The key this site should be activated with without anyone visiting the license
     * page, with where it came from — or null when there is none.
     *
     * @return array{key: string, source: string}|null
     */
    public function provided(): ?array
    {
        $constant = $this->constantKey();

        if ($constant !== null) {
            return ['key' => $constant, 'source' => self::CONSTANT];
        }

        if ($this->isNetworkContext()) {
            $networkKey = $this->network->getKey();

            if ($networkKey !== null) {
                return ['key' => $networkKey, 'source' => self::NETWORK];
            }
        }

        return null;
    }

    /**
     * Who manages this site's key when it is not this site's license page:
     * `constant`, `network`, or null when the license page does.
     *
     * A network-activated plugin is network-managed even before the network admin
     * enters a key: a subsite has nothing to enter.
     */
    public function managedBy(): ?string
    {
        if ($this->constantKey() !== null) {
            return self::CONSTANT;
        }

        return $this->isNetworkContext() ? self::NETWORK : null;
    }

    /**
     * The error code that refuses a change on the license page for a managed key,
     * or null when the page may change it.
     */
    public function refusalCode(): ?string
    {
        switch ($this->managedBy()) {
            case self::CONSTANT:
                return LicenseErrorCode::KEY_FROM_CONSTANT;
            case self::NETWORK:
                return LicenseErrorCode::NETWORK_MANAGED;
            default:
                return null;
        }
    }

    /**
     * `network` when the plugin is network-activated (one key for the network),
     * `site` otherwise.
     */
    public function context(): string
    {
        return $this->isNetworkContext() ? 'network' : 'site';
    }

    /**
     * Whether a subsite only views its license: the plugin is network-activated, so
     * the network admin manages the key.
     */
    public function isNetworkManaged(): bool
    {
        return $this->isNetworkContext();
    }

    public function network(): ?NetworkLicense
    {
        return $this->network;
    }

    private function isNetworkContext(): bool
    {
        return $this->network !== null && $this->network->isNetworkActivated();
    }
}
