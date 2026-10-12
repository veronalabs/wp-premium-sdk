<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Container\PremiumServiceProvider;
use VeronaLabs\WpPremiumSdk\Tests\Support\BuildsSdk;
use VeronaLabs\WpPremiumSdk\Tests\Support\SdkFixture;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * The update offer follows the license (#33), the manifest cache is flushed when
 * the license changes (#34), and "View details" is answered from the manifest (#41)
 * or, without one, from the plugin header, never by wordpress.org.
 */
class UpdateOfferTest extends TestCase
{
    use BuildsSdk;

    private const CACHE = 'wp_premium_sdk_manifest_wp-statistics';

    private PremiumServiceProvider $sdk;

    protected function setUp(): void
    {
        WpStub::reset();
        $this->sdk = $this->activated('KEY-001');
    }

    public function test_an_offer_is_withdrawn_once_the_license_is_revoked(): void
    {
        $this->queueManifest();
        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate((object) ['response' => []]);
        $this->assertArrayHasKey(SdkFixture::PLUGIN, $transient->response);

        WpStub::queueJson(403, ['success' => false, 'error' => 'license_revoked', 'error_code' => 'license_revoked']);
        $this->sdk->licenseManager()->refreshStatus();
        $this->assertFalse($this->sdk->licenseManager()->isValid());

        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate($transient);

        $this->assertArrayNotHasKey(SdkFixture::PLUGIN, $transient->response);
        $this->assertSame('15.0.0', $transient->no_update[SdkFixture::PLUGIN]->new_version);
        $this->assertSame('', $transient->no_update[SdkFixture::PLUGIN]->package);
    }

    public function test_the_host_can_hold_the_update_back(): void
    {
        $seen = [];
        add_filter('wp_premium_sdk/update_allowed', static function ($allowed, $slug, $manifest) use (&$seen) {
            $seen = [$allowed, $slug, $manifest['manifest']['version']];

            return false;
        }, 10, 3);

        $this->queueManifest();
        $stale = (object) ['response' => [SdkFixture::PLUGIN => (object) ['new_version' => '15.1.0']]];
        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate($stale);

        $this->assertSame([true, 'wp-statistics', '16.0.0'], $seen);
        $this->assertArrayNotHasKey(SdkFixture::PLUGIN, $transient->response);
        $this->assertArrayHasKey(SdkFixture::PLUGIN, $transient->no_update);
    }

    public function test_no_update_available_withdraws_an_old_offer(): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => false]);
        $stale = (object) ['response' => [SdkFixture::PLUGIN => (object) ['new_version' => '15.1.0']]];

        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate($stale);

        $this->assertArrayNotHasKey(SdkFixture::PLUGIN, $transient->response);
    }

    public function test_a_failed_fetch_leaves_the_offer_alone(): void
    {
        WpStub::queueError('Network down');
        $offer = (object) ['new_version' => '16.0.0'];

        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate((object) ['response' => [SdkFixture::PLUGIN => $offer]]);

        $this->assertSame($offer, $transient->response[SdkFixture::PLUGIN]);
    }

    public function test_a_manifest_cached_for_another_key_is_not_used(): void
    {
        $this->queueManifest('16.0.0');
        $this->sdk->pluginUpdater()->fetchManifest();

        // The stored key changes without any flush (e.g. a write the SDK does not hear about).
        $other = $this->activated('KEY-002');
        $this->queueManifest('17.0.0');
        $count = count(WpStub::$requestLog);

        $this->assertNull($other->pluginUpdater()->cachedManifest(), 'The cached answer belongs to KEY-001.');
        $this->assertSame('17.0.0', $other->pluginUpdater()->fetchManifest()['manifest']['version']);
        $this->assertSame('KEY-002', WpStub::$requestLog[$count]['args']['headers']['X-License-Key']);
    }

    public function test_each_subsite_has_its_own_cache_when_activated_site_by_site(): void
    {
        WpStub::$isMultisite = true;
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();

        $this->assertArrayHasKey(self::CACHE.'_1', WpStub::$siteTransients);
        $this->assertArrayNotHasKey(self::CACHE, WpStub::$siteTransients);

        WpStub::switchBlog(2);
        $this->assertNull($this->provider()->pluginUpdater()->cachedManifest(), 'Blog 2 does not read blog 1\'s answer.');
    }

    public function test_a_license_change_flushes_the_cache_and_the_backoff(): void
    {
        $this->sdk->register();
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();
        WpStub::$siteTransients[self::CACHE.'_failure'] = ['failures' => 2, 'retry_at' => time() + 3600];

        // A renewal: the expiry moves.
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'expires_at' => '2099-01-01']]);
        $this->sdk->licenseManager()->refreshStatus();

        $this->assertArrayNotHasKey(self::CACHE, WpStub::$siteTransients);
        $this->assertArrayNotHasKey(self::CACHE.'_failure', WpStub::$siteTransients);
    }

    public function test_a_refresh_that_changes_nothing_keeps_the_cache(): void
    {
        $this->sdk->register();
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active', 'features' => []]]);
        $this->sdk->licenseManager()->refreshStatus();

        $this->assertArrayHasKey(self::CACHE, WpStub::$siteTransients);
    }

    public function test_view_details_is_answered_from_the_cached_manifest(): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => true, 'manifest' => [
            'version' => '16.0.0',
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => '7.1',
            'released_at' => '2026-10-01T00:00:00+00:00',
            'changelog' => "### 16.0.0\n- <b>New</b> reports",
            'plugin' => ['url' => 'https://nexus.test/p.zip'],
        ]]);
        $this->sdk->pluginUpdater()->fetchManifest();
        $count = count(WpStub::$requestLog);

        $info = $this->sdk->pluginUpdater()->pluginInformation(false, 'plugin_information', (object) ['slug' => 'wp-statistics-premium']);

        $this->assertIsObject($info);
        $this->assertSame('wp-statistics-premium', $info->slug);
        $this->assertSame('16.0.0', $info->version);
        $this->assertSame('6.0', $info->requires);
        $this->assertSame('7.4', $info->requires_php);
        $this->assertSame('7.1', $info->tested);
        $this->assertStringContainsString('&lt;b&gt;New&lt;/b&gt; reports', $info->sections['changelog']);
        $this->assertCount($count, WpStub::$requestLog, 'No request from the modal.');
    }

    public function test_view_details_offers_the_package_and_never_links_to_wordpress_org(): void
    {
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();

        $info = $this->details();

        $this->assertTrue($info->external);
        $this->assertSame('https://nexus.test/p.zip', $info->download_link);
    }

    public function test_view_details_has_no_download_link_for_the_installed_version(): void
    {
        $this->queueManifest('15.0.0');
        $this->sdk->pluginUpdater()->fetchManifest();

        $info = $this->details();

        $this->assertSame('15.0.0', $info->version);
        $this->assertTrue($info->external);
        $this->assertObjectNotHasProperty('download_link', $info);
    }

    public function test_view_details_answers_from_the_plugin_header_without_a_manifest(): void
    {
        WpStub::$plugins[SdkFixture::PLUGIN] = [
            'Name' => 'WP Statistics Premium',
            'Version' => '15.0.0',
            'Author' => 'VeronaLabs',
            'PluginURI' => 'https://wp-statistics.com',
            'Description' => 'Premium <add-ons>.',
            'RequiresWP' => '6.0',
            'RequiresPHP' => '7.4',
        ];

        $info = $this->details();

        $this->assertIsObject($info, 'Never falls through to wordpress.org for our own slug.');
        $this->assertSame('WP Statistics Premium', $info->name);
        $this->assertSame('15.0.0', $info->version);
        $this->assertSame('6.0', $info->requires);
        $this->assertSame('7.4', $info->requires_php);
        $this->assertSame('https://wp-statistics.com', $info->homepage);
        $this->assertSame('Premium &lt;add-ons&gt;.', $info->sections['description']);
        $this->assertTrue($info->external);
        $this->assertObjectNotHasProperty('download_link', $info);
        $this->assertSame([], WpStub::$requestLog, 'Nothing cached: no request either.');
    }

    public function test_view_details_for_an_unlicensed_site_shows_the_installed_version_without_a_package(): void
    {
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();

        WpStub::queueJson(403, ['success' => false, 'error' => 'license_revoked', 'error_code' => 'license_revoked']);
        $this->sdk->licenseManager()->refreshStatus();
        $count = count(WpStub::$requestLog);

        $info = $this->details();

        $this->assertIsObject($info);
        $this->assertSame('15.0.0', $info->version, 'From ClientConfig when the header is not known.');
        $this->assertObjectNotHasProperty('download_link', $info);
        $this->assertCount($count, WpStub::$requestLog);
    }

    public function test_view_details_leaves_other_slugs_and_actions_alone(): void
    {
        $updater = $this->sdk->pluginUpdater();
        $this->queueManifest();
        $updater->fetchManifest();

        $this->assertFalse($updater->pluginInformation(false, 'plugin_information', (object) ['slug' => 'akismet']));
        $this->assertFalse($updater->pluginInformation(false, 'query_plugins', (object) ['slug' => 'wp-statistics-premium']));
    }

    public function test_a_cached_manifest_for_the_installed_version_is_not_offered(): void
    {
        // Cached before the upgrade to 15.0.0, which is what now runs.
        $this->queueManifest('15.0.0');
        $stale = (object) ['response' => [SdkFixture::PLUGIN => (object) ['new_version' => '15.0.0']]];

        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate($stale);

        $this->assertArrayNotHasKey(SdkFixture::PLUGIN, $transient->response);
        $this->assertArrayHasKey(SdkFixture::PLUGIN, $transient->no_update);

        $this->queueManifest('14.9.0');
        $this->sdk->pluginUpdater()->flush();
        $transient = $this->sdk->pluginUpdater()->injectPluginUpdate((object) ['response' => []]);
        $this->assertArrayNotHasKey(SdkFixture::PLUGIN, $transient->response, 'Older than installed: not offered either.');
    }

    public function test_upgrading_this_plugin_flushes_the_cached_manifest(): void
    {
        $this->sdk->register();
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();

        do_action('upgrader_process_complete', null, ['action' => 'update', 'type' => 'plugin', 'bulk' => true, 'plugins' => ['akismet/akismet.php']]);
        do_action('upgrader_process_complete', null, ['action' => 'update', 'type' => 'theme', 'themes' => [SdkFixture::PLUGIN]]);
        $this->assertArrayHasKey(self::CACHE, WpStub::$siteTransients, 'Another plugin, or not a plugin: kept.');

        do_action('upgrader_process_complete', null, ['action' => 'update', 'type' => 'plugin', 'bulk' => true, 'plugins' => [SdkFixture::PLUGIN]]);
        $this->assertArrayNotHasKey(self::CACHE, WpStub::$siteTransients);
    }

    public function test_replacing_this_plugin_by_upload_flushes_the_cached_manifest(): void
    {
        $this->queueManifest();
        $this->sdk->pluginUpdater()->fetchManifest();

        $upgrader = (object) ['result' => ['destination_name' => 'wp-statistics-premium']];
        $this->sdk->pluginUpdater()->onUpgraderProcessComplete($upgrader, ['action' => 'install', 'type' => 'plugin']);

        $this->assertArrayNotHasKey(self::CACHE, WpStub::$siteTransients);
    }

    /**
     * @return mixed
     */
    private function details()
    {
        return $this->sdk->pluginUpdater()->pluginInformation(false, 'plugin_information', (object) ['slug' => 'wp-statistics-premium']);
    }

    private function activated(string $key): PremiumServiceProvider
    {
        $provider = $this->provider();
        $this->queueActivation(['features' => []]);
        $provider->licenseManager()->activate($key);
        WpStub::$requestLog = [];

        return $provider;
    }

    private function queueManifest(string $version = '16.0.0'): void
    {
        WpStub::queueJson(200, ['success' => true, 'update_available' => true, 'manifest' => [
            'version' => $version,
            'plugin' => ['url' => 'https://nexus.test/p.zip'],
        ]]);
    }
}
