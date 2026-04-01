# Schema AI — WordPress Plugin

AI-powered Schema.org structured data generator using Google Gemini API.

## Quick Start

1. Install in `wp-content/plugins/schema-ai/`.
2. Activate via WP Admin > Plugins.
3. Go to **Schema AI > Settings** and enter your Gemini API key.
4. Schema is auto-generated when posts are published (if enabled).

## Architecture

Singleton core (`Schema_AI_Core`) initializes all subsystems on `plugins_loaded`. PHP 8.0+, WordPress 6.0+.

### Key Files

| File | Purpose |
|------|---------|
| `schema-ai.php` | Plugin bootstrap, autoloader, activation/deactivation hooks |
| `includes/class-schema-ai-core.php` | Singleton core, subsystem init, activation/deactivation, encryption helpers |
| `includes/class-schema-ai-gemini.php` | Gemini API client with retry logic and JSON extraction |
| `includes/class-schema-ai-analyzer.php` | Content analysis — detects Schema.org type from post content |
| `includes/class-schema-ai-generator.php` | Orchestrator — analyzes content, calls Gemini, stores result |
| `includes/class-schema-ai-validator.php` | Validates generated schema (required fields, type-specific checks) |
| `includes/class-schema-ai-cache.php` | Content-hash based cache with transient storage |
| `includes/class-schema-ai-logger.php` | Custom DB table logger for API calls and errors |
| `includes/class-schema-ai-frontend.php` | Outputs JSON-LD in `wp_head` for singular pages |
| `includes/class-schema-ai-bulk.php` | Batch processing via Action Scheduler |
| `includes/class-schema-ai-rest.php` | REST API (`/schema-ai/v1/`) for CRUD operations |
| `includes/class-schema-ai-cli.php` | WP-CLI commands (`wp schema-ai generate`, `bulk`, `status`, `validate`) |
| `admin/class-schema-ai-admin.php` | Admin menus, settings registration, asset enqueuing |
| `admin/class-schema-ai-metabox.php` | Post edit screen meta box with AJAX handlers |
| `admin/js/admin.js` | Vanilla JS — meta box interactions and bulk UI polling |
| `admin/css/admin.css` | Admin styles for dashboard, meta box, bulk progress |
| `admin/views/` | PHP view templates (dashboard, settings, bulk) |
| `uninstall.php` | Clean removal of all plugin data |

### Post Meta Keys

- `_schema_ai_data` — JSON-LD string
- `_schema_ai_type` — detected Schema.org type (e.g. `Article`, `Product`)
- `_schema_ai_status` — `auto`, `manual`, `edited`, `error`, `none`
- `_schema_ai_hash` — content hash for cache invalidation

### Options

- `schema_ai_api_key` — encrypted Gemini API key
- `schema_ai_model` — selected model (default: `gemini-2.0-flash`)
- `schema_ai_auto_generate` — auto-generate on publish toggle
- `schema_ai_post_types` — array of enabled post types
- `schema_ai_publisher_name`, `_url`, `_logo` — publisher info
- `schema_ai_exclude_categories` — category IDs to skip
- `schema_ai_bulk_state` — current bulk operation state

## Testing

1. **Local WP**: Install on LocalWP or DDEV, create test posts, verify JSON-LD in page source.
2. **Rich Results Test**: Paste published URLs into Google's Rich Results Test tool.
3. **WP-CLI**: Use `wp schema-ai validate --url=<post-url>` to test schema output.
4. **Bulk**: Use WP Admin > Schema AI > Bulk Generate to test batch processing.
