# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-07-09 (shutdown 19:42)
**Branch:** main
**Version:** 2.19.0 (pushed, zip on CDN; deployment pending)
**Last Commit:** 4660836 -- chore: release v2.19.0 zip

---

## Last 3 Accomplishments

1. **v3.0 Bite 2 SHIPPED (v2.19.0)** -- typed action engine in
   `includes/class-rrseo-actions.php`: `POST /actions/dry-run` +
   `/actions/execute`, whitelist = update_setting (9 typed WP options from
   issue #5), regenerate_llms_txt, update_meta_draft, toggle_indexing.
   Envelopes in capped `rrseo_action_log` option; 16 new unit tests.

2. **v2.18.1 hotfix shipped, deployed, QA-passed** -- issue #3 (schema +
   audit-log writes flattened to '' by registered string sanitize callbacks
   since v2.14.4 -- registrations removed) and issue #4 (double canonical --
   `rr_emit_singular_canonical()` now unhooks core `rel_canonical` when it
   emits). Both issues closed on GitHub.

3. **Test harness hardened** -- bootstrap now models WP's `sanitize_meta()`
   layer (the blind spot behind #3), records add/remove_action, and stubs the
   action-engine runtime. Suite: 179 tests / 408 assertions green.

---

## Next 3 Priorities

1. **Deploy v2.19.0 + smoke-test** -- POST /self-update (user supplies app
   password), then dry-run + execute `update_setting blog_public` and
   `toggle_indexing` against staging. Close issue #5 after the launch flow
   (`blog_public` 0->1, `show_on_front`/`page_on_front`) verifies.

2. **Decide replace-all removal** -- Bite 2 spec includes removing the
   `replace-all` snippets endpoint; it is a breaking API change so it awaits
   user sign-off as its own commit (see v2.19.0 CHANGELOG "Deferred").

3. **v3.0 Bite 3 -- rollback layer (1-2 weeks)** -- `GET /actions/{action_id}`
   (reads `rrseo_action_log`) + `POST /actions/{action_id}/rollback` replaying
   stored envelopes. Then Bite 4: GitHub Actions CI.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `4660836`
- Version 2.19.0 pushed; zip committed at `releases/v2.19.0/`
- Gates: phpcs clean (9 files), phpunit 179 tests / 408 assertions green

**Files of note:**
- Action engine: `includes/class-rrseo-actions.php` (v3.0 Bite 2)
- Spec: `docs/plugin-v3-executor-spec.md` -- now carries the issue #5
  update_setting whitelist + envelope-storage design
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None. Self-update POSTs need the user's app password (not stored, by design).

---

## Key Context Notes

1. **Bite 2 design decisions** -- envelopes live in the `rrseo_action_log`
   option (site-level actions have no post for `_rrseo_change_log`); post-
   targeted actions also write the per-post audit row. `regenerate_llms_txt`
   returns `reversible: false` (llms.txt renders live from config) -- spec
   table corrected.

2. **Issue #3 root cause (recurrence guard)** -- NEVER register array-valued
   meta keys with string sanitize callbacks; WP applies them on every
   update_post_meta and flattens arrays to ''. `MetaPersistenceTest` guards
   this.

3. **GitHub issues** -- #3, #4 closed (fixed v2.18.1, QA passed). #5 open,
   labeled v3.0-bite-2, closes after v2.19.0 staging verification.

4. **rrc-telemetry.php** -- collecting on target since 2026-07-06; verdicts
   trustworthy after 1-2 weeks. Kill switch: `RRC_PUA_DISABLE` in wp-config.
