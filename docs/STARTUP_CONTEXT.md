# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-01 (shutdown)
**Branch:** main
**Version:** 2.11.2
**Last Commit:** 3c60ab8 — chore(checkpoint): 2026-05-01_2350

---

## Last 3 Accomplishments

1. **v2.11.2 shipped** — robots.txt read-side stale-state bug fixed. POST
   /robots-txt now busts WP object cache after `update_option` so GET
   /robots-txt returns live content instead of a cached empty string.
   `clearstatcache` added to GET, POST, and status handlers. Status bypass
   warning no longer fires when plugin manages the physical file.

2. **v2.11.1 shipped** — fixed snippet renderer ignoring `display_on: all`.
   Switch statement handled `entire_website` but not `all` (the value
   RankRocket sends); added `all` as primary case plus `home`/`homepage`
   aliases for front_page.

3. **v2.11.0 shipped** — robots.txt written as a physical file on every POST
   save. Activation hook syncs existing DB content on upgrade. Deactivation
   confirmation dialog added to Plugins screen.

---

## Next 3 Priorities

1. **Staging auto-update verify** — install v2.11.2 on staging, click
   "Check for Updates" on Dashboard > Updates, confirm PUC delivers zip and
   installs cleanly. Steps in `docs/staging-verify-autoupdate.md`.

2. **P2/P3 gap items** — review `docs/Gap-Priority-Notes.csv` for next
   backlog items now that AEO/GEO layer is complete.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.11.2
- Last commit: `3c60ab8` — pushed, up to date with origin/main
- Working tree: clean (3 untracked: `.claude/settings.local.json`,
  `.phpunit.result.cache`, `composer.lock`)

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~2,810 lines)
- AEO/GEO layer: `includes/class-rrseo-aeo-geo.php`
- Canonical set: `includes/class-rrseo-canonical.php`
- llms generator: `includes/class-rrseo-llms.php`
- Admin panel: `includes/class-rrseo-admin.php`, `admin.js`, `admin.css`
- Release builder: `bin/build-zip.ps1` — always use this for releases
- Gap tracker: `docs/Gap-Priority-Notes.csv` — P1s done; P2/P3 remain
- Staging checklist: `docs/staging-verify-autoupdate.md`

**Blockers:**
- None. v2.11.2 on main, pushed, zip committed.

---

## Key Context Notes

1. **Always use `bin/build-zip.ps1 v<version>` for releases** — script
   verifies 4 structural requirements (PUC Puc/v5p5/, includes/, plugin
   file, loader) and exits non-zero on failure. Pass version without double-v
   (e.g. `v2.11.2`), then rename `releases/vv*/` to `releases/v*/` if the
   double-v bug reappears.

2. **robots.txt option key is `rrseo_robots_txt`** — not
   `rrseo_robots_txt_content`. Any tooling or audit engine reading this
   option must use the correct key.

3. **AEO/GEO plugin boundary** — plugin is a read-only data provider. OAuth,
   GSC/GA4/GBP fetchers, normalization, and report generation live in the
   external audit engine. Plugin exposes 5 REST endpoints for the engine.

4. **WPCS installed globally** — `wp-coding-standards/wpcs` v3.1 installed
   via `composer global require`. `phpcs --standard=phpcs.xml.dist` and
   `composer run lint` both work locally without the pre-commit hook.

5. **Legacy namespace alias** — `rr_legacy_namespace_proxy()` intercepts
   `/rankmath-bridge/v1/...` via `rest_pre_dispatch` and re-dispatches to
   `/rankrocket-seo/v1/...`. No route duplication.
