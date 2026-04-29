# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-04-29
**Branch:** main
**Version:** 2.3.1
**Last Commit:** 7f55bc0 — chore(checkpoint): 2026-04-28_1946

---

## Last 3 Accomplishments

1. **Auto-update repaired (2026-04-29)** — Two bugs fixed in `update-manifest.json`: (a) missing
   `name` field caused PUC `validateMetadata()` to return `WP_Error` silently; (b) field was named
   `zip_url` but PUC and the REST `/self-update` handler both require `download_url`. Manifest
   now includes all required PUC fields (`name`, `slug`, `download_url`, `sections`, `author`,
   `requires`, `requires_php`, `tested`). Release zip `releases/v2.3.1/rankmath-rest-bridge.zip`
   built with correct `rankmath-rest-bridge/` top-level folder and full vendor tree (118 entries,
   ~177 KB). Needs push to GitHub to resolve the download URL on live sites.

2. **replace-all hardened (v2.3.1)** — `rrseo_replace_all_snippets` custom WordPress capability
   created. Auto-granted to administrator role on first load (idempotent DB write). Route permission
   callback checks the custom cap instead of broad `manage_options`. Success response includes
   `deprecated` field pointing to per-snippet endpoints; target removal v3.0.0.

3. **Schema model, preview endpoint, validation layer, audit log (v2.3.0)** — `GET/POST /schema/{post_id}`
   stores JSON-LD in `_rrseo_schema_graph`, injected into `wp_head`. `POST /preview-update` returns
   before/after diff with errors/warnings, no DB write. Hard validation on title (120 char max),
   description (320 char max), OG image URL, robots values, JSON-LD structure, schema @type allowlist,
   post type allowlist, batch max 20. Audit log in `_rrseo_change_log` (capped 100/post),
   `GET /log/{post_id}` endpoint. Both allowlists extensible via `apply_filters`.

---

## Next 3 Priorities

1. **[CRITICAL] Push & verify auto-update end-to-end** — Commit manifest fix + zip, push to GitHub.
   On a staging site: clear the PUC transient (`delete_site_transient('update_plugins')`), bump the
   manifest version to a test value, confirm WP Dashboard > Updates shows the plugin. Then restore
   and confirm one-click upgrade runs `POST /self-update` cleanly.

2. **Testing infrastructure + pre-commit hooks** — No test suite or active git hooks exist.
   Stack: `composer.json` (PHPUnit + WP test suite + wpcs + phpcs), `phpunit.xml.dist`,
   `tests/` with unit tests for validators and meta helpers, `phpcs.xml.dist`, and a
   `.git/hooks/pre-commit` that runs phpcs on every commit. See projectStatus.md backlog
   for full task breakdown.

3. **phpcs quality gate** — `phpcs.xml.dist` configured for WordPress Coding Standards; run
   against `rankmath-rest-bridge.php` and fix violations. Can land before full PHPUnit suite.

---

## Current State

**Git:**
- Branch: main
- Version: 2.3.1
- Last commit: 7f55bc0 — chore(checkpoint): 2026-04-28_1946 (pushed)
- Working tree: DIRTY — manifest fix + v2.3.1 zip staged for commit

**Plugin file:** `rankmath-rest-bridge.php` (single-file plugin, ~1445 lines)
**GitHub repo:** https://github.com/PenZenMaster/rankmath-rest-bridge

**Blockers:**
- Zip + manifest fix not yet pushed → auto-update broken on live sites until pushed
- No `phpcs.xml.dist` → quality gate not runnable

---

## Key Context Notes

1. **Auto-update is a critical plugin feature** — The plugin must self-update cleanly through the
   WordPress admin (Dashboard > Updates). PUC (`vendor/plugin-update-checker`) hooks into WP's
   native update system using `update-manifest.json` on GitHub raw. The manifest MUST have `name`,
   `version`, and `download_url` fields (PUC will silently fail otherwise). The zip MUST have
   `rankmath-rest-bridge/` as its top-level folder and include the full `vendor/` tree.

2. **File name vs plugin identity** — `rankmath-rest-bridge.php` is the WordPress plugin slug
   (matches GitHub repo folder). The Plugin Name header says "RankRocket SEO Control Layer". These
   can diverge safely; only rename the file if the GitHub repo is also renamed.

3. **replace-all is deprecated** — endpoint still works but response includes `deprecated` field.
   Per-snippet CRUD (`POST /snippets`, `POST /snippets/{id}`, `DELETE /snippets/{id}`) is the
   preferred path. Target removal: v3.0.0.
