=== Osiera ===
Contributors: lingyv07
Tags: two-columns, custom-logo, custom-menu, featured-images, translation-ready
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A clean, multilingual corporate theme for manufacturers, exporters and B2B service providers.

== Description ==

Osiera is built for companies that sell to other companies: it ships the content
model a B2B site actually needs instead of a demo homepage you have to dismantle.

* Four content types — products, applications, FAQ and posts — each with its own
  archive and single template.
* Inquiry forms that store submissions in a dedicated database table, so nothing
  is lost in an inbox and everything is searchable from the admin.
* Multilingual without a plugin: locale handling, translated slugs and admin UI
  strings, with a Chinese/English admin interface.
* Per-page SEO fields and JSON-LD structured data, plus a multilingual sitemap.
* Configurable URL segments for each content type.
* No demo content is published on activation; starter content is imported only
  when you trigger it.

= Design =

The theme is intentionally neutral: a system font stack, per-page stylesheets and
no page builder dependency. Bring your own brand colours through the admin
settings.

== Installation ==

1. In your admin panel, go to Appearance → Themes and click the Add New button.
2. Click Upload, choose osiera.zip and install it.
3. Activate the theme. Custom database tables are created on activation.
4. Open the Osiera settings screen to set your URL segments and language options.

== Frequently Asked Questions ==

= Does this theme require any plugins? =

No. Multilingual support, SEO fields, structured data and forms are part of the
theme.

= Where does starter content come from? =

From the dataset configured in `init-config.json`. Nothing is imported until you
start the import from the admin.

= Can I rename the theme folder? =

No. The folder must stay `osiera`, because the text domain and the translation
files depend on it.

== Screenshots ==

1. Home page with the default content model.

== Changelog ==

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
