<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Account;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Container\PremiumServiceProvider;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\Tests\Support\BuildsSdk;
use VeronaLabs\WpPremiumSdk\Tests\Support\SdkFixture;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * Every way out of the account picker deletes the sign-in, and the sign-in belongs
 * to the admin who started it (#10).
 */
class SignInLifetimeTest extends TestCase
{
    use BuildsSdk;

    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function test_the_exchange_names_this_site_as_the_device(): void
    {
        WpStub::$homeUrl = 'https://www.example.com/blog/';
        $this->signIn($this->provider());

        $exchange = (array) json_decode((string) WpStub::$requestLog[0]['args']['body'], true);
        $this->assertStringContainsString('/api/v1/auth/exchange-code', WpStub::$requestLog[0]['url']);
        $this->assertSame('example.com/blog', $exchange['device_name']);
    }

    public function test_the_session_records_who_started_it(): void
    {
        WpStub::$currentUserId = 7;
        $this->signIn($this->provider());

        $this->assertSame(7, WpStub::$options[SdkFixture::OPTION]['account']['user_id']);
    }

    public function test_activating_by_key_ends_the_sign_in(): void
    {
        $sdk = $this->provider();
        $this->signIn($sdk);
        $this->queueActivation();
        WpStub::queueJson(200, ['success' => true]);

        $sdk->licenseManager()->activate('KEY-001');

        $this->assertArrayNotHasKey('account', WpStub::$options[SdkFixture::OPTION]);
        $this->assertStringContainsString('/logout', WpStub::$requestLog[count(WpStub::$requestLog) - 1]['url'], 'The token is revoked.');
        $this->assertFalse($this->provider()->accountManager()->isConnected());
    }

    public function test_activating_on_the_license_page_ends_the_sign_in(): void
    {
        $sdk = $this->provider();
        $this->signIn($sdk);
        $this->queueActivation();
        WpStub::queueJson(200, ['success' => true]);

        $response = $this->ajax($sdk, 'license', 'activate', ['license_key' => 'KEY-001']);

        $this->assertTrue($response['success']);
        $this->assertArrayNotHasKey('account', WpStub::$options[SdkFixture::OPTION]);
    }

    public function test_signing_out_deletes_the_session(): void
    {
        $sdk = $this->provider();
        $this->signIn($sdk);
        WpStub::queueJson(200, ['success' => true]);

        $response = $this->ajax($sdk, 'account', 'logout');

        $this->assertFalse($response['data']['connected']);
        $this->assertArrayNotHasKey(SdkFixture::OPTION, WpStub::$options);
    }

    public function test_an_expired_session_is_deleted_when_read_without_any_401(): void
    {
        $this->signIn($this->provider());
        WpStub::$options[SdkFixture::OPTION]['account']['expires_at'] = time() - 1;
        $calls = count(WpStub::$requestLog);

        $this->assertFalse($this->provider()->accountManager()->isConnected());

        $this->assertArrayNotHasKey(SdkFixture::OPTION, WpStub::$options, 'Gone from the database, not just reported false.');
        $this->assertCount($calls, WpStub::$requestLog);
    }

    public function test_another_admin_does_not_see_the_sign_in(): void
    {
        WpStub::$currentUserId = 1;
        $this->signIn($this->provider());
        WpStub::$currentUserId = 2;
        $sdk = $this->provider();

        $this->assertFalse($sdk->accountManager()->isConnected());
        $this->assertNull($sdk->accountManager()->getUser());
        $this->assertNull($sdk->accountManager()->getPendingChoice());

        $status = $this->ajax($sdk, 'account', 'get_status');
        $this->assertFalse($status['data']['connected']);
        $this->assertNull($status['data']['user']);

        $calls = count(WpStub::$requestLog);

        foreach (['fetch_licenses' => [], 'activate_license' => ['license_key' => 'KEY-002'], 'logout' => []] as $subAction => $body) {
            $response = $this->ajax($sdk, 'account', $subAction, $body);
            $this->assertSame(LicenseErrorCode::SIGN_IN_OTHER_USER, $response['data']['code'], $subAction);
        }

        $this->assertCount($calls, WpStub::$requestLog);
        $this->assertArrayHasKey('account', WpStub::$options[SdkFixture::OPTION], 'The owner\'s sign-in is untouched.');

        WpStub::$currentUserId = 1;
        $this->assertTrue($this->provider()->accountManager()->isConnected());
    }

    public function test_the_owner_still_uses_the_picker(): void
    {
        WpStub::$currentUserId = 5;
        $sdk = $this->provider();
        $this->signIn($sdk);
        WpStub::queueJson(200, ['data' => [['license_key' => 'KEY-1'], ['license_key' => 'KEY-2']]]);

        $response = $this->ajax($sdk, 'account', 'fetch_licenses');

        $this->assertTrue($response['success']);
        $this->assertCount(2, $response['data']['licenses']);
    }

    /**
     * Run the OAuth callback; the account has two licenses, so the picker waits.
     */
    private function signIn(PremiumServiceProvider $sdk): void
    {
        $sdk->store()->setOAuthState('state-1');
        WpStub::queueJson(200, ['access_token' => 'token-1', 'user' => ['email' => 'buyer@example.com', 'name' => 'Ada Buyer']]);
        WpStub::queueJson(200, ['data' => [['license_key' => 'KEY-1'], ['license_key' => 'KEY-2']]]);

        $sdk->accountManager()->handleOAuthCallback('code-1', 'state-1', $sdk->licenseManager());

        $this->assertTrue($sdk->accountManager()->isConnected());
    }
}
