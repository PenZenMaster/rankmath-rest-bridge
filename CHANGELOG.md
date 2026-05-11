# Changelog

## v2.11.5

Fix exclusion pattern case normalization in `rr_is_utility_url()`.

### Fix

- Patterns were being lowercased on every `strpos()` call inside the loop
  (`strtolower( (string) $pattern )`). Now lowercased once when building
  `$active` via `array_map( 'strtolower', array_map( 'strval', ... ) )`,
  so both the path (lowercased by `rr_normalize_url_path()`) and patterns
  share the same lowercase invariant before comparison.
- Updated `rr_normalize_url_path()` docblock to accurately document that
  exclusion patterns are also normalized to lowercase, not used as-is.

---

## v2.11.4

Fix sitemap index lastmod consistency. `rmb_serve_sitemap_index()` previously
derived its per-sub-sitemap `<lastmod>` values from raw `get_posts()` queries
that returned the most recently modified post/page regardless of whether that
post was actually included in the child sitemap. This meant the index could
show a timestamp ahead of the child sitemap's actual newest entry when utility
pages, noindex posts, or test placeholders were the last-modified items.

### Fix

- Replace two raw `get_posts()` calls in `rmb_serve_sitemap_index()` with a
  single `rr_get_canonical_url_set()` call (the same filtered set that
  `rmb_serve_sitemap_type()` uses). Lastmod is now `max()` over the included
  URL entries only, guaranteeing index and child sitemap timestamps are
  consistent.

---

## v2.11.3

Surfaces in the live SEO audit on linkonlogsportables.com identified four head/robots gaps. This release closes all four with conservative defaults and gating options so behaviour can be reverted per-site.

### Features

- **P1 — Canonical URL emission to `<head>`.** New `wp_head` callback emits `<link rel="canonical" href="...">` for singular posts/pages/products in `allowed_post_types`. Source precedence: per-post `_rr_seo_canonical` override → `rank_math_canonical_url` legacy fallback → `get_permalink()`. Suppressed when the post robots meta includes `noindex` or when the `rrseo_emit_canonical` filter returns false. New `rrseo_canonical_url` filter allows external override. New `canonical` field accepted by `/update`, `/preview-update`, and `/meta/bulk-update` (sanitized with `esc_url_raw`). `/get/{id}` returns `rr_seo_canonical` in its `meta` payload.
- **P2 — Twitter Card emission.** New `wp_head` callback emits `twitter:card` (default `summary_large_image`), `twitter:title`, `twitter:description`, and `twitter:image`. Per-post values stored as `_rr_seo_twitter_card`, `_rr_seo_twitter_title`, `_rr_seo_twitter_description`, `_rr_seo_twitter_image`; when unset they fall back to the OG fields, then `rr_seo_*`, then post title / excerpt / featured image. Accepted by `/update`, `/preview-update`, and `/meta/bulk-update`; returned by `/get/{id}` under aliased keys. New `rrseo_emit_twitter_cards` filter.
- **P3 — robots.txt auto-sync.** The `robots_txt` filter now strips any `Sitemap:` directive pointing to WordPress core's `/wp-sitemap.xml` and appends `Sitemap: {site}/sitemap_index.xml` so the dynamic robots.txt agrees with the RankRocket sitemap. Skipped when a custom `rrseo_robots_txt` body is stored (the explicit POST `/robots-txt` content still wins). Gated by the `rrseo_robots_txt_auto_sync` option (default `true`).
- **P5 — `wp_robots()` consolidation.** New `wp_robots` filter callback merges per-post `rr_seo_robots` directives into the associative array WordPress core renders, so only one `<meta name="robots">` tag is emitted per page. `max-image-preview:large` and any other directives added by core or other plugins are preserved. The standalone RankRocket `<meta name="robots">` echo from the existing `wp_head` block is suppressed while consolidation is active. Gated by `rrseo_consolidate_wp_robots` (default `true`).

### Status endpoint

`GET /status` now reports `robots_txt_auto_sync` and `consolidate_wp_robots` so the audit pipeline can confirm gating state without inspecting `wp_options`.

### New filters

- `rrseo_emit_canonical( bool $emit, int $post_id )` — return `false` to suppress canonical emission for a given post.
- `rrseo_canonical_url( string $url, int $post_id )` — final override of the canonical href.
- `rrseo_emit_twitter_cards( bool $emit, int $post_id )` — return `false` to suppress Twitter Card emission.

### New options

- `rrseo_robots_txt_auto_sync` — default `true`. Set to `false` to disable the dynamic `Sitemap:` rewrite.
- `rrseo_consolidate_wp_robots` — default `true`. Set to `false` to restore the two-tag legacy behaviour.

### New per-post meta keys

- `_rr_seo_canonical` — canonical URL override.
- `_rr_seo_twitter_card` — `summary` | `summary_large_image` | `app` | `player`.
- `_rr_seo_twitter_title`
- `_rr_seo_twitter_description`
- `_rr_seo_twitter_image`
