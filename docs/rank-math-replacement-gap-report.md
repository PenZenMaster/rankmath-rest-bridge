# Rank Math Replacement Gap Report

**Date:** 2026-10-07
**Plugin version audited:** 3.20.2
**Method:** Source read of `rankmath-rest-bridge.php` and `includes/*.php`. Nothing was run live.
**Question:** Can the RankRocket SEO Control Layer fully replace the Rank Math
SEO Suite features below on a site that has Rank Math installed?

**Verdict:** No. Strong on REST/no-UI llms.txt, redirects and schema. Gaps in
image SEO, Local SEO, automatic schema and sitemap coverage.

---

## 1. Feature comparison

| Rank Math feature | Replaceable? | Present | Gaps |
|---|---|---|---|
| Image SEO (ALT and Title) | Partial | `GET /images`, `GET`/`POST /images/{id}/alt`, `POST /images/bulk-alt`, `POST /media` (alt, title, caption at upload), `GET /media/placeholders`, `GET /observe/alt-coverage` | No write path for the title of an existing image (`title` is read-only in `GET /images`). No frontend auto ALT/title from patterns (for example `%filename%`). No "add missing attributes" on output. No caption/description edit. No rule-based bulk generation. |
| llms.txt CRUD (no-UI) | Mostly | `GET`/`POST /llms` (intro, sections, custom sections, business facts, exclusions, grouping), `GET /llms/preview`, `POST /llms-txt/regenerate`, `GET /observe/llms-diff`, served at `/llms.txt` | No raw-text override of the full file. No delete/disable endpoint. Content is config-generated. |
| Local SEO / Knowledge Graph | Weak | Generic snippets can carry hand-written `LocalBusiness` JSON-LD (one snippet per location). `GET /aeo-geo/entity`, readiness and schema audits. | No Local SEO settings object: business info, opening hours, geo coordinates, price range, phone, social `sameAs`. No multi-location model. No KML sitemap or map embed. No Organization/Person Knowledge Graph setting. |
| Redirections CRUD | Mostly | `GET`/`POST /redirects`, `GET`/`POST`/`DELETE /redirects/{id}`, `/redirects/bulk`, `/redirects/preview`. Codes 301, 302, 307, 308. Match types exact, prefix, regex (backtracking-safe). Loop detection. Allowlisted cross-domain targets. | No 410 or 451. No hit counter. No 404 monitor/log. No auto-redirect on slug change or trash. No import/export. No contains/starts-with/ends-with match types (prefix and regex cover most uses). |
| Schema CRUD (no-UI) | Mostly | `GET`/`POST /schema/{post_id}` (whole-graph replace, 17-type allowlist, validation), FAQ endpoint with merge helpers, snippets CRUD for site-wide nodes, `/observe/schema-graph`, `/aeo-geo/schema-audit` | No per-node merge on `/schema`. No confirmed endpoint to clear a stored graph. No automatic schema (Article/WebPage defaults, per-post-type templates). No BreadcrumbList generation. |
| Sitemap CRUD (no-UI) | Partial | Own index at `/sitemap_index.xml` with XSL styling. `GET`/`POST /sitemap/exclusions`, `GET /sitemap/preview`, `GET /canonical-urls/preview`, shared canonical URL set. | Only `rmb-sitemap-posts.xml` and `rmb-sitemap-pages.xml` are served. `product` is in `RR_ALLOWED_POST_TYPES` but is not in any served sitemap. No taxonomy, custom post type or author sitemaps. No image, news or video sitemaps. |

## 2. Evidence from the field

- rankrocket.co schema audit (2026-10-07): 76 URLs, 5 with schema (6.6%),
  global warnings `no_faqpage_anywhere` and `no_breadcrumblist_anywhere`. This
  is what a site looks like without automatic schema.
- Several code paths check `class_exists( 'RankMath' )` and read `rank_math_*`
  meta as a migration fallback, so coexistence works. Removing Rank Math today
  would drop automatic schema, breadcrumbs and Local SEO fields.

## 3. Safe to replace now vs. not

- **Safe now:** llms.txt; redirections (if 410/451 and the 404 monitor are not
  needed); snippet-based schema.
- **Not safe without work:** image title and auto attributes; Local SEO;
  automatic schema and breadcrumbs; taxonomy, product and image sitemaps.

## 4. Recommended build order

All items are additive and backward-compatible; each is a feature release
(minor bump per the project versioning rules).

1. **RMR-01** (#39) Local SEO settings object generating LocalBusiness/Organization,
   with multi-location support.
2. **RMR-02** (#40) Automatic baseline schema (WebPage, Article, BreadcrumbList),
   switchable per site.
3. **RMR-03** (#41) Image title write endpoint, plus optional auto ALT/title
   templates on output.
4. **RMR-04** (#42) Taxonomy and product sub-sitemaps, then image sitemaps.
5. **RMR-05** (#43) Redirect 410/451, hit counter and 404 log.

Guardrails that apply to every item (from the project playbook): write only
`rr_seo_*` meta, call `rr_audit_log()` on SEO writes, pass
`rr_validate_seo_fields()` / `rr_validate_schema()`, and do not reintroduce bulk
wipe-and-replace endpoints.

## 5. Design decisions (2026-10-07)

Resolved from the original open questions; recorded as comments on the issues.

1. **Local SEO storage (#39):** a new dedicated options object
   (`rr_local_seo`), not the snippets store. Snippets are free-form blobs that
   cannot be validated per field or queried by location; llms.txt and the
   entity audit duplicate business data today and should read one structured
   source. The object renders into the existing schema pipeline with a stable
   `@id` per location. Migration is never automatic: offer a read-only
   "import from snippets" preview for existing LocalBusiness snippets, dedupe
   by `@id`, never delete snippets, and skip snippets whose `@id` the object
   already emits.
2. **Automatic schema default (#40):** opt-in, default off, per site. Rank
   Math is active on sites such as trevoraspiranti.com and
   endlessenergyfitness.com and already emits schema, so default-on would
   double-emit. Safeguards: a preview endpoint showing exactly what would be
   emitted; skip any `@type` already in the stored graph or snippets; a
   `/status` warning when another schema emitter is active. A later `auto`
   setting ("on only if no other schema plugin is active") is deferred until
   the opt-in version has run on a few sites.
3. **404 log (#43):** acceptable on shared hosting if opt-in, default off and
   aggregated. One row per normalized path (hits, first seen, last seen,
   optional referrer host) in a small custom table with an upsert; ignore
   query strings, static-asset extensions and common bot probe paths; hard cap
   of about 500 distinct paths with oldest evicted; 30-day retention purged by
   wp-cron; no IPs or full user agents. The redirect hit counter is separate
   and always on, since it writes only when a rule matches.

## 6. Remaining open questions

- Exact cap and retention values for the 404 log once tested on a real
  shared-hosting site.
- Whether the later `auto` schema mode is worth building at all.
