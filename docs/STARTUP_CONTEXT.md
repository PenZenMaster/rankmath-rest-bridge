# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-13
**Branch:** main
**Version:** 3.14.1 (shipped, zip on CDN; **confirmed live on trevoraspiranti.com** via public sitemap comment)
**Last Commit:** a601b0a -- chore: release v3.14.1 zip

---

## Last 3 Accomplishments

1. **White Labeling backlog item resolved and shipped (2026-08-13,
   v3.14.1)** -- a years-old backlog bullet that had sat unchecked since
   before v3.0. Verified each sub-requirement individually instead of
   assuming: name/description/author/support-link renaming and full
   Plugins-screen hiding already shipped in v2.12.0/v2.13.0; menu
   restriction to admins, absence of upsell badges/footers, and
   constants-only non-revertable configuration were already true by
   design. The one real gap -- the admin-menu icon was hardcoded -- fixed
   with a new `RRSEO_WL_ICON` constant.

2. **v3.14.1 deployed and live-verified on `trevoraspiranti.com`
   (2026-08-13)** -- confirmed via the public `/sitemap_index.xml`
   trailing comment. First live deployment past v3.8.1 all session, and
   the first real-world confirmation that the issue #20 self-update fix
   (disk-version re-verification, shipped earlier today as v3.9.3)
   actually works correctly in production, not just in unit tests.

3. **Full-day issue/backlog cycle (2026-08-13, v3.9.0 -> v3.14.1)** --
   one audit pass against `trevoraspiranti.com` surfaced issue #21
   (redirects), which led to #25 (a dry_run regression found while
   verifying #21), #20 (self-update false-success, carried from
   2026-08-06), and three more feature requests from the same audit
   (#22 FAQ, #23 schema-hygiene, #24 Agentic Browsing). All scoped,
   built, shipped, and closed; #21 and #22 each split a Stage 2 into
   fresh issues (#27, #26) once Stage 1 landed, both of which also
   shipped and closed same-day. Full detail across four checkpoints:
   `CheckPoint-2026-08-13_0912.md` (#20-#25), `_1141.md` (#26/#27),
   `_1350.md` (llms.txt backlog), `_1536.md` (white-label + deployment).

---

## Next 3 Priorities

No carried-forward blocker -- the "no live deployment" item that
appeared in every checkpoint today is resolved. Natural next steps,
none urgent:

1. **#18** -- `/capabilities` `since: null` backfill (low impact,
   optional; use `git log -S` archaeology, not the issue's own
   confirmed-wrong version-guess table).
2. **#19** -- `entity_clarity` README docs gap (low impact, docs-only).
3. Consider deploying v3.14.1 to Kilday Baxter and Higgins when
   convenient -- not urgent, both stable on older versions with no known
   bugs affecting them specifically. The next real-world audit pass
   (like the one that drove this entire session) is the more likely
   source of actual next work.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `a601b0a` (checkpoint commit
  pending)
- 20 commits across the full 2026-08-13 session (v3.9.0 through
  v3.14.1). Full detail split across four checkpoints (see Last 3
  Accomplishments above).
- Gates: phpcs clean, phpunit 429 tests / 1133 assertions (was 280/799
  at the start of the day)

**Open GitHub issues (2):**
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)

**Live deployment status:**
- `trevoraspiranti.com` -- **v3.14.1 confirmed live** (2026-08-13)
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched this
  session

**Files of note:**
- White-label: `includes/class-rrseo-white-label.php` -- `wl_icon()`
  added alongside the existing `wl_name()`/`wl_hidden()`; consumed by
  `includes/class-rrseo-admin.php`'s `add_menu_page()` call
- Redirects (Stage 1 + 2): `includes/class-rrseo-redirects.php` --
  match precedence exact > longest-prefix > first-regex;
  `rrseo_redirect_extend_allowed_hosts()` bridges into WP core's
  `allowed_redirect_hosts` filter (easy to miss if extending further)
- Typed actions: `includes/class-rrseo-actions.php` -- `RR_ACTION_TYPES`
  has 7 entries; the 3 redirect ones delegate directly to
  `class-rrseo-redirects.php`'s pipeline functions
- FAQ (Stage 1 + 2): `includes/class-rrseo-faq.php` --
  `rr_schema_merge_node()`/`rr_schema_remove_node_type()` are generic
  schema-graph helpers, not FAQ-specific; `rrseo_faq_append_to_content()`
  hooks `the_content:20`, confirmed clear of Elementor's own
  priority-9 replacement by reading Elementor's source directly
- Release hook note: run `git push` twice (zip commit lands after
  refspec)

**Blockers:**
- None.

---

## Key Context Notes

1. **Elementor hooks `the_content` at priority 9 and replaces `$content`
   wholesale; it only strips 3 hardcoded WP core filters afterward
   (`wpautop`, `shortcode_unautop`, `wptexturize`), never third-party
   plugin filters.** Confirmed by reading Elementor's actual GitHub
   source, not recollection or a live test. Any future content-injection
   feature on this plugin should hook `the_content` at a priority
   greater than 9.

2. **`wp_safe_redirect()` has its own separate host allowlist
   (`allowed_redirect_hosts` core filter) from any allowlist a plugin
   defines itself.** Bridge the two (see
   `rrseo_redirect_extend_allowed_hosts()`) rather than assuming a
   plugin-level allowlist alone is sufficient for `wp_safe_redirect()`
   to honor a cross-domain target.

3. **`rmb_schema_set()` (`POST /schema/{post_id}`) has no merge -- it
   always replaces the whole stored graph wholesale.** Reuse
   `rr_schema_graph_nodes()`/`rr_schema_merge_node()`/
   `rr_schema_remove_node_type()` in `class-rrseo-faq.php` for any
   future feature needing to add/update a single node type without
   disturbing others already registered for the same post.

4. **Verify backlog items against actual code before either building
   more or marking them done -- don't just trust the phrasing.** Two
   backlog items closed today (llms.txt raw-content, White Labeling)
   turned out to be already substantially or fully covered by features
   that shipped later under different names, just never checked off.
   Both were resolved by reading the actual code, not by re-litigating
   the original vague request.

5. **`wp_head` output-buffer bracketing pattern** (from #23): hook the
   same action at priority `1` (open buffer) and `PHP_INT_MAX` (close,
   scrub, re-emit). Only engage the buffer when there's actually
   something to do -- zero overhead otherwise.

6. **WP Engine force-rewrites `Cache-Control` on authenticated requests
   at the edge, unconditionally** -- confirmed via WP Engine's own
   `X-Cacheable`/`X-Pass-Why` response headers on
   higginsoverheaddoor.com. Platform policy, not a plugin bug.

7. **`/perf/dequeue-rules` handles are the actual WP dependency handle,
   not the rendered `id` attribute** -- strip the `-css` suffix from
   visible `id` attributes first.

8. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule/redirect write** -- `POST /cache/purge` only
   clears WordPress's internal object cache. A `?cb=<random>` query
   string forces a fresh, uncached fetch for verification.

9. **Full git history goes back to `v1.2.0`, past CHANGELOG.md's
   tracked floor of v2.11.3** -- relevant for issue #18. `git log
   -S"route string" -- rankmath-rest-bridge.php` accurately dates when
   a given route was introduced.

10. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
    (lowercase); `git add` with uppercase path silently stages nothing.
