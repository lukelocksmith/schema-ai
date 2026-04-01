# Schema AI - WordPress Plugin

AI-powered schema.org JSON-LD generator for WordPress using Google Gemini.

## Features

- **Auto-generation** - Automatically generates schema.org JSON-LD when you publish or update a post
- **Smart type detection** - Two-stage analysis: PHP pre-analysis + Gemini AI determines the best schema type (Article, HowTo, FAQPage, TechArticle, and 12 more)
- **16 schema types** - Article, BlogPosting, TechArticle, HowTo, FAQPage, NewsArticle, Review, Product, Service, Organization, LocalBusiness, Event, Recipe, VideoObject, Course, SoftwareApplication
- **Bulk generation** - Process all existing posts at once with configurable batch size and rate limiting
- **Manual editing** - Edit generated JSON-LD directly in the post editor
- **Validation** - Built-in validator checks required fields per Google Rich Results spec
- **Dashboard** - Overview stats, schema type breakdown, API usage tracking, activity log
- **REST API** - Full CRUD API for programmatic access (10 endpoints)
- **WP-CLI** - 6 commands for server-side management
- **Conflict detection** - Warns if other schema plugins (Yoast, RankMath, etc.) are active
- **Post list integration** - Schema status column, bulk actions, per-post generate action
- **Google Rich Results Test** - Direct link to test each post in Google's validator

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Google Gemini API key (free tier available at [Google AI Studio](https://aistudio.google.com/apikey))

## Installation

1. Download the latest release ZIP
2. Upload to WordPress via Plugins > Add New > Upload Plugin
3. Activate the plugin
4. Go to **Schema AI > Settings** and enter your Gemini API key
5. Click **Test API Key** to verify it works
6. Schema will auto-generate when you publish/update posts

## Quick Start

### Auto mode (recommended)
Just publish a post - schema is generated automatically.

### Bulk generate existing posts
Go to **Schema AI > Bulk Generate**, select post type, click Start.

### Per-post generation
In the Posts list, click **Generate Schema** under any post title.

### WP-CLI
```bash
wp schema-ai generate 123          # Single post
wp schema-ai bulk --post-type=post # All posts
wp schema-ai status                # Dashboard stats
```

## How It Works

1. **PHP Pre-Analysis** - Scans content for patterns (questions in headings = FAQ, ordered lists = HowTo, code blocks = TechArticle, etc.)
2. **Gemini AI** - Sends full content + metadata to Gemini with the pre-analysis hint. AI determines final type and generates complete JSON-LD with all required and recommended properties
3. **Validation** - Checks generated schema against Google Rich Results requirements
4. **Output** - Injects validated JSON-LD in `<head>` via `wp_head` hook

## Supported Schema Types

| Type | Detection signals |
|------|------------------|
| Article | Default for blog posts |
| BlogPosting | Blog post with date, author |
| TechArticle | Code blocks, technical content |
| HowTo | Steps, instructions, "how to" keywords |
| FAQPage | Q&A patterns in headings |
| NewsArticle | News, current events |
| Review | Review/comparison keywords |
| Product | WooCommerce product pages |
| Service | Service descriptions |
| Organization | About company pages |
| LocalBusiness | Business with physical address |
| Event | Events with dates/locations |
| Recipe | Cooking recipes |
| VideoObject | Embedded video content |
| Course | Educational courses |
| SoftwareApplication | Software/app pages |

## REST API

```
GET    /wp-json/schema-ai/v1/schema/{id}    # Get schema
POST   /wp-json/schema-ai/v1/schema/{id}    # Generate
PUT    /wp-json/schema-ai/v1/schema/{id}    # Update manually
DELETE /wp-json/schema-ai/v1/schema/{id}    # Remove
GET    /wp-json/schema-ai/v1/stats           # Dashboard stats
GET    /wp-json/schema-ai/v1/log             # Activity log
POST   /wp-json/schema-ai/v1/bulk/start      # Start bulk
POST   /wp-json/schema-ai/v1/bulk/cancel     # Cancel bulk
GET    /wp-json/schema-ai/v1/bulk/status     # Bulk progress
```

## License

GPL v2 or later
