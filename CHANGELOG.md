# Changelog

## v3.4.0

AEO/GEO write surface (issues #9, #10) — fixes `POST /llms` business_facts
persistence, undocumented payload shape, and readiness scoring, surfaced
by the Kilday Baxter & Associates SEO audit (2026-07-19/20).

### Fixed

- **`business_facts` writes are now validated, not silently dropped**
  (issue #9). `POST /llms` previously accepted any JSON shape and always
  returned `success:true` even when nothing matched a registered field —
  including every payload shape the auditing operator tried (wrapped under
  `config`, wrapped under `data`, or sent as unregistered root-level keys).
  `business_facts` writes are now validated by
  `rr_validate_llms_business_facts()`: `business_name` and `description`
  are required whenever `business_facts` is sent non-empty; every other
  field is type- and size-checked (strings capped at 5000 chars, arrays at
  50 items). Invalid payloads are rejected with `422 invalid_business_facts`
  and a `data.errors` array instead of a false `success:true`.
- **`business_facts` now always renders into `/llms.txt`** — previously the
  Business Facts block only appeared when the site had a `sections` config
  with an explicit `business_facts` entry, which almost no site configures.
  It now renders by default (using the same `business_name` ->
  `schema_source_post_id` schema -> homepage schema -> bloginfo priority
  chain already used for entity resolution) unless a `sections.business_facts`
  entry places it explicitly. This is a default output change for every
  site: llms.txt gains a Business Facts block even with a bare bloginfo
  fallback.
- **`aeo-geo/readiness` now scores what it can actually be told** (issue
  #10). `signals.has_business_facts` previously flipped `true` on any
  non-empty `business_facts` object, including a bare `business_name` with
  no AEO-relevant content — now requires `business_name` plus at least two
  of `primary_services`, `service_area`, `common_questions`,
  `key_differentiators`. The `llms_completeness` sub-score's business-facts
  bucket uses the same corrected signal. **This can lower `has_business_facts`
  and `llms_completeness` for sites that previously scored a pass with
  identity-only `business_facts`** (e.g. name/phone/address with no
  services/area/questions/differentiators) — add the enrichment fields via
  `POST /llms` to recover the score.
- New `signals.business_facts_source` field on `/aeo-geo/readiness` reports
  which tier of the resolution chain supplied the entity data
  (`manual_business_facts`, `schema_source_post`, `homepage_schema`, or
  `bloginfo_fallback`).

### Added

- New `business_facts` fields: `description`, `tagline`, `hours`,
  `years_in_business`, `key_differentiators` (array), `common_questions`
  (array of `{question, answer}`) — rendered into a Business Facts block
  plus a Common Questions Q&A block in `/llms.txt`.
- README: documented `POST /llms` payload shape and the full
  `business_facts` field set (previously undocumented); added an AEO / GEO
  section covering all four `/aeo-geo/*` endpoints and the readiness
  scoring rubric (previously undocumented — operators had to
  reverse-engineer the rubric from field names).
- 16 new unit tests (207 -> 223): `tests/unit/LlmsBusinessFactsTest.php`
  (validator + renderer) and additions to `tests/unit/AeoGeoReadinessTest.php`
  (`has_business_facts` threshold behavior, `business_facts_source`).

## v3.3.0

WAF-safe snippet transport (issue #8) — completes the render-blocking-CSS
remediation story started in v3.2.0 for sites behind Cloudflare/Sucuri.

### Added

- **`code_b64` field on snippet writes** — optional base64-encoded snippet
  body accepted by `POST /snippets`, `POST /snippets/bulk` (per item), and
  `POST /snippets/{id}`. Decoded server-side and stored/emitted exactly like
  a plain `code` body. Transport encoding only: WAF managed XSS rules
  (e.g. Cloudflare rule 100015) 403 any request body containing `on*=`
  attribute patterns, which blocks the legitimate
  `<link rel="preload" onload="this.rel='stylesheet'">` perf pattern; inside
  a base64 blob the pattern is invisible to body inspection. Authorization,
  storage semantics, and the read path are unchanged.
- `code_b64` wins when `code`/`content` are also present (callers can build
  wrappers that always encode).
- Strict validation: input must be strict-mode base64 (`base64_decode`
  with `$strict = true`) decoding to non-empty valid UTF-8; anything else is
  rejected 422 `invalid_base64` (per-item error in bulk).

### Notes

- The update endpoint (`POST /snippets/{id}`) accepts `code_b64` even though
  the issue's acceptance criteria listed only create + bulk — the same WAF
  blocks updates, and omitting it would force delete-and-recreate to edit a
  WAF-sensitive snippet.
- 6 new unit tests in `tests/unit/SnippetBase64Test.php`, including the
  acceptance round trip: an `onload=`-bearing payload decodes, stores, and
  emits verbatim at priority 1.

## v3.2.0

Snippet emission priority (issue #7) — unblocks render-blocking-CSS
remediation on locked themes by letting snippets emit before theme-enqueued
styles.

### Added

- **`priority` field on snippets** (integer 0–10000, optional) — accepted by
  `POST /snippets`, `POST /snippets/bulk` (per item), and
  `POST /snippets/{id}`; persisted and returned in all snippet responses.
  Maps directly to WordPress hook priority: a `location: "head"` snippet with
  `priority: 1` emits at `wp_head:1`, ahead of theme stylesheets registered
  at `wp_head:5-10` — required for preload/onload swaps, critical CSS
  inlining, and LCP preloads to have any effect.
- Invalid values (non-integer, negative, > 10000) are rejected with
  422 `invalid_priority`.

### Emission model

- `rmb_register_snippet_emitters()` registers one emitter per distinct
  (location, priority) bucket found in the store at plugin load, plus the
  three location defaults. `rmb_output_snippets()` now emits a single bucket
  per call.
- **Backward compatible**: snippets without `priority` keep the pre-v3.2.0
  defaults (`wp_head:20`, `wp_body_open:10`, `wp_footer:10`) — no behavior
  change, no migration.
- Priorities 0–1 interleave with the plugin's own canonical/Twitter/robots
  emission at `wp_head:1` (same-priority callbacks run in registration
  order) — documented for snippet authors targeting the earliest slot.

### Tests

- 9 new unit tests in `tests/unit/SnippetPriorityTest.php` (validation,
  location defaults, bucket registration, emission-order fixture, bucket
  filtering). Bootstrap gains `is_user_logged_in()` / `esc_attr()` stubs —
  first unit coverage of the snippet emission path.

## v3.1.0

v3.0 Bite 3: rollback layer — every executed action can now be inspected and
undone from its stored envelope.

### New endpoints (`manage_options`)

- **`GET /actions/{action_id}`** — returns the stored envelope from
  `rrseo_action_log` (before/after, rollback payload, reversibility,
  rollback marks). 404 `action_not_found` when absent — the log keeps the
  most recent 200 executed actions; older entries are pruned.
- **`POST /actions/{action_id}/rollback`** — replays the stored rollback
  payload. Honors `dry_run: true` (simulate, nothing written or marked) and
  `force: true` (roll back despite drift).

### Rollback semantics

- **Drift detection** — if the target changed since the action executed
  (current value no longer matches the envelope's recorded `after`), the
  rollback is refused with 409 `action_state_drift` listing the drifted
  fields; `force: true` overrides and records the drift as warnings on the
  rollback envelope.
- **Double-rollback protection** — 409 `action_already_rolled_back` with a
  reference to the first rollback's action ID.
- **Irreversible actions** — 422 `action_not_reversible` with the stored
  reason (e.g. `regenerate_llms_txt`); rollback records themselves are not
  reversible (re-execute the original action to redo).
- **Absent-prior restore** — prior values recorded as absent ('' meta /
  missing option) are deleted on restore, not written back as empty strings.
- Rolled-back originals are marked in the log (`rolled_back_at`,
  `rollback_action_id`); each rollback stores its own envelope, writes the
  per-post audit row for post-targeted actions, and fires both cache busts
  (v2.17.x invariant).

### Plumbing

- `rr_action_log_find()` / `rr_action_log_write()` helpers; route pattern
  matches `rr_action_id()` output only, so the new routes cannot shadow
  `/actions/dry-run` or `/actions/execute`.
- 13 new unit tests in `tests/unit/ActionRollbackTest.php`; bootstrap gains
  `delete_post_meta()` / `delete_option()` stubs.

## v3.0.0

Breaking release completing the v3.0 Bite 2 scope: the deprecated
`POST /snippets/replace-all` endpoint is removed (sign-off 2026-07-10).

### Removed (breaking)

- **`POST /snippets/replace-all`** — deprecated since v2.3.1; every response
  carried a removal notice targeting v3.0.0. Callers now receive WP's standard
  `rest_no_route` 404. Migration: `POST /snippets/bulk` (atomic batch create),
  `POST /snippets/{id}` (update), `DELETE /snippets/{id}` (delete).
- **`RR_REPLACE_ALL_CAP` constant** and the capability provisioning hook. The
  `rrseo_replace_all_snippets` capability is revoked from the administrator
  role automatically on first load after upgrade (it was persisted in the DB
  with the role).

### Rationale

- One call could silently erase the entire snippet store — incompatible with
  the v3.0 typed-action direction (per-change validation, audit logging, and
  rollback envelopes).
- All replacement endpoints (per-snippet CRUD, `/snippets/bulk`) have been
  live since v2.15.0; the deprecation window ran 2026-04-28 to 2026-07-10.

## v2.19.0

v3.0 Bite 2: typed action engine — the executor surface the external Audit
Engine calls to apply approved, low-risk remediation steps.

### New endpoints (POST, `manage_options`)

- **`/actions/dry-run`** — validate a typed action payload and return the
  simulated result (`status: simulated`, before/after, rollback envelope)
  without writing anything.
- **`/actions/execute`** — apply a whitelisted action. Honors `dry_run: true`
  in the body. Response includes `action_id`
  (`rrseo-action-{timestamp}-{hash}`), `applied_at`, `rollback_payload`,
  `reversible`, and `audit_ref`.

### Action whitelist (anything else is rejected 422)

- **`update_setting`** — nine strictly typed WP core options (issue #5):
  `blog_public`, `blogname`, `blogdescription`, `page_on_front`,
  `page_for_posts`, `show_on_front`, `default_ping_status`,
  `default_comment_status`, `posts_per_page`. Post-ID options must point to a
  published page; enums and booleans are membership-checked.
- **`regenerate_llms_txt`** — invalidates the canonical cache and re-renders
  llms.txt via the existing renderer. Returns `reversible: false` with a
  reason (content is derived from live configuration — spec table updated).
- **`update_meta_draft`** — writes SEO fields to `_rr_seo_draft_*` post meta,
  never the live `rr_seo_*` keys; validated via `rr_validate_seo_fields()`.
  Rollback envelope lists the draft fields to delete.
- **`toggle_indexing`** — sets post-level `rr_seo_robots` to `index` or
  `noindex`; prior value stored in the rollback envelope.

### Plumbing

- Executed-action envelopes stored in the capped `rrseo_action_log` option
  (200 entries, no custom tables) — the lookup store for Bite 3's
  `GET /actions/{action_id}`. Post-targeted actions additionally write a
  `_rrseo_change_log` audit row via `rr_audit_log()`.
- Every execute fires both cache busts (`rrseo_bust_option_cache()` for each
  touched option plus the action log, `rrseo_purge_rest_cache()` for affected
  endpoints) per the v2.17.x invariant.
- New module `includes/class-rrseo-actions.php`; 16 unit tests in
  `tests/unit/ActionEngineTest.php`.
- Spec updated: `docs/plugin-v3-executor-spec.md` now carries the issue #5
  option whitelist and the envelope-storage design.

### Deferred (needs sign-off)

- `replace-all` snippet endpoint removal (Bite 2 scope in the spec) is a
  breaking API change — proposed as a separate commit after review.
  *(Signed off 2026-07-10; shipped in v3.0.0.)*

## v2.18.1

Patch release fixing two bugs found during the 2026-07-09 staging verification
pass (GitHub issues #3 and #4).

### Fixed

- **Schema + audit-log writes silently discarded (issue #3)** —
  `_rrseo_schema_graph` and `_rrseo_change_log` had been registered via
  `register_post_meta()` (v2.14.4, G-16) with `type: string` and
  `sanitize_textarea_field` as the sanitize callback. WordPress applies a
  registered sanitize callback on every `update_post_meta()`, and the string
  sanitizers return `''` for arrays — so every `POST /schema/{post_id}` write
  and every `rr_audit_log()` append since v2.14.4 was flattened to an empty
  string. Both registrations are removed (the keys are underscore-prefixed
  protected meta, hidden from REST, and validated upstream); array meta
  persists again and JSON-LD emission works end to end. No data migration is
  needed: previously flattened values are empty strings that the read paths
  already treat as absent.
- **Double `<link rel="canonical">` (issue #4)** — the plugin emitted its
  canonical at `wp_head:1` but never unhooked WP core's `rel_canonical`
  (`wp_head:10`), so singular pages carried two canonical tags. The emitter
  (now the named function `rr_emit_singular_canonical()`) calls
  `remove_action( 'wp_head', 'rel_canonical' )` at the point it is committed
  to emitting. All bail paths — RankMath active, non-singular, disallowed
  post type, noindex, suppression filters, empty URL — leave core's
  canonical in place so those pages still emit exactly one tag.

### Tests

- `tests/unit/MetaPersistenceTest.php` — replays the plugin's `init`
  registrations and asserts the array-valued internal keys are unregistered,
  the schema array round-trips through `update_post_meta()`, and audit-log
  entries append correctly.
- `tests/unit/CanonicalEmissionTest.php` — asserts single-tag emission with
  core `rel_canonical` unhooked, per-post override precedence, and that all
  bail paths keep core's emitter hooked.
- `tests/bootstrap.php` now records `add_action`/`remove_action` calls and
  applies registered sanitize callbacks in `update_post_meta()` — mirroring
  core's `sanitize_meta()` layer whose absence from the harness let issue #3
  ship undetected.

## v2.18.0

v3.0 Bite 1: observation endpoints for the external Audit Engine, plus small-ticket
debt (self-canonical discovery check, admin i18n/display fixes).

### New endpoints (all GET, `manage_options`, read-only)

- **`/observe/heading-hierarchy/{post_id}`** — H1-H6 structure as a nested
  `{tag, text, depth, children}` tree, with structural warnings (`no_h1`,
  `multiple_h1`, `skipped_level`, `empty_heading`).
- **`/observe/broken-links`** — paginated link inventory
  (`page`, `per_page`, `post_type`, `include_external`). Internal links are
  resolved against WordPress locally (`ok` links are omitted; `not_found`
  returns 404; archive-shaped URLs return `unverified`). External links are
  returned with `status_code: null` and `checked: false` — the plugin makes
  no external HTTP calls by design; verification is the Audit Engine's job.
- **`/observe/alt-coverage`** — image ALT rollup: `total`, `missing` (no meta
  row), `empty` (empty string), `coverage_pct`, broken down by parent post
  type. Cached in a 5-minute transient.
- **`/observe/schema-graph/{post_id}`** — full stored `_rrseo_schema_graph`
  JSON-LD for the post plus a deduplicated `types` list.
- **`/observe/llms-diff`** — drift between the rendered llms.txt URL list and
  the canonical URL set: `in_both`, `in_llms_not_canonical`,
  `in_canonical_not_llms`, `in_sync`.

### Changes

- **Self-canonical discovery check** — `rr_is_url_allowed_for_discovery()` now
  excludes posts whose canonical override (`_rr_seo_canonical`, legacy
  `rank_math_canonical_url` fallback) points at a different URL, with new
  exclusion reason `non_self_canonical`. Sitemaps, llms.txt, and all previews
  inherit the rule via the shared canonical set. Comparison ignores case and
  trailing slashes.

### Bug fixes

- **Admin "Loading" placeholders** — `'Loading\xe2\x80\xa6'` was written in
  single quotes, so PHP rendered the escape sequence literally on all six admin
  pages and the meta box. Replaced with ASCII `Loading...`.

### I18n

- `Text Domain: rankrocket-seo` header added to the plugin file.
- Deactivation confirmation dialog strings wrapped in `__()` and emitted via
  `wp_json_encode()`.
- REST error strings remain deliberately untranslated (machine consumers).

---

## v2.17.7

Honor the `dry_run` flag in `POST /meta/bulk-update`.

### Bug fixes

- **`POST /meta/bulk-update` dry_run** — the top-level `dry_run: true` flag was
  ignored; writes are now gated behind `! $dry_run` and a dry-run call returns a
  per-post `changes` diff (matching `/preview-update` shape) with no DB writes,
  no cache bust, and no audit log entry.

---

## v2.17.6

Migrate explicit index/follow default from mu-plugin into `rr_merge_wp_robots`.

### Changes

- **`rr_merge_wp_robots` default robots** — when no `rr_seo_robots` directive is
  stored for a page and `noindex` is not already present in the filter array,
  the function now emits `index, follow` explicitly. This migrates the last
  remaining SEO behaviour from `plugin-usage-audit.php` (now `rrc-telemetry.php`),
  fully retiring the mu-plugin's SEO patch layer.

---

## v2.17.5

Hide "View Details" link from Plugins screen.

### Changes

- **Plugins screen cleanup** — `plugin_row_meta` filter strips the "View Details"
  link (which links to `plugin-install.php`) from this plugin's row. The link
  is meaningless for a private plugin not listed on WordPress.org.

---

## v2.17.4

Remove duplicate `GET /canonical-urls/preview` route registration.

### Bug fixes

- **Duplicate route removed** — `GET /canonical-urls/preview` was registered twice:
  once as a G-18 alias (line ~2258, callback `rmb_sitemap_preview`) and once in
  the AEO/GEO data-layer block (callback `rmb_canonical_urls_preview`, which was
  never implemented). WordPress silently served the first GET match, so the
  undefined callback was never reached, making this a latent fatal risk rather
  than an active crash. Removed the stale AEO/GEO stub; the G-18 alias pointing
  to `rmb_sitemap_preview` remains as the sole, authoritative registration.

---

## v2.17.3

Cache-A/B complete: LiteSpeed URL purge after writes; G-10 slug fix for individual POSTs.

### Bug fixes

- **Cache-A/B complete — LiteSpeed page cache purge** — added
  `rrseo_purge_rest_cache()` helper that fires `litespeed_purge_url` for the
  affected REST endpoints after every write. Snippet writes purge both
  `/status` and `/snippets`; perf/exclusion writes purge `/status`. Fixes
  `/status` counter lag and `/snippets` listing staleness on LiteSpeed Cache
  sites after individual snippet create/update/delete and perf POST operations.
  If LiteSpeed Cache is not active the action is a no-op.

- **G-10 slug collision — individual `POST /snippets`** — replaced the Unix
  timestamp fallback (`$id . '_' . time()`) with the same `while`-loop
  increment scheme (`_1`, `_2`, `_3`...) already used in `POST /snippets/bulk`.
  Fixes data loss when two POSTs with identical titles arrive within the same
  second — each now gets a unique deterministic slug.

---

## v2.17.2

Cache-A/B targeted invalidation, G-10 slug fix, FU-2 clarification.

### Bug fixes

- **Cache-A/B — targeted option cache invalidation** — added
  `rrseo_bust_option_cache()` helper (deletes both the specific option key
  and the `alloptions` bundle from the WP object cache). Called after every
  `update_option()` in all write handlers: `POST /perf/dequeue-rules`,
  `POST /perf/defer-handles`, `POST /sitemap/exclusions`, `POST /snippets`,
  `POST /snippets/{id}`, `POST /snippets/bulk`, `POST /snippets/replace-all`,
  `DELETE /snippets/{id}`. Fixes `GET /status` perf counters and
  `GET /snippets` listing lagging after writes on sites with persistent
  object caches (LiteSpeed, Redis, Memcached).

- **G-10 bulk — slug collision suffix** — duplicate-slug fallback now uses
  incrementing `_1`, `_2`, `_3`... suffixes (was `_<loop-index>` which
  produced `over-0_0`, `over-1_1` etc. on re-submission). A `while` loop
  finds the first available slot.

### Documentation

- **README — `unset_fields` vs empty string** — the `/update` section now
  explicitly states that sending `"title": ""` is an intentional no-op and
  that `unset_fields: ["title"]` is the correct mechanism for clearing a field.

---

## v2.17.1

Hotfix: object cache consistency, is_admin conditional, removed_count, README caching note.

### Bug fixes

- **Object cache flush in `POST /cache/purge`** — `wp_cache_flush()` is now
  called first in the purge handler, before all plugin-specific cache layers.
  Fixes read-after-write inconsistency on sites with persistent object caches
  (Redis, Memcached, SiteGround SuperCacher) where `GET /perf/dequeue-rules`,
  `GET /perf/defer-handles`, `GET /sitemap/exclusions`, and `GET /status`
  appeared to ignore prior writes until a full cache purge.

- **`removed_count` in `POST /snippets/replace-all`** — bust the WP options
  cache for the snippet store before reading `$before`, so the count reflects
  DB state rather than a stale object-cache entry. Fixes `removed_count: 0`
  when snippets existed in the DB but not in the cache.

- **`is_admin` added to `RR_PERF_ALLOWED_CONDITIONALS`** — dequeue rules can
  now use `when_not: ["is_admin"]` to keep assets enqueued in the WP admin
  while dequeuing them on the front end.

### Documentation

- **README caching compatibility section** — explains how to exclude `/wp-json/`
  from page cache for SiteGround SuperCacher, WP Rocket, W3TC, and LiteSpeed;
  notes that `POST /cache/purge` flushes the WP object cache plus all detected
  plugin caches.

---

## v2.17.0

G-07/G-14: Elementor cache repair endpoint, per-user snippet emission.

### New endpoints

- **G-07 — `POST /elementor/repair-cache`** — deletes `_elementor_element_cache`
  and `_elementor_css` for one or more posts so the next page load rebuilds
  fresh Elementor output. Accepts `post_id` (int) for a single post, `post_ids`
  (array of int) for a batch, or both. Returns `{success, repaired:[{post_id,
  status, deleted_keys}]}`. Reports `not_found` per post if ID doesn't exist.
  Documented as a compatibility helper — out of SEO scope but bridges the most
  common Elementor + REST friction point.

### New snippet field: `display_on_user`

- **G-14 — `display_on_user`** — optional field on all snippet write endpoints
  (`POST /snippets`, `POST /snippets/{id}`, `POST /snippets/bulk`). Values:
  `all` (default), `anonymous`, `logged_in`. Existing snippets without the
  field default to `all` at emit time — no migration needed.
  - `anonymous` — snippet is suppressed for logged-in users.
  - `logged_in` — snippet is suppressed for anonymous visitors.
  - Emitter fires `rrseo_snippet_skipped` with reason `user_logged_in` or
    `user_anonymous` respectively so observability hooks stay accurate.

### Minor fix

- Removed orphaned docblock in image handler section (duplicate `@param` block
  above `rmb_image_get_alt` left over from a function reorder).

---

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
