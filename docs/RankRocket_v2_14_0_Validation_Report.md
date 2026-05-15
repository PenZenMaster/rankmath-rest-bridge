# RankRocket SEO Control Layer v2.14.0 — Validation Report

**Test site:** https://rankrocket.co
**Plugin under test:** RankRocket SEO Control Layer v2.14.0 (plugin slug `rankmath-rest-bridge`)
**State during test:** RankMath deleted, RankRocket sole SEO emitter (continued from v2.13.1 validation)
**Tested by:** Computer
**Date:** May 14, 2026, 6:43 PM PDT → 7:05 PM PDT

---

## TL;DR

| # | v2.14.0 Item | Result |
|---|---|---|
| 1 | `post_id:` alias for `page_id:` | ✅ PASS |
| 2 | G-01 gate lifted — `term:`/`tax:` emission | ✅ PASS (perfect isolation) |
| 3 | G-02 `/preview-update` 422 for term IDs | ✅ PASS — **with a discovery: `/update` handles terms natively** |
| 4 | G-03 `consolidate_canonical` in `/status` | ✅ PASS |
| 5 | G-08 `emit_routing_version: 2` in `/status` | ✅ PASS |
| 6 | G-11 `GET /snippets/<slug>` | ✅ PASS |
| 7 | G-12 `POST /llms-txt/regenerate` | 🔥 **FAIL — HTTP 500 fatal** |

**Plus regression check on v2.13.1 hotfixes:** ✅ both intact (whitespace clean, url: still gated).

**6 of 7 ship items verified passing. The 7th (G-12) returns a fatal PHP error on every payload variant — but the site itself stays healthy after, so it's a contained failure.**

The G-02 implementation is **better than the changelog claims**: `/preview-update` rejects term IDs cleanly, AND `/update` natively writes term meta with `object_type: "term"` returned in the response. The 422 from `/preview-update` even hints at it: *"Use POST /update to write term meta directly."* That's the correct UX.

---

## Test 1 — `post_id:` alias restored ✅

### Test
Created two snippets targeting the same post id 2944: one with `display_on=post_id:2944`, one with `display_on=page_id:2944`. Verified both fire on the post URL and neither fires on the homepage.

| | post_id:2944 | page_id:2944 |
|---|---|---|
| POST acceptance | HTTP 200 ✅ | HTTP 200 ✅ |
| Fires on /optimizing-content-with-entity-based-seo-techniques-5/ | 1 ✅ | 1 ✅ |
| Fires on / | 0 ✅ | 0 ✅ |

`post_id:` and `page_id:` are interchangeable aliases. Verdict: **PASS**.

---

## Test 2 — G-01 gate lifted ✅

### Test
Three snippets created targeting term 21 (`category/ai-search-marketing/`):
- `display_on=term:category:ai-search-marketing`
- `display_on=tax:category`
- `display_on=url:/about/` (should remain gated)

### Results

| Pattern | POST | Fires on target term | Fires on different term | Fires on homepage | Fires on single post |
|---|---|---|---|---|---|
| `term:category:ai-search-marketing` | **200** ✅ | 1 ✅ | 0 ✅ | 0 ✅ | 0 ✅ |
| `tax:category` | **200** ✅ | 1 ✅ | 1 ✅ (correct — fires on all category archives) | 0 ✅ | 0 ✅ |
| `url:/about/` | **422** ✅ (still gated) | — | — | — | — |

The 422 response for `url:` returns the updated accepted_patterns array which now correctly includes ALL the new patterns:

```json
{
  "code": "invalid_display_on",
  "accepted_patterns": [
    "entire_website",
    "front_page",
    "singular",
    "all_pages",
    "all_posts",
    "page_id:<int>",
    "post_id:<int>",
    "post_type:<slug>",
    "term_id:<int>",
    "term:<taxonomy>:<slug>",
    "tax:<taxonomy>"
  ]
}
```

Note: `post_id:<int>` is now in the accepted_patterns list (was missing in v2.13.1's response).

### Isolation verified

The `term:` pattern is term-specific — only fires on the exact slug's archive.
The `tax:` pattern is taxonomy-wide — fires on every term archive within that taxonomy.
Neither leaks to homepage, singulars, or unrelated taxonomies.

Verdict: **PASS — G-01 fully shipped.**

---

## Test 3 — G-02 term ID handling ✅ (better than spec)

### What the changelog says
> "G-02: /preview-update returns 422 when post_id resolves to a term"

### What we actually found
`/preview-update` returns the promised 422 with a helpful error message:

```json
{
  "code": "term_not_supported",
  "message": "/preview-update does not support term IDs. Use POST /update to write term meta directly."
}
```

But — and this wasn't in the changelog — `POST /update` actually handles term IDs as a **first-class entity type**. When you send `{"post_id": 21, "title": "...", "description": "..."}`:

```json
{
  "success": true,
  "object_id": 21,
  "object_type": "term",
  "post_id": 21,
  "updated": {
    "rr_seo_title": "AI Search Marketing",
    "rr_seo_description": "Articles and guides on AI search marketing..."
  },
  "warnings": ["title: 19 chars — below recommended minimum of 30"]
}
```

And the values **surface on the live term archive** (verified via Googlebot UA fetch — 5 occurrences of the new title on `/category/ai-search-marketing/`).

### Regression check on post/page writes

`/preview-update` with a real post id and a real page id both still return HTTP 200 — no regression from gating term IDs.

### One small UX caveat

`/update` with `{title:"", description:""}` is a no-op (returns `updated: []`). There's currently no way to **clear** a previously-written term meta value via the REST API. Operators need to write a non-empty replacement value instead. Minor — log as a follow-up.

Verdict: **PASS — G-02 fully shipped, plus an undocumented bonus of native term writes in /update.**

---

## Test 4 — G-03 `consolidate_canonical` ✅

### /status now shows:
```json
{
  ...
  "consolidate_wp_robots": true,
  "consolidate_canonical": true,
  ...
}
```

Field present, default `true`. The plugin-side equivalent of the mu-plugin module `RRC_SEO_DEDUP_CANONICAL`.

### Homepage canonical count
Confirmed exactly 1 `<link rel="canonical">` on the homepage. (mu-plugin module can be set to `false` to retire it, OR left on — they're now both doing the same `remove_action('wp_head', 'rel_canonical')` so there's no conflict.)

Verdict: **PASS.**

---

## Test 5 — G-08 `emit_routing_version: 2` ✅

### /status now shows:
```json
{
  ...
  "emit_routing_version": 2,
  ...
}
```

External auditors can now detect post-v2.13.0 routing without probe snippets. Verdict: **PASS.**

---

## Test 6 — G-11 GET /snippets/`<slug>` ✅

### Existing slug
Created snippet `g11-get-test`, then `GET /snippets/g11-get-test`:

```json
{
  "id": "g11-get-test",
  "title": "G11 GET TEST",
  "content": "<meta name=\"rr-g11\" content=\"test\" />",
  "location": "head",
  "display_on": "entire_website",
  "status": "active",
  "created_at": "2026-05-15 18:50:44",
  "updated_at": "2026-05-15 18:50:44"
}
```

Full record returned: id, title, content, location, display_on, status, created_at, updated_at. All fields present and correctly typed.

### Nonexistent slug
`GET /snippets/totally-nonexistent-slug-zzz` returns:

```
http=404
code: not_found
message: Snippet 'totally-nonexistent-slug-zzz' not found.
```

Clean 404 with informative message. Verdict: **PASS.**

---

## Test 7 — G-12 POST /llms-txt/regenerate 🔥 FAIL

### Test
Hit `POST /llms-txt/regenerate` with four payload variations:

| Payload | Method | HTTP | Result |
|---|---|---|---|
| `{}` with `Content-Type: application/json` | POST | **500** | HTML critical error page |
| Empty body with `Content-Type: application/json` | POST | **500** | HTML critical error page |
| No headers, no body | POST | **500** | HTML critical error page (with WP error wrapper) |
| No body | GET | 404 | (correct — endpoint is POST only) |

### Side effect check
- llms.txt content unchanged before/after (md5 identical) — no partial write
- `/status` still returns 200 after the 500
- Homepage still returns 200 after the 500
- No other endpoint affected

**The fatal is contained to the regenerate handler.** Site stays up.

### What we know about the fatal
The 500 response leaks an HTML error page from WordPress core (`<p>There has been a critical error on this website.</p>`) wrapped in a REST envelope (`{"code":"internal_server_error","data":{"status":500},"additional_errors":[]}`). This suggests:
1. The handler IS being routed to (so the route is registered)
2. The handler throws a PHP fatal before completing
3. The fatal is severe enough that WP's error handler intercepts the response and produces HTML instead of clean JSON

### Likely root causes (dev should check)
1. **Missing helper function** — the handler may be calling a function like `rmb_render_llms_txt()` that doesn't exist or has a typo
2. **Type error** — incompatible types passed to `file_put_contents()`, `wp_send_json()`, or similar
3. **Object/array deref** — `$config->something` where `$config` is null
4. **WordPress dependency loading order** — the endpoint may be calling something that requires `template-loader` to have run

### Reproduction
Single command reproduces the 500:
```bash
curl -X POST "https://rankrocket.co/wp-json/rankrocket-seo/v1/llms-txt/regenerate" \
  -u "rocketman:APP_PASS" -H "Content-Type: application/json" -d '{}'
```

### Recommended fix
1. Wrap the handler body in a try/catch that returns a clean JSON `WP_Error` instead of leaking the HTML error page (even if the underlying bug isn't fixed, this prevents the embarrassing leak)
2. Find the actual fatal — check `/home/USER/logs/error_log` or `wp-content/debug.log` for the stack trace
3. Test the handler in isolation by calling its internal function from a quick CLI script before re-shipping

### What works without G-12
The llms.txt file does still regenerate automatically when underlying metadata changes (verified during the Salvo audit). So this isn't a total blocker — it just means operators can't deterministically trigger a regeneration on demand. They have to wait for the next natural cache cycle.

Verdict: 🔥 **FAIL — needs hotfix.**

---

## Regression checks on v2.13.1 hotfixes ✅

| Hotfix | Status in v2.14.0 |
|---|---|
| `url:` pattern still rejected with 422 | ✅ (verified — was previously deferred deliberately) |
| `<title>` no leading space, no double spaces | ✅ `"SEO Agency for Service Businesses \| Rank Rocket Co"` clean |
| `<meta description>` no leading space, no double spaces | ✅ `"Rank Rocket Co, is the leading SEO Agency..."` clean |

No regressions detected from v2.13.1 → v2.14.0.

---

## Outstanding / follow-up items

### Open from before (no change in v2.14.0)
| Gap | Status |
|---|---|
| G-04 (perf dequeue REST endpoint) | Not started — handled by mu-plugin |
| G-05 (script defer REST endpoint) | Not started — handled by mu-plugin |
| G-06 (migrate-legacy template token detection) | Not started |
| G-07 (Elementor element cache helper) | Not started |
| G-09 (sitemap term/taxonomy exclusion config) | Not started |
| G-10 (bulk snippet POST) | Not started |
| G-13 (snippet emission action hooks) | Not started |
| G-14 (per-user-state conditional emission) | Not started |
| G-15 (hreflang) | Deferred |

### Surfaced in this validation

**FU-1 (HIGH):** Fix the G-12 fatal. Either patch the handler or revert the endpoint registration until ready. Current state leaks HTML error pages, which is worse than 404.

**FU-2 (MEDIUM):** `/update` with empty string values is a no-op. There's no way to clear a previously-set rr_seo_title or rr_seo_description through the REST API. Either:
- Treat empty string as "clear this field" (write empty string to postmeta)
- Add an explicit `unset_fields: ["title", "description"]` array to the request schema
- Document the current behavior and add a `DELETE /update?post_id=N&field=title` endpoint

**FU-3 (LOW):** The v2.14.0 changelog doesn't mention that `/update` natively supports term IDs with `object_type: "term"`. This is a meaningful capability addition — update the README and CHANGELOG to surface it.

**FU-4 (LOW):** The G-12 fatal also reveals that the plugin's REST error envelope leaks HTML when a fatal occurs. Add a global error handler / `register_shutdown_function` that catches fatals during REST requests and returns a clean JSON error response.

---

## Test artifacts

All saved to `/home/user/workspace/`:
- `/home/user/workspace/rr_test/state_probe.py` — original 3-state harness (still reusable)
- `/home/user/workspace/rr_test/rr_status_baseline.json` — v2.13.0 baseline
- `/home/user/workspace/rr_test/results_state*.json` — v2.13.0 state captures
- `/tmp/rr_status_2140.json` — v2.14.0 /status snapshot
- `/tmp/llms_before.txt`, `/tmp/llms_after.txt` — confirmation G-12 made no partial writes (md5 identical)
- `/tmp/regen_*.json` — all four G-12 fatal repros

End of v2.14.0 validation report.
