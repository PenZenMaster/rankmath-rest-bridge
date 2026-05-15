# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-14
**Branch:** main
**Version:** 2.14.0
**Last Commit:** b71631b — chore: release v2.14.0 zip

---

## Last 3 Accomplishments

1. **v2.13.1 shipped and validated** — 422 gate on `term:`, `tax:`, `url:`
   `display_on` patterns (silent failures become loud); whitespace normalization
   in `rmb_resolve_tokens()` fixed leading space in `<title>` and double space
   in `<meta name="description">`. Both fixes verified on live site. G-01
   emitter proven working via `term_id:21` live probe.

2. **v2.14.0 shipped** — 7 items: G-01 gate lifted (`term:`, `tax:` accepted);
   `post_id:` alias restored; G-02 422 in `/preview-update` for term IDs; G-03
   `consolidate_canonical` in `/status`; G-08 `emit_routing_version: 2` in
   `/status`; G-11 `GET /snippets/<slug>`; G-12 `POST /llms-txt/regenerate`.
   Lint-clean, zip built and pushed.

3. **Validation reports committed** — `docs/RankRocket_v2_13_0_Validation_Report.md`
   and `docs/RankRocket_v2_13_1_Validation_Report.md` in repo as permanent record.

---

## Next 3 Priorities

1. **Validate v2.14.0 on live site** — install update, then verify:
   `term:category:uncategorized` snippet accepted (200, no longer 422);
   `GET /snippets/<slug>` returns record; `POST /llms-txt/regenerate` returns
   200 with `line_count`/`byte_size`; `/status` includes `emit_routing_version`
   and `consolidate_canonical`.

2. **Salvo staging verify** — install v2.14.0 zip; confirm `term:product_cat:<slug>`
   snippets fire on taxonomy archive pages (G-01 end-to-end). This is the first
   live test of the lifted gate on a WooCommerce site.

3. **rrc-mu-toolkit GitHub remote + retire sequence** — create GitHub remote,
   push. Then retire `RRC_SEO_TAX_META_DESC` and `RRC_SEO_TAX_META_OG` on
   Salvo: disable constant → staging verify → remove code → commit.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.14.0
- Last commit: `b71631b` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~4,060 lines)
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — 19 gaps;
  G-01/02/03/08/11/12 done in v2.13.x–v2.14.0
- Validation reports: `docs/RankRocket_v2_13_0_Validation_Report.md`,
  `docs/RankRocket_v2_13_1_Validation_Report.md`
- Release builder: `bin/build-zip.ps1` — always use for releases
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote yet

**Blockers:**
- None.

---

## Release Checklist (run for every version bump)

```
1. Bump version in rankmath-rest-bridge.php plugin header + RMB_VERSION constant
2. Update update-manifest.json  — version + download_url (releases/vX.Y.Z/)
3. Update CHANGELOG.md
4. git add + git commit  (conventional: "chore: bump version to X.Y.Z")
5. .\bin\build-zip.ps1   — must pass all 4 structural checks
6. git add releases/vX.Y.Z/  && git commit  ("chore: release vX.Y.Z zip")
7. git push
8. Wait 2-3 min for GitHub CDN, then Check for Updates on live site
```

---

## Key Context Notes

1. **G-01 fully open in v2.14.0** — `term:<tax>:<slug>` and `tax:<tax>` accepted
   at write time and handled by `rmb_snippet_matches_display()` via `is_category()`,
   `is_tag()`, `is_tax()`. Emitter proven on live site. `url:` remains gated.

2. **G-02 status** — `/update` correctly routes term IDs to `update_term_meta()`.
   `/preview-update` now returns 422 for term IDs. No term dry-run support yet.

3. **rrc-mu-toolkit retire sequence** — when shifting to that project, first task
   is creating the GitHub remote, then retiring `RRC_SEO_TAX_META_DESC` and
   `RRC_SEO_TAX_META_OG`. Retire order: disable constant → staging verify →
   remove module code → commit.

4. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates are
   silent. Delivery via WP-CLI or manual zip. See `docs/white-label-configuration.md`.

5. **WPCS installed globally** — `phpcs --standard=phpcs.xml.dist` and
   `composer run lint` both work locally. `php dev-vendor\bin\phpcbf` for auto-fix.
