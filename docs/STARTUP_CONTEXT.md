# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-15
**Branch:** main
**Version:** 2.14.3
**Last Commit:** 97cb348 — chore: release v2.14.3 zip

---

## Last 3 Accomplishments

1. **v2.14.1 shipped** — G-12 fatal fixed: `rmb_llms_regenerate()` was passing
   the array returned by `rr_render_llms_txt()` to `substr_count()`/`strlen()`;
   unpacked `$result['content']` first. Validated clean (all 4 payload variants
   return 200, deterministic md5).

2. **v2.14.2 shipped** — FU-2: `unset_fields` array parameter added to
   `POST /update`. Explicit meta deletion for posts and terms. Returns 422 on
   unknown fields or write/unset conflicts. Audited via `rr_audit_log()`.

3. **v2.14.3 shipped** — FU-1b `line_count` off-by-one fixed; FU-4
   `rrseo_rest_fatal_handler()` shutdown function added (catches PHP fatals
   during REST requests, emits clean JSON 500); FU-3/FU-5 README.md created
   with REST API reference, `/update` term-meta support, and headless
   `/check-updates` + `/self-update` workflow documented.

---

## Next 3 Priorities

1. **Salvo staging verify** — install v2.14.3 zip; confirm
   `term:product_cat:<slug>` snippets fire on WooCommerce taxonomy archive
   pages (G-01 end-to-end, first live WC test).

2. **rrc-mu-toolkit GitHub remote + retire sequence** — create GitHub remote,
   push. Then retire `RRC_SEO_TAX_META_DESC` and `RRC_SEO_TAX_META_OG` on
   Salvo: disable constant → staging verify → remove code → commit.

3. **Choose next plugin gap** — highest practical value options:
   G-10 (bulk snippet POST), G-09 (sitemap term exclusion config),
   G-13 (snippet emission action hooks — low effort).

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.14.3
- Last commit: `97cb348` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~4,120 lines)
- README: `README.md` — created this session (REST API reference)
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — 19 gaps;
  G-01/02/03/08/11/12 done in v2.13.x–v2.14.0
- Validation reports: v2.13.0, v2.13.1, v2.14.0, v2.14.1 all in `docs/`
- Release builder: `bin/build-zip.ps1` — always use for releases
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote yet

**Blockers:**
- None. All FUs from v2.14.0/v2.14.1 reports closed.

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
8. Wait 2-3 min for GitHub CDN, then POST /check-updates + POST /self-update
```

---

## Key Context Notes

1. **G-01 fully open in v2.14.0** — `term:<tax>:<slug>` and `tax:<tax>` accepted
   at write time and handled by `rmb_snippet_matches_display()`. `url:` remains
   gated. G-01 end-to-end on WooCommerce (Salvo) not yet verified.

2. **unset_fields on /update (v2.14.2)** — sending empty string for a field is
   still a no-op (by design). Use `unset_fields: ["field"]` to explicitly
   delete. Works for both posts and terms.

3. **rrseo_rest_fatal_handler (v2.14.3)** — registered on `rest_api_init`.
   Catches PHP fatals, discards HTML error output, emits clean JSON 500. Covers
   the full REST surface, not just the G-12 endpoint.

4. **rrc-mu-toolkit retire sequence** — when shifting to that project, first
   task is creating the GitHub remote, then retiring `RRC_SEO_TAX_META_DESC`
   and `RRC_SEO_TAX_META_OG`. Order: disable constant → staging verify →
   remove module code → commit.

5. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates are
   silent. Delivery via WP-CLI or manual zip or headless `/self-update`.
   See `docs/white-label-configuration.md`.
