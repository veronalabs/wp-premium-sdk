<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Http;

use Exception;
use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class ApiClientTest extends TestCase
{
    private ApiClient $http;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->http = new ApiClient(new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]));
    }

    public function test_get_returns_decoded_json_body_on_success(): void
    {
        WpStub::queueJson(200, ['success' => true, 'data' => ['foo' => 'bar']]);

        $result = $this->http->get('/api/v1/ping');

        $this->assertSame(['success' => true, 'data' => ['foo' => 'bar']], $result);
        $this->assertSame('https://nexus.test/api/v1/ping', WpStub::$requestLog[0]['url']);
        $this->assertSame('GET', WpStub::$requestLog[0]['method']);
    }

    public function test_get_appends_query_string(): void
    {
        WpStub::queueJson(200, []);

        $this->http->get('/api/v1/thing', ['a' => '1', 'b' => '2']);

        $this->assertStringContainsString('a=1', WpStub::$requestLog[0]['url']);
        $this->assertStringContainsString('b=2', WpStub::$requestLog[0]['url']);
    }

    public function test_post_sends_json_body(): void
    {
        WpStub::queueJson(200, ['ok' => true]);

        $this->http->post('/api/v1/thing', ['license_key' => 'XYZ']);

        $sent = json_decode(WpStub::$requestLog[0]['args']['body'], true);
        $this->assertSame(['license_key' => 'XYZ'], $sent);
    }

    public function test_throws_on_transport_error(): void
    {
        WpStub::queueError('Connection refused');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Connection refused');

        $this->http->get('/api/v1/thing');
    }

    public function test_throws_on_api_error_status(): void
    {
        WpStub::queueJson(422, ['message' => 'Invalid license key']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid license key');

        $this->http->get('/api/v1/thing');
    }

    public function test_surfaces_error_code_from_response_body(): void
    {
        WpStub::queueJson(422, ['error_code' => 'invalid_key', 'message' => 'Invalid license key']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::INVALID_KEY, $e->getErrorCode());
            $this->assertSame('Invalid license key', $e->getMessage());
        }
    }

    public function test_error_exception_carries_renewal_block_from_body(): void
    {
        $renewal = [
            'state'     => 'expired',
            'renew_url' => 'https://nexus.test/checkout?coupon=RENEW-ABC',
            'offer'     => ['code' => 'RENEW-ABC', 'discount_type' => 'percentage', 'discount_value' => 20],
        ];
        WpStub::queueJson(403, ['error_code' => 'license_expired', 'message' => 'License has expired', 'renewal' => $renewal]);

        try {
            $this->http->post('/api/v1/license/activate', ['license_key' => 'X']);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('license_expired', $e->getErrorCode());
            $this->assertSame($renewal, $e->getData()['renewal'] ?? null, 'The renewal block must survive on the error so the UI can offer it.');
        }
    }

    public function test_error_exception_has_empty_data_for_transport_failure(): void
    {
        WpStub::queueError('Connection refused');

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame([], $e->getData());
        }
    }

    public function test_falls_back_to_legacy_code_field(): void
    {
        WpStub::queueJson(403, ['code' => 'domain_not_allowed', 'message' => 'Domain not allowed']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::DOMAIN_NOT_ALLOWED, $e->getErrorCode());
        }
    }

    public function test_unknown_code_when_body_has_only_a_message(): void
    {
        WpStub::queueJson(422, ['message' => 'Something went wrong']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::UNKNOWN, $e->getErrorCode());
            $this->assertSame('Something went wrong', $e->getMessage(), 'Legacy message fallback must be preserved.');
        }
    }

    public function test_transport_failure_maps_to_network_error_code(): void
    {
        WpStub::queueError('Connection refused');

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::NETWORK_ERROR, $e->getErrorCode());
        }
    }

    public function test_non_json_body_maps_to_invalid_response_code(): void
    {
        WpStub::$responseQueue[] = [200, '<html>not json</html>', []];

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::INVALID_RESPONSE, $e->getErrorCode());
        }
    }

    public function test_error_carries_the_http_status(): void
    {
        WpStub::queueJson(403, ['error_code' => 'license_suspended', 'message' => 'Suspended']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getHttpStatus());
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_a_429_without_a_code_is_rate_limited_with_retry_after(): void
    {
        WpStub::queueJson(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '120']);

        try {
            $this->http->post('/api/v1/license/validate', []);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::RATE_LIMITED, $e->getErrorCode());
            $this->assertSame(120, $e->getRetryAfter());
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_a_429_error_page_is_rate_limited(): void
    {
        WpStub::$responseQueue[] = [429, '<html>slow down</html>', ['retry-after' => '30']];

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::RATE_LIMITED, $e->getErrorCode());
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function test_a_5xx_error_page_is_a_server_error(): void
    {
        WpStub::$responseQueue[] = [502, '<html>Bad Gateway</html>', []];

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::SERVER_ERROR, $e->getErrorCode());
            $this->assertSame(502, $e->getHttpStatus());
        }
    }

    public function test_a_json_5xx_without_a_code_is_a_server_error(): void
    {
        WpStub::queueJson(500, ['message' => 'Server Error']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::SERVER_ERROR, $e->getErrorCode());
        }
    }

    /**
     * Nexus sends `error_code: null` beside a legacy `code`; the null must not win.
     */
    public function test_a_null_error_code_falls_back_to_legacy_code(): void
    {
        WpStub::queueJson(403, ['error_code' => null, 'code' => 'wrong_product', 'message' => 'Wrong product']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::WRONG_PRODUCT, $e->getErrorCode());
        }
    }

    public function test_a_numeric_code_is_not_taken_for_a_reason(): void
    {
        WpStub::queueJson(404, ['code' => 404, 'message' => 'Not Found']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(LicenseErrorCode::UNKNOWN, $e->getErrorCode());
        }
    }

    public function test_an_unknown_server_code_passes_through(): void
    {
        WpStub::queueJson(403, ['error_code' => 'something_new', 'message' => 'New']);

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('something_new', $e->getErrorCode());
            $this->assertFalse($e->isTransient());
        }
    }

    public function test_a_transport_failure_has_status_zero(): void
    {
        WpStub::queueError('Connection refused');

        try {
            $this->http->get('/api/v1/thing');
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(0, $e->getHttpStatus());
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_post_passes_a_custom_timeout(): void
    {
        WpStub::queueJson(200, []);

        $this->http->post('/api/v1/thing', [], [], 5);

        $this->assertSame(5, WpStub::$requestLog[0]['args']['timeout']);
    }

    public function test_disables_ssl_verify_for_local_tlds(): void
    {
        $this->assertFalse($this->http->shouldVerifySsl('https://nexus.test'));
        $this->assertFalse($this->http->shouldVerifySsl('https://nexus.local'));
        $this->assertFalse($this->http->shouldVerifySsl('https://nexus.localhost'));
        $this->assertTrue($this->http->shouldVerifySsl('https://nexus.io'));
    }

    public function test_buildUrl_joins_base_and_endpoint_without_doubled_slash(): void
    {
        $this->assertSame('https://nexus.test/api/v1/thing', $this->http->buildUrl('/api/v1/thing'));
        $this->assertSame('https://nexus.test/api/v1/thing', $this->http->buildUrl('api/v1/thing'));
    }
}
