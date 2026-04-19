<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;

/**
 * HTTP client for Nexus OAuth + account endpoints.
 */
class AccountClient
{
    public function __construct(
        private ClientConfig $config,
        private ApiClient $http,
    ) {}

    /**
     * Exchange an OAuth authorization code for an access token via Nexus.
     *
     * @throws Exception
     */
    public function exchangeCode(string $code): array
    {
        return $this->http->post('/api/v1/auth/exchange-code', [
            'code' => $code,
            'product_slug' => $this->config->productSlug(),
        ]);
    }

    /**
     * Fetch account status + licenses for the authenticated user.
     *
     * @throws Exception
     */
    public function status(string $accessToken, ?string $pluginSlug = null): array
    {
        $query = ['product_slug' => $this->config->productSlug()];

        if ($pluginSlug) {
            $query['plugin_slug'] = $pluginSlug;
        }

        return $this->http->get('/api/v1/account/status', $query, [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }

    /**
     * @throws Exception
     */
    public function licenses(string $accessToken): array
    {
        return $this->http->get('/api/v1/account/licenses', [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }

    /**
     * @throws Exception
     */
    public function logout(string $accessToken): array
    {
        return $this->http->post('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }
}
