# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-10-07
**Branch:** main
**Version:** 3.20.2 (deployed to olsonsrecycling.com; other sites per Current State)
**Last Commit:** dd28043 -- docs: record #30 verified live on rankrocket.co
(checkpoint commit follows)

---

## Last 3 Accomplishments

1. **#30 verified live (2026-10-07)** -- rankrocket.co schema-audit with
   `inspect_public=1&public_limit=10`: HTTP 200, 76 URLs, 10 inspected / 66
   `not_inspected`, `sources_inspected` = native/snippets/public,
   `complete: false` (honest -- only 10 of 76 fetched), 0 invalid JSON-LD, 0
   duplicate ids, global warnings `no_faqpage_anywhere` /
   `no_breadcrumblist_anywhere`. Site finding: 71/76 URLs without schema
   (coverage 6.6%).

2. **#37 verified live (2026-10-07)** -- MCP v0.14.0 deployed; `set_post_status`
   round trip on fixture draft rankrocket.co post 3882 (draft -> publish: 200,
   in pages sitemap + llms.txt; publish -> draft: 404, removed from both).
   Payload: `{expected_status, new_value}`. Dry-run says `reversible: false`,
   execute says `true` (cosmetic). Fixture 3882 kept as a draft for reuse.

3. **#38 verified live (2026-10-07)** -- v3.20.2 on olsonsrecycling.com
   (post 206): real edit `changed: true` / `post_modified_updated: true`,
   identical re-submit `changed: false`, sitemap `lastmod` moved to now.
   Test edit reverted. olsonsrecycling.com added to the MCP registry as
   `olson-recycling`.

---

## Next 3 Priorities

1. **Start the Rank Math replacement backlog** (#39-#43; order #39, #40, #41,
   #42, #43). Design decisions are settled in the gap report section 5: #39
   uses a dedicated `rr_local_seo` options object, #40 is opt-in default off,
   #43 is an opt-in aggregated 404 log.
2. **Fix MCP tool schema site enums** to include `olson-recycling` (runtime
   works; schemas list only the original four sites). Optional: align
   `reversible` between dry-run and execute for `set_post_status`.
3. **Optional backlog:** #18 (`since: null` backfill), #19 (`entity_clarity`
   README gap); an MCP tool for `GET /aeo-geo/schema-audit`.

## Current State

**Git:**
- Branch `main`, `dd28043` pushed; checkpoint commit follows.
- `rankrocket-mcp` (`master`): `e7016bd` (v0.14.0, `set_post_status` enum); deployed to the remote host and verified.

**Open GitHub issues (7):**
- **#39-#43 (RMR-01..05)** -- Rank Math replacement gaps, filed 2026-10-07 from
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
- `rankrocket.co` -- v3.20.1 (2026-10-01); 4 Service snippets; homepage 2604
  has two empty `<h2>` headings (site content, untouched)
- `tristate-hvac.com` -- v3.20.1 (2026-10-01), `/status` clean
- `trevoraspiranti.com`, `endlessenergyfitness.com` -- plugin reinstalled,
  v3.20.2, `/status` clean (2026-10-07); Rank Math active on both
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched recently

**Files of note:**
- The release hook commits the zip after the push starts, so confirm with
  `git status -sb` and push again if ahead. Only the `releases/v3.20.0/` and
  `v3.20.1/` zips exist for the v3.16.1-v3.20.1 batch.

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
