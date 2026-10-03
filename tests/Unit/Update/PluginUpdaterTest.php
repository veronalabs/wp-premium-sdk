<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * A failed manifest fetch must not be repeated on every update check (#6).
 */
class PluginUpdaterTest extends TestCase
{
    private const FAILURE_KEY = 'wp_premium_sdk_manifest_wp-statistics_failure';

    private PluginUpdater $updater;

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

        $client = new LicenseClient($config, new ApiClient($config));
        $manager = new LicenseManager($client, new PremiumStore($config), new SodiumEncryptor('wp_statistics_premium_cipher'));

        $license = ['status' => 'active', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $manager->activate('KEY-001');
        WpStub::$requestLog = [];

        $this->updater = new PluginUpdater($config, $client, $manager, 'wp-statistics-premium/wp-statistics-premium.php');
    }

    public function test_a_failure_is_not_repeated_on_the_next_check(): void
    {
        WpStub::queueJson(403, ['error_code' => 'license_expired', 'message' => 'Expired']);

        $this->assertNull($this->updater->fetchManifest());
        $this->assertNull($this->updater->fetchManifest());

        $this->assertCount(1, WpStub::$requestLog, 'The second check must wait instead of asking again.');
        $this->assertSame(1, WpStub::$siteTransients[self::FAILURE_KEY]['failures']);
        $this->assertSame('license_expired', WpStub::$siteTransients[self::FAILURE_KEY]['error_code']);
    }

    public function test_the_wait_grows_with_each_failure_and_is_capped(): void
    {
        $waits = [];

        for ($i = 0; $i < 6; $i++) {
            WpStub::queueError('Network down');
            $this->updater->fetchManifest();
            $waits[] = WpStub::$siteTransients[self::FAILURE_KEY]['retry_at'] - time();
            $this->expireBackoff();
        }

        $this->assertEqualsWithDelta([3600, 10800, 21600, 43200, 43200, 43200], $waits, 2);
    }

    public function test_a_longer_retry_after_is_respected(): void
    {
        WpStub::queueJson(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '7200']);

        $this->updater->fetchManifest();

        $this->assertEqualsWithDelta(7200, WpStub::$siteTransients[self::FAILURE_KEY]['retry_at'] - time(), 2);
    }

    public function test_success_clears_the_failure_record(): void
    {
        WpStub::queueError('Network down');
        $this->updater->fetchManifest();
        $this->expireBackoff();

        WpStub::queueJson(200, ['success' => true, 'update_available' => false]);

        $this->assertSame(['success' => true, 'update_available' => false], $this->updater->fetchManifest());
        $this->assertArrayNotHasKey(self::FAILURE_KEY, WpStub::$siteTransients);
    }

    public function test_a_forced_check_skips_the_wait(): void
    {
        WpStub::queueError('Network down');
        $this->updater->fetchManifest();

        WpStub::queueJson(200, ['success' => true]);

        $this->assertSame(['success' => true], $this->updater->fetchManifest(true));
        $this->assertCount(2, WpStub::$requestLog);
    }

    public function test_flush_forgets_the_failure_too(): void
    {
        WpStub::queueError('Network down');
        $this->updater->fetchManifest();

        $this->updater->flush();

        $this->assertArrayNotHasKey(self::FAILURE_KEY, WpStub::$siteTransients);
    }

    /**
     * Pretend the wait has passed, keeping the failure count.
     */
    private function expireBackoff(): void
    {
        WpStub::$siteTransients[self::FAILURE_KEY]['retry_at'] = time() - 1;
    }
}
