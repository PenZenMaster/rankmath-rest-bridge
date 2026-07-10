# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-07-10 (checkpoint 13:59)
**Branch:** main
**Version:** 3.1.0 (shipped, deployed to production, smoke-tested)
**Last Commit:** 3b84d03 -- build: GitHub Actions CI (v3.0 Bite 4)

---

## Last 3 Accomplishments

1. **v3.0 ROADMAP COMPLETE (Bites 1-4)** -- Bite 3 rollback layer shipped
   as v3.1.0 (`GET /actions/{action_id}` + `POST .../rollback` with drift
   detection, double-rollback protection, dry-run); Bite 4 GitHub Actions
   CI live and green (phpcs + phpunit on PHP 7.4 + 8.3, release-integrity
   job). Suite: 192 tests / 469 assertions.

2. **v3.0.0 breaking release** -- replace-all endpoint removed, cap
   auto-revoked on upgrade. v3.0.0 then v3.1.0 both deployed to Higgins
   Overhead Door via self-update; smoke tests passed; issue #5 closed.

3. **CI dependency fix** -- composer platform.php pinned to 7.4.33
   (dependency tree had resolved a package requiring PHP ^8.4 that would
   have failed both CI matrix jobs).

---

## Next 3 Priorities

1. **Telemetry verdict review** -- rrc-telemetry.php collecting on target
   since 2026-07-06; verdicts trustworthy from ~2026-07-13. Kill switch:
   `RRC_PUA_DISABLE` in wp-config.

2. **Roadmap planning** -- v3.0 spec fully delivered; next scope comes
   from the external Audit Engine side (plugin stays lean executor per
   the architectural decision). Old backlog candidate: llms.txt
   raw-content upload verification.

3. **Optional**: confirm a live execute->rollback cycle on Higgins
   (unit coverage thorough; live rollback not explicitly confirmed).

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `3b84d03`
- v3.1.0 zip at `releases/v3.1.0/`; CI run 29122197253 green (3 jobs)
- Gates: phpcs clean (9 files), phpunit 192 tests / 469 assertions

**Files of note:**
- Action engine + rollback: `includes/class-rrseo-actions.php` (v1.10)
- CI: `.github/workflows/ci.yml`
- Spec: `docs/plugin-v3-executor-spec.md` (all 4 bites delivered)
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None.

---

## Key Context Notes

1. **Deployment state** -- Higgins Overhead Door runs v3.1.0 (2026-07-10).

2. **replace-all URL now hits the {id} wildcard** -- returns `not_found`
   404 (slug lookup), NOT `rest_no_route`. Handler never upserts.

3. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
   (lowercase); `git add` with uppercase path silently stages nothing.
   `composer.lock` is gitignored by convention (platform pin keeps CI
   resolution deterministic).

4. **Issue #3 recurrence guard** -- NEVER register array-valued meta keys
   with string sanitize callbacks; `MetaPersistenceTest` guards this.
