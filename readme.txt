=== Osiera ===
Contributors: lingyv07
Tags: two-columns, custom-logo, custom-menu, featured-images, translation-ready
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A multilingual B2B theme with products, applications, FAQ and news as content types, plugin-free translation, structured data and a CDN-aware output layer.

== Description ==

Osiera is built for companies that sell to other companies. Instead of a demo homepage you have to
dismantle, it ships the content model a B2B site actually needs, and the SEO plumbing that usually
comes from five separate plugins.

Everything operational is grouped under one **Osiera** admin menu, so the sidebar stays readable.

= Content model =

* Four content types - products, applications, FAQ and posts - each with its own archive and single
  template.
* The URL segment for each content type is a setting, not a hardcoded string.
* Inquiry forms store submissions in a dedicated database table, so nothing is lost in an inbox and
  everything is searchable from the admin.
* No demo content is published on activation. Starter content is imported only when you trigger it.

= Admin screens =

* **Starter data** - imports the demo dataset from a separate data repository.
* **Email settings** - SMTP for the site, per-language overrides, and a switch that rejects inquiries
  with abnormal content.
* **IndexNow settings** - one key per language, the key file served at /{key}.txt, and URL pushes to
  api.indexnow.org.
* **Structured data** - JSON-LD patches per page type and per language, deep-merged into the
  generated @graph.
* **Site & paths** - target origin for canonical and IndexNow, plus URL prefixes for assets, uploads,
  wp-includes and the API, with the matching nginx snippet generated on screen.
* **Asset updates** - custom CSS and JS edited in the admin, with a manual version string as the
  cache buster.
* **Monitoring** - Clarity, GA4 and Google Ads IDs, conversion events per traffic source, a cookie
  consent bar, and per-site switches for analytics and indexing.

= What the theme switches off =

This is deliberate behaviour, not missing features. Each block can be removed from
inc/setup/remove-features.php, inc/setup/remove-hooks.php or inc/setup/functional.php.

* **Comments** - posting is rejected at wp-comments-post.php and through the REST API, the admin menu
  is removed, and comment feeds are dropped. On a lead-generation site comments are mostly a spam
  surface.
* **WordPress core, plugin and theme updates** - auto-updates disabled, update transients nulled, the
  update screen and the admin nag removed. Deployments are versioned artifacts; you ship a new build
  instead of letting the host update core overnight.
* **Emoji detection script and styles**, including the DNS prefetch.
* **Block library CSS, global styles and classic theme styles** for logged-out visitors.
* **Version and protocol leaks** - wp_generator, RSD, WLW manifest, shortlink, REST API link headers,
  oEmbed discovery links, RSS and comment feed links.
* **Core sitemaps**, replaced by the multilingual sitemap.
* **XML-RPC**, the [video] shortcode and oEmbed auto-embedding.
* **Orphaned auto-drafts** older than seven days, cleaned up on schedule.

= SEO and performance =

* One canonical, emitted by the theme from the configured target origin, so a site reachable on apex,
  www, a CDN domain and a staging host does not produce four duplicate pages.
* One robots source. The theme adjusts only the index and follow group and leaves the core
  max-image-preview, max-snippet and max-video-preview directives untouched. 404 and search results
  get noindex, nofollow, noarchive.
* Multilingual sitemap: /sitemap.xml is a language index, /{lang}/sitemap.xml carries each language,
  with hreflang alternates pointing at each other and x-default on the default language. Legacy
  sitemap addresses redirect to /sitemap.xml.
* Titles are entity-decoded on output, so ampersands do not leak into the title tag or share cards.
* The KSES style whitelist is extended with the layout properties WordPress drops by default, so a
  flex or grid layout does not collapse when an editor saves a post.
* Two stylesheets and one inlined block, scripts in the footer, no third-party font or script CDN.
* PHP-level gzip fallback for servers without compression; an already compressed response is not
  compressed twice.

= Multilingual =

* Locale routing with per-language URL prefixes, translated slugs, and translated admin UI strings.
* Chinese and English admin interface out of the box.
* Settings can be overridden per language: SMTP, IndexNow keys and structured data all fall back to
  the site-wide value when a language has none.

= CDN and reverse proxies =

The theme decides whether a request came through a CDN from one signal: the X-Forwarded-Host header.

* Header present: every absolute URL in the response follows that host - sitemap, JSON-LD, Open
  Graph, assets, redirects - for the main domain and all of its subdomains. Nothing to configure.
* Header absent: a direct origin request. Output is untouched.
* canonical is the exception. It always uses Site & paths - target origin, so a CDN fetch cannot
  canonicalise production pages to an edge hostname.
* Your CDN must forward X-Forwarded-Host and X-Forwarded-Proto, and either vary its cache by Host or
  purge on deploy.
* Optional constants in wp-config.php: TPI_CDN_ADMIN (also rewrite admin URLs, off by default),
  TPI_CDN_LEGACY (retired domains to purge), TPI_CDN_DEBUG (X-CDN-Hosts response header).
* With "This site SEO" switched off, the origin sends noindex and a Disallow robots file while
  CDN-served requests stay indexable, so a preview domain can share the production database safely.

== Installation ==

1. In your admin panel, go to Appearance - Themes and click the Add New button.
2. Click Upload, choose osiera.zip and install it.
3. Activate the theme. Custom database tables are created on activation.
4. Open Osiera - Site & paths to set the target origin and your URL segments.

== Frequently Asked Questions ==

= Does this theme require any plugins? =

No. Multilingual support, SEO fields, structured data, forms, SMTP and IndexNow are part of the
theme.

= Why are comments and update notices missing? =

Both are switched off on purpose. Comments on a lead-generation site are mostly spam, and update
checks are removed so deployments stay deterministic. If you need them, delete the corresponding
block in inc/setup/remove-features.php or inc/setup/functional.php.

= Do I have to update the theme manually? =

Yes. Because update checks are disabled, WordPress will not report available versions. Watch the
project releases, back up, then replace the theme directory or upload a new zip.

= Where does starter content come from? =

From the dataset configured in init-config.json, which lives in a separate repository. Nothing is
imported until you start the import from the admin, and that step needs a network connection.

= Why does my CDN domain show up in the page source? =

Because the request carried X-Forwarded-Host, so the theme rewrote the output to the visitor's
domain. That is the intended behaviour. The X-TPI-Cdn response header confirms it fired. If the
rewrite does not happen, your CDN is stripping that header.

= Can I rename the theme folder? =

No. The folder must stay osiera, because the text domain and the translation files depend on it.

= Do the custom URL prefixes work without nginx rules? =

No. Choosing a custom prefix only changes what the theme outputs. The rewrite rules printed on the
Site & paths screen have to be installed. Leaving every prefix native needs no server configuration.

== Screenshots ==

1. Home page with the default content model.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Four content types, inquiry storage, plugin-free multilingual routing.
* Seven admin screens under a single Osiera menu.
* Comments, update checks and XML-RPC disabled; head output cleaned up.
* Multilingual sitemap with hreflang, canonical and robots consolidation, JSON-LD overrides.
* CDN-aware output built on X-Forwarded-Host, with a pinned canonical.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
