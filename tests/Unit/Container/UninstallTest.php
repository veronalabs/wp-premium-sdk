<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Container\PremiumServiceProvider;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * What the host's uninstall.php gets from PremiumServiceProvider::uninstall().
 */
class UninstallTest extends TestCase
{
    private const OPTION = 'wp_statistics_premium';

    private const MANIFEST = 'wp_premium_sdk_manifest_wp-statistics';

    protected function setUp(): void
    {
        WpStub::reset();
    }

    public function test_it_releases_the_seat_then_removes_everything_stored(): void
    {
        $this->activatedSite();
        WpStub::$siteTransients[self::MANIFEST] = ['success' => true];
        WpStub::$siteTransients[self::MANIFEST.'_failure'] = ['failures' => 1, 'retry_at' => time()];
        WpStub::queueJson(200, ['success' => true]);

        $result = $this->provider()->uninstall();

        $this->assertSame(['removed_remotely' => true, 'error_code' => null], $result);

        $call = WpStub::$requestLog[count(WpStub::$requestLog) - 1];
        $this->assertStringContainsString('/api/v1/license/deactivate', $call['url']);
        $this->assertSame('example.com', json_decode($call['args']['body'], true)['domain']);
        $this->assertSame(PremiumServiceProvider::UNINSTALL_TIMEOUT, $call['args']['timeout']);

        $this->assertArrayNotHasKey(self::OPTION, WpStub::$options);
        $this->assertArrayNotHasKey(self::OPTION.'_cipher', WpStub::$options);
        $this->assertSame([], WpStub::$siteTransients);
    }

    public function test_an_unreachable_server_never_stops_the_cleanup(): void
    {
        $this->activatedSite();
        WpStub::queueError('Operation timed out');

        $result = $this->provider()->uninstall();

        $this->assertSame(['removed_remotely' => false, 'error_code' => LicenseErrorCode::NETWORK_ERROR], $result);
        $this->assertArrayNotHasKey(self::OPTION, WpStub::$options);
        $this->assertArrayNotHasKey(self::OPTION.'_cipher', WpStub::$options);
    }

    public function test_the_seat_can_be_kept(): void
    {
        $this->activatedSite();
        $calls = count(WpStub::$requestLog);

        $this->provider()->uninstall(false);

        $this->assertCount($calls, WpStub::$requestLog, 'No call to Nexus.');
        $this->assertArrayNotHasKey(self::OPTION, WpStub::$options);
    }

    public function test_a_site_without_a_license_makes_no_call(): void
    {
        $result = $this->provider()->uninstall();

        $this->assertSame(['removed_remotely' => true, 'error_code' => null], $result);
        $this->assertSame([], WpStub::$requestLog);
    }

    /**
     * Activate with one provider, as the plugin did while installed; uninstall.php
     * builds a fresh one.
     */
    private function activatedSite(): void
    {
        $license = ['status' => 'active', 'features' => []];
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        WpStub::queueJson(200, ['success' => true, 'license' => $license]);
        $this->provider()->licenseManager()->activate('KEY-001');
        $this->provider()->accountManager()->setFlashError('left over');

        $this->assertArrayHasKey(self::OPTION, WpStub::$options);
        $this->assertArrayHasKey(self::OPTION.'_cipher', WpStub::$options, 'No salts in tests, so the fallback key is stored.');
    }

    private function provider(): PremiumServiceProvider
    {
        return new PremiumServiceProvider(new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => self::OPTION,
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
        ]), 'wp-statistics-premium/wp-statistics-premium.php');
    }
}
