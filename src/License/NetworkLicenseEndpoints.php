<?php

namespace VeronaLabs\WpPremiumSdk\License;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\Http\ApiException;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * AJAX dispatcher for the Network Admin license page of a network-activated plugin.
 *
 * Action: wp_ajax_{prefix}_network_license (capability: manage_network_options)
 * Sub-actions: get_status, save_key, remove_key, activate_all, activate_sites,
 * switch_to_network_key
 *
 * The network admin enters the key once. It is checked by activating the main site
 * with it, then stored network-wide. Other subsites activate themselves with it on
 * their next admin load only when there are seats for all of them (or, for a new
 * subsite, while seats remain); otherwise the network admin hands the seats out
 * with activate_all or activate_sites. Every subsite activates on its own address
 * and uses its own seat. Removing the key deactivates the main site now and each
 * other subsite on its next admin load.
 */
class NetworkLicenseEndpoints extends AbstractAjaxEndpoint
{
    /** At most this many subsites are listed in get_status. */
    public const SUBSITE_LIMIT = 500;

    private NetworkLicense $network;
    private KeySource $keySource;
    private LicenseManager $manager;
    private AutoActivator $autoActivator;

    /** @var callable(): LicenseManager Builds a LicenseManager for the blog switched to. */
    private $managerForCurrentBlog;

    /**
     * @param  callable(): LicenseManager  $managerForCurrentBlog  Builds a LicenseManager
     *         (with its own store and encryptor) for whichever blog is current, used
     *         inside switch_to_blog() to activate other subsites.
     */
    public function __construct(
        ClientConfig $config,
        NetworkLicense $network,
        KeySource $keySource,
        LicenseManager $manager,
        AutoActivator $autoActivator,
        callable $managerForCurrentBlog
    ) {
        parent::__construct($config);
        $this->network = $network;
        $this->keySource = $keySource;
        $this->manager = $manager;
        $this->autoActivator = $autoActivator;
        $this->managerForCurrentBlog = $managerForCurrentBlog;
    }

    protected function getActionName(): string
    {
        return 'network_license';
    }

    protected function getSubActions(): array
    {
        return [
            'get_status' => 'getStatus',
            'save_key' => 'saveKey',
            'remove_key' => 'removeKey',
            'activate_all' => 'activateAll',
            'activate_sites' => 'activateSites',
            'switch_to_network_key' => 'switchToNetworkKey',
        ];
    }

    protected function getErrorCode(): string
    {
        return 'license_error';
    }

    protected function requiredCapability(): string
    {
        return 'manage_network_options';
    }

    /**
     * The network key (masked) and where each subsite stands: whether it holds an
     * activation, its status, its seat, and its last automatic-activation error
     * (e.g. `activation_limit_reached` when the key ran out of seats), plus the seat
     * summary: subsites_total, subsites_active, subsites_waiting, seats_max (0 =
     * unlimited, null = unknown) and seats_left (null when unlimited or unknown).
     */
    protected function getStatus(): void
    {
        $subsites = $this->network->subsites(self::SUBSITE_LIMIT);

        $this->successResponse(array_merge($this->network->seatSummary($subsites), [
            'context' => 'network',
            'is_network_activated' => $this->network->isNetworkActivated(),
            'source' => $this->keySource->constantKey() !== null ? KeySource::CONSTANT : KeySource::NETWORK,
            'has_key' => $this->network->hasKey(),
            'license_key_masked' => $this->network->maskedKey(),
            'updated_at' => $this->network->updatedAt(),
            'subsites' => $this->publicSubsites($subsites),
        ]));
    }

    /**
     * Activate every subsite still waiting for a seat. A subsite activated with a key
     * of its own is not waiting and keeps that key. Refused with
     * `not_enough_seats` (carrying `needed` and `left`) when the license cannot cover
     * them all, so no subsite gets a seat by accident of order.
     *
     * @throws Exception
     */
    protected function activateAll(): void
    {
        $this->activateBlogs(NetworkLicense::waitingBlogIds($this->network->subsites(self::SUBSITE_LIMIT)));
    }

    /**
     * Body: `blog_ids` (array, or comma-separated). Activate just those subsites,
     * skipping any that is not waiting for a seat (already holding one, or activated
     * with a key of its own). Refused with `not_enough_seats` when they need more seats than are left.
     *
     * @throws Exception
     */
    protected function activateSites(): void
    {
        $known = NetworkLicense::waitingBlogIds($this->network->subsites(self::SUBSITE_LIMIT));

        $this->activateBlogs(array_values(array_intersect($known, $this->requestedBlogIds())));
    }

    /**
     * Body: `blog_ids` (array, or comma-separated). Move those subsites from a key of
     * their own to the network key: each is activated with the network key first, and
     * only once that worked is its own key's seat on its address released. Subsites
     * without a key of their own are skipped. Refused with `not_enough_seats` when
     * they need more seats than are left.
     *
     * @throws Exception
     */
    protected function switchToNetworkKey(): void
    {
        $known = [];

        foreach ($this->network->subsites(self::SUBSITE_LIMIT) as $subsite) {
            if ($subsite['has_own_key']) {
                $known[] = (int) $subsite['blog_id'];
            }
        }

        $this->activateBlogs(array_values(array_intersect($known, $this->requestedBlogIds())), true);
    }

    /**
     * @return array<int, int> The `blog_ids` in the request.
     */
    private function requestedBlogIds(): array
    {
        $raw = Request::getArray('blog_ids');

        if ($raw === []) {
            $raw = explode(',', (string) Request::get('blog_ids', ''));
        }

        return array_map('intval', $raw);
    }

    /**
     * Activate each blog in turn, inside switch_to_blog(), on its own address.
     *
     * @param  array<int, int>  $blogIds
     * @param  bool  $releaseOwnKey  Release the seat of the key each blog had before,
     *         once the network key is active there.
     *
     * @throws Exception
     */
    private function activateBlogs(array $blogIds, bool $releaseOwnKey = false): void
    {
        $this->refuseWhenUnavailable();

        $key = $this->keySource->provided()['key'] ?? null;

        if ($key === null) {
            throw new LicenseActionException(LicenseErrorCode::NOT_ACTIVATED, __('Enter the network license key first.', $this->config->textDomain()));
        }

        $summary = $this->network->seatSummary();
        $needed = count($blogIds);

        if ($summary['seats_max'] !== 0 && $summary['seats_left'] !== null && $needed > $summary['seats_left']) {
            throw new LicenseActionException(
                LicenseErrorCode::NOT_ENOUGH_SEATS,
                __('This license does not have enough free seats.', $this->config->textDomain()),
                ['needed' => $needed, 'left' => $summary['seats_left']]
            );
        }

        $results = [];

        foreach ($blogIds as $blogId) {
            $results[] = $this->activateBlog($blogId, $key, $releaseOwnKey);
        }

        $this->successResponse(array_merge($this->network->seatSummary(), ['results' => $results]));
    }

    /**
     * `released` is set only when `$releaseOwnKey` is: null when the blog had no other
     * key or its activation failed, else `{removed_remotely, error_code}`.
     *
     * @return array{blog_id: int, domain: string, activated: bool, error_code: string|null, is_counted: bool|null, released?: array{removed_remotely: bool, error_code: string|null}|null}
     */
    private function activateBlog(int $blogId, string $key, bool $releaseOwnKey = false): array
    {
        switch_to_blog($blogId);

        try {
            $manager = ($this->managerForCurrentBlog)();
            $domain = Request::normaliseDomain(Request::currentDomain());
            $previousKey = $manager->getLicenseKey();
            $released = $releaseOwnKey ? ['released' => null] : [];

            try {
                $manager->activate($key, KeySource::NETWORK);
            } catch (Exception $e) {
                $code = $e instanceof ApiException || $e instanceof LicenseActionException ? $e->getErrorCode() : LicenseErrorCode::UNKNOWN;

                return ['blog_id' => $blogId, 'domain' => $domain, 'activated' => false, 'error_code' => $code, 'is_counted' => null] + $released;
            }

            if ($releaseOwnKey && $previousKey !== null && $previousKey !== $key) {
                $released['released'] = $manager->releaseSeat($previousKey, Request::currentDomain());
            }

            $site = $manager->getLicenseData()['site'] ?? null;
            $this->network->forgetNewSite($blogId);

            // A retry record left by an earlier automatic try no longer applies.
            $store = new PremiumStore($this->config);
            if ($store->get(AutoActivator::SECTION) !== null) {
                $store->delete(AutoActivator::SECTION);
            }

            return [
                'blog_id' => $blogId,
                'domain' => $domain,
                'activated' => true,
                'error_code' => null,
                'is_counted' => is_array($site) && isset($site['is_counted']) ? (bool) $site['is_counted'] : null,
            ] + $released;
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Body: `license_key`. Activates the main site with it, then stores it for the
     * network. A key Nexus refuses (invalid, expired, wrong product …) is not
     * stored. A key that is fine but has no seat left for the main site, or a server
     * that cannot be reached, is stored, and `main_site_error_code` says why the main
     * site is not active yet.
     *
     * @throws Exception
     */
    protected function saveKey(): void
    {
        $this->refuseWhenUnavailable();

        $licenseKey = trim((string) Request::get('license_key', ''));

        if ($licenseKey === '') {
            $this->errorResponse(__('License key is required.', $this->config->textDomain()), $this->getErrorCode());

            return;
        }

        $previousKey = $this->manager->getLicenseKey();
        $mainSiteError = null;

        try {
            $this->manager->activate($licenseKey, KeySource::NETWORK);
        } catch (ApiException $e) {
            if (! $e->isTransient() && $e->getErrorCode() !== LicenseErrorCode::ACTIVATION_LIMIT_REACHED) {
                throw $e;
            }

            $mainSiteError = $e->getErrorCode();
        }

        $this->network->setKey($licenseKey);
        $this->autoActivator->reset();

        if ($mainSiteError === null && $previousKey !== null && $previousKey !== $licenseKey) {
            $this->manager->releaseSeat($previousKey, Request::currentDomain());
        }

        $this->successResponse([
            'has_key' => true,
            'license_key_masked' => $this->network->maskedKey(),
            'main_site_error_code' => $mainSiteError,
            'license' => $this->manager->getLicenseData(),
        ]);
    }

    /**
     * Remove the network key. The main site's license goes now (its seat released);
     * every other subsite's goes on its next admin load.
     *
     * @throws Exception
     */
    protected function removeKey(): void
    {
        $this->refuseWhenUnavailable();

        $this->network->deleteKey();
        $this->autoActivator->reset();

        $result = ['removed_remotely' => true, 'error_code' => null];

        if ($this->manager->getSource() === KeySource::NETWORK) {
            $result = $this->manager->deactivate();
        }

        $this->successResponse([
            'has_key' => false,
            'removed_remotely' => $result['removed_remotely'],
            'error_code' => $result['error_code'],
        ]);
    }

    /**
     * @throws LicenseActionException
     */
    private function refuseWhenUnavailable(): void
    {
        if ($this->keySource->constantKey() !== null) {
            throw new LicenseActionException(LicenseErrorCode::KEY_FROM_CONSTANT, __('The license key is set in wp-config.php. Change or delete it there.', $this->config->textDomain()));
        }

        if (! $this->network->isNetworkActivated()) {
            throw new LicenseActionException('not_network_activated', __('The plugin is not network-activated.', $this->config->textDomain()));
        }
    }

    /**
     * The subsite list without the raw license sections.
     *
     * @param  array<int, array<string, mixed>>  $subsites
     * @return array<int, array<string, mixed>>
     */
    private function publicSubsites(array $subsites): array
    {
        return array_map(static function (array $subsite): array {
            unset($subsite['license']);

            return $subsite;
        }, $subsites);
    }
}
