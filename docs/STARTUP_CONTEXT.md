# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-14
**Branch:** main
**Version:** 2.13.1
**Last Commit:** 24d2ae8 — chore: release v2.13.1 zip

---

## Last 3 Accomplishments

1. **v2.13.1 shipped** — 422 gate on `term:`, `tax:`, `url:` `display_on` patterns
   (silent failures become loud), whitespace normalization in `rmb_resolve_tokens()`
   (leading space in `<title>`, double space in description fixed). Auto-update
   confirmed working on live site.

2. **v2.13.1 validated clean** — full 8-pattern emission matrix verified; G-01
   emitter proven working via `term_id:21` live probe on `/category/ai-search-marketing/`.
   G-01 gate lift in v2.14.0 is de-risked.

3. **v2.14.0 scope locked** — 7 items defined: restore `post_id:` alias, lift G-01
   gate, G-02 422 fix (term IDs in /update + /preview-update), G-03 consolidate_canonical
   flag, G-08 emit_routing_version, G-11 single-resource GET /snippets/<slug>,
   G-12 POST /llms-txt/regenerate.

---

## Next 3 Priorities

1. **v2.14.0 implementation** — work items in order:
   - Restore `post_id:<int>` alias in `rr_validate_display_on()`
   - Lift G-01 gate: add `term:`, `tax:` back to validator + accepted_patterns
   - G-02: return 422 in `/preview-update` + `/update` when post_id resolves to term
   - G-03: `consolidate_canonical` flag in /status and /settings
   - G-08: `emit_routing_version: 2` field in /status
   - G-11: GET /snippets/<slug> single-resource endpoint (404 currently)
   - G-12: POST /llms-txt/regenerate endpoint (404 currently)

2. **Salvo staging verify for v2.13.1** — install zip, confirm 422 gate fires on
   `term:product_cat:<slug>` write attempts.

3. **rrc-mu-toolkit GitHub remote + retire sequence** — create remote, push, then
   retire `RRC_SEO_TAX_META_DESC` and `RRC_SEO_TAX_META_OG` on Salvo.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.13.1
- Last commit: `24d2ae8` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~4,000 lines)
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — 19 gaps
- Validation reports: `docs/RankRocket_v2_13_0_Validation_Report.md`,
  `docs/RankRocket_v2_13_1_Validation_Report.md`
- Release builder: `bin/build-zip.ps1` — always use for releases
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote yet

**Blockers:**
- None.

---

## Release Checklist (run for every version bump)

```
1. Bump version in rankmath-rest-bridge.php plugin header
2. Update update-manifest.json  — version + download_url (releases/vX.Y.Z/)
3. Update CHANGELOG.md
4. git add + git commit  (conventional: "chore: bump version to X.Y.Z")
5. .\bin\build-zip.ps1   — must pass all 4 structural checks
6. git add releases/vX.Y.Z/  && git commit  ("chore: release vX.Y.Z zip")
7. git push
8. Verify: curl -I <download_url>  returns 200 (not 404)
```

> GitHub CDN propagation takes 2-5 minutes. If "Check for Updates" says
> up-to-date immediately after push, wait and click again.

---

## Key Context Notes

1. **G-01 emitter proven** — `rmb_snippet_matches_display()` correctly fires
   `term_id:21` on `/category/ai-search-marketing/` (live validated May 14 2026).
   Emission code for `term:<tax>:<slug>` and `tax:<tax>` uses same code path.
   Gate removal in v2.14.0 is a validator allowlist change only — no emission
   code changes needed.

2. **G-02 still dangerous** — `/preview-update` and `/update` accept any integer
   as `post_id`, including term IDs, and return `valid: true` with no write.
   Fix in v2.14.0: detect term IDs via `rr_resolve_id()` and return 422.

3. **v2.13.1 pattern vocabulary** — 8 accepted `display_on` values:
   `entire_website`, `front_page`, `singular`, `all_pages`, `all_posts`,
   `page_id:<int>`, `post_type:<slug>`, `term_id:<int>`.
   `post_id:<int>` alias was dropped — restore in v2.14.0.

4. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates are
   silent. Delivery via WP-CLI or manual zip. See `docs/white-label-configuration.md`.

5. **WPCS installed globally** — `phpcs --standard=phpcs.xml.dist` and
   `composer run lint` both work locally. `php dev-vendor\bin\phpcbf` for auto-fix.
