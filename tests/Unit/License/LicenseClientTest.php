<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class LicenseClientTest extends TestCase
{
    private LicenseClient $client;

    private ClientConfig $config;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->config = new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]);

        $this->client = new LicenseClient($this->config, new ApiClient($this->config));
    }

    public function test_activate_sends_product_slug_domain_and_site_url(): void
    {
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $this->client->activate('KEY-001', 'example.com', 'https://example.com');

        $body = json_decode(WpStub::$requestLog[0]['args']['body'], true);
        $this->assertSame([
            'license_key' => 'KEY-001',
            'product_slug' => 'wp-statistics',
            'domain' => 'example.com',
            'site_url' => 'https://example.com',
        ], $body);
        $this->assertStringEndsWith('/api/v1/license/activate', WpStub::$requestLog[0]['url']);
    }

    public function test_fetch_manifest_targets_product_scoped_manifest_endpoint(): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => true, 'manifest' => ['version' => '8.0.0']]);

        $result = $this->client->fetchManifest('KEY-001', '7.9.0');

        $this->assertSame('8.0.0', $result['manifest']['version']);
        $this->assertStringContainsString('/api/v1/wp-statistics/update/manifest', WpStub::$requestLog[0]['url']);
        $this->assertStringContainsString('current_version=7.9.0', WpStub::$requestLog[0]['url']);
    }

    public function test_fetch_manifest_sends_this_sites_domain(): void
    {
        WpStub::$homeUrl = 'https://www.example.com/blog/';
        WpStub::queueJson(200, ['update_available' => false]);

        $this->client->fetchManifest('KEY-001', '7.9.0');

        parse_str((string) parse_url(WpStub::$requestLog[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['current_version' => '7.9.0', 'domain' => 'example.com/blog'], $query);
    }

    public function test_fetch_manifest_without_a_version_still_sends_the_domain(): void
    {
        WpStub::queueJson(200, ['update_available' => false]);

        $this->client->fetchManifest('KEY-001');

        parse_str((string) parse_url(WpStub::$requestLog[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['domain' => 'example.com'], $query);
    }

    public function test_fetch_manifest_with_token_sends_the_domain(): void
    {
        WpStub::queueJson(200, ['update_available' => false]);

        $this->client->fetchManifestWithToken('tok_xyz', '7.9.0');

        $this->assertStringContainsString('domain=example.com', WpStub::$requestLog[0]['url']);
    }

    public function test_fetch_manifest_sends_the_key_in_a_header_not_the_url(): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => false]);

        $this->client->fetchManifest('KEY-001', '7.9.0');

        $this->assertSame('KEY-001', WpStub::$requestLog[0]['args']['headers']['X-License-Key']);
        $this->assertStringNotContainsString('license_key', WpStub::$requestLog[0]['url']);
        $this->assertStringNotContainsString('KEY-001', WpStub::$requestLog[0]['url']);
    }

    public function test_a_manifest_401_without_a_code_is_manifest_auth_missing(): void
    {
        // A Nexus without veronalabs/nexus#1237 never sees the header key.
        WpStub::queueJson(401, ['success' => false, 'error' => 'A license key is required.']);

        try {
            $this->client->fetchManifest('KEY-001', '7.9.0');
            $this->fail('Expected an ApiException.');
        } catch (\VeronaLabs\WpPremiumSdk\Http\ApiException $e) {
            $this->assertSame('manifest_auth_missing', $e->getErrorCode());
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertFalse($e->isTransient());
        }
    }

    public function test_a_manifest_401_with_a_code_keeps_it(): void
    {
        WpStub::queueJson(401, ['success' => false, 'error_code' => 'invalid_key']);

        try {
            $this->client->fetchManifest('KEY-001', '7.9.0');
            $this->fail('Expected an ApiException.');
        } catch (\VeronaLabs\WpPremiumSdk\Http\ApiException $e) {
            $this->assertSame('invalid_key', $e->getErrorCode());
        }
    }

    public function test_fetch_manifest_with_token_sends_bearer_header(): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => false]);

        $this->client->fetchManifestWithToken('tok_xyz');

        $headers = WpStub::$requestLog[0]['args']['headers'];
        $this->assertSame('Bearer tok_xyz', $headers['Authorization']);
    }

    public function test_fetch_manifest_omits_current_version_when_not_supplied(): void
    {
        WpStub::queueJson(200, ['update_available' => false]);

        $this->client->fetchManifest('KEY-001');

        $this->assertStringNotContainsString('current_version', WpStub::$requestLog[0]['url']);
    }

    /**
     * #58: a plugin that names its item sends it, so a bundle license knows which
     * package to answer with.
     */
    public function test_fetch_manifest_sends_the_configured_item_slug(): void
    {
        $client = $this->clientWith(['item_slug' => 'wsms']);
        WpStub::queueJson(200, ['update_available' => false]);
        WpStub::queueJson(200, ['update_available' => false]);

        $client->fetchManifest('KEY-001', '8.0.0');
        $client->fetchManifestWithToken('tok_xyz');

        foreach (WpStub::$requestLog as $call) {
            parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
            $this->assertSame('wsms', $query['item_slug']);
        }
    }

    public function test_fetch_manifest_sends_no_item_slug_unless_configured(): void
    {
        WpStub::queueJson(200, ['update_available' => false]);

        $this->client->fetchManifest('KEY-001', '7.9.0');

        $this->assertStringNotContainsString('item_slug', WpStub::$requestLog[0]['url']);
    }

    /**
     * #58: the bundle refusal from a Nexus that sends no code yet.
     */
    public function test_a_bundle_refusal_without_a_code_is_item_slug_required(): void
    {
        WpStub::queueJson(400, ['success' => false, 'error' => 'This license covers several plugins — name one with item_slug', 'covers' => ['wp-sms-pro', 'wp-sms-woocommerce-pro']]);

        $e = $this->manifestFailure();

        $this->assertSame(LicenseErrorCode::ITEM_SLUG_REQUIRED, $e->getErrorCode());
        $this->assertSame(400, $e->getHttpStatus());
        $this->assertSame(['wp-sms-pro', 'wp-sms-woocommerce-pro'], $e->getData()['covers']);
        $this->assertFalse($e->isTransient());
    }

    public function test_a_bundle_refusal_with_a_code_keeps_it(): void
    {
        WpStub::queueJson(400, ['success' => false, 'error' => 'This license covers several plugins — name one with item_slug', 'error_code' => 'item_slug_required']);

        $this->assertSame(LicenseErrorCode::ITEM_SLUG_REQUIRED, $this->manifestFailure()->getErrorCode());
    }

    public function test_an_uncovered_item_without_a_code_is_item_not_covered(): void
    {
        WpStub::queueJson(403, ['success' => false, 'error' => 'This license does not cover wsms', 'covers' => ['wp-sms-pro']]);

        $this->assertSame(LicenseErrorCode::ITEM_NOT_COVERED, $this->manifestFailure()->getErrorCode());
    }

    public function test_another_bare_400_stays_unknown(): void
    {
        WpStub::queueJson(400, ['success' => false, 'error' => 'Something else']);

        $this->assertSame(LicenseErrorCode::UNKNOWN, $this->manifestFailure()->getErrorCode());
    }

    private function manifestFailure(): ApiException
    {
        try {
            $this->client->fetchManifest('KEY-001', '8.0.0');
        } catch (ApiException $e) {
            return $e;
        }

        $this->fail('Expected an ApiException.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function clientWith(array $overrides): LicenseClient
    {
        $config = new ClientConfig($overrides + [
            'product_slug' => 'wsms',
            'option_key' => 'wsms_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '8.0.0',
        ]);

        return new LicenseClient($config, new ApiClient($config));
    }
}
