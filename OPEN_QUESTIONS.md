# Open questions

Carried over from the handoff doc's "Questions to answer before
implementation starts" section. Phases 1–3 (this initial scaffold) proceed
on the doc's own recommended defaults regardless of these; none of them are
blocking until Gravity Forms wiring (phase 4+) or a production launch.

## Business semantics

1. Confirm each checkbox represents a sponsor **company**, not a sponsor
   representative. (Assumed yes — the whole `AccountId` identity decision
   depends on it.)
2. Confirm `Delegate__r.Account` is the authoritative sponsor company rather
   than the Account on `Registration__r`'s Opportunity. Spot-check a few
   sponsor rows against a report before production launch.
3. Should the label be Account name only, or include sponsorship
   tier/package?
4. If two different Account IDs have identical names, should both display,
   and how should users tell them apart? (Currently: both display, since
   dedup is keyed by ID, but nothing distinguishes them visually.)
5. What downstream system consumes the selected Account IDs — does it
   expect IDs, names, or both?

## Event configuration

6. Real event-code format and representative examples. `query-builders.php`
   currently validates against a tentative pattern (letters, digits, `_`,
   `.`, `-`, max 64 chars) — confirm or correct this before launch.
7. Does one form always represent one event, or can the event change by
   page/context?
8. Are forms duplicated for new events? Should a duplicated form keep the
   old event code or force an admin to pick a new one?

## Gravity Forms behavior (relevant once phase 4/5 starts)

9. Confirm production WordPress, PHP, Gravity Forms, Easy Passthrough, and
   Gravity Forms Salesforce Add-On versions. This checkout has Gravity Forms
   3.1.0.4, Gravity Forms Salesforce Add-On 2.0.1, and Easy Passthrough
   1.10.2 vendored for local development — confirm these match production
   before relying on version-specific behavior.
10. Which existing/new fields will use the sponsor source, and do any
    already have production entries?
11. Is Checkbox UI mandatory (given the stable-input-registry complexity it
    requires), or is an enhanced Multi Select an acceptable fallback?
12. Should an inactive-but-historically-selected sponsor show an "Inactive"
    label during editing?
13. Are these selections used in conditional logic, notifications, merge
    tags, or Salesforce feed mappings?

## Operations and security

14. Required freshness: near-real-time, 15 minutes, hourly, or manual before
    launch?
15. Expected maximum number of sponsor companies per event?
16. Which Salesforce environment(s) should local/staging/production
    WordPress point at?
17. Is a dedicated read-only integration user + External Client App already
    provisioned, or does one need to be created?
18. Should cache refresh be synchronous on a visitor request, scheduled via
    WP-Cron, manually initiated, or some combination?
19. During a total outage with no stale cache at all, should the whole form
    be blocked, or only the sponsor field?
