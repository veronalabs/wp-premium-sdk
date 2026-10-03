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
    /** Seconds a normal request may take before it counts as a network error. */
    public const DEFAULT_TIMEOUT = 30;

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
    public function get(string $endpoint, array $query = [], array $headers = [], int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $url = $this->buildUrl($endpoint);

        if (! empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => $timeout,
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
    public function post(string $endpoint, array $body, array $headers = [], int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $response = wp_remote_post($this->buildUrl($endpoint), [
            'timeout' => $timeout,
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
     * Turn a wp_remote_* result into decoded JSON, or throw an ApiException whose
     * error code says what went wrong:
     *
     * - no answer at all (WP_Error) → network_error;
     * - 429, with or without a body → the body's code, else rate_limited, plus the
     *   Retry-After seconds when the server sent them;
     * - a 5xx that isn't JSON (a proxy or PHP error page) → server_error;
     * - any other body that isn't JSON → invalid_response;
     * - any other status >= 400 → the body's `error_code`, then its legacy `code`,
     *   else server_error for a 5xx and unknown for a 4xx. A code the SDK does not
     *   know is passed through as-is.
     *
     * @param  array|\WP_Error  $response
     * @return array<string, mixed>
     *
     * @throws ApiException
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

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $retryAfter = $this->retryAfterSeconds(wp_remote_retrieve_header($response, 'retry-after'));

        if (! is_array($data)) {
            if ($status === 429) {
                throw new ApiException(__('Too many requests to the Nexus API.', $textDomain), LicenseErrorCode::RATE_LIMITED, [], $status, null, $retryAfter);
            }

            if ($status >= 500) {
                throw new ApiException(__('The Nexus API had a problem.', $textDomain), LicenseErrorCode::SERVER_ERROR, [], $status, null, $retryAfter);
            }

            throw new ApiException(__('Invalid response from Nexus API.', $textDomain), LicenseErrorCode::INVALID_RESPONSE, [], $status);
        }

        if ($status >= 400) {
            $errorCode = $this->serverErrorCode($data);

            if ($errorCode === '') {
                if ($status === 429) {
                    $errorCode = LicenseErrorCode::RATE_LIMITED;
                } elseif ($status >= 500) {
                    $errorCode = LicenseErrorCode::SERVER_ERROR;
                } else {
                    $errorCode = LicenseErrorCode::UNKNOWN;
                }
            }

            $message = $data['message'] ?? $data['error'] ?? __('Unknown API error.', $textDomain);
            // Carry the full body so callers can read extras (e.g. a `renewal`
            // block Nexus attaches to an expired-license error).
            throw new ApiException(esc_html((string) $message), $errorCode, $data, $status, null, $retryAfter);
        }

        return $data;
    }

    /**
     * The machine-readable code from an error body: `error_code` first, then the
     * legacy `code`. Only a non-empty, non-numeric string counts — a numeric
     * `code` is an HTTP status echoed back, not a reason.
     *
     * @param  array<string, mixed>  $data
     */
    private function serverErrorCode(array $data): string
    {
        foreach (['error_code', 'code'] as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value) && $value !== '' && ! is_numeric($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Seconds from a Retry-After header, which is either a number of seconds or an
     * HTTP date. Null when absent or unreadable.
     *
     * @param  string|array<int, string>  $header
     */
    private function retryAfterSeconds($header): ?int
    {
        if (is_array($header)) {
            $header = (string) reset($header);
        }

        $header = trim((string) $header);

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
