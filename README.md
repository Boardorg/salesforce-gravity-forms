# Salesforce Gravity Forms

Populates Gravity Forms Checkbox fields with the sponsor companies for an
event, queried directly from Salesforce using OAuth 2.0 Client Credentials
(no JWT or RSA key needed).

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
├── helpers/
│   ├── utilities.php                Gravity Forms–aware logging (falls back to error_log) + cache-lock helpers.
│   └── template.php                 Includes a template file, passing it a set of variables.
├── cache/
│   ├── token-cache.php             Persists the Salesforce access token across requests (transients).
│   ├── choice-cache.php            Fresh (~12 min) + stale (24h) cache for the choice lists.
│   └── checkbox-registry.php       Keeps each sponsor's checkbox sub-input ID stable across form renders.
├── salesforce/
│   ├── authentication.php          OAuth 2.0 Client Credentials; caches the token; stampede lock.
│   ├── client.php                  Authenticated REST query: full pagination, one retry on INVALID_SESSION_ID.
│   ├── query-builders.php          Owns the sponsor WHERE clause and event-code validation. Isolated on purpose — see the file header.
│   └── sponsor-records.php         Ties query-builders + client together to fetch raw sponsor rows.
├── providers/
│   ├── choice-provider.php             What the Gravity Forms layer (or another provider) asks for. Owns the fresh/stale caching.
│   └── salesforce-sponsor-provider.php Turns raw Salesforce rows into a deduplicated choice list ({ value, label, source_id, active, metadata }), keyed by Account ID (the sponsor's company record).
└── gravity-forms/
    ├── form-settings.php   Adds the "Salesforce Event Code" field to a form's settings.
    ├── field-settings.php  Adds the "Dynamic Choice Source" dropdown to a Checkbox field's settings.
    └── dynamic-choices.php Populates a field's choices/inputs with the live sponsor list, via the stable registry.

templates/    HTML for everything above -- one file per render_*() function, loaded via helpers/template.php.
assets/js/    JS for the form editor, enqueued by field-settings.php instead of printed inline.
```
