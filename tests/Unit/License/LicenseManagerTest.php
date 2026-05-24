<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
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
            new FeatureInstaller($config),
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
