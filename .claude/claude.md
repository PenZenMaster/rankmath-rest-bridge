# Claude Code – Project Playbook: RankRocket SEO Control Layer

> Repo: **rank_rocket_seo_plugin** · Purpose: WordPress plugin that is the native SEO control layer for the RankRocket remediation pipeline.

---

## 0) Mission & Working Agreement

When asked for changes in this repo:

1. **Plan** briefly (what/why/files to touch).
2. **Patch** with **small, reviewable diffs**; no broad rewrites unless asked.
3. **Test** using the quality gates in §4.
4. **Summarize** changes, risks, and next steps.

**Non-negotiables**

* Production-ready diffs only — no placeholders or TODO litter in committed code.
* No emojis or Unicode symbols in source code.
* Preserve file headers; bump versions per §1 (Minor +0.01, Major +1.00).
* Never hardcode secrets; use wp-config constants or environment.
* Surgical diffs only; propose a two-stage plan for large refactors.
* Never create versioned duplicate files (e.g., `plugin_v2.php`).

---

## 1) Session Commands (exact phrases to use)

### `RRSEO start`

**Goal:** Prime context at the start of a session.
**Claude should:**

1. Read the lightweight context file (single source of truth):
   * `docs/STARTUP_CONTEXT.md` (~40 lines — contains everything needed)

   OPTIONAL — only if user requests deep history:
   * Latest `docs/archive/checkpoints/CheckPoint-*.md`
   * `docs/projectStatus.md`

2. Post a kickoff note with:
   * **Last session wins** (3 bullet points from STARTUP_CONTEXT)
   * **Next priorities** (3 concrete steps from STARTUP_CONTEXT)
   * **Current state** (branch, version, git status)
   * **Critical alerts** (blockers, open decisions)

3. Confirm environment:
   * Run `git status` to verify working tree
   * Note any uncommitted changes

---

### `RRSEO checkpoint`

**Goal:** Capture state at any point — especially near usage limits or after a milestone.
**Claude should:**

1. Create `docs/archive/checkpoints/CheckPoint-YYYY-MM-DD_HHMM.md` with:
   * **Context summary** (why we are here)
   * **Accomplishments** (what shipped this session)
   * **Technical changes** (files touched, diff overview)
   * **Known issues / blockers**
   * **Next session priorities** (bullet list)
   * **Backlog movement** (added / removed / deferred)
   * **Git status** (branch, last commit hash, pushed: yes/no)

2. Update live docs:
   * `docs/projectStatus.md` — append session summary under current sprint
   * `docs/STARTUP_CONTEXT.md` — refresh Last 3 Accomplishments, Next 3 Priorities, Current State

3. WAIT for user QA/confirmation before git operations.

4. After confirmation: `git add -A && git commit -m "chore(checkpoint): YYYY-MM-DD_HHMM – <summary>" && git push`

5. Reply with a 1-paragraph summary + checklist of next steps.

> **Trigger words**: "checkpoint now", "prepare for rollover", "save state", "juice check" → run **RRSEO checkpoint** immediately.

---

### `RRSEO shutdown`

**Goal:** End a session cleanly.
**Claude should:**

1. **Update `docs/STARTUP_CONTEXT.md`** (~40 lines):
   * Last Updated timestamp + branch + version
   * Last 3 Accomplishments (bullet points only)
   * Next 3 Priorities (concrete, actionable)
   * Current State (git, blockers)
   * Key Context Notes (2-3 important details)

2. **Run RRSEO checkpoint** (full — always on shutdown).

3. Ensure all changes are committed and pushed:
   * Verify `git status` is clean
   * Echo branch, last commit hash

4. Post "Shutdown complete" with:
   * 3 priority bullets for next session
   * Confirmation that STARTUP_CONTEXT.md is updated
   * Any pending user decisions

---

## 2) Project Guardrails

* **Stack**: Single-file WordPress PHP plugin (`rankmath-rest-bridge.php`), PHP 7.4+, WP 5.9+.
* **REST namespace**: `rankrocket-seo/v1` — do not revert to `rankmath-bridge/v1`.
* **Meta keys**: Write to `rr_seo_*` only; read `rank_math_*` as migration fallback via `rr_get_seo_meta()`.
* **Audit log**: All SEO meta + schema writes must call `rr_audit_log()`.
* **Validation**: All external writes must pass `rr_validate_seo_fields()` or `rr_validate_schema()`.
* **Capability**: `replace-all` is guarded by `rrseo_replace_all_snippets` — do not widen to `manage_options`.
* **No file rename**: `rankmath-rest-bridge.php` is the WP plugin file — rename only with a coordinated GitHub repo rename.

---

## 3) Files & Paths (authoritative)

* **Startup Context**: `docs/STARTUP_CONTEXT.md` — primary for `RRSEO start`
* **Project Status**: `docs/projectStatus.md` — full sprint history
* **Checkpoints**: `docs/archive/checkpoints/` — detailed session records
* **Plugin file**: `rankmath-rest-bridge.php` — single source of truth
* **Manifest**: `update-manifest.json` — version + download URL for self-update

> If these files/folders don't exist, create them with minimal scaffolding.

---

## 4) Quality Gate

```bash
phpcs --standard=phpcs.xml.dist rankmath-rest-bridge.php
```

Ref: [Plugin Best Practices](https://developer.wordpress.org/plugins/plugin-basics/best-practices/) · [Security](https://developer.wordpress.org/apis/security/) · [Coding Standards](https://developer.wordpress.org/coding-standards/)

* WordPress Coding Standards enforced via phpcs — treat lint errors as build failures.
* **Validate** inputs; **sanitize** before storing; **escape as late as possible** before output.
* Nonce verification + capability check on every write endpoint or form handler.
* All database queries use `$wpdb->prepare()` with typed placeholders (`%d`, `%s`, `%f`, `%i`) — never interpolate variables into SQL strings.
* Strict comparisons (`===`/`!==`) throughout; Yoda conditions where WPCS requires them.
* Never use `extract()`, `eval()`, or the backtick operator.
* I18n: wrap user-visible strings with `__()` / `_e()` / `esc_html__()` (future pass — flag new strings).
* No inline `<script>` unless unavoidable; enqueue via `wp_enqueue_script()`.

---

## 5) Versioning

* File header and `RMB_VERSION` constant must stay in sync.
* Minor change (additive, backward-compatible): +0.0.1
* Feature / new endpoint: +0.1.0
* Breaking API change: +1.0.0
* Update `update-manifest.json` version + zip_url on every version bump.

---

## 6) Override Directives

Change rules via chat. Claude applies the change, shows a small diff, and commits.

```
ADD RULE -> <Section Anchor>
<one sentence rule to append>

EDIT RULE -> <Section Anchor>
<existing text>
--- becomes ---
<new text>

DELETE RULE -> <Section Anchor>
<exact text to remove>
```

**Section Anchors**: `0) Mission` · `1) Session Commands` · `2) Project Guardrails` · `3) Files & Paths` · `4) Quality Gate` · `5) Versioning` · `6) Override Directives`

---

## 7) Quick Reference

* **Start session**: `RRSEO start`
* **Checkpoint now**: `RRSEO checkpoint`
* **End session**: `RRSEO shutdown`
* **Override rule**: `EDIT RULE -> 2) Project Guardrails ...`
