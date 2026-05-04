<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;

/**
 * HTTP client for Nexus license + unified manifest endpoints.
 *
 * Stateless — all calls return decoded JSON; throw on HTTP/API errors.
 * Product slug, base URL, and text domain come from ClientConfig.
 */
class LicenseClient
{
    private ClientConfig $config;
    private ApiClient $http;

    public function __construct(ClientConfig $config, ApiClient $http)
    {
        $this->config = $config;
        $this->http = $http;
    }

    /**
     * @throws Exception
     */
    public function activate(string $licenseKey, string $domain, string $siteUrl): array
    {
        return $this->http->post('/api/v1/license/activate', [
            'license_key' => $licenseKey,
            'product_slug' => $this->config->productSlug(),
            'domain' => $domain,
            'site_url' => $siteUrl,
        ]);
    }

    /**
     * @throws Exception
     */
    public function deactivate(string $licenseKey, string $domain): array
    {
        return $this->http->post('/api/v1/license/deactivate', [
            'license_key' => $licenseKey,
            'product_slug' => $this->config->productSlug(),
            'domain' => $domain,
        ]);
    }

    /**
     * @throws Exception
     */
    public function validate(string $licenseKey, string $domain): array
    {
        return $this->http->post('/api/v1/license/validate', [
            'license_key' => $licenseKey,
            'product_slug' => $this->config->productSlug(),
            'domain' => $domain,
        ]);
    }

    /**
     * Fetch the unified update manifest (plugin + every licensed module at one version).
     *
     * @throws Exception
     */
    public function fetchManifest(string $licenseKey, ?string $currentVersion = null): array
    {
        $query = ['license_key' => $licenseKey];

        if ($currentVersion !== null) {
            $query['current_version'] = $currentVersion;
        }

        return $this->http->get(
            '/api/v1/'.$this->config->productSlug().'/update/manifest',
            $query
        );
    }

    /**
     * Fetch the manifest using a Sanctum bearer token instead of a license key.
     *
     * @throws Exception
     */
    public function fetchManifestWithToken(string $accessToken, ?string $currentVersion = null): array
    {
        $query = [];

        if ($currentVersion !== null) {
            $query['current_version'] = $currentVersion;
        }

        return $this->http->get(
            '/api/v1/'.$this->config->productSlug().'/update/manifest',
            $query,
            ['Authorization' => 'Bearer '.$accessToken]
        );
    }
}
