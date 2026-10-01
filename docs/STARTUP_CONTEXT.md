# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-09-30
**Branch:** main
**Version:** 3.20.0 (pushed, zip on CDN; live sites NOT updated -- still on
3.14.1 / 3.8.1, see Current State)
**Last Commit:** e4f4b5e -- chore: release v3.20.0 zip
(checkpoint commit follows)

---

## Last 3 Accomplishments

1. **Five issues fixed and pushed, v3.16.1 -> v3.20.0 (2026-09-30)** --
   #28 (purge cached `GET /get/{id}` on SEO meta writes), #31 (local lookup
   misses are unverified, not 404s), #29 (source-aware heading observation),
   #30 (graph-aware schema audit with snippet + public evidence), #37
   (`set_post_status` typed action). Tests 442 -> 528. Code/docs/tests done;
   NOT verified on any live site. See `CheckPoint-2026-09-30_2339.md`.

2. **Issue scan + AUD triage (2026-09-30)** -- moved AUD-01..AUD-05
   (#32-#36, seo-site-audit consumer issues) to `workflow-portal` #50-#54
   and closed the originals as not planned. See
   `CheckPoint-2026-09-30_1750.md`.

3. **Doc backfill for v3.15.0/v3.16.0 + issue #28 surfaced (2026-09-11)**
   -- see `CheckPoint-2026-09-11_1052.md`.

---

## Next 3 Priorities

1. **Deploy + verify 3.20.0** -- `POST /self-update` on tristate-hvac.com
   first (wait 2-3 min for CDN). Verify #28 read-after-write (the #28 fix is
   hypothesis-based: LiteSpeed REST caching; only LiteSpeed per-URL purge, not
   Varnish), then #29 (`source=document` on homepage 2604), #30
   (`inspect_public`), #31 (`/contact` not a verified 404), #37 on a staging
   fixture page (never production page 3646).
2. **Close #28, #29, #30, #31, #37** after live verification; comment on
   #29-#31 that the consumer-side AUD-01 is now workflow-portal#50.
   Downstream consumers (workflow-portal seo-site-audit, rankrocket-mcp)
   must adapt to: `status_code` null for local misses, `no_h1_in_fragment`,
   broader schema coverage.
3. **#18 / #19** (low impact: `/capabilities` `since: null` backfill via
   `git log -S`; `entity_clarity` README docs), then the P3 re-check below.

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
- Branch `main`, `e4f4b5e` pushed, in sync with `origin/main`; checkpoint docs pending commit.

**Open GitHub issues (7):**
- **#28, #29, #30, #31, #37** -- fixed in v3.16.1/v3.17.0/v3.18.0/v3.19.0/
  v3.20.0, pushed, awaiting live verification then close.
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#19** -- `entity_clarity` README docs gap (low impact, optional)
- AUD-01..AUD-05 live in workflow-portal #50-#54.

**Live deployment status:**
- `trevoraspiranti.com` -- v3.14.1 confirmed live (2026-08-13); not yet
  updated to v3.15.0-v3.20.0
- `tristate-hvac.com` -- v3.14.1 deployed, smoke-tested PASS (2026-08-14);
  also the site #28 was reproduced against; not yet updated to
  v3.15.0-v3.20.0
- `endlessenergyfitness.com` -- v3.14.1 confirmed live via `/status`
  (2026-09-02); domain redirect (non-www -> www) verified working
- Kilday Baxter (kildaybaxter.com), Higgins (higginsoverheaddoor.com) --
  still on v3.8.1 as of last check (2026-08-06); not touched recently

**Files of note:**
- Plugin source changed this session: `rankmath-rest-bridge.php`,
  `includes/class-rrseo-{observe,aeo-geo,llms,actions}.php`. Only the
  `releases/v3.20.0/` zip exists for this batch (v3.16.1-v3.19.0 have none).
- The release hook commits the zip after the push starts, so a second
  `git push` is needed to publish the zip commit.

**Blockers:**
- None. Live verification of the five fixes is the gate before closing.

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
