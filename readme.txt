=== Osiera ===
Contributors: lingyv07
Tags: two-columns, custom-logo, custom-menu, featured-images, translation-ready
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A responsive B2B theme with products, applications, FAQ and news as content types, per-locale content mapping, structured data and a CDN-aware output layer.

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

* **Starter data** - imports the demo dataset from a separate data repository, including page bodies
  from pages.json (English and Japanese privacy policy). A page is only filled when it is empty or
  still carries the WordPress default draft.
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

= Multi-language =

* One URL prefix per language, with per-locale content, slugs and settings.
* Adding a language registers a locale - a prefix, a row in the languages table, a flag and a
  locale tag for html lang and hreflang. It does **not** translate anything.
* There is no machine translation and no fallback that shows one language inside another. The same
  slug under two languages is treated as one entry in two languages; text you do not write stays
  missing.
* Chinese and English admin interface out of the box.
* Settings can be overridden per language: SMTP, IndexNow keys and structured data all fall back to
  the site-wide value when a language has none.

= Responsive =

* Fluid layout with breakpoints at 1530, 1400, 1300, 980, 680, 450 and 370 px.
* At 980 px and below the header collapses to a menu button, and the footer menu turns into a fixed
  bottom tab bar (Home, Products, Message, About, Contact).
* Navigation, language switching, social links and search move into the slide-down panel on phones.
* Below-the-fold images are lazy-loaded, and the viewport tag allows zoom up to 5x.

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

No. Multi-language routing, SEO fields, structured data, forms, SMTP and IndexNow are part of the
theme.

= Does the theme translate my content into other languages? =

No. Adding a language registers a locale: a URL prefix, a languages-table row, a flag and an hreflang
tag. You write the content for that language yourself. There is no machine translation, and no
fallback that shows one language inside another page.

= Is the theme mobile friendly? =

Yes. The layout is fluid and has breakpoints at 1530, 1400, 1300, 980, 680, 450 and 370 px. Below
980 px the header collapses to a menu button and the footer menu becomes a fixed bottom tab bar.

= Why are comments and update notices missing? =

Both are switched off on purpose. Comments on a lead-generation site are mostly spam, and update
checks are removed so deployments stay deterministic. If you need them, delete the corresponding
block in inc/setup/remove-features.php or inc/setup/functional.php.

= Do I have to update the theme manually? =

Yes. Because update checks are disabled, WordPress will not report available versions. Watch the
project releases, back up, then replace the theme directory or upload a new zip.

= Where does starter content come from? =

From the dataset configured in init-config.json, which lives in a separate repository. It includes
the page bodies in pages.json, so the privacy policy arrives with real text instead of the WordPress
placeholder. A page is only filled when it is empty or still carries the default draft, so content you
have edited is never overwritten. Nothing is imported until you start the import from the admin, and
that step needs a network connection.

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

= 1.1.0 =
* Import page bodies from pages.json, so the privacy policy ships with real text instead of the WordPress placeholder. Pages that already have content are never overwritten.
* Privacy policy typography for long legal text.
* Editor styles are registered on after_setup_theme with editor-styles support, so the block and classic editors match the front end.
* The comment REST guard only blocks writes; the editor notes panel no longer returns 403.
* Heading hierarchy fixed on the certificate and privacy policy pages (h1 / h2 / h3, no skipped levels).

= 1.0.0 =
* Initial public release.
* Four content types, inquiry storage, plugin-free multilingual routing.
* Seven admin screens under a single Osiera menu.
* Comments, update checks and XML-RPC disabled; head output cleaned up.
* Multilingual sitemap with hreflang, canonical and robots consolidation, JSON-LD overrides.
* CDN-aware output built on X-Forwarded-Host, with a pinned canonical.

== Upgrade Notice ==

= 1.1.0 =
Adds page-body import for the privacy policy and fixes editor styles, comment REST filtering and heading levels. No database migration needed.

= 1.0.0 =
Initial public release.
