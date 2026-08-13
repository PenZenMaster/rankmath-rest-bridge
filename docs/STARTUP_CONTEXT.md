# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-13
**Branch:** main
**Version:** 3.14.0 (shipped, zip on CDN; NOT yet confirmed live on any site -- no self-update deployment run all session)
**Last Commit:** 3a36d95 -- chore: release v3.14.0 zip

---

## Last 3 Accomplishments

1. **Housekeeping: #21 closed properly, Stage 2 split into #27** -- the
   prior checkpoint (0912) flagged that #21 was still open on GitHub
   despite its Stage 1 shipping in v3.9.0/v3.9.1. Fixed at the start of
   this half: closed #21, filed #27 with the deferred scope (regex,
   cross-domain targets, telemetry, multi-hop loop detection,
   typed-action integration), matching the pattern already used for
   #22 -> #26.

2. **#27 (Redirects Stage 2) shipped and closed (2026-08-13, v3.13.0)**
   -- all five deferred items: `match_type: regex` (length-capped,
   backtracking-guarded), cross-domain targets (global host allowlist,
   bridged into WordPress core's own `allowed_redirect_hosts` filter so
   `wp_safe_redirect()` doesn't silently downgrade an already-validated
   external target), write-throttled `hit_count`/`last_hit` telemetry,
   multi-hop loop detection (chains through exact-type rules, up to 10
   hops), and typed-action engine integration (`create_redirect`/
   `update_redirect`/`delete_redirect`, wired directly onto the existing
   pipeline with full rollback support).

3. **#26 (FAQ Stage 2) shipped and closed (2026-08-13, v3.14.0)** --
   visible Q&A content emission via a `the_content` filter at priority
   20. Resolved the blocking design question from scoping by reading
   Elementor's actual GitHub source directly (not recollection, not a
   live test): Elementor hooks `the_content` at priority 9 and only
   strips three hardcoded WordPress core filters afterward, never
   third-party ones -- so priority 20 was confirmed safe with zero live
   deploys. Backward compatible: FAQ entries created before v3.14.0 stay
   schema-only after upgrading; visible emission only activates once a
   display config is explicitly written.

---

## Next 3 Priorities

1. **Deploy v3.14.0 to a live site** -- carried forward across this
   entire session. No site has been updated past v3.8.1 outside of
   read-only diagnostic checks against `trevoraspiranti.com` (via a
   one-shot, already-revoked application password). `POST /self-update`
   is trustworthy now (issue #20 fixed earlier this session) -- good
   opportunity to confirm that live for the first time since the fix.

2. **No specific #26/#27 follow-up pending** -- both fully delivered,
   no further splits queued. Real-world usage reports on regex rules,
   cross-domain targets, or telemetry would be the natural trigger for
   any further Redirects work.

3. **#18 / #19** (both low priority, unchanged for multiple sessions
   now) -- `/capabilities` `since: null` backfill via `git log -S`
   archaeology (not the issue's own wrong version-guess table), and the
   `entity_clarity` README docs gap. Pick up only if nothing else is
   queued.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `3a36d95`
- 18 commits across the full 2026-08-13 session (v3.9.0 through
  v3.14.0). Full detail split across
  `docs/archive/checkpoints/CheckPoint-2026-08-13_0912.md` (first half,
  #20-#25) and `CheckPoint-2026-08-13_1141.md` (second half, #26/#27).
- Gates: phpcs clean, phpunit 429 tests / 1133 assertions (was 280/799
  at the start of the day)

**Open GitHub issues (2):**
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)

**Files of note:**
- Redirects (Stage 1 + 2): `includes/class-rrseo-redirects.php` --
  `rr_redirect_match()` precedence is exact > longest-prefix > first-regex;
  `rr_redirect_regex_is_safe()` is a length cap + heuristic, not a full
  static analyzer; `rrseo_redirect_extend_allowed_hosts()` bridges into
  WP core's `allowed_redirect_hosts` filter (easy to miss if extending
  this further -- `wp_safe_redirect()` has its own separate allowlist)
- Typed actions: `includes/class-rrseo-actions.php` -- `RR_ACTION_TYPES`
  now has 7 entries; the 3 redirect ones delegate directly to
  `class-rrseo-redirects.php`'s pipeline functions rather than
  reimplementing apply/rollback logic inline
- FAQ (Stage 1 + 2): `includes/class-rrseo-faq.php` --
  `rr_schema_merge_node()`/`rr_schema_remove_node_type()` are generic
  schema-graph helpers, not FAQ-specific (promote to the main file if a
  second consumer needs them); `rrseo_faq_append_to_content()` hooks
  `the_content:20`, deliberately guarded so it never fires during this
  plugin's own `apply_filters('the_content', ...)` diagnostic calls in
  `class-rrseo-observe.php`
- Capabilities map: `rr_get_capabilities_map()` -- 8 new entries added
  across the full session (`redirects.list`, `redirects.write`,
  `observe.agentic_browsing`, `schema.strip_third_party`, `faq.read`,
  `faq.write`; #26/#27 added no new capability entries since they extend
  existing routes rather than adding new ones)
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None. Nothing broken, nothing mid-flight. #18/#19 are both explicitly
  non-urgent.

---

## Key Context Notes

1. **Elementor hooks `the_content` at priority 9 and replaces `$content`
   wholesale; it only strips 3 hardcoded WP core filters afterward
   (`wpautop`, `shortcode_unautop`, `wptexturize`), never third-party
   plugin filters.** Confirmed by reading Elementor's actual GitHub
   source (`includes/frontend.php`,
   `Frontend::THE_CONTENT_FILTER_PRIORITY = 9`,
   `apply_builder_in_content()`, `remove_content_filters()`) -- not
   recollection, not a live test. Any future content-injection feature
   on this plugin should hook `the_content` at a priority greater than 9
   to safely receive Elementor's already-rendered output. This is a
   different mechanism from the earlier-confirmed "does
   `apply_filters('the_content', ...)` render real Elementor output
   inside a REST request" finding (2026-08-13 first half, still true) --
   that was about *reading* rendered content; this is about *safely
   appending new* content via a filter of your own.

2. **`wp_safe_redirect()` has its own separate host allowlist
   (`allowed_redirect_hosts` core filter) from any allowlist a plugin
   defines itself.** A target host validated against this plugin's own
   `rrseo_redirect_allowed_hosts` filter will still get silently
   downgraded to a same-site redirect by `wp_safe_redirect()` unless
   that host is *also* on `allowed_redirect_hosts` -- bridge the two (see
   `rrseo_redirect_extend_allowed_hosts()`) rather than assuming they're
   the same list. Found and fixed during #27 implementation, not caught
   during scoping.

3. **`rmb_schema_set()` (`POST /schema/{post_id}`) has no merge -- it
   always replaces the whole stored graph wholesale.** Any future
   feature needing to add/update a single node type without disturbing
   others already registered for the same post should reuse
   `rr_schema_graph_nodes()`/`rr_schema_merge_node()`/
   `rr_schema_remove_node_type()` in `class-rrseo-faq.php` rather than
   reimplementing graph normalization again.

4. **This plugin now has exactly one `the_content` filter
   (`rrseo_faq_append_to_content`, priority 20).** Before FAQ Stage 2
   there was zero precedent; snippets use action hooks
   (`wp_head`/`wp_body_open`/`wp_footer`) that echo outside the content
   flow, not filters that transform `$content` inline. If a second
   feature needs to inject into `the_content`, coordinate priorities
   with this one (and with Elementor's 9) rather than picking a number
   in isolation.

5. **New sanitization posture from FAQ (`wp_kses_post` on `answer`)
   remains the only field in this plugin using that posture** -- every
   other stored content field is either plain-text
   (`sanitize_text_field`) or intentionally verbatim/unescaped
   (snippets). Don't default to whichever precedent is closest when
   adding a new user-facing content field; pick the posture matching
   that field's actual trust level.

6. **`wp_head` output-buffer bracketing pattern** (from #23, still
   valid): hook the same action at priority `1` (open buffer) and
   `PHP_INT_MAX` (close, scrub, re-emit) to inspect/modify everything
   every other `wp_head` callback produced in between. Only engage the
   buffer when there's actually something to do -- zero overhead
   otherwise.

7. **WP Engine force-rewrites `Cache-Control` on authenticated requests
   at the edge, unconditionally** -- confirmed via WP Engine's own
   `X-Cacheable`/`X-Pass-Why` response headers on
   higginsoverheaddoor.com. Platform policy, not a plugin bug.

8. **`/perf/dequeue-rules` handles are the actual WP dependency handle,
   not the rendered `id` attribute** -- strip the `-css` suffix from
   visible `id` attributes first.

9. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule/redirect write** -- `POST /cache/purge` only
   clears WordPress's internal object cache. A `?cb=<random>` query
   string forces a fresh, uncached fetch for verification.

10. **Full git history goes back to `v1.2.0`, past CHANGELOG.md's
    tracked floor of v2.11.3** -- relevant for issue #18. `git log
    -S"route string" -- rankmath-rest-bridge.php` accurately dates when
    a given route was introduced.

11. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
    (lowercase); `git add` with uppercase path silently stages nothing.
