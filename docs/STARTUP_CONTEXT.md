# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-07
**Branch:** main
**Version:** 3.8.1 (shipped, zip on CDN; confirmed live on kildaybaxter.com and higginsoverheaddoor.com)
**Last Commit:** 594e7b8 -- chore: release v3.8.1 zip

---

## Last 3 Accomplishments

1. **Telemetry verdict review completed (2026-08-07)** -- reviewed
   `rrc-telemetry.php`'s 32-day sample (2026-07-06 -> 2026-08-07) from
   rankrocket.co via CSV export (`docs/plugin-usage-2026-08-07.csv`).
   Two cleanup candidates found: `wordpress-importer` (DEAD -- zero
   hooks, zero admin hits, never fired) and `wordfence-activator-1.4.0`
   (stale since 2026-05-13, likely an orphaned installer stub distinct
   from the real `wordfence` plugin, which is fully active). User to
   remove both via wp-admin. Bigger finding: `seo-by-rank-math` and
   `seo-by-rank-math-pro` have fired zero hooks since 2026-05-15 (~12
   weeks) on rankrocket.co -- functionally retired there, relevant
   evidence for the Future/Deferred "P3 RankMath Reference Purge" item,
   but NOT sufficient to act on alone: cross-checked against this
   session's earlier `/status` calls, Higgins still shows
   `rankmath_active: true` (a live client dependency), so the purge
   prerequisite ("confirm no active clients rely on the fallback")
   remains unmet. Everything else on rankrocket.co (11 other plugins +
   this plugin itself) shows healthy, recent activity.

2. **Roadmap/issue review + v3.8.1 fixes (2026-08-06)** -- reviewed
   outstanding GitHub issues and doc-level technical debt. Found 3 new
   issues (#16, #17, #18) filed by an external audit pass immediately
   after #12/#14/#15 closed -- same pattern as the audit that originally
   surfaced #9-#15. Fixed and live-verified #16 (`POST /media` returned
   `400` not the documented `422` for missing `alt_text`/`source` --
   both fields no longer `required` at the REST-args level) and #17
   (`GET /capabilities`'s `Cache-Control` header was being overridden;
   root cause corrected from the earlier "host proxy" guess to WordPress
   core forcing nocache on authenticated REST responses, confirmed
   identically on two unrelated hosts). #18 (`since: null` backfill)
   reviewed but not fixed -- its own suggested version table contains a
   confirmed-wrong guess. Filed #19 (entity_clarity README callout,
   carried as a doc note since 2026-07-20) and #20 (`POST /self-update`
   reports false success -- discovered live on Higgins during this
   session's verification, see below). Cleaned up stale `[ ]` roadmap
   checkboxes in `projectStatus.md` for v3.0 Bites 2-4 (all shipped
   2026-07-09/10, verified real test coverage before checking each box).

3. **Higgins render-block fix completed and verified live (2026-08-06)**
   -- closed out the perf deployment carried over since 2026-07-10.
   Found the theme was still separately enqueuing its own blocking
   `bootstrap`/`font-awesome` stylesheets alongside an existing
   priority:1 async-swap snippet from a prior session -- the async
   preload had zero effect because the blocking original was still
   there. Added `/perf/dequeue-rules` for both (after discovering the
   handle-name gotcha -- see Key Context Notes), added the matching
   Bootstrap async snippet, fixed the existing FA snippet's missing
   `<noscript>` fallback. PageSpeed mobile: Performance 53 -> 60-65
   across 3 post-fix runs; LCP stayed noisy (9.3-13.6s), short of the
   originally-projected 78-85 -- likely a separate bottleneck, not
   investigated further. Real, repeatable win; not fully resolved.

---

## Next 3 Priorities

1. **Issue #20 (`POST /self-update` false-success)** -- medium impact per
   its own writeup: the README documents this endpoint as the
   recommended headless/CI deployment path, but it currently can't be
   trusted to detect its own failure. Fix is well-scoped: re-read the
   installed plugin file's version via `get_plugin_data()` after
   `Plugin_Upgrader::install()` and compare against the expected
   version before reporting success. Now the top open item.

2. **rankrocket.co plugin cleanup (user-actioned, not this repo)** --
   remove `wordpress-importer` (DEAD) and investigate/remove
   `wordfence-activator-1.4.0` (stale orphan) via wp-admin, per the
   2026-08-07 telemetry verdict review. Not tracked as a GitHub issue --
   it's site housekeeping, not a plugin code change.

3. **Issue #18 (`since: null` backfill on `/capabilities`)** -- low
   impact, explicitly optional. If picked up, do NOT use the issue's own
   suggested version table (contains at least one confirmed-wrong guess
   -- see Key Context Notes). Use `git log -S"route string"` archaeology
   instead; full history goes back to `v1.2.0`, well past the
   CHANGELOG.md's tracked floor of v2.11.3.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `594e7b8`
- Kilday Baxter (kildaybaxter.com) and Higgins (higginsoverheaddoor.com)
  both confirmed running v3.8.1. Higgins needed a manual wp-admin update
  after `/self-update` reported false success twice (see issue #20) --
  worked correctly on kildaybaxter.com via the API both times this
  session.
- Gates: phpcs clean, phpunit 280 tests / 799 assertions

**Open GitHub issues (2):**
- **#18** -- `GET /capabilities` `since: null` backfill (low impact, optional)
- **#20** -- `POST /self-update` false-success (medium impact, well-scoped fix)

**Files of note:**
- Capabilities map: `rankmath-rest-bridge.php`
  (`rr_get_capabilities_map()`, `rmb_capabilities_get()`, plus the
  `rest_pre_serve_request` Cache-Control hook right after it)
- Self-update (has the #20 bug): `rankmath-rest-bridge.php`
  (`rmb_self_update()` -- trusts `Plugin_Upgrader::install()`'s return
  value, never re-verifies the installed version from disk)
- Media upload validation: `rankmath-rest-bridge.php`
  (`rr_validate_media_fields()`, `rr_validate_media_file()`,
  `rmb_media_upload()`, `rmb_media_list_placeholders()`)
- Elementor set-data validation: `rankmath-rest-bridge.php`
  (`rr_validate_elementor_data()`, `rr_elementor_walk_tree()`,
  `rr_elementor_clear_css_cache()`, `rmb_elementor_set_data()`)
- Schema graph validation: `rankmath-rest-bridge.php`
  (`rr_validate_schema()`, `rr_validate_schema_graph()`, `rmb_schema_set()`)
- Perf dequeue mechanism: `rankmath-rest-bridge.php`
  (`rrseo_apply_dequeue_rules()` -- hooked `wp_enqueue_scripts:999`)
- Release hook note: run `git push` twice (zip commit lands after refspec)
- GitHub milestone #1 "Programmatic page provisioning" -- closed, 4/4
  issues resolved and live-verified.

**Blockers:**
- None. #18 and #20 are both explicitly non-urgent per their own
  writeups; nothing in the repo is currently broken or blocking other
  work.

---

## Key Context Notes

1. **WP Engine force-rewrites `Cache-Control` on authenticated requests
   at the edge, unconditionally** -- confirmed via WP Engine's own
   `X-Cacheable: NO:Passed` / `X-Pass-Why: auth` response headers on
   higginsoverheaddoor.com. This happens at their reverse proxy, after
   PHP has already sent the correct header -- no origin-level code
   change can override it. `GET /capabilities` is verified fully correct
   (`Cache-Control: public, max-age=60`) on non-WP-Engine hosts
   (kildaybaxter.com) as of v3.8.1. Don't chase this further on WP
   Engine sites specifically -- it's platform policy, not a bug.

2. **The original v3.8.0 diagnosis for the `/capabilities` Cache-Control
   issue was wrong** -- first attributed to kildaybaxter.com's specific
   host/proxy layer. Re-tested on Higgins (a completely different stack)
   during the #17 fix and got the identical override, which ruled that
   out -- the real cause was WordPress core forcing nocache headers on
   every authenticated REST response, fixed in v3.8.1 via a
   `rest_pre_serve_request` hook (the last filter before output, so a
   raw `header()` call there reliably wins). Worth remembering: a
   single-site observation about "the host is doing X" should be
   cross-checked on a second, differently-stacked site before being
   written down as the root cause.

3. **`/perf/dequeue-rules` handles are the actual WP dependency handle,
   not the rendered `id` attribute** -- WordPress prints stylesheet tags
   as `id="{handle}-css"`, so a tag showing `id='bootstrap-css'` has the
   real handle `bootstrap` (not `bootstrap-css`). Dequeuing the wrong
   string silently no-ops (no error, the rule just never matches
   anything). Cost a full extra round-trip on Higgins on 2026-08-06.
   When building dequeue rules from page-source inspection, always strip
   the `-css` suffix from visible `id` attributes first.

4. **WP Engine + Cloudflare stacks need a manual cache purge after any
   snippet/dequeue-rule write** -- the plugin's `POST /cache/purge` only
   clears WordPress's internal object cache; its Varnish-purge attempt
   (`localhost:80`) times out on WP Engine, which doesn't run a
   locally-reachable Varnish the way that assumes. Both WP Engine's page
   cache and Cloudflare's edge cache can serve HTML far older than the
   most recent write (observed `Cache-Control: max-age=15552000` -- 180
   days -- with `cf-cache-status: HIT`) until purged from their own
   dashboards/APIs. A `?cb=<random>` query string forces a fresh,
   uncached fetch for verification without waiting on a real purge.

5. **Full git history goes back to `v1.2.0`, past CHANGELOG.md's
   tracked floor of v2.11.3** -- relevant for issue #18. `git log
   -S"route string" -- rankmath-rest-bridge.php` can accurately date
   when a given route was introduced. Confirmed `/update`, `/get/{id}`,
   `/snippets`, `/cache/purge`, and `/status` all existed in the very
   first commit -- so any `since` guess later than that for those routes
   (e.g. issue #18's own suggested `3.0.0` for `seo.meta.update`) is
   wrong.

6. **Elementor `settings: {}` round-trips as `[]`** -- PHP's JSON encoder
   can't distinguish an empty associative array from an empty list, so
   `POST /elementor/set-data` stores/returns `"settings":[]` for empty
   settings objects rather than `{}`. Elementor's own native storage has
   the same quirk, so this likely matches native behavior. Not filed as
   an issue -- flag if an operator reports the Elementor editor choking
   on a plugin-written element.

7. **Missing required REST args (e.g. `alt_text` entirely absent) used
   to return `400 rest_missing_callback_param` instead of the endpoint's
   documented `422`** -- fixed for `/media` in v3.8.1 (issue #16) by
   dropping `required: true` from the REST-args schema and letting the
   handler's own validator catch both missing and empty values
   uniformly. Worth checking whether the same pattern exists on other
   endpoints with `required: true` args if a similar report comes in.

8. **`business_facts` merge semantics (v3.4.1)** -- sent keys overwrite,
   omitted keys preserved, array fields replace wholesale (not append).
   Sending `business_facts: {}` is now a no-op, not a full clear -- no
   endpoint currently clears the whole object back to schema/bloginfo
   fallback; that would be a separate feature request if ever needed.

9. **`has_business_facts` scoring change is retroactive** -- any site
   with identity-only `business_facts` (name/phone/address, no
   services/area/questions/differentiators) will see `has_business_facts`
   flip false and `llms_completeness`/`overall` drop under v3.4.0+, even
   with no write on their part. Flagged in both CHANGELOGs; not yet
   confirmed whether this affected Kilday Baxter's own live score
   post-deploy.

10. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
    (lowercase); `git add` with uppercase path silently stages nothing.
