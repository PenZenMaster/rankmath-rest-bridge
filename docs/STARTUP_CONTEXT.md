# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-05-29
**Branch:** main
**Version:** 2.17.6
**Last Commit:** a78c908 -- docs: reformat changelog as HTML; white-label plugin header for AMS

---

## Last 3 Accomplishments

1. **Mu-plugin SEO patch layer fully retired** -- All 6 SEO stopgap modules
   removed from rrc-mu-toolkit. RRC_SEO_EXPLICIT_ROBOTS migrated into
   `rr_merge_wp_robots` (rankmath-rest-bridge v2.17.6). File renamed
   `plugin-usage-audit.php` -> `rrc-telemetry.php`; class renamed;
   white-label constants `RRC_TEL_WL_NAME` / `RRC_TEL_WL_HIDE` added (v1.5).

2. **rankmath-rest-bridge v2.17.5-v2.17.6** -- v2.17.5: `plugin_row_meta`
   filter hides the "View Details" link from the Plugins screen. v2.17.6:
   `rr_merge_wp_robots` emits explicit `index, follow` default; plugin header
   white-labelled for AMS; changelog reformatted as HTML in manifest.

3. **Salvo staging verified (G-01)** -- `POST /perf/dequeue-rules` and
   `POST /perf/defer-handles` confirmed working; `term:product_cat:<slug>`
   snippets fire on WooCommerce taxonomy archives end-to-end.

---

## Next 3 Priorities

1. **Deploy v2.17.6** -- `git push` on rank_rocket_seo_plugin (pre-push hook
   auto-builds releases/v2.17.6/rankmath-rest-bridge.zip and commits it).
   Then `POST /self-update` on target site. Also deploy rrc-telemetry.php:
   upload to mu-plugins, delete the old plugin-usage-audit.php.

2. **rrc-mu-toolkit GitHub remote** -- create remote on GitHub, push master.
   Repo is local-only at E:\projects\rrc-mu-toolkit.

3. **G-14 logged-in emission manual check** -- Log in to WP admin on
   rankrocket.co; confirm `display_on_user: logged_in` snippets fire for
   authenticated visitors and suppress for anonymous. Basic Auth does NOT
   establish a WP session -- must use browser login.

---

## Current State

**Git:**
- Branch: `main`
- Version: 2.17.6
- Last committed: `a78c908` -- pushed
- Uncommitted: none

**Files of note:**
- Plugin: `rankmath-rest-bridge.php`, `includes/` (6 helper classes)
- **Release hook:** `hooks/pre-push` -- auto-builds zip on push (installed via composer)
- **v3.0 spec:** `docs/plugin-v3-executor-spec.md` -- authoritative Shape B plugin spec
- Side repo: `E:\projects\rrc-mu-toolkit` -- local + GitHub remote (pushed)
  - `rrc-telemetry.php` v1.5 -- telemetry-only mu-plugin (all SEO modules retired)

**Blockers:**
- None. v2.17.6 is ship-quality; both repos clean and pushed.

---

## Release Checklist (run for every version bump)

```
1. Bump version in rankmath-rest-bridge.php plugin header + RMB_VERSION constant
2. Update update-manifest.json  -- version + download_url (releases/vX.Y.Z/)
3. Update CHANGELOG.md
4. git add + git commit  (conventional: "feat/fix/chore: ...")
5. git push  -- pre-push hook auto-builds releases/vX.Y.Z/*.zip and commits it
6. Wait 2-3 min for GitHub CDN, then POST /self-update on target site
```

Note: The pre-push hook (hooks/pre-push) runs bin/build-zip.ps1 automatically
if the zip for the current version is missing. All 4 structural checks must pass
or the push is aborted. Run .\bin\build-zip.ps1 manually to diagnose failures.

---

## Key Context Notes

1. **v3.0 boundary** -- plugin v3.0 scope is observation endpoints + typed
   executor endpoints only. Agentic runtime lives in the external Audit Engine,
   not this repo. See `docs/plugin-v3-executor-spec.md`. v3.0 starts after
   white-label milestone lands.

2. **Cache architecture (v2.17.x)** -- writes bust three layers: DB (always),
   WP object cache (`rrseo_bust_option_cache`), and LiteSpeed page cache
   (`rrseo_purge_rest_cache`). If another cache plugin is added, wire its URL
   purge into `rrseo_purge_rest_cache()`.

3. **rrc-telemetry.php white-label** -- `RRC_TEL_WL_NAME` overrides admin UI
   label; `RRC_TEL_WL_HIDE=true` suppresses the Tools menu entry entirely.
   The Must-Use Plugins list Plugin Name is static (file header) -- cannot be
   changed at runtime. AMS constants for rankmath-rest-bridge: `RRSEO_WL_*`.

4. **display_on_user (v2.17.0)** -- `all|anonymous|logged_in`. Existing
   snippets without the field default to `all`. Logged-in emission (G-14)
   needs manual browser verification -- Basic Auth does not establish a WP session.
