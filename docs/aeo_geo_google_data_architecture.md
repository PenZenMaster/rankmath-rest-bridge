# Architectural Design: AEO/GEO SEO Audit Data Layer

Prepared for: RankRocket SEO Audit / RankRocket SEO Control Layer  
Scope: Google Business Profile, Google Search Console, Google Analytics 4, AEO/GEO visibility, and AI sentiment evaluation  
Primary use case: Enrich SEO audits with connected Google data and AI-search-readiness analysis at audit time.

---

## 1. Purpose

This document defines the architecture for expanding the SEO audit system beyond public crawl data by connecting:

- **Google Business Profile (GBP)** for local profile configuration, reviews, profile actions, and local discovery data.
- **Google Search Console (GSC)** for organic query, page, CTR, position, sitemap, and index inspection data.
- **Google Analytics 4 (GA4)** for landing page behavior, engagement, events, conversions, geography, and channel attribution.
- **AEO/GEO visibility analysis** for AI answer engine readiness, entity clarity, source consistency, and AI sentiment.

The goal is to transform the audit from a public technical/on-page snapshot into a connected search, local, conversion, and AI-visibility intelligence layer.

---

## 2. Strategic Objectives

1. **Improve audit depth**
   - Move from public-only observations to verified performance and owner-dashboard data.

2. **Improve report specificity**
   - Replace generic scorecard interpretations with evidence-backed explanations from GSC, GA4, GBP, crawl data, and RankRocket.

3. **Support AEO/GEO readiness**
   - Evaluate whether AI engines can understand who the client is, what they do, where they serve, why they are credible, and which pages should be used as canonical sources.

4. **Connect visibility to conversion**
   - Join GSC impressions/clicks with GA4 sessions/events/conversions and GBP actions.

5. **Create an extensible data model**
   - Use one normalized canonical URL set as the join spine across website crawl, RankRocket, sitemap, llms.txt, GSC, GA4, and GBP.

---

## 3. System Overview

```text
User / Agency
   |
   | OAuth consent
   v
Google Connector Layer
   |
   | encrypted refresh tokens
   v
Data Fetchers
   |-------------------------|
   | GBP Fetcher             |
   | GSC Fetcher             |
   | GA4 Fetcher             |
   | Crawl / RankRocket      |
   | AI Visibility Tester    |
   |-------------------------|
   v
Canonical URL + Entity Normalizer
   |
   v
Audit Intelligence Engine
   |
   | scoring, recommendations, opportunity detection
   v
Report Generator
   |
   v
PDF / MD / Dashboard Audit Output
```

### System boundary

**WordPress Plugin (`rankmath-rest-bridge` — this repo)**

The plugin is the canonical data source for the client website. Its role in this architecture is strictly read-only data provider:

- Generates and exposes the canonical URL set via `GET /canonical-urls/preview`
- Serves per-post schema, SEO metadata, and llms.txt configuration via REST
- Generates the XML sitemap and `llms.txt` files
- Exposes a `GET /status` health endpoint the audit engine can ping before each run

The plugin does **not** perform OAuth, call Google APIs, or store audit data. It has no knowledge of the audit engine.

**AI Audit Engine (internal agent)**

The audit engine owns the full audit lifecycle:

- OAuth 2.0 consent flow and encrypted refresh-token storage (`google_connection`)
- Google API fetchers: GSC, GA4, GBP
- Canonical URL normalization, using the plugin REST output as one input source alongside GSC/GA4/GBP data
- Cross-source join logic, scoring, and opportunity detection
- Report generation (PDF, Markdown, dashboard)
- AI sentiment evaluation
- All persistent data storage (`audit_project`, `google_connection`, `gsc_search_row`, etc.)

**Interface between the two systems**

The audit engine calls the plugin REST API (authenticated with a WordPress application password) to:

- Pull the canonical URL set
- Pull per-post schema and SEO metadata
- Pull llms.txt configuration and section data
- Confirm plugin health before starting a run

No data flows from the audit engine back into the plugin in Phases 1–4.

---

## 4. Core Design Principle

### Canonical URL Set as the join spine

Every audit should build one canonical URL set and reuse it everywhere:

- XML sitemap
- `robots.txt` sitemap directive
- `llms.txt`
- PDF report source references
- GSC page URLs
- GA4 landing pages
- WordPress/RankRocket post IDs
- Schema page references
- AEO/GEO source guidance

This prevents a recurring issue where sitemap, llms.txt, reports, and plugin previews disagree.

### Canonical URL rules

Keep URLs that are:

- Published
- Publicly accessible
- HTTP 200
- Indexable
- Self-canonical or canonical to a URL in the set
- Not a duplicate suffix page
- Not redirected
- Not a utility page unless intentionally included

Exclude by default:

- `/thank-you/`
- `/privacy-policy/`
- `/opt-out-preferences/`
- cart, checkout, account, search result, feed, attachment, and tag archive URLs unless strategically needed
- numeric suffix duplicates such as `-2/`, `-3/`, `-4/`
- placeholder/test URLs
- 3xx, 4xx, 5xx, noindex, soft-404, and password-protected URLs

---

## 5. Google API Access Requirements

| Product | API | Primary scope | MVP mode |
|---|---|---|---|
| Google Search Console | Search Console API / Webmasters API | `https://www.googleapis.com/auth/webmasters.readonly` | Read-only |
| Google Analytics 4 | Google Analytics Data API | `https://www.googleapis.com/auth/analytics.readonly` | Read-only |
| Google Business Profile | Business Profile APIs | `https://www.googleapis.com/auth/business.manage` | Read-only behavior enforced by app |

### Write actions

Write actions should be out of scope for the MVP or require explicit confirmation:

- Submit/delete sitemap in GSC
- Add/delete GSC property
- Update GBP business data
- Reply to GBP reviews
- Create GBP posts
- Modify GA4 configuration

GBP uses the broad `business.manage` scope for many read operations, so the application must enforce read-only behavior unless the user explicitly initiates a write workflow.

---

## 6. OAuth and Security Model

### Recommended model

Use OAuth 2.0 user consent, not a shared service account, for the primary agency/client workflow.

Reasons:

- GSC access is tied to the user’s verified properties.
- GA4 access is tied to the user’s analytics properties.
- GBP access is tied to the user’s business accounts and locations.
- OAuth makes client onboarding easier than asking clients to grant service-account access manually across multiple products.

### Security requirements

- Encrypt refresh tokens at rest.
- Never log access tokens or refresh tokens.
- Store granted scopes.
- Store last successful token refresh.
- Store last successful data pull by connector.
- Allow users to disconnect/delete each connector.
- Use least privilege where Google provides a narrower scope.
- Keep GBP write actions behind application-level permission gates.
- Provide data retention settings for raw API data.

### OAuth verification

Production apps using Google sensitive scopes may require Google OAuth app verification, privacy policy disclosure, domain verification, scope justification, and possibly a demonstration video. The app should request only the scopes needed for the selected connector features.

---

## 7. Connector Discovery Flow

### Step 1: Connect Google account

The user selects one or more connectors:

- Google Search Console
- Google Analytics 4
- Google Business Profile

The OAuth flow should request only the scopes required for selected connectors.

### Step 2: Discover accessible assets

After consent:

- GSC: list accessible Search Console properties.
- GA4: list or select GA4 property ID.
- GBP: list accounts, then list locations.

### Step 3: Map assets to audit project

The user maps:

- Website domain -> GSC property
- Website domain -> GA4 property
- Business -> GBP location

### Step 4: Run health check

Before each audit:

- Token refresh succeeds.
- Required scopes are present.
- GSC property still exists and has sufficient permission.
- GA4 property returns data.
- GBP location is accessible.
- Mapped website/GBP/GA4/GSC domains appear consistent.

---

## 8. Google Search Console Architecture

### Data pulled

| Data | Purpose |
|---|---|
| Accessible properties | Match audit site to GSC property |
| Query performance | Identify demand, CTR gaps, intent coverage |
| Page performance | Identify organic landing page winners/losers |
| Query x page | Detect query-page fit and cannibalization |
| Page x device | Identify mobile/desktop performance gaps |
| Search appearance | Evaluate rich results visibility |
| Sitemap status | Confirm correct sitemap is submitted |
| URL Inspection sample | Confirm index/canonical status of priority URLs |

### Recommended GSC pulls

All pulls use a 28-day rolling window.

| Pull | Dimensions |
|---|---|
| Top queries | `query` |
| Top pages | `page` |
| Query-page matrix | `query,page` |
| Page-device matrix | `page,device` |
| Country/device | `country,device` |
| Search appearance | `searchAppearance` |
| Trend | `date` |

### GSC insights

- Low CTR opportunity
- Striking-distance keywords
- Query cannibalization
- High-impression pages with poor snippets
- Pages with organic demand but thin content
- Sitemap mismatch
- Indexing/canonical risk

---

## 9. Google Analytics 4 Architecture

### Data pulled

| Data | Purpose |
|---|---|
| Landing pages | Understand traffic and conversion quality |
| Source/medium | Identify traffic channel contribution |
| Events | Validate phone/form/outbound click tracking |
| Key events | Measure conversion readiness |
| Device category | Connect mobile experience to behavior |
| City/region | Validate service-area traffic |
| Engagement metrics | Identify weak landing pages |

### Recommended GA4 reports

All GA4 reports use a 28-day rolling window.

#### Landing page report

Dimensions:

- `landingPagePlusQueryString`
- `sessionDefaultChannelGroup`
- `deviceCategory`

Metrics:

- `sessions`
- `activeUsers`
- `engagedSessions`
- `engagementRate`
- `screenPageViews`
- `keyEvents`
- `sessionKeyEventRate`

#### Acquisition report

Dimensions:

- `sessionSourceMedium`
- `sessionDefaultChannelGroup`
- `landingPagePlusQueryString`

Metrics:

- `sessions`
- `activeUsers`
- `engagementRate`
- `keyEvents`

#### Event tracking report

Dimensions:

- `eventName`
- `landingPagePlusQueryString`

Metrics:

- `eventCount`
- `keyEvents`

#### Geography report

Dimensions:

- `city`
- `region`
- `country`

Metrics:

- `sessions`
- `activeUsers`
- `keyEvents`

### GA4 insights

- High traffic / low conversion pages
- High organic visibility / low engagement pages
- Missing phone click or form tracking
- Third-party booking or CRM attribution gaps
- Mobile traffic impacted by poor PageSpeed
- Market mismatch between service area and actual traffic

---

## 10. Google Business Profile Architecture

### Data pulled

| Data | Purpose |
|---|---|
| Account list | Discover accessible GBP accounts |
| Location list | Select business profile |
| Business name | NAP/entity consistency |
| Address/service area | Local SEO and SAB validation |
| Phone | NAP consistency |
| Website URL | GBP-to-site mapping |
| Categories | Primary/secondary category audit |
| Business description | Completeness and entity clarity |
| Hours/special hours | Completeness and trust |
| Reviews | Rating, velocity, sentiment, response rate |
| Performance metrics | Calls, website clicks, direction requests |
| Search keyword impressions | Local demand and content gap detection |

### GBP audit insights

- Category completeness
- Services/profile completeness
- Review count gap
- Review velocity
- Review response rate
- Review sentiment themes
- GBP action trends
- Search keyword demand
- GBP keyword-to-content gaps
- Schema reviewCount mismatch
- NAP mismatch

### GBP limitations

Public GBP views are not enough for a complete audit. A complete GBP audit requires owner-level or manager-level access to the profile through the GBP APIs or dashboard.

---

## 11. AEO/GEO Visibility Architecture

AEO/GEO analysis should evaluate whether AI answer engines can confidently understand and recommend the business.

### Inputs

- Canonical URL set
- `llms.txt`
- XML sitemap
- LocalBusiness + applicable trade or profession schema
- Service schema
- FAQPage schema
- BreadcrumbList schema
- AboutPage/ContactPage schema
- GBP profile data
- GBP reviews and themes
- GSC query data
- GBP search keyword impressions
- GA4 landing page engagement
- Social/citation profiles
- Competitor mentions

### AEO/GEO scoring categories

| Category | What it measures |
|---|---|
| Entity clarity | Can AI identify the business, location, category, and services? |
| Source consistency | Do website, GBP, schema, social, and citations agree? |
| Canonical source guidance | Do sitemap and llms.txt point AI to the right URLs? |
| Service answer coverage | Do service pages answer common buyer questions? |
| Local answer coverage | Do pages clearly define markets served? |
| Trust evidence | Are reviews, guarantees, photos, affiliations, and proof points visible? |
| Citation authority | Does the entity appear on trusted external profiles? |
| Structured data depth | Are relevant schema types present and accurate? |

### AEO/GEO report outputs

- AI Source Readiness score
- Entity Consistency table
- llms.txt and sitemap synchronization status
- Service answer coverage matrix
- Missing FAQ opportunities
- Missing schema opportunities
- Citation/source gap list
- AI prompt test plan

---

## 12. AI Sentiment Evaluation Architecture

AI sentiment should be evaluated by querying answer engines with a controlled prompt set and scoring the output.

### Prompt categories

| Prompt type | Example |
|---|---|
| Brand | “What do you know about [business]?” |
| Local | “Best [service] in [city]” |
| Service | “[specific service] near [city]” |
| Comparison | “[business] vs [competitor]” |
| Trust | “Is [business] reputable?” |
| Reviews | “What do customers say about [business]?” |

### Engines to test

- ChatGPT
- Perplexity
- Gemini
- Bing Copilot
- Google AI Overview observations where available

### Sentiment scoring fields

| Field | Meaning |
|---|---|
| Mentioned | Whether the business appears |
| Position | Where it appears among competitors |
| Sentiment | Positive, neutral, negative |
| Accuracy | Whether facts are correct |
| Source quality | Which sources are cited or implied |
| Hallucination risk | Incorrect claims or fabricated details |
| Competitive framing | How the business is positioned against competitors |

### AI sentiment report outputs

- AI Visibility Matrix
- AI Sentiment Matrix
- Brand accuracy notes
- Incorrect claim list
- Source/citation recommendations
- Content/schema fixes to influence future AI answers

---

## 13. Cross-Source Insight Engine

The intelligence layer should merge connected data into actionable findings.

| Finding | Data sources | Logic |
|---|---|---|
| Low CTR page | GSC + crawl | High impressions, low CTR, weak title/meta |
| Conversion leak | GA4 + crawl | High sessions, low key event rate, weak CTA |
| GBP tracking gap | GBP + GA4 | GBP website clicks exist but GA4 attribution/event tracking weak |
| Content gap | GSC + GBP + crawl | Search terms appear in GSC/GBP but not in site content |
| Schema mismatch | GBP + crawl | GBP review/rating differs from LocalBusiness schema |
| Local pack gap | GBP + competitor research | Review/category/profile gaps vs top competitors |
| AI source gap | llms.txt + sitemap + schema | AI guidance files inconsistent or thin |
| Performance priority | GA4 + PageSpeed | Poor mobile speed on high-traffic landing pages |

---

## 14. Normalized Data Model

### `audit_project`

| Field | Type |
|---|---|
| `id` | string |
| `site_url` | string |
| `business_name` | string |
| `market` | string |
| `audit_date` | datetime |

### `google_connection`

| Field | Type |
|---|---|
| `id` | string |
| `user_id` | string (agency user who completed the OAuth flow) |
| `provider` | enum: `gsc`, `ga4`, `gbp` |
| `refresh_token_encrypted` | string (AES-256; never logged) |
| `granted_scopes` | array |
| `status` | enum |
| `last_refresh_at` | datetime |
| `last_successful_pull_at` | datetime |

### `audit_source_mapping`

| Field | Type |
|---|---|
| `audit_project_id` | string |
| `gsc_site_url` | string |
| `ga4_property_id` | string |
| `gbp_account_id` | string |
| `gbp_location_id` | string |

### `canonical_url`

| Field | Type |
|---|---|
| `url` | string |
| `type` | enum |
| `wp_post_id` | integer |
| `indexable` | boolean |
| `in_sitemap` | boolean |
| `in_llms` | boolean |

### `gsc_search_row`

| Field | Type |
|---|---|
| `audit_project_id` | string |
| `pulled_at` | datetime |
| `query` | string |
| `page` | string |
| `device` | string |
| `country` | string |
| `clicks` | number |
| `impressions` | number |
| `ctr` | number |
| `position` | number |

### `ga4_landing_page_row`

| Field | Type |
|---|---|
| `audit_project_id` | string |
| `pulled_at` | datetime |
| `landing_page` | string |
| `channel_group` | string |
| `source_medium` | string |
| `device_category` | string |
| `city` | string |
| `sessions` | number |
| `engagement_rate` | number |
| `key_events` | number |
| `session_key_event_rate` | number |

### `gbp_location_snapshot`

| Field | Type |
|---|---|
| `location_id` | string |
| `title` | string |
| `primary_category` | string |
| `additional_categories` | array |
| `address` | object |
| `service_area` | object |
| `phone` | string |
| `website_uri` | string |
| `profile_description` | string |
| `regular_hours` | object |
| `place_id` | string |

### `gbp_review`

| Field | Type |
|---|---|
| `review_id` | string |
| `rating` | number |
| `text` | string |
| `create_time` | datetime |
| `reply_text` | string |
| `sentiment` | enum |
| `themes` | array |

### `ai_sentiment_result`

| Field | Type |
|---|---|
| `engine` | string |
| `prompt` | string |
| `business_mentioned` | boolean |
| `position` | integer |
| `sentiment` | enum |
| `accuracy_notes` | string |
| `sources` | array |
| `hallucination_flags` | array |

---

## 15. Report Architecture

Add the following report sections.

### Connected Data Status

- GSC connection status
- GA4 connection status
- GBP connection status
- Selected property/location
- Date ranges used
- Last successful data pull
- Missing access warnings

### Search Demand and Visibility

- Top queries
- Top pages
- Branded vs non-branded split
- Low CTR opportunities
- Striking-distance keywords
- Cannibalization issues

### Traffic and Conversion Quality

- Organic landing page sessions
- Engagement by page
- Key event rate by page
- Source/medium attribution
- Device and geography breakdown
- Tracking gaps

### GBP Performance

- Profile completeness
- Category review
- Review count and velocity
- Review response rate
- GBP actions
- GBP search keyword impressions
- Competitor review gap

### AEO/GEO Visibility

- Entity clarity
- Source consistency
- Schema depth
- llms.txt/sitemap synchronization
- AI answer coverage
- Citation/source gaps

### AI Sentiment

- Prompt matrix
- Engine-by-engine visibility
- Sentiment
- Accuracy
- Hallucinations
- Competitor framing

### Cross-Source Opportunities

- Highest-impact combined findings
- Examples:
  - High GSC impressions + low CTR + weak title
  - High GA4 sessions + low conversion + weak CTA
  - GBP keyword demand + missing service content
  - Public schema review count mismatches GBP

---

## 16. Scorecard Improvements

The report should stop using generic “Meaning” text. Each score should explain why the score is what it is.

### Recommended scorecard columns

| Pillar | Score | Target | Interpretation | What prevents 90+ |
|---|---:|---:|---|---|

### Example interpretation

> Technical SEO scored 74/100. The site has clean canonical URLs and RankRocket sitemap generation, but mobile LCP, robots.txt sitemap mismatch, and llms.txt synchronization prevent the score from reaching the 90+ range.

### Data-depth badge

Add a badge to every report:

| Badge | Meaning |
|---|---|
| Public-only audit | No connected Google data |
| Search-connected audit | GSC connected |
| Analytics-connected audit | GA4 connected |
| Local-connected audit | GBP connected |
| Full connected audit | GSC + GA4 + GBP connected |

---

## 17. Implementation Phases

### Phase 1: Connector foundation

P0:

- OAuth flow
- Token storage and refresh
- Scope tracking
- Connection health checks
- GSC property discovery
- GA4 property selection
- GBP account/location discovery

### Phase 2: Read-only data ingestion

P0:

- GSC search analytics
- GSC sitemap status
- GSC URL Inspection sampling
- GA4 landing pages
- GA4 events/key events
- GA4 source/medium/device/geography
- GBP business profile snapshot
- GBP reviews
- GBP performance metrics
- GBP search keyword impressions

### Phase 3: Normalization and joins

P0:

- Canonical URL normalization
- GSC page mapping
- GA4 landing page mapping
- GBP website URL mapping
- RankRocket post ID mapping
- Joined opportunity tables

### Phase 4: Report generation

P0:

- Connected Data Status
- Search Demand section
- Traffic and Conversion section
- GBP Performance section
- AEO/GEO section
- Cross-Source Opportunities
- Improved score interpretations

### Phase 5: AI sentiment and monitoring

P1:

- Prompt library
- Engine testing
- Sentiment scoring
- Source/citation analysis
- Scheduled re-audits

### Phase 6: Optional write workflows

P2:

- GSC sitemap submission
- GBP review response drafting
- GBP profile update recommendations
- GA4 tracking QA recommendations

All write workflows require explicit user confirmation.

---

## 18. MVP Acceptance Criteria

### GBP

Given a connected Google account with GBP access, when the user selects a business location, then the audit can retrieve profile fields, categories, address/service area, website URL, hours, reviews, review count, average rating, performance metrics, and search keyword impressions.

### GSC

Given a connected Google account with Search Console access, when the user selects a property, then the audit can retrieve query/page performance, sitemap status, and sampled URL Inspection data.

### GA4

Given a connected Google account with GA4 access, when the user selects a property, then the audit can retrieve landing page sessions, engagement, key events, source/medium, device, city/region, and event data.

### AEO/GEO

Given crawl data, RankRocket canonical URL data, schema, sitemap, llms.txt, and connected Google data, when the audit runs, then the report evaluates entity clarity, source consistency, AI answer coverage, schema depth, and AI sentiment readiness.

### Reporting

Given connected data is available, when the PDF report is generated, then score interpretations must cite specific evidence instead of generic score text.

---

## 19. Open Questions

1. ~~Should RankRocket provide a shared OAuth app, or should each agency configure its own Google Cloud OAuth app?~~
   **Resolved:** RankRocket provides one shared OAuth app for the MVP. Per-agency Google Cloud projects are a future advanced option.

2. ~~Should raw API data be stored or only normalized summaries?~~
   **Resolved:** Store raw rows during the audit run; purge raw rows after a configurable retention window (default 90 days). Keep normalized summaries permanently for trend comparison.

3. Should GBP write actions be excluded entirely from v1?
4. Should AI sentiment be one-time audit-only or scheduled recurring monitoring?
5. Should GSC URL Inspection inspect all canonical URLs or only a priority sample?
6. Should conversion readiness become a fifth score or remain part of On-Page/Content/Local scoring?
7. Should AEO/GEO receive its own score or be represented as a report section only?

---

## 20. Developer Notes

- Keep the MVP read-only.
- Use the canonical URL set as the join spine.
- Do not silently omit disconnected sections; show explicit “Not connected,” “No data,” or “Access denied” states.
- Store date ranges and last successful pull timestamps.
- Cache API results during each audit run.
- Handle quota errors gracefully.
- Warn when GSC, GA4, GBP, website canonical, sitemap, or llms.txt data disagree.
- GBP public view is insufficient for a complete local audit; owner/manager access is required.
- AEO/GEO visibility depends on entity consistency, schema depth, canonical source guidance, and review/source authority.

