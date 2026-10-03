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

### Changed

- **Every subsite of a multisite network counts as its own site**, whether the plugin is network-activated or not. `Request::currentDomain()` always uses `home_url()`, so `domain` and `site_url` on activation always describe the same subsite. (#7, #8)
- `classify()`: `over_limit` only when `activation_count > max_activations` (3 of 3 with this site among them is `active`), and `expired` now outranks `over_limit`. When Nexus sends `site.is_counted` / `site.active`, they decide. (#9)
- `refreshStatus()` and `validate()` store a refusal from Nexus (expired, suspended, revoked, disabled, invalid key, wrong product, domain not allowed …) instead of keeping a cached `active` status. The cached license is kept only for failures that say nothing about it: network error, server error, unreadable reply, rate limited, or an error with no code. (#6)
- A failed update-manifest fetch is remembered and retried after 1h, 3h, 6h, then every 12h at most (or later, per `Retry-After`), instead of on every update check. `flush()` and a forced check clear or skip the wait. (#6)
- `LicenseManager::deactivate()` returns `['removed_remotely' => bool, 'error_code' => ?string]` instead of `true`, and the `deactivate` sub-action passes both on. The local license is still always removed. (#11)
- The account sign-in is a one-time step: once a license is activated through it, the token is revoked on Nexus (best-effort, 5-second timeout, never failing the activation) and the session (token, user, pending choice, flash error) is deleted. `isConnected()` now means a sign-in is in progress and turns false when the token expires (Nexus's expiry, else 24 hours). A 401 or `token_expired` during the picker clears the session and fails with `account_expired`. (#10)
- `ApiClient` error mapping: a 429 without a code is `rate_limited`, a 5xx without a code or with an HTML error page is `server_error`, `error_code` is read before the legacy `code` (a null or numeric value is skipped), and unknown server codes still pass through. The exception's code is now the HTTP status. (#20)
- A fresh answer from Nexus without an `error_code` clears the stored one, so an old code no longer outlives a recovery.
- README: fixed the non-existent `deactivateAndCleanup()` and `removeAll()` examples, documented `uninstall()`, the sign-in flow, the new sub-actions and the full data model. (#21)

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
- The account `get_status` `connected` / `logged_in` flags now go false after activation; the license page should read license state from the license endpoints, not from the account being connected.
