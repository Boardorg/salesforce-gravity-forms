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
│   ├── choice-cache.php            Fresh (~12 min) + stale (24h) cache for the choice lists. Cleared from Settings → Salesforce.
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
    ├── form-settings.php          Adds the "Salesforce Event Code" field to a form's settings.
    ├── field-settings.php         Adds the "Dynamic Choice Source" dropdown to a Checkbox field's settings.
    ├── dynamic-choices.php        Populates a field's choices/inputs with the live sponsor list, via the stable registry.
    ├── sponsor-field-state.php    Names, ticks and validates each sponsor box by its registry number; fills the Edit URL from the saved entry.
    ├── choice-labels.php          Swaps the form's safe choice values for their visible labels just before the Salesforce add-on sends an entry.
    ├── key-sanitizer.php          Strips stray characters from the Networking Profile Key, on save and when sent to Salesforce.
    └── resend-to-salesforce.php   Adds a "Resend to Salesforce" bulk action to the entry list, for entries the Salesforce add-on failed to send.

templates/    HTML for everything above -- one file per render_*() function, loaded via helpers/template.php.
assets/js/    JS for the form editor, enqueued by field-settings.php instead of printed inline.
```

## How the sponsor boxes stay tied to the right sponsor

Gravity Forms numbers a Checkbox field's boxes by position (the first box is
`39.1`, the second `39.2`...) when it draws them. The registry gives each
sponsor a permanent number instead (`39.5`, `39.51`, `39.8`...) so a sponsor keeps
its number as the live list changes. The two only match while the list is
exactly what it was the first time it was fetched, so `sponsor-field-state.php`
puts everything under the registry number:

- Each box is named and ticked by its registry number, so what a visitor posts
  is read back from the same place, and boxes stay ticked when the form is
  drawn again (Next / Previous, a validation error, or the Networking Profile
  Form Edit URL).
- Each posted value must be the Account Id of the sponsor it was posted for.
  Gravity Forms' own choice check (`validateState`) is switched off for these
  fields, in `dynamic-choices.php`, because it numbers by position too.
- Easy Passthrough is handed the saved picks straight from the saved entry
  (`gpep_field_values`). Left alone it reads the entry through the form as
  stored in the database, which holds an old copy of the sponsor list, so it
  only finds picks saved under a number that old copy knows.

Entries saved before this change may hold a pick under a different number than
its own sponsor's, and some picks may never have been saved. The Edit URL reads
the Account Ids without regard to the number, so it ticks whatever the entry
holds. The Salesforce add-on and Resend read through the live inputs, exactly as
before.

## Sending choice labels to Salesforce

Some Networking Profile questions store a safe value for each choice, such as
`current_focus_financial_planning`, instead of the text the visitor sees. The
Salesforce add-on turns every comma in a list of picks into a semicolon, which
would cut a label like "Data, Analytics" in two, and a safe value has no commas
to cut. Salesforce's picklists only accept the visible labels, so
`choice-labels.php` swaps each safe value back for its label just before the
add-on sends the entry:

- The fields to swap come from the Salesforce feed's field mapping, so no form
  or field IDs are listed anywhere. It runs for every feed on every form, and
  for entries sent again with Resend to Salesforce.
- Only a choice whose value starts with one of the prefixes in
  `SAFE_VALUE_PREFIXES` is swapped. Anything else (an "Other" answer, the
  sponsors' Account Ids, plain text) is sent as it is. A question whose safe
  values start with a new prefix needs that prefix added to the list.
- The picks are joined with semicolons, which is how Salesforce separates the
  picks of a multi-select. A comma inside a label is left alone.
- A safe value the form no longer has (a choice deleted after the entry was
  saved) can't be swapped and is sent as it is, so Salesforce may reject it.
- With logging turned on for Salesforce Gravity Forms, each swap writes a line
  naming the form, the form field and the Salesforce field.

## Resending entries that didn't reach Salesforce

The Gravity Forms Salesforce add-on sends each entry once, when it's submitted.
If Salesforce rejects it (a value too long for its field, a key that matches no
attendee, an outage) the add-on records the failure in the entry's notes and
never tries again.

To resend: **Forms → Entries**, tick the entries, choose **Resend to
Salesforce**, and click Apply. The action only appears on forms that have an
active Salesforce feed, for users who can edit entries.

It queues the add-on's feed again. The add-on sends in the background, so each
entry's notes show the result ("Updated … object" or the new error) within a
minute. Every entry it queues also gets a "Queued to resend to Salesforce."
note.

It skips an entry, and says why on screen, when resending could do harm:

- The add-on already sent it successfully.
- It's in the spam or trash.
- It has no usable Networking Profile Key, so Salesforce couldn't match an attendee.
- A newer entry exists for the same attendee. Resending the older one would overwrite the newer answers in Salesforce.

Up to 100 entries at a time. Fix whatever made Salesforce reject the entry first
(for example, widen a Salesforce field that was too short), or it will just fail
again.
