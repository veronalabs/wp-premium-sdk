<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
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
        $this->assertStringContainsString('license_key=KEY-001', WpStub::$requestLog[0]['url']);
        $this->assertStringContainsString('current_version=7.9.0', WpStub::$requestLog[0]['url']);
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
}
