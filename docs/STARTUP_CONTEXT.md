# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-14 (shutdown 1648)
**Branch:** main
**Version:** 2.13.0
**Last Commit:** 01ea967 — chore: bump version to 2.13.0, update manifest

---

## Last 3 Accomplishments

1. **v2.13.0 shipped** — G-01 (taxonomy `display_on` patterns: `term:TAX:SLUG`,
   `term_id:NNN`, `tax:TAX`, `url:/path/`), G-02 (term meta read/write via
   `/update` and `/get`; taxonomy archive title/robots/description/OG/canonical
   emission), G-08 (`display_on` validation on snippet write with 422 +
   `accepted_patterns[]`). Five commits, all lint-clean, pushed.

2. **`rrc-mu-toolkit` retire markers updated** — `RRC_SEO_TAX_META_DESC` and
   `RRC_SEO_TAX_META_OG` marked READY TO RETIRE (v2.13.0 supersedes them).
   Remaining module retire schedule documented in file header.

3. **Gap report roadmap intact** — G-01/02/08 complete; v2.14.0 through v2.17.0
   milestones unchanged and accurate.

---

## Next 3 Priorities

1. **`rrc-mu-toolkit` GitHub remote + retire sequence** — create GitHub repo,
   push. Then retire `RRC_SEO_TAX_META_DESC` and `RRC_SEO_TAX_META_OG` on
   Salvo: disable via wp-config.php, verify staging, remove code, commit.

2. **Salvo staging verify for v2.13.0** — install v2.13.0 zip; confirm
   `term:product_cat:<slug>` snippets fire and that `/update` with a term ID
   stores and emits correctly on taxonomy archive pages.

3. **v2.14.0 planning** — G-03 (consolidate_canonical flag), G-11 (GET
   /snippets), G-12 (POST /llms-txt/regenerate), G-16 (register_post_meta),
   G-18 (/canonical-urls/preview alias).

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.13.0
- Last commit: `01ea967` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~2,970 lines)
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — 19 gaps, G-01/02/08 done
- White-label: `includes/class-rrseo-white-label.php` (v1.01)
- Release builder: `bin/build-zip.ps1` — always use for releases
- Staging checklist: `docs/staging-verify-autoupdate.md`
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote yet

**Blockers:**
- None.

---

## Key Context Notes

1. **v2.13.0 new API surface** — `/update` and `/get/{id}` now accept term IDs
   alongside post IDs. Response adds `object_id` and `object_type` fields;
   `post_id` kept as backward-compat alias. Term writes go to `update_term_meta()`;
   reads from `rr_get_term_seo_meta()`.

2. **Taxonomy emission** — `rr_is_any_tax_archive()` (is_tax || is_category ||
   is_tag) is the central guard. `rr_override_document_title()` and
   `rr_merge_wp_robots()` both extended. Two new `wp_head` priority-1 closures
   handle description/OG and canonical for tax archives.

3. **rrc-mu-toolkit retire sequence** — when shifting to that project, first
   task is creating the GitHub remote, then retiring `RRC_SEO_TAX_META_DESC`
   and `RRC_SEO_TAX_META_OG`. Retire order: disable constant → staging verify
   → remove module code → commit.

4. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates are
   silent. Delivery via WP-CLI or manual zip. See `docs/white-label-configuration.md`.

5. **WPCS installed globally** — `phpcs --standard=phpcs.xml.dist` and
   `composer run lint` both work locally. `php dev-vendor\bin\phpcbf` for
   auto-fix.
