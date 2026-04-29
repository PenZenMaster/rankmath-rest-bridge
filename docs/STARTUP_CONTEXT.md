# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-04-29
**Branch:** main
**Version:** 2.9.2
**Last Commit:** 0ad343f — fix: legacy namespace alias, register_post_meta, first-paragraph fallback (v2.9.2)

---

## Last 3 Accomplishments

1. **Three P1 gap fixes (v2.9.2)** — All three P1 items from `docs/Gap-Priority-Notes.csv`
   resolved: (a) `rankmath-bridge/v1` legacy namespace alias via `rest_pre_dispatch` proxy —
   old automation clients get working responses + `_deprecated` field instead of 404;
   (b) `register_post_meta` for `_rrseo_llms_section` — `show_in_rest`, WP sanitize layer,
   `auth_callback` all wired; (c) first-paragraph description fallback bug fixed — was
   normalizing content before splitting on paragraph boundaries, causing all content-only
   posts to fall through to thin_description title fallback.

2. **Force Update Check + admin llms.txt tab (v2.9.1)** — `POST /check-updates` clears both
   the `update_plugins` transient and PUC's `external_updates-rankmath-rest-bridge` option,
   then calls `wp_update_plugins()`. Admin Overview gets a one-click "Force Update Check"
   button (replaces WP-CLI-only workflow). Admin llms.txt tab fully rebuilt: shows
   business_facts, section classifier table, exclude patterns, boolean flags, and a lazy-loaded
   "Preview Generated llms.txt" panel with section counts, warnings, excluded URLs, and full
   content. PUC check period now filterable via `rrseo_puc_check_period_hours`.

3. **P0 + P1 crawl sync spec (v2.8.0 + v2.9.0)** — Shared `rr_get_canonical_url_set()`
   helper wired into all sitemaps, llms.txt, and `/sitemap/preview`; section classifier
   (`rr_classify_url_section`), `GET /llms/preview`, `_rrseo_llms_section` per-post meta,
   expanded `/llms` config (business_facts, sections object, exclude_patterns,
   max_description_chars), `GET /images/{id}/alt`, `/images/bulk-alt` batch cap,
   `/status?include_counts=true` with transient cache.

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
- Version: 2.9.2
- Last commit: 0ad343f — pushed, working tree clean
- All releases v2.3.1–v2.9.2 built and pushed to GitHub

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
