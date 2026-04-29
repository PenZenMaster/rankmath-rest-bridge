# RankRocket SEO Control Layer - Endpoint and Crawl File Synchronization Spec

Prepared for: RankRocket SEO Control Layer development  
Plugin observed version: 2.7.0  
Namespace: `rankrocket-seo/v1`  
Date: April 29, 2026

## 1. Problem Statement

RankRocket SEO Control Layer exposes the core endpoints needed for metadata updates, schema/snippets, image ALT updates, cache purge, sitemap generation, and llms.txt serving. However, sitemap generation, llms.txt generation, robots.txt sitemap directives, and preview/report workflows do not yet appear to share one canonical URL selection layer.

This creates a risk that:

- XML sitemaps include one URL set while llms.txt includes another.
- llms.txt lists duplicate numeric suffix URLs such as `-2/` and `-3/`.
- Utility pages such as thank-you, privacy, opt-out, cart, checkout, or account pages leak into AI-facing files.
- robots.txt points to WordPress core sitemap while RankRocket exposes a separate preferred sitemap index.
- audit tooling reports inconsistent findings across robots.txt, sitemap, llms.txt, and PDF source URLs.

The goal is to make RankRocket the single authoritative source for canonical crawl and AI-discovery files.

## 2. Goals

1. Use one shared Canonical URL Set for all generated crawl and AI files.
2. Ensure XML sitemap, robots.txt sitemap directive, llms.txt, and preview endpoints stay synchronized.
3. Give audit tools safe read/preview endpoints before applying changes.
4. Improve llms.txt quality by grouping URLs, adding descriptions, and excluding duplicate or utility URLs.
5. Preserve backwards compatibility where reasonable for existing automation.

## 3. Non-Goals

- Do not build a full SEO crawler that performs deep site-wide rendering for every request.
- Do not replace WordPress core permalink or canonical behavior.
- Do not require external APIs for basic sitemap or llms.txt generation.
- Do not include private, draft, password-protected, noindex, redirected, or utility URLs in public discovery files.
- Do not make destructive snippet replacement part of the normal workflow.

## 4. User Stories

### Story 1 - SEO auditor

As an SEO auditor, I want sitemap, robots.txt, and llms.txt to use the same canonical URL list so that audit reports do not show conflicting crawl-file results.

### Story 2 - AI search optimizer

As an AI search optimizer, I want llms.txt to include only canonical, indexable, useful URLs with human-readable descriptions so that LLMs can understand the site accurately.

### Story 3 - Automation developer

As an automation developer, I want preview and read endpoints for sitemap, llms.txt, robots.txt, metadata, and ALT text so that I can safely inspect state before applying changes.

### Story 4 - WordPress admin

As a WordPress admin, I want robots.txt to reference the RankRocket sitemap automatically so that search engines are directed to the preferred sitemap without manual edits.

## 5. Current Endpoint Matrix

| Endpoint | Method | Current status | Required status |
|---|---:|---|---|
| `/status` | GET | Partial | Add canonical sitemap index, llms, robots URLs, and diagnostics |
| `/update` | POST | Present | Keep |
| `/preview-update` | POST | Present | Keep |
| `/meta/bulk-update` | POST | Present | Keep batch cap |
| `/meta/bulk-get` | POST | Present | Keep |
| `/get/{id}` | GET | Present | Keep |
| `/schema/{post_id}` | GET/POST | Present | Keep |
| `/snippets` | GET/POST | Present | Keep |
| `/snippets/{id}` | GET/POST/DELETE | Present | Keep |
| `/snippets/replace-all` | POST | Present, gated | Keep gated, mark deprecated |
| `/images` | GET | Present | Keep |
| `/images/{id}/alt` | POST | Partial | Add GET |
| `/images/bulk-alt` | POST | Partial | Add batch cap |
| `/cache/purge` | POST | Present | Keep |
| `/sitemap/preview` | GET | Present | Must use shared Canonical URL Set |
| `/sitemap_index.xml` | GET | Present | Must use shared Canonical URL Set |
| `/rmb-sitemap.xml` | GET | Present | Legacy; must use shared Canonical URL Set |
| `/robots-txt` | GET/POST | Partial | Add auto sitemap directive support |
| `/llms` | GET/POST | Partial | Add exclusions, grouping, description fallback config |
| `/llms/preview` | GET | Missing | Add |
| `/llms.txt` | GET | Present | Must use shared Canonical URL Set |
| `rankmath-rest-bridge/v1` alias | All | Missing | Optional P2 compatibility |

## 6. Requirements

### P0 - Shared Canonical URL Set

Create a shared helper used by sitemap, llms.txt, sitemap preview, and any report/source export logic.

Suggested function names:

```php
rr_get_canonical_url_set( array $args = [] );
rr_is_url_allowed_for_discovery( WP_Post $post, array $context = [] );
rr_get_post_discovery_metadata( WP_Post $post );
```

The helper must return a normalized list of canonical, public, indexable URLs with metadata.

Each item should include:

```php
[
  'post_id' => 123,
  'post_type' => 'page',
  'title' => 'Residential Painting in Ypsilanti MI',
  'url' => 'https://example.com/services/residential-painting-services/',
  'canonical_url' => 'https://example.com/services/residential-painting-services/',
  'description' => '...',
  'lastmod' => '2026-04-29T16:22:27+00:00',
  'robots' => 'index,follow',
  'group' => 'service_pages'
]
```

Canonical URL Set inclusion rules:

- Published only.
- Public post types only, unless explicitly configured.
- Indexable only; exclude `noindex`.
- Exclude password-protected content.
- Exclude drafts, private posts, pending posts, trashed posts.
- Exclude redirects where known.
- Exclude URLs whose canonical points to another URL, unless replacing with the final canonical target.
- Exclude duplicate numeric suffix URLs such as `-2/`, `-3/`, `-4/` when a base URL exists.
- Exclude test placeholders such as `/please-do-not-delete-this-*`.
- Exclude utility pages by default.

Default utility exclusion patterns:

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

Acceptance criteria:

- Given a site has a canonical post and `-2` / `-3` duplicate posts, when the Canonical URL Set is generated, then only the canonical base URL is included.
- Given a page is set to `noindex`, when the Canonical URL Set is generated, then the URL is excluded from sitemap and llms.txt.
- Given a utility page matches the default exclusion list, when the Canonical URL Set is generated, then it is excluded unless explicitly included by config.
- Given sitemap and llms.txt are generated, when their URL lists are compared, then every primary llms.txt URL exists in the Canonical URL Set.

### P0 - Sitemap generation must use Canonical URL Set

All RankRocket sitemap outputs must use the shared helper:

- `/sitemap_index.xml`
- `/rmb-sitemap.xml`
- `/rmb-sitemap-posts.xml`
- `/rmb-sitemap-pages.xml`
- `/sitemap/preview`

Acceptance criteria:

- Given `/sitemap_index.xml` and `/sitemap/preview` are requested, when URLs are extracted, then both use the same canonical filtering rules.
- Given a URL is excluded from the Canonical URL Set, when any sitemap endpoint is requested, then the URL is absent.

### P0 - llms.txt generation must use Canonical URL Set

`/llms.txt` must use the same Canonical URL Set as the sitemap.

Default llms.txt structure:

```md
# {Business Name}

> {Short business summary}

Generated by RankRocket SEO Control Layer to help AI assistants understand the business, services, service area, and preferred canonical pages.

## Business Facts
Website:
Business:
Primary services:
Service area:
Phone:
Address:
Schema type:
Entity ID:

## Sitemaps
- [XML Sitemap]({preferred_sitemap_url}): Includes canonical, crawlable, indexable pages.

## Core Business Pages
- [Title](URL): Description.

## Service Pages
- [Title](URL): Description.

## Location Pages
- [Title](URL): Description.

## Educational Articles
- [Title](URL): Description.

## Utility Pages - not primary service sources
- [Title](URL): Description.

## Preferred AI Source Guidance
- Use the homepage, About page, Contact page, and service pages for business facts.
- Use service pages for service descriptions.
- Use location pages for city-specific relevance.
- Ignore duplicate, redirected, numeric suffix, utility, or noindex URLs.
- Do not infer services, locations, guarantees, credentials, or prices not stated on the website.
```

Description fallback order:

1. RankRocket SEO description.
2. WordPress excerpt.
3. First meaningful paragraph from post content.
4. Page title only, but return a warning in preview/status that the description is thin.

Acceptance criteria:

- Given a page has a RankRocket SEO description, when llms.txt is generated, then that description is used.
- Given a page lacks an SEO description but has an excerpt, when llms.txt is generated, then the excerpt is used.
- Given a page lacks description and excerpt but has content, when llms.txt is generated, then the first meaningful paragraph is used.
- Given a primary URL cannot produce a description, when llms preview is generated, then the URL is flagged as `thin_description`.
- Given duplicate suffix posts exist, when llms.txt is generated, then those duplicate URLs are absent.

### P0 - robots.txt sitemap directive synchronization

RankRocket should ensure robots.txt references the preferred RankRocket sitemap index.

Preferred directive:

```txt
Sitemap: https://example.com/sitemap_index.xml
```

Implementation options:

1. Auto-inject the directive into generated/filtered robots.txt output when missing.
2. Add a setting to choose the preferred sitemap URL and expose it through `/robots-txt`.

Acceptance criteria:

- Given robots.txt has no Sitemap directive, when RankRocket robots output is enabled, then a directive to `/sitemap_index.xml` is included.
- Given robots.txt has a WordPress core sitemap directive, when RankRocket is configured as preferred, then the RankRocket sitemap index is output instead or added according to config.
- Given a physical robots.txt file exists, when `/status` is requested, then `physical_robots_txt_exists` is true and the status warns that plugin-level output may be bypassed.

### P0 - Status endpoint improvements

Update `/status` response to expose discovery-file diagnostics.

Required fields:

```json
{
  "plugin": "RankRocket SEO Control Layer",
  "version": "2.7.0",
  "namespace": "rankrocket-seo/v1",
  "rankmath_active": false,
  "sitemap_index_url": "https://example.com/sitemap_index.xml",
  "legacy_sitemap_url": "https://example.com/rmb-sitemap.xml",
  "llms_url": "https://example.com/llms.txt",
  "robots_txt_url": "https://example.com/robots.txt",
  "physical_robots_txt_exists": false,
  "canonical_url_count": 42,
  "llms_url_count": 42,
  "excluded_url_count": 7,
  "warnings": []
}
```

Acceptance criteria:

- Given `/status` is requested by an authorized user, then the response includes sitemap index, legacy sitemap, llms, and robots URLs.
- Given physical robots.txt exists, then `/status` reports it.
- Given llms.txt and sitemap URL sets differ, then `/status` includes a warning.

### P1 - Add `GET /llms/preview`

Add a preview endpoint that returns generated llms.txt plus diagnostics before writing config.

Endpoint:

```txt
GET /wp-json/rankrocket-seo/v1/llms/preview
```

Optional query args:

```txt
?include_utility=false&format=text|json
```

JSON response shape:

```json
{
  "content": "# Example...",
  "url_count": 42,
  "sections": {
    "core_business_pages": 3,
    "service_pages": 8,
    "location_pages": 10,
    "educational_articles": 12,
    "utility_pages": 0
  },
  "excluded": [
    {
      "url": "https://example.com/example-2/",
      "reason": "duplicate_numeric_suffix"
    }
  ],
  "warnings": [
    {
      "url": "https://example.com/page/",
      "code": "thin_description",
      "message": "No SEO description, excerpt, or first paragraph found."
    }
  ]
}
```

Acceptance criteria:

- Given an admin requests `/llms/preview`, then no site state is changed.
- Given `format=text`, then endpoint returns plain generated llms.txt.
- Given `format=json`, then endpoint returns content and diagnostics.

### P1 - Expand `/llms` config

Current `/llms` GET/POST should support first-class canonical filtering and formatting config.

Suggested config:

```json
{
  "intro": "Optional intro paragraph.",
  "business_facts": {
    "business_name": "Example LLC",
    "primary_services": ["Service 1", "Service 2"],
    "service_area": ["City, ST"],
    "phone": "+1-555-555-5555",
    "address": "123 Main St, City, ST",
    "schema_type": "PaintingContractor",
    "entity_id": "https://example.com/#localbusiness"
  },
  "include_sitemaps": true,
  "include_lastmod": true,
  "include_meta_descriptions": true,
  "description_fallback": ["rrseo_description", "excerpt", "first_paragraph"],
  "exclude_noindex": true,
  "exclude_utility_pages": true,
  "exclude_patterns": ["-2/", "-3/", "/thank-you/", "/privacy-policy/", "/opt-out-preferences/"],
  "group_by_intent": true,
  "max_description_chars": 240,
  "sections": [
    "business_facts",
    "sitemaps",
    "core_business_pages",
    "service_pages",
    "location_pages",
    "educational_articles",
    "utility_pages",
    "ai_guidance"
  ]
}
```

Acceptance criteria:

- Given config includes `exclude_patterns`, when llms.txt is generated, then matching URLs are excluded.
- Given config includes business facts, when llms.txt is generated, then Business Facts section is populated.
- Given `group_by_intent` is true, when llms.txt is generated, then URLs are grouped by intent.

### P1 - Add `GET /images/{id}/alt`

Endpoint:

```txt
GET /wp-json/rankrocket-seo/v1/images/{id}/alt
```

Response:

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

Acceptance criteria:

- Given a valid media attachment ID, when GET is requested, then current ALT text is returned.
- Given an invalid ID, when GET is requested, then 404 is returned.

### P1 - Add batch cap to `/images/bulk-alt`

Use the same batch limit strategy as metadata bulk updates.

Acceptance criteria:

- Given more than the allowed batch size is submitted, then endpoint returns a validation error.
- Given allowed batch size or fewer, then endpoint processes the update.

### P2 - Backwards compatibility namespace alias

Register alias namespace:

```txt
rankmath-rest-bridge/v1
```

This alias should route to the same controllers as:

```txt
rankrocket-seo/v1
```

Acceptance criteria:

- Given an existing client calls `/wp-json/rankmath-rest-bridge/v1/status`, then the endpoint returns the same payload as `/wp-json/rankrocket-seo/v1/status`.
- Given alias usage occurs, response may include a deprecation warning.

## 7. Permissions and Security

Required permission model:

- All write endpoints require `manage_options`.
- Read endpoints that expose admin-only metadata or config require `manage_options`.
- Public files such as `/llms.txt`, `/sitemap_index.xml`, and `/rmb-sitemap.xml` remain public.
- Destructive `/snippets/replace-all` remains double-gated:
  - Requires `manage_options`.
  - Requires custom cap `rrseo_replace_all_snippets`.
  - Requires `confirm: true`.
  - Should return a deprecation warning.

## 8. Endpoint Contracts

### GET `/status`

Purpose: Return plugin health and discovery-file diagnostics.

Must include:

- Plugin name.
- Version.
- Namespace.
- RankMath active status.
- Preferred sitemap index URL.
- Legacy sitemap URL.
- llms.txt URL.
- robots.txt URL.
- Whether a physical robots.txt exists.
- Canonical URL count.
- llms URL count.
- Warnings for crawl-file mismatch.

### GET `/sitemap/preview`

Purpose: Return sitemap URL set and exclusions without relying on XML parsing.

Must include:

```json
{
  "canonical_urls": [],
  "excluded_urls": [],
  "counts": {},
  "warnings": []
}
```

### GET `/llms/preview`

Purpose: Preview generated llms.txt and diagnostics.

Must support text and JSON output.

### GET/POST `/llms`

Purpose: Read and write llms.txt config only.

Should not directly store a hard-coded complete URL list unless explicitly set as manual override.

### GET/POST `/robots-txt`

Purpose: Read and write robots.txt override/config.

Must support preferred sitemap directive behavior.

### GET/POST `/images/{id}/alt`

Purpose: Read and update media ALT text.

## 9. Validation Rules

The plugin should produce warnings for:

- `llms.txt` includes a URL not present in the Canonical URL Set.
- `llms.txt` includes a duplicate numeric suffix URL.
- `llms.txt` includes a utility URL in a primary section.
- Sitemap contains noindex URLs.
- robots.txt points to a non-preferred sitemap.
- Physical robots.txt may bypass plugin output.
- Any primary llms.txt URL has no useful description.

## 10. Test Plan

### Unit tests

1. Canonical helper excludes noindex posts.
2. Canonical helper excludes duplicate numeric suffix posts when base exists.
3. Canonical helper keeps orphan suffix posts only when no base exists and no canonical alternative exists.
4. Canonical helper excludes utility pages by default.
5. Description fallback uses SEO description first.
6. Description fallback uses excerpt second.
7. Description fallback uses first paragraph third.
8. llms.txt generator uses same URL list as sitemap generator.
9. robots.txt generator includes preferred RankRocket sitemap directive.
10. `/images/bulk-alt` rejects batches over the configured limit.

### Integration tests

1. Create published page, noindex page, utility page, canonical page, and duplicate `-2` post.
2. Request `/sitemap_index.xml`, `/rmb-sitemap.xml`, `/sitemap/preview`, and `/llms/preview`.
3. Confirm canonical page appears in all required outputs.
4. Confirm noindex, utility, and duplicate suffix URLs are excluded.
5. Confirm `/llms.txt` and sitemap URL sets match for primary URLs.
6. Confirm `/robots.txt` references `/sitemap_index.xml`.
7. Confirm `/status` reports counts and no mismatch warnings.

### Manual QA

For a live client site:

1. Fetch `/status`.
2. Fetch `/robots.txt`.
3. Fetch `/sitemap_index.xml`.
4. Fetch `/rmb-sitemap.xml`.
5. Fetch `/llms.txt`.
6. Compare URL sets.
7. Verify no `-2/` or `-3/` duplicate URLs.
8. Verify no utility pages in primary llms sections.
9. Verify every primary llms URL has a description.

## 11. Definition of Done

This work is complete when:

- Sitemap, sitemap preview, llms.txt, and llms preview use one shared Canonical URL Set.
- robots.txt references the preferred RankRocket sitemap index or clearly reports why it cannot.
- `/status` exposes sitemap, llms, robots, canonical counts, and warnings.
- `/llms/preview` exists.
- `/llms` config supports exclusions, grouping, and description fallback controls.
- `/images/{id}/alt` supports GET and POST.
- `/images/bulk-alt` has a batch cap.
- Tests prove duplicate suffix, noindex, redirect, utility, and placeholder URLs are excluded consistently.

## 12. Priority Summary

### P0

- Shared Canonical URL Set helper.
- Sitemaps use shared helper.
- llms.txt uses shared helper.
- robots.txt sitemap directive sync.
- `/status` diagnostics.

### P1

- `/llms/preview`.
- Expanded `/llms` config.
- `GET /images/{id}/alt`.
- Batch cap for `/images/bulk-alt`.

### P2

- Legacy namespace alias: `rankmath-rest-bridge/v1`.
- Optional advanced manual section overrides for llms.txt.
