# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-04-29
**Branch:** main
**Version:** 2.7.0
**Last Commit:** 74a7257 — chore: add bin/build-zip.ps1 release builder + correct v2.7.0 zip

---

## Last 3 Accomplishments

1. **GET/POST /robots-txt + release build script (v2.7.0)** — New REST endpoint lets the
   pipeline write arbitrary robots.txt content via the `rrseo_robots_txt` option; `robots_txt`
   filter at priority 99 serves it. Detects physical `robots.txt` at webroot and warns.
   `bin/build-zip.ps1` standardises release builds with 4 structural checks (PUC Puc/v5p5/,
   includes/, plugin file, loader); fixed a zip-structure bug that caused v2.7.0 activation
   failure (flattened vendor directory).

2. **Styled sitemaps + per-type sub-sitemaps (v2.6.0)** — `includes/sitemap.xsl` served by
   PHP renders all sitemap XML as browser-readable HTML (dark navy header, priority badges).
   `/sitemap_index.xml` now lists `/rmb-sitemap-posts.xml` + `/rmb-sitemap-pages.xml` separately;
   prior single-entry index was semantically a no-op. Accurate UTC lastmod via `post_modified_gmt`.

3. **WordPress admin panel + Edit Post meta box (v2.5.0)** — 6-page admin menu (Overview, Posts
   & Pages, Image ALT, Snippets, llms.txt, Sitemap) backed by existing REST endpoints; zero
   front-end cost (`is_admin()` gate). Read-only sidebar meta box on Edit Post/Page shows SEO
   fields, schema type, char-count badges, and last 3 audit entries via parallel REST fetch.

---

## Next 3 Priorities

1. **[HIGH] llms.txt structured config** — User requested full config schema during this session;
   NOT YET IMPLEMENTED. Needed fields:
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
   Both `POST /llms` and the dynamic `/llms.txt` output must honour the new schema.

2. **[CRITICAL] Staging verify auto-update** — Follow `docs/staging-verify-autoupdate.md`.
   Requires WP-CLI + staging server access. Clear PUC transient, bump manifest version,
   confirm WP Dashboard update notice, confirm `POST /self-update` succeeds end-to-end.

3. **Admin panel llms.txt tab** — Update the admin panel llms.txt page to display/edit
   the new structured config fields once the schema is implemented.

---

## Current State

**Git:**
- Branch: main
- Version: 2.7.0
- Last commit: 74a7257 — pushed, working tree clean
- All releases v2.3.1–v2.7.0 built and pushed to GitHub

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~2,400 lines)
- Admin classes: `includes/class-rrseo-admin.php`, `includes/class-rrseo-metabox.php`
- Admin assets: `includes/admin.js`, `includes/metabox.js`, `includes/admin.css`
- Sitemap XSL: `includes/sitemap.xsl`
- Release builder: `bin/build-zip.ps1` — always use this for future zips
- GitHub repo: https://github.com/PenZenMaster/rankmath-rest-bridge

**Blockers:**
- llms.txt structured config requested but not implemented
- Staging auto-update verify not yet done
- `composer install` not yet run on dev machine (phpunit/phpcs not active locally)

---

## Key Context Notes

1. **Always use `bin/build-zip.ps1` for releases** — never build zips manually with
   PowerShell one-liners. A `Get-ChildItem -Recurse | Copy-Item` pattern silently flattens
   the `vendor/plugin-update-checker/Puc/v5p5/` sub-tree, causing fatal errors on plugin
   activation after update. The script verifies 4 structural requirements before exiting.

2. **Auto-update is a critical plugin feature** — PUC manifest requires `name`, `version`,
   and `download_url`. The zip must have `rankmath-rest-bridge/` at the top level and include
   the full `vendor/` tree. Physical `robots.txt` in webroot bypasses the plugin's robots.txt
   filter — same caveat applies to the robots.txt endpoint.

3. **llms.txt structured config is the immediate next task** — the user explicitly requested
   this config schema mid-session. It was NOT implemented. Do not start other work until this
   is delivered.
