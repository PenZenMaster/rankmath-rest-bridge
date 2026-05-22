# RankRocket SEO Plugin — v3.0 Executor Specification (Shape B)

## Charter

This document defines the plugin-side scope for the v3.0 development cycle.

The plugin's architectural role is defined in `docs/aeo_geo_google_data_architecture.md` and is
non-negotiable:

> "The plugin is the canonical data source for the client website. Its role in this architecture
> is strictly read-only data provider."
>
> "The plugin does not perform OAuth, call Google APIs, or store audit data. It has no knowledge
> of the audit engine."
>
> "The audit engine owns the full audit lifecycle: OAuth 2.0 consent flow ... Google API
> fetchers: GSC, GA4, GBP ... All persistent data storage ..."

The v3.0 plugin expands this role in two directions only:

1. **Observation endpoints** -- new read-only endpoints that export structured WordPress-resident
   signals the external Audit Engine can pull at scan time.
2. **Executor endpoints** -- a small, typed action surface the Audit Engine can call to apply
   safe, reversible remediation steps after a finding has been approved externally.

The plugin is the executor and local data source. The Audit Engine is the orchestrator, the
scanner, the policy enforcer, and the AI reasoning layer. That boundary does not move in v3.0.

---

## System Boundary (v3.0)

| Responsibility | Owner |
|---|---|
| Observation: collect and expose WordPress-resident signals | **Plugin (this repo)** |
| Execution: apply typed, low-risk actions; persist rollback envelope | **Plugin (this repo)** |
| Scan orchestration, finding aggregation, deduplication | Audit Engine |
| Policy engine, approval queue, operating modes | Audit Engine |
| AI reasoning, summarization, prioritization | Audit Engine |
| OAuth, GSC/GA4/GBP fetchers, portal sync | Audit Engine |
| Persistent audit storage (scans, findings, actions at scale) | Audit Engine |

---

## In Scope: Plugin v3.0

### Observation endpoints

New read-only endpoints under `rankrocket-seo/v1`. All require `manage_options`. None mutate state.

| Endpoint | Response | Purpose |
|---|---|---|
| `GET /observe/heading-hierarchy/{post_id}` | JSON tree: `{tag, text, depth, children}` | Export H1-H6 structure for heading-analysis findings |
| `GET /observe/broken-links` | Paginated: `{url, status_code, anchor_text, source_post_id}` | Broken internal/external link inventory |
| `GET /observe/alt-coverage` | Rollup by post type: `{total, missing, empty, coverage_pct}` | ALT text gap summary |
| `GET /observe/schema-graph/{post_id}` | Full JSON-LD graph for the post | Schema audit input for the Audit Engine |
| `GET /observe/llms-diff` | `{in_llms_not_canonical, in_canonical_not_llms, in_both}` | Drift between llms.txt and canonical URL set |

### Executor endpoints

A typed action surface. The plugin validates, applies, and logs. The Audit Engine decides,
approves, and queues.

| Endpoint | Purpose |
|---|---|
| `POST /actions/dry-run` | Validate a typed action payload; return simulated result without applying |
| `POST /actions/execute` | Apply a typed action; write audit row; return rollback envelope |
| `GET /actions/{action_id}` | State lookup by audit-log row ID |
| `POST /actions/{action_id}/rollback` | Replay the stored rollback envelope |

#### Initial action whitelist (Bite 2)

| Action type | What it does | Risk | Reversible |
|---|---|---|---|
| `update_setting` | Toggle a WP option (e.g., `blog_public`) | Low | Yes |
| `regenerate_llms_txt` | Trigger llms.txt rebuild via existing llms class | Low | Yes -- rollback restores prior content |
| `update_meta_draft` | Write a draft title/meta/alt to a draft field, not the live field | Low | Yes -- rollback deletes draft field |
| `toggle_indexing` | Set post-level `rr_seo_robots` (noindex/index) | Medium | Yes |

High-risk action types (canonicals, redirects, bulk content edits, plugin deactivation,
database cleanup) are out of scope for v3.0. The Audit Engine drives those via human workflows
or external tooling.

#### Executor request/response contract

Every `POST /actions/execute` request:

```json
{
  "action_type": "update_setting",
  "target_id": "blog_public",
  "payload": { "new_value": "1" },
  "dry_run": false
}
```

Every `POST /actions/execute` response:

```json
{
  "action_id": "rrseo-action-{timestamp}-{hash}",
  "action_type": "update_setting",
  "status": "completed",
  "applied_at": "ISO-8601",
  "rollback_payload": { "old_value": "0" },
  "reversible": true,
  "audit_ref": "{_rrseo_change_log entry}"
}
```

---

## Explicitly Out of Scope (plugin-side, v3.0)

- Scan orchestration, finding aggregation, deduplication -- **Audit Engine**
- Policy engine, approval queue, operating modes (advisory/assisted/autonomous) -- **Audit Engine**
- AI reasoning, summarization, content drafting -- **Audit Engine**
- OAuth flows, GSC/GA4/GBP API calls -- **Audit Engine**
- Portal sync, SyncEvent, outbound webhooks -- **Audit Engine**
- Custom DB tables for scans, findings, or actions at scale -- **Audit Engine**
- Background jobs, Action Scheduler, WP-Cron scan workers -- **Audit Engine**
- New REST namespace (`seo-agent/v1`) -- namespace stays `rankrocket-seo/v1`
- AEO/GEO prompt baseline testing -- **Audit Engine**
- Multi-site fleet intelligence -- **Audit Engine** (or future separate plugin)
- Autonomous remediation modes -- **Audit Engine** with plugin as executor only

---

## Reuse Map

Build v3.0 on these existing functions. Do not introduce parallel implementations.

| Function | File | How v3.0 uses it |
|---|---|---|
| `rr_get_seo_meta()` | `rankmath-rest-bridge.php` | Read SEO meta (with RankMath fallback) inside observation endpoints |
| `rr_audit_log()` | `rankmath-rest-bridge.php` | Required call on every `execute` action -- no exceptions |
| `rr_validate_seo_fields()` | `rankmath-rest-bridge.php` | Validate `update_meta_draft` payloads before apply |
| `rr_validate_schema()` | `rankmath-rest-bridge.php` | Validate schema-adjacent action payloads |
| `rrseo_bust_option_cache()` | `rankmath-rest-bridge.php` | Required after every executor write (v2.17.x invariant) |
| `rrseo_purge_rest_cache()` | `rankmath-rest-bridge.php` | Required after every executor write (v2.17.x invariant) |
| `/canonical-urls/preview` endpoint | `rankmath-rest-bridge.php` | Source for `llms-diff` canonical side |
| `RR_RRSEO_LLMS` class | `includes/class-rrseo-llms.php` | Reuse for `regenerate_llms_txt` action |
| `_rrseo_change_log` post meta | `rankmath-rest-bridge.php` | Store rollback envelopes per action -- no new table |

---

## Acceptance Criteria

- Every `execute` call produces an audit log row via `rr_audit_log()`. No silent mutations.
- Every `execute` call fires both `rrseo_bust_option_cache()` and `rrseo_purge_rest_cache()`
  after the write, regardless of action type. (v2.17.x cache invariant.)
- Every action in the whitelist supports `dry-run` before `execute`.
- Every action response includes a `rollback_payload` or `"reversible": false` with a reason.
- No new custom DB tables. Rollback envelopes stored in `_rrseo_change_log` post meta.
- Namespace stays `rankrocket-seo/v1`. No `seo-agent/v1` alias introduced.
- No new external HTTP calls added to the plugin.
- PHPUnit test coverage for every new endpoint: happy path, dry-run, rollback, auth failure.
- GitHub Actions CI (phpcs + phpunit) runs on push/PR before v3.0 ships.

---

## Implementation Bites

See `docs/projectStatus.md` for the tracked checklist. Summary:

| Bite | Scope | Estimate |
|---|---|---|
| 1 | Observation endpoints (5 new GET endpoints) | 1-2 weeks |
| 2 | Typed action engine: dry-run + execute + initial whitelist + remove replace-all | 2-3 weeks |
| 3 | Rollback + `GET /actions/{action_id}` + audit log extension | 1-2 weeks |
| 4 | GitHub Actions CI + PHPUnit coverage for all new paths | 2 weeks |

Prerequisite milestones (must land first): cache stabilization (done), Salvo staging verify,
mu-plugin retirement, white labeling.

---

## Sequencing

v3.0 starts after:

1. Cache stabilization -- done (v2.17.3)
2. Salvo WooCommerce staging verify -- in progress
3. rrc-mu-toolkit GitHub remote + mu-plugin retire sequence -- in progress
4. White labeling -- next milestone after mu-plugin retirement

---

## References

- `docs/aeo_geo_google_data_architecture.md` -- canonical boundary definition (authoritative)
- `docs/archive/agentic-seo-plugin-spec-original.md` -- archived original agentic spec;
  agent-runtime content to be migrated to the Audit Engine repo by the Audit Engine team
- `docs/projectStatus.md` -- v3.0 milestone checklist with tracked bites
