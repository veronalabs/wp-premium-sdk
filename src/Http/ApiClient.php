<?php

namespace VeronaLabs\WpPremiumSdk\Http;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;

/**
 * Thin wp_remote_* wrapper for Nexus API calls.
 *
 * Disables SSL verification for local TLDs (.test, .local, .localhost) so developers
 * can hit a local Nexus during development. Throws on transport or API errors.
 */
class ApiClient
{
    private ClientConfig $config;

    public function __construct(ClientConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function get(string $endpoint, array $query = [], array $headers = []): array
    {
        $url = $this->buildUrl($endpoint);

        if (! empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => $this->shouldVerifySsl(),
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ]);

        return $this->parseResponse($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function post(string $endpoint, array $body, array $headers = []): array
    {
        $response = wp_remote_post($this->buildUrl($endpoint), [
            'timeout' => 30,
            'sslverify' => $this->shouldVerifySsl(),
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $headers),
            'body' => wp_json_encode($body),
        ]);

        return $this->parseResponse($response);
    }

    /**
     * Build a full URL from an endpoint path.
     */
    public function buildUrl(string $endpoint): string
    {
        return $this->config->apiBaseUrl().'/'.ltrim($endpoint, '/');
    }

    /**
     * SSL verification policy — disable for local dev TLDs so self-signed certs work.
     */
    public function shouldVerifySsl(?string $url = null): bool
    {
        $host = wp_parse_url($url ?? $this->config->apiBaseUrl(), PHP_URL_HOST);

        if (! $host) {
            return true;
        }

        foreach (['.test', '.local', '.localhost'] as $localTld) {
            if (substr($host, -strlen($localTld)) === $localTld) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array|\WP_Error  $response
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    private function parseResponse($response): array
    {
        $textDomain = $this->config->textDomain();

        if (is_wp_error($response)) {
            throw new ApiException(sprintf(
                /* translators: %s: transport error message */
                __('Nexus API request failed: %s', $textDomain),
                $response->get_error_message()
            ), LicenseErrorCode::NETWORK_ERROR);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ApiException(__('Invalid response from Nexus API.', $textDomain), LicenseErrorCode::INVALID_RESPONSE);
        }

        if ($code >= 400) {
            // Prefer the server's machine-readable code; fall back to its raw
            // message text (unchanged legacy behavior) when no code is present.
            $errorCode = (string) ($data['error_code'] ?? $data['code'] ?? '');
            $message = $data['message'] ?? $data['error'] ?? __('Unknown API error.', $textDomain);
            // Carry the full body so callers can read extras (e.g. a `renewal`
            // block Nexus attaches to an expired-license error).
            throw new ApiException(esc_html($message), $errorCode, $data);
        }

        return $data;
    }
}
