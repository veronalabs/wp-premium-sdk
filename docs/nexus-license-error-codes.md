# Nexus license error codes

A small, stable contract between **Nexus** (the licensing backend) and the
**wp-premium-sdk** consumed by each premium plugin (wp-sms, wp-statistics, …).

## Why codes, not messages

The SDK calls `__('…', $textDomain)` with a **variable** text domain, so any
user-facing string living in the SDK is **not** extracted into either plugin's
`.pot` — it can never be translated. Therefore:

- **Nexus** returns a short, machine-readable `error_code` (and may keep its
  human `message` for logging / fallback).
- **The SDK** classifies stored license data into a state code and surfaces the
  action `error_code` from API failures — both language-neutral.
- **Each plugin** maps the code to a translatable message under its own *literal*
  text domain (`'wp-sms'`, `'wp-statistics-premium'`).

The single source of truth for the values is
[`src/License/LicenseErrorCode.php`](../src/License/LicenseErrorCode.php).

## Backward compatibility (safe to ship before Nexus changes)

The client **degrades gracefully**:

- If a response has no `error_code`, the SDK falls back to the legacy `code`
  field, then to `unknown` — while keeping the server's `message` text as the
  Exception message (today's behavior).
- A plugin's message map falls back to the server `message` (or a generic
  string) for any code it does not recognize.

So Nexus can adopt these codes incrementally; nothing breaks in the meantime.

---

## Action error codes (emitted by Nexus)

Returned on `activate` / `validate` / `deactivate` failures, as
`{ "error_code": "<code>", "message": "<human text>" }` with an HTTP status
`>= 400`. The SDK reads `error_code` (falling back to `code`).

| `error_code`               | When Nexus should emit it                                   | Suggested HTTP | Default English message |
|----------------------------|-------------------------------------------------------------|---------------:|-------------------------|
| `invalid_key`              | The key does not exist or is malformed.                     | 422            | The license key is invalid. |
| `key_disabled`             | The key exists but has been disabled.                       | 403            | This license key has been disabled. |
| `activation_limit_reached` | No activation slots remain for the key.                     | 409            | You've reached the activation limit for this license. |
| `domain_not_allowed`       | This domain may not activate the key.                       | 403            | This domain is not allowed to activate this license. |
| `license_expired`          | The key is expired (rejected during an action).             | 403            | This license has expired. |
| `license_suspended`        | The key is suspended (e.g. billing problem).                | 403            | This license is suspended. |
| `rate_limited`             | Too many requests from this caller.                         | 429            | Too many requests. Please try again shortly. |
| `server_error`             | Nexus hit an internal error.                                | 500            | The licensing server had a problem. Please try again. |
| `unknown`                  | Any other error with no specific code.                      | 4xx/5xx        | An unexpected error occurred. |

## Client-only error codes (never sent by Nexus)

Produced by the SDK itself; listed here so the contract is complete.

| `error_code`       | Cause                                              |
|--------------------|----------------------------------------------------|
| `network_error`    | WP HTTP transport failure (`WP_Error`) — server unreachable. |
| `invalid_response` | The server replied, but the body was not valid JSON. |

---

## License state codes (computed by the SDK)

`LicenseManager::classify()` derives one of these from **stored** license data
(`status`, `expires_at`, `activation_count`, `max_activations`). Nexus drives
them indirectly via the `status` it returns on `validate`/`get_status`
(`active`, `expired`, `suspended`, `revoked`, `disabled`) plus the
expiry/activation fields.

| state code      | Meaning                                          | Plugin CTA |
|-----------------|--------------------------------------------------|------------|
| `active`        | Valid and within its window.                     | none |
| `expiring_soon` | Active, expires within 14 days (+ `days_remaining`). | Renew now |
| `expired`       | Past expiry, or `status: expired`.               | Renew |
| `suspended`     | `status: suspended`.                             | Contact support |
| `revoked`       | `status: revoked`.                               | Contact support |
| `disabled`      | `status: disabled`.                              | Contact support |
| `over_limit`    | `activation_count >= max_activations` (`max > 0`). | Manage activations |
| `not_activated` | No key stored on this site.                      | Activate |
| `invalid`       | `status` present but unrecognized (catch-all).   | (generic) |

### Notice precedence (only one notice shows)

Highest → lowest:

```
suspended / revoked / disabled  (→ support)
  > over_limit                  (→ manage)
    > expired                   (→ renew)
      > expiring_soon           (→ renew now)
        > not_activated         (→ activate)
          > active              (none)
```

### Expiry thresholds

Warn when `days_remaining <= 14`, escalating at buckets **14 / 7 / 3 / 1** (the
plugin re-arms a dismissed reminder at the next bucket).

```
days_remaining = ceil((strtotime(expires_at) - time()) / 86400)
```

An empty `expires_at` is a **lifetime** license: never `expiring_soon`/`expired`.

---

> Keep this file in sync across plugins that vendor the SDK. The canonical copy
> lives in the SDK repo; mirrored copies live under each plugin's `docs/`.
