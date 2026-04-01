# Schema AI Plugin - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WordPress plugin that uses Gemini AI to auto-generate schema.org JSON-LD for posts/pages on publish, with bulk processing, manual editing, dashboard stats, REST API, and WP-CLI.

**Architecture:** PHP 8.0+ WordPress plugin with singleton Core class that loads service classes. Each service registers its own WP hooks. Gemini API called via wp_remote_post with header auth. Data stored in post_meta + one custom log table.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, Google Gemini 2.0 Flash API, Action Scheduler, Vanilla JS

**Spec:** `docs/superpowers/specs/2026-03-25-schema-ai-plugin-design.md`

---

## File Map

| File | Responsibility |
|------|---------------|
| `schema-ai.php` | Plugin bootstrap, activation/deactivation hooks, autoloader |
| `includes/class-schema-ai-core.php` | Singleton, loads all service classes |
| `includes/class-schema-ai-gemini.php` | Gemini API client: call, retry, parse response |
| `includes/class-schema-ai-analyzer.php` | PHP pre-analysis of content - suggested type + confidence |
| `includes/class-schema-ai-generator.php` | Orchestrates: analyzer - gemini - validator - save |
| `includes/class-schema-ai-validator.php` | Required fields per schema type, validation |
| `includes/class-schema-ai-frontend.php` | Injects JSON-LD in wp_head, conflict detection |
| `includes/class-schema-ai-bulk.php` | Bulk processor with Action Scheduler |
| `includes/class-schema-ai-logger.php` | Custom table CRUD for operation log |
| `includes/class-schema-ai-cache.php` | Transient cache with content-hash invalidation |
| `includes/class-schema-ai-rest.php` | REST API endpoints |
| `includes/class-schema-ai-cli.php` | WP-CLI commands |
| `admin/class-schema-ai-admin.php` | Admin menus, settings page, dashboard |
| `admin/class-schema-ai-metabox.php` | Editor meta box + AJAX handlers |
| `admin/views/dashboard.php` | Dashboard stats template |
| `admin/views/settings.php` | Settings page template |
| `admin/views/bulk.php` | Bulk generator template |
| `admin/js/admin.js` | Vanilla JS: meta box, bulk progress, settings |
| `admin/css/admin.css` | Admin styles |
| `uninstall.php` | Cleanup on uninstall |

---

## Task 1: Plugin Bootstrap and Core

**Files:**
- Create: `schema-ai.php`
- Create: `includes/class-schema-ai-core.php`

- [ ] **Step 1: Create main plugin file `schema-ai.php`**

See spec for plugin header. Bootstrap defines constants (VERSION, DB_VERSION, FILE, DIR, URL, BASENAME), registers PSR-4 style autoloader for `Schema_AI_` prefix classes in `includes/` and `admin/` dirs. Registers activation hook calling `Schema_AI_Core::activate()`, deactivation hook calling `Schema_AI_Core::deactivate()`, and `plugins_loaded` hook calling `Schema_AI_Core::instance()->init()`.

- [ ] **Step 2: Create Core singleton `includes/class-schema-ai-core.php`**

Singleton with `instance()`, `init()`, `activate()`, `deactivate()`, `maybe_upgrade()`. Also has static `encrypt()` and `decrypt()` using `openssl_encrypt('aes-256-cbc')` with `wp_salt('auth')` as key. `init()` loads Action Scheduler if not present, loads text domain, instantiates and calls `init()` on all service classes. `activate()` creates DB table, sets defaults, runs conflict detection. `deactivate()` cancels Action Scheduler jobs, clears transients.

- [ ] **Step 3: Commit**

---

## Task 2: Logger (Custom Table)

**Files:**
- Create: `includes/class-schema-ai-logger.php`

- [ ] **Step 1: Create Logger class**

Static methods: `create_table()` using dbDelta for `wp_schema_ai_log` table (id, post_id, action, schema_type, status, tokens_used, model, duration_ms, error_message, created_at). `log(array $data)` inserts row. `get_recent(int $limit, int $offset)` with JOIN to posts for title. `get_stats()` returns today/month calls and tokens.

- [ ] **Step 2: Commit**

---

## Task 3: Cache Manager

**Files:**
- Create: `includes/class-schema-ai-cache.php`

- [ ] **Step 1: Create Cache class**

Uses WP transients with `schema_ai_` prefix. `get(post_id)` checks content hash match. `set(post_id, schema)` stores JSON with hash. `delete(post_id)`. Content hash is md5 of post_content + post_title + post_modified. Hooks into `save_post` at priority 5 to invalidate.

- [ ] **Step 2: Commit**

---

## Task 4: Gemini API Client

**Files:**
- Create: `includes/class-schema-ai-gemini.php`

- [ ] **Step 1: Create Gemini client**

`generate(string $prompt)` method returns `array{success, data?, error?, tokens_used?, model?}`. Uses `wp_remote_post` to Gemini API v1beta endpoint with `x-goog-api-key` header auth. Sets `responseMimeType: application/json`, temperature 0.1, maxOutputTokens 4096. Has `make_request()` with rate limit handling (exponential backoff 2s/4s/8s on 429). `parse_json_response()` tries: 1) direct json_decode, 2) regex extract from markdown fences, 3) find JSON boundaries { ... }. If parse fails, retries prompt with explicit "return raw JSON" instruction. `truncate_content()` strips HTML for estimation, truncates at 24000 chars.

- [ ] **Step 2: Commit**

---

## Task 5: Content Analyzer

**Files:**
- Create: `includes/class-schema-ai-analyzer.php`

- [ ] **Step 1: Create Analyzer class**

`analyze(int $post_id)` returns `array{type, confidence}`. Checks: post_type product = Product, headings with "?" = FAQ, ordered lists / "Krok/Step N" = HowTo, comparison words in title = Review, date patterns + event words = Event, recipe keywords = Recipe, video embeds = VideoObject, code blocks = TechArticle. Scoring system: highest signal wins. Confidence: high (>=10), medium (>=5), low (default).

- [ ] **Step 2: Commit**

---

## Task 6: Validator

**Files:**
- Create: `includes/class-schema-ai-validator.php`

- [ ] **Step 1: Create Validator class**

`REQUIRED_FIELDS` const maps 16 types to their required fields per Google Rich Results spec. `validate(array $schema)` handles `@graph` by validating each item. `validate_single()` checks @type exists, required fields present, type-specific validation (HowTo steps have name/text, FAQ mainEntity has name + acceptedAnswer.text). Returns `{valid, errors[], warnings[]}`.

- [ ] **Step 2: Commit**

---

## Task 7: Schema Generator (Orchestrator)

**Files:**
- Create: `includes/class-schema-ai-generator.php`

- [ ] **Step 1: Create Generator class**

Hooks into `transition_post_status` at priority 20. `on_publish()` has all guards: status must be publish, auto-generate enabled, supported post type, not autosave/revision, no bulk lock transient, not in excluded categories, not status=edited. `generate_for_post(int $post_id)` orchestrates: calls Analyzer, builds prompt with full metadata (title, URL, dates, author, image, categories, tags, locale, publisher info), calls Gemini, ensures @context, validates, saves to post_meta (_schema_ai_data, _schema_ai_type, _schema_ai_status), invalidates cache, logs to DB. Returns `{success, type?, error?}`.

The prompt template follows the spec exactly (9 instructions including language matching).

- [ ] **Step 2: Commit**

---

## Task 8: Frontend Output

**Files:**
- Create: `includes/class-schema-ai-frontend.php`

- [ ] **Step 1: Create Frontend class**

Hooks `wp_head` at priority 99 (late, for conflict detection). Only outputs on `is_singular()`. Gets `_schema_ai_data` meta, validates JSON, outputs via `wp_json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT` inside `<script type="application/ld+json">` tag.

- [ ] **Step 2: Commit**

---

## Task 9: Admin Settings and Menu

**Files:**
- Create: `admin/class-schema-ai-admin.php`
- Create: `admin/views/settings.php`
- Create: `admin/views/dashboard.php`

- [ ] **Step 1: Create Admin class**

Registers menu page "Schema AI" with dashicon `code-standards`, three subpages: Dashboard (default), Bulk Generate, Settings. Registers all settings with sanitize callbacks. API key field uses password type, shows masked value, encrypts on save. Enqueues admin.css and admin.js with `wp_localize_script` providing ajaxUrl, restUrl, nonce, restNonce. Shows conflict notice from `schema_ai_conflicts` option. `get_overview_stats()` queries all enabled post types for counts by status.

- [ ] **Step 2: Create settings.php view**

Form with `settings_fields('schema_ai_settings')`. Fields: API Key (password), Model (select), Auto-generate (checkbox), Post Types (multi-checkbox from public post types), Publisher Name/URL/Logo (text/url), Exclude Categories (multi-checkbox).

- [ ] **Step 3: Create dashboard.php view**

Overview cards grid: total posts, with schema (green), without, errors (red). Types breakdown as badges. API Usage card: today/month calls and tokens. Recent Activity table: time, post (linked), type, status icon, tokens, duration.

- [ ] **Step 4: Commit**

---

## Task 10: Meta Box

**Files:**
- Create: `admin/class-schema-ai-metabox.php`

- [ ] **Step 1: Create Metabox class**

Registers meta box on all enabled post types, side position. Renders: status badge (auto/edited/error/none), type label, JSON-LD textarea (readonly by default), validation result, action buttons (Regenerate, Edit, Remove). Three AJAX handlers with nonce + capability checks: `ajax_regenerate` calls Generator, `ajax_save` validates JSON and saves with status=edited, `ajax_remove` deletes meta and sets status=none.

- [ ] **Step 2: Commit**

---

## Task 11: Bulk Processor

**Files:**
- Create: `includes/class-schema-ai-bulk.php`
- Create: `admin/views/bulk.php`

- [ ] **Step 1: Create Bulk class**

AJAX handlers: `ajax_start` queries posts by mode (missing/errors/all), saves state to `schema_ai_bulk_state` option, sets `schema_ai_bulk_lock` transient, schedules individual `schema_ai_bulk_process` actions via Action Scheduler with configurable delay. `process_single(int $post_id)` calls Generator, updates state counts and log (last 50 entries). `ajax_cancel` sets status=cancelled, unschedules all actions, clears lock. `ajax_status` returns current state. `get_counts(string $post_type)` returns with/without/error counts.

- [ ] **Step 2: Create bulk.php view**

Form: post type select (with counts), mode radio (missing/errors/all), batch size input, delay input. Start/Cancel buttons. Progress bar div. Log entries div. All dynamic updates via JS polling.

- [ ] **Step 3: Commit**

---

## Task 12: REST API

**Files:**
- Create: `includes/class-schema-ai-rest.php`

- [ ] **Step 1: Create REST class**

Namespace `schema-ai/v1`. Routes:
- GET/POST/PUT/DELETE `/schema/{post_id}` - per-post CRUD, requires `edit_post`
- GET `/stats` - overview stats, requires `manage_options`
- GET `/log` - paginated log, requires `manage_options`
- POST `/bulk/start`, `/bulk/cancel`, GET `/bulk/status` - bulk ops, requires `manage_options`

GET schema returns schema+status+type+validation. POST generates. PUT updates (expects JSON body with `schema` key). DELETE removes.

- [ ] **Step 2: Commit**

---

## Task 13: WP-CLI Commands

**Files:**
- Create: `includes/class-schema-ai-cli.php`

- [ ] **Step 1: Create CLI class**

Commands:
- `wp schema-ai generate <post_id>` - generate single
- `wp schema-ai bulk [--post-type=post] [--mode=missing]` - bulk with progress bar, 1s delay between posts
- `wp schema-ai status` - prints overview stats + API usage
- `wp schema-ai validate <post_id>` - validates existing schema
- `wp schema-ai remove <post_id>` - removes schema
- `wp schema-ai log [--limit=20]` - formatted table of recent entries

Uses `WP_CLI\Utils\make_progress_bar` for bulk and `format_items('table', ...)` for log.

- [ ] **Step 2: Commit**

---

## Task 14: Admin JavaScript

**Files:**
- Create: `admin/js/admin.js`

- [ ] **Step 1: Create vanilla JS**

IIFE with `schemaAI` config from `wp_localize_script`. Helper `ajax(action, data)` using FormData + fetch. Two modules:

**initMetaBox():** Binds Regenerate (AJAX call, reload on success), Edit toggle (switches textarea readonly, saves on second click), Remove (confirm + AJAX). Shows/hides spinner.

**initBulk():** Start button collects form values, AJAX starts bulk, begins 2s polling interval. Poll updates progress bar width, info text, log entries (using textContent for entries). Cancel button stops poll and AJAX cancels. Auto-resumes polling if page loads with status=running.

- [ ] **Step 2: Commit**

---

## Task 15: Admin CSS

**Files:**
- Create: `admin/css/admin.css`

- [ ] **Step 1: Create styles**

Grid layout for dashboard cards. Stat numbers with color coding (green success, red error). Type badges. Meta box styles: status badges by type, monospace code textarea, validation colors, edit mode highlight, danger button. Bulk: progress bar with fill transition, log entry colors. Responsive: single column under 782px.

- [ ] **Step 2: Commit**

---

## Task 16: Uninstall

**Files:**
- Create: `uninstall.php`

- [ ] **Step 1: Create uninstall.php**

Guard with `WP_UNINSTALL_PLUGIN`. Drops `wp_schema_ai_log` table. Deletes all `_schema_ai_*` post meta. Deletes all `schema_ai_*` options. Clears all `schema_ai_*` transients. Unschedules Action Scheduler actions.

- [ ] **Step 2: Commit**

---

## Task 17: Final Setup

- [ ] **Step 1: Create directories**

```bash
mkdir -p languages admin/views admin/js admin/css includes
touch languages/.gitkeep
```

- [ ] **Step 2: Create CLAUDE.md**

Project-level instructions: quick start (copy to wp-content/plugins, activate, set API key), architecture overview, key files, testing approach (local WP + Rich Results Test + WP-CLI).

- [ ] **Step 3: Final commit**

---

## Self-Review

- [x] Spec coverage: bootstrap, core, AI engine (gemini + analyzer + generator), validator, frontend, admin (settings + dashboard + metabox), bulk, REST API, WP-CLI, JS, CSS, uninstall, conflict detection, encryption, hook guards, response parsing, content truncation, DB versioning - all covered
- [x] No placeholders - all tasks describe complete implementations
- [x] Type consistency - class names, meta keys, option names consistent across all tasks
- [x] Note: Action Scheduler needs to be downloaded separately (composer require woocommerce/action-scheduler or manual copy to vendor/)
