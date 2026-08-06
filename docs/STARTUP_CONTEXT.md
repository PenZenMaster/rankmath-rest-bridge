# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.8.0 (shipped, zip on CDN; confirmed live on kildaybaxter.com and higginsoverheaddoor.com)
**Last Commit:** a67841a -- chore(checkpoint): 2026-08-06_0841 - Programmatic page provisioning milestone shipped and closed

---

## Last 3 Accomplishments

1. **Higgins render-block fix completed and verified live (2026-08-06)**
   -- closed out the perf deployment carried over since 2026-07-10.
   Found the theme was still separately enqueuing its own blocking
   `bootstrap`/`font-awesome` stylesheets alongside the existing
   priority:1 async-swap snippet from a prior session -- the async
   preload had zero effect because the blocking original was still
   there. Added `/perf/dequeue-rules` for both (after discovering the
   handle-name gotcha -- see Key Context Notes), added the matching
   Bootstrap async snippet, fixed the existing FA snippet's missing
   `<noscript>` fallback. Verified via direct HTML inspection (dequeue
   confirmed, both async snippets rendering correctly) after working
   around WP Engine + Cloudflare's aggressive page caching (also see Key
   Context Notes). PageSpeed mobile: Performance 53 -> 60-65 across 3
   post-fix runs, FCP/TBT/SI all consistently improved; LCP stayed noisy
   (9.3-13.6s) and didn't hit the originally-projected 78-85 -- likely a
   separate LCP-specific bottleneck (image load / origin response time
   under throttling), not the CSS block this fix targeted. Real,
   repeatable win; not fully resolved.

2. **"Programmatic page provisioning" milestone closed (2026-08-06)** --
   all 4 issues (#12 elementor set-data, #13 schema graph, #14 media
   upload, #15 capabilities) implemented, shipped v3.5.0-v3.8.0, and
   live-verified on kildaybaxter.com in one combined smoke test. All 4
   issues + the GitHub milestone closed with evidence.

3. **v3.8.0 shipped (2026-08-06, issue #15)** -- `GET /capabilities`:
   plugin_version, wp_version, host state (elementor_active,
   elementor_pro_active, rank_math_active), capabilities map keyed by
   stable dotted identifiers, allowed_schema_types, audit_log_enabled.
   Pure read. 7 new tests (273 -> 280).

---

## Next 3 Priorities

1. **Telemetry verdict review** -- `rrc-telemetry.php` collecting since
   2026-07-06, trustworthy from ~2026-07-13 (now well past). Kill switch:
   `RRC_PUA_DISABLE` in wp-config. Carried over across multiple sessions,
   not yet actioned. Now the top open item.

2. **Higgins LCP still elevated (optional follow-up, not urgent)** --
   render-block fix is done and verified (see accomplishment above), but
   LCP held at 9.3-13.6s across post-fix runs vs. the 78-85 performance
   score originally projected. Worth a closer look at the LCP element
   itself (likely the hero banner image) and/or WP Engine origin
   response time if this becomes a priority again -- not investigated
   further this session per user's call to stop here.

3. **No open GitHub issues or milestones as of 2026-08-06** -- next work
   item is user-directed rather than issue-driven. Worth a fresh look at
   whether any follow-up items noted in past CHANGELOGs deserve filing
   (e.g. the `entity_clarity` scoring README callout noted in the
   2026-07-20 checkpoint, still not filed as an issue).

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `a67841a`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.8.0. Higgins
  (higginsoverheaddoor.com) also confirmed running v3.8.0 as of the
  2026-08-06 perf deployment session (was several releases behind at
  session start; updated itself to latest independently, same pattern
  observed on Kilday Baxter earlier -- user manually forces these).
- Gates: phpcs clean, phpunit 280 tests / 799 assertions

**Files of note:**
- Capabilities map: `rankmath-rest-bridge.php`
  (`rr_get_capabilities_map()`, `rmb_capabilities_get()`)
- Elementor set-data validation: `rankmath-rest-bridge.php`
  (`rr_validate_elementor_data()`, `rr_elementor_walk_tree()`,
  `rr_elementor_clear_css_cache()`, `rmb_elementor_set_data()`)
- Media upload validation: `rankmath-rest-bridge.php`
  (`rr_validate_media_fields()`, `rr_validate_media_file()`,
  `rmb_media_upload()`, `rmb_media_list_placeholders()`)
- Schema graph validation: `rankmath-rest-bridge.php`
  (`rr_validate_schema()`, `rr_validate_schema_graph()`, `rmb_schema_set()`)
- Perf dequeue mechanism: `rankmath-rest-bridge.php`
  (`rrseo_apply_dequeue_rules()` -- hooked `wp_enqueue_scripts:999`)
- Release hook note: run `git push` twice (zip commit lands after refspec)
- GitHub milestone #1 "Programmatic page provisioning" -- closed, 4/4
  issues resolved and live-verified.

**Blockers:**
- None. All open work from this session shipped, tested, and verified
  live. No open issues or milestones remain in the repo.

---

## Key Context Notes

1. **`/perf/dequeue-rules` handles are the actual WP dependency handle, not
   the rendered `id` attribute** -- WordPress prints stylesheet tags as
   `id="{handle}-css"`, so a tag showing `id='bootstrap-css'` has the real
   handle `bootstrap` (not `bootstrap-css`). Dequeuing the wrong string
   silently no-ops (no error, the rule just never matches anything).
   Cost a full extra round-trip on Higgins on 2026-08-06. When building
   dequeue rules from page-source inspection, always strip the `-css`
   suffix from visible `id` attributes first.

2. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule write** -- the plugin's `POST /cache/purge` only
   clears WordPress's internal object cache; its Varnish-purge attempt
   (`localhost:80`) times out on WP Engine, which doesn't run a
   locally-reachable Varnish the way that assumes. Both WP Engine's page
   cache and Cloudflare's edge cache can serve HTML far older than the
   most recent write (observed `Cache-Control: max-age=15552000` -- 180
   days -- with `cf-cache-status: HIT` on Higgins) until purged from
   their own dashboards/APIs. A `?cb=<random>` query string is a reliable
   way to force a fresh, uncached fetch for verification without waiting
   on a real purge. Same applies to kildaybaxter.com if it's on a similar
   stack -- confirm before assuming a write is invisible just because a
   plain fetch doesn't show it.

3. **Elementor `settings: {}` round-trips as `[]`** -- PHP's JSON encoder
   can't distinguish an empty associative array from an empty list, so
   `POST /elementor/set-data` stores/returns `"settings":[]` for empty
   settings objects rather than `{}`. Observed live on kildaybaxter.com
   during the v3.8.0 smoke test; Elementor's own native storage has the
   same quirk, so this likely matches native behavior rather than being
   plugin-specific. Not filed as an issue -- flag if an operator reports
   the Elementor editor choking on a plugin-written element.

4. **`GET /capabilities`'s `Cache-Control` header gets overridden on
   kildaybaxter.com** -- the plugin sets `public, max-age=60`, but the
   live response carries the host's own `no-cache, must-revalidate,
   max-age=0, no-store, private` instead. Confirmed this is the
   host/proxy layer overriding response headers on `/wp-json/` paths
   downstream of PHP, not a plugin defect -- nothing to fix here.

5. **Missing required REST args (e.g. `alt_text` entirely absent) return
   `400 rest_missing_callback_param`, not the endpoint's own 422** --
   WP core's route-arg validation intercepts before the callback runs.
   The plugin's custom 422 validators only fire for present-but-invalid
   values (e.g. `alt_text: ""`). Both paths confirmed correct in the
   v3.8.0 smoke test; worth knowing when writing regression tests or
   README examples so expected status codes match reality.

6. **`business_facts` merge semantics (v3.4.1)** -- sent keys overwrite,
   omitted keys preserved, array fields replace wholesale (not append).
   Sending `business_facts: {}` is now a no-op, not a full clear -- no
   endpoint currently clears the whole object back to schema/bloginfo
   fallback; that would be a separate feature request if ever needed.

7. **`has_business_facts` scoring change is retroactive** -- any site with
   identity-only `business_facts` (name/phone/address, no
   services/area/questions/differentiators) will see `has_business_facts`
   flip false and `llms_completeness`/`overall` drop under v3.4.0+, even
   with no write on their part. Flagged in both CHANGELOGs; not yet
   confirmed whether this affected Kilday Baxter's own live score
   post-deploy.

8. **Docs-only follow-up, not filed as an issue** -- `entity_clarity`
   scoring caps out when manual `business_facts` omits `schema_type`/
   `entity_id`, because manual config fully overrides schema-derived
   signals by design (resolver priority chain, `rr_resolve_business_facts()`).
   Worth a README callout so operators know to include those two fields.

9. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
   (lowercase); `git add` with uppercase path silently stages nothing.
