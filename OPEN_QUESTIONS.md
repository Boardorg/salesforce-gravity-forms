# Open questions

Carried over from the handoff doc's "Questions to answer before
implementation starts" section. Phases 1–3 (this initial scaffold) proceed
on the doc's own recommended defaults regardless of these; none of them are
blocking until Gravity Forms wiring (phase 4+) or a production launch.

## Gravity Forms behavior (relevant once phase 4/5 starts)

1. Confirm production WordPress, PHP, Gravity Forms, Easy Passthrough, and
   Gravity Forms Salesforce Add-On versions. This checkout has Gravity Forms
   3.1.0.4, Gravity Forms Salesforce Add-On 2.0.1, and Easy Passthrough
   1.10.2 vendored for local development — confirm these match production
   before relying on version-specific behavior.
2. Which existing/new fields will use the sponsor source, and do any
   already have production entries?
3. Is Checkbox UI mandatory (given the stable-input-registry complexity it
   requires), or is an enhanced Multi Select an acceptable fallback?
4. Should an inactive-but-historically-selected sponsor show an "Inactive"
   label during editing?
5. Are these selections used in conditional logic, notifications, merge
   tags, or Salesforce feed mappings?
6. Two Account IDs that share the same Account Name will both display as
   separate checkboxes with identical labels — should there be a way to
   tell them apart visually (e.g. appending part of the Account ID), or is
   that acceptable as-is?

## Operations and security

7. Required freshness: near-real-time, 15 minutes, hourly, or manual before
   launch?
8. Expected maximum number of sponsor companies per event?
9. Which Salesforce environment(s) should local/staging/production
   WordPress point at?
10. Is a dedicated read-only integration user + External Client App already
    provisioned, or does one need to be created?
11. Should cache refresh be synchronous on a visitor request, scheduled via
    WP-Cron, manually initiated, or some combination?
12. During a total outage with no stale cache at all, should the whole form
    be blocked, or only the sponsor field?
