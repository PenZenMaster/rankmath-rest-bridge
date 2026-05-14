# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-13 (shutdown)
**Branch:** main
**Version:** 2.12.2
**Last Commit:** 83e70a4 — chore: .gitignore cleanup

---

## Last 3 Accomplishments

1. **`.gitignore` cleaned up** — `composer.lock`, `.phpunit.result.cache`, and
   `.claude/settings.local.json` added; working tree is now fully clean.

2. **White-label doc created** (`docs/white-label-configuration.md`) — covers
   Tier 1 rename constants, Tier 2 hide constant, and update delivery options
   for hidden installs (auto-update filter, WP-CLI, manual zip).

3. **Tier 2 update-row suppression shipped** (`class-rrseo-white-label.php`
   v1.01) — `puc_pre_inject_update` and `puc_pre_inject_info` filters added so
   the plugin does not appear on Dashboard > Updates or the details modal.

---

## Next 3 Priorities

1. **Staging auto-update verify** — install v2.12.2 on staging; with Tier 2
   active, test update delivery via WP-CLI (`wp plugin update rankmath-rest-bridge`)
   since the update row is suppressed on Dashboard > Updates.

2. **P2/P3 gap items** — review `docs/Gap-Priority-Notes.csv` for next backlog
   items now that white-label and snippet renderer are complete.

3. **projectStatus.md sprint catch-up** — file is missing v2.11.x and v2.12.x
   history; needs a full update.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.12.2
- Last commit: `83e70a4` — pushed, working tree clean

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
   silent (no UI row on Plugins screen or Dashboard > Updates). Delivery options:
   auto-update filter, WP-CLI, or manual zip. See `docs/white-label-configuration.md`.

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
