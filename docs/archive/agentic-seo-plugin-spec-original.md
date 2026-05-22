> **ARCHIVED — 2026-05-21**
>
> This document described an agentic SEO plugin that would host its agent runtime, AI reasoning
> layer, policy engine, approval queue, OAuth flows, Google API fetchers, and persistent audit
> data **inside the WordPress plugin**. After review, that design was found to conflict with the
> canonical boundary defined in `docs/aeo_geo_google_data_architecture.md` (plugin = read-only
> data provider; all orchestration = external Audit Engine).
>
> **Retargeted:** The agent runtime, scan orchestration, AI reasoning, OAuth, GSC/GA4/GBP
> fetchers, policy engine, approval queue, and portal sync described here belong in the external
> **Audit Engine** repo, not in this plugin. Transfer this document to the Audit Engine repo as
> its foundational product spec.
>
> **Plugin-side scope** distilled from this spec lives in `docs/plugin-v3-executor-spec.md`.
>
> The original content is preserved below verbatim for the Audit Engine team.

---

# Agentic SEO Plugin Product & Technical Specification

## Overview

This specification reframes the current plugin concept from a REST endpoint collection into an on-site agentic SEO operations platform for WordPress. The source document defines a broad audit scope spanning technical SEO, indexing, performance, content quality, schema, analytics-enriched diagnostics, and AEO/GEO readiness, while also requiring the plugin to function without Google Search Console (GSC) and Google Analytics 4 (GA4), optionally use those integrations when available, and push results into the agency workflow portal.[file:1]

The product direction implied by the source is not "API first" in the narrow sense, but "site intelligence first." The plugin should observe the site, evaluate conditions, generate findings, recommend or execute remediation, and synchronize outcomes externally. REST endpoints remain important, but as control surfaces for the plugin's subsystems rather than the sole purpose of the plugin.[file:1]

## Product Goals

### Primary goals

- Audit the WordPress site using local signals even when external integrations are unavailable.[file:1]
- Optionally enrich audits with GSC and GA4 data when connected.[file:1]
- Support corrective actions that are either human-curated or autonomous, depending on policy.[file:1]
- Push findings, actions, and status updates to the SEO agency dashboard / workflow portal.[file:1]
- Convert manual audit steps into repeatable, machine-executable workflows over time.[file:1]

### Non-goals for MVP

- Full autonomous editing of published content.
- High-risk SEO changes without approval, such as bulk canonical rewrites or mass redirect creation.
- Dependence on GSC or GA4 for core functionality.[file:1]

## Product Positioning

The plugin is a WordPress-resident SEO operations agent. It combines deterministic checks, selective AI reasoning, and guarded automation to maintain technical SEO health, improve content readiness for both traditional and AI-driven search, and create a closed feedback loop between the site and the agency dashboard.[file:1]

### Core value proposition

- Local-first: usable on any WordPress site with no external data requirement.[file:1]
- Integration-enhanced: deeper diagnosis when GSC and GA4 are available.[file:1]
- Action-oriented: findings map to suggested or executable remediation rather than static reports.[file:1]
- Agency-ready: multi-site rollup and workflow sync into the existing portal.[file:1]

## Users and Operating Modes

### Primary users

- SEO agency operators managing many WordPress sites.
- Technical SEOs performing audits and remediation.
- Site owners or editors reviewing suggested actions.
- Automation workflows consuming plugin findings via API.[file:1]

### Operating modes

| Mode | Description | Typical use |
|---|---|---|
| Advisory | Generate findings and recommendations only | Conservative client environments |
| Assisted | Draft actions and await approval | Standard agency workflow |
| Autonomous | Execute only policy-approved low-risk actions | Mature deployments with guardrails |

## Functional Scope

The source document defines the following audit domains.[file:1]

### Audit domains

- Technical and indexing settings: search visibility, permalinks, SSL/HTTPS, sitemaps, robots.txt, canonicals, crawl/indexing issues.[file:1]
- Speed and performance: Core Web Vitals, caching, image optimization, plugin/theme bloat, database optimization.[file:1]
- On-page and content optimization: title/meta quality, heading hierarchy, alt text, broken links, internal linking, orphan pages, thin/duplicate content.[file:1]
- Structured data and UX: schema validation and mobile responsiveness.[file:1]
- GSC-enriched diagnostics: index inflation, crawl budget waste, striking-distance keywords, cannibalization signals, discovered/crawled not indexed patterns.[file:1]
- GA4-enriched diagnostics: landing page quality, traffic drop interpretation, conversion path value, internal site search, spam anomaly review.[file:1]
- AEO/GEO readiness: AI bot permissions, llms.txt, JavaScript dependency, answer-first formatting, conversational headings, summaries, list/table structure, entity/E-E-A-T signals, off-site reputation and prompt baseline tracking.[file:1]

## System Architecture

### High-level architecture

The plugin should be structured as modular services rather than as a flat set of endpoints.

| Layer | Responsibility |
|---|---|
| Site Observers | Collect WordPress, crawl, HTML, media, schema, and integration data |
| Rule Engine | Run deterministic checks and assign findings |
| AI Reasoning Layer | Interpret ambiguous patterns, summarize, prioritize, and draft remediation |
| Action Engine | Execute approved fixes using WordPress-native operations |
| Approval & Policy Layer | Enforce autonomy boundaries and approval requirements |
| Sync Layer | Push data to external workflow systems |
| REST API Layer | Expose plugin state, findings, actions, approvals, and integrations |

### Design principles

- Deterministic first, AI second.
- Every action must be traceable to evidence.
- Every executable action must be reversible where practical.
- Findings and actions must be idempotent so repeated runs do not corrupt state.
- Optional integrations should extend coverage, not gate the product.[file:1]

## Agent Roles

The most effective design is capability-based specialization. Each agent produces structured findings, not free-form prose by default.

### 1. Crawlability Agent

**Purpose:** Ensure the site is discoverable and indexable by search engines and AI crawlers.[file:1]

**Inputs:**
- WordPress reading settings
- Permalink settings
- robots.txt
- sitemap availability
- canonical tags
- HTTPS state
- crawler directives

**Responsibilities:**
- Detect "discourage search engines" status.[file:1]
- Validate permalink quality.[file:1]
- Verify HTTPS and mixed-content issues.[file:1]
- Confirm sitemap generation and accessibility.[file:1]
- Inspect robots rules for blocked assets or AI bots.[file:1]
- Detect missing or inconsistent canonicals.[file:1]
- Generate or validate `llms.txt`.[file:1]

### 2. Performance Agent

**Purpose:** Detect technical factors that reduce crawl efficiency and user experience.[file:1]

**Inputs:**
- Front-end performance signals
- plugin/theme inventory
- image metadata
- cache/plugin configuration
- database health metrics

**Responsibilities:**
- Score Core Web Vitals opportunities.[file:1]
- Detect cache plugin presence and probable configuration status.[file:1]
- Find oversized images and missing dimensions.[file:1]
- Identify plugin/theme bloat risk.[file:1]
- Flag database cleanup opportunities.[file:1]

### 3. On-Page Agent

**Purpose:** Evaluate page-level SEO and content structure.[file:1]

**Inputs:**
- rendered HTML
- post metadata
- media data
- internal link graph

**Responsibilities:**
- Review title tags and meta descriptions.[file:1]
- Validate heading hierarchy and H1 count.[file:1]
- Audit image alt text coverage.[file:1]
- Find broken internal and external links.[file:1]
- Identify orphan pages and weak internal linking.[file:1]
- Flag thin or duplicate content candidates.[file:1]

### 4. Schema & Entity Agent

**Purpose:** Improve machine readability and entity trust signals.[file:1]

**Inputs:**
- schema markup in rendered pages
- site settings
- organization details
- author profiles

**Responsibilities:**
- Validate Organization, LocalBusiness, Article, Product, FAQPage, and related schema as applicable.[file:1]
- Detect schema errors and omissions.[file:1]
- Verify author bylines and structured author signals.[file:1]
- Normalize entity data for portal sync.[file:1]

### 5. Analytics Agent

**Purpose:** Add search and engagement diagnostics when integrations are available.[file:1]

**Inputs:**
- GSC data (optional)
- GA4 data (optional)

**Responsibilities:**
- Detect index inflation and crawl waste.[file:1]
- Surface striking-distance queries.[file:1]
- Flag cannibalization clues.[file:1]
- Compare impressions, clicks, sessions, and CTR patterns.[file:1]
- Identify landing pages with intent mismatch or low engagement.[file:1]
- Review organic conversion and spam/anomaly indicators.[file:1]

### 6. AEO/GEO Agent

**Purpose:** Optimize content and technical setup for AI-mediated discovery and citation.[file:1]

**Inputs:**
- rendered page content
- heading structure
- robots/crawler directives
- summary/list/table presence
- off-site reputation inputs if available

**Responsibilities:**
- Check for answer-first formatting.[file:1]
- Evaluate conversational headings.[file:1]
- Detect summary blocks on long-form content.[file:1]
- Verify table/list usage for extractability.[file:1]
- Confirm AI crawler access and llms.txt support.[file:1]
- Track prompt-baseline visibility tests as external records when available.[file:1]

### 7. Orchestrator Agent

**Purpose:** Coordinate scan execution, finding aggregation, prioritization, and action scheduling.

**Responsibilities:**
- Start scans.
- Route jobs to specialized agents.
- Deduplicate findings.
- Prioritize issues by severity, confidence, and impact.
- Produce summaries for dashboard consumption.
- Trigger action proposals or automatic execution according to policy.

## Data Model

The data model should separate observations, findings, actions, and approvals. This is critical for safety, auditability, and clean synchronization.

### Core entities

| Entity | Purpose |
|---|---|
| Site | WordPress install being monitored |
| Scan | A single audit execution over one site |
| Observation | Raw collected evidence |
| Finding | Interpreted issue or opportunity |
| Recommendation | Human-readable next step derived from a finding |
| Action | Executable remediation task |
| Approval | Human decision record for gated actions |
| Policy | Rules controlling autonomy and approvals |
| Integration | External connection state such as GSC/GA4/portal |
| SyncEvent | Outbound or inbound portal synchronization event |
| AuditLog | Immutable activity trail |

### Suggested entity definitions

#### Site

```json
{
  "site_id": "uuid",
  "home_url": "https://example.com",
  "wp_version": "6.x",
  "plugin_version": "0.x",
  "environment": "production",
  "seo_plugin": "rankmath|yoast|aioseo|none",
  "mode": "advisory|assisted|autonomous",
  "policy_id": "uuid",
  "connected_integrations": ["portal", "gsc", "ga4"]
}
```

#### Scan

```json
{
  "scan_id": "uuid",
  "site_id": "uuid",
  "scan_type": "full|incremental|targeted",
  "status": "queued|running|completed|failed",
  "started_at": "ISO-8601",
  "completed_at": "ISO-8601",
  "requested_by": "system|user_id",
  "agents_run": ["crawlability", "performance", "onpage"]
}
```

#### Observation

```json
{
  "observation_id": "uuid",
  "scan_id": "uuid",
  "agent": "crawlability",
  "subject_type": "site|page|post|media|setting|integration",
  "subject_id": "123",
  "key": "search_engine_visibility",
  "value": true,
  "evidence": {
    "source": "wp_option",
    "path": "blog_public"
  },
  "captured_at": "ISO-8601"
}
```

#### Finding

```json
{
  "finding_id": "uuid",
  "scan_id": "uuid",
  "agent": "crawlability",
  "category": "indexing",
  "severity": "critical|high|medium|low|info",
  "confidence": 0.98,
  "title": "Search engine visibility is disabled",
  "description": "WordPress is configured to discourage indexing.",
  "subject_type": "site",
  "subject_id": "site_id",
  "evidence_refs": ["observation_id_1"],
  "suggested_action_types": ["update_setting"],
  "automation_level": "autonomous",
  "status": "open|accepted|dismissed|resolved",
  "created_at": "ISO-8601"
}
```

#### Action

```json
{
  "action_id": "uuid",
  "finding_id": "uuid",
  "action_type": "update_setting|create_file|update_meta|create_redirect|queue_content_task",
  "target_type": "site|post|page|media|file|option",
  "target_id": "blog_public",
  "payload": {
    "new_value": false
  },
  "risk_level": "low|medium|high",
  "requires_approval": false,
  "status": "draft|pending_approval|approved|rejected|executing|completed|failed|rolled_back",
  "dry_run_result": {
    "summary": "Will set blog_public to 1"
  },
  "rollback_payload": {
    "old_value": true
  },
  "executed_at": null,
  "verified_at": null
}
```

#### Approval

```json
{
  "approval_id": "uuid",
  "action_id": "uuid",
  "decision": "approved|rejected",
  "decided_by": "user_id",
  "reason": "Safe to apply during maintenance window",
  "decided_at": "ISO-8601"
}
```

#### Policy

```json
{
  "policy_id": "uuid",
  "name": "default-assisted",
  "mode": "assisted",
  "auto_execute": ["update_setting", "create_file"],
  "approval_required": ["create_redirect", "update_canonical", "content_edit"],
  "blocked_actions": ["bulk_content_delete"],
  "max_actions_per_scan": 10
}
```

## Endpoint Map

The API should be organized by resources and workflows, not by arbitrary utility functions.

### Base namespace

`/wp-json/seo-agent/v1`

### Health and configuration

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/health` | Plugin health, version, dependency status |
| GET | `/config` | Current plugin configuration and operating mode |
| PATCH | `/config` | Update settings such as mode, scan defaults, sync behavior |
| GET | `/policies` | List available policies |
| POST | `/policies` | Create policy |
| PATCH | `/policies/{policy_id}` | Update policy |

### Sites and integrations

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/site` | Site profile and integration state |
| GET | `/integrations` | List integration connections |
| POST | `/integrations/{provider}/connect` | Start connection flow |
| POST | `/integrations/{provider}/disconnect` | Remove connection |
| POST | `/integrations/{provider}/sync` | Force sync or import |

### Scans

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/scans` | List scans |
| POST | `/scans` | Create a full, incremental, or targeted scan |
| GET | `/scans/{scan_id}` | Scan detail |
| POST | `/scans/{scan_id}/cancel` | Cancel a running scan |
| GET | `/scans/{scan_id}/summary` | Rollup of findings and actions |
| GET | `/scans/{scan_id}/observations` | Raw collected observations |
| GET | `/scans/{scan_id}/findings` | Findings produced by the scan |

### Findings

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/findings` | Query findings across scans |
| GET | `/findings/{finding_id}` | Finding detail |
| PATCH | `/findings/{finding_id}` | Change status, assignee, notes |
| POST | `/findings/{finding_id}/recommend` | Generate or refresh remediation draft |
| POST | `/findings/{finding_id}/actions` | Create an action from a finding |

### Actions and approvals

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/actions` | List actions |
| GET | `/actions/{action_id}` | Action detail |
| POST | `/actions/{action_id}/dry-run` | Simulate an action |
| POST | `/actions/{action_id}/approve` | Approve gated action |
| POST | `/actions/{action_id}/reject` | Reject gated action |
| POST | `/actions/{action_id}/execute` | Execute action |
| POST | `/actions/{action_id}/rollback` | Roll back action when supported |
| GET | `/approvals` | Approval queue |

### Agent-specific convenience endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/agents` | List registered agents |
| POST | `/agents/{agent_key}/run` | Execute a targeted agent scan |
| GET | `/agents/{agent_key}/capabilities` | Capabilities and action types |

### Sync and logs

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/sync/push` | Push current state to portal |
| GET | `/sync/events` | Sync history |
| GET | `/logs` | Audit and execution logs |

## Approval Matrix

The approval model should be based on risk, reversibility, and blast radius.

| Action Type | Example | Risk | Default Approval Rule |
|---|---|---|---|
| Read-only scan | Crawl site, inspect settings | Low | No approval |
| Generate summary/recommendation | Create remediation notes | Low | No approval |
| Update simple setting | Enable indexing visibility, regenerate sitemap | Low | Autonomous allowed |
| Create support file | Create `llms.txt`, export report | Low | Autonomous allowed |
| Add metadata draft | Draft title/meta/schema suggestions | Low | Approval not required if stored as draft only |
| Fix media metadata | Add missing dimensions or alt draft fields | Low | Assisted/autonomous depending on policy |
| Create internal-link suggestions | Recommendations only | Low | No approval |
| Insert internal links into content | Modify published content | Medium | Approval required |
| Update canonicals | Change canonical targets | High | Approval required |
| Create or edit redirects | Add 301/302 rules | High | Approval required |
| Bulk content edits | Rewrite multiple posts/pages | High | Approval required |
| Plugin deactivation/removal | Disable suspected bloat plugin | High | Approval required |
| Database cleanup | Delete revisions/transients | Medium | Approval required unless explicitly whitelisted |
| Noindex/index toggles on content | Change indexability of posts/pages | High | Approval required |

### Approval rules

- Autonomous mode should still respect blocked actions in policy.
- Any action affecting published content, URL behavior, indexability, canonicalization, redirects, or plugin state should require approval by default.
- Bulk actions should always require approval even when single-item versions do not.
- Every approved action should support dry run output before execution.

## Execution Flow

### Standard workflow

1. A scan is requested manually, via schedule, or through the portal.
2. The orchestrator determines which agents to run.
3. Agents collect observations and emit findings.
4. The orchestrator deduplicates and prioritizes findings.
5. The system generates recommendations and optional draft actions.
6. Policy is evaluated to determine whether actions are advisory, approval-gated, or autonomous.
7. Approved/autonomous actions run through the action engine.
8. Post-action verification confirms whether the issue is resolved.
9. Results are synchronized to the workflow portal.

### Safety requirements

- Actions must validate preconditions before execution.
- Every executable action should persist prior state for rollback where practical.
- Verification should be explicit; successful execution alone does not equal remediation.
- AI-generated text should never execute directly without mapping to a typed action.

## Portal Sync Contract

The source document explicitly calls for pushing results to the SEO agency dashboard / workflow portal.[file:1]

### Outbound sync payload categories

- Site profile
- Scan summaries
- Findings
- Actions
- Approval queue state
- Execution logs
- Trend snapshots over time

### Sync behavior

- Queue events locally when the portal is unavailable.
- Retry with backoff.
- Use signed requests and per-site credentials.
- Treat the portal as a system of coordination, not as the only source of truth.

## MVP Build Plan

The build plan should prioritize deterministic value first and add AI and autonomy only after the data model and safety rails are stable.

### Phase 0: Foundations

**Goal:** Establish the plugin skeleton and internal contracts.

**Deliverables:**
- Core plugin bootstrap.
- Settings and mode management.
- Basic REST namespace.
- Site, scan, finding, action, policy data tables or custom post type strategy.
- Audit logging.
- Portal authentication scaffold.

### Phase 1: Deterministic Audit MVP

**Goal:** Ship useful local auditing without external integrations.[file:1]

**Deliverables:**
- Crawlability Agent v1.
- On-Page Agent v1.
- Schema & Entity Agent v1.
- Findings list and scan summaries.
- Advisory-only recommendations.
- Portal push for findings and summaries.[file:1]

**Included checks:**
- Search visibility.[file:1]
- Permalinks.[file:1]
- HTTPS/mixed content indicators.[file:1]
- Sitemap presence.[file:1]
- robots.txt issues.[file:1]
- canonical presence.[file:1]
- H1/heading structure.[file:1]
- missing alt text.[file:1]
- broken internal links.[file:1]
- basic schema detection.[file:1]
- mobile/usability heuristic flags.[file:1]
- AI crawler permissions and llms.txt checks.[file:1]

### Phase 2: Assisted Remediation

**Goal:** Move from reporting to safe action creation.

**Deliverables:**
- Action Engine v1.
- Dry-run support.
- Approval queue endpoints.
- Policy engine v1.
- Low-risk executable actions.

**Initial executable actions:**
- Enable/disable indexing visibility setting.
- Generate `llms.txt`.
- Regenerate sitemap where supported.
- Draft title/meta suggestions.
- Draft alt text suggestions.
- Export structured remediation tasks to the portal.

### Phase 3: Integration Enrichment

**Goal:** Add GSC and GA4 as optional signal layers.[file:1]

**Deliverables:**
- GSC connection and property validation.
- GA4 connection and property validation.
- Analytics Agent v1.
- Portal sync for query/page metrics.

**Initial enriched diagnostics:**
- Index inflation.[file:1]
- crawl waste.[file:1]
- striking-distance terms.[file:1]
- cannibalization hints.[file:1]
- landing page engagement mismatch.[file:1]
- conversion-value prioritization.[file:1]

### Phase 4: AI Reasoning Layer

**Goal:** Add summarization, prioritization, and draft remediation logic.

**Deliverables:**
- AI-generated executive summaries.
- Prioritized issue clusters.
- Draft remediation explanations.
- Content/AEO recommendations.
- Agency-facing "what changed / what to do next" summaries.

**Constraints:**
- AI outputs map to typed finding and action records.
- No direct execution from free-form completions.

### Phase 5: Controlled Autonomy

**Goal:** Allow policy-approved low-risk auto-remediation.

**Deliverables:**
- Autonomous execution for low-risk actions.
- Rollback handling.
- Post-execution verification.
- Escalation rules for failed or ambiguous fixes.

### Phase 6: Multi-Site Intelligence

**Goal:** Optimize for agency scale.

**Deliverables:**
- Fleet-level benchmarking.
- Cross-site issue clustering.
- Recommended playbooks by site type.
- SLA dashboards and remediation throughput metrics.
- Prompt-driven workflows initiated from the portal.[file:1]

## Recommended Technical Decisions

### WordPress implementation

- Use custom database tables for scans, observations, findings, actions, approvals, and logs rather than overloading post meta.
- Use Action Scheduler or WP-Cron-backed jobs for queued scans and background work.
- Wrap all mutating operations in typed service classes rather than endpoint callbacks.
- Abstract SEO-plugin-specific integrations behind adapters for Rank Math, Yoast, and AIOSEO.

### AI implementation

- Treat AI as a reasoning and drafting subsystem, not the primary source of truth.
- Use retrieval from local observations and findings to constrain prompts.
- Require structured responses such as JSON for any AI-produced recommendation that feeds the system.

### Security

- Require capability checks on every mutating endpoint.
- Sign outbound portal requests.
- Encrypt integration tokens at rest where possible.
- Maintain immutable audit logs for actions and approvals.

## Acceptance Criteria for MVP

### Product acceptance

- A site can run a complete local audit with no GSC or GA4 connection.[file:1]
- Findings are structured, filterable, and synchronized to the portal.[file:1]
- Low-risk actions can be drafted and dry-run safely.
- High-risk actions cannot execute without approval.

### Technical acceptance

- Scans are resumable or recoverable after interruption.
- Re-running the same scan does not create duplicate unresolved findings without deduplication logic.
- Every executed action has a corresponding log and verification result.
- External sync failures do not break local operation.

## Open Questions

- Should the plugin store a normalized page graph for all internal linking and orphan analysis, or compute it on demand?
- Which actions should be supported natively in MVP versus exported as portal tasks?
- How much remediation should be plugin-agnostic versus adapted to the active SEO plugin?
- Should AEO/GEO prompt baseline tests run in the plugin, the portal, or both?[file:1]
- What tenancy and auth model should the portal use for multi-site command execution?[file:1]

## Summary Direction

The source document already contains a valuable audit framework. The clearest next step is to operationalize it as a capability-based agentic system: deterministic observers and rules first, AI reasoning second, controlled actions third, and full autonomy only where risk is low and rollback is clear.[file:1]
