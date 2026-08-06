# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.7.0 (shipped, zip on CDN; not yet smoke-tested live)
**Last Commit:** 1a69ac0 -- chore: release v3.7.0 zip

---

## Last 3 Accomplishments

1. **v3.7.0 shipped (2026-08-06, issue #12)** -- `POST
   /elementor/set-data` writes `_elementor_data` (wp_slash()d JSON,
   matching Elementor's own convention), `_elementor_edit_mode`,
   `_elementor_template_type`. Top-level section/container shape
   validated (422 on malformed input), widgets recursively counted,
   unrecognized `widgetType` produces a non-blocking warning (best-effort
   list, `rrseo_elementor_core_widget_types` filter) rather than
   rejecting. CSS cache cleared via `files_manager->clear_cache()`.
   `post_id` is a body field (not URL param); auth uses the standard
   `manage_options` gate, not the issue's suggested `edit_post` model --
   both deliberate, noted in the commit. 18 new tests (255 -> 273). Not
   yet smoke-tested live -- next priority, alongside #14.

2. **v3.6.0 shipped (2026-08-06, issue #14)** -- `POST /media` audited
   upload wrapper around `media_handle_sideload()`: required `alt_text` +
   `source`, MIME allowlist checked against real file content (415 on
   mismatch), 10MB cap (413), `is_placeholder`/`source` pairing,
   `dry_run`. New `GET /media/placeholders`. 16 new tests (239 -> 255).
   Not yet smoke-tested live.

3. **v3.5.0 shipped and confirmed live (2026-08-06, issue #13)** --
   `POST /schema/{post_id}` now accepts single node, bare array, or
   `@graph` envelope; all normalize to a canonical `@graph` envelope.
   Live smoke test on kildaybaxter.com post 2090 confirmed correct
   rendering. Closed #13 with evidence.

---

## Next 3 Priorities

1. **Smoke-test v3.7.0 and v3.6.0 live, in one pass** -- self-update
   kildaybaxter.com to v3.7.0, then: (a) POST /elementor/set-data with a
   small real section/widget tree against a test/scratch post, verify
   meta persisted and CSS cache clear ran; (b) POST /media with a real
   image + attach_to_post_id, verify alt/source meta persisted and the
   415/413 reject paths. Close #12 and #14 once confirmed, same pattern
   as #9/#10/#11/#13.

2. **#15 `GET /capabilities`** -- last (capstone) in the "Programmatic
   page provisioning" milestone sequence (#13 -> #14 -> #12 -> #15, all
   implemented except #15). Enumerates #12/#13/#14 plus pre-existing
   routes in one pass now that all three are real.

3. **Resume Higgins v3.3.0 perf deployment** -- carried over across
   multiple sessions. POST the Font Awesome + Bootstrap preload/onload
   swap with `code_b64` + `priority:1`; verify PageSpeed mobile moves
   63 -> 78-85. Higgins should also eventually move to v3.7.x.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `1a69ac0`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.5.0 as of the
  last live smoke test; v3.6.0/v3.7.0 not yet deployed/tested on any
  live site. Higgins still on v3.3.0.
- Gates: phpcs clean, phpunit 273 tests / 677 assertions

**Files of note:**
- Elementor set-data validation: `rankmath-rest-bridge.php`
  (`rr_validate_elementor_data()`, `rr_elementor_walk_tree()`,
  `rr_elementor_clear_css_cache()`, `rmb_elementor_set_data()`)
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
  open and #13 closed -- #12/#14 implemented and shipped, pending live
  smoke test before close. Sequencing noted via comments on each issue.

**Blockers:**
- None. v3.7.0 built, tested locally (phpcs/phpunit clean), and pushed;
  live smoke tests for both #12 and #14 still outstanding (priority 1
  above).

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
