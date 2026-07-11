# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-07-10 (shutdown 16:11)
**Branch:** main
**Version:** 3.3.0 (shipped, zip on CDN; Higgins deployment pending)
**Last Commit:** fd01178 -- chore: release v3.3.0 zip

---

## Last 3 Accomplishments

1. **Four releases in one day** -- v3.0.0 (replace-all removed, breaking),
   v3.1.0 (Bite 3 rollback layer), v3.2.0 (snippet priority, issue #7),
   v3.3.0 (WAF-safe code_b64 transport, issue #8). v3.0 roadmap Bites 1-4
   are ALL COMPLETE, including Bite 4 GitHub Actions CI (green on PHP
   7.4 + 8.3 + release-integrity job).

2. **Higgins perf story unblocked** -- v3.2.0 priority field puts snippets
   at wp_head:1 (ahead of theme CSS at 5-10); v3.3.0 code_b64 gets
   onload= payloads past Cloudflare XSS rules. Together they unlock the
   2,590ms render-block savings (projected mobile 63 -> 78-85).

3. **Issues #5, #6, #7, #8 all closed** -- #7/#8 same-day
   file-to-ship turnaround with implementation comments.

---

## Next 3 Priorities

1. **Deploy v3.3.0 to Higgins + collect the perf win** -- self-update
   (user runs; needs app password), then POST the Font Awesome +
   Bootstrap preload/onload swap with code_b64 + priority:1. Verify
   PageSpeed mobile moves from 63 toward 78-85.

2. **Telemetry verdict review** -- rrc-telemetry.php collecting since
   2026-07-06; trustworthy from ~2026-07-13. Kill switch:
   RRC_PUA_DISABLE in wp-config.

3. **Optional cleanup** -- live execute->rollback cycle confirmation on
   Higgins; llms.txt raw-content upload verification (old backlog);
   tick stale v3.0 bite checkboxes in projectStatus.md (~lines 611-624).

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `fd01178`
- Higgins runs v3.2.0; v3.3.0 zip on CDN awaiting deployment
- Gates: phpcs clean (9 files), phpunit 207 tests / 511 assertions, CI green

**Files of note:**
- Snippet priority + b64: emission section + write handlers in
  `rankmath-rest-bridge.php`; helpers `rmb_snippet_priority()`,
  `rr_validate_snippet_priority()`, `rr_decode_snippet_b64()`
- Action engine + rollback: `includes/class-rrseo-actions.php` (v1.10)
- CI: `.github/workflows/ci.yml`
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None. Self-update needs user's app password (not stored, by design).

---

## Key Context Notes

1. **Snippet priority model (v3.2.0)** -- one emitter per (location,
   priority) bucket registered at plugin load; defaults unchanged
   (wp_head:20, wp_body_open:10, wp_footer:10). Priority 0-1 interleaves
   with plugin canonical/robots emission at wp_head:1.

2. **code_b64 (v3.3.0)** -- transport-only base64; strict decode + UTF-8
   check, 422 invalid_base64; wins over code/content; storage stays
   decoded HTML. Update endpoint included beyond issue #8's AC.

3. **composer platform.php pinned 7.4.33** -- keeps dependency resolution
   installable on plugin minimum (lock is gitignored; the pin is what
   guarantees CI-vs-local consistency).

4. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
   (lowercase); `git add` with uppercase path silently stages nothing.
