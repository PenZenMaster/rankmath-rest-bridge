# RankRocket SEO Control Layer v2.13.1 — Hotfix Validation Report

**Test site:** https://rankrocket.co
**Plugin under test:** RankRocket SEO Control Layer v2.13.1 (plugin slug `rankmath-rest-bridge`)
**State during test:** RankMath deleted, RankRocket sole SEO emitter (= State 3 from prior validation)
**Tested by:** Computer
**Date:** May 14, 2026, 6:22 PM PDT → 6:32 PM PDT

---

## TL;DR

| Hotfix from changelog | Verified |
|---|---|
| Gate `term:`, `tax:`, `url:` → return 422 | ✅ |
| Whitespace normalization in `<title>` and `<meta description>` | ✅ |
| Version bump 2.13.0 → 2.13.1 | ✅ |
| **Bonus discovery: term_id: emission works on the live emitter** | ✅ G-01 unblocked |

**Both shipped fixes are clean and complete. v2.13.1 also accidentally answered the changelog caveat: the `term_id:` emitter works on real term archives, which means lifting the `term:` / `tax:` gate in v2.14.0 is safe.**

Two follow-up items surfaced for v2.14.0 planning:
1. `post_id:<int>` was dropped from the accepted_patterns list (was an alias in the original spec). Either restore as an alias for `page_id:` or document the removal.
2. The accepted_patterns list grew with patterns that weren't in the original dev spec: `singular`, `all_pages`, `all_posts`, `post_type:<slug>`. All verified working. Should be added to the public API documentation.

---

## Fix 1 — 422 gating for unimplemented patterns

### Test method
POST 6 snippets with gated `display_on` values and 4 snippets with accepted patterns. Verify status codes and inspect error response structure.

### Results — gated patterns (expected HTTP 422)

| display_on value | HTTP | Response code | Hint provided | accepted_patterns provided |
|---|---|---|---|---|
| `term:category:uncategorized` | **422** ✅ | `invalid_display_on` | ✅ | ✅ |
| `term:product_cat:custom-cupolas` | **422** ✅ | `invalid_display_on` | ✅ | ✅ |
| `tax:category` | **422** ✅ | `invalid_display_on` | ✅ | ✅ |
| `tax:product_cat` | **422** ✅ | `invalid_display_on` | ✅ | ✅ |
| `url:/` | **422** ✅ | `invalid_display_on` | ✅ | ✅ |
| `url:/about/` | **422** ✅ | `invalid_display_on` | ✅ | ✅ |

Sample error response:
```json
{
  "code": "invalid_display_on",
  "message": "Invalid display_on value: 'term:category:uncategorized'.",
  "hint": "term:, tax:, and url: patterns are gated. Use one of the accepted_patterns values.",
  "accepted_patterns": [
    "entire_website",
    "front_page",
    "singular",
    "all_pages",
    "all_posts",
    "page_id:<int>",
    "post_type:<slug>",
    "term_id:<int>"
  ]
}
```

### Results — accepted patterns

| display_on value | HTTP | Stored | Verdict |
|---|---|---|---|
| `entire_website` | 200 | ✅ | ✅ |
| `front_page` | 200 | ✅ | ✅ |
| `page_id:3699` | 200 | ✅ | ✅ |
| `post_id:3699` | **422** | ❌ | ⚠ Was `post_id:` an alias in the spec? See Follow-up #1 |
| `singular` | 200 | ✅ | ✅ (new pattern) |
| `all_pages` | 200 | ✅ | ✅ (new pattern) |
| `all_posts` | 200 | ✅ | ✅ (new pattern) |
| `post_type:post` | 200 | ✅ | ✅ (new pattern) |
| `term_id:21` | 200 | ✅ | ✅ — fires on the matching term archive (see Fix 4 below) |

### Storage hygiene
Confirmed via `/status.snippet_ids` after the 422-pattern POSTs: zero gated patterns leaked into storage. The validator runs before the persistence layer.

### Verdict
**✅ PASS.** Clean rejection, informative error response, no storage pollution. The fix exceeded the minimum bar — operators get the full list of accepted patterns in the error response, not just an opaque 422.

---

## Fix 2 — Whitespace normalization

### Test method
GET the homepage with Googlebot UA, parse `<title>`, `<meta name="description">`, and OG fields. Compare to the v2.13.0 capture from the prior State 3 validation.

### Results

| Element | v2.13.0 (broken) | v2.13.1 (fixed) | Status |
|---|---|---|---|
| `<title>` | `' SEO Agency for Service Businesses \| Rank Rocket Co'` (leading space) | `'SEO Agency for Service Businesses \| Rank Rocket Co'` | ✅ leading space removed |
| `<meta name="description">` | `'Rank Rocket Co, is the leading  SEO Agency for...'` (double space before "SEO") | `'Rank Rocket Co, is the leading SEO Agency for...'` | ✅ double space collapsed |
| Trailing whitespace | — | not present | ✅ |
| Internal multi-space | sometimes present | never present (single-space normalized) | ✅ |

The `rmb_resolve_tokens()` fix correctly applies both:
1. `trim()` — removes leading/trailing whitespace from final assembled strings
2. Whitespace collapse — `[ \t]+` → single space — handles the case where empty template tokens left double spaces between adjacent words after substitution

### Verdict
**✅ PASS.** Visual quality of SERP previews is now identical to what RankMath emitted in State 1, just without the bugs from the cut-over.

---

## Fix 3 — Version bump

| Source | v2.13.0 | v2.13.1 |
|---|---|---|
| `/status.version` | `2.13.0` | `2.13.1` ✅ |
| `/status.plugin` | `RankRocket SEO Control Layer` | unchanged ✅ |

No `/status` schema changes (no new fields, no removed fields). The accepted_patterns list documented in the 422 response is the only customer-facing surface change.

### Verdict
**✅ PASS.**

---

## Fix 4 (bonus) — G-01 emitter readiness via `term_id:<int>`

### Background
The v2.13.1 changelog noted: *"the emission code for term: / tax: in rmb_snippet_matches_display() is present and looks correct. Before removing the gate in v2.14.0, do a cache-cleared hit on a category archive with a term_id: snippet to confirm the emitter fires."*

`term_id:<int>` is NOT gated (it's in accepted_patterns), so we can test the emitter today.

### Test method
1. Find a real category term: `id=21, slug=ai-search-marketing, count=2 posts, link=/category/ai-search-marketing/`
2. POST a snippet with `display_on=term_id:21`
3. Purge cache, wait 4s
4. GET 4 different URLs: the term archive, homepage, a single post, a page
5. Count probe occurrences in each

### Results

| URL | Probe fires? | Expected? |
|---|---|---|
| `/category/ai-search-marketing/` (term 21 archive) | **YES (1×)** | ✅ |
| `/` (homepage) | NO | ✅ correct |
| `/optimizing-content-with-entity-based-seo-techniques-5/` (single post) | NO | ✅ correct |
| Page (id 3699) | NO | ✅ correct |

### Implication for v2.14.0
The taxonomy matching logic in `rmb_snippet_matches_display()` is **proven working** for `term_id:<int>`. The remaining work to lift the gate on `term:<tax>:<slug>` and `tax:<tax>` is purely:
1. Open the validator allowlist
2. Confirm the slug-resolution path in the matcher correctly resolves `<tax>:<slug>` to a term ID and calls the same code path that already handles `term_id`

The hard part (the matcher) is done. Gate removal in v2.14.0 should be a one-day task.

### Verdict
**✅ PASS — G-01 unblocked for v2.14.0.**

---

## Full pattern emission matrix

For each accepted pattern, I created a probe snippet and checked emission on 4 representative URLs. This documents the complete v2.13.1 behavior contract.

| Pattern | Homepage `/` | Page (id 3699) | Single post | Term archive (id 21) |
|---|---|---|---|---|
| `entire_website` | ✅ | ✅ | ✅ | ✅ |
| `front_page` | ✅ | — | — | — |
| `singular` | ✅ | ✅ | ✅ | — |
| `all_pages` | ✅ | ✅ | — | — |
| `all_posts` | — | — | ✅ | — |
| `page_id:3699` | — | ✅ | — | — |
| `post_type:post` | — | — | ✅ | — |
| `term_id:21` | — | — | — | ✅ |

✅ = fires (count = 1), — = does not fire (count = 0)

**Note on `front_page`:** the homepage `/` is *also* the front page in WordPress's `is_front_page()` sense, AND it's a page (`is_singular()` true, `is_page()` true). So `entire_website`, `front_page`, `singular`, `all_pages` all fire there — correct WordPress semantics, just worth knowing.

**Note on `singular`:** fires on both pages AND posts (anything that's `is_singular()`). On rankrocket.co, posts and pages both qualified.

**Note on the term archive:** `singular`, `all_pages`, `all_posts`, `post_type:post`, `front_page` correctly do NOT fire there — taxonomy archives are not "singular" in WordPress's sense. Only `entire_website` and the specific `term_id:21` matched.

### Verdict
**✅ Full pattern semantics correct.** No surprise emissions, no missing fires.

---

## Outstanding gaps (unchanged from v2.13.0 report)

These weren't part of the v2.13.1 hotfix scope but remain open for future versions:

| Gap | Status in v2.13.1 |
|---|---|
| G-01 full taxonomy routing (term:, tax: emission) | Gated. Emitter code present & verified via term_id:. Gate removal scheduled for v2.14.0 |
| G-02 term ID writes in /preview-update | Still silently accepts term IDs as `post_id` with `valid: true`. Should return 422 with same hint pattern as Fix 1 |
| G-03 `consolidate_canonical` settings flag | Still missing from /status and /settings |
| G-08 `emit_routing_version` field | Still missing from /status |
| G-11 GET /snippets/<slug> single resource | Still returns 404 (collection GET works) |
| G-12 POST /llms-txt/regenerate | Still returns 404 |

The dev spec items NOT in the v2.13.1 changelog are simply not yet shipped; this isn't a regression.

---

## Follow-up items for v2.14.0 planning

### Follow-up 1 — `post_id:<int>` alias
The original dev spec T-02 routing matcher includes:
```php
// page_id:NNN / post_id:NNN
if ( preg_match( '/^(?:page|post)_id:(\d+)$/', $display_on, $m ) ) {
```
But v2.13.1 rejects `post_id:3699` with 422. Either:
- **Option A:** restore `post_id:` as an alias for `page_id:` (matches the dev spec, simpler for callers who don't care about page-vs-post)
- **Option B:** document the removal — `post_id:` is not supported; use `page_id:` for any singular content (pages OR posts)

Pick one and update accepted_patterns + docs.

### Follow-up 2 — Document the new patterns
v2.13.1 introduced four patterns that weren't in the original dev spec: `singular`, `all_pages`, `all_posts`, `post_type:<slug>`. All work correctly. The accepted_patterns array in the 422 response advertises them, but they should also live in the README, CHANGELOG entry, and any external docs. Suggested verbiage:

- `singular` — fires on any single page or single post (anything where `is_singular()` is true)
- `all_pages` — fires on all `page` post-type singulars
- `all_posts` — fires on all `post` post-type singulars
- `post_type:<slug>` — fires on singulars of the given post type (e.g. `post_type:product` for WooCommerce products)

### Follow-up 3 — Apply Fix 1 pattern to /preview-update for term IDs
The 422-with-hint UX is excellent. The same pattern should be applied to G-02 (term writes via `/preview-update` and `/update`). Today these endpoints silently accept term IDs as `post_id` and return `valid: true` — the most dangerous failure mode. A consistent 422 response with `accepted_post_types: ["post","page","product"]` and a hint pointing to a future `/terms/<id>` endpoint would close the gap until proper term writes ship.

### Follow-up 4 — Plan G-01 gate removal for v2.14.0
Since `term_id:` emission is proven working, the gate removal is a 3-step change:
1. Remove `term:`, `tax:`, `url:` from the gated set in `rr_validate_display_on()`
2. Confirm `rmb_snippet_matches_display()` correctly resolves `term:<tax>:<slug>` → term_id and routes to the same logic that's already validated
3. Add `term:<tax>:<slug>` and `tax:<tax>` to the accepted_patterns array

`url:<path>` should remain gated unless we want the additional matching complexity — it's lower value than taxonomy patterns and can stay 422 until requested.

---

## Test artifacts

Saved to `/home/user/workspace/rr_test/`:
- `state_probe.py` — original 3-state harness from v2.13.0 validation (reusable)
- `results_state1.json` / `results_state2.json` / `results_state3.json` — v2.13.0 captures
- `rr_status_baseline.json` — pre-2.13.1 /status snapshot
- This report supersedes the v2.13.0 issue list for whitespace and pattern gating only.

End of v2.13.1 validation report.
