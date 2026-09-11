# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-09-11
**Branch:** main
**Version:** 3.16.0 (shipped, zip on CDN; live sites still on 3.14.1 as of
last check -- see Current State)
**Last Commit:** 48bfba0 -- chore(checkpoint): 2026-09-11_1052

---

## Last 3 Accomplishments

1. **Doc backfill for v3.15.0/v3.16.0 + issue #28 surfaced (2026-09-11)**
   -- `RRSEO start` caught that this file, `projectStatus.md`, and the
   checkpoint archive had all drifted two releases behind actual repo
   state. Backfilled both releases and discovered issue #28 (stale-cache
   bug, filed 2026-08-15) had never been tracked in this repo's docs at
   all. See `CheckPoint-2026-09-11_1052.md`.

2. **v3.16.0 SHIPPED** -- new `GET /elementor/{post_id}` read endpoint;
   closes the gap where nothing could read back a post's existing
   Elementor layout (only the write-side `dry_run` existed before). Built
   for workflow-portal's Location Page Builder workflow. 442 tests total.

3. **v3.15.0 SHIPPED** -- new `create_page` typed action (draft-only,
   hard-clamped `status`; rollback trashes rather than hard-deletes) via
   the existing `/actions/*` engine, no new REST route needed.

---

## Next 3 Priorities

1. **#28 (NEW, top priority)** -- `GET /get/{id}` returns stale SEO meta
   immediately after a `POST /update` write, live on `tristate-hvac.com`.
   Root cause already well-narrowed by the reporter: the site runs
   LiteSpeed + Varnish in front of WP, and `rmb_update_meta()` only busts
   WordPress's own object cache -- never the edge-cache purge logic
   `rmb_cache_purge()` (`POST /cache/purge`) already has. Likely fix:
   have write handlers call that same purge routine (or purge just the
   affected URL) on every write. Not yet scoped as a version bump.
2. **#18** -- `/capabilities` `since: null` backfill (low impact,
   optional; use `git log -S` archaeology, not the issue's own
   confirmed-wrong version-guess table).
3. **#19** -- `entity_clarity` README docs gap (low impact, docs-only).

**[P3, still deferred] RankMath Reference Purge is NOT fully scoped** --
go/no-go prerequisite ("confirm no active clients rely on the
`rank_math_*` read-path fallback") unmet as of last check (2026-08-06,
Higgins still `rankmath_active: true`, untouched since). Needs a re-check
across all 4 original live sites (tristate-hvac, trevoraspiranti, Higgins,
Kilday Baxter) before it can be picked up.

*(Optional, low priority, carried over)* Harden
`endlessenergyfitness.com`'s cPanel domain-redirect ordering -- WordPress's
own canonical redirect covers the gap correctly today, so this is
defense-in-depth only, not a live bug.

---

## Current State

**Git:**
- Branch `main`, in sync with `origin/main` at `48bfba0`.
- Working tree clean.

**Open GitHub issues (3):**
- **#28** -- `GET /get/{id}` stale-cache bug on write (see Next 3
  Priorities above) -- newly surfaced into this repo's docs, not new on
  GitHub (filed 2026-08-15)
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)

**Live deployment status:**
- `trevoraspiranti.com` -- v3.14.1 confirmed live (2026-08-13); not yet
  updated to v3.15.0/v3.16.0
- `tristate-hvac.com` -- v3.14.1 deployed, smoke-tested PASS (2026-08-14);
  also the site #28 was reproduced against; not yet updated to
  v3.15.0/v3.16.0
- `endlessenergyfitness.com` -- v3.14.1 confirmed live via `/status`
  (2026-09-02); domain redirect (non-www -> www) verified working
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched recently

**Files of note:**
- No plugin source files changed this session -- docs-only backfill
  (this file, `projectStatus.md`, new checkpoint). Plugin source itself
  last changed in `5cc3816`/`f4293e5` (v3.15.0/v3.16.0), prior session.

**Blockers:**
- None for docs work. #28 blocks confident read-after-write verification
  on LiteSpeed/Varnish-fronted sites until fixed.

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

4. **Verify backlog items against actual code (or live telemetry) before
   either building more or marking them done -- don't just trust the
   phrasing.** The P3 RankMath Reference Purge item is the standing
   example: it reads like a ready ticket but has an unmet go/no-go
   prerequisite baked into its own text.

5. **This plugin's full REST API surface (51 routes) is documented
   externally** in `rankrocket-mcp`'s
   `docs/investigation-mcp-rationale.md`, verified against source as of
   2026-08-14 -- useful as a secondary reference if this repo's own
   `/capabilities` map or docs ever drift, though that external doc is a
   point-in-time snapshot, not a live source of truth.

6. **WP Engine force-rewrites `Cache-Control` on authenticated requests at
   the edge, unconditionally** -- confirmed via WP Engine's own
   `X-Cacheable`/`X-Pass-Why` response headers on higginsoverheaddoor.com.
   Platform policy, not a plugin bug. (`endlessenergyfitness.com` is on a
   different stack -- LiteSpeed/cPanel, with LSCache -- not WP Engine.)

7. **`/perf/dequeue-rules` handles are the actual WP dependency handle,
   not the rendered `id` attribute** -- strip the `-css` suffix from
   visible `id` attributes first.

8. **This plugin's own redirect engine (`class-rrseo-redirects.php`) is
   path-only by design** -- `rr_redirect_normalize_path()` strips
   scheme/host via `wp_parse_url(..., PHP_URL_PATH)` before matching, so
   it can never distinguish or act on which domain a request came in on.
   Domain-level (e.g. non-www -> www) redirects must be done at the
   DNS/hosting layer (cPanel Domain Redirects, WP Engine's redirect
   panel, Cloudflare, etc.), never through this plugin's `/redirects`
   API. Confirmed by source read 2026-09-02.

9. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule/redirect write** -- `POST /cache/purge` only
   clears WordPress's internal object cache. A `?cb=<random>` query
   string forces a fresh, uncached fetch for verification. (For
   LiteSpeed/LSCache stacks like endlessenergyfitness.com, the same
   `?cb=<random>` cache-busting trick works for manual `curl`
   verification, even though the purge mechanism itself differs.)

10. **Full git history goes back to `v1.2.0`, past CHANGELOG.md's tracked
    floor of v2.11.3** -- relevant for issue #18. `git log -S"route
    string" -- rankmath-rest-bridge.php` accurately dates when a given
    route was introduced.

11. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
    (lowercase); `git add` with uppercase path silently stages nothing.

12. **The RankRocket MCP server's site registry (`sites.json`) is
    server-side state, resolved at server startup, not per-call** --
    editing the file doesn't take effect until the MCP server process
    itself restarts and re-reads it. It runs on a separate remote host
    (`mcp.fullmetaljacket.com`), not this local machine -- confirm which
    host you're actually editing before assuming a change took effect. A
    malformed registry file (e.g. a JSON syntax error) takes down every
    site's tools, not just the one being added/edited.
