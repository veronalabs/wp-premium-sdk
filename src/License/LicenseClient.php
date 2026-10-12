<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\Http\ApiException;

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
     * The product slug this client asks Nexus about.
     */
    public function productSlug(): string
    {
        return $this->config->productSlug();
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
     * Release the seat `$domain` holds on this key. Any domain on the license works,
     * not only this site's — that is how a site is removed from the license page.
     *
     * @param  int  $timeout  Seconds to wait; uninstall passes a short one.
     *
     * @throws Exception
     */
    public function deactivate(string $licenseKey, string $domain, int $timeout = ApiClient::DEFAULT_TIMEOUT): array
    {
        return $this->http->post('/api/v1/license/deactivate', [
            'license_key' => $licenseKey,
            'product_slug' => $this->config->productSlug(),
            'domain' => $domain,
        ], [], $timeout);
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
     * The key travels in the `X-License-Key` header, never in the URL, so it stays
     * out of access logs along the way (Nexus, CDN, proxies, HTTP loggers).
     *
     * A Nexus that does not read the header yet (before veronalabs/nexus#1237)
     * sees no key and answers 401 without a code. That 401 is reported as
     * `manifest_auth_missing` rather than `unknown`, so the failure is visible
     * (the key is never put back in the URL).
     *
     * @throws Exception
     */
    public function fetchManifest(string $licenseKey, ?string $currentVersion = null): array
    {
        $query = [];

        if ($currentVersion !== null) {
            $query['current_version'] = $currentVersion;
        }

        try {
            return $this->http->get(
                '/api/v1/'.$this->config->productSlug().'/update/manifest',
                $query,
                ['X-License-Key' => $licenseKey]
            );
        } catch (ApiException $e) {
            if ($e->getHttpStatus() === 401 && in_array($e->getErrorCode(), [LicenseErrorCode::UNKNOWN, LicenseErrorCode::INVALID_RESPONSE], true)) {
                throw new ApiException($e->getMessage(), LicenseErrorCode::MANIFEST_AUTH_MISSING, $e->getData(), 401, $e, $e->getRetryAfter());
            }

            throw $e;
        }
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
