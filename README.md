# WordPress Multisite Text Search Tool

## Overview

A PHP script for fast text searching across WordPress multisite networks using direct database queries. Designed for large multisite installations where WP-CLI searches are too slow or time out.

## How It Works

**Database Access**: Directly queries MySQL tables bypassing WordPress bootstrap overhead.

**Environment Detection**: Auto-detects database credentials from:
- DDEV (`IS_DDEV_PROJECT`, `DDEV_HOSTNAME`)
- Pantheon (`PANTHEON_ENVIRONMENT`, `PRESSFLOW_SETTINGS`)
- WP Engine (`WPE_APIKEY`)
- Environment variables (`DB_HOST`, `DB_NAME`, etc.)
- wp-config.php parsing
- DATABASE_URL format

**Search Scope**: 
- `wp_*_posts.post_content` - Post/page content
- `wp_*_postmeta.meta_value` - Custom fields, page builder data
- `wp_*_options.option_value` - Widgets, theme settings, plugin configs

**Built-in Exclusions**: Automatically filters out noise:
```
jpsq_sync*, jetpack_*, *_transient*, wpins_*, 
active_plugins, recently_activated, fs_accounts
```

## Usage

### Basic Syntax
```bash
php multisite-text-search.php "search_term" [options]
```

### Options
- `--posts-only` - Search only post content
- `--meta-only` - Search only post meta
- `--options-only` - Search only options table
- `--summary` - Show counts only
- `--case-sensitive` - Case-sensitive search
- `--published-only` - Published content only
- `--exclude="pattern*"` - Exclude patterns (wildcards supported)

### Examples
```bash
# Find shortcode usage
php multisite-text-search.php "[gallery"

# Content-only search (faster)
php multisite-text-search.php "contact-form-7" --posts-only

# Summary view
php multisite-text-search.php "old-domain.com" --summary

# Exclude specific patterns
php multisite-text-search.php "text" --exclude="backup_*" --exclude="cache_*"
```

## Output Format

```
Environment detected: ddev
Database: db on db
Table prefix: wp_
----------------------------------------
Searching for: "shortcode"
Options: posts-only, excluding: jpsq_sync*, jetpack_*, *_transient*
============================================================
Scanning 247 active sites...

FOUND in: site.domain.com/ (Blog ID: 5) - 2 matches
URL: http://site.domain.com/
  └─ Post: "Page Title" (ID: 123, Type: page, Status: publish)
     → http://site.domain.com/?p=123
  └─ Option: widget_text-3 (ID: 1456)
     WP-CLI: ddev wp --url=site.domain.com/ option get widget_text-3

============================================================
SEARCH SUMMARY:
Total matches found: 15
Sites with matches: 8  
Total sites scanned: 247
Match rate: 3.2% of sites contain this text
Search completed in 12.34 seconds.
```

## Performance

- **Typical speed**: 40-60 seconds for 700~ sites
- **Memory usage**: Minimal (streams results, doesn't load content)
- **Database impact**: Read-only SELECT queries only
- **Optimization**: Uses table existence checks and prepared statements

## Technical Requirements

- PHP 7.0+ with PDO MySQL extension
- Direct database access
- Multisite network with standard table structure
- Database credentials via environment detection or wp-config.php

## Query Logic

1. **Site Discovery**: `SELECT blog_id FROM wp_blogs WHERE deleted=0 AND spam=0`
2. **Table Detection**: `SHOW TABLES LIKE 'wp_*_posts'` per site
3. **Content Search**: `SELECT * FROM table WHERE content LIKE '%term%'`
4. **Filtering**: Apply exclude patterns using regex wildcard matching
5. **Results**: Aggregate and format output with direct links

## Wildcard Patterns

Exclude patterns support shell-style wildcards:
- `*` - Matches any string
- `?` - Matches single character
- Examples: `jpsq_sync*`, `*_transient*`, `backup_*`, `temp_?`

Converted to regex: `jpsq_sync*` becomes `/^jpsq_sync.*$/i`, `temp_?` becomes `/^temp_.$/i`
