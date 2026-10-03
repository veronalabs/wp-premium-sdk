# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `PremiumServiceProvider::uninstall(bool $releaseSeat = true)` for the host's `uninstall.php`: releases this site's seat on Nexus (best-effort, 5-second timeout, never throws), then deletes the option row, the `{option_key}_cipher` fallback key and the manifest cache transients. The SDK still registers no activation, deactivation or uninstall hooks.
- `get_status` returns `state`, the `LicenseManager::classify()` result. (#13)
- License sub-actions `list_sites` and `remove_site`, working on the license key: list the sites on the license (fetched fresh with this site's domain, each flagged `this_site`) and release another site's seat. This site is refused with code `this_site`. (#14)
- Stored license data gains `license_id`, `manage_url`, `upgrade_url`, `buyer`, `sites` and `site` when Nexus sends them; older servers keep working without them, and a reply that leaves them out or sends null keeps the last known value. The `renewal` block passes through the new `is_trial`, `trial_ends_at`, `grace_ends_at` and `auto_renews` fields. (#14)
- `last_success_at` on the stored license: when Nexus last actually answered. `last_validated_at` stays the last attempt. (#12)
- Error codes `wrong_product`, `token_expired` and the client-only `account_expired`. (#20, #10)
- `ApiException::getHttpStatus()`, `getRetryAfter()` and `isTransient()`; `LicenseErrorCode::isTransient()`. (#20)
- `ActivationVetoedException`, thrown when the `wp_premium_sdk/activation_gate` filter vetoes an activation, reporting whether the seat was handed back. (#11)
- AJAX errors carry `retry_after` when rate limited. (#20)
- `CHANGELOG.md`.
- License key from a wp-config constant: `ClientConfig` option `license_key_constant` names it (e.g. `WP_STATISTICS_LICENSE_KEY`). On `admin_init` the SDK activates that key when the site has no license, another key, or an activation on another domain, waiting 1h, 6h, then 24h between failed tries (longer per `Retry-After`). `get_status` reports `source` (`manual`, `constant` or `network`) and `auto_activation` (the last failed try: attempts, times, error code). `activate` and `deactivate` refuse with `key_from_constant`; deleting the constant removes the license on the next admin load. The key is never logged or kept in the retry record. (#19)
- `activated_domain` on the stored license (normalised), learned at activation, or from the next successful domain check for a license stored before. `get_status` reports `domain_changed: {was, now}` when this site's domain differs. New sub-action `move_license` activates the current domain, then releases the old one only once that worked, reporting each step; the old domain is released by default only when Nexus counts the new one (`license.site.is_counted`), and `release_old` overrides that. `LicenseManager::domainChange()`, `moveLicense()`, `getSource()`. (#18)
- Installing the licensed tier's package: `ClientConfig` option `installed_tier`; `get_status` reports `tier_mismatch: {installed, licensed}` from the cached manifest's `tier_slug`; new sub-action `install_tier_package` (needs `install_plugins`) fetches the manifest fresh and installs its package with `Plugin_Upgrader` when the tier differs, then clears the manifest cache. It answers `file_mods_disabled` or `filesystem_credentials_needed` instead of trying or prompting, and honours a running rate-limit wait. `PluginUpdater::cachedManifest()`, `fetchPackageManifest()`, `rateLimitedFor()`. (#15)
- One key for a network-activated plugin: the network admin enters it once (new endpoint `wp_ajax_{prefix}_network_license`: `get_status`, `save_key`, `remove_key`, capability `manage_network_options`); it is stored encrypted in the site option `{option_key}_network`. Each subsite activates itself with it on its own address and seat, keeping its activation in its own row; `get_status` reports `context` (`network` / `site`) and `is_network_managed`, and a subsite's `activate`, `deactivate`, `remove_site` and `move_license` refuse with `network_managed`. When the plugin becomes network-activated, the main site's stored key is adopted as the network key. Removing the network key deactivates each subsite on its next admin load. (#17)
- Seats on a network are not handed out in visit order: a subsite activates itself only when the license has a free seat for every subsite still waiting (or is unlimited), or, for a subsite created after the key was entered, while any seat is left; until the seat count is known only the main site tries. Otherwise the network admin chooses with the new `activate_all` (refused with `not_enough_seats`, `needed` and `left` when short) and `activate_sites` (`blog_ids`). The network `get_status` reports `subsites_total`, `subsites_active`, `subsites_waiting`, `seats_max`, `seats_left`, and `holds_seat` per subsite. Subsites get no seat-shortfall state or notice: their `auto_activation` is always null. (#17)
- Network-wide updates: on a network-activated plugin, the update check and manifest call use the network key and count as licensed when the main site's activation is valid, whichever subsite triggers them, so updates no longer depend on that subsite's seat. (#7, #17)
- The account sign-in belongs to the WordPress user who started it (`user_id`); other admins see `connected: false` and their `fetch_licenses`, `activate_license` and `logout` answer `sign_in_other_user`. The exchange sends `device_name` (this site's domain). (#10)
- `LicenseManager::onActivated()` and `LicenseManager::isValidLicenseData()`; error codes `sign_in_other_user` and `not_enough_seats`.
- `SodiumEncryptor` takes `$networkWide`, keeping its no-salts fallback key in a site option, so a value it encrypts reads back on every subsite. (#17)
- Error codes `key_from_constant`, `network_managed`, `domain_unchanged`, `file_mods_disabled`, `filesystem_credentials_needed`, `installed_tier_unknown`, `licensed_tier_unknown`, `package_unavailable`, `install_failed`, carried by the new `LicenseActionException`.
- `PremiumServiceProvider` accessors `keySource()`, `networkLicense()`, `autoActivator()`, `tierPackageInstaller()`.

### Changed

- **Every subsite of a multisite network counts as its own site**, whether the plugin is network-activated or not. `Request::currentDomain()` always uses `home_url()`, so `domain` and `site_url` on activation always describe the same subsite. (#7, #8)
- `classify()`: `over_limit` only when `activation_count > max_activations` (3 of 3 with this site among them is `active`), and `expired` now outranks `over_limit`. When Nexus sends `site.is_counted` / `site.active`, they decide. (#9)
- `refreshStatus()` and `validate()` store a refusal from Nexus (expired, suspended, revoked, disabled, invalid key, wrong product, domain not allowed …) instead of keeping a cached `active` status. The cached license is kept only for failures that say nothing about it: network error, server error, unreadable reply, rate limited, or an error with no code. (#6)
- A failed update-manifest fetch is remembered and retried after 1h, 3h, 6h, then every 12h at most (or later, per `Retry-After`), instead of on every update check. `flush()` and a forced check clear or skip the wait. (#6)
- `LicenseManager::deactivate()` returns `['removed_remotely' => bool, 'error_code' => ?string]` instead of `true`, and the `deactivate` sub-action passes both on. The local license is still always removed. (#11)
- The account sign-in is a one-time step: once a license is activated through it, the token is revoked on Nexus (best-effort, 5-second timeout, never failing the activation) and the session (token, user, pending choice, flash error) is deleted. `isConnected()` now means a sign-in is in progress and turns false when the token expires (Nexus's expiry, else 24 hours). A 401 or `token_expired` during the picker clears the session and fails with `account_expired`. (#10)
- `ApiClient` error mapping: a 429 without a code is `rate_limited`, a 5xx without a code or with an HTML error page is `server_error`, `error_code` is read before the legacy `code` (a null or numeric value is skipped), and unknown server codes still pass through. The exception's code is now the HTTP status. (#20)
- A fresh answer from Nexus without an `error_code` clears the stored one, so an old code no longer outlives a recovery.
- The account session is deleted on every way out of the picker: any successful activation (by key too, including a constant or network key), signing out, and passing its `expires_at`, checked on every read rather than only when Nexus answers 401. (#10)
- `uninstall()` also deletes the network key and its fallback cipher key on a multisite. (#17)
- `LicenseEndpoints` and `LicenseBootstrap` take the new services in their constructors; `PremiumServiceProvider` wires them. `AbstractAjaxEndpoint` gains `requiredCapability()`. `LicenseManager::releaseSeat()` is public.
- README: fixed the non-existent `deactivateAndCleanup()` and `removeAll()` examples, documented `uninstall()`, the sign-in flow, the new sub-actions and the full data model. (#21)

### Fixed

- On a network, a subsite activated with a key of its own before the plugin was network-activated keeps it. `activate_all`, `activate_sites` and the subsite's own admin load no longer replace it with the network key (which left its seat on the old key taken), and it no longer counts as waiting. The network `get_status` reports `has_own_key` per subsite and `subsites_own_key` in the seat summary. The new network sub-action `switch_to_network_key` (`blog_ids`) moves such subsites to the network key on purpose: it activates the network key first, then releases the own key's seat, reporting `released` per subsite. (#24)
- `Request::normaliseDomain()` drops any port, not just `:80` and `:443`, matching `currentDomain()`. A network subsite on a site served on a port (e.g. `localhost:8890`) now counts as holding the seat it activated. (#25)
- `PremiumServiceProvider::uninstall()` also deletes the network's new-sites list (`{option_key}_network_new_sites`). (#26)

### Deprecated

- `Request::useNetworkLicenceFor()` does nothing and will be removed in a later release. (#7)

### Removed

- The stored `refresh_token`; Nexus does not issue one. (#10)
- The `network_home_url()` branch of `Request::currentDomain()`. (#7)

### Host changes needed

- **wp-statistics-premium**: drop the `Request::useNetworkLicenceFor(...)` call in `premium/src/Container/PremiumServiceProvider.php`; it is now a no-op. Its `Test_NetworkLicenceIdentity` expects one seat per network and will fail against this SDK.
- Hosts that copy `classify()`'s precedence should rank `expired` above `over_limit`: wp-statistics-premium `premium/src/Service/Admin/Notices.php` (the `PRIORITY` map and its comment) and the comment in wp-sms-premium's copy.
- Hosts should call `$sdk->uninstall()` from their `uninstall.php`, once per site on a network.
- Hosts that read `deactivate()` as a bool, or the `deactivate` response as `{removed}` only, should read `removed_remotely` / `error_code` and warn when the seat could not be released.
- Map the new codes in the host's message table: `wrong_product`, `account_expired`, and `rate_limited` with `retry_after`.
- **New config**: pass `license_key_constant` (e.g. `'WP_STATISTICS_LICENSE_KEY'`) and `installed_tier` (the tier the build ships as) in `ClientConfig`. Map `key_from_constant` to "The key is set in wp-config.php. Delete it there to remove it." and hide Remove when `source` is `constant`. (#19, #15)
- **License page**: show `domain_changed` with "Move license here" (`move_license`), saying when `activated.is_counted` is false that the new domain uses no seat; show `tier_mismatch` with "Install {licensed} now" (`install_tier_package`), mapping `file_mods_disabled` and `filesystem_credentials_needed`. (#18, #15)
- **Network Admin page needed** (#17): a network-admin license screen on `network_admin_menu` that uses `wp_ajax_{prefix}_network_license`: enter / remove the key, show "12 of 40 subsites active · this license allows 3 · Upgrade" from the seat summary, list subsites with `holds_seat`, and offer "Activate on all (needed, left)" (`activate_all`, mapping `not_enough_seats`) and a pick list (`activate_sites`). On subsites, read `is_network_managed` and show "Managed by your network admin" without Activate/Remove and without any seat notice; map `network_managed`.
- **Account sign-in** (#10): map `sign_in_other_user`; when `get_status` says not connected, show the normal activation screen even if another admin is mid-sign-in.
- The manifest request still sends the license key in the query string, so it can land in server and proxy logs; moving it to a header needs Nexus support first. (#15)
- The account `get_status` `connected` / `logged_in` flags now go false after activation; the license page should read license state from the license endpoints, not from the account being connected.
