 Overall Assessment                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  
  Strong spec. The problem statement is accurate — the current codebase does have three separate noindex-filtering loops in rmb_serve_sitemap(), rmb_serve_llms_txt(), and rmb_sitemap_preview() that can diverge. The P0 shared canonical URL set is the    right architectural fix and everything else flows correctly from it. Priority triage, acceptance criteria, and Definition of Done are all sensible.                                                                                                      
                                         
  ---
  Clarifying Questions

  1. Intent classification — the biggest gap

  The spec proposes group_by_intent: true and a sections array (service_pages, location_pages, educational_articles, etc.), but doesn't define the algorithm that assigns a URL to a section. This is the hardest part of the entire spec and has three
  possible approaches:

  - Manual: the pipeline explicitly tags each post with its section via a new API call (most reliable, requires pipeline work)
  - Heuristic: classify by URL pattern, post type, WordPress category/tag (brittle, site-specific)
  - Hybrid: heuristic default, overridable per-post via a new rrseo_llms_section post-meta key

  Which is intended? Without an answer, group_by_intent cannot be implemented. The current sections config reads like an ordered list of output headings, but the classifier that puts a URL into a section isn't specified anywhere.

  2. business_facts — where does the data come from?

  The business_facts block in the /llms config (business_name, primary_services, service_area, phone, address, schema_type, entity_id) — is this:

  - Purely manual config written via POST /llms?
  - Or should it attempt to pull from _rrseo_schema_graph on the homepage/contact page (where LocalBusiness or Organization schema is typically stored)?

  Pulling from existing schema would be smarter and reduce double-entry, but adds complexity.

  3. Redirect detection — needs scoping

  "Exclude redirects where known" is vague. The plugin has no redirect registry. Options that are actually feasible without an external plugin:

  - WordPress's own redirect_canonical() behaviour (only catches some cases)
  - Checking for wp_redirect hooks (not reliable at query time)
  - Integration with Redirection plugin or .htaccess (out of scope per Non-Goals §3)

  Would you accept narrowing this to: "Exclude posts where WordPress get_permalink() returns a URL that differs from the post's stored slug due to redirect_canonical"? Or drop it from P0 entirely?

  4. Numeric suffix detection — edge cases need rules

  The spec says exclude -2/, -3/ duplicate URLs when a base URL exists. Two edge cases need answers:

  - /top-10-tips/ — the 10 looks like a numeric suffix but it's part of the original title. The safe detection rule is: match the pattern {slug}-{integer}/ and confirm a post with slug {slug} exists. Is that the intended check?
  - Orphan suffixes: test case 3 says keep /page-2/ if no /page/ exists. Is that intentional? A URL with a numeric suffix and no base strongly suggests a slug collision artefact — keeping it seems risky for llms.txt quality.

  5. exclude_utility_pages vs exclude_patterns — redundancy

  These two config keys are doing the same thing. exclude_utility_pages: true appears to be shorthand for "apply the default exclusion pattern list." If that's correct, the cleaner model is:

  {
    "exclude_patterns": ["-2/", "-3/", "/thank-you/", "..."]
  }

  where exclude_utility_pages: true simply merges the default set into exclude_patterns at generation time. Should these be collapsed into one config key?

  6. GET /images/{id}/alt — route conflict

  POST /images/{id}/alt already exists and is registered with a single-method definition. Adding GET requires changing the route registration to the two-entry array pattern (as used for /schema/{post_id}). Should the GET response match the POST
  response shape exactly? The spec shows a slightly different shape (adds title and caption, drops before/after/success) — is that intentional?

  7. Physical robots.txt + auto-inject

  When a physical robots.txt exists, the plugin's robots_txt filter is bypassed. The spec says the plugin should auto-inject the Sitemap: directive into its filtered output. But if a physical file exists, no injection happens regardless of config.

  Should the POST /robots-txt endpoint:
  - Warn if the submitted content is missing a Sitemap: directive AND offer to add it?
  - Or just store whatever is submitted and let the operator manage it?

  8. max_description_chars — truncation behaviour

  Should truncation happen at a word boundary (with …) or at a hard character limit? And does the 240-char limit apply to descriptions from all three fallback sources (SEO description, excerpt, first paragraph), or only to auto-generated fallbacks?

  ---
  Design Feedback

  Things that are solid

  - P0 Canonical URL Set helper — rr_get_canonical_url_set() as the single source of truth is exactly right. The current codebase has three independent noindex-filter loops; consolidating them eliminates the drift risk.
  - Description fallback chain (rrseo_description → excerpt → first_paragraph) — practical and well-ordered.
  - /llms/preview with format=text|json — the excluded[] and warnings[] arrays in the JSON response are exactly what audit tooling needs before applying changes.
  - physical_robots_txt_exists in /status — good operational signal.
  - Batch cap on /images/bulk-alt — straightforward, should just reuse RR_BATCH_MAX.
  - Test plan — unit + integration + manual QA, with specific scenarios. Test case 8 ("llms.txt and sitemap URL sets match for primary URLs") is the key regression guard for the whole spec.

  One concern — /status canonical URL count has a performance cost

  Computing canonical_url_count and llms_url_count in /status means running the full canonical URL set generator on every status call. For a site with 500+ posts this could be slow. Consider:

  - Computing once and caching in a transient (invalidated on save_post, delete_post)
  - Or making it opt-in: GET /status?include_counts=true

  rankmath-rest-bridge/v1 alias — note the original namespace

  The spec lists the P2 alias as rankmath-rest-bridge/v1. Worth confirming: the namespace the plugin originally used before v2.2.0 was rankmath-bridge/v1, not rankmath-rest-bridge/v1. If backward compat is the goal, clarify which old callers you're
  trying to support and which namespace they were using.

  The sections config order vs content

  The sections array controls output order but not which URLs go into each section. These are two separate concerns that the spec conflates. A cleaner model might be:

  "sections": {
    "core_business_pages": { "label": "Core Business Pages", "order": 1 },
    "service_pages":        { "label": "Service Pages",       "order": 2, "url_patterns": ["/services/"] },
    "location_pages":       { "label": "Location Pages",      "order": 3, "url_patterns": ["/locations/"] }
  }

  Where url_patterns is the classifier. This makes the classification algorithm explicit and configurable, which sidesteps the intent-inference problem entirely.