<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
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
        $token = $this->manager->getAccessToken();

        if (! $token) {
            $this->errorResponse(
                __('Not connected to Nexus account.', $this->config->textDomain()),
                $this->getErrorCode()
            );

            return;
        }

        $response = $this->client->licenses($token);
        $licenses = $response['data'] ?? $response['licenses'] ?? [];

        $this->successResponse(['licenses' => $licenses]);
    }

    /**
     * @throws Exception
     */
    protected function activateLicense(): void
    {
        $licenseKey = Request::get('license_key', '');

        if ($licenseKey === '') {
            $this->errorResponse(
                __('License key is required.', $this->config->textDomain()),
                $this->getErrorCode()
            );

            return;
        }

        $data = $this->licenseManager->activate($licenseKey);

        $this->manager->clearPendingChoice();

        $this->successResponse(['license' => $data]);
    }
}
