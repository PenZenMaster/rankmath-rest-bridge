# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.5.0 (shipped, zip on CDN; confirmed live on kildaybaxter.com)
**Last Commit:** cdceafa -- chore: release v3.5.0 zip

---

## Last 3 Accomplishments

1. **v3.5.0 shipped and confirmed live (2026-08-06, issue #13)** --
   `POST /schema/{post_id}` now accepts single node (unchanged), bare
   array, or `@graph` envelope; all normalize to a canonical `@graph`
   envelope in `_rrseo_schema_graph`, per-node `@type` validation, 20-node
   cap (413). `wp_head` emitter needed no changes. 11 new tests (228 ->
   239), phpcs clean. Live smoke test on kildaybaxter.com post 2090: wrote
   a Service + BreadcrumbList graph, confirmed one `<script
   type="application/ld+json">` tag renders both nodes correctly on the
   production page. Closed #13 with evidence.

2. **v3.4.1 smoke test confirmed live (2026-08-06)** -- ran the #11
   regression test against kildaybaxter.com: baseline full `business_facts`
   write, then a partial write of `{"business_facts":{"email":"..."}}`.
   Diff showed only `email` added; every other field preserved unchanged,
   readiness scores held steady. Closed issues #9, #10, #11 with evidence.

3. **v3.4.0/v3.4.1 shipped (issues #9, #10, #11)** -- AEO/GEO write
   surface: `business_facts` writes validated and merged (not replaced);
   Business Facts + Common Questions block renders into `/llms.txt` by
   default; `has_business_facts` scoring tightened.

---

## Next 3 Priorities

1. **#14 `POST /media`** -- next in the "Programmatic page provisioning"
   milestone sequence (#13 done -> #14 -> #12 -> #15). Audited media-upload
   wrapper around `media_handle_sideload()`; required `alt_text` + `source`,
   MIME allowlist, 10MB cap, `_rrseo_media_source`/`_rrseo_media_placeholder`
   meta, dry_run support.

2. **Resume Higgins v3.3.0 perf deployment** -- carried over across
   multiple sessions. POST the Font Awesome + Bootstrap preload/onload
   swap with `code_b64` + `priority:1`; verify PageSpeed mobile moves
   63 -> 78-85. Higgins should also eventually move to v3.5.x.

3. **Investigate unattended plugin self-update on Kilday Baxter** -- the
   site was already running v3.5.0 by the time this session went to
   trigger `/self-update` manually; `/check-updates` reported "latest
   version" unprompted. Plugin code has no cron/scheduled self-update
   logic of its own -- likely WP core's background auto-update picked it
   up via the bundled Plugin Update Checker's transient, meaning automatic
   updates may be enabled for this plugin on that site. Confirm with user
   whether that's intended (a bad release could auto-deploy with no human
   review window) before shipping #14/#12/#15.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `cdceafa`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.5.0 as of the
  2026-08-06 smoke test (self-updated on its own -- see priority 3 above).
  Higgins still on v3.3.0.
- Gates: phpcs clean, phpunit 239 tests / 585 assertions

**Files of note:**
- Schema graph validation: `rankmath-rest-bridge.php`
  (`rr_validate_schema()`, `rr_validate_schema_graph()`, `rmb_schema_set()`)
- `business_facts` validate/merge/render: `includes/class-rrseo-llms.php`
  (`rr_validate_llms_business_facts()`, `rr_merge_llms_business_facts()`,
  `rr_render_business_facts_lines()`)
- Readiness scoring: `includes/class-rrseo-aeo-geo.php`
  (`rr_aeo_compute_readiness()` -- `has_business_facts` rubric)
- Release hook note: run `git push` twice (zip commit lands after refspec)
- GitHub milestone #1 "Programmatic page provisioning" holds #12/#14/#15
  (open) and #13 (closed) -- sequencing noted via comments on each issue.

**Blockers:**
- None. v3.5.0 smoke-tested and confirmed live on Kilday Baxter; issue
  #13 closed. Unattended self-update behavior (priority 3) needs a
  decision, not strictly blocking.

---

## Key Context Notes

1. **`business_facts` merge semantics (v3.4.1)** -- sent keys overwrite,
   omitted keys preserved, array fields replace wholesale (not append).
   Sending `business_facts: {}` is now a no-op, not a full clear -- no
   endpoint currently clears the whole object back to schema/bloginfo
   fallback; that would be a separate feature request if ever needed.

2. **`has_business_facts` scoring change is retroactive** -- any site with
   identity-only `business_facts` (name/phone/address, no
   services/area/questions/differentiators) will see `has_business_facts`
   flip false and `llms_completeness`/`overall` drop under v3.4.0+, even
   with no write on their part. Flagged in both CHANGELOGs; not yet
   confirmed whether this affected Kilday Baxter's own live score
   post-deploy.

3. **Docs-only follow-up, not filed as an issue** -- `entity_clarity`
   scoring caps out when manual `business_facts` omits `schema_type`/
   `entity_id`, because manual config fully overrides schema-derived
   signals by design (resolver priority chain, `rr_resolve_business_facts()`).
   Worth a README callout so operators know to include those two fields.

4. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
   (lowercase); `git add` with uppercase path silently stages nothing.
