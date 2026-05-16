# RankRocket SEO Control Layer v2.17.2 — Hotfix Validation Report

**Test site:** https://rankrocket.co
**Plugin under test:** RankRocket SEO Control Layer v2.17.2
**State during test:** RankMath deleted, RankRocket sole SEO emitter, LiteSpeed Cache active (persistent object cache)
**Tested by:** Computer
**Date:** May 15, 2026, 5:15 PM PDT → 5:25 PM PDT

---

## TL;DR

| Changelog Item | Result |
|---|---|
| Cache-A/B targeted option cache invalidation | 🟡 **PARTIAL** — `/snippets` listing fixed for some paths; `/status` counters still lag for many writes |
| G-10 slug collision suffix uses incrementing `_1`/`_2`/... | 🟡 **PARTIAL** — works for bulk batches, BUT sequential individual POSTs use Unix timestamp and collide within the same second |
| `unset_fields` documented as the clear mechanism | ✅ **FULLY FIXED AND VERIFIED** — undocumented in v2.17.1 reports, now both documented AND working |

**Net:** v2.17.2 ships one excellent fix (`unset_fields` for FU-2) and two partial fixes (Cache-A/B and G-10 slug collision) that need additional work to be production-grade.

The plugin is still ship-quality for normal operations — these issues only surface under:
- Multiple rapid sequential POSTs with identical titles (G-10 slug)
- Automation that polls `/status` immediately after writes (Cache-A/B)

Operators not exercising those edge cases won't notice.

---

## Fix 1 — Cache-A/B targeted invalidation 🟡 PARTIAL

### What the changelog promised
> "Fixes `GET /status` perf counters and `GET /snippets` listing lagging after writes on sites with persistent object caches (LiteSpeed, Redis, Memcached)."

### What actually works ✅
- `GET /snippets` listing returns 3 records immediately after 3 POSTs (no purge needed)
- `GET /status.snippet_count` updates correctly after `POST /snippets/replace-all`
- `GET /sitemap/exclusions` returns persisted config without cache purge (carried from v2.17.1)
- `GET /perf/dequeue-rules` and `GET /perf/defer-handles` are read-after-write consistent

### What still doesn't work ❌

After running 3 individual `POST /snippets`, `/status.snippet_count` reports **0** (should be 3):
```
POST /snippets x3  → each 200 success
GET /status        → snippet_count: 0  ❌
GET /snippets      → returns 3 records ✅
```

Same after a `DELETE /snippets/<id>`:
```
DELETE /snippets/cache-test-1  → 200
GET /status                    → snippet_count: 0 (should be 2) ❌
```

Same for perf counters after individual POSTs:
```
POST /perf/dequeue-rules {rules:[<one rule>]}  → 200
GET /status.perf_dequeue_rules_count           → 0 (should be 1) ❌

POST /perf/defer-handles {handles:[a, b]}      → 200
GET /status.perf_defer_handles_count           → 0 (should be 2) ❌
```

### Root cause hypothesis
The `rrseo_bust_option_cache()` helper added in v2.17.2 invalidates the specific option key AND the `alloptions` bundle. But the `/status` endpoint likely composes its counter values by reading separately from a cached internal source (possibly a transient or a denormalized count), and that secondary cache is NOT cleared by `rrseo_bust_option_cache()`.

`POST /snippets/replace-all` does refresh `/status.snippet_count` correctly — suggesting that handler does call something extra. Suggest auditing the difference between the replace-all save path and the individual `POST /snippets` / `DELETE /snippets/<id>` / `POST /perf/*` paths.

### Severity
**MEDIUM** — automation scripts polling `/status` for confirmation will see misleading counts. The fix is straightforward: extend `rrseo_bust_option_cache()` or its callers to also delete whatever transient/cache key `/status` reads from.

### Verdict
**🟡 PARTIAL** — collection GETs are fixed (real progress!), but `/status` counters still need their own cache invalidation path.

---

## Fix 2 — G-10 slug collision suffix 🟡 PARTIAL with new bug

### What the changelog promised
> "Duplicate-slug fallback now uses incrementing `_1`, `_2`, `_3`... suffixes (was `_<loop-index>` which produced `over-0_0`, `over-1_1` etc. on re-submission). A `while` loop finds the first available slot."

### Bulk path: works perfectly ✅

5-item bulk POST with identical title `BULK DUP`:
```
id=bulk-dup       ✅
id=bulk-dup_1     ✅
id=bulk-dup_2     ✅
id=bulk-dup_3     ✅
id=bulk-dup_4     ✅
```

Clean incrementing scheme as documented.

### Sequential individual POST path: uses Unix timestamps and collides ❌

5 sequential individual `POST /snippets` with identical title `SEQ TEST`:
```
run 1: id=seq-test                  created_at=00:17:56
run 2: id=seq-test_1778890676       created_at=00:17:56   ← Unix timestamp suffix
run 3: id=seq-test_1778890677       created_at=00:17:57
run 4: id=seq-test_1778890677       created_at=00:17:57   ← SAME ID as run 3
run 5: id=seq-test_1778890677       created_at=00:17:57   ← SAME ID as runs 3-4
```

The collision-resolver for individual POSTs uses the current Unix timestamp instead of an incrementing counter. **When multiple POSTs land in the same second, they get the same suffix.** Confirmed via `removed_count: 3` on the subsequent `replace-all` — only 3 unique IDs were actually stored, not 5 (one overwrote, one silently failed, or one merged).

The bulk path probably uses an in-loop `$i++` counter which is why it works correctly within a single request. Individual POSTs don't have that counter context and fall back to time-based deduplication, which fails under rapid sequential writes.

### Severity
**MEDIUM** — most users won't trigger this (you'd have to POST the same title twice within a second). But for automation that creates snippets in tight loops, **data loss is possible** because subsequent POSTs return `success: true` with the same ID as a prior one.

### Suggested fix
The collision-resolver should use the same `while`-loop "find first available slot" pattern that works for bulk:
```php
$base = sanitize_title($title);
$candidate = $base;
$i = 1;
while (snippet_exists($candidate)) {
    $candidate = "{$base}_{$i}";
    $i++;
}
return $candidate;
```

Don't use a timestamp — use the DB to discover the next free slot atomically.

### Verdict
**🟡 PARTIAL** — bulk works, sequential doesn't.

---

## Fix 3 — `unset_fields` mechanism ✅ FULLY FIXED

### What the changelog promised
> "Documentation: the `/update` section now explicitly states that sending `"title": ""` is an intentional no-op and that `unset_fields: ["title"]` is the correct mechanism for clearing a field."

The changelog frames this as documentation-only. **But `unset_fields` actually works as a clearing mechanism — which is the real story here.** This closes FU-2 from prior reports.

### Test sequence

1. Set values:
```bash
POST /update {"post_id":2944,"title":"FU2 PROBE TITLE","description":"FU2 probe description"}
→ 200 updated: {"rr_seo_title":"FU2 PROBE TITLE","rr_seo_description":"FU2 probe description"}
```

2. Clear using `unset_fields`:
```bash
POST /update {"post_id":2944,"unset_fields":["title","description"]}
→ 200
{
  "success": true,
  "object_id": 2944,
  "object_type": "post",
  "updated": {"rr_seo_title":"","rr_seo_description":""},
  "warnings": []
}
```

3. Verify cleared via `/get/<id>`:
```bash
GET /get/2944 → rr_seo_title: <not set>  rr_seo_description: <not set>  ✅
```

4. Confirm empty-string POST is still a no-op (as documented):
```bash
POST /update {"post_id":N,"title":"RESTORED","description":"restored"}  → set
POST /update {"post_id":N,"title":"","description":""}                  → updated: [] (no-op)
GET /get/2944.rr_seo_title → "RESTORED"  ✅ (empty-string POST did nothing)
```

### Verdict
**✅ FULLY FIXED.** FU-2 is closed. Operators now have a clean way to remove metadata via REST.

---

## New side-finding: `/snippets` listing also lags for batch operations

While probing other things, I noticed:
1. Cleanup `replace-all` reported `removed_count: 0` (because nothing was there per the listing)
2. `GET /snippets` showed 3 records (`cache-test-1/2/3`) which had been deleted earlier in tests
3. Then later when I ran another batch of tests and called `replace-all`, it reported `removed_count: 7` — which is 3 (cache-test) + 4 (bulk-dup_1 through bulk-dup_4) that were left over

So `GET /snippets` was undercounting (missed the 4 bulk-dup entries), AND `replace-all` correctly counted them. The DB has the right state; the listing's cache key is being missed by the v2.17.2 invalidation.

This is the same root cause as the `/status` counter lag — the v2.17.2 fix invalidated some cache keys but not all. **Suggest a `/diagnostics/cache-state` debug endpoint** that shows which option keys and which transients the plugin has cached, so this kind of issue is easier to debug going forward.

### Severity
**LOW** — the authoritative endpoints (`replace-all` response, `/get/<id>`, `/sitemap/exclusions`) all return correct data. Only the listing/counter views lag.

---

## Regression sweep — 8/8 prior features still working ✅

| Feature | Source | Result |
|---|---|---|
| `/preview-update` rejects term IDs with 422 | v2.14.0 G-02 | ✅ |
| `display_on=url:<path>` still gated with 422 | v2.13.1 | ✅ |
| `POST /elementor/repair-cache` | v2.17.0 G-07 | ✅ |
| `POST /llms-txt/regenerate` | v2.14.1 | ✅ |
| `line_count` matches `wc -l` | v2.14.1 FU-1b | ✅ 82 = 82 |
| G-09 `/sitemap/exclusions` persistence | v2.17.1 | ✅ persists across POST→GET |
| `<title>` + `<meta description>` whitespace clean | v2.13.1 | ✅ |
| Single canonical on homepage | v2.14.0 G-03 + mu-plugin | ✅ |

No regressions.

---

## Install confirmation

| Source | Value |
|---|---|
| `/status.version` | `2.17.2` ✅ |
| `/status.warnings` | `[]` |
| Self-update flow (POST /self-update) | `from: 2.17.1 to: 2.17.2 — Plugin re-activated.` ✅ |

Self-update was required (manifest showed 2.17.2 available, /status showed 2.17.1 until I called /self-update).

---

## Final cleanup state

```
snippet_count: 0
perf_dequeue_rules_count: 0
perf_defer_handles_count: 0
sitemap exclusions: all empty
warnings: []
```

Site state is clean.

---

## Recommended v2.17.3 hotfix scope

| Priority | Item | Estimated effort |
|---|---|---|
| 🟡 MEDIUM | Cache-A/B continued — `/status` counter cache invalidation for individual snippet POST/DELETE and perf POST | 1 hr |
| 🟡 MEDIUM | G-10 slug collision — use DB-discovery `while` loop for individual POSTs (replace the timestamp scheme) | 30 min |
| 🟢 LOW | `/snippets` listing cache invalidation on individual snippet operations | 30 min |
| 🟢 LOW | Optional `/diagnostics/cache-state` debug endpoint | 1 hr |

The three persistence/cache fixes likely share root cause (some cache layer not being invalidated by some write paths). A focused audit of every option-touching write handler should close all three.

---

## Open follow-ups (carried forward)

| ID | Severity | Item | Status |
|---|---|---|---|
| FU-2 | ✅ FIXED in v2.17.2 (via `unset_fields`) | — | Closed |
| Cache-A/B status counters | 🟡 PARTIAL in v2.17.2 | needs additional work |
| G-10 slug collision (sequential POSTs) | 🟡 PARTIAL in v2.17.2 | needs additional work |
| Listing endpoint cache lag | 🟢 NEW | low severity |
| G-10 bulk `rrseo_batch_max` cap not enforced | OPEN | from v2.17.0 |
| G-13 hook-firing observability | OPEN | /log endpoint present, hooks not externally verifiable |
| G-15 hreflang support | DEFERRED | unchanged |
| FU-4 global REST shutdown handler | OPEN | quiet since v2.14.1 G-12 fix |

---

## Test artifacts

`/tmp/`:
- `/tmp/status_2172.json` — v2.17.2 /status
- `/tmp/wipe.json`, `/tmp/wipe2.json`, `/tmp/wipe3.json` — replace-all responses showing removed_count
- `/tmp/u1.json`, `/tmp/u2.json`, `/tmp/u3.json` — unset_fields round-trip
- `/tmp/regen.json` — llms regenerate (line_count verification)
- `/tmp/bulk_dup.json.resp` — bulk slug collision test

End of v2.17.2 validation report.
