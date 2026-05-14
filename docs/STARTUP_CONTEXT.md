# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-13 (shutdown)
**Branch:** main
**Version:** 2.12.2
**Last Commit:** 6de11a1 — feat: merge v2.12.2 snippet renderer (P6 — sitewide/singular/body_open/killswitch)

---

## Last 3 Accomplishments

1. **White-label doc created** (`docs/white-label-configuration.md`) — covers
   Tier 1 rename constants, Tier 2 hide constant, and an "Updates when hidden"
   section with auto-update filter snippet, WP-CLI command, and manual zip steps.

2. **Tier 2 update-row suppression shipped** (`class-rrseo-white-label.php`
   v1.01) — `RRSEO_WL_HIDE_PLUGIN` now also hooks
   `puc_pre_inject_update-rankmath-rest-bridge` and
   `puc_pre_inject_info-rankmath-rest-bridge` so the plugin does not appear on
   Dashboard > Updates or in the version-details modal.

3. **v2.12.2 shipped (prior session)** — snippet renderer merged with full
   coverage: sitewide, singular, `body_open`, post-type targeting, and killswitch.

---

## Next 3 Priorities

1. **Staging auto-update verify** — install v2.12.2 on staging; with Tier 2
   active, test update delivery via WP-CLI (`wp plugin update rankmath-rest-bridge`)
   since the update row is now suppressed on Dashboard > Updates.

2. **P2/P3 gap items** — review `docs/Gap-Priority-Notes.csv` for next backlog
   items now that white-label and snippet renderer are complete.

3. **projectStatus.md refresh** — file is stale (shows v2.10.0, 2026-05-01);
   needs sprint history catch-up for v2.11.x and v2.12.x.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.12.2
- Pending commit after shutdown: white-label doc + v1.01 PUC suppression fix
- Working tree: clean after commit (3 untracked: `.claude/settings.local.json`,
  `.phpunit.result.cache`, `composer.lock`)

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~2,810 lines)
- White-label: `includes/class-rrseo-white-label.php` (v1.01)
- White-label doc: `docs/white-label-configuration.md`
- Release builder: `bin/build-zip.ps1` — always use this for releases
- Gap tracker: `docs/Gap-Priority-Notes.csv` — P1s done; P2/P3 remain
- Staging checklist: `docs/staging-verify-autoupdate.md`

**Blockers:**
- None.

---

## Key Context Notes

1. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates are
   silent (no UI row). Delivery options: auto-update filter, WP-CLI, or manual
   zip upload. See `docs/white-label-configuration.md`.

2. **Always use `bin/build-zip.ps1 v<version>` for releases** — verifies 4
   structural requirements and exits non-zero on failure.

3. **AEO/GEO plugin boundary** — plugin is a read-only data provider. OAuth,
   fetchers, normalization, and report generation live in the external audit
   engine. Plugin exposes 5 REST endpoints for the engine.

4. **WPCS installed globally** — `wp-coding-standards/wpcs` v3.1 via
   `composer global require`. `phpcs --standard=phpcs.xml.dist` and
   `composer run lint` both work locally.

5. **Legacy namespace alias** — `rr_legacy_namespace_proxy()` intercepts
   `/rankmath-bridge/v1/...` via `rest_pre_dispatch` and re-dispatches to
   `/rankrocket-seo/v1/...`. No route duplication.
