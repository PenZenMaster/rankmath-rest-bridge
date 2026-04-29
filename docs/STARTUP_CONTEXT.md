# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-04-29
**Branch:** main
**Version:** 2.4.0
**Last Commit:** pending (2026-04-29 session)

---

## Last 3 Accomplishments

1. **Testing stack + pre-commit hook (2026-04-29)** — `composer.json` (PHPUnit 9.x, phpcs/wpcs,
   vendor-dir: dev-vendor), `phpcs.xml.dist` (WordPress-Core/Extra/Docs), `phpunit.xml.dist`,
   `tests/bootstrap.php` (WP stubs), 35 unit tests across SeoValidationTest, SchemaValidationTest,
   ManifestTest. `hooks/pre-commit` committed; auto-installed by `composer install`.
   Run `composer run qa` to execute lint + tests after `composer install`.

2. **POST /migrate-legacy (v2.4.0)** — New endpoint batch-copies `rank_math_*` values into
   `rr_seo_*` keys per post. Skips fields where native key is already set; skips posts not found
   or not in the allowed post-type list. Supports `dry_run: true` to preview without writing.
   All migrations written to audit log via `rr_audit_log()`.

3. **Auto-update repaired (2026-04-29)** — `update-manifest.json` fixed (missing `name` field +
   `zip_url` → `download_url`). Release zips v2.3.1 and v2.4.0 built and pushed to GitHub.
   Staging verify checklist in `docs/staging-verify-autoupdate.md`.

---

## Next 3 Priorities

1. **[CRITICAL] Staging verify auto-update** — Follow `docs/staging-verify-autoupdate.md`.
   Requires WP-CLI + staging server access. Clear PUC transient, bump manifest to a test version,
   confirm WP Dashboard shows update notice, confirm `POST /self-update` succeeds.

2. **Activate + validate test suite** — Run `composer install` on dev machine to install PHPUnit +
   phpcs. Then `composer run qa` to see current lint violations. Fix violations as a dedicated task.

3. **Verify llms.txt upload** — Check whether `POST /llms` supports uploading raw arbitrary
   llms.txt content (not just structured intro/sections). If not, add a `raw_content` field that
   bypasses dynamic generation. See projectStatus.md backlog for full scope.

---

## Current State

**Git:**
- Branch: main
- Version: 2.4.0
- Working tree: DIRTY — all 2026-04-29 session changes pending commit/push

**Plugin file:** `rankmath-rest-bridge.php` (~1545 lines)
**GitHub repo:** https://github.com/PenZenMaster/rankmath-rest-bridge

**Blockers:**
- `composer install` not yet run on dev machine — phpcs/phpunit not yet active
- Staging verify not yet done — auto-update assumed correct but untested on live WP

---

## Key Context Notes

1. **Auto-update is a critical plugin feature** — PUC (`vendor/plugin-update-checker`) hooks into
   WP's native update system using `update-manifest.json` on GitHub raw. The manifest MUST have
   `name`, `version`, and `download_url` fields (PUC silently fails otherwise). The zip MUST have
   `rankmath-rest-bridge/` as its top-level folder and include the full `vendor/` tree.

2. **Testing setup: dev-vendor, not vendor** — `composer.json` uses `"vendor-dir": "dev-vendor"`
   to avoid conflict with the manually committed `vendor/plugin-update-checker/`. The PUC library
   is committed directly; dev tools (phpunit, phpcs) install to `dev-vendor/`. Do not run
   `composer install` as root in production; dev-vendor is gitignored.

3. **replace-all is deprecated** — endpoint still works but response includes `deprecated` field.
   Per-snippet CRUD (`POST /snippets`, `POST /snippets/{id}`, `DELETE /snippets/{id}`) is the
   preferred path. Target removal: v3.0.0.
