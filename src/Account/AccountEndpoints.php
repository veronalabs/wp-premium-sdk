<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * AJAX dispatcher for account / OAuth actions.
 *
 * Action: wp_ajax_{prefix}_account
 * Sub-actions: init_oauth, logout, get_status, fetch_licenses, activate_license
 */
class AccountEndpoints extends AbstractAjaxEndpoint
{
    private AccountManager $manager;
    private LicenseManager $licenseManager;
    private AccountClient $client;

    public function __construct(ClientConfig $config, AccountManager $manager, LicenseManager $licenseManager, AccountClient $client)
    {
        parent::__construct($config);
        $this->manager = $manager;
        $this->licenseManager = $licenseManager;
        $this->client = $client;
    }

    protected function getActionName(): string
    {
        return 'account';
    }

    protected function getSubActions(): array
    {
        return [
            'init_oauth' => 'initOAuth',
            'logout' => 'logout',
            'get_status' => 'getStatus',
            'fetch_licenses' => 'fetchLicenses',
            'activate_license' => 'activateLicense',
        ];
    }

    protected function getErrorCode(): string
    {
        return 'account_error';
    }

    protected function initOAuth(): void
    {
        $returnUrl = (string) Request::get('return_url', '');
        $returnUrl = $returnUrl !== '' ? esc_url_raw($returnUrl) : '';

        $this->successResponse($this->manager->getAuthorizeUrl($returnUrl !== '' ? $returnUrl : null));
    }

    protected function logout(): void
    {
        if ($this->refuseOtherUsersSignIn()) {
            return;
        }

        $this->manager->logout();
        $this->successResponse(['connected' => false]);
    }

    protected function getStatus(): void
    {
        $this->successResponse([
            'logged_in' => $this->manager->isConnected(),
            'connected' => $this->manager->isConnected(),
            'user' => $this->manager->getUser(),
            'oauth_error' => $this->manager->consumeFlashError(),
        ]);
    }

    /**
     * @throws Exception
     */
    protected function fetchLicenses(): void
    {
        if ($this->refuseOtherUsersSignIn()) {
            return;
        }

        $token = $this->manager->getAccessToken();

        if (! $token) {
            // A session that is still stored but yields no token has lapsed: clear
            // it and say so, so the UI asks for a fresh sign-in.
            $lapsed = $this->manager->hasSession();

            if ($lapsed) {
                $this->manager->clearSession();
            }

            $this->errorResponse(
                $lapsed
                    ? __('Your sign-in expired. Please sign in again.', $this->config->textDomain())
                    : __('Not connected to Nexus account.', $this->config->textDomain()),
                $lapsed ? LicenseErrorCode::ACCOUNT_EXPIRED : $this->getErrorCode()
            );

            return;
        }

        try {
            $response = $this->client->licenses($token);
        } catch (Exception $e) {
            if (! $this->manager->isSignInExpired($e)) {
                throw $e;
            }

            // The token died mid-picker: drop the sign-in so the UI offers a fresh one.
            $this->manager->clearSession();
            $this->errorResponse(
                __('Your sign-in expired. Please sign in again.', $this->config->textDomain()),
                LicenseErrorCode::ACCOUNT_EXPIRED
            );

            return;
        }

        $licenses = $response['data'] ?? $response['licenses'] ?? [];

        $this->successResponse(['licenses' => $licenses]);
    }

    /**
     * @throws Exception
     */
    protected function activateLicense(): void
    {
        if ($this->refuseOtherUsersSignIn()) {
            return;
        }

        $licenseKey = Request::get('license_key', '');

        if ($licenseKey === '') {
            $this->errorResponse(
                __('License key is required.', $this->config->textDomain()),
                $this->getErrorCode()
            );

            return;
        }

        $data = $this->licenseManager->activate($licenseKey);

        // The sign-in has done its job; from here on the license key is enough.
        $this->manager->endSignIn();

        $this->successResponse(['license' => $data]);
    }

    /**
     * Another admin's sign-in is in progress: this user gets the normal activation
     * screen, never that user's licenses.
     *
     * @return bool Whether the call was refused (the response is sent).
     */
    private function refuseOtherUsersSignIn(): bool
    {
        if (! $this->manager->isSignInOfOtherUser()) {
            return false;
        }

        $this->errorResponse(
            __('Another administrator is signing in to the account.', $this->config->textDomain()),
            LicenseErrorCode::SIGN_IN_OTHER_USER
        );

        return true;
    }
}
