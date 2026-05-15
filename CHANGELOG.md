# Changelog

## v2.16.1

G-19/G-06: description fallback for page head, migrate-legacy token guard.

### New behaviour

- **G-19 — `<meta name="description">` fallback chain** — the `wp_head`
  description handler now falls back to WordPress excerpt and then to the
  first meaningful content paragraph when no explicit `rr_seo_description`
  is stored. The title-only fallback is intentionally excluded from the head
  output (a missing tag is preferable to a title duplicate). Fallback max
  length defaults to 155 chars and is filterable via `rrseo_excerpt_fallback_length`.
  Reuses the `rr_get_discovery_description()` fallback chain already in use
  for llms.txt and sitemap preview.

- **G-06 — `/migrate-legacy` template token guard** — fields whose RankMath
  legacy value contains a template token pattern (`%title%`, `%sitename%`,
  `%excerpt%`, `%sep%`, etc.) are now skipped instead of migrated verbatim.
  They appear in the response under `skipped` with
  `reason: "skipped_due_to_template_token"` and their raw value, so the
  caller knows exactly which fields need manual backfill. Dry-run mode
  surfaces the same information without writing.

---

## v2.16.0

G-04/G-05: Performance module — dequeue rules and script defer via REST.

### New behaviour

- **G-04 — `GET /perf/dequeue-rules`** — returns the current dequeue rule set
  and the `allowed_conditionals` whitelist.

- **G-04 — `POST /perf/dequeue-rules`** — replaces the stored rule set. Each
  rule: `{ handles: string[], type: "auto"|"script"|"style", when_not: string[] }`.
  `when_not` values must be in the allowed conditionals list (WordPress
  conditional function names: `is_front_page`, `is_home`, `is_woocommerce`,
  `is_cart`, `is_checkout`, `is_product`, etc. — full list in GET response).
  At `wp_enqueue_scripts:999`, each rule dequeues and deregisters its handles
  unless any `when_not` condition is true on the current page. Empty `when_not`
  fires on every page. Passing `rules: []` clears all rules.

- **G-05 — `GET /perf/defer-handles`** — returns the current defer handle list.

- **G-05 — `POST /perf/defer-handles`** — replaces the stored list. Adds a
  `defer` attribute to matching `<script>` tags via `script_loader_tag:10`.
  Skips tags that already contain `defer`. Passing `handles: []` clears all.

- **`/status`** — now includes `perf_dequeue_rules_count` and
  `perf_defer_handles_count`.

### Admin fix

- Page title in WP admin displayed as `RankRocket SEO \xe2\x80\x94 Overview`
  (raw UTF-8 bytes) due to a hex escape in a single-quoted PHP string.
  Replaced with ASCII ` - ` separator.

---

## v2.15.0

G-10/G-09/G-17: bulk snippet create, sitemap exclusion config, expanded placeholder patterns.

### New endpoints

- **G-10 — `POST /snippets/bulk`** — atomically creates multiple snippets in
  one request. Accepts `{"snippets":[{title, content, location, display_on,
  status}, ...]}`. Validates every item before writing; if any item fails,
  returns `422 validation_failed` with per-item `errors` array and saves
  nothing. On success returns `{success, count, snippets:[...]}`. Capped at
  `rrseo_batch_max()` (default 100).

- **G-09/G-17 — `GET /sitemap/exclusions`** — returns the current sitemap
  exclusion config. Default: all arrays empty.

- **G-09/G-17 — `POST /sitemap/exclusions`** — updates the exclusion config.
  All four arrays are optional (omitted keys are left unchanged). Calls
  `rr_invalidate_canonical_cache()` on every save.
  - `excluded_post_slugs` (array of strings) — takes effect immediately in
    `rr_is_url_allowed_for_discovery()`; matching posts are excluded from
    sitemaps and llms.txt with reason `excluded_post_slug`.
  - `excluded_term_ids`, `excluded_term_slugs`, `excluded_taxonomies` — stored
    now, will be respected when taxonomy archive support is added to the
    canonical URL set.

### Changes to exclusion logic (class-rrseo-canonical.php)

- **G-17 — expanded placeholder patterns** — `rr_is_url_allowed_for_discovery()`
  now rejects any post whose slug starts with `do-not-index-` in addition to
  the existing `please-do-not-delete-this-` prefix. Reason: `test_placeholder`.
- **G-09/G-17 — `excluded_post_slugs`** — `rr_is_url_allowed_for_discovery()`
  reads the `rrseo_sitemap_exclusions` option and rejects posts whose
  `post_name` matches any entry. Reason: `excluded_post_slug`.

---

## v2.14.4

G-16/G-18/G-13: register_post_meta, canonical-urls/preview alias, snippet hooks.

### New behaviour

- **G-16 — `register_post_meta` for all plugin-owned keys** — all 12
  `RR_SEO_META_KEYS` values (`rr_seo_title`, `rr_seo_description`, etc.) are
  now formally declared to WordPress core via `register_post_meta()` with
  `show_in_rest: true`, correct `sanitize_callback` per field type
  (`esc_url_raw` for URL fields, `sanitize_key` for `twitter_card`, and
  `sanitize_text_field` for the rest), and `auth_callback` requiring
  `manage_options`. `_rrseo_llms_section` registration expanded from
  specific post types to all post types (`''`). `_rrseo_schema_graph` and
  `_rrseo_change_log` registered with `show_in_rest: false` (internal).

- **G-18 — `GET /canonical-urls/preview`** — thin alias over the existing
  `/sitemap/preview` handler. Returns the same Canonical URL Set payload
  (canonical_urls, excluded_urls, counts, warnings) under the semantically
  correct endpoint name. `/sitemap/preview` remains available unchanged.

- **G-13 — Snippet emission action hooks** — `rmb_output_snippets()` now
  fires `do_action('rrseo_snippet_emitted', $snippet, $location)` after each
  successful emit, and `do_action('rrseo_snippet_skipped', $snippet, $reason,
  $location)` when a snippet is skipped. Reason values: `inactive`,
  `empty_content`, `display_on_mismatch`. The `$snippet` array always
  includes the snippet's `id` key. Enables mu-plugins and themes to build
  debug logs or observability tooling without patching core emitter code.

---

## v2.14.3

FU-1b/FU-4/FU-3/FU-5: line_count fix, REST fatal handler, README + term/self-update docs.

### Bug fixes

- **FU-1b — `line_count` off-by-one** — `POST /llms-txt/regenerate` reported
  `line_count` one higher than the actual line count when the rendered content
  ended with a trailing newline (normal case). Changed from
  `substr_count($content, "\n") + 1` to `substr_count($content, "\n")` to
  match `wc -l` convention.

### New behaviour

- **FU-4 — REST fatal handler** — `rrseo_rest_fatal_handler()` registered as a
  PHP shutdown function on `rest_api_init`. If a PHP fatal occurs during a REST
  request, it discards any buffered HTML error page and emits a clean
  `{"code":"internal_server_error",...}` JSON response with HTTP 500. Prevents
  the "critical error" HTML page from leaking through the REST envelope.

### Documentation

- **FU-3 — `/update` native term support** — `POST /update` accepts taxonomy
  term IDs via `post_id` and writes/reads `rr_seo_*` term meta directly.
  Response includes `object_type: "term"`. This was implemented in v2.14.0 but
  not surfaced in CHANGELOG or README.
- **FU-5 — `/check-updates` + `/self-update`** — headless self-update flow
  documented in README. `POST /check-updates` reports available version;
  `POST /self-update` downloads and activates the new zip (~3 seconds, no WP
  admin login required). Enables CI/CD-style rollouts across client sites.
- **README.md created** — REST API reference covering key endpoints, `display_on`
  vocabulary, self-update workflow, and release checklist.

---

## v2.14.2

FU-2: add `unset_fields` to `POST /update` — explicit meta deletion.

### Behaviour

- **`unset_fields` parameter on `POST /update`** — accepts an array of field
  name strings. Each named field is deleted from post meta or term meta
  (whichever applies to the resolved ID). Valid names are the same keys
  accepted by the write fields: `title`, `description`, `focus_keyword`,
  `robots`, `og_title`, `og_description`, `og_image`, `canonical`,
  `twitter_card`, `twitter_title`, `twitter_description`, `twitter_image`.
- Cleared fields appear in the `updated` response map with value `""` so
  callers can confirm what was removed.
- Returns HTTP 422 with `invalid_unset_field` if an unrecognised field name
  is supplied; response includes `valid_fields` array.
- Returns HTTP 422 with `unset_write_conflict` if the same field appears in
  both the write fields and `unset_fields`.
- Write/delete are fully audited via `rr_audit_log()` with `before`/`after`
  values.

---

## v2.14.1

Fix G-12 fatal: `POST /llms-txt/regenerate` returned HTTP 500 on every call.

### Bug fix

- **G-12 hotfix** — `rmb_llms_regenerate()` assigned the array returned by
  `rr_render_llms_txt()` directly to `$content` then passed it to
  `substr_count()` and `strlen()`, both of which expect a string, causing a PHP
  fatal. Fixed by unpacking `$result['content']` before those calls.

---

## v2.14.0

G-01 gate lifted; post_id: alias restored; G-02/03/08/11/12 implemented.

### Behaviour

- **Lift G-01 gate** — `term:<taxonomy>:<slug>` and `tax:<taxonomy>` `display_on`
  patterns are now accepted at write time. Emitter code was proven working on a
  live term archive in v2.13.1 validation. `url:/<path>` remains gated.
- **Restore `post_id:` alias** — `post_id:<int>` now accepted in `display_on`
  and in the emitter as an alias for `page_id:<int>`. Was dropped in v2.13.1;
  restored per dev spec T-02.
- **G-02 — `/preview-update` term guard** — returns HTTP 422 with error code
  `term_not_supported` when `post_id` resolves to a taxonomy term. Use
  `POST /update` directly for term meta writes.
- **G-03 — `consolidate_canonical` in `/status`** — new boolean field reading
  option `rrseo_consolidate_canonical` (default `true`). Mirrors the existing
  `consolidate_wp_robots` field.
- **G-08 — `emit_routing_version` in `/status`** — new integer field, value `2`,
  signals that the v2.13.0+ routing vocabulary (term/tax/term_id patterns) is
  active.
- **G-11 — `GET /snippets/<slug>`** — returns the full snippet record for a
  single slug, or 404 if not found. Collection `GET /snippets` unchanged.
- **G-12 — `POST /llms-txt/regenerate`** — invalidates the canonical URL set
  transient and re-renders llms.txt. Response: `success`, `url`, `line_count`,
  `byte_size`, `regenerated` (mysql timestamp).

---

## v2.13.1

Gate unimplemented `display_on` patterns; fix title and description whitespace.

### Bugs fixed

- **Silent snippet failures** — `term:<taxonomy>:<slug>` and `tax:<taxonomy>`
  `display_on` patterns were accepted (HTTP 200) but never fired on taxonomy
  archive pages. They now return HTTP 422 with error code `invalid_display_on`,
  a `hint` field, and an `accepted_patterns[]` list. `url:/<path>` receives the
  same treatment. `term_id:<int>` remains accepted; its emission path was not
  flagged in live testing.
- **Leading space in `<title>`** — when RankMath is deactivated and
  `rr_override_document_title()` resolves a stored SEO title, a template with a
  leading space (e.g., ` %title% | %sitename%`) produced `<title> Site Title |
  …</title>`. Fixed by trimming the output of `rmb_resolve_tokens()`.
- **Double space in `<meta name="description">`** — empty `%excerpt%` token
  substitution left adjacent spaces in the stored template, producing
  `"leading  double-space"`. Fixed by collapsing runs of spaces/tabs to a single
  space in `rmb_resolve_tokens()` output.

---

## v2.13.0

Taxonomy archive SEO support (G-01 write-path, G-02 term meta, G-08 validation).

### Behaviour

- **Taxonomy routing in `display_on`**: emitter now handles `term:<taxonomy>:<slug>`,
  `term_id:<int>`, `tax:<taxonomy>`, and `url:/<path>` patterns in
  `rmb_snippet_matches_display()` via `is_category()`, `is_tag()`, `is_tax()`,
  and `$_SERVER['REQUEST_URI']` path comparison.
- **Term meta read/write**: `/update` and `/get/{id}` resolve the supplied ID
  via `rr_resolve_id()` and route writes to `update_term_meta()` / reads from
  `rr_get_term_seo_meta()` when the ID maps to a `WP_Term`. Response adds
  `object_id` and `object_type` fields; `post_id` retained as a
  backward-compatible alias.
- **Taxonomy archive tag emission**: `rr_override_document_title()` and
  `rr_merge_wp_robots()` extended for `rr_is_any_tax_archive()`. Two new
  `wp_head` priority-1 closures emit `<meta name="description">`, OG tags, and
  `<link rel="canonical">` on category/tag/custom taxonomy archives from stored
  term meta, falling back to native term description.
- **`display_on` validation on write**: POST /snippets and POST /snippets/{id}
  now validate the `display_on` field against the write-permitted pattern set
  and return HTTP 422 with `accepted_patterns[]` on mismatch (G-08).

---

## v2.12.2

P6 — Snippet renderer covers the targeting vocabulary RankRocket actually sends
and the operational guards live sites need.

### Bug

On linkonlogsportables.com running v2.11.6, `POST /snippets` successfully
created an active head snippet with `display_on: sitewide` and a valid
`<script type="application/ld+json">` body, but the rendered HTML contained
zero `application/ld+json` matches after cache purge. The previous switch
inside `rmb_output_snippets()` handled `all` and `entire_website` but not
`sitewide`, so the snippet fell through the default branch and emitted nothing.

### Behaviour

- **Targeting**: add `sitewide`, `singular`, `post_type:slug`. Preserve
  the legacy values `all`, `entire_website`, `home`, `homepage`,
  `front_page`, `all_pages`, `all_posts`, `page_id:NNN`, and bare integer.
  Unknown values are silently skipped so the renderer stays forward
  compatible with new RankRocket targeting strings.
- **Locations**: `head` now fires at `wp_head:20` (after the
  canonical/Twitter/robots emission added in v2.11.3 at priority 1). New
  `body_open` location fires at `wp_body_open:10`. `footer` continues to
  emit at `wp_footer:10`. Unknown locations are silently skipped.
- **Output**: each snippet is wrapped in
  `<!-- rrseo:snippet id="ID" -->...<!-- /rrseo:snippet -->` for
  debuggability. Content is echoed verbatim — no `esc_html` or
  `wp_kses_post` — because JSON-LD `<script>` bodies must not be stripped.
- **Operational guards**: short-circuits on `is_admin()`, `REST_REQUEST`,
  and `wp_doing_ajax()` so snippet bodies never land inside admin screens
  or JSON API responses.

### New option

- `rrseo_emit_snippets` — boolean, default `true`. Set to `false` to
  killswitch all emission without deleting snippets. Surfaced in `/status`
  as `emit_snippets`.

### New filter

- `rrseo_render_snippets( bool $emit )` — per-request override.
  Themes/mu-plugins can `return false` to suppress emission on a specific
  template (maintenance pages, AMP shells, etc.).

### Internal

- Targeting logic extracted to `rmb_snippet_matches_display()` so the
  matcher is unit-testable and the main renderer reads as a linear loop.

---

## v2.12.1

Raise default batch cap and expose a filter for per-site tuning.

### Changes

- `RR_BATCH_MAX` raised from `20` to `100`.
- New `rrseo_batch_max()` helper wraps `apply_filters( 'rrseo_batch_max', RR_BATCH_MAX )`.
  All four bulk endpoints (`/meta/bulk-get`, `/meta/bulk-update`,
  `/images/bulk-alt`, `/migrate`) now enforce `rrseo_batch_max()` instead of
  the bare constant, so the limit can be changed at runtime without touching
  plugin code.
- Admin JS `per_page` query param now reads `rrseo_batch_max()` via
  `wp_localize_script` so it stays in sync with the server-side cap.

To override (add to a mu-plugin or `wp-config.php`):
```php
add_filter( 'rrseo_batch_max', function() { return 200; } );
```

---

## v2.12.0

White-label support — Tier 1 (rename) and Tier 2 (hide) via `wp-config.php` constants.

### New feature

A new `RRSEO_White_Label` class (`includes/class-rrseo-white-label.php`) is
loaded in the admin and wires up two WordPress filters.

**Tier 1 — Rename** (swap branding, plugin still visible in Plugins screen):

- `RRSEO_WL_NAME` — Plugin name in Plugins list and admin sidebar/page headings
- `RRSEO_WL_DESCRIPTION` — Plugin description in Plugins list
- `RRSEO_WL_AUTHOR` — Author name in Plugins list
- `RRSEO_WL_AUTHOR_URL` — Author URL in Plugins list
- `RRSEO_WL_SUPPORT_URL` — Appends a custom Support link to the plugin row meta

**Tier 2 — Hide** (remove plugin from Plugins screen entirely):

- `RRSEO_WL_HIDE_PLUGIN` (bool `true`) — Removes plugin entry from Plugins
  screen; deactivation warning script is also suppressed.

All constants are optional. When none are defined, default RankRocket branding
is used unchanged. Define constants in `wp-config.php` only — they cannot be
overridden from the database or settings UI.

Admin menu titles and all six settings-page headings now read the WL name via
`RRSEO_White_Label::wl_name()`. Deactivation warning dialog injects the WL
name dynamically via `wp_json_encode`.

---

## v2.11.6

Accept `code` as an alias for `content` in snippet write endpoints.

### Fix

- `POST /snippets` — `code` is now an accepted optional field alongside
  `content`. If neither is provided a `400 missing_content` error is returned
  with a message naming both accepted field names. `content` was previously
  marked `required` in the args schema, which rejected callers sending `code`.
- `POST /snippets/{id}` — update handler now falls back to the `code` param
  when `content` is absent.
- `POST /snippets/replace-all` — each item in the `snippets` array now falls
  back to `item['code']` when `item['content']` is absent.

---

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
