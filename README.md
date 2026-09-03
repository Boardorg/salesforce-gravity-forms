# Salesforce Gravity Forms

Populates Gravity Forms Checkbox fields with the sponsor companies for an
event, queried directly from Salesforce using OAuth 2.0 Client Credentials
(no JWT or RSA key needed).

## Status

Phases 1–3 are built: bootstrap/config, the Salesforce OAuth and query
client, and the sponsor query-builder and choice provider. **No Gravity
Forms UI is wired up yet** -- no form/field settings screen, no stable
checkbox registry, no admin diagnostics screen. See `OPEN_QUESTIONS.md` for
what's still open before that (or production use).

## Configuration

WP Engine (production) doesn't give us environment variables or
`wp-config.php` access. So credentials are entered through **Settings →
Salesforce** in wp-admin and stored in `wp_options`, unencrypted.

That's a real trade-off: a routine database backup now contains the secret,
which a constant never would. The secret field is write-only (never shown
again after saving), and the option isn't autoloaded.

## Architecture

```text
includes/
├── config/config.php               Reads credentials from a constant, env var, or wp_options; returns an error if any are missing.
├── admin/credentials-settings.php  wp-admin screen for the wp_options fallback (Settings → Salesforce).
├── helpers/utilities.php           Gravity Forms–aware logging (falls back to error_log) + cache-lock helpers.
├── cache/
│   ├── token-cache.php             Persists the Salesforce access token across requests (transients).
│   └── choice-cache.php            Fresh (~12 min) + stale (24h) cache for the choice lists.
├── salesforce/
│   ├── authentication.php          OAuth 2.0 Client Credentials; caches the token; stampede lock.
│   ├── client.php                  Authenticated REST query: full pagination, one retry on INVALID_SESSION_ID.
│   ├── query-builders.php          Owns the sponsor WHERE clause and event-code validation. Isolated on purpose — see the file header.
│   └── sponsor-records.php         Ties query-builders + client together to fetch raw sponsor rows.
└── providers/
    ├── choice-provider.php             What a future Gravity Forms layer (or another provider) asks for. Owns the fresh/stale caching.
    └── salesforce-sponsor-provider.php Turns raw Salesforce rows into a deduplicated choice list ({ value, label, source_id, active, metadata }), keyed by Account ID.
```

The Gravity Forms layer (phases 4–5, not built yet) should only ever call
`ChoiceProvider\get_choices( 'sponsors', $event_code )` -- it never needs to
know Salesforce is involved.
