# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-11-19
**Branch:** main
**Version:** 3.22.1 (Elementor 4 atomic fix #44; not yet deployed anywhere)
**Last Commit:** 1ec57d2 -- chore: release v3.22.1 zip
(checkpoint commit follows)

---

## Last 3 Accomplishments

1. **#44 fixed: v3.22.1 (2026-11-19)** -- `POST /elementor/set-data` rejected
   Elementor 4 atomic pages (top-level `e-flexbox`) with 422, even for an
   unmodified GET round trip (found on stuarthale.com, Elementor 4.4.1).
   Allowlist now includes `e-flexbox` / `e-div-block`, new
   `rrseo_elementor_top_level_types` filter, `e-` atomic widgets skip the
   unknown-widget warning. 4 new tests with an atomic page fixture; 629 tests,
   phpcs clean. Commits 4b90a3d + zip 1ec57d2, pushed.

2. **#39 closed (2026-11-19)** -- Olson Recycling Local SEO migration done by
   the user (positive path verified live). Local SEO Stage 1 + 2 (v3.21.x,
   v3.22.0) and the MCP tools are all shipped.

3. **stuarthale.com onboarded (2026-11-19)** -- white-labeled "AMS SEO"
   (Adventure Marketing Solutions); install confirmed complete by the user.

---

## Next 3 Priorities

1. **Verify #44 live** -- `POST /self-update` on stuarthale.com (wait 2-3 min
   for the GitHub CDN), then `GET /elementor/236` and send it unchanged to
   `POST /elementor/set-data` with `dry_run: true`. Expect a diff, not 422.
   If it passes, close #44.
2. **Rank Math backlog:** #40 auto schema + BreadcrumbList (reuse the scan,
   gate and `schema_emitters`; opt-in, default off), then #41, #42, #43.
   Optional: #18, #19, `reversible` mismatch on `set_post_status`, blog-style
   home (no `page_on_front`) cannot be public-scanned.
3. **Roll out v3.22.1** to Olson, tristate-hvac, trevoraspiranti,
   endlessenergyfitness. Kilday Baxter and Higgins are still on 3.8.1.
   Owner decision pending: retire HFCM "Home Schema" on rankrocket.co before
   enabling Local SEO there.

## Current State

**Git:**
- Branch `main`, `1ec57d2` pushed; 2026-11-19_1649 checkpoint commit follows.
- `rankrocket-mcp` (`master`): v0.15.1 (`c3b0723` at last look); Local SEO tools deployed per that repo's docs. Another session had uncommitted doc edits there at shutdown (`docs/STARTUP_CONTEXT.md`, `CheckPoint-2026-10-07_1752.md`); not touched from this session.

**Open GitHub issues (7):**
- **#44** -- Elementor 4 atomic containers rejected by `set-data`; fixed in v3.22.1, open only for live verification on stuarthale.com (priority 1)
- **#40-#43 (RMR-02..05)** -- Rank Math replacement gaps, filed 2026-10-07 from
  `docs/rank-math-replacement-gap-report.md`: #39 Local SEO settings, #40
  automatic baseline schema + BreadcrumbList, #41 image title write + auto
  ALT/title, #42 taxonomy/product/image sitemaps, #43 410/451 redirects + 404
  log. Open design questions are listed in the report.
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)
- Closed this stretch: #39 (2026-11-19), #28, #29, #31, #30, #37, #38 (all verified live). AUD-01..AUD-05 live in
  workflow-portal #50-#54.

**Live deployment status:**
- `olsonsrecycling.com` -- v3.22.0 (2026-11-19); Local SEO migration complete
  and enabled, old LocalBusiness snippets retired (per user)
- `rankrocket.co` -- v3.22.0 (2026-10-07); Local SEO entity stored, `enabled:
  false`; 4 Service snippets; homepage 2604 has two empty `<h2>` headings and
  its Organization/WebSite/WebPage graph is printed by HFCM, not this plugin
- `stuarthale.com` -- v3.22.0 (2026-11-19), white-labeled "AMS SEO"; install
  complete (per user). SiteGround, Elementor 4.4.1 atomic layouts. Needs
  v3.22.1 for `elementor/set-data` (#44).
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

21. **White-label (`RRSEO_WL_*`) is display-only** -- it renames the Plugins
    row and admin menu, but the REST namespace (`rankrocket-seo/v1`) and the
    plugin folder (`rankmath-rest-bridge`) stay unchanged. Constants must be
    defined above `/* That's all, stop editing! */` in wp-config.php.

22. **Elementor 4 atomic shape** -- top-level `elType` is `e-flexbox` /
    `e-div-block`; widgets are `elType: widget` with `widgetType` `e-heading`,
    `e-paragraph`, etc., and settings are typed objects
    (`{"$$type": "string", "value": "h1"}` for `tag`). Only
    `rr_validate_elementor_data()` reads `_elementor_data` structure; heading
    observation works on rendered HTML.

23. **Claude Code's PowerShell tool failed every command on 2026-11-19** with
    "pwsh exited with code 1: The command line is too long" (environment is
    ~4.5 KB, so not PATH). Use the Bash tool (Git Bash) for git, `gh`,
    `dev-vendor/bin/phpunit` and `dev-vendor/bin/phpcs` if it recurs.
