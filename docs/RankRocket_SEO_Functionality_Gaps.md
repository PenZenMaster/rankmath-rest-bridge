# RankRocket SEO Control Layer — Functionality Gap Report

**Surfaced from:** Salvo Metal Works SEO audit, May 14, 2026
**RankRocket version in use:** v2.12.2 (with intra-session auto-upgrade from 2.8.0)
**Purpose:** Document items in the Salvo remediation plan that COULD NOT be completed through the RankRocket SEO REST API alone, due to current plugin limitations. Each gap is a candidate plugin enhancement.

---

## Summary table

| # | Gap | Current workaround | Suggested plugin enhancement | Severity |
|---|---|---|---|---|
| G-01 | Taxonomy targeting in `display_on` is not supported | mu-plugin emits meta description and OG tags directly on `wp_head` for `product_cat` archives | Add `term:TAX:SLUG`, `term_id:NNN`, `tax:TAX`, `url:/path/` routing patterns to the snippet emitter | HIGH |
| G-02 | RankRocket `/update` endpoint rejects taxonomy term IDs | Term descriptions written via standard `wp/v2/product_cat/{id}` REST | Extend allowed_post_types to include taxonomy term writes; or add `/terms/{id}` endpoint with title/description/og/canonical/robots | HIGH |
| G-03 | Cannot suppress WordPress core `rel_canonical` (duplicate canonical) | mu-plugin removes WP-core action | Add settings flag `consolidate_canonical: true` mirroring existing `consolidate_wp_robots` | MEDIUM |
| G-04 | No way to dequeue plugin or theme assets via REST | mu-plugin handles WooCommerce dequeue on non-store pages | Add `/perf/dequeue-rules` endpoint accepting `{handle, type:style|script, when:not_woocommerce|page_id:NNN|term:TAX:SLUG}` | MEDIUM |
| G-05 | No way to defer script handles via REST | mu-plugin filters `script_loader_tag` for known-safe handles | Add `/perf/defer-handles` endpoint; combine with G-04 into a Performance module | MEDIUM |
| G-06 | `migrate-legacy` does not detect RankMath template tokens | Could not patch `rank_math_title`, `rank_math_description` during the transitional period when both plugins were active | If `migrate-legacy` runs while RankMath still active, optionally write BOTH `rr_seo_*` and `rank_math_*` postmeta so legacy still emits during cut-over (or document this is intentional and require RankMath deactivation first) | LOW |
| G-07 | `_elementor_data` and `_elementor_element_cache` writes via WP REST do not persist | Patched `_elementor_data` succeeded but stale `_elementor_element_cache` overrode rendered output | Add helper endpoint `/elementor/repair-cache?post_id=NNN` that deletes the stale element cache row | LOW |
| G-08 | Snippet `display_on` accepts arbitrary strings without validation | Test snippets with bogus patterns saved but never fired (silent failure) | On POST to `/snippets`, validate `display_on` against the known pattern allowlist; return 400 with a hint if invalid | LOW |
| G-09 | Sitemap exclusion of low-value WooCommerce taxonomies is automatic but not configurable | Could not include `/product-category/unavailable/` in noindex unless products were individually noindexed | Add settings array `sitemap_excluded_taxonomies` and `sitemap_excluded_terms` so the operator can exclude `unavailable`-style management categories | LOW |
| G-10 | No bulk-snippet REST endpoint | Created 8 snippets one-by-one with sequential curl calls | Add `POST /snippets/bulk` accepting `{snippets:[{title, code, ...}, ...]}` for atomic batch creation | LOW |
| G-11 | No `/snippets` GET returning full collection | Had to call `/status` to enumerate snippet IDs, then no GET-by-slug worked (404) | Add `GET /snippets` returning array of all snippets; add `GET /snippets/<id-or-slug>` that actually resolves | LOW |
| G-12 | No `/llms-txt/regenerate` endpoint | After metadata changes, had to wait for next-page cache refresh to see llms.txt rebuild | Expose explicit `POST /llms-txt/regenerate` for deterministic regeneration | LOW |
| G-13 | No event hook for "snippet emitted" or "snippet skipped" | Could not log why a snippet did not render | Fire `do_action('rrseo_snippet_emitted', $snippet, $context)` and `do_action('rrseo_snippet_skipped', $snippet, $reason)` for observability | LOW |
| G-14 | No conditional emission based on logged-in vs anonymous | All snippets emit identically for anonymous and logged-in visitors | Add optional `display_on_user:anonymous` and `display_on_user:logged_in` filter to snippet config | LOW |
| G-15 | No support for hreflang at scale | N/A for Salvo (English-only) but a gap for multilingual clients | Future-state: per-post `hreflang` array + sitewide language config | DEFERRED |

---

## G-01 (HIGH) — Taxonomy targeting in `display_on`

### Observed behavior
Tested in production on May 14, 2026:

| `display_on` value | POST status | Actually fired on live page |
|---|---|---|
| `entire_website` | 200 OK | Yes |
| `front_page` | 200 OK | Yes (home only) |
| `page_id:17446` | 200 OK | Yes (only on that page) |
| `term:product_cat:custom-cupolas` | 200 OK | **No** |
| `tax:product_cat:3944` | 200 OK | **No** |
| `term_id:3944` | 200 OK | **No** |
| `url:/product-category/custom-cupolas/` | 200 OK | **No** |
| `product_cat:custom-cupolas` | 200 OK | **No** |

### Impact
Blocked the ability to:
- Inject `<meta name="description">` on the 8 WooCommerce product category archive pages
- Inject `<meta property="og:*">` and `<meta name="twitter:*">` on the same pages
- Inject `CollectionPage` + `Product` JSON-LD per category (replacement for the synthetic-rating HFCM snippets removed during the audit)

### Workaround used
The mu-plugin `plugin-usage-audit.php` v1.2 hooks `wp_head` and emits these tags directly for product_cat archives, reading from the term's `description` field. This works but is brittle: any other plugin that emits taxonomy-context meta will conflict, and the operator has to manage two systems (RankRocket for singular content, mu-plugin for taxonomies).

### Suggested fix
Implemented in dev spec T-02. Plugin code in `Salvo_Metal_Works_Dev_Spec.md`.

---

## G-02 (HIGH) — REST `/update` rejects taxonomy term IDs

### Observed behavior
```
POST /wp-json/rankrocket-seo/v1/update
{"post_id": 4019, "title": "Custom Cupolas | Salvo", "description": "..."}
HTTP 200 — accepted but stored against post_id 4019 which IS NOT A POST. Result: lost write, no error returned to caller.
```

`allowed_post_types` in the plugin source is documented as `post, page, product`. Term IDs share the same numeric namespace as post IDs but are NOT valid here.

### Impact
Could not write canonical, robots, og_*, title, description, or canonical URL for WooCommerce product categories (or any other taxonomy) through the same uniform interface as posts/pages.

### Suggested fix
**Option A (preferred):** Extend allowed_post_types runtime check. If the ID resolves to a term, route to a taxonomy meta handler.

**Option B (more explicit):** Add a parallel `/terms/{id}` REST namespace mirroring `/update` semantics.

In either case, return a 422 with `{ "error": "id resolved to taxonomy term but plugin currently rejects term writes; supported in v2.13.0" }` rather than silently 200-ing.

---

## G-03 (MEDIUM) — No `consolidate_canonical` settings flag

### Observed behavior
WordPress core's `wp_head` action emits `rel_canonical()` regardless of any SEO plugin. RankRocket emits its own canonical earlier in `<head>`. Result: two `<link rel="canonical">` tags on every page. Both point to the same URL so it's harmless, but it's a polish issue.

### Existing pattern in plugin
The plugin already has `consolidate_wp_robots: true` and emits a consolidated robots meta. The same pattern should exist for canonicals.

### Suggested fix
1. Add option `rrseo_consolidate_canonical` (default `true`)
2. On plugin bootstrap, if option is true, run `remove_action( 'wp_head', 'rel_canonical' );`
3. Expose in `/status` response as `consolidate_canonical: true`
4. Allow override via `POST /settings` with `{ "consolidate_canonical": false }`

### Workaround used
mu-plugin module `RRC_SEO_DEDUP_CANONICAL` does exactly this. Can be retired once G-03 is shipped.

---

## G-04 (MEDIUM) — No REST surface for asset dequeue rules

### Observed behavior
WooCommerce loads ~150 KiB of CSS and ~80 KiB of JS on every page including non-store pages (homepage, About, FAQ, Contact). There is no way to configure conditional dequeue rules through RankRocket.

### Impact
PageSpeed mobile Performance score was 74. After dequeuing WooCommerce assets on non-store pages (via mu-plugin in T-01), expected lift is ~5-10 points.

### Suggested fix
Add a `Performance` module to RankRocket with a `/perf/dequeue-rules` endpoint:

```
POST /wp-json/rankrocket-seo/v1/perf/dequeue-rules
{
  "rules": [
    {
      "handles": ["woocommerce-layout", "wc-blocks-style", "wc-add-to-cart"],
      "type": "auto",          // auto-detects style vs script from registered handles
      "when_not": ["is_woocommerce", "is_cart", "is_checkout", "is_account_page",
                   "is_product", "is_product_category", "is_product_tag", "is_shop"]
    }
  ]
}
```

Internally this translates to a `wp_enqueue_scripts` hook with the conditional ladder. Rules stored as option `rrseo_perf_dequeue_rules`.

### Workaround used
Hardcoded in mu-plugin module `RRC_SEO_WC_DEQUEUE`. Can be retired once G-04 ships.

---

## G-05 (MEDIUM) — No REST surface for script defer

### Observed behavior
Render-blocking JS audits flag scripts that ship as synchronous despite being safe to defer. No way to configure these in RankRocket.

### Suggested fix
Companion to G-04: add `/perf/defer-handles` endpoint accepting `{ handles: ["font-awesome-4-shim", "wp-emoji-release", ...] }`. Internally filter `script_loader_tag`.

### Workaround used
mu-plugin module `RRC_SEO_DEFER_NONCRIT`. Can be retired once G-05 ships.

---

## G-06 (LOW) — `migrate-legacy` overwrites with broken template tokens

### Observed behavior
When migrating from RankMath, `migrate-legacy` copies `rank_math_*` postmeta into `rr_seo_*`. But if a page's RankMath description is the literal template token `%title% %excerpt%` (the bug condition that triggered the entire audit), the migration copies that literal string. Result: post-migration `rr_seo_description` contains `%title% %excerpt%`, which then emits literally because RankRocket does not run RankMath's template engine.

### Workaround used
Skipped migration entirely. Wrote fresh real metadata to 19 priority pages via `/meta/bulk-update`.

### Suggested fix
On `migrate-legacy`, detect known template token patterns (`%title%`, `%sitename%`, `%excerpt%`, `%sep%`, `%page%`, `%category%`, `%tag%`) and:
- Either skip the field entirely and log it as `skipped_due_to_template_token`
- Or run a minimal template expansion (replace `%title%` with the post title, `%sitename%` with `get_bloginfo('name')`, etc.) so the migrated value is at least sensible

Include the count of skipped fields in the migration response so the operator knows to manually backfill them.

---

## G-07 (LOW) — Elementor `_elementor_element_cache` not handled

### Observed behavior
During the footer email mailto fix, patched `_elementor_data` via WP REST. The DB updated correctly (verified via context=edit GET), but the live page still served the OLD email because Elementor's `_elementor_element_cache` postmeta (which stores the rendered HTML) was stale.

### Workaround used
Asked the operator to manually clear the SiteGround/Elementor cache, which incidentally cleared `_elementor_element_cache`.

### Suggested fix
Optional helper endpoint:
```
POST /wp-json/rankrocket-seo/v1/elementor/repair-cache
{ "post_id": 17474 }   // or "post_ids": [17474, 17446]
```
That does:
```php
delete_post_meta( $post_id, '_elementor_element_cache' );
delete_post_meta( $post_id, '_elementor_css' );
```
This is technically out-of-scope for an SEO plugin, but it bridges the most common Elementor + REST friction point. Document it as "compatibility helper, optional".

---

## G-08 (LOW) — `display_on` validation accepts garbage

### Observed behavior
Any string passed to `display_on` is accepted with HTTP 200, even if it cannot fire anywhere.

### Suggested fix
On `/snippets` POST, validate `display_on` against the known allowlist:
- `entire_website`
- `front_page`
- `page_id:<int>`
- `post_id:<int>` (after G-01 ships)
- `term:<tax>:<slug>` (after G-01 ships)
- `term_id:<int>` (after G-01 ships)
- `tax:<tax>` (after G-01 ships)
- `url:<path>` (after G-01 ships)

Reject with HTTP 422 + `{ error, hint, accepted_patterns: [...] }` if no pattern matches.

---

## G-09 (LOW) — Sitemap exclusion of taxonomies not configurable

### Observed behavior
RankRocket's sitemap correctly excludes WooCommerce dynamic pages (cart, checkout, my-account) and policy pages. But there is no way to instruct it to exclude a SPECIFIC term, like `/product-category/unavailable/`, while keeping the rest of the taxonomy in the sitemap.

### Workaround used
Noindexed the 2 products inside `/unavailable/`. Lucky outcome: RankRocket auto-removed the entire `product_cat-sitemap.xml` after RankMath was deactivated.

### Suggested fix
Add settings:
```
rrseo_sitemap_excluded_terms   = [4024]                // by term ID
rrseo_sitemap_excluded_term_slugs = ["unavailable", "uncategorized"]
rrseo_sitemap_excluded_taxonomies = []                 // skip entire taxonomy
```

---

## G-10 (LOW) — No bulk-snippet endpoint

### Observed behavior
Phase 1 and Phase 2 each required posting 6-8 snippets sequentially with one curl per snippet. Worked but creates 6-8 separate HTTP round-trips, each invoking the plugin's full snippet save pipeline.

### Suggested fix
```
POST /wp-json/rankrocket-seo/v1/snippets/bulk
{ "snippets": [ {...}, {...}, ... ] }
```
Atomic: either all succeed or all roll back. Response: `{ count, results: [{slug, success, errors}], saved_to: "..." }`.

---

## G-11 (LOW) — Snippet GET endpoint broken/missing

### Observed behavior
- `GET /wp-json/rankrocket-seo/v1/snippets/<slug>` returns 404 even for slugs that DO exist
- `GET /wp-json/rankrocket-seo/v1/snippets` (no slug) returns 404
- Only way to enumerate is via `/status` which returns `snippet_count` and `snippet_ids` array

### Suggested fix
- `GET /snippets` returns full collection
- `GET /snippets/<slug>` returns single snippet by stable slug

---

## G-12 (LOW) — `llms.txt` regeneration not exposable

### Observed behavior
After updating 19 page descriptions, llms.txt eventually regenerated, but no explicit "regenerate now" endpoint exists. Took a cache miss + traffic to refresh.

### Suggested fix
```
POST /wp-json/rankrocket-seo/v1/llms-txt/regenerate
```
Returns `{ regenerated_at, included_count, excluded_count, bytes }`.

---

## G-13 (LOW) — No observability hooks for snippet emission

### Observed behavior
When a snippet does not appear on a page, the operator has no way to determine WHY (was display_on mismatch? was the snippet disabled? was the page noindexed?). The audit relied on viewing HTML source to confirm emission.

### Suggested fix
Two action hooks:
- `do_action( 'rrseo_snippet_emitted', $snippet, $context )`
- `do_action( 'rrseo_snippet_skipped', $snippet, $reason, $context )`

Plus an admin debug log entry written when `WP_DEBUG` is true, viewable in WP Admin > RankRocket > Diagnostics.

---

## G-14 (LOW) — No per-user-state conditional emission

### Observed behavior
All snippets emit identically for logged-in admin users and anonymous visitors. For some snippets (e.g., a tracking-pixel snippet for marketing) you only want emission for anonymous traffic.

### Suggested fix
Add optional fields:
```
{
  ...
  "display_on_user": "anonymous"   // or "logged_in" or "all" (default)
}
```

---

## G-15 (DEFERRED) — hreflang at scale

Not relevant to Salvo (English-only) but flagged for multilingual sites: there is currently no way to configure per-page `<link rel="alternate" hreflang="..." />` tags through RankRocket.

---

# Recommended plugin roadmap order

| Milestone | Bundled gaps | Plugin version |
|---|---|---|
| v2.13.0 | G-01, G-02, G-08 (taxonomy routing + REST + validation) | 2.13.0 |
| v2.14.0 | G-03 (consolidate_canonical), G-11 (GET /snippets), G-12 (regenerate llms.txt) | 2.14.0 |
| v2.15.0 | G-04, G-05 (Performance module: dequeue + defer) | 2.15.0 |
| v2.16.0 | G-06 (migrate-legacy template detection), G-07 (elementor cache helper) | 2.16.0 |
| v2.17.0 | G-09 (sitemap term exclusion), G-10 (bulk snippets), G-13 (observability hooks) | 2.17.0 |
| Future | G-14 (per-user-state), G-15 (hreflang) | TBD |

Once v2.13.0 ships, three of the mu-plugin modules in T-01 of the dev spec (`RRC_SEO_TAX_META_DESC`, `RRC_SEO_TAX_META_OG`, and the schemas that depend on taxonomy targeting) can be retired in favor of native RankRocket snippets. After v2.14.0, retire `RRC_SEO_DEDUP_CANONICAL`. After v2.15.0, retire `RRC_SEO_WC_DEQUEUE` and `RRC_SEO_DEFER_NONCRIT`. After all of those, the mu-plugin returns to its pre-Salvo state as a pure telemetry tool.

End of gap report.
