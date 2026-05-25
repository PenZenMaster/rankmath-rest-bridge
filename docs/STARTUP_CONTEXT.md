# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-25
**Branch:** main
**Version:** 2.17.4
**Last Commit:** 96f3dea — chore: mark tested up to WordPress 7.0

---

## Last 3 Accomplishments

1. **v2.17.4 shipped** — Duplicate `GET /canonical-urls/preview` route removed
   (stale AEO/GEO stub pointing to undefined `rmb_canonical_urls_preview`; latent
   fatal, not a crash). G-18 alias over `rmb_sitemap_preview` remains
   authoritative. WordPress 7.0 added to `Tested up to` header and manifest.

2. **v3.0 architecture decided** — Shape B adopted: plugin stays lean (observation
   + typed executor endpoints only). Agentic runtime retargeted to external Audit
   Engine. See `docs/plugin-v3-executor-spec.md`.

3. **v2.17.3 shipped** — LiteSpeed page cache purge (`rrseo_purge_rest_cache()`);
   G-10 individual `POST /snippets` slug collision fixed with while-loop increment.
   Cache-A/B closed completely.

---

## Next 3 Priorities

1. **Salvo staging verify** — install v2.17.4; configure `POST /perf/dequeue-rules`
   to replace `RRC_SEO_WC_DEQUEUE`; configure `POST /perf/defer-handles` to
   replace `RRC_SEO_DEFER_NONCRIT`; verify `term:product_cat:<slug>` snippets
   fire on WooCommerce taxonomy archives (G-01 end-to-end, not yet verified on
   WooCommerce). Then retire the two perf mu-plugin modules.

2. **rrc-mu-toolkit GitHub remote + retire sequence** — create GitHub remote,
   push. Retire mu-plugin modules in order (disable constant → staging verify →
   remove code → commit):
   - `RRC_SEO_DEDUP_CANONICAL` (lowest risk — consolidate_canonical is default)
   - `RRC_SEO_TAX_META_DESC` + `RRC_SEO_TAX_META_OG` (after G-01 WC verified)
   - `RRC_SEO_WC_DEQUEUE` + `RRC_SEO_DEFER_NONCRIT` (after perf module verified)

3. **G-14 logged-in emission manual check** — log in to WP admin on rankrocket.co,
   view front-end with a `display_on_user: logged_in` snippet active; confirm
   it fires for logged-in and suppresses for anonymous visitors. Basic Auth does
   NOT establish a WP session so this can't be verified via curl.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.17.4
- Last committed: `96f3dea` — pushed
- Uncommitted: none

**Files of note:**
- Plugin: `rankmath-rest-bridge.php`, `includes/` (6 helper classes)
- **v3.0 spec:** `docs/plugin-v3-executor-spec.md` — authoritative Shape B plugin spec
- Agentic spec archived: `docs/archive/agentic-seo-plugin-spec-original.md`
- Architecture boundary: `docs/aeo_geo_google_data_architecture.md:75-108`
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote yet

**Blockers:**
- None. v2.17.4 is ship-quality.

---

## Release Checklist (run for every version bump)

```
1. Bump version in rankmath-rest-bridge.php plugin header + RMB_VERSION constant
2. Update update-manifest.json  — version + download_url (releases/vX.Y.Z/)
3. Update CHANGELOG.md
4. git add + git commit  (conventional: "feat/fix/chore: ...")
5. .\bin\build-zip.ps1   — must pass all 4 structural checks
6. git add releases/vX.Y.Z/  && git commit  ("chore: release vX.Y.Z zip")
7. git push
8. Wait 2-3 min for GitHub CDN, then POST /self-update on target site
```

---

## Key Context Notes

1. **v3.0 boundary (2026-05-21)** — plugin v3.0 scope is observation endpoints + typed
   executor endpoints only. Agentic runtime (scan orchestration, AI reasoning, policy engine,
   approval queue, OAuth, portal sync) lives in the external Audit Engine, not this repo.
   See `docs/plugin-v3-executor-spec.md`. v3.0 starts after white-label milestone lands.

2. **Cache architecture (v2.17.x)** — writes now bust three layers: DB (always),
   WP object cache (`rrseo_bust_option_cache`), and LiteSpeed page cache
   (`rrseo_purge_rest_cache`). If another cache plugin is added, wire its URL
   purge into `rrseo_purge_rest_cache()`. Server config: exclude `/wp-json/`
   from page cache entirely for best results.

3. **Mu-plugin retirement is staged, not complete** — five modules are retirable
   but none have been removed yet. Salvo staging verify must come first. The
   mu-plugin stays as a telemetry tool after all modules are retired.

4. **display_on_user (v2.17.0)** — `all|anonymous|logged_in`. Existing snippets
   without the field default to `all`. Logged-in-side emission needs manual
   verification (G-14 — Basic Auth doesn't establish a WP session).

5. **unset_fields (v2.14.2)** — the correct way to clear stored meta via REST.
   Empty string on `/update` is intentionally a no-op (prevents accidental wipes
   from blank template renders). Documented in README.
