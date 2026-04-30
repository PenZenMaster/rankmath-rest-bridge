# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-04-29
**Branch:** main
**Version:** 2.9.3
**Last Commit:** a9ed4ab — fix: schema visible in footer + PUC loader path mismatch (v2.9.3)

---

## Last 3 Accomplishments

1. **Two regression fixes verified on production (v2.9.3)** — (a) Schema JSON-LD was
   rendering as visible plain text in the site footer because `wp_kses_post()` strips
   `<script>` tags but leaves inner content; replaced with raw echo (admin-only,
   capability-guarded at write). (b) PUC auto-update notifications were silently disabled
   because `build-zip.ps1` flattened the `plugin-update-checker/` directory directly into
   `vendor/` — the `file_exists()` loader check always failed. Fixed build script to use
   explicit mkdir + wildcard copy; both issues confirmed resolved on shadesofwhitellc.com.

2. **Three P1 gap fixes (v2.9.2)** — Legacy `rankmath-bridge/v1` namespace alias via
   `rest_pre_dispatch` proxy; `register_post_meta` for `_rrseo_llms_section`; first-paragraph
   description fallback bug (content was normalized before paragraph splitting).

3. **Force Update Check + admin llms.txt tab (v2.9.1)** — `POST /check-updates` clears
   `update_plugins` transient + PUC cache then calls `wp_update_plugins()`. Admin llms.txt
   tab rebuilt with lazy-loaded preview panel. PUC check period filterable via
   `rrseo_puc_check_period_hours`.

---

## Next 3 Priorities

1. **Staging auto-update end-to-end verify** — v2.9.1+ adds the Force Update Check button.
   Install v2.9.2 on staging via `POST /self-update`, use the button to force a re-check,
   confirm WP Dashboard shows the update. Full steps in `docs/staging-verify-autoupdate.md`.
   Set `add_filter('rrseo_puc_check_period_hours', fn() => 1)` in staging wp-config for faster cycles.

2. **Remaining P2 gaps from `docs/Gap-Priority-Notes.csv`**:
   - Self-canonical/redirect: check WordPress `get_canonical_url()` per post and exclude
     URLs whose canonical differs from `get_permalink()` (easy, 10 lines)
   - `/sitemap_index.xml` lastmod: derive from canonical set instead of raw `get_posts()`
   - `/canonical-urls/preview` alias endpoint (P2, already in plan)
   - Expand test-placeholder pattern list (P2)

3. **Docs & projectStatus catch-up** — `projectStatus.md` still shows v2.7.0 as current.
   Needs a session entry for v2.8.0–v2.9.2, updated version history table, and backlog
   items checked off (llms.txt structured config, P0/P1 crawl sync, namespace alias, etc.).

---

## Current State

**Git:**
- Branch: main
- Version: 2.9.3
- Last commit: a9ed4ab — pushed, working tree clean
- All releases v2.3.1–v2.9.3 built and pushed to GitHub

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~2,700 lines)
- Canonical set: `includes/class-rrseo-canonical.php`
- llms generator: `includes/class-rrseo-llms.php`
- Admin panel: `includes/class-rrseo-admin.php`, `admin.js`, `admin.css`
- Meta box: `includes/class-rrseo-metabox.php`, `metabox.js`
- Sitemap XSL: `includes/sitemap.xsl`
- Release builder: `bin/build-zip.ps1` — always use this; never manual PS one-liners
- Gap tracker: `docs/Gap-Priority-Notes.csv` — P1s all done; P2/P3 remain
- Staging checklist: `docs/staging-verify-autoupdate.md`

**Blockers:**
- Staging auto-update end-to-end test not yet run on a live site
- `composer install` not yet run on dev machine (phpunit/phpcs not active locally)

---

## Key Context Notes

1. **Always use `bin/build-zip.ps1` for releases** — never manual PowerShell one-liners.
   The v2.9.0 zip was accidentally rebuilt this session by a manual build command and had
   to be restored via `git restore`. The script verifies 4 structural requirements (PUC
   Puc/v5p5/, includes/, plugin file, loader) and exits non-zero on failure.

2. **Legacy namespace alias works via proxy, not duplicate registration** — `rr_legacy_namespace_proxy()`
   uses `rest_pre_dispatch` to intercept `/rankmath-bridge/v1/...` and re-dispatch to
   `/rankrocket-seo/v1/...`. No route duplication. Every proxied response gets `_deprecated`
   with the preferred namespace.

3. **Gap-Priority-Notes.csv is the active defect tracker** — all P1 items resolved in v2.9.2.
   Remaining: P2 (self-canonical check, sitemap index lastmod, canonical-urls/preview alias,
   placeholder list expansion) and P3 (pattern lowercasing, non-issue).
