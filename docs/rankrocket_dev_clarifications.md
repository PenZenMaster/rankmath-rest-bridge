# RankRocket SEO Control Layer - Developer Clarifications

Date: April 29, 2026  
Related spec: `rankrocket_endpoint_sync_spec.md`  
Plugin target: RankRocket SEO Control Layer

## Summary

This document clarifies implementation details raised in developer feedback on the RankRocket endpoint and crawl-file synchronization spec.

The main architectural decision remains unchanged: sitemap generation, robots.txt sitemap directives, llms.txt generation, sitemap preview, and report/source exports should use one shared **Canonical URL Set**.

## 1. Intent Classification

Use a **hybrid classification model**.

Classification priority:

1. Explicit per-post meta: `_rrseo_llms_section`
2. Manual config mapping by post ID or URL
3. Configured section `url_patterns`
4. Safe built-in heuristics
5. Fallback classification

Recommended config shape:

```json
{
  "sections": {
    "core_business_pages": {
      "label": "Core Business Pages",
      "order": 1,
      "url_patterns": ["/", "/about/", "/contact/", "/gallery/"]
    },
    "service_pages": {
      "label": "Service Pages",
      "order": 2,
      "url_patterns": ["/services/"]
    },
    "location_pages": {
      "label": "Location Pages",
      "order": 3,
      "url_patterns": ["/locations/", "/house-painter-near-me-in-"]
    },
    "educational_articles": {
      "label": "Educational Articles",
      "order": 4,
      "post_types": ["post"]
    }
  }
}
```

Default fallback rules:

- `post_type=post` -> `educational_articles`
- homepage, About, Contact, Services, Gallery -> `core_business_pages`
- URLs under `/services/` -> `service_pages`
- configured location URL patterns -> `location_pages`
- otherwise:
  - pages -> `core_business_pages`
  - posts -> `educational_articles`

Important distinction:

- `sections` controls output labels, order, and matching rules.
- `_rrseo_llms_section` provides per-post override.
- The generator should not rely on opaque AI-style intent inference.

## 2. `business_facts` Source of Truth

Use a **hybrid source model**.

Priority order:

1. Manual `/llms` config overrides all other sources.
2. If manual config is missing, derive from existing schema, especially `_rrseo_schema_graph` on homepage or contact page.
3. If schema is missing, fall back to WordPress site name, site URL, and plugin options.

Reasoning:

- Manual config gives agencies editorial control.
- Schema-derived defaults reduce double entry.
- WordPress fallbacks prevent empty llms.txt sections on simple sites.

Recommended config block:

```json
{
  "business_facts": {
    "business_name": "Example LLC",
    "primary_services": ["Service 1", "Service 2"],
    "service_area": ["City, ST"],
    "phone": "+1-555-555-5555",
    "address": "123 Main St, City, ST",
    "schema_type": "PaintingContractor",
    "entity_id": "https://example.com/#localbusiness"
  }
}
```

## 3. Redirect Detection Scope

Narrow redirect detection for P0.

P0 should not attempt:

- Full external HTTP crawling.
- `.htaccess` parsing.
- Redirection plugin integration.
- Other third-party redirect registry integrations.

P0 rule:

- Exclude or warn only where WordPress/RankRocket can determine a canonical mismatch or known internal redirect from available data.

Optional P1:

- Add deeper validation behind an explicit flag:

```txt
/sitemap/preview?deep_check=true
```

The deep check may perform HTTP status and redirect checks but should not run by default.

## 4. Numeric Suffix Detection

Use the safe detection rule.

A numeric suffix duplicate is only detected when:

1. The slug matches `{base}-{integer}` with the integer at the end.
2. A published/indexable base post or page with slug `{base}` exists.

Examples:

- `/top-10-tips/` -> keep. The number is part of the real slug.
- `/best-painter-ann-arbor-2/` -> exclude only if `/best-painter-ann-arbor/` exists.

Orphan suffix rule:

- Keep orphan suffix URLs in the Canonical URL Set only if they are:
  - published
  - indexable
  - self-canonical
  - not utility/test content
  - no base URL exists

However, orphan suffix URLs should generate a warning:

```json
{
  "code": "orphan_numeric_suffix",
  "url": "https://example.com/page-2/",
  "message": "URL has a numeric suffix but no canonical base URL was found."
}
```

Reasoning:

- Silently excluding orphan suffix URLs may remove the only valid live article.
- Keeping them with a warning preserves sitemap/llms synchronization while surfacing cleanup needs.

## 5. `exclude_utility_pages` vs `exclude_patterns`

Keep both settings.

Behavior:

- `exclude_utility_pages: true` applies RankRocket's default utility-page exclusion set.
- `exclude_patterns` adds custom exclusions.

Example:

```json
{
  "exclude_utility_pages": true,
  "exclude_patterns": ["-2/", "-3/", "/custom-thank-you/"]
}
```

This means:

- apply the built-in utility exclusions
- plus custom patterns

Default utility exclusion examples:

```json
[
  "/thank-you/",
  "/privacy-policy/",
  "/opt-out-preferences/",
  "/cart/",
  "/checkout/",
  "/my-account/",
  "/account/",
  "/search/",
  "/feed/"
]
```

## 6. `GET /images/{id}/alt`

Add GET support to the existing `/images/{id}/alt` route.

Implementation note:

- Register the route with separate GET and POST handlers, similar to the `/schema/{post_id}` route pattern.

GET response should be read-oriented:

```json
{
  "id": 952,
  "url": "https://example.com/wp-content/uploads/example.jpg",
  "filename": "example.jpg",
  "alt": "Current ALT text",
  "title": "Media title",
  "caption": "Media caption"
}
```

POST response:

- Keep the existing response shape for backward compatibility.
- Preserve success/before/after fields.
- Optionally include the same media fields as GET, but do not remove or rename existing POST response fields.

## 7. Physical `robots.txt` and Auto-Inject

If a physical `robots.txt` file exists, WordPress filters may be bypassed. In that case, the plugin cannot reliably inject output.

Required behavior:

- `/status` should report:

```json
{
  "physical_robots_txt_exists": true
}
```

- `/status` should include a warning:

```json
{
  "code": "physical_robots_txt_bypass",
  "message": "A physical robots.txt file exists and may bypass RankRocket robots.txt output."
}
```

For `POST /robots-txt`:

- Store submitted content as-is by default.
- Return a warning if no preferred `Sitemap:` directive is present.
- Support an optional flag:

```json
{
  "ensure_sitemap_directive": true
}
```

When `ensure_sitemap_directive` is true:

- Append the preferred RankRocket sitemap directive if missing.
- Replace the existing sitemap directive if plugin config says RankRocket sitemap should be preferred.

Preferred directive:

```txt
Sitemap: https://example.com/sitemap_index.xml
```

## 8. `max_description_chars`

Use word-boundary truncation.

Rules:

- Apply the limit to all displayed llms.txt descriptions, regardless of source.
- Clean whitespace before truncating.
- Truncate at the nearest word boundary under the limit.
- Use ASCII `...`, not a Unicode ellipsis.
- Default max: `240`.

Example:

```txt
This is a long generated description that would exceed the configured maximum...
```

## 9. `/status` Performance

Do not run full canonical URL generation on every default `/status` request.

Recommended model:

```txt
GET /status
```

returns lightweight plugin health.

```txt
GET /status?include_counts=true
```

returns canonical URL counts, llms URL counts, exclusions, and mismatch warnings.

Caching option:

- Store canonical counts in a transient.
- Invalidate on:
  - `save_post`
  - `delete_post`
  - post status changes
  - RankRocket metadata updates
  - robots config updates
  - llms config updates

## 10. Legacy Namespace Alias

The correct historical namespace to support is:

```txt
rankmath-bridge/v1
```

not:

```txt
rankmath-rest-bridge/v1
```

If a compatibility alias is added, support:

```txt
/wp-json/rankmath-bridge/v1/...
```

and return a deprecation warning pointing callers to:

```txt
/wp-json/rankrocket-seo/v1/...
```

Example warning:

```json
{
  "deprecated_namespace": "rankmath-bridge/v1",
  "preferred_namespace": "rankrocket-seo/v1"
}
```

## 11. Revised P0/P1/P2 Priority

### P0

- Shared Canonical URL Set helper.
- Sitemaps use shared helper.
- llms.txt uses shared helper.
- robots.txt sitemap directive sync.
- `/status` exposes core URLs and physical robots.txt warning.
- Numeric suffix duplicate logic with orphan warning.

### P1

- `/llms/preview`.
- Expanded `/llms` config.
- Hybrid section classification.
- Business facts manual config plus schema fallback.
- `GET /images/{id}/alt`.
- Batch cap for `/images/bulk-alt`.
- `/status?include_counts=true`.

### P2

- Legacy namespace alias: `rankmath-bridge/v1`.
- Optional `deep_check=true` redirect/HTTP validation.
- Advanced manual section overrides and per-post UI.

## 12. Final Implementation Guidance

The key implementation principle is:

> Build one Canonical URL Set and make every discovery file consume it.

The Canonical URL Set should drive:

- `/sitemap_index.xml`
- `/rmb-sitemap.xml`
- `/rmb-sitemap-posts.xml`
- `/rmb-sitemap-pages.xml`
- `/sitemap/preview`
- `/llms.txt`
- `/llms/preview`
- robots.txt sitemap directive validation
- audit/report source URL exports

Do not maintain separate hard-coded URL loops for sitemap and llms.txt.

The generator can vary formatting by output type, but the underlying eligible URL set must be shared.
