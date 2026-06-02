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
