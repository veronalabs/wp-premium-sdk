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
  field (a numeric `code` is ignored), then to a code from the HTTP status
  (`rate_limited` for 429, `server_error` for 5xx), then to `unknown` — while
  keeping the server's `message` text as the Exception message.
- A code the SDK does not know is passed through unchanged.
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
| `wrong_product`            | The key exists but belongs to a different product.          | 403            | This license key is for a different product. |
| `license_expired`          | The key is expired (rejected during an action).             | 403            | This license has expired. |
| `license_suspended`        | The key is suspended (e.g. billing problem).                | 403            | This license is suspended. |
| `rate_limited`             | Too many requests from this caller. The SDK also uses it for any 429 without a code, and passes `Retry-After` on as `retry_after` (seconds). | 429 | Too many requests. Please try again shortly. |
| `token_expired`            | An account (api/v1) access token has expired.               | 401            | (handled by the SDK — see `account_expired`) |
| `server_error`             | Nexus hit an internal error. The SDK also uses it for any 5xx without a code, including an HTML error page. | 500 | The licensing server had a problem. Please try again. |
| `unknown`                  | Any other error with no specific code.                      | 4xx/5xx        | An unexpected error occurred. |

## Client-only error codes (never sent by Nexus)

Produced by the SDK itself; listed here so the contract is complete.

| `error_code`       | Cause                                              |
|--------------------|----------------------------------------------------|
| `network_error`    | WP HTTP transport failure (`WP_Error`) — server unreachable. |
| `invalid_response` | The server replied (below 500, not 429), but the body was not valid JSON. |
| `account_expired`  | The account sign-in expired during the license picker (Nexus answered 401 or `token_expired`). The SDK has already cleared the session; ask the user to sign in again. |

The HTTP status rides on the exception too (`ApiException::getHttpStatus()`,
0 when there was no answer).

### Refusals vs. transient failures

When a background check (`refreshStatus()` / `validate()`) fails, the SDK
decides what the failure means:

- **Transient** — `network_error`, `server_error`, `invalid_response`,
  `rate_limited`, or an error with no code (`unknown`): it says nothing about
  the license, so the cached license is kept and only `last_validated_at` moves.
- **Refusal** — any other code: Nexus has answered, so the answer is stored.
  The stored `status` becomes `expired` (`license_expired`), `suspended`
  (`license_suspended`), `revoked`, `disabled` (`key_disabled`) or `invalid`
  (everything else, e.g. `invalid_key`, `wrong_product`, `domain_not_allowed`),
  and `error_code` holds the code. When the body carries a `license` block, that
  is stored instead.

Failed update-manifest fetches are remembered and retried after 1h, 3h, 6h,
then every 12h at most (or later, if `Retry-After` says so).

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
| `over_limit`    | `activation_count > max_activations` (`max > 0`) — see below. | Manage activations |
| `not_activated` | No key stored on this site.                      | Activate |
| `invalid`       | `status` present but unrecognized (catch-all).   | (generic) |

### Notice precedence (only one notice shows)

Highest → lowest:

```
suspended / revoked / disabled  (→ support)
  > expired                     (→ renew)
    > over_limit                (→ manage)
      > expiring_soon           (→ renew now)
        > not_activated         (→ activate)
          > active              (none)
```

`expired` outranks `over_limit`: renewing is what fixes an expired license, and
the renew prompt must not hide behind a seat notice.

### When a license is over its limit

`activation_count` includes this site once it is activated, so 3 of 3 with this
site among them is **not** over the limit — that is a customer using exactly the
seats they paid for. Only 4 of 3 is.

When Nexus sends this site's own seat (`license.site`), that decides instead:

| `site.is_counted` | `site.active` | over_limit when |
|---|---|---|
| `false` | any | never (a development site uses no seat) |
| `true` / absent | `false` | `activation_count >= max_activations` (no seat free for this site) |
| `true` / absent | `true` / absent | `activation_count > max_activations` |

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
