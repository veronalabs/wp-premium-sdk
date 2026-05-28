<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class LicenseManagerTest extends TestCase
{
    private PremiumStore $store;

    private LicenseManager $manager;

    protected function setUp(): void
    {
        WpStub::reset();

        $config = new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]);

        $this->store = new PremiumStore($config);
        $this->manager = new LicenseManager(
            new LicenseClient($config, new ApiClient($config)),
            $this->store,
            new SodiumEncryptor('wp_statistics_premium_cipher'),
        );
    }

    public function test_has_feature_matches_only_listed_slugs(): void
    {
        $this->store->set('license', ['features' => ['entry-pages', 'goals']]);

        $this->assertTrue($this->manager->hasFeature('goals'));
        $this->assertFalse($this->manager->hasFeature('events'));
    }

    public function test_wildcard_feature_entitles_every_module(): void
    {
        $this->store->set('license', ['features' => ['*']]);

        $this->assertTrue($this->manager->hasFeature('entry-pages'));
        $this->assertTrue($this->manager->hasFeature('any-other-module'));
    }

    public function test_refresh_status_pulls_renewed_expiry_from_server(): void
    {
        $this->activateWith('2020-01-01T00:00:00+00:00');

        // Renewal happened on the server.
        WpStub::queueJson(200, ['success' => true, 'license' => [
            'status' => 'active',
            'expires_at' => '2027-01-01T00:00:00+00:00',
            'features' => ['entry-pages'],
        ]]);

        $this->manager->refreshStatus();

        $this->assertSame('2027-01-01T00:00:00+00:00', $this->manager->getLicenseData()['expires_at']);
    }

    public function test_refresh_status_validates_without_a_domain(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $this->manager->refreshStatus();

        $lastBody = json_decode(WpStub::$requestLog[count(WpStub::$requestLog) - 1]['args']['body'], true);
        $this->assertSame('', $lastBody['domain'] ?? 'MISSING');
    }

    public function test_refresh_status_keeps_cache_on_api_failure(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        WpStub::queueError('Network down');
        $this->manager->refreshStatus();

        $this->assertSame('2027-01-01T00:00:00+00:00', $this->manager->getLicenseData()['expires_at']);
    }

    public function test_get_license_data_exposes_masked_key_not_raw(): void
    {
        $this->activateWith('2027-01-01T00:00:00+00:00');

        $data = $this->manager->getLicenseData();

        $this->assertArrayNotHasKey('license_key', $data, 'Raw key must never reach the UI.');
        $this->assertArrayHasKey('license_key_masked', $data);
        $this->assertStringEndsWith('-001', $data['license_key_masked']);
        $this->assertStringContainsString('•', $data['license_key_masked']);
    }

    public function test_activate_captures_tier_slug_from_response(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'pro', 'features' => [], 'expires_at' => '2027-01-01T00:00:00+00:00'];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate

        $data = $this->manager->activate('KEY-001');

        $this->assertSame('pro', $data['tier_slug'] ?? null, 'Public license data must expose the Nexus tier_slug.');
        $this->assertSame('pro', $this->manager->getTier());
    }

    public function test_activation_gate_veto_throws_and_rolls_back(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'basic', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate
        WpStub::queueJson(200, ['success' => true]);                        // deactivate (rollback)

        add_filter('wp_premium_sdk/activation_gate', static function ($error, array $licenseData) {
            return ($licenseData['tier_slug'] ?? '') === 'basic' ? 'Tier too low for this build.' : $error;
        }, 10, 2);

        try {
            $this->manager->activate('KEY-001');
            $this->fail('Expected the activation gate to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Tier too low for this build.', $e->getMessage());
        }

        $this->assertNull($this->manager->getLicenseData(), 'A vetoed activation must not persist license data.');

        $last = WpStub::$requestLog[count(WpStub::$requestLog) - 1];
        $this->assertStringContainsString('/api/v1/license/deactivate', $last['url'], 'Veto must roll back the Nexus activation.');
    }

    public function test_activation_gate_allows_when_filter_returns_null(): void
    {
        $license = ['status' => 'active', 'tier_slug' => 'elite', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // activate
        WpStub::queueJson(200, ['success' => true, 'license' => $license]); // validate

        add_filter('wp_premium_sdk/activation_gate', static fn ($error) => $error, 10, 2);

        $data = $this->manager->activate('KEY-001');

        $this->assertSame('elite', $data['tier_slug']);
        $this->assertSame('elite', $this->manager->getTier());
    }

    /**
     * Seed the cache via activate() (which makes an activate + a validate call).
     */
    private function activateWith(string $expiresAt): void
    {
        $license = ['status' => 'active', 'expires_at' => $expiresAt, 'features' => ['entry-pages']];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->manager->activate('KEY-001');
    }
}
