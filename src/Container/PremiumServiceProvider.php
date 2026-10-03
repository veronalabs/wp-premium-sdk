<?php

namespace VeronaLabs\WpPremiumSdk\Container;

use InvalidArgumentException;
use Throwable;
use VeronaLabs\WpPremiumSdk\Account\AccountBootstrap;
use VeronaLabs\WpPremiumSdk\Account\AccountClient;
use VeronaLabs\WpPremiumSdk\Account\AccountEndpoints;
use VeronaLabs\WpPremiumSdk\Account\AccountManager;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\Encryption\SodiumEncryptor;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Http\ApiClient;
use VeronaLabs\WpPremiumSdk\License\LicenseBootstrap;
use VeronaLabs\WpPremiumSdk\License\LicenseClient;
use VeronaLabs\WpPremiumSdk\License\LicenseEndpoints;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;
use VeronaLabs\WpPremiumSdk\Module\ModuleLoader;
use VeronaLabs\WpPremiumSdk\Store\PremiumStore;
use VeronaLabs\WpPremiumSdk\Update\PluginUpdater;

/**
 * Builds the full service graph for a plugin from one ClientConfig.
 *
 * Usage:
 *
 *   $provider = new PremiumServiceProvider(
 *       config: $config,
 *       pluginBasename: 'wp-statistics-premium/wp-statistics-premium.php',
 *       encryptor: null, // defaults to SodiumEncryptor
 *   );
 *   $provider->register();
 *
 * After register(), the SDK has hooked its AJAX endpoints, WP update filter,
 * module loader, and OAuth callback handler. Individual services can still
 * be pulled from the provider for use by the host plugin's admin UI.
 *
 * The SDK registers no activation, deactivation or uninstall hooks of its own.
 * The host's uninstall.php calls uninstall() to release the seat and remove
 * what the SDK stored.
 */
class PremiumServiceProvider
{
    /** Seconds uninstall() waits for Nexus to release the seat. */
    public const UNINSTALL_TIMEOUT = 5;

    private ClientConfig $config;

    private string $pluginBasename;

    private EncryptorInterface $encryptor;

    private ApiClient $http;

    private PremiumStore $store;

    private LicenseClient $licenseClient;

    private FeatureInstaller $featureInstaller;

    private LicenseManager $licenseManager;

    private PluginUpdater $pluginUpdater;

    private LicenseEndpoints $licenseEndpoints;

    private LicenseBootstrap $licenseBootstrap;

    private AccountClient $accountClient;

    private AccountManager $accountManager;

    private AccountEndpoints $accountEndpoints;

    private AccountBootstrap $accountBootstrap;

    private ModuleLoader $moduleLoader;

    public function __construct(
        ClientConfig $config,
        string $pluginBasename,
        ?EncryptorInterface $encryptor = null
    ) {
        if ($pluginBasename === '') {
            throw new InvalidArgumentException('pluginBasename cannot be empty.');
        }

        $this->config = $config;
        $this->pluginBasename = $pluginBasename;
        $this->encryptor = $encryptor ?? new SodiumEncryptor($this->cipherOptionKey());

        $this->wire();
    }

    public function register(): void
    {
        $this->licenseBootstrap->register();
        $this->accountBootstrap->register();
        $this->moduleLoader->register();
    }

    /**
     * Remove everything the SDK stored for this site, for the host's uninstall.php.
     *
     * First, when `$releaseSeat` is true and a license is stored, it tells Nexus
     * this site no longer uses its seat — best-effort, with a short timeout, and
     * never throwing, so a slow or unreachable server cannot hold up the uninstall.
     * Then it deletes the SDK's option row (license + account sections), the
     * fallback cipher-key option, and the manifest cache and failure-backoff site
     * transients.
     *
     * OAuth `state` transients are keyed by a random token and cannot be listed
     * through the WordPress API; they expire on their own within 10 minutes.
     *
     * Needs only the autoloader and a ClientConfig: construct the provider and call
     * this, without register(). On a network, run it once per site inside
     * switch_to_blog(), with a new provider for each site.
     *
     * @return array{removed_remotely: bool, error_code: string|null} Whether Nexus
     *         released the seat (true when there was none to release or
     *         `$releaseSeat` is false).
     */
    public function uninstall(bool $releaseSeat = true): array
    {
        $result = ['removed_remotely' => true, 'error_code' => null];
        $this->store->resetCache();

        if ($releaseSeat) {
            try {
                $result = $this->licenseManager->deactivate(self::UNINSTALL_TIMEOUT);
            } catch (Throwable $e) {
                $result = ['removed_remotely' => false, 'error_code' => LicenseErrorCode::UNKNOWN];
            }
        }

        delete_option($this->config->optionKey());
        delete_option($this->cipherOptionKey());

        foreach ($this->pluginUpdater->cacheKeys() as $key) {
            delete_site_transient($key);
        }

        $this->store->resetCache();

        return $result;
    }

    public function config(): ClientConfig
    {
        return $this->config;
    }

    public function licenseManager(): LicenseManager
    {
        return $this->licenseManager;
    }

    public function accountManager(): AccountManager
    {
        return $this->accountManager;
    }

    public function pluginUpdater(): PluginUpdater
    {
        return $this->pluginUpdater;
    }

    public function featureInstaller(): FeatureInstaller
    {
        return $this->featureInstaller;
    }

    public function moduleLoader(): ModuleLoader
    {
        return $this->moduleLoader;
    }

    public function store(): PremiumStore
    {
        return $this->store;
    }

    /**
     * The option SodiumEncryptor keeps its fallback key in (used when wp-config
     * has no salts).
     */
    private function cipherOptionKey(): string
    {
        return $this->config->optionKey().'_cipher';
    }

    private function wire(): void
    {
        $this->http = new ApiClient($this->config);
        $this->store = new PremiumStore($this->config);
        $this->featureInstaller = new FeatureInstaller($this->config);

        $this->licenseClient = new LicenseClient($this->config, $this->http);
        $this->licenseManager = new LicenseManager($this->licenseClient, $this->store, $this->encryptor);
        $this->pluginUpdater = new PluginUpdater($this->config, $this->licenseClient, $this->licenseManager, $this->pluginBasename);
        $this->licenseEndpoints = new LicenseEndpoints($this->config, $this->licenseManager, $this->pluginUpdater, $this->featureInstaller);
        $this->licenseBootstrap = new LicenseBootstrap($this->config, $this->licenseEndpoints, $this->pluginUpdater);

        $this->accountClient = new AccountClient($this->config, $this->http);
        $this->accountManager = new AccountManager($this->config, $this->accountClient, $this->store, $this->encryptor);
        $this->accountEndpoints = new AccountEndpoints($this->config, $this->accountManager, $this->licenseManager, $this->accountClient);
        $this->accountBootstrap = new AccountBootstrap($this->config, $this->accountManager, $this->licenseManager, $this->accountEndpoints);

        $this->moduleLoader = new ModuleLoader($this->config, $this->licenseManager);
    }
}
