# RankRocket SEO Control Layer — Project Status

**Last Updated:** 2026-04-29
**Current Version:** 2.3.1
**Working Directory:** `E:\projects\rank_rocket_seo_plugin\`
**Branch:** main
**Last Commit:** 7f55bc0 — chore(checkpoint): 2026-04-28_1946 (pushed)
**Git Status:** Dirty — manifest fix + v2.3.1 zip pending commit/push

---

## 2026-04-29 Session — Auto-Update Repair — IN PROGRESS

### Session Summary
Diagnosed and repaired the broken WordPress native auto-update mechanism. Two bugs in
`update-manifest.json` caused PUC to silently fail on every update check. Manifest rewritten to
PUC spec. Release zip `releases/v2.3.1/rankmath-rest-bridge.zip` built. Pending push to GitHub.
Auto-update flagged as a critical plugin feature in all project docs.

### Accomplishments

**Auto-update manifest fix**
- Root cause 1: `name` field absent — PUC `PluginInfo::validateMetadata()` requires both `name`
  and `version`; missing `name` returns `WP_Error`, aborting silently with no WP notification
- Root cause 2: field named `zip_url` — PUC maps JSON keys directly to `PluginInfo` properties;
  `zip_url` is unknown and gets discarded, leaving `download_url = null`; REST `/self-update` also
  reads `download_url` and returns "Could not determine zip URL" error
- Fix: rewrote manifest with `name`, `slug`, `download_url`, `sections`, `author`, `requires`,
  `requires_php`, `tested` — all required/recommended PUC fields

**Release zip built**
- `releases/v2.3.1/rankmath-rest-bridge.zip` — 177 KB, 118 entries
- Structure: `rankmath-rest-bridge/rankmath-rest-bridge.php` + `update-manifest.json` + full
  `vendor/plugin-update-checker/` tree (required for PUC to function on the installed site)

### Files Changed (this session)
- `update-manifest.json` — field names fixed, required PUC fields added
- `releases/v2.3.1/rankmath-rest-bridge.zip` — created (new artifact)
- `docs/STARTUP_CONTEXT.md` — refreshed; auto-update marked critical
- `docs/projectStatus.md` — this entry

### Commits
- Pending

### Known Issues / Gaps
- Zip + manifest not yet pushed → auto-update download URL still broken on live sites
- No end-to-end staging test yet (clear transient, bump manifest version, confirm WP Update notice)
- No `phpcs.xml.dist` — quality gate not runnable

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

### [CRITICAL] Auto-Update (in progress — must complete before any other backlog work)
- [x] Fix `update-manifest.json` — field names + PUC required fields
- [x] Build `releases/v2.3.1/rankmath-rest-bridge.zip`
- [ ] Push to GitHub so `raw.githubusercontent.com` download URL resolves
- [ ] End-to-end staging test: clear PUC transient, bump manifest version, confirm WP Dashboard
      Update notice, confirm one-click upgrade succeeds

### Ready to Start (priority order)
- [ ] **Testing + pre-commit hooks** — No test suite or active hooks exist. Required work:
      (a) `composer.json` with `phpunit/phpunit`, `wp-phpunit/wp-phpunit`, `yoast/wp-test-utils`
          as dev dependencies; `phpcs` + `dealerdirect/phpcodesniffer-composer-installer` +
          `wp-coding-standards/wpcs` for sniffing
      (b) `phpunit.xml.dist` — bootstrap WP test suite, map `tests/` directory
      (c) `tests/` — at minimum: bootstrap file, unit tests for `rr_validate_seo_fields()`,
          `rr_validate_schema()`, `rr_get_seo_meta()` fallback logic, manifest field assertions
      (d) `phpcs.xml.dist` — WordPress Coding Standards, exclude vendor
      (e) `.git/hooks/pre-commit` — run `phpcs` (fast); run `phpunit` only if `--all` flag set
          (keeps commit speed acceptable while enforcing standards on every commit)
- [ ] `phpcs.xml.dist` — can be bootstrapped independently if full test suite is deferred
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
