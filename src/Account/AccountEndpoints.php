<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;

/**
 * AJAX dispatcher for account / OAuth actions.
 *
 * Action: wp_ajax_{prefix}_account
 * Sub-actions: init_oauth, logout, get_status
 */
class AccountEndpoints extends AbstractAjaxEndpoint
{
    public function __construct(
        ClientConfig $config,
        private AccountManager $manager,
        private LicenseManager $licenseManager,
    ) {
        parent::__construct($config);
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
        ];
    }

    protected function getErrorCode(): string
    {
        return 'account_error';
    }

    protected function initOAuth(): void
    {
        $this->successResponse($this->manager->getAuthorizeUrl());
    }

    protected function logout(): void
    {
        $this->manager->logout();
        $this->successResponse(['connected' => false]);
    }

    protected function getStatus(): void
    {
        $this->successResponse([
            'connected' => $this->manager->isConnected(),
            'oauth_error' => $this->manager->consumeFlashError(),
        ]);
    }
}
