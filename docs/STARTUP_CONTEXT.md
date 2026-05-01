# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-01
**Branch:** feature/aeo-geo-audit-data-layer
**Version:** 2.10.0
**Last Commit:** 3695b84 — fix(aeo-geo): ?array nullable type hints on optional canonical_result params

---

## Last 3 Accomplishments

1. **AEO/GEO audit data layer delivered (v2.10.0)** — `includes/class-rrseo-aeo-geo.php` adds 5 new
   REST endpoints: `/canonical-urls/preview` (join spine, resolves P2 gap), `/aeo-geo/readiness`
   (4-dimension readiness score), `/aeo-geo/entity` (NAP signals), `/aeo-geo/schema-audit`
   (per-URL schema inventory), `/aeo-geo/source-sync` (canonical vs sitemap partition). 24 unit tests,
   2 simplify passes — DB calls reduced from 3 to 1 per readiness request, dead code removed.

2. **`check-updates` regression fixed** — `POST /check-updates` REST handler was calling
   `wp_update_plugins()` synchronously, causing blocking HTTP to `api.wordpress.org` that exceeded
   PHP `max_execution_time` on restricted hosts. Removed the call; button renamed "Clear Update Cache"
   with a link to Dashboard > Updates. Affects v2.9.3 live sites — hotfix or v2.10.0 needed.

3. **PHP 8.4 test suite repaired** — Added `WP_Post` stub class and `is_admin()` stub to
   `tests/bootstrap.php`; updated `makePost()`/`makePage()` helpers in all 3 test files to return
   `WP_Post` instead of `stdClass`; fixed 2 pre-existing assertion bugs in `CanonicalUrlSetTest.php`.
   Full suite: 129/129 passing.

---

## Next 3 Priorities

1. **Release v2.10.0** — push branch, merge to main, run `bin/build-zip.ps1 v2.10.0`, create GitHub
   Release, confirm `update-manifest.json` on main resolves correctly for PUC.

2. **Hotfix decision** — v2.9.3 live sites have the broken `check-updates` button. Either cherry-pick
   the fix to main as v2.9.4, or accept that v2.10.0 covers it. Fast path: cherry-pick takes 5 min.

3. **Staging auto-update verify** — with v2.10.0 released, install on staging, click "Check Again" on
   Dashboard > Updates, confirm PUC delivers and zip installs cleanly. Steps in
   `docs/staging-verify-autoupdate.md`.

---

## Current State

**Git:**
- Branch: `feature/aeo-geo-audit-data-layer`
- Version: 2.10.0
- Last commit: `3695b84` — 5 commits ahead of origin, NOT yet pushed or merged
- Working tree: clean (3 untracked: `.claude/settings.local.json`, `.phpunit.result.cache`, `composer.lock`)

**Files of note:**
- AEO/GEO layer: `includes/class-rrseo-aeo-geo.php` (~550 lines, 5 helpers + 5 REST callbacks)
- AEO/GEO tests: `tests/unit/AeoGeoReadinessTest.php` (24 tests, 129 total in suite)
- Plugin: `rankmath-rest-bridge.php` (~2,800 lines)
- Canonical set: `includes/class-rrseo-canonical.php`
- llms generator: `includes/class-rrseo-llms.php`
- Admin panel: `includes/class-rrseo-admin.php`, `admin.js`, `admin.css`
- Release builder: `bin/build-zip.ps1` — always use this; never manual PS one-liners
- Gap tracker: `docs/Gap-Priority-Notes.csv` — P1s done; P2/P3 remain
- Staging checklist: `docs/staging-verify-autoupdate.md`

**Blockers:**
- v2.10.0 not released — 5 commits local-only; zip not built; manifest on main still v2.9.3
- v2.9.3 live sites: check-updates button appears unresponsive (hotfix pending)
- `composer install` not yet run on dev machine

---

## Key Context Notes

1. **Always use `bin/build-zip.ps1` for releases** — never manual PowerShell one-liners. The script
   verifies 4 structural requirements (PUC Puc/v5p5/, includes/, plugin file, loader) and exits
   non-zero on failure.

2. **AEO/GEO plugin boundary** — the plugin is a read-only data provider only. OAuth, GSC/GA4/GBP
   fetchers, normalization, and report generation all live in the external audit engine. The plugin
   exposes 5 REST endpoints for the audit engine to poll.

3. **`in_llms` is always true for canonical URLs** — `rr_is_utility_url()` applies the llms
   `exclude_patterns` during canonical URL set construction, so any URL matching an exclude_pattern
   never reaches the canonical set and therefore never appears in the AEO/GEO endpoints.

4. **Legacy namespace alias works via proxy** — `rr_legacy_namespace_proxy()` uses `rest_pre_dispatch`
   to intercept `/rankmath-bridge/v1/...` and re-dispatch to `/rankrocket-seo/v1/...`. No route
   duplication. Every proxied response gets `_deprecated` with the preferred namespace.
