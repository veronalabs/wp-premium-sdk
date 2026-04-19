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
}
