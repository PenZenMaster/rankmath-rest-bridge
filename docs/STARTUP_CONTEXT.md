# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-15
**Branch:** main
**Version:** 2.17.3
**Last Commit:** eac8587 — chore: release v2.17.3 zip

---

## Last 3 Accomplishments

1. **v2.17.3 shipped** — LiteSpeed page cache purge (`rrseo_purge_rest_cache()`
   fires `litespeed_purge_url` for `/status` and `/snippets` after every write).
   G-10 individual `POST /snippets` slug collision fixed (while-loop `_1`/`_2`/`_3`
   increment, same as bulk). Closes Cache-A/B completely.

2. **v2.17.2 shipped** — `rrseo_bust_option_cache()` added after all 8
   `update_option()` write sites (WP object cache bust). G-10 bulk slug
   collision fixed with increment scheme. FU-2 documented: `unset_fields`
   is the correct clear mechanism; empty string is intentional no-op.

3. **FU-2 closed** — confirmed working in v2.17.2 validation: `unset_fields:
   ["title","description"]` correctly deletes stored meta and is audited.
   Three prior reports flagged it as open because they tested empty strings.

---

## Next 3 Priorities

1. **Salvo staging verify** — install v2.17.3; configure `POST /perf/dequeue-rules`
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
- Version: 2.17.3
- Last commit: `eac8587` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~4,700+ lines)
- Validation reports: v2.13.0–v2.17.2 all in `docs/`
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — all gaps closed
  except G-13 (observability), G-14 (per-user, partial), G-15 (hreflang, deferred)
- Architecture doc: `docs/aeo_geo_google_data_architecture.md` — external audit
  engine spec; plugin role already fulfilled by existing endpoints
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote yet

**Blockers:**
- None. v2.17.3 is ship-quality.

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

1. **Cache architecture (v2.17.x)** — writes now bust three layers: DB (always),
   WP object cache (`rrseo_bust_option_cache`), and LiteSpeed page cache
   (`rrseo_purge_rest_cache`). If another cache plugin is added, wire its URL
   purge into `rrseo_purge_rest_cache()`. Server config: exclude `/wp-json/`
   from page cache entirely for best results.

2. **Mu-plugin retirement is staged, not complete** — five modules are retirable
   but none have been removed yet. Salvo staging verify must come first. The
   mu-plugin stays as a telemetry tool after all modules are retired.

3. **display_on_user (v2.17.0)** — `all|anonymous|logged_in`. Existing snippets
   without the field default to `all`. Logged-in-side emission needs manual
   verification (G-14 — Basic Auth doesn't establish a WP session).

4. **unset_fields (v2.14.2)** — the correct way to clear stored meta via REST.
   Empty string on `/update` is intentionally a no-op (prevents accidental wipes
   from blank template renders). Documented in README.

5. **rrc-mu-toolkit retire sequence** — order matters: disable constant first,
   do staging verify, then remove code, then commit. Never skip staging verify.
