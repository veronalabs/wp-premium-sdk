<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\Support\BuildsSdk;
use VeronaLabs\WpPremiumSdk\Tests\Support\SdkFixture;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * Remembering the domain a license was activated on, and moving it (#18).
 */
class DomainChangeTest extends TestCase
{
    use BuildsSdk;

    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];
    }

    public function test_activation_stores_the_normalised_domain(): void
    {
        WpStub::$homeUrl = 'https://WWW.Example.com/';
        $this->queueActivation();
        $sdk = $this->provider();

        $sdk->licenseManager()->activate('KEY-001');

        $this->assertSame('example.com', WpStub::$options[SdkFixture::OPTION]['license']['activated_domain']);
        $this->assertSame('example.com', $sdk->licenseManager()->getLicenseData()['activated_domain']);
        $this->assertNull($sdk->licenseManager()->domainChange());
    }

    public function test_a_new_domain_is_reported(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://staging.example.com';

        $this->assertSame(['was' => 'example.com', 'now' => 'staging.example.com'], $this->provider()->licenseManager()->domainChange());
    }

    public function test_the_same_site_spelled_differently_is_not_a_change(): void
    {
        $this->activateOn('https://example.com/blog');
        WpStub::$homeUrl = 'http://www.example.com/blog/';

        $this->assertNull($this->provider()->licenseManager()->domainChange());
    }

    public function test_get_status_reports_domain_changed(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://new-example.com';
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $response = $this->ajax($this->provider(), 'license', 'get_status');

        $this->assertSame(['was' => 'example.com', 'now' => 'new-example.com'], $response['data']['domain_changed']);
    }

    public function test_a_refresh_keeps_the_activated_domain(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://new-example.com';
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $sdk = $this->provider();
        $sdk->licenseManager()->refreshStatus();

        $this->assertSame('example.com', $sdk->licenseManager()->domainChange()['was']);
    }

    public function test_a_license_stored_before_the_field_learns_it_from_a_domain_check(): void
    {
        $this->activateOn('https://example.com');
        unset(WpStub::$options[SdkFixture::OPTION]['license']['activated_domain']);

        $sdk = $this->provider();
        $this->assertNull($sdk->licenseManager()->domainChange());

        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);
        $sdk->licenseManager()->validate();

        $this->assertSame('example.com', WpStub::$options[SdkFixture::OPTION]['license']['activated_domain']);
    }

    public function test_move_license_activates_here_then_releases_the_old_domain(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://new-example.com';
        WpStub::$requestLog = [];
        $this->queueActivation(['site' => ['active' => true, 'is_counted' => true]]);
        WpStub::queueJson(200, ['success' => true]);
        $sdk = $this->provider();

        $response = $this->ajax($sdk, 'license', 'move_license');

        $this->assertTrue($response['success']);
        $this->assertSame(['/api/v1/license/activate', '/api/v1/license/validate', '/api/v1/license/deactivate'], $this->paths());
        $this->assertSame('new-example.com', $this->request(3)['body']['domain']);
        $this->assertSame('example.com', $this->request()['body']['domain']);
        $this->assertSame(['domain' => 'new-example.com', 'is_counted' => true], $response['data']['activated']);
        $this->assertTrue($response['data']['released']['attempted']);
        $this->assertTrue($response['data']['released']['removed_remotely']);
        $this->assertNull($sdk->licenseManager()->domainChange());
    }

    public function test_a_failed_activation_leaves_the_old_domain_alone(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://new-example.com';
        WpStub::$requestLog = [];
        WpStub::queueJson(409, ['error_code' => 'activation_limit_reached', 'message' => 'No seats']);
        $sdk = $this->provider();

        $response = $this->ajax($sdk, 'license', 'move_license');

        $this->assertFalse($response['success']);
        $this->assertSame(LicenseErrorCode::ACTIVATION_LIMIT_REACHED, $response['data']['code']);
        $this->assertSame(['/api/v1/license/activate'], $this->paths());
        $this->assertSame('example.com', $sdk->licenseManager()->domainChange()['was']);
    }

    public function test_a_domain_that_uses_no_seat_keeps_the_old_one(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://staging.example.com';
        WpStub::$requestLog = [];
        $this->queueActivation(['site' => ['active' => true, 'is_counted' => false]]);

        $response = $this->ajax($this->provider(), 'license', 'move_license');

        $this->assertTrue($response['success']);
        $this->assertSame(['/api/v1/license/activate', '/api/v1/license/validate'], $this->paths());
        $this->assertFalse($response['data']['activated']['is_counted']);
        $this->assertFalse($response['data']['released']['attempted']);
    }

    public function test_release_old_overrides_the_default(): void
    {
        $this->activateOn('https://example.com');
        WpStub::$homeUrl = 'https://staging.example.com';
        WpStub::$requestLog = [];
        $this->queueActivation(['site' => ['active' => true, 'is_counted' => false]]);
        WpStub::queueJson(404, ['error_code' => 'activation_not_found', 'message' => 'Gone']);

        $response = $this->ajax($this->provider(), 'license', 'move_license', ['release_old' => '1']);

        $this->assertTrue($response['data']['released']['attempted']);
        $this->assertFalse($response['data']['released']['removed_remotely']);
        $this->assertSame('activation_not_found', $response['data']['released']['error_code']);
    }

    public function test_move_license_without_a_change_is_refused(): void
    {
        $this->activateOn('https://example.com');
        $calls = count(WpStub::$requestLog);

        $response = $this->ajax($this->provider(), 'license', 'move_license');

        $this->assertSame(LicenseErrorCode::DOMAIN_UNCHANGED, $response['data']['code']);
        $this->assertCount($calls, WpStub::$requestLog);
    }

    private function activateOn(string $homeUrl): void
    {
        WpStub::$homeUrl = $homeUrl;
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('KEY-001');
    }
}
