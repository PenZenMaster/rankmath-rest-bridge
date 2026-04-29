# RankRocket SEO Control Layer — Project Status

**Last Updated:** 2026-04-29
**Current Version:** 2.7.0
**Working Directory:** `E:\projects\rank_rocket_seo_plugin\`
**Branch:** main
**Last Commit:** 74a7257 — chore: add bin/build-zip.ps1 release builder + correct v2.7.0 zip (pushed)
**Git Status:** Clean

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

### [HIGH — NEXT] llms.txt Structured Config
Full config schema requested by user 2026-04-29 — not yet implemented:
```json
{
  "include_sitemaps": true,
  "include_lastmod": true,
  "include_meta_descriptions": true,
  "description_fallback": ["rrseo_description", "excerpt", "first_paragraph"],
  "exclude_noindex": true,
  "exclude_utility_pages": true,
  "exclude_patterns": ["-2/", "-3/", "/thank-you/", "/privacy-policy/", "/opt-out-preferences/"],
  "sections": ["business_facts", "sitemaps", "services", "service_pages", "location_pages", "posts", "ai_guidance"],
  "max_description_chars": 240
}
```
- Update `POST /llms` to accept and persist these fields
- Update `rmb_serve_llms_txt()` to honour all controls
- Update admin panel llms.txt tab to display/edit

### [CRITICAL] Auto-Update Staging Verify
- [x] Manifest fixed and correct zips pushed
- [ ] End-to-end staging test — see `docs/staging-verify-autoupdate.md`

### Ready to Start
- [ ] `composer install` on dev machine to activate phpunit + phpcs locally
- [ ] Run `composer run qa` — phpcs 0 errors expected; phpunit 35 tests
- [ ] Verify llms.txt raw-content upload support (backlog item from earlier)
- [ ] I18n pass — wrap user-visible strings with `__()` / `_e()`

### Future / Deferred
- [ ] Native `rr_seo_score` postmeta key + scoring endpoint
- [ ] Remove `replace-all` endpoint (v3.0.0 milestone)
- [ ] Custom capability management UI
- [ ] `GET /schema/bulk`
- [ ] OpenGraph image dimension validation
- [ ] Centralized multi-site hub (MainWP-style) — separate future plugin

---

## Version History

| Version | Date       | Summary                                                              |
|---------|------------|----------------------------------------------------------------------|
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
