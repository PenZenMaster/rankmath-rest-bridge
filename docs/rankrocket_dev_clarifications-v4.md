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

## 13. `url_patterns` Matching Semantics

Use **prefix matching only** for `url_patterns`.

Rules:

- Match against the normalized path portion of the URL, not the full URL.
- Matching should be case-insensitive.
- Normalize paths with a leading slash and trailing slash before comparing.
- Do not use arbitrary `contains` matching by default.
- Do not use regex unless a future explicit `regex_patterns` field is added.

Example:

```json
{
  "url_patterns": ["/services/"]
}
```

Matches:

```txt
/services/
/services/residential-painting-services/
/services/commercial-painting-services/
```

Does not match:

```txt
/blog/best-services-for-homeowners/
/about-our-services/
```

For exact single-page matches, support a separate `exact_paths` field or explicit post/page IDs.

Recommended section config:

```json
{
  "sections": {
    "core_business_pages": {
      "label": "Core Business Pages",
      "order": 1,
      "exact_paths": ["/", "/about/", "/contact/", "/gallery/"]
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
    }
  }
}
```

Acceptance criteria:

- Given `url_patterns` contains `/services/`, when a URL path is `/services/residential-painting-services/`, then it matches `service_pages`.
- Given `url_patterns` contains `/services/`, when a URL path is `/blog/best-services-for-homeowners/`, then it does not match.
- Given `exact_paths` contains `/about/`, when the URL path is `/about/`, then it matches.
- Given `exact_paths` contains `/about/`, when the URL path is `/about/team/`, then it does not match.

## 14. `_rrseo_llms_section` Meta Key and Write Path

`_rrseo_llms_section` should be registered as a first-class RankRocket post meta key.

Recommended constant:

```php
const META_LLMS_SECTION = '_rrseo_llms_section';
```

Implementation requirements:

- Add the meta key to the plugin constants block.
- Register post meta for supported public post types.
- Sanitize values against allowed section keys from `/llms` config.
- Include the field in audit logs whenever it is set, changed, or cleared.
- Expose it in read/preview endpoints so automation can verify classification.

Recommended write path:

- Add `llms_section` to `POST /update`.
- Add `llms_section` to `POST /meta/bulk-update`.
- Do not create a separate endpoint unless future UI needs require it.

Reasoning:

- It is page/post SEO discovery metadata, similar operationally to title, description, robots, and OG fields.
- The automation pipeline already uses `/update` and `/meta/bulk-update`.
- Keeping it in the same endpoint makes bulk classification straightforward.

Single update payload:

```json
{
  "post_id": 123,
  "llms_section": "service_pages"
}
```

Bulk update payload:

```json
{
  "updates": [
    {
      "post_id": 123,
      "llms_section": "service_pages"
    },
    {
      "post_id": 456,
      "llms_section": "location_pages"
    }
  ]
}
```

Read endpoints should expose `llms_section`:

- `GET /get/{id}`
- `POST /meta/bulk-get`
- `GET /sitemap/preview`
- `GET /llms/preview`

Recommended audit log entry:

```json
{
  "field": "llms_section",
  "meta_key": "_rrseo_llms_section",
  "before": "educational_articles",
  "after": "service_pages",
  "message": "Updated llms.txt section classification."
}
```

Validation behavior:

- If `llms_section` matches an allowed configured section key, save it.
- If `llms_section` is empty or null, delete `_rrseo_llms_section` and allow automatic classification.
- If `llms_section` is invalid, return a validation error listing allowed section keys.

Priority:

- Treat `_rrseo_llms_section` as P1.
- The shared Canonical URL Set can ship without manual section overrides.
- Manual section overrides are important for high-quality llms.txt output and agency workflows.

## 15. Additional Clarifications from Developer Review v3

### 15.1 Pattern Normalization Edge Case

Pattern strings are used **as-is**.

Trailing-slash normalization applies only to URL paths, not to pattern strings.

This is intentional because some patterns are directory prefixes and some are partial slug stems.

Example:

```json
{
  "url_patterns": ["/house-painter-near-me-in-"]
}
```

This must match:

```txt
/house-painter-near-me-in-ann-arbor-mi/
/house-painter-near-me-in-ypsilanti-mi/
```

Therefore, do not normalize the pattern to:

```txt
/house-painter-near-me-in-/
```

Path normalization rules:

- Normalize incoming URL paths.
- Ensure URL paths have a leading slash.
- Normalize URL path case for comparison.
- Normalize URL path trailing slash according to site convention.
- Do not alter configured pattern strings except for case-insensitive comparison.

### 15.2 Multi-Section Conflict Tiebreaker

When a URL matches multiple sections, the section with the lowest `order` value wins.

If two matching sections have the same `order`, use this fallback order:

1. Exact post/page ID assignment.
2. `exact_paths`.
3. `url_patterns`.
4. `post_types`.
5. Built-in fallback classification.

If there is still a tie, use the first matching section in config order and return a warning in preview diagnostics:

```json
{
  "code": "section_match_tie",
  "url": "https://example.com/services/locations/ann-arbor/",
  "matched_sections": ["service_pages", "location_pages"],
  "selected_section": "service_pages"
}
```

### 15.3 `business_facts` Schema Source Post

Add optional `schema_source_post_id` support to `/llms` config.

Purpose:

- Allows the pipeline or admin to specify which page should be used as the schema fallback source for business facts.
- Avoids ambiguous homepage/contact-page lookup behavior.

Recommended config:

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
  },
  "schema_source_post_id": 123
}
```

Business facts source priority:

1. Manual `business_facts` config.
2. Schema from `schema_source_post_id`, if provided.
3. Schema from homepage.
4. Schema from contact page.
5. WordPress site name/site URL/plugin options fallback.

If `schema_source_post_id` is provided but no usable schema exists on that post, return a warning:

```json
{
  "code": "schema_source_missing_business_facts",
  "post_id": 123,
  "message": "Configured schema_source_post_id does not contain usable LocalBusiness or Organization schema."
}
```

### 15.4 Stale `_rrseo_llms_section` Values

Stored `_rrseo_llms_section` values can become stale if the `/llms` section config changes and a section key is removed.

Read behavior:

- Do not silently delete the stored value.
- Do not silently trust the stale value.
- Return the stored value with a `stale_section_key` warning.
- Fall back to automatic classification for generated output unless config explicitly allows stale keys.

Recommended response behavior:

```json
{
  "llms_section": "old_service_pages",
  "effective_llms_section": "service_pages",
  "warnings": [
    {
      "code": "stale_section_key",
      "stored_section": "old_service_pages",
      "effective_section": "service_pages",
      "message": "Stored llms_section is not present in current llms section config. Automatic classification was used for output."
    }
  ]
}
```

Write behavior:

- If `llms_section` is invalid at write time, reject with validation error.
- If `llms_section` is null or empty, clear the stored override.
- If config later changes and makes a stored key stale, do not mutate content automatically; report warning and let the pipeline clear or update it.

### 15.5 `llms_section` in `/sitemap/preview`

Clarification: `llms_section` is not required for pure XML sitemap generation.

However, `/sitemap/preview` is being treated as a broader **Canonical URL Set preview** endpoint, not only a literal sitemap XML preview.

Therefore:

- It is acceptable and useful for `/sitemap/preview` to include URL metadata such as `llms_section`.
- The endpoint should document that it previews the Canonical URL Set used by sitemap and llms generation.
- If the team wants a cleaner name later, add `/canonical-urls/preview` as an alias or replacement.

Recommended response fields per URL:

```json
{
  "post_id": 123,
  "loc": "https://example.com/services/",
  "lastmod": "2026-04-29T16:22:27+00:00",
  "priority": 0.8,
  "included": true,
  "noindex": false,
  "llms_section": "service_pages",
  "effective_llms_section": "service_pages",
  "exclusion_reason": null,
  "warnings": []
}
```

### 15.6 `llms_section` Handler Architecture

Use **Option A** for now.

Handle `llms_section` as a special case in:

- `rmb_update_meta()`
- `rmb_meta_bulk_update()`

Do not add `llms_section` to `RR_SEO_META_KEYS`.

Reasoning:

- `llms_section` is classification metadata, not an SEO output field.
- It uses different validation logic.
- It maps to `_rrseo_llms_section`, not the existing `_rrseo_*` SEO output fields.
- A separate architecture is not needed for one field.

Implementation guidance:

```php
// Pseudocode only
if ( $request->has_param( 'llms_section' ) ) {
    $section = $request->get_param( 'llms_section' );
    $result  = rr_validate_llms_section( $section );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    rr_update_llms_section_meta( $post_id, $section );
    rr_log_meta_change( $post_id, 'llms_section', $before, $after );
}
```

Future option:

- If more classification fields are added later, introduce `RR_CLASSIFICATION_META_KEYS` or a dedicated classification metadata handler.

### 15.7 Multiple Existing `Sitemap:` Directives in robots.txt

When `ensure_sitemap_directive` is true, the plugin should normalize sitemap directives.

Recommended behavior:

1. Parse all existing `Sitemap:` lines case-insensitively.
2. Preserve non-sitemap robots.txt rules exactly as submitted.
3. If `preferred_sitemap_only` is true, remove existing sitemap directives and append the preferred RankRocket sitemap directive once.
4. If `preferred_sitemap_only` is false, preserve existing sitemap directives and append the preferred RankRocket sitemap directive only if it is not already present.
5. Deduplicate identical sitemap directives.

Recommended default:

```json
{
  "ensure_sitemap_directive": true,
  "preferred_sitemap_only": true
}
```

Preferred output:

```txt
User-agent: *
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php

Sitemap: https://example.com/sitemap_index.xml
```

If a physical robots.txt exists:

- Do not claim injection succeeded.
- Return a warning that the physical file may bypass stored/plugin-generated robots output.

### 15.8 Description Line-Break Normalization

Before truncating llms.txt descriptions, normalize whitespace.

Rules:

- Convert CRLF and CR to LF.
- Replace line breaks with single spaces.
- Collapse repeated whitespace into a single space.
- Trim leading/trailing whitespace.
- Decode common HTML entities if the source is rendered/excerpt/meta content.
- Strip HTML tags before truncation.
- Then apply word-boundary truncation with ASCII `...`.

Example:

Input:

```txt
Residential painting services

for Ypsilanti homeowners &amp; businesses.
```

Output:

```txt
Residential painting services for Ypsilanti homeowners & businesses.
```

The `max_description_chars` limit applies after whitespace and entity normalization.

## 16. Additional Clarifications from Developer Review v4

### 16.1 Remove Ambiguous Contact Page Schema Heuristic

Remove the generic "contact page" fallback from the business facts source priority.

There is no reliable WordPress-native concept of "the contact page." Slug-based heuristics such as `/contact/` or `/contact-us/` are site-specific and can create ambiguous behavior.

Revised business facts source priority:

1. Manual `business_facts` config.
2. Schema from `schema_source_post_id`, if provided.
3. Schema from homepage.
4. WordPress site name/site URL/plugin options fallback.

If a site stores LocalBusiness or Organization schema on its contact page instead of the homepage, the pipeline should set:

```json
{
  "schema_source_post_id": 456
}
```

This keeps the fallback chain deterministic.

### 16.2 Trailing-Slash Normalization Rule

Use a simple, explicit trailing-slash rule for matching.

For `url_patterns` prefix comparison:

- Normalize URL paths to lowercase.
- Ensure URL paths start with `/`.
- Ensure URL paths end with `/`.
- Pattern strings are used as configured except for lowercase comparison.
- Do not force trailing slashes onto pattern strings because some patterns intentionally end in a partial slug stem such as `/house-painter-near-me-in-`.

Examples:

```txt
Path:    /Services/Residential-Painting-Services
Compare: /services/residential-painting-services/
```

Pattern:

```txt
/services/
```

Result:

```txt
match
```

Partial slug-stem pattern:

```txt
/house-painter-near-me-in-
```

Still matches:

```txt
/house-painter-near-me-in-ann-arbor-mi/
```

This avoids having to infer the site's permalink convention from `get_option( 'permalink_structure' )` and keeps behavior predictable.

### 16.3 Add `/canonical-urls/preview` to P2 Backlog

Add a P2 endpoint alias:

```txt
GET /wp-json/rankrocket-seo/v1/canonical-urls/preview
```

Purpose:

- Provide a clearly named preview endpoint for the shared Canonical URL Set.
- Avoid overloading `/sitemap/preview` with broader URL metadata responsibilities.
- Support future migration away from using `/sitemap/preview` as the general canonical URL audit endpoint.

Recommended behavior:

- Return the same payload as the enhanced `/sitemap/preview`.
- Include `llms_section`, `effective_llms_section`, `exclusion_reason`, and per-URL warnings.
- Include a top-level `source` or `endpoint_role` field to make the purpose clear.

Suggested response shape:

```json
{
  "endpoint_role": "canonical_url_set_preview",
  "canonical_urls": [],
  "excluded_urls": [],
  "warnings": [],
  "counts": {
    "included": 0,
    "excluded": 0
  }
}
```

Priority:

- P2.
- Useful for clarity and future API ergonomics.
- Not required for P0/P1 completion if `/sitemap/preview` exposes the needed diagnostics.

### 16.4 WordPress REST Request Implementation Note

The prior pseudocode used:

```php
$request->has_param( 'llms_section' )
```

`WP_REST_Request` does not provide `has_param()`.

Use:

```php
$request->get_param( 'llms_section' ) !== null
```

or inspect the request params array directly.

Corrected pseudocode:

```php
// Pseudocode only
$section = $request->get_param( 'llms_section' );

if ( $section !== null ) {
    $result = rr_validate_llms_section( $section );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    rr_update_llms_section_meta( $post_id, $section );
    rr_log_meta_change( $post_id, 'llms_section', $before, $after );
}
```

If an empty string is passed:

```php
llms_section: ""
```

it should be treated as an explicit clear/delete operation for `_rrseo_llms_section`.
