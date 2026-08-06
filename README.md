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

### Schema (JSON-LD)

#### `GET /schema/{post_id}` — read the stored JSON-LD schema

```bash
curl "$BASE/schema/123" -u "$CRED"
```

Returns whatever was stored: a single node object, or a `@graph` envelope
for multi-node writes. `schema: null` if nothing has been written yet.

#### `POST /schema/{post_id}` — validate and store JSON-LD schema

Accepts any of three shapes in the `schema` field. All three validate each
node's `@type` against the allowed list and normalize to a canonical
`@graph` envelope on write, except a single node, which is stored exactly
as received.

**1. Single node** (unchanged since v3.0.0):

```bash
curl -X POST "$BASE/schema/123" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{"schema": {"@context": "https://schema.org", "@type": "Service", "name": "Bookkeeping"}}'
```

**2. Bare array of nodes** — wrapped into a `@graph` envelope with
`@context` defaulted to `https://schema.org`:

```bash
curl -X POST "$BASE/schema/123" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{"schema": [
        {"@type": "Service", "name": "Bookkeeping"},
        {"@type": "BreadcrumbList", "itemListElement": []}
      ]}'
```

**3. `@graph` envelope** (preferred canonical form for multi-node writes):

```bash
curl -X POST "$BASE/schema/123" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{"schema": {
        "@context": "https://schema.org",
        "@graph": [
          {"@type": "Service", "name": "Bookkeeping"},
          {"@type": "FAQPage", "mainEntity": []}
        ]
      }}'
```

Provider references between nodes (e.g. `Service.provider` -> a
`LocalBusiness.@id` on another page) are stored verbatim — the caller is
responsible for keeping `@id` values consistent across nodes and pages.

Optional `dry_run: true` validates without writing.

**Errors:**

- `422 validation_failed` — a node's `@type` isn't in the allowed list, a
  required field is missing, or a `@graph` array is empty. The `errors`
  array is per-node for multi-node payloads (e.g. `schema: @graph[1] @type
  'Widget' not allowed. Allowed: ...`).
- `413 validation_failed` — more than 20 nodes in one graph (raise via the
  `rrseo_schema_graph_max_nodes` filter).

The `wp_head` emitter (priority 5) outputs whatever is stored verbatim in
one `<script type="application/ld+json">` tag — no separate rendering path
for single-node vs. graph.

---

### Media

#### `POST /media` — audited media-upload wrapper

Wraps `media_handle_sideload()` with the plugin's validation/audit-log
conventions. `multipart/form-data`, not JSON.

```bash
curl -X POST "$BASE/media" -u "$CRED" \
  -F "file=@hero.webp" \
  -F "alt_text=Downtown Peru, IL along Route 6" \
  -F "source=client_supplied" \
  -F "attach_to_post_id=2090"
```

| Field | Type | Notes |
|---|---|---|
| `file` | binary | Required. |
| `alt_text` | string | Required, non-empty — no empty ALT text is ever written. |
| `source` | string | Required. One of `ai_placeholder`, `client_supplied`, `reused`, `stock`, `screenshot`. Stored as `_rrseo_media_source`. |
| `is_placeholder` | bool | Default `false`. If `true`, `source` must be `ai_placeholder`. Stored as `_rrseo_media_placeholder`. |
| `filename` | string | Optional — overrides the uploaded file's name. |
| `title`, `caption` | string | Optional. |
| `attach_to_post_id` | int | Optional — sets `post_parent`; also required for an audit-log entry (audit log is per-post meta, so unattached library uploads aren't logged). |
| `dry_run` | bool | Default `false`. Validates MIME/size/alt_text/source without uploading. |

MIME allowlist: `image/jpeg`, `image/png`, `image/webp`, `image/avif`
(checked against the real file content via `wp_check_filetype_and_ext()`,
not the client-supplied header) — `415` on anything else. Size cap 10 MB —
`413` if exceeded. `422` on missing/empty `alt_text`, an unrecognized
`source`, or `is_placeholder:true` with a non-`ai_placeholder` source.

**Response (201):**

```json
{
  "media_id": 4712,
  "source_url": "https://kildaybaxter.com/wp-content/uploads/2026/08/hero.webp",
  "mime_type": "image/webp",
  "bytes": 84210,
  "width": 1600,
  "height": 900,
  "alt_text": "Downtown Peru, IL along Route 6",
  "meta": { "_rrseo_media_source": "client_supplied", "_rrseo_media_placeholder": false }
}
```

#### `GET /media/placeholders` — list AI-placeholder media

Returns every attachment where `_rrseo_media_placeholder` is set, for the
manual-replace checklist page-builder workflows generate per rollout.

```bash
curl "$BASE/media/placeholders" -u "$CRED"
```

---

### Elementor

#### `POST /elementor/set-data` — write an Elementor page's element tree

Writes `_elementor_data`, `_elementor_edit_mode`, and
`_elementor_template_type` via REST, for programmatic page-builder
workflows that would otherwise have to fall back to plain Gutenberg
HTML + a manual "Edit with Elementor" conversion per page.

```bash
curl -X POST "$BASE/elementor/set-data" -u "$CRED" \
  -H "Content-Type: application/json" \
  -d '{
    "post_id": 2090,
    "elementor_data": [
      {
        "id": "sec1", "elType": "section", "settings": {},
        "elements": [
          { "id": "col1", "elType": "column", "settings": {},
            "elements": [
              { "id": "w1", "elType": "widget", "widgetType": "heading", "settings": {} }
            ]
          }
        ]
      }
    ]
  }'
```

| Field | Type | Notes |
|---|---|---|
| `post_id` | int | Required. |
| `elementor_data` | array | Required. Top-level array of `section`/`container` nodes, each with `elType`, `elements` (array), `id`, `settings` (object). Stored as a JSON string in `_elementor_data`, matching Elementor's own storage format. |
| `edit_mode` | string | Default `builder`. |
| `template_type` | string | Default `wp-page`. Common values: `wp-page`, `wp-post`, `section`, `page`, `landing-page`. |
| `css_print_method` | string | Optional — merged into `_elementor_page_settings.css_print_method` if provided. |
| `dry_run` | bool | Default `false`. Validates and returns `widget_count`/`elementor_data_bytes` without writing. |

**Validation:** `422 validation_failed` if the top-level shape is wrong
(not an array, empty, or a node missing `elType`/`elements`/`id`/`settings`).
Deeper widget-level content isn't shape-validated — only the section/
container/column skeleton is. If a nested `widgetType` isn't in the
plugin's best-effort core-widget list, the write still succeeds; a
non-blocking warning is returned instead (e.g. `widgetType 'pro-forms' is
not in the known Elementor core list -- may require Elementor Pro or a
third-party addon`). That list isn't authoritative — extend it via the
`rrseo_elementor_core_widget_types` filter if you get false positives.

**Response (200):**

```json
{
  "post_id": 2090,
  "elementor_data_bytes": 8421,
  "widget_count": 12,
  "edit_mode": "builder",
  "template_type": "wp-page",
  "css_regenerated": true,
  "warnings": []
}
```

After a successful write, Elementor's CSS cache is cleared via
`\Elementor\Plugin::$instance->files_manager->clear_cache()` (a global
clear, not page-specific) so the next front-end render doesn't serve
stale CSS — `css_regenerated` reports whether that ran (`false` if
Elementor isn't active on the site).

---

### Snippets

#### `GET /snippets` — list all snippets
#### `GET /snippets/{slug}` — fetch a single snippet by slug
#### `POST /snippets` — create a snippet
#### `POST /snippets/{slug}` — update a snippet
#### `POST /snippets/bulk` — atomic batch create (all-or-nothing validation)
#### `DELETE /snippets/{slug}` — delete a snippet

**`priority` field (v3.2.0+)** — optional integer `0`–`10000` on all snippet
write endpoints; maps to WordPress hook priority for the snippet's location
hook. Omitted = current defaults (`wp_head:20`, `wp_body_open:10`,
`wp_footer:10`). Use a low priority to emit before theme-enqueued assets —
required for render-blocking-CSS mitigations to work:

```json
{
  "title": "Async external CSS (perf)",
  "code": "<link rel=\"preload\" as=\"style\" href=\"...\" onload=\"this.onload=null;this.rel='stylesheet'\">",
  "location": "head",
  "display_on": "entire_website",
  "priority": 1
}
```

**`code_b64` field (v3.3.0+)** — optional base64-encoded alternative to
`code`/`content` on all snippet write endpoints; wins when both are present.
Use it if your host sits behind Cloudflare or another WAF that inspects
request bodies for HTML event handlers: managed XSS rules 403 any body
containing `on*=` patterns, which blocks legitimate preload/onload perf
snippets. Base64 hides the attribute pattern during transport only — the
snippet is decoded on receipt, stored as plain HTML, and returned decoded by
all read endpoints. Invalid base64 (or non-UTF-8 content) is rejected with
422 `invalid_base64`.

```bash
# Python: base64.b64encode(html.encode()).decode()  |  JS: btoa(html)
curl -X POST "$BASE/snippets" -u "$CRED" -H "Content-Type: application/json" \
  -d '{"title":"Async external CSS (perf)","location":"head","priority":1,
       "display_on":"entire_website",
       "code_b64":"PGxpbmsgcmVsPSJwcmVsb2FkIiBhcz0ic3R5bGUiIGhyZWY9Ii4uLiIgb25sb2FkPSJ0aGlzLnJlbD0nc3R5bGVzaGVldCciPg=="}'
```

Common WordPress `wp_head` priorities for targeting:

| Priority | What runs there | Use case |
|---|---|---|
| `1` | Plugin canonical/robots emission; before nearly everything | Preloads, critical CSS inlining |
| `5` | `wp_resource_hints` (dns-prefetch) | Additional preconnect hints |
| `7` | `wp_preload_resources` (WP 6.1+) | LCP image preload |
| `8` | `wp_enqueue_scripts` fires | Theme/plugin styles register here |
| `10` | Default action priority | Most theme/plugin output |
| `20` | Snippet default (unchanged) | General head snippets |
| `100+` | Late injection | Analytics, tracking pixels |

> `POST /snippets/replace-all` was removed in v3.0.0 (deprecated since v2.3.1).
> Use the per-snippet endpoints or `POST /snippets/bulk`; the
> `rrseo_replace_all_snippets` capability is revoked automatically on upgrade.

---

### llms.txt

#### `GET /llms` / `POST /llms` — llms.txt configuration

`POST /llms` accepts top-level fields matching the config option shape —
there is no `config` or `data` wrapper; send the fields directly in the
request body. All fields are optional; omitted fields are left unchanged.

| Field | Type | Notes |
|---|---|---|
| `intro` | string | Free text inserted after the header. |
| `sections` | object | URL classifier config — see `GET /llms/preview`. |
| `custom_sections` | array | Legacy `{heading, items}` text blocks. |
| `exclude_patterns` | array of string | URL path prefixes to omit. |
| `schema_source_post_id` | int | Post to pull LocalBusiness/Organization schema from. |
| `include_sitemaps` / `include_lastmod` / `exclude_noindex` / `exclude_utility_pages` / `group_by_intent` | bool | Renderer toggles. |
| `max_description_chars` | int | Per-URL description truncation length. |
| `business_facts` | object | Site-wide business identity — see below. |

**`business_facts`** is the write path for the curated business identity
block (v3.4.0, issues #9/#10) — the fields AI assistants and the
`/aeo-geo/*` readiness scorer consume.

**Writes are merged, not replaced (v3.4.1, issue #11)** — an incoming
`business_facts` object is merged key-by-key onto the stored value. Keys
you send overwrite the stored value (array-type fields like
`primary_services` or `common_questions` replace wholesale when sent, they
do not append); keys you omit are left untouched. This means true partial
updates work — e.g. sending only `{"business_facts": {"email": "..."}}`
adds `email` and leaves `phone`, `primary_services`, `common_questions`,
etc. exactly as they were. Validation runs against the **merged** result,
so `business_name`/`description` only need to already exist from a prior
write — they don't have to be repeated on every partial update. The first
write for a site must still include both. Sending `business_facts: {}` is
now a no-op (it no longer clears the stored value back to schema/bloginfo
resolution); clear an individual field by sending it with an empty value
(e.g. `"phone": ""`).

`business_name` and `description` are required in the resulting (merged)
object whenever `business_facts` is non-empty; every other key is
optional. Strings are capped at 5000 characters, arrays at 50 items.
Invalid payloads return `422 invalid_business_facts` with a `data.errors`
array — nothing is silently dropped.

```bash
curl -X POST "$BASE/llms" -u "$CRED" -H "Content-Type: application/json" -d '{
  "business_facts": {
    "business_name": "Kilday Baxter & Associates",
    "description": "Full-service CPA firm in Oglesby, Illinois.",
    "tagline": "Accounting and tax services for the Illinois Valley",
    "phone": "815-883-3500",
    "address": "755 W. Walnut Street, Oglesby, IL 61348",
    "hours": "Monday-Friday 8:00 AM - 6:00 PM. Saturday by appointment.",
    "years_in_business": "30+",
    "primary_services": ["Bookkeeping", "Payroll", "Tax Preparation"],
    "service_area": ["Oglesby, IL", "LaSalle, IL", "Peru, IL"],
    "key_differentiators": ["CPAs on staff", "30+ years serving the Illinois Valley"],
    "common_questions": [
      {"question": "Do I need a CPA or is a bookkeeper enough?",
       "answer": "It depends on the complexity of your finances..."}
    ]
  }
}'
```

Once written, `business_facts` renders into `/llms.txt` as a **Business
Facts** block plus a **Common Questions** Q&A block — by default right
after the intro, or in its configured position if `sections.business_facts`
is set. This happens automatically; no separate publish step is needed
beyond `POST /llms-txt/regenerate` to bust the canonical-URL cache if the
site's URL set also changed.

If `business_facts` is omitted from a write, identity data falls back to
`schema_source_post_id` schema, then homepage `LocalBusiness`/`Organization`
schema, then WordPress `bloginfo()` (site name/URL only — least complete).

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

#### `GET /capabilities` — route inventory and version manifest

**Recommended as the first call for any new integration.** Returns a
machine-readable capability map so a consumer can probe what this plugin
build supports in one round trip, instead of per-route `OPTIONS` calls or
trial-and-error `POST`s that can't distinguish "route doesn't exist" from
"route exists but silently no-ops."

```bash
curl "$BASE/capabilities" -u "$CRED"
```

```json
{
  "plugin_version": "3.7.0",
  "plugin_namespace": "rankrocket-seo/v1",
  "wp_version": "6.6.2",
  "elementor_active": true,
  "elementor_pro_active": true,
  "rank_math_active": false,
  "capabilities": {
    "seo.meta.update": { "available": true, "route": "POST /update", "since": null },
    "schema.write.graph": { "available": true, "route": "POST /schema/{post_id} (bare array or @graph envelope)", "since": "3.5.0" },
    "media.upload": { "available": true, "route": "POST /media", "since": "3.6.0" },
    "elementor.set_data": { "available": true, "route": "POST /elementor/set-data", "since": "3.7.0" }
  },
  "allowed_schema_types": ["LocalBusiness", "Service", "BreadcrumbList", "..."],
  "audit_log_enabled": true
}
```

Capability keys are stable, dotted identifiers (`schema.write.graph`, not
a URL) — key off these, not route paths, since routes can change shape
across versions while the feature identifier stays put. `since` is `null`
for routes that predate this plugin's earliest tracked CHANGELOG entry
(v2.11.3); the exact introducing version isn't reliably known, so it's
left unset rather than guessed. Pure read, no side effects; response
carries `Cache-Control: public, max-age=60`.

**Known host caveat:** on WP Engine, this header may still arrive as
`max-age=0, must-revalidate, private` regardless of plugin version —
WP Engine's edge layer force-rewrites `Cache-Control` on any request it
detects as authenticated (visible via its own `X-Pass-Why: auth`
response header), overriding the origin's header entirely outside PHP.
Not a plugin bug; nothing to fix on this side. Confirmed correct
(`public, max-age=60` passes through untouched) on non-WP-Engine hosts.

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

### AEO / GEO (v2.10.0; scoring updated v3.4.0)

Read-only AI/answer-engine-optimization signals for the external Audit
Engine. All require `manage_options`. The plugin has no knowledge of
whether GSC/GA4/GBP connectors are active elsewhere, so `data_depth_badge`
is always `public-only`.

```bash
# Canonical URL set with sitemap/llms/schema membership flags
curl "$BASE/canonical-urls/preview" -u "$CRED"

# Top-level readiness snapshot (scores + signals) — see rubric below
curl "$BASE/aeo-geo/readiness" -u "$CRED"

# Resolved entity signals (business_facts -> schema_source_post_id -> homepage schema -> bloginfo)
curl "$BASE/aeo-geo/entity" -u "$CRED"

# Per-URL schema type inventory + missing-opportunity flags
curl "$BASE/aeo-geo/schema-audit" -u "$CRED"

# Three-way sync check: canonical URL set vs XML sitemap vs llms.txt
curl "$BASE/aeo-geo/source-sync" -u "$CRED"
```

**`GET /aeo-geo/readiness` scoring rubric** — four 0-100 sub-scores,
averaged into `overall`:

| Score | Formula |
|---|---|
| `entity_clarity` | +25 each for non-empty `business_name`, `phone`, `address`, `schema_type`; +10 if `entity_id` set; -10 per resolver warning. Floor 0. |
| `canonical_source_guidance` | Equals `source_sync.sync_score` (percent of canonical URLs present in both the XML sitemap and llms.txt). |
| `schema_depth` | Schema coverage percent across canonical URLs, minus 10 per global warning (no LocalBusiness/Organization anywhere, no FAQPage anywhere, no BreadcrumbList anywhere). |
| `llms_completeness` | +20 each for non-empty `intro`, `signals.has_business_facts`, any `sections` config, `exclude_patterns`, `max_description_chars`. |

**Key signals:**

- `has_business_facts` — `true` only when `business_facts.business_name` is
  non-empty **and** at least two of `primary_services`, `service_area`,
  `common_questions`, `key_differentiators` are populated. A business name
  alone is identity, not AEO-ready business facts (v3.4.0, issue #10) — set
  it via `POST /llms`, see [llms.txt](#llmstxt) above.
- `business_facts_source` — which tier of the resolution priority chain
  supplied the entity data: `manual_business_facts`, `schema_source_post`,
  `homepage_schema`, or `bloginfo_fallback`.
- `has_homepage_localbusiness_schema` — whether the homepage's stored
  schema graph includes a `LocalBusiness`/`Organization`-family `@type`.
- `sitemap_llms_in_sync` — `true` when `source_sync.sync_status` is `synced`.

---

### Typed Actions (v2.19.0 — v3.0 Bites 2+3)

Whitelisted, auditable, reversible mutations for the external Audit Engine.
All require `manage_options`. Whitelist: `update_setting` (9 typed WP core
options), `regenerate_llms_txt`, `update_meta_draft` (writes `_rr_seo_draft_*`,
never live meta), `toggle_indexing`. Anything else is rejected 422.

```bash
# Validate + simulate; never writes
curl -X POST "$BASE/actions/dry-run" -u "$CRED" -H "Content-Type: application/json" \
  -d '{"action_type":"update_setting","target_id":"blog_public","payload":{"new_value":1}}'

# Apply; returns action_id + rollback envelope, stored in rrseo_action_log
curl -X POST "$BASE/actions/execute" -u "$CRED" -H "Content-Type: application/json" \
  -d '{"action_type":"toggle_indexing","target_id":123,"payload":{"new_value":"noindex"}}'

# Look up a stored envelope (log keeps the most recent 200 executed actions)
curl "$BASE/actions/rrseo-action-20260710120000-1a2b3c4d" -u "$CRED"

# Undo an executed action by replaying its stored envelope
curl -X POST "$BASE/actions/rrseo-action-20260710120000-1a2b3c4d/rollback" \
  -u "$CRED" -H "Content-Type: application/json" -d '{}'
```

Rollback refusals: 404 `action_not_found`, 422 `action_not_reversible`
(e.g. `regenerate_llms_txt`), 409 `action_already_rolled_back`, and 409
`action_state_drift` when the target changed since execution — send
`{"force": true}` to roll back anyway. `{"dry_run": true}` simulates
without writing or marking.

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

**Always run step 3.** `POST /self-update`'s `success: true` response is
not yet a reliable signal by itself — it currently reports the
manifest's version as installed without re-reading the actual file on
disk, so a host-level issue (filesystem permissions, git-based deploy
sync reverting the write, stale opcache) can produce a false-positive
success with no version change (tracked in issue #20). Confirm via
`GET /status` before trusting an automated rollout as complete.

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
