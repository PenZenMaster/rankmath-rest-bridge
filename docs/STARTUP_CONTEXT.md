# RankRocket SEO Control Layer -- Startup Context

**Last Updated:** 2026-08-06
**Branch:** main
**Version:** 3.4.1 (shipped, zip on CDN; confirmed live on kildaybaxter.com)
**Last Commit:** ac352e5 -- chore(checkpoint): 2026-07-20_1840

---

## Last 3 Accomplishments

1. **v3.4.1 smoke test confirmed live (2026-08-06)** -- ran the #11
   regression test against kildaybaxter.com: baseline full `business_facts`
   write, then a partial write of `{"business_facts":{"email":"..."}}`.
   Diff showed only `email` added; every other field (primary_services,
   service_area, common_questions, key_differentiators, etc.) preserved
   unchanged, and readiness scores held steady instead of collapsing.
   Closed issues #9, #10, #11 on GitHub with the verification evidence.

2. **v3.4.0 shipped (issues #9, #10)** -- AEO/GEO write surface, surfaced
   by the Kilday Baxter & Associates audit: `business_facts` writes now
   validated (business_name + description required, 422 on bad input);
   Business Facts + Common Questions block now renders into `/llms.txt`
   by default; `has_business_facts` scoring tightened to require real
   enrichment, not just a name; README gained full `POST /llms` +
   `/aeo-geo/*` docs.

3. **v3.4.1 shipped (issue #11)** -- caught a regression in the v3.4.0
   fix itself during an audit replay: partial `business_facts` writes
   were replacing (not merging), silently wiping fields and collapsing
   readiness scores. Fixed with `rr_merge_llms_business_facts()`;
   validation now runs against the merged result.

---

## Next 3 Priorities

1. **Resume Higgins v3.3.0 perf deployment** -- carried over across
   sessions. Self-update (needs user's app password), then POST the
   Font Awesome + Bootstrap preload/onload swap with `code_b64` +
   `priority:1`; verify PageSpeed mobile moves 63 -> 78-85. Higgins
   should also eventually move to v3.4.1.

2. **Triage 4 new issues filed 2026-08-06** -- all from the Location
   Page Builder skill's Kilday Baxter Peru, IL rollout (2026-07-24):
   #12 `POST /elementor/set-data`, #13 schema `@graph`/array support
   on `POST /schema/{post_id}`, #14 `POST /media` upload wrapper,
   #15 `GET /capabilities` route inventory. None blocking (skill has
   working fallbacks); designed to bundle as one milestone.

3. **Telemetry verdict review** -- `rrc-telemetry.php` collecting since
   2026-07-06, trustworthy from ~2026-07-13 (now well past). Kill switch:
   `RRC_PUA_DISABLE` in wp-config.

---

## Current State

**Git:**
- Branch `main` -- in sync with origin at `ac352e5`
- Kilday Baxter (kildaybaxter.com) confirmed running v3.4.1 as of the
  2026-08-06 smoke test -- first live site on v3.4.x. Higgins still on
  v3.3.0 (see priority 1 above).
- Gates: phpcs clean, phpunit 228 tests / 550 assertions

**Files of note:**
- `business_facts` validate/merge/render: `includes/class-rrseo-llms.php`
  (`rr_validate_llms_business_facts()`, `rr_merge_llms_business_facts()`,
  `rr_render_business_facts_lines()`)
- Readiness scoring: `includes/class-rrseo-aeo-geo.php`
  (`rr_aeo_compute_readiness()` -- `has_business_facts` rubric)
- Release hook note: run `git push` twice (zip commit lands after refspec)

**Blockers:**
- None. v3.4.1 smoke-tested and confirmed live on Kilday Baxter;
  issues #9/#10/#11 closed.

---

## Key Context Notes

1. **`business_facts` merge semantics (v3.4.1)** -- sent keys overwrite,
   omitted keys preserved, array fields replace wholesale (not append).
   Sending `business_facts: {}` is now a no-op, not a full clear -- no
   endpoint currently clears the whole object back to schema/bloginfo
   fallback; that would be a separate feature request if ever needed.

2. **`has_business_facts` scoring change is retroactive** -- any site with
   identity-only `business_facts` (name/phone/address, no
   services/area/questions/differentiators) will see `has_business_facts`
   flip false and `llms_completeness`/`overall` drop under v3.4.0+, even
   with no write on their part. Flagged in both CHANGELOGs; not yet
   confirmed whether this affected Kilday Baxter's own live score
   post-deploy.

3. **Docs-only follow-up, not filed as an issue** -- `entity_clarity`
   scoring caps out when manual `business_facts` omits `schema_type`/
   `entity_id`, because manual config fully overrides schema-derived
   signals by design (resolver priority chain, `rr_resolve_business_facts()`).
   Worth a README callout so operators know to include those two fields.

4. **Git index case quirk** -- playbook tracked as `.claude/claude.md`
   (lowercase); `git add` with uppercase path silently stages nothing.
