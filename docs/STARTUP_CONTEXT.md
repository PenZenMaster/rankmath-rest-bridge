# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-07-06
**Branch:** main
**Version:** 2.17.7
**Last Commit:** 8a13bed -- chore: release v2.17.7 zip

---

## Last 3 Accomplishments

1. **v2.17.7 deployed end-to-end** -- dry_run fix (`931e9b1`) pushed; pre-push
   hook auto-built the release zip (`8a13bed`); CDN verified; `POST /self-update`
   run on rankrocket.co. First full proof of the automated release pipeline --
   the [CRITICAL] auto-update staging verify backlog item is CLOSED.

2. **rrc-telemetry.php v1.5 deployed** -- uploaded to `wp-content/mu-plugins/`
   on the target site; old `plugin-usage-audit.php` deleted. Plugin usage
   telemetry (Tools > Plugin Usage, DEAD/IDLE/ACTIVE verdicts) now collecting.

3. **Docs reconciled** -- projectStatus.md was stale at v2.17.4; recovered the
   missing 2026-05-29 session entry and refreshed the header. rrc-mu-toolkit
   GitHub remote confirmed live and in sync.

---

## Next 3 Priorities

1. **G-14 logged-in emission manual check** -- Log in to wp-admin on
   rankrocket.co in a real browser, then view a front-end page carrying a
   `display_on_user: logged_in` snippet (validation slug: `rr-logged`).
   Confirm present in page source while logged in, absent in incognito.
   Basic Auth does NOT establish a WP session -- browser login only.

2. **Small-ticket debt** -- P2 gaps: self-canonical/redirect check via
   `get_canonical_url()` (~10 lines); `/sitemap_index.xml` lastmod derived
   from canonical set instead of raw `get_posts()`. I18n pass on
   user-visible strings.

3. **v3.0 Bite 1 kickoff** -- observation endpoints per
   `docs/plugin-v3-executor-spec.md` (heading-hierarchy, broken-links,
   alt-coverage, schema-graph, llms-diff). White-label prerequisite landed
   in v2.17.5/6 -- v3.0 is unblocked.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.17.7 (deployed to rankrocket.co)
- Last committed: `8a13bed` -- pushed
- Uncommitted: docs updates from 2026-07-06 checkpoint (commit pending QA)

**Files of note:**
- Plugin: `rankmath-rest-bridge.php`, `includes/` (6 helper classes)
- **Release hook:** `hooks/pre-push` -- auto-builds zip on push (installed via composer)
- **v3.0 spec:** `docs/plugin-v3-executor-spec.md` -- authoritative Shape B plugin spec
- Side repo: `E:\projects\rrc-mu-toolkit` -- GitHub remote live, in sync
  - `rrc-telemetry.php` v1.5 -- deployed to target mu-plugins 2026-07-06

**Blockers:**
- None. WP app password for rankrocket.co is not stored on the dev machine
  (by design) -- REST deploy calls need the user to supply it.

---

## Release Checklist (run for every version bump)

```
1. Bump version in rankmath-rest-bridge.php plugin header + RMB_VERSION constant
2. Update update-manifest.json  -- version + download_url (releases/vX.Y.Z/)
3. Update CHANGELOG.md
4. git add + git commit  (conventional: "feat/fix/chore: ...")
5. git push  -- pre-push hook auto-builds releases/vX.Y.Z/*.zip and commits it
   NOTE: the hook's zip commit lands AFTER the push refspec is computed --
   run `git push` a second time (or check `git status -sb`) to push the zip.
6. Wait 2-3 min for GitHub CDN, then POST /self-update on target site
```

Note: The pre-push hook (hooks/pre-push) runs bin/build-zip.ps1 automatically
if the zip for the current version is missing. All 4 structural checks must pass
or the push is aborted. Run .\bin\build-zip.ps1 manually to diagnose failures.

---

## Key Context Notes

1. **v3.0 boundary** -- plugin v3.0 scope is observation endpoints + typed
   executor endpoints only. Agentic runtime lives in the external Audit Engine,
   not this repo. See `docs/plugin-v3-executor-spec.md`. Prerequisites are
   done -- v3.0 Bite 1 can start.

2. **Cache architecture (v2.17.x)** -- writes bust three layers: DB (always),
   WP object cache (`rrseo_bust_option_cache`), and LiteSpeed page cache
   (`rrseo_purge_rest_cache`). If another cache plugin is added, wire its URL
   purge into `rrseo_purge_rest_cache()`.

3. **rrc-telemetry.php** -- deployed and collecting. Verdicts need >= 1 day
   of observation before they mean anything; give it 1-2 weeks of normal
   traffic before trusting DEAD verdicts. Kill switch:
   `define('RRC_PUA_DISABLE', true)` in wp-config.php. White-label:
   `RRC_TEL_WL_NAME` / `RRC_TEL_WL_HIDE` (AMS plugin uses `RRSEO_WL_*`).

4. **display_on_user (v2.17.0)** -- `all|anonymous|logged_in`. Storage,
   validation, and anonymous-side emission verified (v2.17.0 report).
   Logged-in side (G-14) still needs the manual browser check above.
