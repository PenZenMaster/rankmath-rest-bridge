# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-10-07
**Branch:** main
**Version:** 3.22.0 (Local SEO Stage 2; deployed to rankrocket.co only)
**Last Commit:** 3d68a7a -- chore: release v3.22.0 zip
(checkpoint commit follows)

---

## Last 3 Accomplishments

1. **Local SEO Stage 2 shipped: v3.22.0 (#39, 2026-10-07)** -- public-page scan
   (`GET /local-seo/preview?inspect_public=1`), enable gate (422
   `duplicate_schema_conflict` unless `acknowledge_duplicates`; warns
   "unverified" if a page cannot be fetched), passive `schema_emitters` list in
   `/status`, Local SEO feeds llms.txt business facts + `source: local_seo` in
   the entity audit + a `local_seo` schema-audit source, read-only
   `GET /local-seo/import-preview`, shared snippet matcher
   (`rr_snippet_applies_to_post`). 625 tests, phpcs clean. **Verified live on
   rankrocket.co:** scan found HFCM's `#organization` as `same_id`; enabling
   was refused with 422 (dry run too); import-preview returned 0 candidates
   (expected: only Service snippets there).

2. **Local SEO Stage 1 + fixes: v3.21.0 / v3.21.1 (#39)** -- `rr_local_seo`
   option, strict validating REST CRUD, opt-in emission, action-log audit;
   3.21.1 fixed LiteSpeed page-caching of the GETs (no-store headers, wider
   purge). Verified live on rankrocket.co (Local SEO stored, `enabled: false`).

3. **MCP tools for Local SEO shipped** -- `rankrocket-mcp` v0.15.0
   (`rankrocket_local_seo`, `rankrocket_local_seo_write`, REST client now
   surfaces `data.conflicts`) and v0.15.1 (serverInfo version fix, done by a
   separate session). Per the MCP repo's own docs, 0.15.1 is deployed to cPanel
   and the live server exposes 25 tools. The "MCP site enums" item turned out
   to be a stale tool schema cached by the client, not a code bug -- reconnect
   the client to refresh. Earlier today: #30/#37/#38 verified and closed, Rank
   Math gap report + #39-#43 filed, plugin reinstalled on two sites.

---

## Next 3 Priorities

1. **Exercise Local SEO through the MCP on a real site** (reconnect the client
   first so it sees the new tools): `rankrocket_local_seo` `preview` with
   `inspect_public: true` on rankrocket.co (expect the HFCM `#organization`
   conflict), then `preview_set` with `enabled: true` (expect
   `duplicate_schema_conflict` naming the node).
2. **Olson Recycling is the real migration test** (3 LocalBusiness snippets):
   deploy v3.22.0 there (currently 3.20.2), run `import-preview`, write the
   entity + 3 locations with `enabled: false`, check `preview?inspect_public=1`
   for conflicts, retire the old snippets, then enable. That is the only live
   proof of the positive path (clean enable, emission, import mapping). After
   it, decide whether to close #39.
3. **Rest of the Rank Math backlog:** #40 auto schema (reuse the scan, gate and
   `schema_emitters`; opt-in, default off), #41, #42, #43. Owner decision
   pending: retire HFCM "Home Schema" on rankrocket.co before enabling Local SEO
   there. Optional: #18, #19, `reversible` mismatch on `set_post_status`,
   blog-style home (no `page_on_front`) cannot be public-scanned.

## Current State

**Git:**
- Branch `main`, `3d68a7a` pushed; checkpoint commit follows.
- `rankrocket-mcp` (`master`): v0.15.1 (`c3b0723` at last look); Local SEO tools deployed per that repo's docs. Another session had uncommitted doc edits there at shutdown (`docs/STARTUP_CONTEXT.md`, `CheckPoint-2026-10-07_1752.md`); not touched from this session.

**Open GitHub issues (7):**
- **#39** -- Stages 1 and 2 shipped (v3.21.0, 3.21.1, 3.22.0) and MCP tools shipped; open only for the Olson positive-path verification (priority 2)
- **#40-#43 (RMR-02..05)** -- Rank Math replacement gaps, filed 2026-10-07 from
  `docs/rank-math-replacement-gap-report.md`: #39 Local SEO settings, #40
  automatic baseline schema + BreadcrumbList, #41 image title write + auto
  ALT/title, #42 taxonomy/product/image sitemaps, #43 410/451 redirects + 404
  log. Open design questions are listed in the report.
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)
- Closed this stretch: #28, #29, #31, #30, #37, #38 (all verified live). AUD-01..AUD-05 live in
  workflow-portal #50-#54.

**Live deployment status:**
- `olsonsrecycling.com` -- v3.20.2 (2026-10-07), #38 verified; `/status` clean
- `rankrocket.co` -- v3.22.0 (2026-10-07); Local SEO entity stored, `enabled:
  false`; 4 Service snippets; homepage 2604 has two empty `<h2>` headings and
  its Organization/WebSite/WebPage graph is printed by HFCM, not this plugin
- `tristate-hvac.com` -- v3.20.1 (2026-10-01), `/status` clean
- `trevoraspiranti.com`, `endlessenergyfitness.com` -- plugin reinstalled,
  v3.20.2, `/status` clean (2026-10-07); Rank Math active on both
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched recently

**Files of note:**
- The release hook commits the zip after the push starts, so confirm with
  `git status -sb` and push again if ahead. Only the `releases/v3.20.0/` and
  `v3.20.1/` zips exist for the v3.16.1-v3.20.1 batch; v3.20.2, v3.21.0 and
  v3.21.1 and v3.22.0 zips also exist.

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

13. **Observation endpoints now declare their evidence** -- heading
    observation has `scope`/`source`/`complete` (`source=auto` fetches the
    post's own permalink via `wp_safe_remote_get`, which blocks private IPs),
    link observation never reports a measured status (the plugin makes no
    external HTTP), and schema audit reports per-source evidence with
    `summary.complete`. Treat "none found" as "none in the inspected sources"
    unless `complete` is true.

14. **MCP registry and deploy mechanics** -- the registry the MCP uses is
    `/home/fullmetaljacket/persistent/rankrocket-mcp/sites.json` on the remote
    host (a local `~/.rankrocket-mcp/sites.json` also exists and is NOT what
    the connected server reads). A change needs the MCP server restarted
    before it takes effect. The MCP has no `/self-update` or
    `/check-updates` tool: updating a site is done by the user via REST
    (PowerShell: `curl.exe -X POST ... -u "${WPU}:${WPP}"`, use `${}` around
    variable names before a colon) or WP Admin > Plugins. Never read
    `sites.json` for credentials.

15. **Live write tests are done on empty-SEO low-traffic posts** (write
    `focus_keyword` only -- not rendered publicly -- then revert with
    `unset_fields` and confirm). Used 2026-10-01 on rankrocket.co post 3768
    and tristate-hvac.com post 121.

16. **Local SEO emission is opt-in and its dedupe is blind to other plugins** --
    `rr_local_seo_plan()` compares against the post's `_rrseo_schema_graph`
    and this plugin's snippets only. HFCM, WPCode and theme output are
    invisible at runtime by design; protection is the Stage 2 preview scan and
    enable gate. Never enable on a site whose homepage already prints an
    Organization until the other source is retired.

17. **LiteSpeed page-caches REST GETs on rankrocket.co** -- a stale response
    looks like a logic bug. Verify with a `?cb=<random>` query string first.
    Local SEO GETs now send no-cache headers (v3.21.1); older endpoints may not.

18. **Editing repo files from Python on Windows writes CRLF** -- phpcs rejects
    it (`Generic.Files.LineEndings`). Open with `newline=''` for read and write,
    or convert with `sed -i 's/\r$//'`. Git's index stays LF either way.

19. **MCP clients cache the tool list per session** -- after the MCP server is
    restarted with a new `sites.json` or new tools, an already-connected client
    keeps the old tool schemas (and old site enum) until it reconnects.
    `rankrocket_sites` and the server itself reflect the live state.

20. **Public scan limits** -- `inspect_public` / the enable gate use
    `rr_observe_fetch_frontend_html()` (`wp_safe_remote_get`, same host only,
    unauthenticated). It cannot scan a blog-style home with no `page_on_front`
    post, loopback-blocked hosts, or pages behind a login; those come back
    "unverified" and the write proceeds with a warning.
