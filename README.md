# Salesforce Gravity Forms

Populates Gravity Forms Checkbox fields with the sponsor companies for an
event, queried directly from Salesforce using OAuth 2.0 Client Credentials
(no JWT or RSA key needed).

It also looks after the Networking Profile forms: it sends each attendee's
link to the form for their event (see "Routing Networking Profile links"),
and sets up the feeds of a new form for you (see "New forms").

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
├── admin/
│   ├── credentials-settings.php    wp-admin screen for the wp_options fallback (Settings → Salesforce).
│   └── router-settings.php         wp-admin screen for the router (Settings → NP Router): switch, forms table, link check, feed tools.
├── routing/
│   ├── settings.php                Readers for the router's options and the three notice texts. Used on the front end, so no admin code.
│   ├── key-lookup.php              Asks Salesforce which conference a Networking Profile Key belongs to; day / five-minute / outage caches.
│   ├── form-map.php                Event Code → form → page, the overrides box, and detecting which form a page shows.
│   └── router.php                  Decides (redirect, notice or leave alone) and acts, on template_redirect.
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
    ├── form-settings.php          Adds the "Salesforce Event Code" and "Networking Profile Page" fields to a form's settings.
    ├── field-settings.php         Adds the "Dynamic Choice Source" dropdown to a Checkbox field's settings.
    ├── dynamic-choices.php        Populates a field's choices/inputs with the live sponsor list, via the stable registry.
    ├── sponsor-field-state.php    Names, ticks and validates each sponsor box by its registry number; fills the Edit URL from the saved entry.
    ├── choice-labels.php          Swaps the form's safe choice values for their visible labels just before the Salesforce add-on sends an entry.
    ├── key-sanitizer.php          Strips stray characters from the Networking Profile Key, on save and when sent to Salesforce.
    ├── feed-automation.php        On duplicate or import: copies the Salesforce feed, points the Easy Passthrough feed at the new form, clears a duplicate's code and page.
    └── resend-to-salesforce.php   Adds a "Resend to Salesforce" bulk action to the entry list, for entries the Salesforce add-on failed to send.

templates/    HTML for everything above -- one file per render_*() function, loaded via helpers/template.php.
assets/js/    JS for the form editor, enqueued by field-settings.php instead of printed inline.
```

## Routing Networking Profile links

Every Networking Profile link in a Cvent email points at one page
(`https://theassemble.com/networking-profile?attendee=A-NP-0000000041`), whatever
the event. The key in the link is unique to one attendee registration, so the
router uses it to find the right form:

1. The key is cleaned the way the key cleaner does it (a stray `>` is dropped).
2. Salesforce is asked which Conference code the attendee's Cvent event has
   (`CventEvents__Attendee__c` → `CventEvents__Event__r.Conference__c`: NALES, MDWS ...).
3. The form whose **Salesforce Event Code** matches that code is the form for the
   event. There is no table to maintain; the form says which event it is for.
4. The visitor is sent to that form's page, chosen in the form's **Networking
   Profile Page** setting. Left blank, the router page itself counts, but only
   when it really shows that form.
5. An **edit link** (`?ep_token=...`) names a saved entry, not an attendee, so
   Easy Passthrough is asked which form the entry belongs to and the visitor goes
   to that form's page.

It fails closed. If the event has no form, the form has no page, the key isn't
found, or Salesforce can't be reached and nothing is cached, the visitor sees a
short notice (the wording is set on the settings screen) and never another
event's form. An unreachable Salesforce shows a 503. An attendee who opened
their link recently still gets through an outage, from the cached answer.

It watches the router page and every page a form is chosen for, so a key can't
be used on another event's page either: a NALES key on the MDWS page is sent to
the NALES page. A plain visit (no key, no token), a form submission, and
anything in wp-admin or a Gravity Forms preview are left alone. Every request it
does handle is kept out of page caches, since the answer depends on the key.

**Caches.** A key's conference is remembered for a day; a key with no event or no
Conference code yet for five minutes, so a fix in Salesforce shows up soon; and a
copy for 30 days that is read only when Salesforce can't be reached. The list of
forms is cached for an hour and emptied whenever a form is saved, trashed,
restored or switched on or off. **Clear Lookup Cache** on the settings screen
empties both.

**What the plugin's Salesforce user needs.** Read on `CventEvents__Attendee__c`
and `CventEvents__Event__c`, and on the key and Conference fields. A least-privilege
API-only user doesn't have that by default. Settings → NP Router → **Check a link**
shows the exact error if it's missing.

### Settings → NP Router

- **Router** switch. It ships **off**; links behave as they always did until it's on.
- **Router page**: the page the Cvent links point at (`/networking-profile/`).
- **Template form**: when a Networking Profile form is *imported*, its Salesforce
  feed is copied from this form.
- **Overrides**: one `name = form ID` per line, where the name is an Event Code
  (`TEST = 1`) or a single key (`A-NP-0000000041 = 2`). For test attendees: the
  TEST event has no form of its own. A key is checked first, then the event.
- **Notice wording** for the three notices.
- **Forms and pages**: every live form with an Event Code, its page, whether the
  page shows the form, its Salesforce feed, and whether its Easy Passthrough feed
  takes answers from the form itself, with anything wrong in red.
- **Check a link**: paste a link, a key or an edit link to see where the router
  would send it. It asks Salesforce live and changes nothing.
- **Copy a Salesforce feed** and **Clear Lookup Cache**.

### Switching it on

1. Deploy the plugin. Copy the new files before the main plugin file, which now
   requires them.
2. Settings → NP Router: choose the router page and the template form, add any
   overrides, and save.
3. For each form, **Form Settings → Salesforce Integration → Networking Profile
   Page**. The NALES form can stay blank when `/networking-profile/` shows it.
4. The **Forms and pages** table must show every form ready (no red).
5. **Check a link** with a NALES link, an MDWS link and a test key. The first
   check also finds out whether Salesforce lets the plugin read the Cvent objects.
6. Tick the switch and save. Clear the page cache at WP Engine once, so nobody is
   served an older copy of a link they opened before.
7. Open real links in a private window.

To switch it off, untick the switch. Nothing else needs undoing.

### Adding an event

1. In Salesforce, set **Conference** and **Conference Year** on the event's Cvent
   Event row (the router and the registration integration both need them).
2. Duplicate or import the form (see "New forms"), then set its **Salesforce Event
   Code** and **Networking Profile Page** in Form Settings, and edit its answer lists.
3. Create the page that shows the form.
4. The forms table shows when everything is in place.

One form per Event Code: if two claim the same code the oldest wins, and the table
says so.

## New forms

The Salesforce add-on refuses to copy its feed when a form is duplicated, and a
Gravity Forms import never carries it. Easy Passthrough does copy its feed, but
the copy still takes its saved answers from the original form, so an edit link
would load the wrong form's entry. `feed-automation.php` fixes both for
Networking Profile forms (forms with an Event Code):

- **Duplicate a form**: its Salesforce feed is copied, its Easy Passthrough feed
  is pointed at the new form, and its Event Code and page are cleared so the copy
  can't claim the original's event until it's set up.
- **Import a form**: its Salesforce feed is copied from the template form, and an
  Easy Passthrough feed that takes answers from another Networking Profile form is
  pointed at the new form.
- **Settings → NP Router → Copy a Salesforce feed** does the same by hand for any
  form that has none, and **Fix** in the forms table repoints an Easy Passthrough
  feed.

A Salesforce feed is only ever copied onto a form that has none, and only when
every field the feed uses is on the new form. What happened is shown as a notice
the next time an administrator opens wp-admin.

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
