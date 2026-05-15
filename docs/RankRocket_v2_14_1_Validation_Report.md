# RankRocket SEO Control Layer v2.14.1 — Hotfix Validation Report

**Test site:** https://rankrocket.co
**Plugin under test:** RankRocket SEO Control Layer v2.14.1
**State during test:** RankMath deleted, RankRocket sole SEO emitter
**Tested by:** Computer
**Date:** May 15, 2026, 12:05 PM PDT → 12:14 PM PDT

---

## TL;DR

| Verification | Result |
|---|---|
| Self-update via `POST /self-update` from 2.14.0 → 2.14.1 | ✅ |
| G-12 `/llms-txt/regenerate` no longer 500s | ✅ |
| Response payload matches changelog (`success`, `url`, `line_count`, `byte_size`, `regenerated`) | ✅ |
| `byte_size` value matches actual file | ✅ |
| `line_count` value matches actual file | ⚠ off-by-one (API says 83, real lines 82) |
| All 4 payload variants (`{}`, no body, no headers, GET) handled correctly | ✅ |
| Regression sweep across all v2.13.1 + v2.14.0 features | ✅ |
| Site stability | ✅ |

**Net: G-12 fixed cleanly. One cosmetic off-by-one in `line_count` to round-trip in a v2.14.2.**

---

## Sequence of events

1. **Initial check:** `/status` reported `2.14.0`. The hotfix was committed to GitHub (manifest + zip both 200) but the site had not pulled it yet.
2. **Reproduced G-12 fatal on v2.14.0** — confirmed the same HTTP 500 + leaked HTML error page we documented in the v2.14.0 report. Site stayed up, llms.txt unchanged.
3. **Discovered RankRocket has a `/self-update` REST endpoint** while enumerating routes. Triggered it.
4. **`POST /self-update` succeeded** — returned `{"success":true,"from_version":"2.14.0","to_version":"2.14.1","message":"Updated from 2.14.0 to 2.14.1. Plugin re-activated."}` and `/status.version` flipped to `2.14.1`.
5. **Re-ran the G-12 test on v2.14.1** — clean 200 with the expected payload.
6. **Ran full regression sweep** — every prior feature still passes.

This also incidentally validates an undocumented capability: **the plugin's `/self-update` endpoint works end-to-end as a one-shot REST trigger** (no WP admin click required). Worth surfacing in the README — it makes CI / scripted deployments much easier.

---

## G-12 Primary validation

### Test 1: canonical payload `{}` with Content-Type

```bash
curl -X POST "$BASE/llms-txt/regenerate" -u "$CRED" \
  -H "Content-Type: application/json" -d '{}'
```

**Response:**
```json
{
  "success": true,
  "url": "https://rankrocket.co/llms.txt",
  "line_count": 83,
  "byte_size": 23195,
  "regenerated": "2026-05-15 19:07:43"
}
```

- HTTP **200** ✅
- Response time **0.44s** (vs 2.04s on v2.14.0 before the fatal hit)
- All four expected fields present (`success`, `line_count`, `byte_size`, `regenerated`) ✅
- Bonus field `url` is a nice touch — gives operators a click-to-verify link

### Test 2: no body, only Content-Type

```bash
curl -X POST "$BASE/llms-txt/regenerate" -u "$CRED" \
  -H "Content-Type: application/json"
```

✅ HTTP 200, same payload shape, `regenerated` timestamp updated.

### Test 3: no headers, no body

```bash
curl -X POST "$BASE/llms-txt/regenerate" -u "$CRED"
```

✅ HTTP 200, same payload shape. Plugin is forgiving on input format.

### Test 4: wrong method (GET)

```bash
curl -X GET "$BASE/llms-txt/regenerate" -u "$CRED"
```

✅ HTTP 404 with `code: rest_no_route` — clean rejection, no 500 leakage.

### Cross-validation of response values vs actual file

| Field | API response | Actual file | Match |
|---|---|---|---|
| `byte_size` | 23195 | 23195 (`wc -c`) | ✅ |
| `line_count` | 83 | 82 (`wc -l`, `grep -c '^'`, Python `splitlines()`) | ⚠ **off by 1** |

### Determinism check
The md5 of `llms.txt` before and after the regenerate is **identical** (`bbffab5b55d4ca7f3169d07b1acb692f`). This confirms regeneration is deterministic — the same input data produces the same output bytes. Important property for diff-based CI checks.

### Site health post-regenerate
- `/status` → 200 ✅
- `/` (homepage) → 200 ✅
- `/llms.txt` → 200 ✅

---

## The `line_count: 83` vs actual 82 quirk

### Root cause (from file inspection)
The file ends with a trailing newline. Three counting methods agree the real line count is 82:
- `wc -l` → 82 (counts `\n` characters)
- `grep -c '^'` → 82
- Python `splitlines()` → 82

But the plugin returns 83. The likely calculation in the handler is:
```php
$line_count = substr_count( $content, "\n" ) + 1;
```

This formula is correct **only when the file does NOT end with `\n`**. For a file that DOES end with `\n` (which is best practice and what RankRocket emits), it produces line_count one higher than the visible line count — counting a "phantom" empty line after the trailing newline.

### Fix options (one-line)

```php
// Option A: trim trailing newlines before counting
$line_count = substr_count( rtrim( $content, "\n" ), "\n" ) + 1;

// Option B: just count newlines (matches wc -l)
$line_count = substr_count( $content, "\n" );

// Option C: split and count non-empty
$line_count = count( array_filter( explode( "\n", $content ), 'strlen' ) );
```

Option B is the simplest and matches `wc -l` convention which operators are most likely to verify against. Recommend it for v2.14.2.

### Severity
**LOW** — the value is off by exactly 1, never by more. Doesn't affect functionality. Cosmetic round-up for whoever wants to sanity-check the count from a CI script.

---

## Regression sweep on v2.14.1

All previously-verified features re-tested after the self-update. Every check passed.

| Feature | Source | Status |
|---|---|---|
| `/status.version` reports 2.14.1 | this report | ✅ |
| `/status.emit_routing_version` = 2 | v2.14.0 | ✅ |
| `/status.consolidate_canonical` = true | v2.14.0 | ✅ |
| `/status.rankmath_active` = false | baseline | ✅ |
| `display_on=entire_website` accepted | baseline | ✅ |
| `display_on=front_page` accepted | baseline | ✅ |
| `display_on=page_id:N` accepted | baseline | ✅ |
| `display_on=post_id:N` accepted (alias) | v2.14.0 | ✅ |
| `display_on=term:<tax>:<slug>` accepted | v2.14.0 G-01 | ✅ |
| `display_on=tax:<tax>` accepted | v2.14.0 G-01 | ✅ |
| `display_on=singular` accepted | v2.13.1 | ✅ |
| `display_on=term_id:<int>` accepted | v2.13.1 | ✅ |
| `display_on=url:<path>` STILL gated (422) | v2.13.1 / v2.14.0 | ✅ |
| `/preview-update` rejects term IDs with 422 | v2.14.0 G-02 | ✅ |
| `/preview-update` accepts post/page IDs | baseline | ✅ |
| `GET /snippets/<slug>` returns full record | v2.14.0 G-11 | ✅ |
| `GET /snippets/<bad-slug>` returns 404 | v2.14.0 G-11 | ✅ |
| `<title>` no leading/trailing/double space | v2.13.1 | ✅ |
| `<meta description>` no leading/trailing/double space | v2.13.1 | ✅ |
| Single canonical on homepage | v2.14.0 G-03 + mu-plugin | ✅ |
| `/status.warnings` array empty | this report | ✅ |
| `/status.snippet_count` = 0 (clean cleanup) | this report | ✅ |

---

## Bonus discovery: `/self-update` works headlessly

Worth surfacing in plugin docs. The self-update flow that worked here:

```bash
# Check what's available
curl -X POST "$BASE/check-updates" -u "$CRED"
# {"success":true,"message":"Version 2.14.1 is available."}

# Install it
curl -X POST "$BASE/self-update" -u "$CRED"
# {"success":true,"from_version":"2.14.0","to_version":"2.14.1","zip_url":"...","message":"..."}

# Verify
curl "$BASE/status" -u "$CRED" | jq .version
# "2.14.1"
```

Total time: ~3 seconds. No WP admin login required. This makes the plugin **scriptable for CI/CD-style rollouts** across many client sites — a real differentiator vs RankMath's "click here to update" workflow.

**Recommend adding this to the README/CHANGELOG so the capability isn't lost in the route list.**

---

## Outstanding follow-ups (unchanged from v2.14.0 report, except G-12 closed)

| ID | Severity | Item | Status |
|---|---|---|---|
| FU-1 | HIGH | Fix G-12 fatal | ✅ **FIXED in 2.14.1** |
| FU-1b (new) | LOW | `line_count` off-by-one when file ends in trailing newline | Open — one-line fix for v2.14.2 |
| FU-2 | MEDIUM | `/update` with empty string is a no-op (can't clear meta) | Open |
| FU-3 | LOW | Document `/update` native term support (was undocumented in v2.14.0) | Open |
| FU-4 | LOW | Add global REST shutdown handler to catch fatals and return JSON | Open — G-12 was the last surfaced case but it'll recur |
| FU-5 (new) | LOW | Document `/check-updates` and `/self-update` endpoints in README/CHANGELOG | Open |
| G-04, G-05 | MEDIUM | Performance dequeue/defer REST endpoints | Still handled by mu-plugin |
| G-06 | LOW | migrate-legacy template token detection | Open |
| G-07 | LOW | Elementor element cache helper | Open |
| G-09 | LOW | Sitemap term exclusion config | Open |
| G-10 | LOW | Bulk snippet POST endpoint | Open |
| G-13 | LOW | Snippet emission action hooks | Open |
| G-14 | LOW | Per-user-state conditional emission | Open |
| G-15 | DEFERRED | hreflang support | Deferred |

---

## Test artifacts

All in `/tmp/`:
- `/tmp/llms_pre.txt`, `/tmp/llms_post.txt` — llms.txt before/after regenerate (md5 identical)
- `/tmp/regen1.json`, `/tmp/regen2.json`, `/tmp/regen3.json` — 3 successful regeneration responses
- `/tmp/regen_get.json` — clean 404 from wrong-method test
- `/tmp/check.json`, `/tmp/selfup.json` — self-update flow responses

End of v2.14.1 validation report.
