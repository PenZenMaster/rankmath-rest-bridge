# RankRocket SEO Control Layer — Project Status

**Last Updated:** 2026-04-28
**Current Version:** 2.3.1
**Working Directory:** `E:\projects\rank_rocket_seo_plugin\`
**Branch:** main
**Last Commit:** d39f465 — feat: harden snippets/replace-all with custom capability + deprecation (v2.3.1)
**Git Status:** Clean (not yet pushed)

---

## 2026-04-28 Session — Founding Session — COMPLETE

### Session Summary
Pulled the plugin from GitHub (`PenZenMaster/rankmath-rest-bridge`), renamed the mental model,
introduced native meta keys, built out the schema/preview/validation/audit stack, and hardened
the destructive replace-all endpoint. Three commits landed; working tree is clean.

### Accomplishments

**v2.2.0 — Mental model rename + native meta keys**
- Plugin Name header updated to "RankRocket SEO Control Layer"
- REST namespace: `rankmath-bridge/v1` -> `rankrocket-seo/v1` (breaking, hence minor bump)
- Introduced `RR_SEO_META_KEYS` and `RR_SEO_LEGACY_META_KEYS` constants
- New `rr_get_seo_meta( $post_id, $field )` helper: native `rr_seo_*` key first, `rank_math_*`
  fallback for migration
- All internal reads (wp_head output, sitemap, llms.txt) use the helper
- All writes target `rr_seo_*` only
- Option keys (`rmb_managed_snippets`, `rmb_llms_config`) and PUC slug unchanged to avoid data loss

**v2.3.0 — Schema model + preview + validation + audit log**
- `GET /rankrocket-seo/v1/schema/{post_id}` — returns stored `_rrseo_schema_graph` or null
- `POST /rankrocket-seo/v1/schema/{post_id}` — validates JSON-LD, stores, supports `dry_run: true`
- Schema JSON-LD injected into `wp_head` for singular posts
- `POST /rankrocket-seo/v1/preview-update` — returns before/after diff + errors/warnings, no write
- `rr_validate_seo_fields()`: title (30-60 warn, 120 hard), desc (50-160 warn, 320 hard),
  og_image URL check, robots token allowlist, post type allowlist, batch max 20
- `rr_validate_schema()`: JSON parse check, @context required, @type in allowlist (17 types),
  both allowlists filterable
- `rr_audit_log()`: appends to `_rrseo_change_log` postmeta, capped at 100 entries per post
- `GET /rankrocket-seo/v1/log/{post_id}` — returns log most-recent-first
- `/update` and `/meta/bulk-update` now run validation and write audit entries
- Hard validation errors return HTTP 422 with `errors[]` + `warnings[]`
- Bulk update: per-item validation (invalid items skipped, not batch-rejected)

**v2.3.1 — replace-all hardened**
- `RR_REPLACE_ALL_CAP = 'rrseo_replace_all_snippets'` constant
- `init` hook grants cap to administrator role on first load (idempotent)
- Route permission callback checks custom cap instead of `manage_options`
- Success response includes `deprecated` field; route annotated `@deprecated`
- Target removal: v3.0.0

### Files Changed (this session)
- `rankmath-rest-bridge.php` — all logic (rewritten end-to-end across three versions)
- `update-manifest.json` — version bumped to 2.3.1

### Commits
- `885bc19` — feat: rename mental model to RankRocket SEO Control Layer (v2.2.0)
- `82bc78a` — feat: schema model, preview endpoint, validation layer, audit log (v2.3.0)
- `d39f465` — feat: harden snippets/replace-all with custom capability + deprecation (v2.3.1)

### Known Issues / Gaps
- No `phpcs.xml.dist` — quality gate not runnable
- `releases/v2.3.1/` zip does not exist — self-update manifest has broken download URL
- `rr_seo_score` has no native meta key — score reads fall through to `rank_math_seo_score`
- No I18n wrappers on user-visible strings yet

---

## Backlog

### Ready to Start
- [ ] `phpcs.xml.dist` — WordPress Coding Standards config + fix any violations
- [ ] `releases/v2.3.1/` zip packaging — create zip, push, verify self-update
- [ ] `POST /migrate-legacy` — batch-copy `rank_math_*` -> `rr_seo_*` with audit log
- [ ] I18n pass — wrap user-visible strings with `__()` / `_e()` and text domain

### Future / Deferred
- [ ] Native `rr_seo_score` postmeta key + scoring endpoint
- [ ] Remove `replace-all` endpoint (v3.0.0 milestone)
- [ ] Custom capability management UI (grant/revoke `rrseo_replace_all_snippets` per role)
- [ ] `GET /schema/bulk` — retrieve schema for multiple posts in one call
- [ ] OpenGraph image dimension validation
- [ ] Batch migrate endpoint UI for the WP admin

---

## Version History

| Version | Date       | Summary                                                        |
|---------|------------|----------------------------------------------------------------|
| 2.3.1   | 2026-04-28 | replace-all: custom cap + deprecation notice                   |
| 2.3.0   | 2026-04-28 | Schema model, preview endpoint, validation layer, audit log    |
| 2.2.0   | 2026-04-28 | Rename to RRSEO Control Layer; native rr_seo_* keys; new namespace |
| 2.1.4   | (prior)    | Varnish PURGE + Breeze cache detection                         |
| 2.1.x   | (prior)    | og:image, sitemap fixes, llms.txt fixes                        |
| 2.0.x   | (prior)    | Self-update, cache purge, snippets, image ALT                  |
| 1.x     | (prior)    | Original RankMath REST Bridge                                  |
