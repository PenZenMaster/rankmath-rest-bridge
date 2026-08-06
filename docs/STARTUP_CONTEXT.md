# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.8.0 (shipped, zip on CDN; confirmed live on kildaybaxter.com)
**Last Commit:** 48b6f0b -- chore: update startup context for v3.8.0 (issue #15)

---

## Last 3 Accomplishments

1. **"Programmatic page provisioning" milestone closed (2026-08-06)** --
   all 4 issues (#12 elementor set-data, #13 schema graph, #14 media
   upload, #15 capabilities) implemented, shipped v3.5.0-v3.8.0, and
   live-verified on kildaybaxter.com in one combined smoke test: created
   a scratch draft page (2112), wrote a real Elementor section/widget
   tree (persistence confirmed byte-for-byte via the page's own REST
   meta), uploaded a real test image with full reject-path coverage
   (400/422/415), confirmed GET /capabilities reports accurate
   plugin/host state, then cleaned up (media force-deleted, page
   trashed). All 4 issues + the GitHub milestone closed with evidence.

2. **v3.8.0 shipped (2026-08-06, issue #15)** -- `GET /capabilities`:
   plugin_version, wp_version, host state (elementor_active,
   elementor_pro_active, rank_math_active), capabilities map keyed by
   stable dotted identifiers, allowed_schema_types, audit_log_enabled.
   Pure read. 7 new tests (273 -> 280).

3. **v3.7.0 shipped (2026-08-06, issue #12)** -- `POST
   /elementor/set-data` writes `_elementor_data`/`_elementor_edit_mode`/
   `_elementor_template_type`, validates shape (422), counts widgets,
   clears Elementor's CSS cache. 18 new tests (255 -> 273).

---

## Next 3 Priorities

1. **Resume Higgins v3.3.0 perf deployment** -- carried over across
   multiple sessions, now the top open item. POST the Font Awesome +
   Bootstrap preload/onload swap with `code_b64` + `priority:1`; verify
   PageSpeed mobile moves 63 -> 78-85. Higgins should also eventually
   move to v3.8.x (currently on v3.3.0, several releases behind).

2. **Telemetry verdict review** -- `rrc-telemetry.php` collecting since
   2026-07-06, trustworthy from ~2026-07-13 (now well past). Kill switch:
   `RRC_PUA_DISABLE` in wp-config. Carried over across multiple sessions,
   not yet actioned.

3. **No open GitHub issues or milestones as of 2026-08-06** -- next work
   item is user-directed (Higgins deployment, telemetry review, or a new
   ask) rather than issue-driven. Worth a fresh look at whether any
   follow-up items noted in past CHANGELOGs deserve filing (e.g. the
   `entity_clarity` scoring README callout noted in the 2026-07-20
   checkpoint, still not filed as an issue).

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `48b6f0b`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.8.0 as of the
  2026-08-06 combined smoke test. Higgins still on v3.3.0 -- several
  releases behind, due for its own deployment pass.
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
- Release hook note: run `git push` twice (zip commit lands after refspec)
- GitHub milestone #1 "Programmatic page provisioning" -- closed, 4/4
  issues resolved and live-verified.

**Blockers:**
- None. All open work from this session shipped, tested, and verified
  live. No open issues or milestones remain in the repo.

---

## Key Context Notes

1. **Elementor `settings: {}` round-trips as `[]`** -- PHP's JSON encoder
   can't distinguish an empty associative array from an empty list, so
   `POST /elementor/set-data` stores/returns `"settings":[]` for empty
   settings objects rather than `{}`. Observed live on kildaybaxter.com
   during the v3.8.0 smoke test; Elementor's own native storage has the
   same quirk, so this likely matches native behavior rather than being
   plugin-specific. Not filed as an issue -- flag if an operator reports
   the Elementor editor choking on a plugin-written element.

2. **`GET /capabilities`'s `Cache-Control` header gets overridden on
   kildaybaxter.com** -- the plugin sets `public, max-age=60`, but the
   live response carries the host's own `no-cache, must-revalidate,
   max-age=0, no-store, private` instead. Confirmed this is the
   host/proxy layer overriding response headers on `/wp-json/` paths
   downstream of PHP, not a plugin defect -- nothing to fix here.

3. **Missing required REST args (e.g. `alt_text` entirely absent) return
   `400 rest_missing_callback_param`, not the endpoint's own 422** --
   WP core's route-arg validation intercepts before the callback runs.
   The plugin's custom 422 validators only fire for present-but-invalid
   values (e.g. `alt_text: ""`). Both paths confirmed correct in the
   v3.8.0 smoke test; worth knowing when writing regression tests or
   README examples so expected status codes match reality.

4. **`business_facts` merge semantics (v3.4.1)** -- sent keys overwrite,
   omitted keys preserved, array fields replace wholesale (not append).
   Sending `business_facts: {}` is now a no-op, not a full clear -- no
   endpoint currently clears the whole object back to schema/bloginfo
   fallback; that would be a separate feature request if ever needed.

5. **`has_business_facts` scoring change is retroactive** -- any site with
   identity-only `business_facts` (name/phone/address, no
   services/area/questions/differentiators) will see `has_business_facts`
   flip false and `llms_completeness`/`overall` drop under v3.4.0+, even
   with no write on their part. Flagged in both CHANGELOGs; not yet
   confirmed whether this affected Kilday Baxter's own live score
   post-deploy.

6. **Docs-only follow-up, not filed as an issue** -- `entity_clarity`
   scoring caps out when manual `business_facts` omits `schema_type`/
   `entity_id`, because manual config fully overrides schema-derived
   signals by design (resolver priority chain, `rr_resolve_business_facts()`).
   Worth a README callout so operators know to include those two fields.

7. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
   (lowercase); `git add` with uppercase path silently stages nothing.
