# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-10-01
**Branch:** main
**Version:** 3.20.1 (live on rankrocket.co and tristate-hvac.com; other sites
not updated -- see Current State)
**Last Commit:** c65c0de -- chore: release v3.20.1 zip
(shutdown checkpoint commit follows)

---

## Last 3 Accomplishments

1. **Deployed + verified live, v3.20.1 (2026-10-01)** -- rankrocket.co
   (3.16.0 -> 3.20.1) and tristate-hvac.com (3.14.1 -> 3.20.1) updated via
   `POST /self-update`. Verified #29 (homepage 2604: one H1, no `no_h1`),
   #31 (`/contact` unverified, not a 404) and #28 (write visible to the next
   `GET /get/{id}` on tristate, the original repro site, and on rankrocket).
   Closed #28, #29, #31. See `CheckPoint-2026-10-01_0035.md`.

2. **v3.20.1 nbsp fix (2026-10-01)** -- verification found `<h2>&nbsp;</h2>`
   headings were not flagged `empty_heading` (PHP `trim()`/`\s` ignore
   U+00A0). New `rr_observe_normalize_text()`; 536 tests; verified live.

3. **Five issues fixed and pushed, v3.16.1 -> v3.20.0 (2026-09-30)** -- #28,
   #31, #29, #30, #37. See `CheckPoint-2026-09-30_2339.md`.

---

## Next 3 Priorities

1. **Verify #30 live, then close it** -- `GET /aeo-geo/schema-audit?inspect_public=1&public_limit=10`
   on rankrocket.co (4 Service snippets, homepage graph): expect
   `public_schema: inspected`, snippet + graph types, `summary.complete`.
   The MCP has no schema-audit tool; call REST directly (PowerShell,
   `curl.exe`, credential prompt).
2. **#37** -- add `create_page` and `set_post_status` to the
   `rankrocket_action_execute` enum in the `rankrocket-mcp` repo (currently
   only update_setting, regenerate_llms_txt, update_meta_draft,
   toggle_indexing, redirect actions), then test `set_post_status` on a
   staging fixture page (never production page 3646), then close #37.
3. **Diagnose `trevoraspiranti` / `endlessenergyfitness`** -- both returned
   "No route was found" on `/status` via the MCP (2026-09-30), contradicting
   notes that they were on 3.14.1; not rechecked after the registry restart.
   Then update them to 3.20.1, then #18/#19 and the P3 re-check below.

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
- Branch `main`, `c65c0de` pushed; shutdown checkpoint commit follows.

**Open GitHub issues (4):**
- **#30** -- fixed in v3.19.0, awaiting live verification (see priority 1)
- **#37** -- fixed in v3.20.0, blocked on the MCP action enum (priority 2)
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)
- Closed this stretch: #28, #29, #31. AUD-01..AUD-05 live in
  workflow-portal #50-#54.

**Live deployment status:**
- `rankrocket.co` -- v3.20.1 (2026-10-01); 4 Service snippets; homepage 2604
  has two empty `<h2>` headings (site content, untouched)
- `tristate-hvac.com` -- v3.20.1 (2026-10-01), `/status` clean
- `trevoraspiranti.com`, `endlessenergyfitness.com` -- last known 3.14.1, but
  MCP `/status` returned "No route was found" (2026-09-30); unresolved
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched recently

**Files of note:**
- The release hook commits the zip after the push starts, so confirm with
  `git status -sb` and push again if ahead. Only the `releases/v3.20.0/` and
  `v3.20.1/` zips exist for the v3.16.1-v3.20.1 batch.

**Blockers:**
- None for code. #30 needs a direct REST call (no MCP tool); #37 needs an MCP
  repo change.

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
