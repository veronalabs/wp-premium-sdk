<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class PremiumStoreTest extends TestCase
{
    private PremiumStore $store;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->store = new PremiumStore(new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'wp_statistics_oauth_state_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'wp-statistics-premium',
            'current_version' => '15.0.0',
        ]));
    }

    public function test_set_and_get_roundtrips_a_section(): void
    {
        $this->store->set('license', ['status' => 'active']);

        $this->assertSame(['status' => 'active'], $this->store->get('license'));
    }

    public function test_get_returns_null_for_missing_section(): void
    {
        $this->assertNull($this->store->get('missing'));
    }

    public function test_sections_are_isolated(): void
    {
        $this->store->set('license', ['status' => 'active']);
        $this->store->set('account', ['access_token' => 'abc']);

        $this->assertSame(['status' => 'active'], $this->store->get('license'));
        $this->assertSame(['access_token' => 'abc'], $this->store->get('account'));
    }

    public function test_delete_section_keeps_others(): void
    {
        $this->store->set('license', ['status' => 'active']);
        $this->store->set('account', ['access_token' => 'abc']);

        $this->store->delete('license');

        $this->assertNull($this->store->get('license'));
        $this->assertSame(['access_token' => 'abc'], $this->store->get('account'));
    }

    public function test_delete_last_section_removes_option_row(): void
    {
        $this->store->set('license', ['status' => 'active']);
        $this->store->delete('license');

        $this->assertArrayNotHasKey('wp_statistics_premium', WpStub::$options);
    }

    public function test_clear_removes_everything(): void
    {
        $this->store->set('license', ['status' => 'active']);
        $this->store->set('account', ['access_token' => 'abc']);
        $this->store->clear();

        $this->assertNull($this->store->get('license'));
        $this->assertNull($this->store->get('account'));
        $this->assertArrayNotHasKey('wp_statistics_premium', WpStub::$options);
    }

    public function test_oauth_state_is_one_time_use(): void
    {
        $this->store->setOAuthState('state-abc');

        $this->assertTrue($this->store->verifyOAuthState('state-abc'));
        $this->assertFalse($this->store->verifyOAuthState('state-abc'), 'State should be consumed on first verify.');
    }

    public function test_unknown_state_fails_verification(): void
    {
        $this->assertFalse($this->store->verifyOAuthState('nope'));
    }

    public function test_a_license_change_fires_license_changed_without_the_key(): void
    {
        $this->store->set('license', ['license_key' => 'cipher', 'status' => 'active', 'expires_at' => '2027-01-01', 'activated_at' => 100]);

        [$tag, $args] = WpStub::$actionLog[0];
        $this->assertSame('wp_premium_sdk/license_changed', $tag);
        $this->assertSame('wp-statistics', $args[0]);
        $this->assertNull($args[1], 'No license before.');
        $this->assertSame('active', $args[2]['status']);
        $this->assertSame('2027-01-01', $args[2]['expires_at']);
        $this->assertArrayNotHasKey('license_key', $args[2]);
    }

    public function test_a_write_that_changes_nothing_entitlement_depends_on_is_silent(): void
    {
        $this->store->set('license', ['license_key' => 'cipher-1', 'status' => 'active', 'activated_at' => 100]);
        WpStub::$actionLog = [];

        $this->store->set('license', ['license_key' => 'cipher-2', 'status' => 'active', 'activated_at' => 100, 'last_validated_at' => 200]);
        $this->store->set('account', ['token' => 't']);

        $this->assertSame([], WpStub::$actionLog);
    }

    public function test_each_entitlement_change_fires(): void
    {
        $base = ['status' => 'active', 'activated_at' => 100, 'tier_slug' => 'basic', 'features' => ['a'], 'max_activations' => 1];
        $this->store->set('license', $base);

        foreach ([['status' => 'expired'], ['tier_slug' => 'pro'], ['features' => ['a', 'b']], ['max_activations' => 5], ['activated_at' => 200]] as $change) {
            WpStub::$actionLog = [];
            $this->store->set('license', array_merge($base, $change));
            $this->assertCount(1, WpStub::$actionLog, 'Change: '.json_encode($change));
            $this->store->set('license', $base);
        }
    }

    public function test_this_sites_standing_and_a_refusal_fire(): void
    {
        $base = ['status' => 'active', 'activated_at' => 100, 'site' => ['active' => true, 'is_counted' => true]];
        $this->store->set('license', $base);

        foreach ([
            ['site' => ['active' => false, 'is_counted' => true]],
            ['site' => ['active' => true, 'is_counted' => false]],
            ['error_code' => 'domain_not_allowed'],
        ] as $change) {
            WpStub::$actionLog = [];
            $this->store->set('license', array_merge($base, $change));
            $this->assertCount(1, WpStub::$actionLog, 'Change: '.json_encode($change));
            $this->store->set('license', $base);
        }

        $snapshot = PremiumStore::licenseSnapshot(array_merge($base, ['error_code' => 'domain_not_allowed']));
        $this->assertSame(['active' => true, 'is_counted' => true], $snapshot['site']);
        $this->assertSame('domain_not_allowed', $snapshot['error_code']);
        $this->assertSame(['active' => null, 'is_counted' => null], PremiumStore::licenseSnapshot(['status' => 'active'])['site']);
    }

    public function test_other_sites_activating_is_silent(): void
    {
        $this->store->set('license', ['status' => 'active', 'activated_at' => 100, 'max_activations' => 5, 'activation_count' => 1, 'sites' => [['domain' => 'a.test']]]);
        WpStub::$actionLog = [];

        $this->store->set('license', ['status' => 'active', 'activated_at' => 100, 'max_activations' => 5, 'activation_count' => 2, 'sites' => [['domain' => 'a.test'], ['domain' => 'b.test']]]);

        $this->assertSame([], WpStub::$actionLog);
        $this->assertArrayNotHasKey('activation_count', PremiumStore::licenseSnapshot(['status' => 'active']));
    }

    public function test_removing_the_license_fires_with_a_null_new_value(): void
    {
        $this->store->set('license', ['status' => 'active']);
        $this->store->set('account', ['token' => 't']);
        WpStub::$actionLog = [];

        $this->store->delete('license');

        $this->assertNull(WpStub::$actionLog[0][1][2]);

        $this->store->set('license', ['status' => 'active']);
        WpStub::$actionLog = [];
        $this->store->clear();

        $this->assertCount(1, WpStub::$actionLog);
        $this->assertNull(WpStub::$actionLog[0][1][2]);
    }
}
