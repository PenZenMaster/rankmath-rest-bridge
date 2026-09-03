# RankRocket SEO Control Layer — Project Status

**Last Updated:** 2026-09-02
**Current Version:** 3.14.1
**Working Directory:** `E:\projects\rank_rocket_seo_plugin\`
**Branch:** main
**Last Commit:** 58ec0b8 -- chore(checkpoint): 2026-08-14_0940
**Git Status:** clean

---

## 2026-09-02 Session -- New Site Onboarding + Domain Redirect (endlessenergyfitness.com)

### Session Summary
Short session, no plugin source touched. User migrated a new client site,
`endlessenergyfitness.com`, from non-www to `www` canonical and asked
whether a redirect was needed. Work happened entirely outside this repo:
onboarded the site into the RankRocket MCP site registry (on the remote
MCP host, `mcp.fullmetaljacket.com`) and set up + verified a domain-level
301 redirect via cPanel. Full detail in
`docs/archive/checkpoints/CheckPoint-2026-09-02_0930.md`.

### Accomplishments
- **`endlessenergyfitness.com` onboarded to the RankRocket MCP registry**
  on the remote MCP host -- confirmed working via `rankrocket_status`
  (plugin v3.14.1, RankMath active, Elementor + Elementor Pro active).
  Caught and fixed a JSON syntax error (missing closing brace) in the
  registry file that had taken the whole MCP server down.
- **Domain-level 301 (non-www -> www) verified working** for
  `endlessenergyfitness.com` via cPanel Domain Redirects. Confirmed by
  source read that this plugin's own redirect engine is path-only and
  cannot do domain-level redirects (correctly routed the user to cPanel
  instead). Live-verified via `curl` with cache-busting: every real URL
  tested gets a single 301 hop with full path preserved to the matching
  `www` URL, no loops.
- **Non-blocking finding**: the new cPanel `.htaccess` rule currently
  only fires at the raw server level for the domain root and static
  files; WordPress-routed pages are redirected by WordPress's own
  pre-existing `redirect_canonical()` instead (likely an `.htaccess`
  rule-ordering artifact from cPanel appending after the WordPress
  block). Functionally correct today; true defense-in-depth would need
  the block moved above `# BEGIN WordPress`.

### Technical Changes
- None in this repo. All changes were external: remote MCP server's
  `sites.json`, and `endlessenergyfitness.com`'s cPanel Domain Redirects
  config.

### Known Issues / Blockers
- cPanel redirect rule ordering on `endlessenergyfitness.com` (see
  Accomplishments) -- not urgent, no user-facing impact.
- Pre-existing items unchanged: #18, #19 (low-impact, optional); P3
  RankMath Reference Purge still blocked on re-checking `rankmath_active`
  across the original 4 live sites.

### Next
Carryover from prior session (unchanged): re-check `rankmath_active`
across tristate-hvac, trevoraspiranti, Higgins, Kilday Baxter before P3
RankMath Reference Purge can be picked up. #18/#19 remain low-priority.
New, low-priority: consider hardening the `endlessenergyfitness.com`
cPanel redirect ordering if defense-in-depth is wanted.

### Backlog Movement
- No plugin backlog items added, closed, or reprioritized.

---

## 2026-08-14 Session -- Deployment Confirmation, Cleanup, MCP Investigation

### Session Summary
Short session. Confirmed a second live v3.14.1 deployment
(tristate-hvac.com, smoke-tested PASS), cleaned up 17 stale release zips
from `releases/`, then spent most of the session investigating and
building a sibling project: an MCP (Model Context Protocol) server
wrapping this plugin's REST API. The investigation itself is the
notable finding -- it disproved the assumption that `workflow-portal`
already drives this plugin programmatically (it doesn't; credentials are
hand-pasted into an external Perplexity.ai session per run). That gap
became the justification for building `rankrocket-mcp` as a new,
separate repo. No source files in this plugin repo changed.

### Accomplishments
- **tristate-hvac.com confirmed live on v3.14.1**, smoke-tested PASS
  (2026-08-14) -- second live deployment site, alongside
  trevoraspiranti.com (2026-08-13).
- **Old release zips removed** (commit `8526e5e`) -- 17 stale zips
  (v3.3.0-v3.14.0) deleted from `releases/`; current v3.14.1 zip kept.
  Recoverable from git history.
- **MCP integration investigated and scoped** -- two parallel research
  passes (this plugin's full REST API surface: 51 routes, auth model,
  typed action engine, `/capabilities`; and `workflow-portal`'s actual
  integration pattern) confirmed there is currently zero programmatic
  integration between `workflow-portal` and this plugin. Full findings
  preserved in the new repo, not here (see Backlog Movement below).
- **`rankrocket-mcp` built and shipped (Phase 1)** -- new standalone repo
  at `E:\projects\rankrocket-mcp`, pushed to
  `github.com/PenZenMaster/rankrocket-mcp` (private). Three read-only MCP
  tools (`rankrocket_status`, `rankrocket_content_audit`,
  `rankrocket_action_dry_run`) built and build-verified against this
  plugin's actual route definitions; not yet live-tested against a real
  site. Ported this repo's `.claude/CLAUDE.md` playbook pattern over
  (`RRMCP start/checkpoint/shutdown`) so both repos share a consistent
  workflow.
- **[P3] RankMath Reference Purge backlog item reviewed** -- found not
  fully scoped: its own stated prerequisite (confirm no live client
  depends on the `rank_math_*` fallback) is unmet per the 2026-08-06
  telemetry note already in this doc (Higgins still `rankmath_active:
  true`), and the "optionally rename identifiers" scope question is
  still open. No code change; just a scoping finding, now carried into
  Next 3 Priorities in `STARTUP_CONTEXT.md`.

### Technical Changes
- `releases/v3.3.0/` through `releases/v3.14.0/` -- zip files deleted
  (commit `8526e5e`), no other files touched in this repo.

### Known Issues / Blockers
- None new. #18 and #19 remain open, low-impact, optional (unchanged).
- P3 RankMath Reference Purge blocked on re-verifying `rankmath_active`
  across all 4 live sites (see Accomplishments above) -- not a code
  blocker, a scoping blocker.

### Next
Re-check `rankmath_active` status across tristate-hvac, trevoraspiranti,
Higgins, and Kilday Baxter before P3 RankMath Reference Purge can be
picked up. #18/#19 remain low-priority, pick up only if nothing else is
queued. No urgent items.

### Backlog Movement
- No plugin backlog items added, closed, or reprioritized this session
  beyond the P3 scoping note above. The MCP investigation and build
  happened entirely in the new `rankrocket-mcp` repo and does not modify
  this plugin's backlog -- full investigation detail (complete REST API
  route inventory, workflow-portal findings, design rationale) lives in
  that repo's `docs/investigation-mcp-rationale.md`, not duplicated here.

---

## 2026-08-13 Session (final) -- White-Label Fix + Live Deployment: v3.14.1

### Session Summary
Short close-out to the day. Fixed the White Labeling backlog item
(verified each sub-requirement individually; only the admin-menu icon
was actually missing) and shipped it as v3.14.1. User then deployed
v3.14.1 to `trevoraspiranti.com` and it was live-verified via the
public sitemap comment -- the first live deployment past v3.8.1 all
session, and the first real-world confirmation that the issue #20
self-update fix (shipped earlier today) works correctly in production.

### Accomplishments
- **v3.14.1 SHIPPED** -- new `RRSEO_WL_ICON` constant
  (`RRSEO_White_Label::wl_icon()`) for the admin-menu sidebar icon,
  closing out a backlog item that had sat unchecked since before v3.0.
  Everything else in that bullet (renaming, hiding, non-revertable
  constants-only config, no upsell branding to remove) was already true
  by design or shipped in v2.12.0/v2.13.0 -- verified, not assumed.
- **v3.14.1 DEPLOYED + LIVE-VERIFIED** on `trevoraspiranti.com`,
  confirmed via the public `/sitemap_index.xml` trailing comment.

### Next
No carried-forward blocker. #18/#19 remain low-priority/optional.
Consider deploying v3.14.1 to Kilday Baxter and Higgins when
convenient -- not urgent.

---

## 2026-08-13 Session (continued) -- Redirects Stage 2 (#27) + FAQ Stage 2 (#26): v3.13.0 -> v3.14.0

### Session Summary
Direct continuation of the same day's earlier #20-#25 cycle. Opened by
fixing a gap the prior checkpoint flagged: issue #21 was still open on
GitHub despite its Stage 1 shipping earlier -- closed it properly and
split the deferred scope into a fresh #27, matching the #22 -> #26
pattern already used once that day. User then asked to scope #27 and
#26 together and implement both. Both were fully understood from code
built earlier in the session, so no research forks were needed this
time -- scoping happened directly, including one piece of real
first-time research (reading Elementor's actual GitHub source to
resolve #26's blocking design question) that avoided a live deploy to
a client site.

### Accomplishments
- **#21 closed, #27 filed** -- housekeeping fix; #21's Stage 1
  (v3.9.0/v3.9.1) had shipped but the parent issue was never formally
  closed. #27 carries the five deferred items with full context from
  the actual Stage 1 implementation, not a bare re-file of the original
  proposal.
- **v3.13.0 SHIPPED + CLOSED #27** -- all five Redirects Stage 2 items:
  `match_type: regex` (200-char cap, nested-quantifier rejection, PCRE
  validity check -- a heuristic guard, not a full static analyzer);
  cross-domain targets via a global `rrseo_redirect_allowed_hosts`
  filter allowlist, **bridged into WordPress core's own
  `allowed_redirect_hosts` filter** (found during implementation that
  `wp_safe_redirect()` has a separate allowlist and would have silently
  downgraded an already-validated external target back to same-site
  without this); write-throttled `hit_count`/`last_hit` telemetry
  (skip-if-recent, 60s, deliberately skips the REST-cache purge to
  avoid cache-thrashing a popular redirect); multi-hop loop detection
  (chains through exact-type rules, up to 10 hops, superseding Stage
  1's `source === target`-only check); typed-action engine integration
  (`create_redirect`/`update_redirect`/`delete_redirect` wired directly
  onto the existing pipeline, full dry-run/execute/rollback support).
  Match precedence: exact > longest-prefix > first-regex. 42 new tests
  (379 -> 417).
- **v3.14.0 SHIPPED + CLOSED #26** -- FAQ Stage 2 visible content
  emission. Resolved the blocking design question from scoping by
  reading Elementor's actual source directly: Elementor hooks
  `the_content` at priority 9 and only strips 3 hardcoded WP core
  filters afterward, never third-party ones -- so a filter at priority
  20 was confirmed safe with zero live testing needed. `POST
  /faq/{post_id}` gained `position`/`heading` fields; visible HTML
  renders from the same stored items as the schema, so they can't drift
  apart. Backward compatible -- FAQ entries created before v3.14.0 stay
  schema-only after upgrading; visible emission only activates once a
  display config is explicitly written (deliberate, to avoid a surprise
  front-end change on existing sites). New `_rrseo_faq_display` meta
  key, separate from the schema graph (matches #23's
  `strip_third_party` separation pattern). 12 new tests (417 -> 429).
- Suite grew 379 -> 429 tests (50 new) this half; phpcs clean on every
  commit; both release zips verified before push.

### Next
Deploy v3.14.0 to a live site -- no site updated past v3.8.1 all session,
outside of read-only diagnostic checks. No #26/#27 follow-up pending;
both fully delivered. #18/#19 remain low-priority, unchanged for
multiple sessions.

---

## 2026-08-13 Session -- Issues #21/#25/#20/#24/#23/#22 Cycle: v3.9.0 -> v3.12.0

### Session Summary
User filed issue #21 (REST-managed redirects) from a live audit against
`trevoraspiranti.com`. Scoped it down with the user's sign-off on the key
tradeoffs, shipped the REST API then a matching admin UI page. While
verifying, found a real regression (#25: `dry_run:true` silently
persisting writes on the new bulk endpoint, plus the same pre-existing
bug in `/snippets/bulk`) and fixed it same-session, then picked up the
carried-over #20 (`self-update` false-success) and fixed that too. The
same audit had surfaced three more feature requests (#22 FAQ, #23
schema-hygiene, #24 Agentic Browsing diagnostics); all three were scoped
together via parallel research forks that ground-truthed each issue's
own technical claims against actual code (two of three turned out to be
wrong), then implemented and shipped one at a time. #22 was split after
Stage 1 shipped -- Stage 2 (visible content emission) filed as a fresh,
better-scoped issue (#26) rather than left as a vague carryover.

### Accomplishments
- **v3.9.0 + v3.9.1 SHIPPED (#21 Stage 1)** -- full REST-managed redirect
  surface (`GET/POST /redirects`, `GET/POST/DELETE /redirects/{id}`,
  `POST /redirects/bulk`, `POST /redirects/preview`) plus a matching
  admin UI page (read-only table + "Test a URL" tool, matching this
  panel's existing observability-only design). Scoped down from the
  original proposal: exact/prefix matching only, relative targets only,
  single-hop loop rejection only, no hit_count telemetry, no typed-action
  integration -- all explicitly deferred, not silently dropped. New
  `includes/class-rrseo-redirects.php`. 43 new tests (280 -> 323).
- **v3.9.2 SHIPPED + CLOSED #25** -- `POST /redirects/bulk` and the
  pre-existing `POST /snippets/bulk` both silently ignored `dry_run:true`
  and persisted writes anyway. Both now register the arg and gate the
  write behind `! $dry_run`. 2 new regression tests (323 -> 325).
- **v3.9.3 SHIPPED + CLOSED #20** -- `POST /self-update` now re-reads the
  installed version from disk via `get_plugin_data()` before reporting
  success, instead of trusting the manifest's claimed version. Returns
  `500` on a genuine mismatch instead of a false `success: true`.
- **v3.10.0 SHIPPED + CLOSED #24** -- `GET /observe/agentic-browsing/{post_id}`,
  3 static-DOM checks (primary action, schema completeness, BreadcrumbList
  presence) matching PSI's Agentic Browsing sub-audits. Live-verified
  before writing code (via a one-shot, user-supplied and since-revoked
  application password against `trevoraspiranti.com`) that
  `apply_filters('the_content', ...)` returns real Elementor-rendered
  HTML inside a REST request -- the issue's own claim that this endpoint
  "already renders the page" was false (it's a pure meta read); the
  actual rendering endpoints needed live verification instead. Extracted
  `rr_observe_extract_schema_types()` for reuse. 12 new tests (325 -> 337).
- **v3.11.0 SHIPPED + CLOSED #23** -- `strip_third_party` field on the
  existing `POST /schema/{post_id}` (the issue's own simpler fallback
  variant, not a separate `/schema-hygiene` resource). Implemented as a
  `wp_head` output-buffer scrub, chosen over Elementor-widget-data parsing
  because the issue's own real-world example reads like dynamically-
  rendered widget output, which parsing `_elementor_data` would not have
  caught. New `includes/class-rrseo-schema-hygiene.php`. 18 new tests
  (337 -> 355).
- **v3.12.0 SHIPPED -- #22 Stage 1 closed, Stage 2 split into #26** --
  `GET/POST/DELETE /faq/{post_id}` merges an `FAQPage` schema node onto a
  post's existing graph without disturbing other nodes. Required new
  read-modify-write logic (`rr_schema_merge_node()`/
  `rr_schema_remove_node_type()`) since `rmb_schema_set()` only does
  wholesale replace. New `wp_kses_post` sanitization posture for
  `answer` -- deliberately stricter than snippets' intentional
  verbatim/unescaped storage. Visible content emission deferred to #26
  (this plugin has zero `the_content` filter precedent; needs the same
  kind of live verification #24 needed before implementation). New
  `includes/class-rrseo-faq.php`. 24 new tests (355 -> 379).
- Suite grew 280 -> 379 tests (99 new) across the session; phpcs clean on
  every commit; all six release zips built via the pre-push hook and
  verified before push.

### Next
Deploy v3.12.0 to a live site (none updated past v3.8.1 this session,
outside of read-only diagnostic checks). Issue #26 (FAQ Stage 2) needs a
live-verification step (Elementor `the_content` filter priority) before
implementation, same shape as #24's blocker. #18/#19 remain low-priority,
unchanged from before this session.

---

## 2026-08-06 Session (final) -- v3.8.1 Post-Close Fixes (#16/#17), Telemetry Verdict Review Complete

### Session Summary
Continuation of the same day's milestone-closing session. At the user's
request, reviewed outstanding GitHub issues and doc-level technical
debt: found and fixed two real bugs (#16, #17) surfaced by an external
post-close audit of the milestone work, filed two new issues (#19 a
long-carried docs gap, #20 a genuine self-update reliability bug
discovered live during verification on Higgins), cleaned up
long-stale roadmap checkboxes, and finally closed out the telemetry
verdict review that had sat as the top open priority since 2026-07-10.

### Accomplishments
- **v3.8.1 SHIPPED + CLOSED #16, #17** -- `POST /media` now returns the
  documented `422` (not WP's generic `400`) for missing `alt_text`/
  `source`. `GET /capabilities`'s `Cache-Control` header fix required
  correcting an earlier wrong root-cause guess (host proxy -> actually
  WordPress core forcing nocache on authenticated REST responses,
  confirmed via cross-host testing) -- fixed via a `rest_pre_serve_request`
  hook. Both live-verified on kildaybaxter.com and higginsoverheaddoor.com.
- **Found WP Engine has its own separate Cache-Control override**
  (`X-Pass-Why: auth` on every authenticated request, after PHP already
  sent the correct header) -- documented as host platform policy, not a
  plugin defect.
- **Found + filed #20**: `POST /self-update` reported false success
  twice on Higgins (`to_version: 3.8.1` while `/status` stayed on
  `3.8.0`) -- `rmb_self_update()` never re-verifies the installed
  version from disk. Worked around via manual wp-admin update; bug
  filed with a well-scoped fix proposed, not yet implemented.
- **Reviewed #18, declined its own suggested fix** -- the issue's
  version-backfill table contains a confirmed-wrong guess; git history
  goes back to v1.2.0 (past CHANGELOG's v2.11.3 floor), so accurate
  backfill is feasible via `git log -S` archaeology instead.
- **Filed #19** for the entity_clarity README callout carried as a doc
  note since 2026-07-20.
- **Cleaned up stale roadmap checkboxes** for v3.0 Bites 2-4 (shipped
  2026-07-09/10, never checked off) -- verified real test coverage
  before checking each box.
- **Telemetry verdict review completed** -- reviewed rrc-telemetry.php's
  32-day rankrocket.co sample via CSV export. Two cleanup candidates
  for the user (`wordpress-importer` DEAD, `wordfence-activator-1.4.0`
  stale orphan). RankMath silent ~12 weeks on rankrocket.co -- relevant
  but not sufficient evidence for the P3 RankMath Reference Purge item;
  Higgins still shows `rankmath_active: true`.
- Suite: 280 tests / 799 assertions throughout; phpcs clean.

### Next
Fix issue #20 (self-update false-success) -- now the top open item.
User completes rankrocket.co plugin cleanup separately. #18 optional,
pick up only if nothing else is queued.

---

## 2026-08-06 Session -- Programmatic Page Provisioning Milestone: Issues #12-#15 -- SHIPPED and CLOSED

### Session Summary
Four issues filed 2026-08-06 by the Location Page Builder skill's Kilday
Baxter Peru, IL rollout (2026-07-24) were triaged, sequenced into a
GitHub milestone by risk/dependency, and built out end-to-end in one
session: #13 (schema `@graph` support), #14 (`POST /media`), #12 (`POST
/elementor/set-data`), #15 (`GET /capabilities`). Session opened with the
carryover v3.4.1 smoke test (issues #9/#10/#11), then moved straight into
the new milestone. All four milestone issues were implemented, released
(v3.5.0-v3.8.0), and live-verified on kildaybaxter.com by end of session;
the milestone is now closed.

### Accomplishments
- **Issues #9/#10/#11 closed** -- live smoke test confirmed the v3.4.1
  `business_facts` merge fix holds on kildaybaxter.com.
- **v3.5.0 SHIPPED + CLOSED (#13)** -- `POST /schema/{post_id}` accepts
  single node, bare array, or `@graph` envelope, normalized to a
  canonical `@graph` in `_rrseo_schema_graph`; 20-node cap (413). 11 new
  tests (228 -> 239). Live-verified: Service + BreadcrumbList graph
  renders correctly in one `<script>` tag on a production page.
- **v3.6.0 SHIPPED + CLOSED (#14)** -- `POST /media` audited upload
  wrapper: required `alt_text`/`source`, MIME allowlist checked against
  real file content (415), 10MB cap (413), `is_placeholder`/`source`
  pairing, `dry_run`; new `GET /media/placeholders`. 16 new tests (239 ->
  255).
- **v3.7.0 SHIPPED + CLOSED (#12)** -- `POST /elementor/set-data` writes
  `_elementor_data`/`_elementor_edit_mode`/`_elementor_template_type`;
  shape validated (422), widgets counted, non-blocking Pro-widget
  warnings, CSS cache cleared. 18 new tests (255 -> 273).
- **v3.8.0 SHIPPED + CLOSED (#15)** -- `GET /capabilities`: version, host
  state, dotted-key capability map with `available`/`route`/`since`,
  `allowed_schema_types`, `audit_log_enabled`. 7 new tests (273 -> 280).
- **Combined live smoke test** -- self-updated kildaybaxter.com 3.5.0 ->
  3.8.0; verified all three remaining capabilities against a scratch
  draft page (persistence confirmed via REST meta, full reject-path
  coverage on media upload), cleaned up, closed #12/#14/#15 plus the
  GitHub milestone (4/4 resolved).
- Suite: 280 tests / 799 assertions; phpcs clean on all four releases.

### Next
Resume the Higgins v3.3.0 perf deployment (now several releases behind
current) -- render-block swap, `code_b64` + `priority:1`, PageSpeed
measurement, then move Higgins to v3.8.x. Telemetry verdict review on
`rrc-telemetry.php` also still outstanding. No open GitHub issues remain.

---

## 2026-07-20 Session (final) -- AEO/GEO Write Surface: Issues #9, #10, #11 -- SHIPPED

### Session Summary
Two field-driven issues (#9, #10) arrived from the Kilday Baxter &
Associates SEO audit, both traced to the same root cause: `POST /llms`
`business_facts` writes had no validation, no documented payload shape,
and never rendered into `/llms.txt` without a hand-authored `sections`
config. Bundled into a single v3.4.0 release. A replay of the same audit
against the freshly-deployed v3.4.0 immediately surfaced a regression
(#11) in that fix -- caught and shipped same-day as v3.4.1.

### Accomplishments
- **v3.4.0 SHIPPED (issues #9, #10)** -- `business_facts` writes validated
  (`business_name` + `description` required, size caps, `422
  invalid_business_facts`); Business Facts + Common Questions block now
  renders into `/llms.txt` by default; new fields `description`,
  `tagline`, `hours`, `years_in_business`, `key_differentiators`,
  `common_questions`; `has_business_facts` tightened to require a name
  plus >=2 enrichment fields, new `business_facts_source` signal; README
  gained full `POST /llms` + `/aeo-geo/*` docs (previously undocumented).
  16 new tests (207 -> 223).
- **v3.4.1 SHIPPED (issue #11)** -- same-day fix for a regression in the
  v3.4.0 fix itself: partial `business_facts` writes were replacing the
  whole stored object instead of merging, contradicting the v3.4.0
  README's own contract and silently collapsing readiness scores on any
  incremental update. New `rr_merge_llms_business_facts()`; validation now
  runs against the merged result. 5 new tests (223 -> 228).
- Removed 9 superseded release zips (v2.17.4-v3.2.0) at user's
  confirmation -- only current + immediately-prior zips retained going
  forward.
- Suite: 228 tests / 550 assertions; phpcs clean on both releases.

### Next
User smoke-tests v3.4.1 against a live site; close issues #9/#10/#11 on
GitHub once confirmed; resume the still-pending Higgins v3.3.0 perf
deployment (render-block swap, code_b64 + priority:1, PageSpeed
measurement) carried over from the prior session.

---

## 2026-07-10 Session (final) -- Issues #7 + #8: Higgins Perf Unblock -- COMPLETE

### Session Summary
Same-day continuation after the v3.0 roadmap completed. The user filed two
feature requests from live Higgins Overhead Door perf work; both were
reviewed, built, shipped, and closed within hours. Together they unblock
snippet-based render-blocking-CSS elimination on locked-theme, WAF-fronted
client sites -- the projected 2,590ms LCP savings on Higgins is now
collectable.

### Accomplishments
- **v3.2.0 SHIPPED (issue #7)** -- snippet `priority` field (0-10000 ->
  WP hook priority); one emitter per (location, priority) bucket; snippets
  without priority keep per-location defaults (deliberate deviation from
  the issue's blanket-20 spec to avoid moving body_open/footer snippets);
  9 new tests -- first unit coverage of the emission path
- **v3.3.0 SHIPPED (issue #8)** -- `code_b64` WAF-safe transport on all
  three snippet write endpoints; strict base64 + UTF-8 validation (422
  invalid_base64); code_b64 wins over code/content; update endpoint
  included beyond the issue's AC; 6 new tests
- **Issues #7 + #8 closed** with implementation comments
- Suite: 207 tests / 511 assertions; CI green on every push

### Next
Deploy v3.3.0 to Higgins + POST the render-block swap (code_b64 +
priority:1) + measure PageSpeed; telemetry verdict review (~2026-07-13);
cleanup items (live rollback confirmation, llms.txt raw-content
verification, stale bite checkboxes below).

---

## 2026-07-10 Session (continued) -- Bites 3+4: v3.0 Roadmap COMPLETE

### Session Summary
Same-day continuation after the v3.0.0 release. Shipped Bite 3 (rollback
layer) as v3.1.0 -- deployed and smoke-tested by the user on production --
then Bite 4 (GitHub Actions CI), whose first run came back green on all
three jobs. The v3.0 milestone from docs/plugin-v3-executor-spec.md is
fully delivered.

### Accomplishments
- **v3.1.0 SHIPPED + DEPLOYED (Bite 3)** -- `GET /actions/{action_id}` +
  `POST /actions/{action_id}/rollback`: drift detection (force override),
  double-rollback protection, irreversible refusal, dry-run,
  delete-on-absent-prior restores; 13 new tests (192/469 green)
- **Bite 4 CI SHIPPED, first run green** -- phpcs + phpunit on PHP 7.4 +
  8.3 and a release-integrity job (version agreement + zip committed)
- **Dependency fix** -- composer platform.php pinned 7.4.33; lock had
  resolved doctrine/instantiator 2.1.0 (PHP ^8.4) which would have broken
  both CI jobs
- **README** -- Typed Actions section added (Bites 2+3 were undocumented)

### Next
Telemetry verdict review (~2026-07-13); roadmap planning -- v3.0 spec
delivered, next scope comes from the Audit Engine side; optional live
execute->rollback confirmation on Higgins.

---

## 2026-07-10 Session -- v3.0.0 Breaking Release: replace-all Removed -- COMPLETE

### Session Summary
User signed off on the deferred replace-all removal after a business-facing
impact breakdown. Shipped v3.0.0 (breaking), which the user deployed to
production (Higgins Overhead Door) via self-update the same session --
2.18.1 -> 3.0.0 in one hop, no issues. Action-engine smoke test passed on
the live install; issue #5 closed with verification comment. Bite 3 started.

### Accomplishments
- **v3.0.0 SHIPPED (breaking)** -- `POST /snippets/replace-all` removed
  (route + handler + `RR_REPLACE_ALL_CAP`); capability hook inverted to
  revoke `rrseo_replace_all_snippets` from administrator on first load;
  README/CHANGELOG/manifest updated with migration paths
- **v3.0.0 DEPLOYED + VERIFIED IN PRODUCTION** -- self-update across a
  two-version jump; action engine smoke-tested live (update_setting
  blog_public launch flow, toggle_indexing)
- **Issue #5 CLOSED** -- verified in production
- **Playbook**: shell-labeling rule added (every command labeled with its
  target environment); stale replace-all guardrail rewritten
- Note: removal spot-check returns `not_found` 404 (the `/snippets/{id}`
  wildcard catches "replace-all" as a slug), not `rest_no_route`; handler
  never upserts, so legacy callers cannot write

### Next
v3.0 Bite 3 rollback layer (GET /actions/{action_id} +
POST /actions/{action_id}/rollback); Bite 4 GitHub Actions CI; telemetry
verdict review (~2026-07-13).

---

## 2026-07-09 Session -- v2.18.1 Hotfix + v3.0 Bite 2 (v2.19.0) -- COMPLETE

### Session Summary
Reviewed the three GitHub issues filed from the staging verification pass.
Root-caused both bugs in code (the issue reports' suspected causes were
partly wrong), shipped hotfix v2.18.1 -- deployed and QA-passed by the user
mid-session -- then built and shipped v3.0 Bite 2 as v2.19.0.

### Accomplishments
- **Issue #3 CLOSED (v2.18.1)** -- schema + audit-log writes had been
  silently flattened to '' since v2.14.4 by registered string sanitize
  callbacks on array-valued meta keys; registrations removed
- **Issue #4 CLOSED (v2.18.1)** -- double canonical: emitter (now
  `rr_emit_singular_canonical()`) unhooks core `rel_canonical` when emitting
- **v3.0 Bite 2 SHIPPED (v2.19.0)** -- `POST /actions/dry-run` +
  `/actions/execute` in new `includes/class-rrseo-actions.php`; whitelist:
  update_setting (9 typed options, issue #5), regenerate_llms_txt,
  update_meta_draft, toggle_indexing; envelopes in capped `rrseo_action_log`
  option; per-post audit rows; both cache busts per invariant
- **Issue #5** -- folded into Bite 2 as update_setting; shipped; closes
  after staging verification
- **Harness hardened** -- bootstrap models core sanitize_meta() (the #3
  blind spot); 26 new tests; suite 179 tests / 408 assertions green

### Next
Deploy v2.19.0 + smoke-test /actions/* on staging (close #5); decide
replace-all removal (breaking, deferred for sign-off); Bite 3 rollback layer
(GET /actions/{id} + POST /actions/{id}/rollback).

---

## 2026-07-06 Session (continued) -- v2.18.0: Bite 1 + Debt Burn-Down -- COMPLETE

### Session Summary
Same-day continuation. G-14 logged-in emission verified manually by the user
(browser login) -- the last open validation item from v2.17.0 is closed.
Shipped v2.18.0: all five v3.0 Bite 1 observation endpoints, the P2
self-canonical discovery fix, admin i18n/display fixes, and a phpunit
bootstrap repair that brings the test suite back to green (153 tests).

### Accomplishments
- **G-14 CLOSED** -- `display_on_user: logged_in` emission verified in a real
  browser session (fires logged-in, absent anonymous)
- **v3.0 Bite 1 COMPLETE** -- `GET /observe/heading-hierarchy/{id}`,
  `/observe/broken-links`, `/observe/alt-coverage`,
  `/observe/schema-graph/{id}`, `/observe/llms-diff` in new
  `includes/class-rrseo-observe.php`; 24 unit tests on the pure helpers
- **P2 self-canonical gap fixed** -- discovery set excludes posts whose
  canonical override points elsewhere (`non_self_canonical`)
- **P2 sitemap lastmod box was stale** -- already fixed in v2.11.4
  (index lastmod derives from the canonical set); checkbox reconciled
- **I18n pass done for the admin surface** -- Text Domain header added;
  deactivation dialog localized; REST error strings deliberately left
  untranslated (machine consumers). Fixed `'Loading\xe2\x80\xa6'` literal
  escape bug visible on all six admin pages + meta box
- **phpunit bootstrap repaired** -- suite had drifted (undefined
  register_activation_hook, is_tax, __, ...); 153 tests / 324 assertions green

### Next
Deploy v2.18.0 (push builds zip; POST /self-update needs user credentials);
v3.0 Bite 2 -- typed action engine (dry-run + execute + whitelist + remove
replace-all); llms.txt raw-content upload verification (old backlog item).

---

## 2026-07-06 Session -- v2.17.7 Deployed + Telemetry Rollout -- COMPLETE

### Session Summary
Deployment session. Pushed the v2.17.7 dry_run fix that was committed locally,
verified the pre-push hook auto-built the release zip, and deployed v2.17.7 to
rankrocket.co via POST /self-update -- completing the first full end-to-end run
of the automated release pipeline (bump -> push -> hook-built zip -> CDN ->
self-update). rrc-telemetry.php v1.5 deployed to mu-plugins on the target site.

### Accomplishments
- v2.17.7 pushed to GitHub (`931e9b1` dry_run fix + `8a13bed` hook-built zip;
  all 4 structural zip checks passed)
- v2.17.7 deployed to rankrocket.co via `POST /self-update` (user-confirmed)
- **[CRITICAL] Auto-update staging verify CLOSED** -- end-to-end flow proven live
- rrc-telemetry.php v1.5 deployed to `wp-content/mu-plugins/`; old
  plugin-usage-audit.php removed (user-confirmed)
- rrc-mu-toolkit GitHub remote confirmed live and in sync (origin/master)
- Recovered missing 2026-05-29 session entry below (was in checkpoint only)

### Next
G-14 manual logged-in emission check (browser login, not Basic Auth);
P2 gaps (self-canonical check, sitemap lastmod) + I18n pass;
v3.0 Bite 1 kickoff -- observation endpoints per plugin-v3-executor-spec.md.

---

## 2026-05-29 Session -- Mu-Plugin Retired + v2.17.5/6 White-Label -- COMPLETE

### Session Summary
(Recovered from CheckPoint-2026-05-29_1800.md -- entry was never appended here.)
Mu-plugin SEO patch layer fully retired; telemetry file renamed and white-labelled;
rankmath-rest-bridge v2.17.5-v2.17.6 shipped AMS white-label housekeeping.

### Accomplishments
- All 6 mu-plugin SEO modules retired; RRC_SEO_EXPLICIT_ROBOTS migrated into
  `rr_merge_wp_robots` default (`226363a`)
- plugin-usage-audit.php renamed rrc-telemetry.php v1.5 (git mv, history intact);
  class renamed; RRC_TEL_WL_NAME / RRC_TEL_WL_HIDE white-label constants added
- v2.17.5: plugin_row_meta filter hides View Details link
- v2.17.6: explicit `index, follow` robots default; AMS white-label plugin header;
  changelog reformatted as HTML for the View Details modal (final: `a78c908`)
- PHPUnit 10 + VerdictTest suite added to rrc-mu-toolkit

### Next
Deploy v2.17.6; deploy rrc-telemetry.php; G-14 manual check.

---

## 2026-05-27 Session -- v2.17.4 Self-Update Fix + Pre-Push Hook -- COMPLETE

### Session Summary
Live update failure reported: users upgrading from v2.9.0 received "Download failed.
Not Found". Root cause: `releases/v2.17.4/rankmath-rest-bridge.zip` was never built
when v2.17.4 shipped. Fixed immediately; then hardened the release process with an
automated `hooks/pre-push` Git hook that builds and commits the zip on every push.

### Accomplishments
- v2.17.4 self-update unblocked (built + pushed the missing zip, commit `229d6f1`)
- `hooks/pre-push` added -- auto-builds release zip before every push (commit `2dd7221`)
- `composer.json` updated to install pre-push hook alongside pre-commit on `composer install`
- Release checklist in STARTUP_CONTEXT.md and CLAUDE.md trimmed from 8 steps to 6

### Next
Salvo staging verify (v2.17.4, perf modules, G-01 WC end-to-end);
rrc-mu-toolkit GitHub remote + retire sequence; G-14 manual logged-in check.

---

## 2026-05-21 Session -- v3.0 Architecture Decision + Doc Layer -- COMPLETE

### Session Summary
Strategic architecture review of `docs/agentic-seo-plugin-spec.md`. Full pros/cons analysis
confirmed the direction (observation + executor surface) is right but the spec shape (agentic
runtime inside WP) was wrong. Adopted Shape B: plugin stays lean read-only data provider +
typed executor; agentic runtime retargeted to external Audit Engine per existing boundary in
`docs/aeo_geo_google_data_architecture.md`. Doc layer delivered — no source code changed.

### Accomplishments
- Agentic spec analyzed: pros/cons, sizing (8-10 months as-written vs 7-10 weeks Shape B)
- Shape B adopted: plugin = observation endpoints + typed executor endpoints only
- Agentic spec archived; redirect pointer written; original preserved for Audit Engine team
- `docs/plugin-v3-executor-spec.md` created — authoritative Shape B plugin-side spec
- v3.0 milestone added to projectStatus.md (4 bites, ~7-10 weeks, sequenced after white-label)

### Next
Salvo WooCommerce staging verify (v2.17.3, perf module, G-01 WC end-to-end);
rrc-mu-toolkit GitHub remote + retire sequence; G-14 manual logged-in check.
Code work: minor fix for duplicate `/canonical-urls/preview` route (lines 2258 + 2575).

---

## 2026-05-15 Session (continued) — v2.17.1/2/3 Validation Hotfixes — COMPLETE

### Session Summary
Worked through three v2.17.x validation reports. v2.17.1 (5/5 fixes confirmed).
v2.17.2 shipped — WP object cache bust at all write sites; G-10 bulk slug fix;
FU-2 documented. v2.17.3 shipped — LiteSpeed URL purge after writes; G-10
individual POST slug fix. All FU-2 findings closed. Architecture doc reviewed —
confirmed external scope, no plugin changes needed.

### Accomplishments
- v2.17.1 validated clean (all 5 hotfixes confirmed)
- v2.17.2: rrseo_bust_option_cache() at all writes; bulk slug _1/_2/_3; README note
- v2.17.3: rrseo_purge_rest_cache() LiteSpeed URL purge; individual POST slug fix
- All three validation reports committed to repo
- Mu-plugin retirement sequence confirmed and documented

### Next
Salvo staging verify (install v2.17.3, configure perf module, verify G-01 WC
end-to-end, retire WC_DEQUEUE + DEFER_NONCRIT); rrc-mu-toolkit GitHub remote +
retire TAX_META modules; G-14 manual logged-in verification.

---

## 2026-05-15 Session — Full Gap Sprint v2.14.4 → v2.17.0 — COMPLETE

### Session Summary
Full sprint through the complete open gap list. Shipped 9 versions (v2.14.4
through v2.17.0) closing G-04, G-05, G-06, G-07, G-09, G-10, G-13, G-14,
G-16, G-17, G-18, G-19. Confirmed Images polish (GET /images/{id}/alt +
bulk-alt cap) already done from crawl sync spec. Only G-15 (hreflang) remains
deferred. All FUs from v2.14.x validation reports were closed earlier this
session.

### Accomplishments
- v2.14.4: G-16 register_post_meta, G-18 canonical-urls/preview alias, G-13 snippet hooks
- v2.15.0: G-10 bulk snippets, G-09/G-17 sitemap exclusions + placeholder expansion
- v2.16.0: G-04/G-05 performance module (dequeue + defer), admin title fix
- v2.16.1: G-19 description fallback in head, G-06 migrate-legacy token guard
- v2.17.0: G-07 Elementor cache helper, G-14 display_on_user snippet field
- README.md created with REST API reference

### Next
Validate v2.17.0 on live site; Salvo staging verify (G-01 WC end-to-end + perf
module to replace mu-plugin modules); rrc-mu-toolkit GitHub remote + retire sequence.

---

## 2026-05-14 Session 3 — v2.13.1 Hotfix + v2.14.0 — COMPLETE

### Session Summary
Reviewed 3-state v2.13.0 validation. Shipped v2.13.1 (422 gate + whitespace
fix). Validated v2.13.1 — G-01 emitter proven live. Shipped v2.14.0 with all
7 follow-up items: G-01 gate lift, post_id: alias, G-02/03/08/11/12.

### Accomplishments
- v2.13.1: 422 gate on term:/tax:/url: patterns, whitespace normalization
- v2.13.1: validated clean; G-01 emitter proven via term_id:21 live probe
- v2.14.0: G-01 gate lifted, post_id: alias, G-02 422, G-03/08 /status fields,
  G-11 GET /snippets/<slug>, G-12 POST /llms-txt/regenerate
- Validation reports v2.13.0 and v2.13.1 committed to repo

### Next
Validate v2.14.0 on live site; Salvo staging verify (G-01 end-to-end on WC);
rrc-mu-toolkit GitHub remote + retire sequence.

---

## 2026-05-14 Session 3 — v2.13.1 Hotfix + v2.14.0 Kickoff — COMPLETE

### Session Summary
Reviewed 3-state v2.13.0 validation report. Identified and fixed two issues:
422 gate for unimplemented `display_on` patterns and whitespace normalization
in `rmb_resolve_tokens()`. Shipped v2.13.1. Validated clean. G-01 emitter
proven working via `term_id:` live probe. v2.14.0 scope locked.

### Accomplishments
- Reviewed v2.13.0 3-state validation (11 pass, 5 fail, 2 investigate)
- Shipped v2.13.1: 422 gate on term:/tax:/url: patterns + token whitespace fix
- Validated v2.13.1: all fixes confirmed, full 8-pattern emission matrix verified
- G-01 emitter proven working via term_id:21 probe on live term archive
- v2.14.0 scope defined: post_id: alias, G-01 gate lift, G-02 422, G-03, G-08, G-11, G-12

### Next
Implement v2.14.0 — restore post_id: alias, lift G-01 gate, G-02 422, G-03,
G-08, G-11, G-12. Then Salvo staging verify + rrc-mu-toolkit remote.

---

## 2026-05-14 Session 2 — v2.13.0 Implementation — COMPLETE

### Session Summary
Implemented all three gaps in the v2.13.0 milestone. Five atomic commits,
all lint-clean. mu-plugin retire markers updated. Ready to shift to
rrc-mu-toolkit for retirement sequence.

### Accomplishments
- G-01: `rr_is_any_tax_archive()` + 4 new `display_on` patterns (term/term_id/tax/url)
- G-08: `rr_validate_display_on()` + 422 validation on snippet create/update
- G-02: `rr_resolve_id()`, `rr_get_term_seo_meta()`, term routing in /update and /get
- G-02: Taxonomy archive emission — title, robots, description/OG, canonical
- Version bump 2.12.2 → 2.13.0, manifest updated
- `rrc-mu-toolkit` retire markers added for RRC_SEO_TAX_META_DESC and RRC_SEO_TAX_META_OG

### Next
Create rrc-mu-toolkit GitHub remote; retire superseded mu-plugin modules; v2.14.0 planning.

---

## 2026-05-14 Session 1 — Housekeeping + v2.13.0 planning — COMPLETE

### Session Summary
Triaged untracked `plugin-usage-audit.php` into its own repo (`rrc-mu-toolkit`).
Committed the Salvo gap report as the new roadmap document, retired the old CSV,
and planned the full v2.13.0 implementation (G-01, G-02, G-08).

### Accomplishments
- `rrc-mu-toolkit` repo scaffolded locally with initial commit `4c7e017`
- `docs/RankRocket_SEO_Functionality_Gaps.md` committed (19 gaps, G-01 to G-19)
- `docs/Gap-Priority-Notes.csv` retired; 4 surviving items migrated as G-16 to G-19
- v2.13.0 implementation plan defined (5 commits, risks documented)

### Next
Implement v2.13.0: G-01 (taxonomy display_on) -> G-08 (validation) -> G-02 (term meta + emission)

---

## 2026-05-13 Session 2 — .gitignore cleanup — COMPLETE

### Session Summary
Identified three untracked files that did not belong in source control.
Updated `.gitignore` and pushed.

### Accomplishments
- `.gitignore` — added `composer.lock`, `.phpunit.result.cache`,
  `.claude/settings.local.json`; working tree now clean

---

## 2026-05-13 Session 1 — White-label doc + Tier 2 update suppression — COMPLETE

### Session Summary
Created white-label configuration guide. Identified and fixed Tier 2 gap: plugin
name leaked on Dashboard > Updates. Fixed via PUC `puc_pre_inject_update` and
`puc_pre_inject_info` filters in `class-rrseo-white-label.php` v1.01.

### Accomplishments
- `docs/white-label-configuration.md` — Tier 1/Tier 2 config guide with update
  delivery options for hidden-plugin scenario
- `includes/class-rrseo-white-label.php` v1.01 — PUC suppression hooks added to
  constructor; plugin no longer surfaces on Dashboard > Updates under Tier 2

### Next
- Staging auto-update verify (WP-CLI path for Tier 2 installs)
- P2/P3 gap review (`docs/Gap-Priority-Notes.csv`)
- `docs/projectStatus.md` full sprint catch-up for v2.11.x / v2.12.x

---

## 2026-05-01 Session — v2.10.0 AEO/GEO Audit Data Layer — IN PROGRESS (branch not merged)

### Session Summary
AEO/GEO audit data layer implemented and reviewed. `check-updates` regression diagnosed and fixed.
PHP 8.4 test suite healed. Two full simplify passes completed.

### Accomplishments

**v2.10.0 — AEO/GEO Audit Data Layer**
- `includes/class-rrseo-aeo-geo.php` (new, ~550 lines) — 5 helper functions + 5 REST callbacks
- `GET /canonical-urls/preview` — machine-readable canonical URL set; resolves P2 backlog gap
- `GET /aeo-geo/readiness` — entity clarity, source guidance, schema depth, llms completeness scores
- `GET /aeo-geo/entity` — NAP, business_facts, homepage schema types, source priority label
- `GET /aeo-geo/schema-audit` — per-URL schema type inventory + missing-opportunity detection
- `GET /aeo-geo/source-sync` — canonical vs sitemap partition (post/page vs product-type URLs)
- `tests/unit/AeoGeoReadinessTest.php` (new) — 24 tests, 76 assertions

**Regression Fix (also v2.10.0)**
- `POST /check-updates` — removed blocking `wp_update_plugins()` call; was causing HTTP timeout
  on restricted hosts, making button appear unresponsive
- Admin "Clear Update Cache" button + description updated to direct user to Dashboard > Updates

**Test Suite Repairs (PHP 8.4)**
- `tests/bootstrap.php` — `WP_Post` stub class + `is_admin()` stub
- `makePost()`/`makePage()` → `WP_Post` in all 3 test files
- `CanonicalUrlSetTest.php` — fixed 2 pre-existing assertion bugs

**Simplify Passes**
- `rr_aeo_compute_readiness()` caches `rr_get_canonical_url_set()` — 3 DB calls → 1
- `rr_aeo_compute_source_sync()` simplified from 6-branch diff to 2-branch partition; dead variables removed
- `?array` nullable type hints, `??` idiom, `home_url('/')` simplification, `phpcs:ignore` pattern

### Commits (this session, branch only)
- `78f6aef` — feat(aeo-geo): canonical-urls/preview + AEO/GEO readiness endpoints (v2.10.0)
- `56aa7c5` — fix: remove wp_update_plugins() from check-updates handler
- `093895f` — test: fix 2 pre-existing CanonicalUrlSetTest failures (PHP 8.4 era)
- `915a610` — refactor(aeo-geo): simplify source_sync, cut DB queries in readiness, fix idioms
- `3695b84` — fix(aeo-geo): ?array nullable type hints on optional canonical_result params

### Known Issues / Next Steps
- Branch not pushed or merged to main
- v2.10.0 release zip not built
- `check-updates` regression affects v2.9.3 live sites (hotfix or v2.10.0 release needed)
- Staging auto-update verify still outstanding

---

## 2026-04-29 Session (continued) — v2.8.0 through v2.9.2 — COMPLETE

### Session Summary
Crawl sync spec P0 and P1 delivered; admin panel and update tooling improvements; all P1
gaps from Gap-Priority-Notes.csv resolved.

### Accomplishments

**v2.8.0 — Shared Canonical URL Set (P0 crawl sync)**
- `includes/class-rrseo-canonical.php` (new) — `rr_get_canonical_url_set()`, `rr_is_url_allowed_for_discovery()`, `rr_get_post_discovery_metadata()`, `rr_get_discovery_description()`, description fallback chain (rrseo_description → excerpt → first_paragraph → title), word-boundary truncation, utility exclusion, numeric-suffix duplicate detection
- All sitemaps, `rmb_serve_llms_txt()`, and `/sitemap/preview` refactored to use the shared helper
- `rmb_sitemap_preview()` expanded with `excluded_urls[]`, per-URL `warnings[]`, UTC lastmod fix
- `rmb_status()` expanded: `sitemap_index_url`, `physical_robots_txt_exists`, `warnings[]`
- `rmb_robots_set()` — `ensure_sitemap_directive` + `preferred_sitemap_only` flags; v4 §15.7 normalisation
- 28 unit tests in `CanonicalUrlSetTest.php`

**v2.9.0 — Crawl sync P1**
- `includes/class-rrseo-llms.php` (new) — section classifier, `rr_classify_url_section()`, `rr_auto_classify_section()`, `rr_validate_llms_section()`, `rr_resolve_business_facts()`, `rr_render_llms_txt()`, `rmb_llms_preview()` REST handler
- `META_LLMS_SECTION` constant, `RR_LLMS_CONFIG_KEY`, `RR_ROBOTS_CONFIG_KEY`
- `GET /llms/preview` (?format=json|text) — read-only, no DB writes
- `POST /llms` expanded: sections object, business_facts, exclude_patterns, max_description_chars, boolean flags
- `POST /update` and `/meta/bulk-update` accept `llms_section`
- `GET /get/{id}` and `/meta/bulk-get` return `llms_section` + `effective_llms_section`
- `GET /images/{id}/alt` handler added
- `/images/bulk-alt` batch cap (`RR_BATCH_MAX`)
- `/status?include_counts=true` with 12h transient cache
- 19 unit tests in `SectionClassifierTest.php`
- Canonical cache invalidation hooks on save_post, delete_post, option updates

**v2.9.1 — Force Update Check + admin llms.txt tab**
- `POST /check-updates` — clears `update_plugins` transient + PUC's `external_updates-rankmath-rest-bridge` option
- Admin Overview — "Force Update Check" button; one-click replaces WP-CLI workflow
- PUC check period filterable via `rrseo_puc_check_period_hours`
- Admin llms.txt tab rebuilt: Business Facts, Section Classifier table, Content Settings, live preview panel
- `docs/staging-verify-autoupdate.md` updated with Force Update Check as primary option

**v2.9.2 — Three P1 gap fixes (from Gap-Priority-Notes.csv)**
- `rankmath-bridge/v1` namespace alias via `rr_legacy_namespace_proxy()` on `rest_pre_dispatch`
- `register_post_meta` for `_rrseo_llms_section` (show_in_rest, sanitize, auth_callback)
- First-paragraph description fallback bug fixed (split raw content before normalization)
- 5 new description fallback tests

### Commits (this session)
- `7057439` — feat: shared Canonical URL Set — v2.8.0
- `607751f` — feat: section classifier, llms/preview, _rrseo_llms_section — v2.9.0
- `82fcc12` — feat: Force Update Check + admin llms.txt tab — v2.9.1
- `0ad343f` — fix: namespace alias, register_post_meta, first-paragraph — v2.9.2

### Known Issues / Gaps
- Staging auto-update verify not yet run on a live site
- `composer install` not yet run on dev machine
- P2 gaps from `docs/Gap-Priority-Notes.csv` still open

---

## 2026-04-29 Session — v2.4.0 through v2.7.0 — COMPLETE

### Session Summary
Broad second session. Delivered auto-update repair, full WPCS compliance, testing stack,
title fix, WordPress admin UI, styled sitemaps, robots.txt endpoint, and a release build
script. Seven version bumps across one session.

### Accomplishments

**v2.4.0 — Testing stack + migrate-legacy**
- `composer.json` (PHPUnit 9.x, phpcs/wpcs, dev-vendor)
- `phpcs.xml.dist`, `phpunit.xml.dist`
- `tests/` — 35 unit tests (validators, schema, manifest, title)
- `hooks/pre-commit` — blocking lint gate on every commit
- `POST /migrate-legacy` — batch rank_math_* → rr_seo_* with dry_run + audit log

**v2.4.1 — WPCS compliance + document title fix**
- 1,804 pre-existing errors resolved (phpcbf + 38 manual)
- `pre_get_document_title` filter moved to plugin-load — fixed silent title discard
- Pre-commit hook set to blocking (`EXIT_CODE=1`)

**v2.5.0 — WordPress admin panel + meta box**
- 6-page admin menu backed by existing REST endpoints
- Read-only Edit Post/Page sidebar meta box with char-count badges
- `includes/` directory introduced; admin loaded via `is_admin()` gate

**v2.6.0 — Styled sitemaps + per-type sub-sitemaps**
- `includes/sitemap.xsl` — browser-readable HTML via PHP-served XSL
- Per-type sub-sitemaps (posts, pages) replace single-entry index
- Accurate UTC lastmod (`post_modified_gmt`)

**v2.7.0 — robots.txt endpoint + release build script**
- `GET/POST /rankrocket-seo/v1/robots-txt`
- `robots_txt` filter at priority 99
- Physical-file detection + warning in all responses
- `bin/build-zip.ps1` with 4 structural verification checks
- Fixed flattened-vendor zip bug that caused v2.7.0 activation failure

### Commits (this session)
- `7ec5e1b` — fix: repair auto-update mechanism + v2.3.1 zip
- `abdb2d4` — feat: migrate-legacy, PHPUnit/phpcs stack, pre-commit (v2.4.0)
- `658bc4b` — fix: pre_get_document_title timing bug (v2.4.1)
- `0988c7d` — refactor: WPCS compliance pass — 1,804 errors
- `9555ba7` — docs: WPCS compliance task + breakdown
- `0b04b1d` — feat: admin panel + meta box (v2.5.0)
- `0671720` — feat: styled sitemaps + per-type sub-sitemaps (v2.6.0)
- `6e0365f` — feat: GET/POST /robots-txt (v2.7.0)
- `56f2f77` — fix: correct v2.7.0 zip (flattened vendor)
- `74a7257` — chore: bin/build-zip.ps1 + correct v2.7.0 zip

### Known Issues / Gaps
- Staging auto-update verify not yet done
- `composer install` not yet run on dev machine
- llms.txt structured config requested but NOT implemented

---

## 2026-04-28 Session — Founding Session — COMPLETE

### Session Summary
Pulled plugin from GitHub, renamed mental model, introduced native meta keys, built schema/
preview/validation/audit stack, hardened replace-all endpoint. Three commits, v2.2.0–v2.3.1.

### Commits
- `885bc19` — feat: rename mental model (v2.2.0)
- `82bc78a` — feat: schema model, preview, validation, audit log (v2.3.0)
- `d39f465` — feat: harden replace-all with custom capability (v2.3.1)

---

## Backlog

### [DONE] Auto-Update Staging Verify
- [x] Manifest fixed and correct zips pushed
- [x] Force Update Check button added (v2.9.1) — no WP-CLI needed
- [x] End-to-end test on live site — v2.17.7 deployed to rankrocket.co via
      POST /self-update on 2026-07-06 (full pipeline: push -> hook zip -> CDN -> update)

### [HIGH] P2 Gaps from Gap-Priority-Notes.csv
- [x] ~~Legacy namespace alias~~ — fixed v2.9.2
- [x] ~~register_post_meta for _rrseo_llms_section~~ — fixed v2.9.2
- [x] ~~First-paragraph description fallback bug~~ — fixed v2.9.2
- [x] ~~Self-canonical/redirect check~~ — fixed v2.18.0 (`non_self_canonical` exclusion in `rr_is_url_allowed_for_discovery()`)
- [x] ~~`/sitemap_index.xml` lastmod~~ — already fixed v2.11.4 (derives from canonical set); checkbox was stale
- [x] ~~`/canonical-urls/preview` endpoint alias~~ — delivered in v2.10.0 AEO/GEO layer
- [x] ~~Expand test-placeholder pattern list~~ — delivered v2.15.0 (`do-not-index-` prefix + operator-configured excluded_post_slugs via /sitemap/exclusions)

### [DONE] llms.txt Structured Config
- [x] `POST /llms` accepts and persists all new fields (v2.9.0)
- [x] `rmb_serve_llms_txt()` delegates to `rr_render_llms_txt()` (v2.9.0)
- [x] Admin panel llms.txt tab displays all config + preview (v2.9.1)

### Ready to Start
- [x] ~~composer install / composer run qa~~ — done 2026-07-06; bootstrap drift
      repaired; phpcs clean, 153 tests / 324 assertions green
- [x] ~~Verify llms.txt raw-content upload support~~ — verified 2026-08-13.
      Carried unverified since 2026-04-29 with no concrete spec anywhere in
      the repo defining what "raw content" meant beyond the phrase itself.
      Confirmed already covered by two fields that shipped later as the
      llms.txt config feature matured: `intro` (`POST /llms`) is
      sanitized once at write time (`sanitize_textarea_field`) and then
      emitted **verbatim** in `rr_render_llms_txt()`
      (`includes/class-rrseo-llms.php:634`) — free-form multi-line raw
      text, no truncation or reformatting; `custom_sections` covers
      arbitrary structured `{heading, items}` text blocks. Both documented
      in README's llms.txt section. No code change needed.
- [x] ~~I18n pass~~ — done 2026-07-06 for the admin surface (Text Domain header,
      deactivation dialog). REST error strings deliberately untranslated.

### After AEO/GEO Audit Data Layer
- [x] ~~**White Labeling** — agency/developer rebranding capabilities~~ —
      verified and closed 2026-08-13 (v3.14.1). Had sat unchecked since
      before v3.0 despite most of it shipping in v2.12.0/v2.13.0; this
      session verified each sub-item individually rather than assuming:
  - [x] Rename plugin name, description — shipped v2.12.0
        (`RRSEO_WL_NAME`/`RRSEO_WL_DESCRIPTION`/`RRSEO_WL_AUTHOR`/
        `RRSEO_WL_AUTHOR_URL`). **Icon was the one real gap** — fixed
        2026-08-13 (v3.14.1) with new `RRSEO_WL_ICON` constant.
  - [x] Hide main menu for non-admin users — already true by design (the
        entire admin menu requires `manage_options`); no separate control
        needed.
  - [x] Remove plugin logos/upgrade badges/"Powered by" footers — N/A,
        nothing like that exists anywhere in this plugin's admin UI.
  - [x] Replace "View Details"/"Support" links — Support: `RRSEO_WL_SUPPORT_URL`
        (v2.12.0). View Details: stripped unconditionally, not just under
        white-label (private plugin, no WordPress.org listing to link to).
  - [x] Settings lockable via `wp-config.php` constants — the core
        architecture of the whole module; nothing is ever database-stored.
  - Documented in `docs/white-label-configuration.md`.

### v3.0 — Plugin as Audit-Engine Executor (Shape B) — COMPLETE (all 4 bites shipped 2026-07-06 through 2026-07-10)

Prerequisites (cache stabilization, mu-plugin retirement, white-label work)
all completed prior to Bite 1. Roadmap fully delivered; kept below for
historical reference and acceptance-criteria traceability.

Reference: `docs/plugin-v3-executor-spec.md`

**Bite 1 — Observation endpoints (1-2 weeks) — DONE v2.18.0 (2026-07-06)**
- [x] `GET /observe/heading-hierarchy/{post_id}` — nested tree + structural warnings
- [x] `GET /observe/broken-links` (scoped, paginated; internal resolved locally,
      external returned unchecked — no external HTTP per acceptance criteria)
- [x] `GET /observe/alt-coverage` (rollup by parent post type, 5-min transient)
- [x] `GET /observe/schema-graph/{post_id}`
- [x] `GET /observe/llms-diff` (current llms.txt vs canonical URL set)

**Bite 2 — Typed action engine (2-3 weeks) — DONE v2.19.0 (2026-07-09)**
- [x] `POST /actions/dry-run` -- typed payload validation, simulated result
- [x] `POST /actions/execute` -- typed payload, persisted audit row, rollback envelope
- [x] Initial action whitelist: `update_setting`, `regenerate_llms_txt`, `update_meta_draft`, `toggle_indexing`
- [x] Remove `replace-all` endpoint (removed v3.0.0, 2026-07-10)

**Bite 3 — Rollback + state (1-2 weeks) — DONE v3.1.0 (2026-07-10), deployed + smoke-tested**
- [x] `GET /actions/{action_id}` -- state lookup
- [x] `POST /actions/{action_id}/rollback` -- replays stored rollback envelope
- [x] Audit log extension: `action_id` + `rollback_payload` stored in `_rrseo_change_log` (no new table)

**Bite 4 — CI/test hardening (2 weeks) — DONE 2026-07-10, green on first run**
- [x] GitHub Actions: phpcs + phpunit on push/PR (PHP 7.4 + 8.3, plus release-integrity job)
- [x] PHPUnit coverage for every new endpoint (dry-run, execute, rollback, auth failure paths)
- [x] Integration test: assert both cache-bust calls fire after every executor write

**Acceptance criteria**
- Every executed action has an audit row and verification result.
- Every action supports dry-run before execute.
- No new custom DB tables.
- No new external HTTP calls from the plugin.
- Namespace stays `rankrocket-seo/v1`.
- All v2.17.x cache invariants preserved on every new write path.

**Out of scope (lives in Audit Engine, not here)**
- Scan orchestration, finding aggregation, policy/approval engine
- AI reasoning layer, AEO/GEO scoring, AI sentiment evaluation
- OAuth, GSC/GA4/GBP fetchers, portal sync
- Custom plugin DB tables for scans/findings/actions
- Autonomous remediation modes

---

### Future / Deferred
- [ ] Native `rr_seo_score` postmeta key + scoring endpoint
- [ ] Custom capability management UI
- [ ] `GET /schema/bulk`
- [ ] OpenGraph image dimension validation
- [ ] Centralized multi-site hub (MainWP-style) — separate future plugin
- [ ] **[P3] RankMath Reference Purge** — remove all external and internal RankMath artifacts:
  - Remove visible RankMath references from admin UI, plugin headers, and REST responses
  - Refactor `rr_get_seo_meta()` migration fallback: make `rank_math_*` read-path opt-in (constant/option) rather than always-on, so sites without RankMath do not incur fallback overhead or surface RankMath key names in responses
  - Optionally rename remaining internal `rank_math_*` / `rankmath` identifiers to `rrseo_*` equivalents in a coordinated search-and-replace pass
  - Use case: new client builds with no RankMath installed; sites migrating off Yoast or AIO SEO where RankMath was never present
  - Prerequisite: confirm no active clients rely on the `rank_math_*` read-path fallback before removing it
  - **Evidence so far (2026-08-07 telemetry verdict review, `docs/plugin-usage-2026-08-07.csv`):** on rankrocket.co, `seo-by-rank-math`/`seo-by-rank-math-pro` fired zero hooks for ~12 weeks (last activity 2026-05-15) — functionally retired on the dogfood site. But `/status` on higginsoverheaddoor.com still reports `rankmath_active: true` (a live client dependency as of 2026-08-06), so the prerequisite remains unmet — this is a partial data point, not a green light

---

## Version History

| Version | Date       | Summary                                                              |
|---------|------------|----------------------------------------------------------------------|
| 2.13.0  | 2026-05-14 | Taxonomy display_on patterns (G-01), term meta routing (G-02), display_on validation (G-08) |
| 2.10.0  | 2026-05-01 | AEO/GEO audit data layer (5 endpoints); fix check-updates regression |
| 2.9.3   | 2026-04-29 | Fix schema JSON-LD in footer; fix PUC vendor path mismatch           |
| 2.9.2   | 2026-04-29 | rankmath-bridge/v1 alias; register_post_meta; first-para bug fix    |
| 2.9.1   | 2026-04-29 | Force Update Check button + POST /check-updates; admin llms tab     |
| 2.9.0   | 2026-04-29 | P1 crawl sync: section classifier, /llms/preview, _rrseo_llms_section |
| 2.8.0   | 2026-04-29 | Shared Canonical URL Set (P0) — all sitemaps + llms.txt unified     |
| 2.7.0   | 2026-04-29 | GET/POST /robots-txt; bin/build-zip.ps1; zip structure fix           |
| 2.6.0   | 2026-04-29 | Styled sitemaps (XSL) + per-type sub-sitemaps                        |
| 2.5.0   | 2026-04-29 | WordPress admin panel + Edit Post/Page meta box                      |
| 2.4.1   | 2026-04-29 | WPCS compliance (1,804 errors); document title fix                   |
| 2.4.0   | 2026-04-29 | POST /migrate-legacy; PHPUnit + phpcs testing stack                  |
| 2.3.1   | 2026-04-28 | replace-all: custom capability + deprecation notice                  |
| 2.3.0   | 2026-04-28 | Schema model, preview endpoint, validation layer, audit log          |
| 2.2.0   | 2026-04-28 | Rename to RRSEO Control Layer; native rr_seo_* keys; new namespace   |
| 2.1.x   | (prior)    | og:image, sitemap fixes, llms.txt fixes                              |
| 2.0.x   | (prior)    | Self-update, cache purge, snippets, image ALT                        |
| 1.x     | (prior)    | Original RankMath REST Bridge                                        |
