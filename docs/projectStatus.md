# RankRocket SEO Control Layer — Project Status

**Last Updated:** 2026-05-14
**Current Version:** 2.13.0
**Working Directory:** `E:\projects\rank_rocket_seo_plugin\`
**Branch:** main
**Last Commit:** 7368e88 — chore: release v2.13.0 zip
**Git Status:** clean

---

## 2026-05-14 Session 2 — v2.13.0 Implementation — COMPLETE

### Session Summary
Implemented all three gaps in the v2.13.0 milestone. Five atomic commits,
all lint-clean. mu-plugin retire markers updated. Ready to shift to
rrc-mu-toolkit for retirement sequence.

### Accomplishments
- G-01: `rr_is_any_tax_archive()` + 4 new `display_on` patterns (term/term_id/tax/url)
- G-08: `rr_validate_display_on()` + 422 validation on snippet create/update
- G-02: `rr_resolve_id()`, `rr_get_term_seo_meta()`, term routing in /update and /get
- G-02: Taxonomy archive emission — title, robots, description/OG, canonical
- Version bump 2.12.2 → 2.13.0, manifest updated
- `rrc-mu-toolkit` retire markers added for RRC_SEO_TAX_META_DESC and RRC_SEO_TAX_META_OG

### Next
Create rrc-mu-toolkit GitHub remote; retire superseded mu-plugin modules; v2.14.0 planning.

---

## 2026-05-14 Session 1 — Housekeeping + v2.13.0 planning — COMPLETE

### Session Summary
Triaged untracked `plugin-usage-audit.php` into its own repo (`rrc-mu-toolkit`).
Committed the Salvo gap report as the new roadmap document, retired the old CSV,
and planned the full v2.13.0 implementation (G-01, G-02, G-08).

### Accomplishments
- `rrc-mu-toolkit` repo scaffolded locally with initial commit `4c7e017`
- `docs/RankRocket_SEO_Functionality_Gaps.md` committed (19 gaps, G-01 to G-19)
- `docs/Gap-Priority-Notes.csv` retired; 4 surviving items migrated as G-16 to G-19
- v2.13.0 implementation plan defined (5 commits, risks documented)

### Next
Implement v2.13.0: G-01 (taxonomy display_on) -> G-08 (validation) -> G-02 (term meta + emission)

---

## 2026-05-13 Session 2 — .gitignore cleanup — COMPLETE

### Session Summary
Identified three untracked files that did not belong in source control.
Updated `.gitignore` and pushed.

### Accomplishments
- `.gitignore` — added `composer.lock`, `.phpunit.result.cache`,
  `.claude/settings.local.json`; working tree now clean

---

## 2026-05-13 Session 1 — White-label doc + Tier 2 update suppression — COMPLETE

### Session Summary
Created white-label configuration guide. Identified and fixed Tier 2 gap: plugin
name leaked on Dashboard > Updates. Fixed via PUC `puc_pre_inject_update` and
`puc_pre_inject_info` filters in `class-rrseo-white-label.php` v1.01.

### Accomplishments
- `docs/white-label-configuration.md` — Tier 1/Tier 2 config guide with update
  delivery options for hidden-plugin scenario
- `includes/class-rrseo-white-label.php` v1.01 — PUC suppression hooks added to
  constructor; plugin no longer surfaces on Dashboard > Updates under Tier 2

### Next
- Staging auto-update verify (WP-CLI path for Tier 2 installs)
- P2/P3 gap review (`docs/Gap-Priority-Notes.csv`)
- `docs/projectStatus.md` full sprint catch-up for v2.11.x / v2.12.x

---

## 2026-05-01 Session — v2.10.0 AEO/GEO Audit Data Layer — IN PROGRESS (branch not merged)

### Session Summary
AEO/GEO audit data layer implemented and reviewed. `check-updates` regression diagnosed and fixed.
PHP 8.4 test suite healed. Two full simplify passes completed.

### Accomplishments

**v2.10.0 — AEO/GEO Audit Data Layer**
- `includes/class-rrseo-aeo-geo.php` (new, ~550 lines) — 5 helper functions + 5 REST callbacks
- `GET /canonical-urls/preview` — machine-readable canonical URL set; resolves P2 backlog gap
- `GET /aeo-geo/readiness` — entity clarity, source guidance, schema depth, llms completeness scores
- `GET /aeo-geo/entity` — NAP, business_facts, homepage schema types, source priority label
- `GET /aeo-geo/schema-audit` — per-URL schema type inventory + missing-opportunity detection
- `GET /aeo-geo/source-sync` — canonical vs sitemap partition (post/page vs product-type URLs)
- `tests/unit/AeoGeoReadinessTest.php` (new) — 24 tests, 76 assertions

**Regression Fix (also v2.10.0)**
- `POST /check-updates` — removed blocking `wp_update_plugins()` call; was causing HTTP timeout
  on restricted hosts, making button appear unresponsive
- Admin "Clear Update Cache" button + description updated to direct user to Dashboard > Updates

**Test Suite Repairs (PHP 8.4)**
- `tests/bootstrap.php` — `WP_Post` stub class + `is_admin()` stub
- `makePost()`/`makePage()` → `WP_Post` in all 3 test files
- `CanonicalUrlSetTest.php` — fixed 2 pre-existing assertion bugs

**Simplify Passes**
- `rr_aeo_compute_readiness()` caches `rr_get_canonical_url_set()` — 3 DB calls → 1
- `rr_aeo_compute_source_sync()` simplified from 6-branch diff to 2-branch partition; dead variables removed
- `?array` nullable type hints, `??` idiom, `home_url('/')` simplification, `phpcs:ignore` pattern

### Commits (this session, branch only)
- `78f6aef` — feat(aeo-geo): canonical-urls/preview + AEO/GEO readiness endpoints (v2.10.0)
- `56aa7c5` — fix: remove wp_update_plugins() from check-updates handler
- `093895f` — test: fix 2 pre-existing CanonicalUrlSetTest failures (PHP 8.4 era)
- `915a610` — refactor(aeo-geo): simplify source_sync, cut DB queries in readiness, fix idioms
- `3695b84` — fix(aeo-geo): ?array nullable type hints on optional canonical_result params

### Known Issues / Next Steps
- Branch not pushed or merged to main
- v2.10.0 release zip not built
- `check-updates` regression affects v2.9.3 live sites (hotfix or v2.10.0 release needed)
- Staging auto-update verify still outstanding

---

## 2026-04-29 Session (continued) — v2.8.0 through v2.9.2 — COMPLETE

### Session Summary
Crawl sync spec P0 and P1 delivered; admin panel and update tooling improvements; all P1
gaps from Gap-Priority-Notes.csv resolved.

### Accomplishments

**v2.8.0 — Shared Canonical URL Set (P0 crawl sync)**
- `includes/class-rrseo-canonical.php` (new) — `rr_get_canonical_url_set()`, `rr_is_url_allowed_for_discovery()`, `rr_get_post_discovery_metadata()`, `rr_get_discovery_description()`, description fallback chain (rrseo_description → excerpt → first_paragraph → title), word-boundary truncation, utility exclusion, numeric-suffix duplicate detection
- All sitemaps, `rmb_serve_llms_txt()`, and `/sitemap/preview` refactored to use the shared helper
- `rmb_sitemap_preview()` expanded with `excluded_urls[]`, per-URL `warnings[]`, UTC lastmod fix
- `rmb_status()` expanded: `sitemap_index_url`, `physical_robots_txt_exists`, `warnings[]`
- `rmb_robots_set()` — `ensure_sitemap_directive` + `preferred_sitemap_only` flags; v4 §15.7 normalisation
- 28 unit tests in `CanonicalUrlSetTest.php`

**v2.9.0 — Crawl sync P1**
- `includes/class-rrseo-llms.php` (new) — section classifier, `rr_classify_url_section()`, `rr_auto_classify_section()`, `rr_validate_llms_section()`, `rr_resolve_business_facts()`, `rr_render_llms_txt()`, `rmb_llms_preview()` REST handler
- `META_LLMS_SECTION` constant, `RR_LLMS_CONFIG_KEY`, `RR_ROBOTS_CONFIG_KEY`
- `GET /llms/preview` (?format=json|text) — read-only, no DB writes
- `POST /llms` expanded: sections object, business_facts, exclude_patterns, max_description_chars, boolean flags
- `POST /update` and `/meta/bulk-update` accept `llms_section`
- `GET /get/{id}` and `/meta/bulk-get` return `llms_section` + `effective_llms_section`
- `GET /images/{id}/alt` handler added
- `/images/bulk-alt` batch cap (`RR_BATCH_MAX`)
- `/status?include_counts=true` with 12h transient cache
- 19 unit tests in `SectionClassifierTest.php`
- Canonical cache invalidation hooks on save_post, delete_post, option updates

**v2.9.1 — Force Update Check + admin llms.txt tab**
- `POST /check-updates` — clears `update_plugins` transient + PUC's `external_updates-rankmath-rest-bridge` option
- Admin Overview — "Force Update Check" button; one-click replaces WP-CLI workflow
- PUC check period filterable via `rrseo_puc_check_period_hours`
- Admin llms.txt tab rebuilt: Business Facts, Section Classifier table, Content Settings, live preview panel
- `docs/staging-verify-autoupdate.md` updated with Force Update Check as primary option

**v2.9.2 — Three P1 gap fixes (from Gap-Priority-Notes.csv)**
- `rankmath-bridge/v1` namespace alias via `rr_legacy_namespace_proxy()` on `rest_pre_dispatch`
- `register_post_meta` for `_rrseo_llms_section` (show_in_rest, sanitize, auth_callback)
- First-paragraph description fallback bug fixed (split raw content before normalization)
- 5 new description fallback tests

### Commits (this session)
- `7057439` — feat: shared Canonical URL Set — v2.8.0
- `607751f` — feat: section classifier, llms/preview, _rrseo_llms_section — v2.9.0
- `82fcc12` — feat: Force Update Check + admin llms.txt tab — v2.9.1
- `0ad343f` — fix: namespace alias, register_post_meta, first-paragraph — v2.9.2

### Known Issues / Gaps
- Staging auto-update verify not yet run on a live site
- `composer install` not yet run on dev machine
- P2 gaps from `docs/Gap-Priority-Notes.csv` still open

---

## 2026-04-29 Session — v2.4.0 through v2.7.0 — COMPLETE

### Session Summary
Broad second session. Delivered auto-update repair, full WPCS compliance, testing stack,
title fix, WordPress admin UI, styled sitemaps, robots.txt endpoint, and a release build
script. Seven version bumps across one session.

### Accomplishments

**v2.4.0 — Testing stack + migrate-legacy**
- `composer.json` (PHPUnit 9.x, phpcs/wpcs, dev-vendor)
- `phpcs.xml.dist`, `phpunit.xml.dist`
- `tests/` — 35 unit tests (validators, schema, manifest, title)
- `hooks/pre-commit` — blocking lint gate on every commit
- `POST /migrate-legacy` — batch rank_math_* → rr_seo_* with dry_run + audit log

**v2.4.1 — WPCS compliance + document title fix**
- 1,804 pre-existing errors resolved (phpcbf + 38 manual)
- `pre_get_document_title` filter moved to plugin-load — fixed silent title discard
- Pre-commit hook set to blocking (`EXIT_CODE=1`)

**v2.5.0 — WordPress admin panel + meta box**
- 6-page admin menu backed by existing REST endpoints
- Read-only Edit Post/Page sidebar meta box with char-count badges
- `includes/` directory introduced; admin loaded via `is_admin()` gate

**v2.6.0 — Styled sitemaps + per-type sub-sitemaps**
- `includes/sitemap.xsl` — browser-readable HTML via PHP-served XSL
- Per-type sub-sitemaps (posts, pages) replace single-entry index
- Accurate UTC lastmod (`post_modified_gmt`)

**v2.7.0 — robots.txt endpoint + release build script**
- `GET/POST /rankrocket-seo/v1/robots-txt`
- `robots_txt` filter at priority 99
- Physical-file detection + warning in all responses
- `bin/build-zip.ps1` with 4 structural verification checks
- Fixed flattened-vendor zip bug that caused v2.7.0 activation failure

### Commits (this session)
- `7ec5e1b` — fix: repair auto-update mechanism + v2.3.1 zip
- `abdb2d4` — feat: migrate-legacy, PHPUnit/phpcs stack, pre-commit (v2.4.0)
- `658bc4b` — fix: pre_get_document_title timing bug (v2.4.1)
- `0988c7d` — refactor: WPCS compliance pass — 1,804 errors
- `9555ba7` — docs: WPCS compliance task + breakdown
- `0b04b1d` — feat: admin panel + meta box (v2.5.0)
- `0671720` — feat: styled sitemaps + per-type sub-sitemaps (v2.6.0)
- `6e0365f` — feat: GET/POST /robots-txt (v2.7.0)
- `56f2f77` — fix: correct v2.7.0 zip (flattened vendor)
- `74a7257` — chore: bin/build-zip.ps1 + correct v2.7.0 zip

### Known Issues / Gaps
- Staging auto-update verify not yet done
- `composer install` not yet run on dev machine
- llms.txt structured config requested but NOT implemented

---

## 2026-04-28 Session — Founding Session — COMPLETE

### Session Summary
Pulled plugin from GitHub, renamed mental model, introduced native meta keys, built schema/
preview/validation/audit stack, hardened replace-all endpoint. Three commits, v2.2.0–v2.3.1.

### Commits
- `885bc19` — feat: rename mental model (v2.2.0)
- `82bc78a` — feat: schema model, preview, validation, audit log (v2.3.0)
- `d39f465` — feat: harden replace-all with custom capability (v2.3.1)

---

## Backlog

### [CRITICAL] Auto-Update Staging Verify
- [x] Manifest fixed and correct zips pushed
- [x] Force Update Check button added (v2.9.1) — no WP-CLI needed
- [ ] End-to-end test on live staging site — `docs/staging-verify-autoupdate.md`

### [HIGH] P2 Gaps from Gap-Priority-Notes.csv
- [x] ~~Legacy namespace alias~~ — fixed v2.9.2
- [x] ~~register_post_meta for _rrseo_llms_section~~ — fixed v2.9.2
- [x] ~~First-paragraph description fallback bug~~ — fixed v2.9.2
- [ ] Self-canonical/redirect check — use `get_canonical_url()` per post (~10 lines)
- [ ] `/sitemap_index.xml` lastmod — derive from canonical set, not raw `get_posts()`
- [x] ~~`/canonical-urls/preview` endpoint alias~~ — delivered in v2.10.0 AEO/GEO layer
- [ ] Expand test-placeholder pattern list beyond `please-do-not-delete-this-*`

### [DONE] llms.txt Structured Config
- [x] `POST /llms` accepts and persists all new fields (v2.9.0)
- [x] `rmb_serve_llms_txt()` delegates to `rr_render_llms_txt()` (v2.9.0)
- [x] Admin panel llms.txt tab displays all config + preview (v2.9.1)

### Ready to Start
- [ ] `composer install` on dev machine to activate phpunit + phpcs locally
- [ ] Run `composer run qa` — phpcs 0 errors expected; phpunit 50+ tests
- [ ] Verify llms.txt raw-content upload support (old backlog item)
- [ ] I18n pass — wrap user-visible strings with `__()` / `_e()`

### After AEO/GEO Audit Data Layer
- [ ] **White Labeling** — agency/developer rebranding capabilities:
  - Rename plugin name, description, and icon in Plugins menu and sidebar
  - Hide or restrict main menu / sub-menus for non-admin users
  - Remove plugin logos, upgrade badges, and "Powered by" footers from settings pages
  - Replace default "View Details" / "Support" links with custom agency URL
  - All settings lockable via `wp-config.php` constant to prevent client revert
  - Use case: client handoff — hide third-party branding, reinforce agency identity

### Future / Deferred
- [ ] Native `rr_seo_score` postmeta key + scoring endpoint
- [ ] Remove `replace-all` endpoint (v3.0.0 milestone)
- [ ] Custom capability management UI
- [ ] `GET /schema/bulk`
- [ ] OpenGraph image dimension validation
- [ ] Centralized multi-site hub (MainWP-style) — separate future plugin
- [ ] **[P3] RankMath Reference Purge** — remove all external and internal RankMath artifacts:
  - Remove visible RankMath references from admin UI, plugin headers, and REST responses
  - Refactor `rr_get_seo_meta()` migration fallback: make `rank_math_*` read-path opt-in (constant/option) rather than always-on, so sites without RankMath do not incur fallback overhead or surface RankMath key names in responses
  - Optionally rename remaining internal `rank_math_*` / `rankmath` identifiers to `rrseo_*` equivalents in a coordinated search-and-replace pass
  - Use case: new client builds with no RankMath installed; sites migrating off Yoast or AIO SEO where RankMath was never present
  - Prerequisite: confirm no active clients rely on the `rank_math_*` read-path fallback before removing it

---

## Version History

| Version | Date       | Summary                                                              |
|---------|------------|----------------------------------------------------------------------|
| 2.13.0  | 2026-05-14 | Taxonomy display_on patterns (G-01), term meta routing (G-02), display_on validation (G-08) |
| 2.10.0  | 2026-05-01 | AEO/GEO audit data layer (5 endpoints); fix check-updates regression |
| 2.9.3   | 2026-04-29 | Fix schema JSON-LD in footer; fix PUC vendor path mismatch           |
| 2.9.2   | 2026-04-29 | rankmath-bridge/v1 alias; register_post_meta; first-para bug fix    |
| 2.9.1   | 2026-04-29 | Force Update Check button + POST /check-updates; admin llms tab     |
| 2.9.0   | 2026-04-29 | P1 crawl sync: section classifier, /llms/preview, _rrseo_llms_section |
| 2.8.0   | 2026-04-29 | Shared Canonical URL Set (P0) — all sitemaps + llms.txt unified     |
| 2.7.0   | 2026-04-29 | GET/POST /robots-txt; bin/build-zip.ps1; zip structure fix           |
| 2.6.0   | 2026-04-29 | Styled sitemaps (XSL) + per-type sub-sitemaps                        |
| 2.5.0   | 2026-04-29 | WordPress admin panel + Edit Post/Page meta box                      |
| 2.4.1   | 2026-04-29 | WPCS compliance (1,804 errors); document title fix                   |
| 2.4.0   | 2026-04-29 | POST /migrate-legacy; PHPUnit + phpcs testing stack                  |
| 2.3.1   | 2026-04-28 | replace-all: custom capability + deprecation notice                  |
| 2.3.0   | 2026-04-28 | Schema model, preview endpoint, validation layer, audit log          |
| 2.2.0   | 2026-04-28 | Rename to RRSEO Control Layer; native rr_seo_* keys; new namespace   |
| 2.1.x   | (prior)    | og:image, sitemap fixes, llms.txt fixes                              |
| 2.0.x   | (prior)    | Self-update, cache purge, snippets, image ALT                        |
| 1.x     | (prior)    | Original RankMath REST Bridge                                        |
