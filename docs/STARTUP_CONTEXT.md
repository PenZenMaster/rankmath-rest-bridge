# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-07-10 (checkpoint 11:50)
**Branch:** main
**Version:** 3.0.0 (shipped, deployed to production, verified)
**Last Commit:** ab18857 -- docs: add shell-labeling rule to project playbook

---

## Last 3 Accomplishments

1. **v3.0.0 SHIPPED + DEPLOYED (breaking)** -- `POST /snippets/replace-all`
   removed (deprecated since v2.3.1); `rrseo_replace_all_snippets` cap
   auto-revoked on upgrade. User deployed to Higgins Overhead Door via
   self-update (2.18.1 -> 3.0.0 in one hop); no issues.

2. **Issue #5 CLOSED** -- action-engine smoke test passed on the live
   install: dry-run + execute for `update_setting blog_public` (launch
   flow) and `toggle_indexing`. Verification comment on the issue.

3. **Playbook rule added** -- every command/script given to the user must
   be labeled with its execution environment (PowerShell/CMD/Git Bash).
   In global CLAUDE.md section 6 + project playbook section 0 + memory.

---

## Next 3 Priorities

1. **v3.0 Bite 3 -- rollback layer (IN PROGRESS)** --
   `GET /actions/{action_id}` (reads `rrseo_action_log`), then
   `POST /actions/{action_id}/rollback` replaying stored envelopes.
   Edge cases: `reversible: false`, double rollback, current-value drift.

2. **v3.0 Bite 4 -- GitHub Actions CI** -- phpcs + phpunit on every
   push/PR instead of local hooks only.

3. **Telemetry verdict review** -- rrc-telemetry.php collecting on target
   since 2026-07-06; verdicts trustworthy from ~2026-07-13. Kill switch:
   `RRC_PUA_DISABLE` in wp-config.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `ab18857`
- v3.0.0 zip committed at `releases/v3.0.0/` (128 entries, checks 4/4)
- Gates: phpcs clean (9 files), phpunit 179 tests / 408 assertions green

**Files of note:**
- Action engine: `includes/class-rrseo-actions.php` (Bite 2; Bite 3 builds
  on its `rrseo_action_log` envelope store)
- Spec: `docs/plugin-v3-executor-spec.md`
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None.

---

## Key Context Notes

1. **replace-all URL now hits the {id} wildcard** -- POST to
   `/snippets/replace-all` returns `not_found` 404 (slug lookup), NOT
   `rest_no_route`. Handler never upserts; legacy callers cannot write.

2. **Git index case quirk** -- playbook is tracked as `.claude/claude.md`
   (lowercase); `git add .claude/CLAUDE.md` silently stages nothing.

3. **Issue #3 recurrence guard** -- NEVER register array-valued meta keys
   with string sanitize callbacks; `MetaPersistenceTest` guards this.

4. **Deployment state** -- Higgins Overhead Door runs v3.0.0 (2026-07-10).
