<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\Support\Request;

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
     * `domain` is the site asking, so Nexus can check it holds a seat on the
     * license, as activate and validate do: this site's domain unless `$domain`
     * names another (a network-wide update check sends the main site's).
     *
     * `item_slug` (ClientConfig) names the store item asking, so a bundle license
     * knows which package to answer with. A bundle refusal without it is reported
     * as `item_slug_required`, and one for an item the license does not cover as
     * `item_not_covered`, also from a Nexus that sends no code for them yet.
     *
     * @throws Exception
     */
    public function fetchManifest(string $licenseKey, ?string $currentVersion = null, ?string $domain = null): array
    {
        $query = $this->manifestQuery($currentVersion, $domain);

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

            throw $this->withItemRefusalCode($e);
        }
    }

    /**
     * Fetch the manifest using a Sanctum bearer token instead of a license key.
     *
     * @throws Exception
     */
    public function fetchManifestWithToken(string $accessToken, ?string $currentVersion = null, ?string $domain = null): array
    {
        $query = $this->manifestQuery($currentVersion, $domain);

        try {
            return $this->http->get(
                '/api/v1/'.$this->config->productSlug().'/update/manifest',
                $query,
                ['Authorization' => 'Bearer '.$accessToken]
            );
        } catch (ApiException $e) {
            throw $this->withItemRefusalCode($e);
        }
    }

    /**
     * Give the manifest's "which item?" refusals a code when Nexus sent none.
     * Nexus before veronalabs/nexus#1332 answers a bundle license asked without
     * `item_slug` with a bare 400 ("… name one with item_slug" / "… name a plugin
     * with item_slug"), and an item the license does not cover with a bare 403
     * ("This license does not cover …"). Anything else, and any reply that has a
     * code, is returned unchanged.
     */
    private function withItemRefusalCode(ApiException $e): ApiException
    {
        if ($e->getErrorCode() !== LicenseErrorCode::UNKNOWN) {
            return $e;
        }

        $error = (string) ($e->getData()['error'] ?? $e->getData()['message'] ?? '');
        $code = null;

        if ($e->getHttpStatus() === 400 && stripos($error, 'with item_slug') !== false) {
            $code = LicenseErrorCode::ITEM_SLUG_REQUIRED;
        } elseif ($e->getHttpStatus() === 403 && stripos($error, 'does not cover') !== false) {
            $code = LicenseErrorCode::ITEM_NOT_COVERED;
        }

        return $code === null ? $e : new ApiException($e->getMessage(), $code, $e->getData(), $e->getHttpStatus(), $e, $e->getRetryAfter());
    }

    /**
     * The manifest query: `current_version` when given, `domain` (this site's
     * unless another is named), and `item_slug` when the plugin names its item. A
     * blank domain or item is left out, so a host that sets no `item_slug` sends
     * the same request as before.
     *
     * @return array<string, string>
     */
    private function manifestQuery(?string $currentVersion, ?string $domain): array
    {
        $query = [];

        if ($currentVersion !== null) {
            $query['current_version'] = $currentVersion;
        }

        $domain = $domain ?? Request::currentDomain();

        if ($domain !== '') {
            $query['domain'] = $domain;
        }

        if ($this->config->itemSlug() !== '') {
            $query['item_slug'] = $this->config->itemSlug();
        }

        return $query;
    }
}
