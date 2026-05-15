# RankRocket SEO Control Layer — Startup Context

**Last Updated:** 2026-05-15
**Branch:** main
**Version:** 2.17.0
**Last Commit:** 53b3a00 — chore: release v2.17.0 zip

---

## Last 3 Accomplishments

1. **Full gap sprint complete (v2.14.4 → v2.17.0)** — closed G-04, G-05,
   G-06, G-07, G-09, G-10, G-13, G-14, G-16, G-17, G-18, G-19 across nine
   releases. Every gap from the original report is closed except G-15
   (hreflang, explicitly deferred). Key items: Performance module
   (dequeue-rules + defer-handles), bulk snippets, sitemap exclusions,
   snippet emission hooks, Elementor cache helper, display_on_user field.

2. **README.md created** — REST API reference with endpoint examples,
   display_on vocabulary table, headless self-update workflow (`/check-updates`
   + `/self-update`), and release checklist.

3. **All v2.14.x FUs closed** — G-12 fatal fixed (v2.14.1), unset_fields on
   /update (v2.14.2), line_count fix + REST fatal handler + docs (v2.14.3).

---

## Next 3 Priorities

1. **Validate v2.17.0 on live site** — install update; spot-check:
   `POST /perf/dequeue-rules` stores and applies rules; `POST /snippets/bulk`
   creates batch; `display_on_user: anonymous` suppresses for logged-in users;
   `POST /elementor/repair-cache` returns repaired+deleted_keys.

2. **Salvo staging verify** — install v2.17.0 zip; configure
   `POST /perf/dequeue-rules` to replace `RRC_SEO_WC_DEQUEUE` mu-plugin;
   configure `POST /perf/defer-handles` to replace `RRC_SEO_DEFER_NONCRIT`;
   confirm `term:product_cat:<slug>` snippets fire on WooCommerce taxonomy
   archives (G-01 end-to-end). Then retire the two mu-plugin modules.

3. **rrc-mu-toolkit GitHub remote + retire sequence** — create GitHub remote,
   push local repo. Then retire `RRC_SEO_TAX_META_DESC` and
   `RRC_SEO_TAX_META_OG` on Salvo (disable constant → staging verify →
   remove code → commit).

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.17.0
- Last commit: `53b3a00` — pushed, working tree clean

**Files of note:**
- Plugin: `rankmath-rest-bridge.php` (~4,600+ lines)
- Canonical class: `includes/class-rrseo-canonical.php`
- README: `README.md` — REST API reference (created this session)
- Gap report: `docs/RankRocket_SEO_Functionality_Gaps.md` — all gaps closed
  except G-15 (hreflang, deferred)
- Validation reports: v2.13.0–v2.14.1 in `docs/`
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
4. git add + git commit  (conventional: "feat/fix/chore: ...")
5. .\bin\build-zip.ps1   — must pass all 4 structural checks
6. git add releases/vX.Y.Z/  && git commit  ("chore: release vX.Y.Z zip")
7. git push
8. Wait 2-3 min for GitHub CDN, then POST /check-updates + POST /self-update
```

---

## Key Context Notes

1. **Performance module (v2.16.0)** — `POST /perf/dequeue-rules` replaces
   `RRC_SEO_WC_DEQUEUE` mu-plugin; `POST /perf/defer-handles` replaces
   `RRC_SEO_DEFER_NONCRIT`. Not yet configured on Salvo — that's next priority.

2. **display_on_user (v2.17.0)** — new snippet field `all|anonymous|logged_in`.
   Existing snippets without the field default to `all` at emit time — no DB
   migration needed. Fires `rrseo_snippet_skipped` with reason `user_logged_in`
   or `user_anonymous`.

3. **Sitemap exclusions (v2.15.0)** — `POST /sitemap/exclusions` with
   `excluded_post_slugs` takes effect immediately. `excluded_term_ids`,
   `excluded_term_slugs`, `excluded_taxonomies` stored for future taxonomy
   sitemap support.

4. **rrc-mu-toolkit retire sequence** — when shifting to that project, first
   task is creating the GitHub remote, then retiring `RRC_SEO_TAX_META_DESC`
   and `RRC_SEO_TAX_META_OG`. Order: disable constant → staging verify →
   remove module code → commit.

5. **Tier 2 update flow** — when `RRSEO_WL_HIDE_PLUGIN` is `true`, updates
   are silent. Use WP-CLI, manual zip, or headless `POST /self-update`.
   See `docs/white-label-configuration.md`.
