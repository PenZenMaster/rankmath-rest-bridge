# RankRocket SEO Control Layer

WordPress plugin that is the native SEO control layer for the RankRocket
remediation pipeline. Manages title/meta, schema injection, image ALT text,
llms.txt, XML sitemap, robots.txt, cache purge, and self-updates. RankMath is
not required; `rank_math_*` post-meta is read as a migration fallback.

- **REST namespace:** `rankrocket-seo/v1`
- **Requires:** PHP 7.4+, WordPress 5.9+
- **Auth:** All write endpoints require `manage_options` capability via
  WordPress Application Passwords.

---

## Caching Compatibility

If your host runs a persistent page or object cache (SiteGround SuperCacher,
Redis Object Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache), you must
exclude the REST API path from full-page caching to avoid stale GET responses
after writes:

**SiteGround SuperCacher:** Exclude `/wp-json/` from Dynamic Cache.
**WP Rocket:** Add `/wp-json/(.*)` to the "Never Cache URL(s)" list.
**W3 Total Cache / LiteSpeed / Breeze:** Exclude `/wp-json/` from page cache rules.

`POST /cache/purge` flushes the WordPress object cache and all detected
plugin-level caches. Call it after any bulk operation if you need read-after-write
consistency without a page reload.

---

## REST API — Key Endpoints

All examples use:

```bash
BASE="https://example.com/wp-json/rankrocket-seo/v1"
CRED="admin:APP_PASSWORD"
```

### SEO Meta

#### `POST /update` — write or clear SEO meta for a post or term

Accepts a `post_id` that resolves to either a post/page or a taxonomy term.
The plugin detects the type automatically and writes to post meta or term meta
accordingly.

```bash
# Write title + description to a post
curl -X POST "$BASE/update" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{"post_id": 123, "title": "New Title", "description": "New description."}'

# Write term meta to a taxonomy term
curl -X POST "$BASE/update" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{"post_id": 21, "title": "Category Title", "description": "Category description."}'
```

Response includes `object_type: "post"` or `object_type: "term"` so callers
know which path was taken.

**Clearing fields with `unset_fields`**

To delete a previously-stored meta value, pass its name in the `unset_fields`
array. **Sending an empty string (`"title": ""`) is intentionally a no-op** —
empty strings are skipped so that pipeline templates that render a blank value
for a missing field don't accidentally wipe live SEO data. Use `unset_fields`
for explicit, deliberate deletion.

```bash
curl -X POST "$BASE/update" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{"post_id": 123, "unset_fields": ["title", "og_image"]}'
```

Valid field names: `title`, `description`, `focus_keyword`, `robots`,
`og_title`, `og_description`, `og_image`, `canonical`, `twitter_card`,
`twitter_title`, `twitter_description`, `twitter_image`.

Returns `422 invalid_unset_field` for unrecognised names (includes
`valid_fields` array). Returns `422 unset_write_conflict` if the same field
appears in both write params and `unset_fields`.

#### `GET /get/{id}` — read SEO meta

```bash
curl "$BASE/get/123" -u "$CRED"
```

#### `POST /preview-update` — dry-run diff (posts only)

Returns what would change without writing. Returns `422 term_not_supported`
for term IDs — use `POST /update` directly for term meta writes.

---

### Snippets

#### `GET /snippets` — list all snippets
#### `GET /snippets/{slug}` — fetch a single snippet by slug
#### `POST /snippets` — create a snippet
#### `POST /snippets/{slug}` — update a snippet
#### `POST /snippets/bulk` — atomic batch create (all-or-nothing validation)
#### `DELETE /snippets/{slug}` — delete a snippet

> `POST /snippets/replace-all` was removed in v3.0.0 (deprecated since v2.3.1).
> Use the per-snippet endpoints or `POST /snippets/bulk`; the
> `rrseo_replace_all_snippets` capability is revoked automatically on upgrade.

---

### llms.txt

#### `POST /llms-txt/regenerate` — invalidate cache and re-render

Forces a fresh render of the dynamic `/llms.txt` response. Returns
`line_count`, `byte_size`, and `regenerated` timestamp.

```bash
curl -X POST "$BASE/llms-txt/regenerate" -u "$CRED" \
  -H "Content-Type: application/json" -d '{}'
```

---

### Status

#### `GET /status` — plugin state snapshot

Returns version, routing vocabulary version (`emit_routing_version: 2` for
v2.13.0+), canonical/robots consolidation flags, snippet count, llms.txt URL,
and more. Useful for audit scripts and CI health checks.

---

### Observation (v2.18.0 — v3.0 Bite 1)

Read-only signals for the external Audit Engine. All require `manage_options`;
none mutate state; the plugin performs no external HTTP calls.

```bash
# Heading structure as a nested tree, with warnings (no_h1, multiple_h1, ...)
curl "$BASE/observe/heading-hierarchy/123" -u "$CRED"

# Link inventory: internal not_found/unverified + external unchecked
curl "$BASE/observe/broken-links?page=1&per_page=20&post_type=post" -u "$CRED"

# Image ALT coverage rollup by parent post type
curl "$BASE/observe/alt-coverage" -u "$CRED"

# Stored JSON-LD graph for a post
curl "$BASE/observe/schema-graph/123" -u "$CRED"

# Drift between llms.txt and the canonical URL set
curl "$BASE/observe/llms-diff" -u "$CRED"
```

External links are returned with `status_code: null` and `checked: false` —
external verification belongs to the Audit Engine, not the plugin.

---

### Self-Update

The plugin ships a headless self-update flow — no WP admin login required.
This makes it scriptable for CI/CD-style rollouts across multiple client sites.

```bash
# 1. Check what version is available
curl -X POST "$BASE/check-updates" -u "$CRED"
# {"success":true,"message":"Version 2.14.3 is available."}

# 2. Install it
curl -X POST "$BASE/self-update" -u "$CRED"
# {"success":true,"from_version":"2.14.2","to_version":"2.14.3","message":"..."}

# 3. Verify
curl "$BASE/status" -u "$CRED" | jq .version
# "2.14.3"
```

Total time: ~3 seconds. The plugin re-activates itself after installation.

---

## Snippet `display_on` Vocabulary

| Pattern | Fires on |
|---|---|
| `entire_website` | Every page |
| `front_page` | Homepage only |
| `singular` | All single posts/pages |
| `all_posts` | All posts |
| `all_pages` | All pages |
| `page_id:<int>` | Specific post/page by ID |
| `post_id:<int>` | Alias for `page_id:<int>` |
| `post_type:<slug>` | All posts of a given post type |
| `term_id:<int>` | Specific taxonomy term by ID |
| `term:<taxonomy>:<slug>` | Specific term archive by slug |
| `tax:<taxonomy>` | All archives for a taxonomy |

`url:/<path>` is reserved and currently returns `422`.

---

## White-Label Configuration

See [`docs/white-label-configuration.md`](docs/white-label-configuration.md).

---

## Release Checklist

```
1. Bump version in plugin header + RMB_VERSION constant
2. Update update-manifest.json — version + download_url
3. Update CHANGELOG.md
4. git add + git commit
5. .\bin\build-zip.ps1  (must pass all 4 structural checks)
6. git add releases/vX.Y.Z/ && git commit
7. git push
8. Wait 2-3 min, then POST /check-updates + POST /self-update on target site
```
