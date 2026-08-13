# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-13
**Branch:** main
**Version:** 3.12.0 (shipped, zip on CDN; NOT yet confirmed live on any site -- no self-update deployment run this session)
**Last Commit:** 2b3a921 -- chore: release v3.12.0 zip

---

## Last 3 Accomplishments

1. **Issue #21 (redirects) Stage 1 shipped, then #25 found and fixed
   (2026-08-13, v3.9.0-v3.9.2)** -- built a full REST-managed redirect
   surface (`GET/POST /redirects`, `GET/POST/DELETE /redirects/{id}`,
   `POST /redirects/bulk`, `POST /redirects/preview`) plus a matching
   admin UI page, scoped down from the original proposal (no regex, no
   cross-domain targets, single-hop loop check only). While verifying,
   found a real regression in the new bulk endpoint: `dry_run: true`
   silently persisted writes anyway -- and the same bug existed in the
   pre-existing `POST /snippets/bulk`. Both fixed same-session (issue
   #25). Also fixed issue #20 (`POST /self-update` false-success) in the
   same run, now re-verifying the installed version from disk via
   `get_plugin_data()` before reporting success.

2. **Issues #22/#23/#24 scoped via parallel research, then all shipped
   (2026-08-13, v3.10.0-v3.12.0)** -- an audit against
   `trevoraspiranti.com` surfaced three more feature requests. Ground-
   truthed each against actual code before implementing (two of the
   three issues' own technical claims turned out to be wrong -- see Key
   Context Notes). Shipped `GET /observe/agentic-browsing/{post_id}`
   (#24, 3 static-DOM checks), a `strip_third_party` field on
   `POST /schema/{post_id}` (#23, `wp_head` output-buffer scrub), and
   `GET/POST/DELETE /faq/{post_id}` (#22 Stage 1, schema-only FAQPage
   node merge -- Stage 2 visible-content emission split into new issue
   #26 since it needs the same kind of live-verification #24 needed
   before implementation).

3. **Live-verified a load-bearing technical claim before writing #24's
   code** -- confirmed (via a one-shot, user-supplied and since-revoked
   application password) that `apply_filters('the_content', ...)`
   returns real Elementor-rendered HTML even inside a REST API request
   on `trevoraspiranti.com`, not just on normal front-end page loads.
   This was the single biggest open risk from scoping #24 and #22 Stage
   2 -- confirmed rather than assumed.

---

## Next 3 Priorities

1. **Deploy v3.12.0 to a live site** -- no site has been updated past
   v3.8.1 outside of read-only diagnostic checks against
   `trevoraspiranti.com` this session. `POST /self-update` is now
   trustworthy (issue #20 fixed) -- good opportunity to confirm that
   live for the first time since the fix.

2. **Issue #26** (FAQ Stage 2 -- visible Q&A HTML emission) -- if picked
   up, start with a live-verification step first: confirm what priority
   Elementor's own `the_content` filter registers at, so a
   plugin-appended FAQ block actually survives rather than being
   silently dropped. Same shape of open question #24 had; don't write
   emission code before resolving it.

3. **#18 / #19** (both low priority, unchanged from before this
   session) -- `/capabilities` `since: null` backfill via `git log -S`
   archaeology (not the issue's own wrong version-guess table), and the
   `entity_clarity` README docs gap. Pick up only if nothing else is
   queued.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `2b3a921`
- 14 commits this session (6 feature/fix + 6 release zips + 2 admin-UI/
  fix-only, v3.9.0 through v3.12.0). Full detail in
  `docs/archive/checkpoints/CheckPoint-2026-08-13_0912.md`.
- Gates: phpcs clean, phpunit 379 tests / 1037 assertions (was 280/799
  at session start)

**Open GitHub issues (3):**
- **#26** (new) -- FAQ Stage 2, visible content emission. Needs live
  verification of Elementor `the_content` filter priority before
  implementation.
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)

**Files of note:**
- Redirects: `includes/class-rrseo-redirects.php` (new this session --
  validation, longest-prefix-wins matching, CRUD/bulk/preview pipeline,
  `template_redirect:1` hook)
- Schema hygiene: `includes/class-rrseo-schema-hygiene.php` (new --
  `wp_head` output-buffer scrub bracketed by `RR_SCHEMA_HYGIENE_MARKER_*`)
- FAQ: `includes/class-rrseo-faq.php` (new -- `rr_schema_merge_node()`/
  `rr_schema_remove_node_type()` are generic schema-graph merge helpers,
  not FAQ-specific; promote to the main file if a second consumer needs
  them)
- Agentic Browsing: `includes/class-rrseo-observe.php`
  (`rmb_observe_agentic_browsing()`, `rr_observe_extract_schema_types()`
  -- shared with `/observe/schema-graph`)
- Self-update (issue #20 fix): `rankmath-rest-bridge.php`
  (`rmb_self_update()` -- now calls `get_plugin_data()` post-install)
- Capabilities map: `rr_get_capabilities_map()` -- 6 new entries this
  session (`redirects.list`, `redirects.write`, `observe.agentic_browsing`,
  `schema.strip_third_party`, `faq.read`, `faq.write`)
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None. #26 needs a research step (not a blocker, just a prerequisite)
  before implementation. #18/#19 are both explicitly non-urgent.

---

## Key Context Notes

1. **Two of three scoped-together issues' own technical claims were
   wrong -- verify before implementing, don't trust the issue writeup.**
   #23 cited the canonical-tag dedup fix (issue #4) as prior art for its
   proposed mechanism; it isn't a close analog (that fix is a single
   named-hook unhook, not a solution for arbitrary embedded content).
   #24 claimed `/observe/schema-graph` "already renders the page"; it's
   a pure `get_post_meta()` read with zero rendering. Both corrected
   during scoping, before code was written.

2. **`apply_filters('the_content', ...)` returns real Elementor-rendered
   HTML inside a REST API request**, live-verified 2026-08-13 against
   `trevoraspiranti.com` (Elementor Pro, WP Engine-equivalent stack) --
   19 real, content-specific headings came back from
   `/observe/heading-hierarchy`, not empty/raw output. Not previously
   confirmed; Elementor's frontend bootstrap normally only initializes
   on real front-end page loads, so this was a genuine open question,
   not an assumption. De-risks any future `/observe/*` work needing
   rendered content on Elementor sites. Does NOT by itself confirm the
   *write* side (a plugin-added `the_content` filter appending new
   content) -- that's issue #26's open question, a different mechanism
   (filter priority ordering, not read-side rendering).

3. **`rmb_schema_set()` (`POST /schema/{post_id}`) has no merge --
   it always replaces the whole stored graph wholesale.** Building FAQ
   schema (issue #22) required new read-modify-write logic
   (`rr_schema_merge_node()`/`rr_schema_remove_node_type()` in
   `includes/class-rrseo-faq.php`) to add/remove a single node type
   without disturbing others already registered for the same post
   (`LocalBusiness`, `Service`, etc.). Generic, reusable for any future
   single-purpose node -- not promoted to the main file yet since FAQ is
   still the only consumer.

4. **This plugin has zero `the_content` filter precedent anywhere.**
   Every existing content-injection mechanism (snippets) uses action
   hooks (`wp_head`/`wp_body_open`/`wp_footer`) that echo *outside* the
   content flow, not filters that transform `$content` inline. Relevant
   for issue #26 and any future content-injection feature -- there's no
   existing pattern to copy, it would be new.

5. **New sanitization posture introduced this session:** FAQ `answer`
   fields are sanitized via `wp_kses_post` (issue #22). This is a
   genuinely different, stricter trust level than snippets, which are
   intentionally stored and emitted **verbatim, unescaped** by design
   (the existing docblock is explicit: admin-authored HTML/JS/JSON-LD,
   must not be escaped). Don't conflate the two conventions when adding
   future user-facing content fields -- pick the posture that matches
   the field's actual trust level, not whichever precedent is closest.

6. **`wp_head` output-buffer bracketing pattern established** (issue
   #23): hook the same action at priority `1` (open buffer) and
   `PHP_INT_MAX` (close, scrub, re-emit) to inspect/modify everything
   every other `wp_head` callback produced in between. Only engage the
   buffer when there's actually something to do (checked via a cheap
   post-meta read) -- zero overhead on pages with nothing configured.
   Reusable pattern for any future "modify what other things put in
   `<head>`" feature.

7. **WP Engine force-rewrites `Cache-Control` on authenticated requests
   at the edge, unconditionally** -- confirmed via WP Engine's own
   `X-Cacheable: NO:Passed` / `X-Pass-Why: auth` response headers on
   higginsoverheaddoor.com. Platform policy, not a plugin bug; don't
   chase this further on WP Engine sites specifically.

8. **`/perf/dequeue-rules` handles are the actual WP dependency handle,
   not the rendered `id` attribute** -- strip the `-css` suffix from
   visible `id` attributes first when building rules from page-source
   inspection.

9. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule/redirect write** -- `POST /cache/purge` only
   clears WordPress's internal object cache; its Varnish-purge attempt
   times out on WP Engine. A `?cb=<random>` query string forces a fresh,
   uncached fetch for verification without waiting on a real purge.

10. **Full git history goes back to `v1.2.0`, past CHANGELOG.md's
    tracked floor of v2.11.3** -- relevant for issue #18. `git log
    -S"route string" -- rankmath-rest-bridge.php` accurately dates when
    a given route was introduced.

11. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
    (lowercase); `git add` with uppercase path silently stages nothing.
