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
}
