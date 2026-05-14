# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-14 (checkpoint 1616)
**Branch:** main
**Version:** 2.12.2
**Last Commit:** a79b18a — docs(gaps): migrate G-16-G-19 into gap report

---

## Last 3 Accomplishments

1. **`rrc-mu-toolkit` repo scaffolded** — `plugin-usage-audit.php` (v1.2) moved
   to its own standalone repo at `E:\projects\rrc-mu-toolkit`. Refactor notes
   appended to the file header document the two hardcoded client values
   (`| Salvo Metal Works`, fallback OG image URL) and class naming issue
   (`_V11`) to fix before wider deployment. Local only — no remote yet.

2. **Gap report committed, CSV retired** — `docs/RankRocket_SEO_Functionality_Gaps.md`
   is now the single roadmap source of truth (19 gaps, G-01 through G-19,
   versioned roadmap v2.13.0 through v2.17.0+). `Gap-Priority-Notes.csv` deleted.

3. **v2.13.0 fully planned** — implementation plan for G-01 (taxonomy `display_on`
   patterns), G-08 (`display_on` validation), and G-02 (term meta read/write +
   taxonomy archive emission). Five-commit sequence, risks identified.

---

## Next 3 Priorities

1. **G-01 (v2.13.0 Step 1)** — Add `rr_is_any_tax_archive()` helper and extend
   `rmb_snippet_matches_display()` for `term:TAX:SLUG`, `term_id:NNN`, `tax:TAX`,
   `url:/path/` patterns (~lines 371-410 of `rankmath-rest-bridge.php`).

2. **G-08 (v2.13.0 Step 2)** — Add `rr_validate_display_on()`, wire 422 validation
   into `rmb_snippets_create()` (~line 3011) and `rmb_snippets_update()` (~lines
   3042-3046).

3. **G-02 (v2.13.0 Step 3)** — `rr_resolve_id()` helper, term meta read/write
   routing in `/update` and `/get/{id}`, taxonomy archive SEO tag emission on
   `wp_head` + `pre_get_document_title`.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.12.2
- Last commit: `a79b18a` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~2,810 lines)
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — 19 gaps, full roadmap
- White-label: `includes/class-rrseo-white-label.php` (v1.01)
- Release builder: `bin/build-zip.ps1` — always use for releases
- Staging checklist: `docs/staging-verify-autoupdate.md`
- Side repo: `E:\projects\rrc-mu-toolkit` — local only, no remote

**Blockers:**
- None.

---

## Key Context Notes

1. **v2.13.0 plan** — G-01 -> G-08 -> G-02, in that order. After G-02 ships,
   four mu-plugin modules (`RRC_SEO_TAX_META_DESC`, `RRC_SEO_TAX_META_OG`, and
   two taxonomy schema modules) can be retired from `rrc-mu-toolkit`.

2. **Gap report is the backlog** — `docs/RankRocket_SEO_Functionality_Gaps.md`
   replaces the old CSV. Roadmap milestones: v2.13.0 (G-01/02/08), v2.14.0
   (G-03/11/12/16/18), v2.15.0 (G-04/05), v2.16.0 (G-06/07), v2.17.0 (G-09/10/13/17/19).

3. **`rrc-mu-toolkit` parameterization** — when setting up the GitHub remote for
   that repo, also parameterize `RRC_PUA_OG_SITE_SUFFIX` and
   `RRC_PUA_OG_FALLBACK_IMAGE` constants to remove Salvo-specific hardcoding.

4. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates are
   silent. Delivery via auto-update filter, WP-CLI, or manual zip. See
   `docs/white-label-configuration.md`.

5. **WPCS installed globally** — `wp-coding-standards/wpcs` v3.1 via
   `composer global require`. `phpcs --standard=phpcs.xml.dist` and
   `composer run lint` both work locally.
