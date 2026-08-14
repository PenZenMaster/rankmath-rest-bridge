# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-14
**Branch:** main
**Version:** 3.14.1 (shipped, zip on CDN; live on trevoraspiranti.com **and**
tristate-hvac.com, smoke-tested PASS on both)
**Last Commit:** 8526e5e -- chore: remove old release zips (v3.3.0-v3.14.0)

---

## Last 3 Accomplishments

1. **tristate-hvac.com deployed and smoke-tested PASS (2026-08-14)** --
   second live v3.14.1 deployment (after trevoraspiranti.com on
   2026-08-13). No plugin-side issues found.

2. **Old release zips cleaned up (2026-08-14, commit 8526e5e)** -- 17
   stale zips (v3.3.0 through v3.14.0) deleted from `releases/`; only the
   current v3.14.1 zip remains tracked. Recoverable from git history if
   ever needed.

3. **New sibling project spun up: `rankrocket-mcp` (2026-08-14)** --
   investigated whether an MCP (Model Context Protocol) server wrapping
   this plugin's REST API would add value, given the (incorrect)
   assumption that `workflow-portal` already drove the plugin
   programmatically. Confirmed `workflow-portal` has **zero** programmatic
   integration with this plugin today -- credentials are hand-pasted into
   an external Perplexity.ai prompt session. Built and shipped a Phase 1
   MCP server (read-only tools: status, content-audit/observation
   endpoints, action dry-run) as a new standalone repo at
   `E:\projects\rankrocket-mcp` (pushed to
   `github.com/PenZenMaster/rankrocket-mcp`, private), with its own ported
   `.claude/CLAUDE.md` playbook (`RRMCP start/checkpoint/shutdown`) so both
   repos share a consistent workflow. **This repo (`rank_rocket_seo_plugin`)
   was not modified** -- the MCP server is a pure external REST client;
   the "lean executor" architecture boundary in
   `docs/plugin-v3-executor-spec.md` was explicitly preserved by design.

---

## Next 3 Priorities

1. **#18** -- `/capabilities` `since: null` backfill (low impact,
   optional; use `git log -S` archaeology, not the issue's own
   confirmed-wrong version-guess table).
2. **#19** -- `entity_clarity` README docs gap (low impact, docs-only).
3. **[P3] RankMath Reference Purge is NOT fully scoped -- needs a
   go/no-go check before it can be picked up.** (`docs/projectStatus.md:1013-1019`)
   The item's own stated prerequisite -- "confirm no active clients rely
   on the `rank_math_*` read-path fallback" -- is unmet: as of the last
   check (2026-08-06), Higgins Overhead Door's `/status` still reported
   `rankmath_active: true`, and Higgins hasn't been touched since. Before
   this item can be scoped for real, someone needs to re-check
   `rankmath_active` across all 4 live sites (tristate-hvac,
   trevoraspiranti, Higgins, Kilday Baxter) and resolve the open
   "optionally rename internal identifiers" scope question.

---

## Current State

**Git:**
- Branch `main`, in sync with `origin/main` at `8526e5e`.
- Working tree clean.

**Open GitHub issues (2):**
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)

**Live deployment status:**
- `trevoraspiranti.com` -- v3.14.1 confirmed live (2026-08-13)
- `tristate-hvac.com` -- v3.14.1 deployed, smoke-tested PASS (2026-08-14)
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched this session

**Files of note:**
- No plugin source files changed this session -- only `releases/` cleanup
  and doc/checkpoint updates.
- Sibling repo `E:\projects\rankrocket-mcp` now exists as an external
  REST client of this plugin's API -- see that repo's
  `docs/investigation-mcp-rationale.md` for the full investigation this
  session ran into this plugin's REST surface (route inventory, auth
  model, typed action engine, architecture-boundary quotes) if that detail
  is ever needed again from this side.

**Blockers:**
- None.

---

## Key Context Notes

1. **Elementor hooks `the_content` at priority 9 and replaces `$content`
   wholesale; it only strips 3 hardcoded WP core filters afterward
   (`wpautop`, `shortcode_unautop`, `wptexturize`), never third-party
   plugin filters.** Any future content-injection feature on this plugin
   should hook `the_content` at a priority greater than 9.

2. **`wp_safe_redirect()` has its own separate host allowlist
   (`allowed_redirect_hosts` core filter) from any allowlist a plugin
   defines itself.** Bridge the two via
   `rrseo_redirect_extend_allowed_hosts()`.

3. **`rmb_schema_set()` (`POST /schema/{post_id}`) has no merge -- it
   always replaces the whole stored graph wholesale.** Reuse
   `rr_schema_graph_nodes()`/`rr_schema_merge_node()`/
   `rr_schema_remove_node_type()` in `class-rrseo-faq.php` for any future
   feature needing to add/update a single node type without disturbing
   others.

4. **Verify backlog items against actual code (or, per this session, live
   telemetry) before either building more or marking them done -- don't
   just trust the phrasing.** The P3 RankMath Reference Purge item above
   is the latest example: it reads like a ready ticket but has an unmet
   go/no-go prerequisite baked into its own text.

5. **This plugin's full REST API surface (51 routes) is now documented
   externally** in `rankrocket-mcp`'s
   `docs/investigation-mcp-rationale.md`, verified against source as of
   2026-08-14 -- useful as a secondary reference if this repo's own
   `/capabilities` map or docs ever drift, though that external doc is a
   point-in-time snapshot, not a live source of truth.

6. **WP Engine force-rewrites `Cache-Control` on authenticated requests at
   the edge, unconditionally** -- confirmed via WP Engine's own
   `X-Cacheable`/`X-Pass-Why` response headers on higginsoverheaddoor.com.
   Platform policy, not a plugin bug.

7. **`/perf/dequeue-rules` handles are the actual WP dependency handle,
   not the rendered `id` attribute** -- strip the `-css` suffix from
   visible `id` attributes first.

8. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule/redirect write** -- `POST /cache/purge` only
   clears WordPress's internal object cache. A `?cb=<random>` query
   string forces a fresh, uncached fetch for verification.

9. **Full git history goes back to `v1.2.0`, past CHANGELOG.md's tracked
   floor of v2.11.3** -- relevant for issue #18. `git log -S"route
   string" -- rankmath-rest-bridge.php` accurately dates when a given
   route was introduced.

10. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
    (lowercase); `git add` with uppercase path silently stages nothing.
