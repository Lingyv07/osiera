# Osiera

A self-contained WordPress theme for B2B sites: products, applications, FAQ and news as first-class
content types, multilingual routing, and the SEO plumbing usually assembled from five plugins —
without the plugin stack. Comments and WordPress update prompts are switched off by design, and the
operational settings live in one admin panel.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Requires PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.0-777bb4.svg)](https://www.php.net/)
[![Requires WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b.svg)](https://wordpress.org/)

Osiera is opinionated about structure and neutral about design. It gives you the content model a B2B
site actually needs, a light front end, and no pre-filled demo content unless you import it.

## Contents

- [What's inside](#whats-inside)
- [Admin screens](#admin-screens)
- [What the theme switches off](#what-the-theme-switches-off)
- [SEO and performance decisions](#seo-and-performance-decisions)
- [CDN and reverse proxies](#cdn-and-reverse-proxies)
- [Caveats](#caveats)
- [Requirements](#requirements)
- [Installation](#installation)
- [Starter content](#starter-content)
- [Directory layout](#directory-layout)
- [Development notes](#development-notes)
- [中文说明](#中文说明)
- [License](#license)

## What's inside

- **Four content types** — products, applications, FAQ and posts, each with its own archive and
  single template, plus per-type URL segments you can rename.
- **Inquiry forms with stored submissions** — every submission lands in a dedicated database table,
  searchable from the admin, not buried in a mailbox.
- **Multilingual without a plugin** — locale routing, translated slugs, translated admin UI strings
  (Chinese/English out of the box), per-language settings overrides.
- **Per-page SEO** — title and meta description per page, JSON-LD `@graph` output, and a multilingual
  sitemap with `hreflang` cross-references.
- **Configurable URL paths** — assets, uploads, `wp-includes` and the API prefix are settings, with
  the matching nginx snippet generated on screen.
- **CDN-aware output** — when a CDN forwards `X-Forwarded-Host`, every URL in the output follows the
  visitor's domain, while `canonical` stays pinned to your production origin.
- **No bundled demo content** — nothing is published on activation. Starter content is imported only
  when you trigger it.
- **Light front end** — two stylesheets (base + media queries) with one inlined block, scripts in the
  footer, optional PHP-level gzip fallback, no third-party font or script CDN.

## Admin screens

Everything operational sits under one **Osiera** menu, so the sidebar does not turn into a list of
unrelated settings pages.

| Screen | What it does |
|---|---|
| **Starter data** | Imports the demo dataset from `osiera-data`. Nothing is published until you run it. |
| **Email settings** | SMTP for the whole site with per-language overrides, plus a switch that rejects inquiries with abnormal content. |
| **IndexNow settings** | Stores one key per language, serves `/{key}.txt`, and pushes URLs to `api.indexnow.org`. |
| **Structured data** | Paste JSON-LD patches per page type (`common`, `home`, `product`, `post`, `application`, `faq`, `page`) and per language; they are deep-merged into the generated `@graph`. |
| **Site & paths** | Target origin for `canonical` and IndexNow, plus the URL prefix for assets, uploads, `wp-includes` and the API — native WordPress paths or your own, with the nginx snippet updated live. |
| **Asset updates** | Custom CSS/JS edited in the admin and written to `assets/`, with a manual version string used as the cache buster. |
| **Monitoring** | Clarity / GA4 / Google Ads IDs, conversion events per traffic source, a cookie consent bar, and the two "this site only" switches described below. |

Inquiries get their own top-level menu next to FAQ management, because a submenu item is invisible
while the parent is collapsed.

## What the theme switches off

This is a deliberate part of the theme, not an accident of missing features. If you need any of it
back, remove the corresponding block from `inc/setup/remove-features.php`, `remove-hooks.php` or
`functional.php`.

| Disabled | How, and why |
|---|---|
| **Comments** | Posting is rejected at `wp-comments-post.php` and through the REST API, the admin menu is removed, and comment feeds are dropped. On a lead-generation B2B site comments are mostly a spam surface. |
| **WordPress core / plugin / theme update checks** | Auto-updates disabled, update transients nulled, `update-core.php` removed from the menu, and the update nag removed. Deployments are versioned artifacts — you ship a new theme build rather than letting a shared host update core at 3am. |
| **Emoji detection script, styles and DNS prefetch** | Roughly 10 KB of front-end payload per request for something a B2B site never uses. |
| **Block library CSS, global styles, classic theme styles** | Dropped for logged-out visitors; the theme does not rely on core block CSS. |
| **`wp_generator`, RSD, WLW manifest, shortlink** | Version fingerprinting and dead protocol links in `<head>`. |
| **REST API link headers, oEmbed discovery, host JS** | The theme's own REST routes keep working; only the advertisement of them is removed. |
| **RSS / comment feed `<link>` tags** | No subscription entry point exists, and their titles follow the admin locale, mixing Chinese into an English page source. |
| **Core sitemaps (`/wp-sitemap.xml`)** | Replaced by the multilingual sitemap. Core also attaches `wp-sitemap.xsl`, which triggers Chrome/Edge's XSLT warning. |
| **XML-RPC** | Brute-force and pingback surface with no use on this kind of site. |
| **`[video]` shortcode and oEmbed auto-embed** | `.mp4` URLs render as native `<video>` elements instead of third-party iframes. |
| **Admin bar on the front end** | Hidden for non-admin views. |
| **Auto-drafts older than 7 days** | Cleaned up on `wp_scheduled_delete`, so the posts table does not accumulate rows. |

The consequence is worth stating plainly: **WordPress will never tell you that a security release
exists.** Watching for updates is your job now — see [Caveats](#caveats).

## SEO and performance decisions

- **One canonical, emitted by the theme.** Core `rel_canonical` is removed and replaced with a
  canonical built from the configured target origin. On a site reachable through apex, `www`, a CDN
  domain and a staging host, that is the difference between one indexed page and four duplicates.
- **One robots source.** The theme adjusts only the `index` / `follow` group and leaves core's
  `max-image-preview:large`, `max-snippet` and `max-video-preview` untouched, so large image previews
  keep working. 404 and search results get `noindex, nofollow, noarchive`.
- **Multilingual sitemap with `hreflang`.** `/sitemap.xml` is a language index; `/{lang}/sitemap.xml`
  carries every page for that language, with `xhtml:link` alternates pointing at each other and
  `x-default` on the default language. Legacy `wp-sitemap*`, `sitemap_index.xml` and
  `sitemap-index.xml` addresses 301 to `/sitemap.xml`.
- **Titles are entity-decoded** on output, so `&amp;` does not leak into `<title>` and share cards.
- **The KSES style whitelist is extended** with the layout properties WordPress drops by default
  (`display`, `overflow-x/y`, `box-sizing`, `transform`, and balanced function values). Without it,
  a flex or grid layout collapses the moment an editor saves the post.
- **PHP gzip fallback.** If the server already compresses, the response carries `Content-Encoding` and
  nothing is compressed twice. Turn it off with
  `add_filter('osiera_php_gzip', '__return_false');`.
- **No external requests on first paint.** Styles, scripts, icons and fonts are all served from the
  theme; there is no third-party font or asset CDN to wait on.

## CDN and reverse proxies

The theme decides whether a request came through a CDN from **one signal: the `X-Forwarded-Host`
header.**

- **Header present** → the request is a CDN origin fetch. Every absolute URL in the response is
  rewritten to that host: `sitemap`, JSON-LD, Open Graph, static assets, redirects — and not just
  your main domain but every subdomain of it. Nothing has to be configured in the admin, and no
  domain constants are needed in `wp-config.php`.
- **Header absent** → a direct origin request. Output is untouched, zero overhead.

`canonical` is the one exception: it follows **Site & paths → target origin** (falling back to the
current host when empty). It is deliberately excluded from the rewrite, otherwise a CDN fetch would
canonicalise your production pages to whatever hostname the edge used.

Optional constants, all in `wp-config.php`:

| Constant | Default | Effect |
|---|---|---|
| `TPI_CDN_ADMIN` | `false` | Rewrite admin URLs too. Left off so a CDN cannot break login redirects; turn it on only if you really administer the site through the CDN domain. |
| `TPI_CDN_LEGACY` | `''` | Comma-separated retired domains that should still be purged from output. |
| `TPI_CDN_DEBUG` | `false` | Adds an `X-CDN-Hosts` response header showing which hosts were detected. |

The response header `X-TPI-Cdn` is always present when a CDN host was detected — a quick way to
confirm the rewrite is actually firing.

What your CDN must do:

1. **Forward `X-Forwarded-Host`** — some CDNs strip it by default; without it the theme sees a direct
   request and outputs origin URLs.
2. **Forward `X-Forwarded-Proto`** — used to decide between `http` and `https` in generated URLs.
3. **Keep the cache key aware of the Host**, or purge on deploy. HTML contains per-host absolute URLs,
   so one cached copy served to another hostname will carry the wrong domain.
4. **Leave the site root reachable** — the IndexNow key file lives at `/{key}.txt`.

Related behaviour worth knowing:

- The **"This site" switch** in Monitoring, when off, stops collecting analytics for direct origin
  hits and only counts CDN origin fetches. Useful when the origin is also reachable directly.
- The **"This site SEO" switch**, when off, sends `noindex` and a `Disallow: /` robots file on the
  origin while CDN-served requests stay fully indexable — a staging domain can sit on the same
  database as production without polluting the index.
- The asset manager references the minified `js-dist` bundle **only on CDN requests**; direct origin
  requests get the readable source files, which keeps on-server debugging possible.

## Caveats

- **Updates are manual.** With update checks disabled, neither core nor plugins will report available
  versions. Watch releases yourself, replace the theme directory (or upload a new zip), and keep a
  backup. This is a deliberate trade for deterministic deployments, not an oversight.
- **Comments are gone.** Plugins or themes that expect comment support will not find it.
- **`canonical` needs a target origin.** If you leave Site & paths empty while serving the same site
  on several domains, canonical falls back to the visited host. Fill it in for anything public.
- **Custom URL prefixes need nginx.** Choosing `custom` for assets, uploads, `wp-includes` or the API
  only changes what the theme outputs; the rewrite rules printed on that screen have to be installed.
  Leaving everything native needs no server configuration at all.
- **Asset updates write into the theme directory.** The saved option is the source of truth; the file
  under `assets/` is regenerated when missing, so a theme update does not lose your custom CSS/JS.
- **The version string is manual.** Editing custom CSS without bumping the version leaves browsers on
  the cached copy.
- **IndexNow needs a root-reachable key file** and outbound HTTPS to `api.indexnow.org`.
- **Starter content is fetched, not bundled.** A clone or a release zip contains no `data-init/`
  folder; the first import needs a network connection.
- **Multilingual data lives in custom tables**, created on activation. Switching themes does not
  remove them.

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| MySQL / MariaDB | 5.7 / 10.3 |

## Installation

**From a release zip (recommended)**

1. Download the latest `osiera-x.y.z.zip` from [Releases](https://github.com/Lingyv07/osiera/releases).
   Do **not** use the "Source code" archives — they carry a different top-level folder and are not
   packaged as a theme.
2. In WordPress, go to **Appearance → Themes → Add New → Upload Theme** and install it.
3. Activate Osiera. Custom database tables are created on activation.
4. Open **Osiera → Site & paths** and set your target origin and URL segments.

**From source**

```bash
cd wp-content/themes
git clone https://github.com/Lingyv07/osiera.git osiera
```

The trailing `osiera` is the destination folder name. Git would derive it from the URL
anyway, but WordPress stores the folder name as the active theme, and the `.mo` files in
`languages/` are looked up by that same slug — so pinning it explicitly is deliberate.

A clone contains no `data-init/` folder: it is git-ignored. Starter content is fetched
from `osiera-data` on first import, which means that step needs a network connection.
Release zips behave identically.

## Starter content

Osiera ships with an importer rather than a demo site. The dataset it uses lives in a
separate repository, [`osiera-data`](https://github.com/Lingyv07/osiera-data), and is
fetched at import time — it is intentionally **not** part of this repository:

```json
{
  "data_init_url": "https://raw.githubusercontent.com/Lingyv07/osiera-data/refs/heads/main/cnc-data"
}
```

The importer maps on-demand media (`assets/images/...`) and JSON fixtures
(`data/...`) from that URL, so an import needs an internet connection the first
time. Import runs only when you trigger it from the admin. Do not commit real
customer content back into this repository — put per-site content in your own
data repository.


## Directory layout

```
osiera/
├── api/                  # REST endpoints (cookies, inquiry, video page)
├── assets/               # css / js / svg / wp-assets (front end)
├── inc/
│   ├── admin/            # admin screens, settings, columns
│   ├── common/           # request helpers, formatting
│   ├── content/          # routes, fields, page order
│   ├── database/         # table creation and schema
│   ├── media/            # image/svg helpers
│   ├── multilang/        # locale, translation store
│   ├── output/           # css/js enqueue, sitemap, cdn, gzip
│   └── setup/            # theme setup, starter content, functional bits
├── languages/            # admin UI translations
├── page/                 # page templates
├── tables/               # one class per custom table, plus its admin screen
├── template/             # shared template parts
├── functions.php
└── style.css             # theme header only — styles are enqueued from assets/css

# present locally, not in this repository:
#   data-init/            # starter-content dataset, fetched from osiera-data
#   backup/               # local revision snapshots
```


## Development notes

- `style.css` carries the theme header and nothing else. All visual styles live in `assets/css/*.css` and are enqueued from `inc/output/css.php`.
- Escape on output, sanitize on input, and keep the `osiera` text domain on every string.
- Behaviour changes that touch `<head>`, URLs or headers belong in `inc/output/` or `inc/setup/` — keep them out of templates so they apply to every route.
- `backup/` holds local revision snapshots and is git-ignored on purpose. Never commit it.
- `data-init/` is git-ignored as well: the starter dataset belongs to `osiera-data`, not here.
- Version bumps: update the header in `style.css`, `readme.txt` (`Stable tag`) and `CHANGELOG.md` together, then tag `vX.Y.Z`.

## 中文说明

Osiera 是一个面向制造商、外贸出口与 B2B 服务商的 WordPress 主题，思路是"结构先行、设计克制"：

**内容与后台**

- 内置产品、应用、FAQ、文章四种内容类型，各自独立的模板与详情页，URL 段可改名；
- 询盘表单提交入库，后台可检索，不依赖邮件收件箱；
- 不装插件即可多语言，语言前缀、译文 slug、后台界面中英文切换；
- 后台统一收在一个 **Osiera** 菜单下：初始数据、邮箱设置、IndexNow、结构化数据、站点与路径、资源更新、监控管理。

**主动关闭的功能**

- **评论**：`wp-comments-post.php` 与 REST 提交均拒绝，后台评论菜单移除，评论 Feed 去掉；
- **WordPress 更新**：自动更新关闭、更新检测不再发起、`update-core.php` 菜单与顶部升级提示全部移除；
- 前台 Emoji 脚本与样式、区块库 CSS、`wp_generator`、RSD、WLW、短链接、REST/oEmbed 发现链接、RSS/评论 Feed 链接；
- 核心 sitemap（含会触发 Chrome XSLT 警告的 `wp-sitemap.xsl`）、XML-RPC、`[video]` 短代码与 oEmbed 自动嵌入；
- 孤儿自动草稿（超过 7 天）定期清理。

**SEO 与性能**

- canonical 只由主题输出一份，按"站点与路径 → 目标源"生成，多个域名指向同一站时不产生重复收录；
- robots 只保留一处逻辑，404 与搜索结果 `noindex, nofollow, noarchive`，核心的大图预览指令原样保留；
- 多语言 sitemap：`/sitemap.xml` 是索引，`/{lang}/sitemap.xml` 为各语言地图，条目标注 `hreflang` 互指与 `x-default`；
- 标题输出前做实体解码，避免 `<title>` 里出现 `&amp;`；
- 补齐 KSES 的 CSS 白名单（`display`、`overflow`、`box-sizing`、`transform` 等），编辑器里排好的 flex/grid 不会一保存就塌；
- 服务器没开 gzip 时由 PHP 兜底，已压缩的响应不会压第二遍。

**CDN 与反向代理**

- 判定只看一个信号：请求头有没有 `X-Forwarded-Host`。有就是 CDN 回源，输出里所有域名替换成访客域名（不只是主域名，所有子域一起收敛）；没有就原样输出，零开销。
- `canonical` 是唯一例外，始终用"目标源"，不会被 CDN 域名冲掉。
- CDN 需要转发 `X-Forwarded-Host` 与 `X-Forwarded-Proto`，缓存键要区分 Host 或部署时清缓存。
- `wp-config.php` 可选常量：`TPI_CDN_ADMIN`（后台也换域名，默认 false，避免登录跳转异常）、`TPI_CDN_LEGACY`（需要一并清掉的历史域名）、`TPI_CDN_DEBUG`（响应头 `X-CDN-Hosts` 方便确认命中）。
- 监控里的"本站 SEO"关闭后，源站 `noindex` + robots 禁抓，经 CDN 访问照常收录——预览站与正式站共用一套数据时不会污染索引。

**注意事项**

- 更新提示已关闭，**安全更新要自己盯**，换主题目录或上传新 zip 前先备份；
- canonical 的目标源必须填成正式对外域名；不填就跟当前访问域名一致；
- 自定义 URL 前缀只改变主题输出，对应的 nginx 规则要自己装（页面上会实时生成）；
- 资源更新写入主题目录，保存的 option 才是数据源，文件丢了会按 option 重建；版本号需手动改，否则浏览器仍用旧缓存；
- 用 git 克隆时目录名必须是 `osiera`，否则翻译无法加载。

## License

Osiera is free software, released under the [GNU General Public License v3.0 or later](LICENSE).

```
Copyright (C) 2026 Lingyv07

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later version.
```

Bundled third-party assets keep their own licenses — see [NOTICE.md](NOTICE.md).

## Contributing

Bug reports and pull requests are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) first. Security issues should not be filed as public issues — see the contact address in `CONTRIBUTING.md`.
