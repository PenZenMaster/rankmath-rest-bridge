# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-04-28
**Branch:** main
**Version:** 2.3.1
**Last Commit:** d39f465 - feat: harden snippets/replace-all with custom capability + deprecation (v2.3.1)

---

## Last 3 Accomplishments

1. **Mental model rename + native meta keys (v2.2.0)** — Plugin renamed to "RankRocket SEO Control Layer".
   REST namespace changed from `rankmath-bridge/v1` to `rankrocket-seo/v1`. Native `rr_seo_*`
   post-meta keys introduced as primary store; `rank_math_*` keys demoted to read-only migration
   fallback via `rr_get_seo_meta()` helper.

2. **Schema model, preview endpoint, validation layer, audit log (v2.3.0)** — `GET/POST /schema/{post_id}`
   stores JSON-LD in `_rrseo_schema_graph`, injected into `wp_head`. `POST /preview-update` returns
   before/after diff with errors/warnings, no DB write. Hard validation on title (120 char max),
   description (320 char max), OG image URL, robots values, JSON-LD structure, schema @type allowlist,
   post type allowlist, batch max 20. Audit log in `_rrseo_change_log` (capped 100/post),
   `GET /log/{post_id}` endpoint. Both allowlists extensible via `apply_filters`.

3. **replace-all hardened (v2.3.1)** — `rrseo_replace_all_snippets` custom WordPress capability
   created. Auto-granted to administrator role on first load (idempotent DB write). Route permission
   callback checks the custom cap instead of broad `manage_options`. Success response includes
   `deprecated` field pointing to per-snippet endpoints; target removal v3.0.0.

---

## Next 3 Priorities

1. **phpcs quality gate** — Add `phpcs.xml.dist` configured for WordPress Coding Standards.
   Run against `rankmath-rest-bridge.php` and fix any violations. This unblocks the §4 quality gate.

2. **Release packaging** — Create `releases/v2.3.1/rankmath-rest-bridge.zip` and push so the
   self-update manifest's `download_url` resolves to a real file. Verify `POST /self-update` works
   end-to-end on a staging site.

3. **Legacy migration endpoint** — `POST /rankrocket-seo/v1/migrate-legacy` accepts `post_ids[]`,
   copies `rank_math_*` values into `rr_seo_*` keys for each post (skip if native key already set),
   returns per-post before/after and writes to audit log. Lets pipeline operators self-service the
   one-time migration without direct DB access.

---

## Current State

**Git:**
- Branch: main
- Version: 2.3.1
- Last commit: d39f465 (local only — not yet pushed to GitHub)
- Working tree: clean

**Plugin file:** `rankmath-rest-bridge.php` (single-file plugin, ~1300 lines)
**GitHub repo:** https://github.com/PenZenMaster/rankmath-rest-bridge

**Blockers:**
- No `phpcs.xml.dist` → quality gate not runnable
- No zip in `releases/v2.3.1/` → self-update manifest has a broken download URL

---

## Key Context Notes

1. **File name vs plugin identity** — `rankmath-rest-bridge.php` is the WordPress plugin slug
   (matches GitHub repo folder). The Plugin Name header says "RankRocket SEO Control Layer". These
   can diverge safely; only rename the file if the GitHub repo is also renamed.

2. **replace-all is deprecated** — endpoint still works but response includes `deprecated` field.
   Per-snippet CRUD (`POST /snippets`, `POST /snippets/{id}`, `DELETE /snippets/{id}`) is the
   preferred path. Target removal: v3.0.0.

3. **rr_seo_score has no native key yet** — `rmb_get_meta` and `rmb_meta_bulk_get` still read
   `rank_math_seo_score` directly. When a native scoring feature lands, introduce `rr_seo_score`
   postmeta and update those two handlers.
