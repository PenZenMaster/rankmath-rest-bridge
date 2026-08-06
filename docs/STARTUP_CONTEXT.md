# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.6.0 (shipped, zip on CDN; not yet smoke-tested live)
**Last Commit:** d4b537a -- chore: release v3.6.0 zip

---

## Last 3 Accomplishments

1. **v3.6.0 shipped (2026-08-06, issue #14)** -- `POST /media` audited
   upload wrapper around `media_handle_sideload()`: required `alt_text` +
   `source` (allowlist, stored `_rrseo_media_source`), MIME allowlist
   checked against real file content via `wp_check_filetype_and_ext()`
   (415 on mismatch), 10MB cap (413), `is_placeholder`/`source` pairing
   (`_rrseo_media_placeholder`), `dry_run`. New `GET /media/placeholders`.
   Extracted `rr_validate_media_fields()`/`rr_validate_media_file()` as
   pure, unit-testable helpers (16 new tests, 239 -> 255). Not yet
   smoke-tested live -- next priority.

2. **v3.5.0 shipped and confirmed live (2026-08-06, issue #13)** --
   `POST /schema/{post_id}` now accepts single node (unchanged), bare
   array, or `@graph` envelope; all normalize to a canonical `@graph`
   envelope in `_rrseo_schema_graph`, per-node `@type` validation, 20-node
   cap (413). Live smoke test on kildaybaxter.com post 2090: wrote a
   Service + BreadcrumbList graph, confirmed one `<script
   type="application/ld+json">` tag renders both nodes correctly on the
   production page. Closed #13 with evidence.

3. **v3.4.1 smoke test confirmed live (2026-08-06)** -- ran the #11
   regression test against kildaybaxter.com: partial `business_facts`
   write preserved all other fields, readiness scores held steady.
   Closed issues #9, #10, #11 with evidence.

---

## Next 3 Priorities

1. **Smoke-test v3.6.0 live** -- self-update kildaybaxter.com (or
   another test site), POST /media with a real image + attach_to_post_id,
   verify alt text/source meta persisted, MIME/size rejection paths
   (415/413), and dry_run. Close #14 once confirmed, same pattern as
   #9/#10/#11/#13.

2. **#12 `POST /elementor/set-data`** -- third in the "Programmatic page
   provisioning" milestone sequence (#13 done -> #14 done -> #12 -> #15).
   Elementor confirmed always-active on target sites (user confirmed
   2026-08-06), so no inactive-plugin fallback branch needed.

3. **Resume Higgins v3.3.0 perf deployment** -- carried over across
   multiple sessions. POST the Font Awesome + Bootstrap preload/onload
   swap with `code_b64` + `priority:1`; verify PageSpeed mobile moves
   63 -> 78-85. Higgins should also eventually move to v3.6.x.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `d4b537a`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.5.0 as of the
  last live smoke test; v3.6.0 not yet deployed/tested on any live site.
  Higgins still on v3.3.0.
- Gates: phpcs clean, phpunit 255 tests / 615 assertions

**Files of note:**
- Media upload validation: `rankmath-rest-bridge.php`
  (`rr_validate_media_fields()`, `rr_validate_media_file()`,
  `rmb_media_upload()`, `rmb_media_list_placeholders()`)
- Schema graph validation: `rankmath-rest-bridge.php`
  (`rr_validate_schema()`, `rr_validate_schema_graph()`, `rmb_schema_set()`)
- `business_facts` validate/merge/render: `includes/class-rrseo-llms.php`
  (`rr_validate_llms_business_facts()`, `rr_merge_llms_business_facts()`,
  `rr_render_business_facts_lines()`)
- Readiness scoring: `includes/class-rrseo-aeo-geo.php`
  (`rr_aeo_compute_readiness()` -- `has_business_facts` rubric)
- Release hook note: run `git push` twice (zip commit lands after refspec)
- GitHub milestone #1 "Programmatic page provisioning" holds #12/#14/#15
  open and #13 closed -- #14 implemented and shipped, pending live smoke
  test before close. Sequencing noted via comments on each issue.

**Blockers:**
- None. v3.6.0 built, tested locally (phpcs/phpunit clean), and pushed;
  live smoke test still outstanding (priority 1 above).

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
