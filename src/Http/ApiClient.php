<?php

namespace VeronaLabs\WpPremiumSdk\Http;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;

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
            throw new Exception(sprintf(
                /* translators: %s: transport error message */
                __('Nexus API request failed: %s', $textDomain),
                $response->get_error_message()
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new Exception(__('Invalid response from Nexus API.', $textDomain));
        }

        if ($code >= 400) {
            $message = $data['message'] ?? $data['error'] ?? __('Unknown API error.', $textDomain);
            throw new Exception(esc_html($message));
        }

        return $data;
    }
}
