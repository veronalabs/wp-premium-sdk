<?php

namespace VeronaLabs\WpPremiumSdk\License;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Encryption\EncryptorInterface;
use VeronaLabs\WpPremiumSdk\Support\Request;

/**
 * The license key a network admin enters once for a network-activated plugin.
 *
 * Only the key is network-wide: it lives in a site option (`{option_key}_network`),
 * encrypted with an encryptor whose key every subsite can read. Each subsite still
 * keeps its own activation in its own option row, activates itself with this key on
 * its own address, and uses its own seat (see AutoActivator).
 *
 * Seats are not handed out in visit order: a subsite activates itself only when
 * the license has a free seat for every subsite still waiting for one, or, for a
 * subsite created after that, while any seat is left (see seatsAllowAutoActivation()).
 * Otherwise the network admin picks which subsites get the seats.
 *
 * Updates are network-wide too: one copy of the plugin, one update transient. The
 * update check uses the network key and counts as licensed when the main site's
 * activation is valid, whichever subsite asks (see mainSiteLicense()).
 */
class NetworkLicense
{
    private ClientConfig $config;

    private string $pluginBasename;

    private EncryptorInterface $encryptor;

    public function __construct(ClientConfig $config, string $pluginBasename, EncryptorInterface $encryptor)
    {
        $this->config = $config;
        $this->pluginBasename = $pluginBasename;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the host plugin is active for the whole network. Only then is the key
     * kept network-wide; a plugin activated site by site keeps one key per site.
     */
    public function isNetworkActivated(): bool
    {
        if (! is_multisite()) {
            return false;
        }

        if (function_exists('is_plugin_active_for_network')) {
            return is_plugin_active_for_network($this->pluginBasename);
        }

        // wp-admin/includes/plugin.php is not loaded on every request; read the same
        // option is_plugin_active_for_network() reads.
        $active = get_site_option('active_sitewide_plugins', []);

        return is_array($active) && isset($active[$this->pluginBasename]);
    }

    public function hasKey(): bool
    {
        return $this->getKey() !== null;
    }

    /**
     * The decrypted network key, or null when none is stored (or it cannot be read).
     */
    public function getKey(): ?string
    {
        $row = get_site_option($this->optionKey(), []);

        if (! is_array($row) || empty($row['license_key']) || ! is_string($row['license_key'])) {
            return null;
        }

        $key = $this->encryptor->decrypt($row['license_key']);

        return $key !== null && $key !== '' ? $key : null;
    }

    public function setKey(string $licenseKey): void
    {
        update_site_option($this->optionKey(), [
            'license_key' => $this->encryptor->encrypt($licenseKey),
            'updated_at' => time(),
        ]);
    }

    public function deleteKey(): void
    {
        delete_site_option($this->optionKey());
    }

    /**
     * When the key was last saved, or null.
     */
    public function updatedAt(): ?int
    {
        $row = get_site_option($this->optionKey(), []);

        return is_array($row) && isset($row['updated_at']) ? (int) $row['updated_at'] : null;
    }

    /**
     * The network key for display: every character but the last four hidden.
     */
    public function maskedKey(): ?string
    {
        $key = $this->getKey();

        if ($key === null) {
            return null;
        }

        $length = strlen($key);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', $length - 4).substr($key, -4);
    }

    /**
     * The site option holding the network key.
     */
    public function optionKey(): string
    {
        return $this->config->optionKey().'_network';
    }

    /**
     * The site option the default encryptor keeps its fallback key in, used when
     * wp-config has no salts.
     */
    public function cipherOptionKey(): string
    {
        return $this->config->optionKey().'_network_cipher';
    }

    /**
     * The site option listing subsites created after the key was entered.
     */
    public function newSitesOptionKey(): string
    {
        return $this->config->optionKey().'_network_new_sites';
    }

    /**
     * The main site's stored license section, read from its own row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function mainSiteLicense(): ?array
    {
        $row = get_blog_option((int) get_main_site_id(), $this->config->optionKey(), []);
        $license = is_array($row) ? ($row['license'] ?? null) : null;

        return is_array($license) && $license !== [] ? $license : null;
    }

    /**
     * Each subsite's license as its own option row has it, read straight from the
     * rows without asking Nexus. The raw key never leaves the row.
     *
     * @return array<int, array{blog_id: int, domain: string, holds_seat: bool, is_activated: bool, status: string, error_code: string, source: string, site: mixed, last_success_at: mixed, auto_activation_error: string|null, retry_at: int|null, license: array<string, mixed>}>
     */
    public function subsites(int $limit = 500): array
    {
        if (! is_multisite() || ! function_exists('get_sites')) {
            return [];
        }

        $list = [];

        foreach (get_sites(['number' => $limit, 'fields' => 'ids', 'archived' => 0, 'deleted' => 0, 'spam' => 0]) as $blogId) {
            $blogId = (int) $blogId;
            $row = get_blog_option($blogId, $this->config->optionKey(), []);
            $row = is_array($row) ? $row : [];
            $license = is_array($row['license'] ?? null) ? $row['license'] : [];
            $failure = is_array($row[AutoActivator::SECTION] ?? null) ? $row[AutoActivator::SECTION] : [];
            $failed = (int) ($failure['attempts'] ?? 0) > 0;
            $domain = Request::normaliseDomain((string) get_home_url($blogId));

            $list[] = [
                'blog_id' => $blogId,
                'domain' => $domain,
                'holds_seat' => $this->holdsSeat($license, $domain),
                'is_activated' => ! empty($license['license_key']),
                'status' => (string) ($license['status'] ?? ''),
                'error_code' => (string) ($license['error_code'] ?? ''),
                'source' => (string) ($license['source'] ?? ''),
                'site' => $license['site'] ?? null,
                'last_success_at' => $license['last_success_at'] ?? null,
                'auto_activation_error' => $failed ? ($failure['error_code'] ?? null) : null,
                'retry_at' => $failed ? (int) ($failure['retry_at'] ?? 0) : null,
                'license' => $license,
            ];
        }

        return $list;
    }

    /**
     * How the network stands on seats:
     * - `subsites_total`, `subsites_active` (holding an activation of the network's
     *   key on their own domain) and `subsites_waiting` (the rest);
     * - `seats_max` (0 = unlimited, null = not known yet) and `seats_left` (null when
     *   unlimited or not known), from the freshest answer any subsite got from Nexus.
     *
     * @param  array<int, array<string, mixed>>|null  $subsites  subsites(), when already read.
     * @return array{subsites_total: int, subsites_active: int, subsites_waiting: int, seats_max: int|null, seats_left: int|null}
     */
    public function seatSummary(?array $subsites = null): array
    {
        $subsites = $subsites ?? $this->subsites();
        $active = 0;
        $freshest = null;

        foreach ($subsites as $subsite) {
            if ($subsite['holds_seat']) {
                $active++;
            }

            $license = $subsite['license'];

            if ($this->isNetworkLicense($license)
                && ($freshest === null || (int) ($license['last_success_at'] ?? 0) > (int) ($freshest['last_success_at'] ?? 0))) {
                $freshest = $license;
            }
        }

        $max = $freshest !== null ? (int) ($freshest['max_activations'] ?? 0) : null;
        $left = $max !== null && $max > 0 ? max(0, $max - (int) ($freshest['activation_count'] ?? 0)) : null;

        return [
            'subsites_total' => count($subsites),
            'subsites_active' => $active,
            'subsites_waiting' => count($subsites) - $active,
            'seats_max' => $max,
            'seats_left' => $left,
        ];
    }

    /**
     * Whether `$blogId` may activate itself with the network key now: the license is
     * unlimited, has a free seat for every subsite still waiting, or — for a subsite
     * created after the key was entered — has any seat left. While the seat counts
     * are unknown (no subsite has heard from Nexus yet) only the main site may try:
     * its answer tells the others how many seats there are.
     */
    public function seatsAllowAutoActivation(int $blogId): bool
    {
        $summary = $this->seatSummary();

        if ($summary['seats_max'] === null) {
            return $blogId === (int) get_main_site_id();
        }

        if ($summary['seats_max'] === 0) {
            return true;
        }

        $left = (int) $summary['seats_left'];

        if ($left >= $summary['subsites_waiting']) {
            return true;
        }

        return $left > 0 && $this->isNewSite($blogId);
    }

    public function rememberNewSite(int $blogId): void
    {
        $ids = $this->newSites();
        $ids[] = $blogId;
        update_site_option($this->newSitesOptionKey(), array_values(array_unique($ids)));
    }

    public function forgetNewSite(int $blogId): void
    {
        $ids = $this->newSites();

        if (! in_array($blogId, $ids, true)) {
            return;
        }

        update_site_option($this->newSitesOptionKey(), array_values(array_diff($ids, [$blogId])));
    }

    public function isNewSite(int $blogId): bool
    {
        return in_array($blogId, $this->newSites(), true);
    }

    /**
     * @return array<int, int>
     */
    private function newSites(): array
    {
        $ids = get_site_option($this->newSitesOptionKey(), []);

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * A subsite holds a seat of the network key when its row carries an activation
     * that came from the network (or the wp-config constant) on its own domain.
     *
     * @param  array<string, mixed>  $license
     */
    private function holdsSeat(array $license, string $domain): bool
    {
        if (empty($license['license_key']) || ! $this->isNetworkLicense($license)) {
            return false;
        }

        $activatedOn = (string) ($license['activated_domain'] ?? '');

        return $activatedOn === '' || $activatedOn === $domain;
    }

    /**
     * @param  array<string, mixed>  $license
     */
    private function isNetworkLicense(array $license): bool
    {
        return in_array($license['source'] ?? '', [KeySource::NETWORK, KeySource::CONSTANT], true);
    }
}
