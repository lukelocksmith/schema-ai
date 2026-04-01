# Schema AI - WordPress Plugin Design Spec

**Date:** 2026-03-25
**Author:** Lukasz + Claude
**Status:** Approved

## Overview

Custom WordPress plugin that uses Google Gemini AI to automatically generate and inject schema.org JSON-LD structured data for posts and pages. Full automation on publish, manual editing capability, bulk processing for existing content, dashboard with statistics, REST API, WP-CLI, and operation logging.

**Target site:** https://important.is (Polish, blog + agency + services)

## Why custom plugin (not fork/existing)

Reviewed existing solutions:
- **SchemaGenius AI** (~3000 LOC, 10 installs): uses deprecated `gemini-pro`, sends only 50-word excerpt, hardcoded type detection, security issues (`unserialize`, API key in URL), no tests
- **PlugStudio AI SEO**: AI does NOT generate schema at all - only finds Wikipedia entity + writes summary. Hardcoded 6-field Article schema. Marketing misleading.

Neither is worth forking. Building from scratch gives us: modern Gemini model, intelligent content analysis, full automation, proper security.

---

## Architecture

### Plugin name: `schema-ai`

### File structure

```
schema-ai/
├── schema-ai.php                    # Bootstrap, activation/deactivation hooks
├── includes/
│   ├── class-schema-ai-core.php     # Singleton, loads dependencies
│   ├── class-schema-ai-gemini.php   # Gemini API client (2.0 Flash)
│   ├── class-schema-ai-analyzer.php # Content analysis → schema type + data
│   ├── class-schema-ai-generator.php# Builds JSON-LD from AI response
│   ├── class-schema-ai-validator.php# Validation per schema.org required fields
│   ├── class-schema-ai-frontend.php # Injects JSON-LD in wp_head
│   ├── class-schema-ai-bulk.php     # Bulk processor (Action Scheduler)
│   ├── class-schema-ai-logger.php   # Operation log (custom table)
│   ├── class-schema-ai-cache.php    # Transient cache with hash invalidation
│   ├── class-schema-ai-rest.php     # REST API endpoints
│   └── class-schema-ai-cli.php      # WP-CLI commands
├── admin/
│   ├── class-schema-ai-admin.php    # Menu, settings page, dashboard
│   ├── class-schema-ai-metabox.php  # Meta box in post editor
│   ├── views/
│   │   ├── dashboard.php            # Stats, log, overview
│   │   ├── settings.php             # API key, model, defaults
│   │   └── bulk.php                 # Bulk generator UI
│   ├── js/admin.js                  # Vanilla JS (zero jQuery)
│   └── css/admin.css
└── languages/
    └── schema-ai.pot
```

### Data storage

- **`_schema_ai_data`** post meta - JSON string with generated schema
- **`_schema_ai_type`** post meta - detected type (Article, HowTo, FAQ...)
- **`_schema_ai_status`** post meta - `auto|manual|edited|error|none`
- **`wp_schema_ai_log`** custom table - operation log (post_id, action, timestamp, tokens_used, model, status)

### Auto-generation flow

```
Post published/updated
    → hook: save_post / transition_post_status
    → Guards: skip if autosave, revision, non-published status, or bulk-in-progress lock
    → Analyzer: parse content (headings, lists, Q&A patterns)
    → Content prep: strip HTML for token count, truncate at 6000 tokens if needed
    → Gemini API: full content + metadata → JSON-LD
    → Response parsing: extract JSON from potential markdown fences, validate JSON syntax
    → Validator: check required fields per detected type
    → Save to post_meta
    → Cache invalidation
    → Log entry
```

**Hook guards (critical):**
- Skip `wp_is_post_autosave()` and `wp_is_post_revision()`
- Only fire on `publish` status (not draft, pending, private)
- Check `_schema_ai_lock` transient to avoid collision with bulk processor
- Skip if post type not in enabled list
- Skip if category is in exclude list

---

## AI Engine & Type Detection

### Gemini API Client

- **Model:** `gemini-2.0-flash` (fast, cheap, good enough for structured data)
- **API key:** in header `x-goog-api-key` (NOT in URL)
- **Temperature:** `0.1` (deterministic output, not creative)
- **Max tokens:** 4096
- **Retry:** 1 retry after 2s on general failure; exponential backoff (2s, 4s, 8s) on HTTP 429 rate limit
- **Content limit:** Strip HTML tags for token estimation (~4 chars/token), truncate at 6000 tokens (~24000 chars). If truncated, append note to prompt: "Content truncated. Generate schema based on available content."
- **Response parsing:** 1) Try `json_decode` directly. 2) If fails, regex extract from markdown fences (```json...```). 3) If still fails, retry once with appended instruction "Return raw JSON only, no markdown fencing." 4) If all fails → status `error`, log details.

### Two-stage type detection

**Stage 1 - PHP pre-analysis (free, no AI):**

```
Post content → parse HTML:
- Headings with "?" → FAQ signal
- Ordered lists / "Krok 1", "Step 1" → HowTo signal
- Comparison tables → Article/Review signal
- Post type "product" → Product
- Category/tag hints
→ Result: suggested_type + confidence
```

**Stage 2 - Gemini (confirms/corrects + generates):**

```
You are a schema.org structured data expert. Analyze this WordPress post
and generate valid JSON-LD markup.

POST METADATA:
- Title: {title}
- URL: {url}
- Published: {date}
- Modified: {modified}
- Author: {author_name}
- Featured image: {image_url}
- Categories: {categories}
- Tags: {tags}
- Language: {locale}

FULL CONTENT (HTML):
{post_content}

PHP PRE-ANALYSIS SUGGESTS: {suggested_type} (confidence: {confidence})

INSTRUCTIONS:
1. Determine the BEST schema.org type for this content. Choose from:
   Article, BlogPosting, HowTo, FAQPage, NewsArticle, Review,
   TechArticle, Product, Service, Event, Organization, LocalBusiness,
   Recipe, VideoObject, Course, SoftwareApplication
2. Generate complete JSON-LD with ALL required and recommended properties
3. For HowTo: extract actual steps from content
4. For FAQPage: extract actual Q&A pairs from content
5. For Article/BlogPosting: include full author, publisher, image objects
6. Use @graph if multiple types apply (e.g. Article + FAQPage)
7. Publisher is always: {"@type": "Organization", "name": "{site_name}", "url": "{site_url}"}
8. Generate all property values (name, description, step text, etc.) in the SAME LANGUAGE as the content
9. Always include @context: "https://schema.org"

Return ONLY valid JSON-LD. No explanations, no markdown, no code fences.
```

### Supported schema types (16)

| Type | When AI selects it |
|------|-------------------|
| Article | Default for blog posts |
| BlogPosting | Blog post with date, author |
| TechArticle | Technical tutorial |
| HowTo | Steps, instructions, "how to" |
| FAQPage | Q&A pairs in content |
| NewsArticle | News, current events |
| Review | Product/service review |
| Product | Product page |
| Service | Service page |
| Organization | About company |
| LocalBusiness | Business with physical address |
| Event | Event with date/location |
| Recipe | Cooking recipe |
| VideoObject | Page with embedded video |
| Course | Course/training |
| SoftwareApplication | App/tool page |

---

## Admin UI

### Settings Page (`Schema AI → Settings`)

- **API Key** - text field, stored as `schema_ai_api_key` in `wp_options` (encrypted with `openssl_encrypt` using `wp_salt('auth')`)
- **Model** - dropdown: `gemini-2.0-flash` (default), `gemini-2.5-flash`, `gemini-2.5-pro`
- **Auto-generate** - checkbox: auto-generate on publish/update (default: ON)
- **Post types** - multi-checkbox: which post types to handle (default: post, page)
- **Default publisher** - organization name + URL + logo (used in every schema)
- **Exclude categories** - exclude categories from auto-generation

### Meta Box in editor (`Schema AI`)

```
┌─ Schema AI ──────────────────────────────────┐
│                                               │
│  Status: ✅ Auto-generated  |  Type: HowTo   │
│  Generated: 2026-03-25 14:30  |  Tokens: 847 │
│                                               │
│  ┌─ JSON-LD Preview ───────────────────────┐  │
│  │ {                                       │  │
│  │   "@context": "https://schema.org",     │  │
│  │   "@type": "HowTo",                     │  │
│  │   "name": "Jak napisać...",             │  │
│  │   ...                                   │  │
│  │ }                                       │  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  [Regenerate]  [Edit manually]  [Remove]      │
│                                               │
│  Validation: ✅ All required fields present   │
└───────────────────────────────────────────────┘
```

- **Regenerate** - calls AI again (e.g. after content edit)
- **Edit manually** - opens textarea for manual JSON-LD edit, status → `edited`
- **Remove** - deletes schema, status → `none`
- **Validation** - inline feedback: green or red with list of missing fields

### Dashboard (`Schema AI → Dashboard`)

```
┌─ Overview ───────────────────────────────────┐
│                                               │
│  Total posts: 127                             │
│  With schema: 98 (77%)                        │
│  Without schema: 24 (19%)                     │
│  Errors: 5 (4%)                               │
│                                               │
│  Schema types breakdown:                      │
│  Article: 45  |  HowTo: 23  |  FAQ: 12       │
│  BlogPosting: 18                              │
│                                               │
├─ Recent Activity ────────────────────────────┤
│                                               │
│  14:30  "Jak napisać skill..."  HowTo  ✅     │
│  14:28  "WordPress w 2026"      Article ✅     │
│  14:25  "AI modele"             Article ⚠️    │
│         → Missing: image                      │
│                                               │
├─ API Usage ──────────────────────────────────┤
│                                               │
│  Today: 12 calls  |  Tokens: 8,420           │
│  This month: 234 calls  |  Tokens: 156,800   │
│                                               │
└───────────────────────────────────────────────┘
```

### Bulk Generator (`Schema AI → Bulk Generate`)

```
┌─ Bulk Generator ─────────────────────────────┐
│                                               │
│  Post type: [Posts]                           │
│                                               │
│  Posts without schema: 24                     │
│  Posts with errors: 5                         │
│                                               │
│  [x] Generate missing only                    │
│  [ ] Regenerate ALL (overwrites existing)     │
│  [ ] Include edited schemas                   │
│                                               │
│  Batch size: [10]  Delay: [2s]                │
│                                               │
│  [Start Bulk Generation]                      │
│                                               │
│  Progress: ████████░░░░░░░░ 12/24 (50%)      │
│  ETA: ~24 seconds                             │
│  [Pause]  [Cancel]                            │
│                                               │
│  Log:                                         │
│  ✅ "Post title 1" → Article (1.2s, 340 tok) │
│  ✅ "Post title 2" → HowTo (1.8s, 612 tok)  │
│  ❌ "Post title 3" → API error (retry 1/1)   │
│                                               │
└───────────────────────────────────────────────┘
```

- Uses **Action Scheduler** (WooCommerce library) instead of WP-Cron
- Pause/Resume/Cancel in real-time
- Configurable batch size and delay (rate limiting)
- Separate "regenerate all" option with confirmation

---

## REST API Endpoints

```
GET    /wp-json/schema-ai/v1/schema/{post_id}     # Get schema for post
POST   /wp-json/schema-ai/v1/schema/{post_id}     # Generate/regenerate schema
PUT    /wp-json/schema-ai/v1/schema/{post_id}     # Update schema manually
DELETE /wp-json/schema-ai/v1/schema/{post_id}     # Remove schema
GET    /wp-json/schema-ai/v1/stats                 # Dashboard statistics
GET    /wp-json/schema-ai/v1/log                   # Operation log (paginated)
POST   /wp-json/schema-ai/v1/bulk/start            # Start bulk generation
POST   /wp-json/schema-ai/v1/bulk/pause            # Pause bulk
POST   /wp-json/schema-ai/v1/bulk/cancel           # Cancel bulk
GET    /wp-json/schema-ai/v1/bulk/status            # Bulk progress
```

**Permissions:**
- Per-post endpoints (GET/POST/PUT/DELETE schema): require `edit_post` capability for the specific post
- Admin endpoints (stats, log, bulk): require `manage_options`

---

## WP-CLI Commands

```bash
wp schema-ai generate <post_id>        # Generate schema for single post
wp schema-ai bulk [--post-type=post]   # Bulk generate for post type
wp schema-ai status                    # Show dashboard stats
wp schema-ai validate <post_id>        # Validate existing schema
wp schema-ai remove <post_id>          # Remove schema from post
wp schema-ai log [--limit=20]          # Show recent log entries
```

---

## Validator - Required Fields per Type

Based on schema.org and Google Rich Results requirements:

| Type | Required fields |
|------|----------------|
| Article/BlogPosting | headline, author, datePublished, image, publisher |
| TechArticle | headline, author, datePublished |
| HowTo | name, step[] (each: name, text) |
| FAQPage | mainEntity[] (each: name, acceptedAnswer.text) |
| NewsArticle | headline, author, datePublished, image, publisher |
| Review | itemReviewed, author, reviewRating |
| Product | name, image, offers (price, priceCurrency, availability) |
| Service | name, provider, description |
| Organization | name, url |
| LocalBusiness | name, address, telephone |
| Event | name, startDate, location |
| Recipe | name, image, recipeIngredient[], recipeInstructions[] |
| VideoObject | name, description, thumbnailUrl, uploadDate |
| Course | name, description, provider |
| SoftwareApplication | name, operatingSystem, applicationCategory |

---

## Security

- API key encrypted with `openssl_encrypt('aes-256-cbc')` using `wp_salt('auth')` as key material, stored in `wp_options`
- API key sent via `x-goog-api-key` header (not URL parameter)
- All REST endpoints require `manage_options` capability
- All AJAX handlers verify nonce + capability
- JSON-LD output sanitized via `wp_json_encode()` with `JSON_UNESCAPED_SLASHES`
- No `unserialize()` anywhere - only `json_decode`/`json_encode`
- Input sanitization on all user inputs

---

## Performance

- Transient cache for generated schemas (invalidated on post update)
- Conditional asset loading (admin JS/CSS only on plugin pages)
- Action Scheduler for bulk (no PHP timeout issues)
- Configurable delay between API calls (rate limiting)
- Schema output cached per post (not regenerated on every page view)

---

## Dependencies

- **WordPress 6.0+**
- **PHP 8.0+**
- **Action Scheduler** (bundled, same as WooCommerce uses)
- **Google Gemini API** (external, free tier available)

---

## Conflict Detection

On activation, the plugin scans for known schema plugins:
- Yoast SEO, RankMath, SEOPress, AIOSEO, Schema Pro
- Check: `is_plugin_active()` for known slugs
- If found: show admin notice warning about potential duplicate schemas
- Option: "Disable Schema AI output for posts that already have schema from another plugin" (detect existing `<script type="application/ld+json">` in `wp_head` output)

Frontend output hook runs at priority `99` (late) so it can check if other plugins already injected schema.

---

## Uninstall & Cleanup

**Deactivation (`register_deactivation_hook`):**
- Cancel all pending Action Scheduler jobs
- Clear all transients with `schema_ai_` prefix

**Uninstall (`uninstall.php`):**
- Drop `wp_schema_ai_log` custom table
- Delete all `_schema_ai_*` post meta from `wp_postmeta`
- Delete all `schema_ai_*` options from `wp_options`
- Remove Action Scheduler hooks

**Settings page** includes "Clean up all data" button with confirmation dialog.

---

## Versioning & Migration

- `schema_ai_db_version` option tracks current DB schema version
- On plugin update, `upgrade_routine()` compares stored version to current
- Each migration is a numbered function (e.g., `migrate_to_1_1()`)
- Migrations run once and update the version option

---

## Action Scheduler Conflict Prevention

- Do NOT bundle Action Scheduler directly
- Use the standard pattern: check if `action_scheduler_initialize` function exists (WooCommerce already loaded it), if not, require the bundled copy from `vendor/woocommerce/action-scheduler/`
- This prevents version conflicts when WooCommerce is present

---

## Multilingual Support

- Prompt includes `{locale}` (e.g., `pl_PL`) and instruction: "Generate all schema property values (name, description, step text) in the same language as the content"
- Compatible with WPML/Polylang: each translation is a separate post with its own `_schema_ai_data` meta
- No special integration needed - standard WP post meta per translation

---

## Estimated size

~4000-5000 lines of PHP + ~300 lines JS + ~200 lines CSS

---

## Research sources

- SEOPress "Master Google Structured Data Types" guide (Feb 2021)
- SchemaGenius AI plugin code review (v2.0.0, ~3077 LOC)
- PlugStudio AI SEO & GEO plugin code review (v2.0.1, ~445 LOC)
- schema.org official documentation
- Google Rich Results documentation
