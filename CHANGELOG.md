# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Nothing yet.

## [1.0.0] - 2026-11-06

### Added
- Four content types: products, applications, FAQ and posts, each with archive and single templates.
- Inquiry forms with submissions stored in a dedicated database table.
- Multilingual layer: locale routing, translated slugs, translated admin UI (Chinese/English).
- Per-page SEO fields, JSON-LD structured data and a multilingual sitemap with `hreflang` alternates.
- Configurable URL segments per content type.
- Seven admin screens under a single Osiera menu: starter data, email, IndexNow, structured data,
  site paths, asset updates and monitoring.
- Starter-content importer driven by an external dataset (`init-config.json`).
- Optional gzip output and CDN host support based on `X-Forwarded-Host`.

### Changed
- `canonical` is emitted by the theme against the configured target origin instead of core's
  request-derived value, so multi-domain and CDN setups do not produce duplicate indexed pages.
- `robots` is driven from a single filter that only adjusts the `index` / `follow` group, keeping
  core's `max-image-preview`, `max-snippet` and `max-video-preview` directives intact.
- Sitemap output replaced core sitemaps: `/sitemap.xml` is a language index and
  `/{lang}/sitemap.xml` carries each language; legacy sitemap addresses 301 to `/sitemap.xml`.

### Fixed
- Titles are entity-decoded before output, so `&amp;` no longer leaks into `<title>` or share cards.
- KSES style whitelist extended with `display`, `overflow`, `box-sizing`, `transform` and balanced
  function values, so flex and grid layouts no longer collapse when a post is saved.
- Auto-drafts older than seven days are deleted on `wp_scheduled_delete` instead of accumulating.
- Video URLs in content render as native `<video>` elements rather than oEmbed iframes.

### Removed
- Comments: submission blocked at `wp-comments-post.php` and through the REST API, admin menu removed,
  comment feeds dropped.
- WordPress core, plugin and theme update checks, auto-updates and admin update notices.
- Emoji script, styles and DNS prefetch; block library CSS, global styles and classic theme styles
  for logged-out visitors.
- Head clutter: `wp_generator`, RSD, WLW manifest, shortlink, REST API link headers, oEmbed
  discovery and host JS, RSS and comment feed links.
- XML-RPC, the `[video]` shortcode and oEmbed auto-embedding.
- Core sitemaps, including the `wp-sitemap.xsl` stylesheet that triggers browser XSLT warnings.

[Unreleased]: https://github.com/Lingyv07/osiera/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Lingyv07/osiera/releases/tag/v1.0.0
