# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-07-06 (shutdown 19:04)
**Branch:** main
**Version:** 2.18.0 (pushed, zip on CDN; deployment to rankrocket.co pending)
**Last Commit:** f1f00ea -- chore: release v2.18.0 zip

---

## Last 3 Accomplishments

1. **v3.0 Bite 1 COMPLETE (v2.18.0)** -- five read-only observation endpoints
   in `includes/class-rrseo-observe.php`: heading-hierarchy, broken-links,
   alt-coverage, schema-graph, llms-diff. 24 new unit tests. No external HTTP
   calls -- external link verification stays in the Audit Engine.

2. **Debt burn-down** -- P2 self-canonical gap fixed (`non_self_canonical`
   discovery exclusion); admin `Loading\xe2\x80\xa6` literal-escape display
   bug fixed; I18n pass done for the admin surface (Text Domain header,
   deactivation dialog); phpunit bootstrap drift repaired -- suite green
   again (153 tests / 324 assertions).

3. **G-14 CLOSED + v2.17.7 deployed** -- logged-in snippet emission verified
   manually in a browser; v2.17.7 self-update ran on rankrocket.co, proving
   the full automated release pipeline end-to-end.

---

## Next 3 Priorities

1. **Deploy v2.18.0** -- push already builds the zip via pre-push hook.
   Wait 2-3 min for CDN, then `POST /self-update` on rankrocket.co
   (user supplies the app password; not stored on this machine). Smoke-test
   the five `/observe/*` endpoints against live data afterwards.

2. **v3.0 Bite 2 -- typed action engine (2-3 weeks)** --
   `POST /actions/dry-run`, `POST /actions/execute`, initial whitelist
   (update_setting, regenerate_llms_txt, update_meta_draft, toggle_indexing),
   remove `replace-all`. Every execute: `rr_audit_log()` + both cache busts.
   See `docs/plugin-v3-executor-spec.md`.

3. **llms.txt raw-content upload verification** -- last old backlog item in
   Ready to Start.

---

## Current State

**Git:**
- Branch: `main` -- in sync with origin at `f1f00ea`
- Version: 2.18.0 -- pushed; CDN manifest confirmed serving 2.18.0
- Test suite: phpcs clean (8 files), phpunit 153 tests green

**Files of note:**
- Plugin: `rankmath-rest-bridge.php`, `includes/` (7 helper files -- observe
  endpoints added 2026-07-06)
- **Release hook:** `hooks/pre-push` -- auto-builds zip on push. NOTE: the
  hook's zip commit lands AFTER the push refspec is computed -- run
  `git push` a second time to push the zip commit.
- **v3.0 spec:** `docs/plugin-v3-executor-spec.md` -- authoritative Shape B spec
- Side repo: `E:\projects\rrc-mu-toolkit` -- in sync with GitHub;
  `rrc-telemetry.php` v1.5 deployed to target mu-plugins 2026-07-06

**Blockers:**
- None. WP app password for rankrocket.co is not stored on the dev machine
  (by design) -- self-update POST needs the user.

---

## Release Checklist (run for every version bump)

```
1. Bump version in rankmath-rest-bridge.php plugin header + RMB_VERSION constant
2. Update update-manifest.json  -- version + download_url (releases/vX.Y.Z/)
3. Update CHANGELOG.md
4. git add + git commit  (conventional: "feat/fix/chore: ...")
5. git push  -- pre-push hook auto-builds releases/vX.Y.Z/*.zip and commits it
   NOTE: run `git push` a second time to push the hook's zip commit.
6. Wait 2-3 min for GitHub CDN, then POST /self-update on target site
```

---

## Key Context Notes

1. **v3.0 progress** -- Bite 1 (observation) shipped in v2.18.0. Bite 2 (typed
   action engine) is next; Bite 3 rollback; Bite 4 CI. Boundary unchanged:
   agentic runtime lives in the external Audit Engine, plugin is executor +
   data source only.

2. **Cache architecture (v2.17.x invariant)** -- every write busts three
   layers: DB, WP object cache (`rrseo_bust_option_cache`), LiteSpeed page
   cache (`rrseo_purge_rest_cache`). Bite 2 executor writes MUST call both.

3. **Observation endpoint design decisions (v2.18.0)** -- broken-links never
   makes HTTP calls: internal links resolve via url_to_postid/get_page_by_path
   (`ok` omitted, `not_found` = 404, archive-shaped = `unverified`), external
   links return `checked: false` for the Audit Engine. alt-coverage caches in
   a 5-min transient (`rrseo_observe_alt_coverage`).

4. **rrc-telemetry.php** -- deployed and collecting on the target site.
   Verdicts need 1-2 weeks of traffic before DEAD is trustworthy. Kill
   switch: `define('RRC_PUA_DISABLE', true)` in wp-config.php.
