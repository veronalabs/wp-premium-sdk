<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\License\AutoActivator;
use VeronaLabs\WpPremiumSdk\License\KeySource;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\Support\BuildsSdk;
use VeronaLabs\WpPremiumSdk\Tests\Support\SdkFixture;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * A license key set in wp-config for bulk setup (#19).
 *
 * Constants cannot be undefined, so each test names its own.
 */
class LicenseKeyConstantTest extends TestCase
{
    use BuildsSdk;

    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];
    }

    public function test_a_defined_constant_activates_the_site_once(): void
    {
        define('SDK_TEST_KEY_ACTIVATES', 'CONST-KEY-0001');
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_ACTIVATES']);
        $this->queueActivation();

        $sdk->autoActivator()->run();

        $this->assertSame('/api/v1/license/activate', $this->paths()[0]);
        $activate = (array) json_decode((string) WpStub::$requestLog[0]['args']['body'], true);
        $this->assertSame('CONST-KEY-0001', $activate['license_key']);
        $this->assertSame('example.com', $activate['domain']);
        $this->assertSame(KeySource::CONSTANT, $sdk->licenseManager()->getSource());

        $calls = count(WpStub::$requestLog);
        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_ACTIVATES'])->autoActivator()->run();
        $this->assertCount($calls, WpStub::$requestLog, 'Already activated with that key: nothing to do.');
    }

    public function test_an_undefined_or_empty_constant_does_nothing(): void
    {
        define('SDK_TEST_KEY_EMPTY', '   ');

        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_EMPTY'])->autoActivator()->run();
        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_NEVER_DEFINED'])->autoActivator()->run();

        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_a_different_stored_key_is_replaced_and_its_seat_released(): void
    {
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('OLD-KEY-0001');

        define('SDK_TEST_KEY_REPLACES', 'CONST-KEY-0002');
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_REPLACES']);
        WpStub::$requestLog = [];
        $this->queueActivation();
        WpStub::queueJson(200, ['success' => true]);

        $sdk->autoActivator()->run();

        $this->assertSame(['/api/v1/license/activate', '/api/v1/license/validate', '/api/v1/license/deactivate'], $this->paths());
        $this->assertSame('OLD-KEY-0001', $this->request()['body']['license_key']);
        $this->assertSame('CONST-KEY-0002', $sdk->licenseManager()->getLicenseKey());
    }

    public function test_a_failure_waits_1h_then_6h_then_24h(): void
    {
        define('SDK_TEST_KEY_BACKOFF', 'CONST-KEY-0003');
        $waits = [];

        for ($i = 0; $i < 4; $i++) {
            WpStub::queueJson(422, ['error_code' => 'invalid_key', 'message' => 'Invalid']);
            $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_BACKOFF']);
            $sdk->autoActivator()->run();

            $failure = $sdk->autoActivator()->lastFailure();
            $this->assertSame($i + 1, $failure['attempts']);
            $this->assertSame(LicenseErrorCode::INVALID_KEY, $failure['error_code']);
            $waits[] = $failure['retry_at'] - $failure['last_attempt_at'];

            $calls = count(WpStub::$requestLog);
            $this->provider(['license_key_constant' => 'SDK_TEST_KEY_BACKOFF'])->autoActivator()->run();
            $this->assertCount($calls, WpStub::$requestLog, 'Inside the wait: no new attempt.');

            $this->expireWait();
        }

        $this->assertSame([3600, 21600, 86400, 86400], $waits);
    }

    public function test_a_longer_retry_after_is_respected(): void
    {
        define('SDK_TEST_KEY_RETRY_AFTER', 'CONST-KEY-0004');
        WpStub::queueJson(429, ['message' => 'Slow down'], ['Retry-After' => '7200']);
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_RETRY_AFTER']);

        $sdk->autoActivator()->run();

        $failure = $sdk->autoActivator()->lastFailure();
        $this->assertSame(7200, $failure['retry_at'] - $failure['last_attempt_at']);
        $this->assertSame(LicenseErrorCode::RATE_LIMITED, $failure['error_code']);
    }

    public function test_the_key_is_never_stored_in_the_retry_record(): void
    {
        define('SDK_TEST_KEY_SECRET', 'CONST-SECRET-9999');
        WpStub::queueError('Network down');

        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_SECRET'])->autoActivator()->run();

        $this->assertStringNotContainsString('CONST-SECRET-9999', serialize(WpStub::$options));
        $this->assertArrayHasKey(AutoActivator::SECTION, WpStub::$options[SdkFixture::OPTION]);
    }

    public function test_success_clears_the_retry_record(): void
    {
        define('SDK_TEST_KEY_RECOVERS', 'CONST-KEY-0005');
        WpStub::queueError('Network down');
        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_RECOVERS'])->autoActivator()->run();
        $this->expireWait();
        $this->queueActivation();

        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_RECOVERS']);
        $sdk->autoActivator()->run();

        $this->assertTrue($sdk->licenseManager()->isActivated());
        $this->assertNull($sdk->autoActivator()->lastFailure());
    }

    public function test_get_status_reports_the_constant_and_the_last_failure(): void
    {
        define('SDK_TEST_KEY_STATUS', 'CONST-KEY-0006');
        WpStub::queueJson(403, ['error_code' => 'wrong_product', 'message' => 'Wrong product']);
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_STATUS']);
        $sdk->autoActivator()->run();

        $response = $this->ajax($sdk, 'license', 'get_status');

        $this->assertSame(KeySource::CONSTANT, $response['data']['source']);
        $this->assertSame(LicenseErrorCode::WRONG_PRODUCT, $response['data']['auto_activation']['error_code']);
        $this->assertStringNotContainsString('CONST-KEY-0006', (string) json_encode($response));
    }

    public function test_get_status_reports_manual_for_a_typed_key(): void
    {
        $this->queueActivation();
        $sdk = $this->provider();
        $sdk->licenseManager()->activate('TYPED-KEY-0001');
        WpStub::queueJson(200, ['success' => true, 'license' => ['status' => 'active']]);

        $response = $this->ajax($sdk, 'license', 'get_status');

        $this->assertSame(KeySource::MANUAL, $response['data']['source']);
        $this->assertNull($response['data']['auto_activation']);
    }

    public function test_deactivate_and_activate_refuse_a_key_from_the_constant(): void
    {
        define('SDK_TEST_KEY_LOCKED', 'CONST-KEY-0007');
        $this->queueActivation();
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_LOCKED']);
        $sdk->autoActivator()->run();
        $calls = count(WpStub::$requestLog);

        $deactivate = $this->ajax($sdk, 'license', 'deactivate');
        $activate = $this->ajax($sdk, 'license', 'activate', ['license_key' => 'OTHER-KEY']);

        $this->assertFalse($deactivate['success']);
        $this->assertSame(LicenseErrorCode::KEY_FROM_CONSTANT, $deactivate['data']['code']);
        $this->assertSame(LicenseErrorCode::KEY_FROM_CONSTANT, $activate['data']['code']);
        $this->assertCount($calls, WpStub::$requestLog);
        $this->assertTrue($sdk->licenseManager()->isActivated());
    }

    public function test_the_account_sign_in_cannot_replace_a_key_from_the_constant(): void
    {
        define('SDK_TEST_KEY_ACCOUNT', 'CONST-KEY-0008');

        $response = $this->ajax($this->provider(['license_key_constant' => 'SDK_TEST_KEY_ACCOUNT']), 'account', 'activate_license', ['license_key' => 'OTHER-KEY']);

        $this->assertSame(LicenseErrorCode::KEY_FROM_CONSTANT, $response['data']['code']);
        $this->assertSame([], WpStub::$requestLog);
    }

    public function test_deleting_the_constant_removes_the_license_it_activated(): void
    {
        define('SDK_TEST_KEY_DELETED', 'CONST-KEY-0009');
        $this->queueActivation();
        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_DELETED'])->autoActivator()->run();
        WpStub::queueJson(200, ['success' => true]);

        // The next request: the host still names the constant, wp-config no longer defines it.
        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_GONE']);
        $sdk->autoActivator()->run();

        $this->assertSame('/api/v1/license/deactivate', $this->paths()[count(WpStub::$requestLog) - 1]);
        $this->assertFalse($sdk->licenseManager()->isActivated());
    }

    public function test_a_typed_key_survives_when_no_constant_is_set(): void
    {
        $this->queueActivation();
        $this->provider()->licenseManager()->activate('TYPED-KEY-0002');
        $calls = count(WpStub::$requestLog);

        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_NOT_THERE']);
        $sdk->autoActivator()->run();

        $this->assertCount($calls, WpStub::$requestLog);
        $this->assertTrue($sdk->licenseManager()->isActivated());
    }

    public function test_a_cloned_site_activates_its_own_domain_and_keeps_the_old_seat(): void
    {
        define('SDK_TEST_KEY_CLONE', 'CONST-KEY-0010');
        $this->queueActivation();
        $this->provider(['license_key_constant' => 'SDK_TEST_KEY_CLONE'])->autoActivator()->run();
        WpStub::$homeUrl = 'https://staging.example.com';
        WpStub::$requestLog = [];
        $this->queueActivation();

        $sdk = $this->provider(['license_key_constant' => 'SDK_TEST_KEY_CLONE']);
        $sdk->autoActivator()->run();

        $this->assertSame(['/api/v1/license/activate', '/api/v1/license/validate'], $this->paths());
        $this->assertSame('staging.example.com', $this->request(2)['body']['domain']);
        $this->assertNull($sdk->licenseManager()->domainChange());
    }

    public function test_it_hooks_admin_init(): void
    {
        $this->provider()->register();

        $this->assertNotEmpty(WpStub::$filters['admin_init'] ?? []);
    }

    /**
     * Move the stored retry time into the past, as if the wait had run out.
     */
    private function expireWait(): void
    {
        WpStub::$options[SdkFixture::OPTION][AutoActivator::SECTION]['retry_at'] = time() - 1;
    }
}
