# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.8.0 (shipped, zip on CDN; not yet smoke-tested live)
**Last Commit:** 67712a3 -- chore: release v3.8.0 zip

---

## Last 3 Accomplishments

1. **v3.8.0 shipped (2026-08-06, issue #15)** -- `GET /capabilities`,
   capstone of the "Programmatic page provisioning" milestone. Returns
   plugin_version, wp_version, host state (elementor_active,
   elementor_pro_active, rank_math_active), a capabilities map keyed by
   stable dotted identifiers (schema.write.graph, elementor.set_data,
   media.upload, etc.) with available/route/since, allowed_schema_types,
   audit_log_enabled. Pure read, `Cache-Control: public, max-age=60`.
   `since` is `null` for routes predating the earliest tracked CHANGELOG
   entry (v2.11.3) rather than guessed. 7 new tests (273 -> 280). All 4
   milestone issues (#12-#15) now implemented; none live-smoke-tested yet.

2. **v3.7.0 shipped (2026-08-06, issue #12)** -- `POST
   /elementor/set-data` writes `_elementor_data`/`_elementor_edit_mode`/
   `_elementor_template_type`. Top-level shape validated (422), widgets
   recursively counted, unrecognized `widgetType` warns rather than
   rejects. CSS cache cleared via `files_manager->clear_cache()`. 18 new
   tests (255 -> 273).

3. **v3.6.0 shipped (2026-08-06, issue #14)** -- `POST /media` audited
   upload wrapper: required `alt_text` + `source`, MIME allowlist checked
   against real file content (415), 10MB cap (413), `is_placeholder`/
   `source` pairing, `dry_run`. New `GET /media/placeholders`. 16 new
   tests (239 -> 255).

---

## Next 3 Priorities

1. **Smoke-test v3.8.0 live (covers #12, #14, #15 in one pass)** --
   self-update kildaybaxter.com to v3.8.0, then: (a) GET /capabilities,
   confirm the three new capabilities report `available: true` with
   correct `since`; (b) POST /elementor/set-data with a small real
   section/widget tree against a test/scratch post, verify meta persisted
   and CSS cache clear ran; (c) POST /media with a real image +
   attach_to_post_id, verify alt/source meta and the 415/413 reject
   paths. Close #12/#14/#15 once confirmed, same pattern as #9/#10/#11/#13.
   This also closes out the "Programmatic page provisioning" milestone
   entirely (#13 already closed).

2. **Resume Higgins v3.3.0 perf deployment** -- carried over across
   multiple sessions. POST the Font Awesome + Bootstrap preload/onload
   swap with `code_b64` + `priority:1`; verify PageSpeed mobile moves
   63 -> 78-85. Higgins should also eventually move to v3.8.x.

3. **Telemetry verdict review** -- `rrc-telemetry.php` collecting since
   2026-07-06, trustworthy from ~2026-07-13 (now well past). Kill switch:
   `RRC_PUA_DISABLE` in wp-config. Carried over, not yet actioned.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `67712a3`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.5.0 as of the
  last live smoke test; v3.6.0/v3.7.0/v3.8.0 not yet deployed/tested on
  any live site. Higgins still on v3.3.0.
- Gates: phpcs clean, phpunit 280 tests / 799 assertions

**Files of note:**
- Capabilities map: `rankmath-rest-bridge.php`
  (`rr_get_capabilities_map()`, `rmb_capabilities_get()`)
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
  open and #13 closed -- all 4 now implemented and shipped (v3.5.0-
  v3.8.0), pending one combined live smoke test before closing the rest.

**Blockers:**
- None. v3.8.0 built, tested locally (phpcs/phpunit clean), and pushed;
  live smoke test covering #12/#14/#15 still outstanding (priority 1
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
