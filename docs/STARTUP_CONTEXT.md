# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-09-02
**Branch:** main
**Version:** 3.14.1 (shipped, zip on CDN; live on trevoraspiranti.com,
tristate-hvac.com, **and** endlessenergyfitness.com per its own `/status`)
**Last Commit:** 58ec0b8 -- chore(checkpoint): 2026-08-14_0940

---

## Last 3 Accomplishments

1. **`endlessenergyfitness.com` onboarded to the RankRocket MCP site
   registry (2026-09-02)** -- registered on the remote MCP host
   (`mcp.fullmetaljacket.com`, not this repo or local machine). Fixed a
   JSON syntax error (missing closing brace) that had taken the whole MCP
   server down mid-session. Verified live via `rankrocket_status`:
   plugin v3.14.1, RankMath active, Elementor + Elementor Pro active.

2. **Domain-level 301 (non-www -> www) set up and verified for
   endlessenergyfitness.com (2026-09-02)** -- confirmed via source read
   that this plugin's own redirect engine (`class-rrseo-redirects.php`)
   is path-only and architecturally cannot do domain-level redirects;
   routed the user to cPanel's Domain Redirects tool instead. Live
   `curl`-verified afterward: every real URL redirects in a single 301
   hop with full path preserved, no loops. Minor non-blocking finding:
   cPanel's rule currently only fires raw for the domain root/static
   files -- WordPress's own `redirect_canonical()` is covering everything
   else correctly in the meantime (see checkpoint for detail).

3. **tristate-hvac.com deployed and smoke-tested PASS (2026-08-14)** --
   second live v3.14.1 deployment (after trevoraspiranti.com on
   2026-08-13). No plugin-side issues found.

---

## Next 3 Priorities

1. **#18** -- `/capabilities` `since: null` backfill (low impact,
   optional; use `git log -S` archaeology, not the issue's own
   confirmed-wrong version-guess table).
2. **#19** -- `entity_clarity` README docs gap (low impact, docs-only).
3. **[P3] RankMath Reference Purge is NOT fully scoped -- needs a
   go/no-go check before it can be picked up.** (`docs/projectStatus.md`)
   The item's own stated prerequisite -- "confirm no active clients rely
   on the `rank_math_*` read-path fallback" -- is unmet: as of the last
   check (2026-08-06), Higgins Overhead Door's `/status` still reported
   `rankmath_active: true`, and Higgins hasn't been touched since. Before
   this item can be scoped for real, someone needs to re-check
   `rankmath_active` across all 4 original live sites (tristate-hvac,
   trevoraspiranti, Higgins, Kilday Baxter) and resolve the open
   "optionally rename internal identifiers" scope question. (Note:
   endlessenergyfitness.com is a 5th site, onboarded 2026-09-02, not part
   of this original prerequisite check.)

*(Optional, low priority)* Harden `endlessenergyfitness.com`'s cPanel
domain-redirect: the `.htaccess` rule cPanel added currently sits after
the `# BEGIN WordPress` block, so it only fires raw for the domain root
and static files rather than all paths. WordPress's own canonical
redirect is covering the gap correctly today, so this is defense-in-depth
only, not a live bug.

---

## Current State

**Git:**
- Branch `main`, in sync with `origin/main` at `58ec0b8`.
- Working tree clean.

**Open GitHub issues (2):**
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)

**Live deployment status:**
- `trevoraspiranti.com` -- v3.14.1 confirmed live (2026-08-13)
- `tristate-hvac.com` -- v3.14.1 deployed, smoke-tested PASS (2026-08-14)
- `endlessenergyfitness.com` -- v3.14.1 confirmed live via `/status`
  (2026-09-02); newly onboarded to the MCP registry this session; domain
  redirect (non-www -> www) verified working
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched this session

**Files of note:**
- No plugin source files changed this session -- only docs/checkpoint
  updates. All actual work happened on the remote MCP host
  (`mcp.fullmetaljacket.com`, site registry) and on
  `endlessenergyfitness.com`'s cPanel hosting (domain redirect).
- The RankRocket MCP server itself runs on `mcp.fullmetaljacket.com`, a
  separate host from this Windows machine and from the
  `E:\projects\rankrocket-mcp` source repo -- a local
  `C:\Users\georg\.rankrocket-mcp\sites.json` edit made early in this
  session was a dead end (inert; wrong file) once that was discovered.

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
