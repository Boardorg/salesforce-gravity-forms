# BoardMC Salesforce Gravity Forms

Populates one or more Gravity Forms Checkbox fields with the sponsor
companies for the event assigned to that form, queried directly from
Salesforce (OAuth 2.0 Client Credentials — no JWT/RSA key involved).

## Status

Phases 1–3 of the implementation plan are built: bootstrap/config,
Salesforce OAuth + paginated query client, and the sponsor query-builder /
normalization provider. **No Gravity Forms UI is wired up yet** — there is no
form/field settings screen, no stable checkbox-input registry, and no admin
diagnostics screen. See `OPEN_QUESTIONS.md` for what's still unresolved
before that work (or production use) can start.

## Configuration

Production runs on WP Engine's standard managed WordPress plans, which offer
neither environment variables nor deploy-time control over `wp-config.php`
(the only access available there is SFTP and phpMyAdmin) — so, as an
explicit decision and a deliberate departure from the more common "never
store these in `wp_options`" guidance, credentials are entered through
**Settings → BoardMC Salesforce** in wp-admin and stored, unencrypted, in
`wp_options`.

That trade-off was made knowingly: it's a wider blast radius than a
constant would be (a routine database backup/export now contains the
secret, where it otherwise wouldn't), accepted because SFTP/phpMyAdmin are
the only channels this environment actually provides. The client secret
field is write-only — it's never redisplayed once saved, only "configured"
or "not configured" — and the option is stored non-autoloaded.

A `wp-config.php` constant or environment variable of the matching name, if
defined, always takes precedence over the wp_admin-entered value — so a
VIP-hosted or local environment (like this plugin's dev sandbox) can still
configure it the original way instead:

| Constant | Purpose |
|---|---|
| `BOARDMC_SFGF_SALESFORCE_LOGIN_URL` | Salesforce login/base URL. Environment-specific — point a local/staging site at a sandbox. |
| `BOARDMC_SFGF_SALESFORCE_CLIENT_ID` | Client ID for a dedicated Salesforce External Client App / Connected App. Use a least-privilege integration user — do not reuse a human user's credentials or the existing app's Connected App. |
| `BOARDMC_SFGF_SALESFORCE_CLIENT_SECRET` | Client secret for the same app. |
| `BOARDMC_SFGF_SALESFORCE_API_VERSION` | Optional. Salesforce REST API version. Defaults to `59.0`, matching the existing app. |

## Architecture

```text
includes/
├── config/config.php               Reads the constants above (or their wp_options fallback); fails loudly (WP_Error) if incomplete.
├── admin/credentials-settings.php  wp-admin screen for the wp_options fallback (Settings → BoardMC Salesforce).
├── helpers/utilities.php           Gravity Forms–aware logging (falls back to error_log) + cache-lock helpers.
├── cache/
│   ├── token-cache.php             Persists the Salesforce access token across requests (transients).
│   └── choice-cache.php            Fresh (~12 min) + stale (24h) cache for normalized choice lists.
├── salesforce/
│   ├── authentication.php          OAuth 2.0 Client Credentials; caches the token; stampede lock.
│   ├── client.php                  Authenticated REST query: full pagination, one retry on INVALID_SESSION_ID.
│   ├── query-builders.php          Owns the sponsor WHERE clause and event-code validation. Isolated on purpose — see the file header.
│   └── sponsor-records.php         Ties query-builders + client together to fetch raw sponsor rows.
└── providers/
    ├── choice-provider.php             The contract a future Gravity Forms layer (or a future proxy/API provider) consumes. Owns the fresh/stale cache policy.
    └── salesforce-sponsor-provider.php Normalizes raw Salesforce rows into deduplicated { value, label, source_id, active, metadata } choices, keyed by Account ID.
```

The Gravity Forms layer (phases 4–5, not built yet) is meant to only ever
call `ChoiceProvider\get_choices( 'sponsors', $event_code )` — it should
never need to know Salesforce is involved.
