# Osiera

A clean, multilingual WordPress theme built for manufacturers, exporters and B2B service providers.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Requires PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.0-777bb4.svg)](https://www.php.net/)
[![Requires WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b.svg)](https://wordpress.org/)

Osiera is opinionated about structure and neutral about design: it gives you the content model a B2B site actually needs — products, applications, FAQ, news — with a light, fast front end and no pre-filled demo content unless you ask for it.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Starter content](#starter-content)
- [Directory layout](#directory-layout)
- [Development notes](#development-notes)
- [中文说明](#中文说明)
- [License](#license)

## Features

- **Four content types** — products, applications, FAQ and posts, each with its own template and single view.
- **Inquiry forms with stored submissions** — submissions land in a dedicated database table instead of an inbox you cannot search.
- **Multilingual without a plugin** — locale handling, translated slugs and admin UI strings, Chinese/English admin interface out of the box.
- **Per-page SEO and JSON-LD** — title, description and structured data per page, plus a multilingual sitemap.
- **Configurable URL paths** — the segment for each content type is a setting, not a hardcoded string.
- **No bundled demo content** — nothing is published on activation. Starter content is imported only when you trigger it.
- **Light front end** — per-page CSS files, deferred scripts, optional gzip output and CDN host support.

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| MySQL / MariaDB | 5.7 / 10.3 |

## Installation

**From a release zip (recommended)**

1. Download the latest `osiera-x.y.z.zip` from [Releases](https://github.com/Lingyv07/osiera/releases).
2. In WordPress, go to **Appearance → Themes → Add New → Upload Theme** and install it.
3. Activate Osiera. Custom database tables are created on activation.

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
├── tables/               # one class per custom table
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
- `backup/` holds local revision snapshots and is git-ignored on purpose. Never commit it.
- `data-init/` is git-ignored as well: the starter dataset belongs to `osiera-data`, not here.
- Version bumps: update the header in `style.css`, `readme.txt` (`Stable tag`) and `CHANGELOG.md` together, then tag `vX.Y.Z`.

## 中文说明

Osiera 是一个面向制造商、外贸出口与 B2B 服务商的 WordPress 主题，主打"结构先行、设计克制"：

- 内置产品、应用、FAQ、文章四种内容类型，各自独立的模板与详情页；
- 询盘表单提交入库，后台可检索，不依赖邮件收件箱；
- 不装插件即可中英双语，后台界面中文/英文可切换；
- 每页独立 SEO 与 JSON-LD 结构化数据，自带多语言 sitemap；
- 安装后不预置任何演示内容，起始数据需手动导入。

安装：下载 Release 里的 zip → 外观 → 主题 → 上传 → 启用。用 git 克隆时目录名必须是 `osiera`，否则翻译无法加载。

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
