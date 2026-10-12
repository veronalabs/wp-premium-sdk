<?php

namespace VeronaLabs\WpPremiumSdk\Account;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * AJAX dispatcher for account / OAuth actions.
 *
 * Action: wp_ajax_{prefix}_account
 * Sub-actions: init_oauth, logout, get_status, fetch_licenses, activate_license,
 *              remove_site
 *
 * License keys never reach the browser: licenses go out without them (an `id` and
 * `license_key_masked` instead), and activate_license / remove_site take that
 * `license_id` and look the key up again in the signed-in user's account.
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
            'remove_site' => 'removeSite',
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
        $error = $this->manager->consumeFlashErrorDetail();

        $this->successResponse([
            'logged_in' => $this->manager->isConnected(),
            'connected' => $this->manager->isConnected(),
            'user' => $this->manager->getUser(),
            'oauth_error' => $error['message'] ?? null,
            'oauth_error_code' => $error['code'] ?? null,
            'oauth_error_extra' => $error['extra'] ?? null,
            'pending_choice' => $this->manager->getPendingChoice(),
        ]);
    }

    /**
     * @throws Exception
     */
    protected function fetchLicenses(): void
    {
        $licenses = $this->accountLicenses();

        if ($licenses === null) {
            return;
        }

        $this->successResponse(['licenses' => $this->manager->publicLicenses($licenses)]);
    }

    /**
     * @throws Exception
     */
    protected function activateLicense(): void
    {
        $licenseId = (string) Request::get('license_id', '');
        // A key the user typed is still accepted; the picker sends license_id.
        $licenseKey = $licenseId === '' ? (string) Request::get('license_key', '') : $this->keyForLicenseId($licenseId);

        if ($licenseKey === null) {
            return;
        }

        if ($licenseKey === '') {
            $this->errorResponse(
                __('License key is required.', $this->config->textDomain()),
                $this->getErrorCode()
            );

            return;
        }

        // Replaces any key stored before, handing its seat on this domain back.
        $result = $this->licenseManager->replaceKey($licenseKey);

        // The sign-in has done its job; from here on the license key is enough.
        $this->manager->endSignIn();

        $this->successResponse(['license' => $result['license'], 'released' => $result['released']]);
    }

    /**
     * The capability remove_site needs on top of the endpoint's own. The sign-in
     * belongs to the site, so any admin can use it; releasing a seat touches the
     * customer's other sites, so it is kept to admins who may also install plugins
     * (single-site admins can, multisite subsite admins cannot).
     */
    public const REMOVE_SITE_CAPABILITY = 'install_plugins';

    /**
     * Free a seat for this site: release `domain` from the signed-in user's license
     * `license_id`, then return the account's licenses again. This site is refused.
     * Needs REMOVE_SITE_CAPABILITY; without it the answer is `forbidden`.
     *
     * @throws Exception
     */
    protected function removeSite(): void
    {
        if (! current_user_can(self::REMOVE_SITE_CAPABILITY)) {
            $this->errorResponse(
                __('You do not have permission to remove sites from this license.', $this->config->textDomain()),
                'forbidden'
            );

            return;
        }

        $licenseId = (string) Request::get('license_id', '');
        $domain = (string) Request::get('domain', '');

        if ($licenseId === '' || $domain === '') {
            $this->errorResponse(
                __('License and site domain are required.', $this->config->textDomain()),
                $this->getErrorCode()
            );

            return;
        }

        if ($this->licenseManager->isThisSite($domain)) {
            $this->errorResponse(__('To remove this site, deactivate the license instead.', $this->config->textDomain()), 'this_site');

            return;
        }

        $licenseKey = $this->keyForLicenseId($licenseId);

        if ($licenseKey === null) {
            return;
        }

        $result = $this->licenseManager->releaseSeat($licenseKey, $domain);

        if (! $result['removed_remotely']) {
            throw new ApiException(
                __('Could not remove the site from the license.', $this->config->textDomain()),
                (string) $result['error_code']
            );
        }

        $licenses = $this->accountLicenses();

        if ($licenses === null) {
            return;
        }

        if ($this->manager->getPendingChoice() !== null) {
            $this->manager->setPendingChoice($licenses);
        }

        $this->successResponse([
            'removed' => $domain,
            'licenses' => $this->manager->publicLicenses($licenses),
        ]);
    }

    /**
     * The key of the signed-in user's license `$licenseId`, looked up in the
     * account; null when the response is already sent.
     *
     * @throws Exception
     */
    private function keyForLicenseId(string $licenseId): ?string
    {
        $licenses = $this->accountLicenses();

        if ($licenses === null) {
            return null;
        }

        $key = $this->manager->findLicenseKey($licenses, $licenseId);

        if ($key === null) {
            $this->errorResponse(
                __('That license is not on your account.', $this->config->textDomain()),
                $this->getErrorCode()
            );
        }

        return $key;
    }

    /**
     * The signed-in user's licenses, keys included (server-side only), or null when
     * there is no usable sign-in and the error response is sent.
     *
     * @throws Exception
     *
     * @return array<int, mixed>|null
     */
    private function accountLicenses(): ?array
    {
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

            return null;
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

            return null;
        }

        $licenses = $response['data'] ?? $response['licenses'] ?? [];

        return is_array($licenses) ? $licenses : [];
    }
}
