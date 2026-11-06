<?php
/**
 * 多语言站点地图（纯 XML，无样式，带 hreflang 互指）
 *
 *   /sitemap.xml          → 语言索引（sitemapindex）
 *   /{lang}/sitemap.xml   → 该语言的全部页面 / 文章 / 产品 / 应用 / FAQ
 *                           （en/、ja/、ru/ … 与前台语言前缀一致）
 *
 * 每条 <url> 里带 <xhtml:link rel="alternate" hreflang="…" href="…" />，
 * 把同一内容的所有语言版本互相指认一遍，默认语言那一版额外标 hreflang="x-default"
 * —— 和 explore.microsoft.com / google.com 的 gmail sitemap 是同一套写法。
 *
 * 为什么不用核心 /wp-sitemap.xml：
 *   1. 核心只会把全站 URL 堆在一张地图里，做不到「按语言归类」；
 *   2. 核心默认挂 wp-sitemap.xsl，Blink（Chrome / Edge）正在移除 XSLT，
 *      访问时会弹「此网站使用了 XSLT」的警告，之前已经把它摘掉了。
 * 所以直接关掉核心 sitemaps（见 inc/setup/functional.php），自己输出裸 XML。
 *
 * 不挂 rewrite 规则，直接按请求路径接管（template_redirect 优先级 1），
 * 换固定链接结构、新站启用主题都不用重新 flush，天然可用。
 */

if (!function_exists('tpi_sitemap_request_path')) {
    /** 当前请求相对站点根的路径（去掉子目录前缀与首尾斜杠） */
    function tpi_sitemap_request_path(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = trim($path, '/');
        $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && ($path === $home || strpos($path, $home . '/') === 0)) {
            $path = ltrim(substr($path, strlen($home)), '/');
        }
        return $path;
    }
}

if (!function_exists('tpi_sitemap_languages')) {
    /**
     * 站点语言：slug / 显示名 / hreflang / 顺序
     *
     * hreflang 取语言表里的 locale（en、ru、ja …），没有就退回 slug；
     * 下划线换成连字符，两个语言撞了同一个 hreflang 时后来的退回 slug，
     * 免得一条 <url> 里出现两个一样的 hreflang（Google 会判冲突）。
     *
     * @return array<int, array{slug:string,name:string,hreflang:string,pos:int}>
     */
    function tpi_sitemap_languages(): array
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }

        $rows = [];
        $seen = [];
        $pos  = 0;
        foreach (tpi_languages() as $language) {
            $slug = (string) ($language['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $locale   = trim((string) ($language['locale'] ?? ''));
            $hreflang = $locale !== '' ? tpi_locale_tag($locale) : strtolower($slug);
            if ($hreflang === '' || isset($seen[$hreflang])) {
                $hreflang = strtolower($slug);
            }
            if ($hreflang === '' || isset($seen[$hreflang])) {
                continue;
            }
            $seen[$hreflang] = true;
            $rows[] = [
                'slug'     => $slug,
                'name'     => (string) ($language['name'] ?? strtoupper($slug)),
                'hreflang' => $hreflang,
                'pos'      => $pos++,
            ];
        }

        return $rows;
    }
}

if (!function_exists('tpi_sitemap_collect')) {
    /**
     * 收集全部语言的全部 URL，并给每条算好 hreflang 互指
     *
     * 关键：tpi_all_languages 绕开前台「只取当前语言」的查询过滤
     * （tpi_filter_front_query_by_language），否则匿名请求永远只拿到默认语言。
     *
     * @return array<string, array<int, array>> [lang => items]
     */
    function tpi_sitemap_collect(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $types     = ['page', 'post', 'product', 'application', 'faq'];
        $languages = tpi_sitemap_languages();
        $order     = [];
        foreach ($languages as $language) {
            $order[$language['slug']] = $language['pos'];
        }
        $default = tpi_default_language();

        // 首页要特殊处理：get_permalink() 里判断「是不是首页」用的是
        // option_page_on_front，而主题会按**当前语言**过滤这个 option。
        // 在 /de/sitemap.xml 请求里拿英文首页的 get_permalink() 会得到 /home，
        // 而不是干净的 /，x-default 就会指向一个不在地图里的地址。
        $raw_front  = function_exists('tpi_raw_page_on_front') ? tpi_raw_page_on_front() : (int) get_option('page_on_front');
        $front_slug = $raw_front > 0 ? (string) get_post_field('post_name', $raw_front) : '';

        // term 缓存必须开着：下面每条都要读 tpi_lang 归属，关掉会变成 N 次查询
        $posts = get_posts([
            'post_type'              => $types,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
            'tpi_all_languages'      => true,
        ]);

        // 第一遍：把每条内容自己的地址、语言、修改时间收进来
        $rows = [];
        foreach ($posts as $post) {
            $object = get_post_type_object($post->post_type);
            if (!$object || empty($object->public)) {
                continue;
            }
            $lang = (string) tpi_get_post_language($post->ID);
            if ($lang === '' || !isset($order[$lang])) {
                $lang = $default;
            }
            if (!isset($order[$lang])) {
                continue;
            }
            $url = '';
            if ($front_slug !== '' && $post->post_type === 'page'
                && $post->post_name === $front_slug && (int) $post->post_parent === 0) {
                // 这条就是某语言（含默认语言）的首页：/ 或 /ja/home
                $url = function_exists('tpi_language_home_url')
                    ? (string) tpi_language_home_url($lang)
                    : (string) get_permalink($post);
            }
            if ($url === '') {
                $url = (string) get_permalink($post);
            }
            if ($url === '') {
                continue;
            }
            $rows[$post->ID] = [
                'loc'     => $url,
                'lastmod' => (string) get_post_modified_time('c', true, $post),
                'type'    => (int) array_search($post->post_type, $types, true),
                'title'   => (string) get_the_title($post),
                'lang'    => $lang,
            ];
        }

        // 第二遍：按 `_tpi_translations` 把同一内容的多语言版本串起来
        foreach ($rows as $post_id => $row) {
            $by_lang = [];

            foreach (tpi_content_translation_map($post_id) as $one_lang => $one_id) {
                $one_lang = (string) $one_lang;
                $one_id   = (int) $one_id;
                // 译文没发布 / 不在收录范围（草稿、回收站、私有）就跳过
                if ($one_lang === '' || !isset($order[$one_lang]) || !isset($rows[$one_id])) {
                    continue;
                }
                $by_lang[$one_lang] = $rows[$one_id]['loc'];
            }

            // 自己一定算一条
            $by_lang[$row['lang']] = $row['loc'];

            $alternates = [];
            foreach ($languages as $language) {
                if (isset($by_lang[$language['slug']])) {
                    $alternates[] = [
                        'hreflang' => $language['hreflang'],
                        'href'     => $by_lang[$language['slug']],
                    ];
                }
            }

            $rows[$post_id]['alternates'] = $alternates;
            // x-default：优先指向默认语言版本；这条内容没有默认语言译文时指向自己，
            // 保证每条 <url> 都有 x-default（Google 的样例就是这么写的）
            $rows[$post_id]['xdefault']   = $by_lang[$default] ?? $row['loc'];
        }

        // 分语言、组内按「页面 → 文章 → 产品 → 应用 → FAQ，再按标题」排
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['lang']][] = $row;
        }
        foreach ($groups as $lang => $items) {
            usort($items, function ($a, $b) {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] <=> $b['type'];
                }
                return strcasecmp($a['title'], $b['title']);
            });
            $groups[$lang] = $items;
        }

        $cache = $groups;
        return $cache;
    }
}

if (!function_exists('tpi_sitemap_public_host')) {
    /**
     * sitemap 里 <loc> 应当使用的对外主机名。
     *
     * 和页面输出走同一套判定（见 inc/output/cdn-host.php 的 tpi_cdn_effective_host）：
     *   CDN 回源（带 X-Forwarded-Host）→ 用它，sitemap 里不出现源站域名；
     *   直连源站 → 访问域名；
     *   最后兜底数据库 siteurl。
     * 主题里不写死任何域名，后台也不需要填。
     */
    function tpi_sitemap_public_host(): string
    {
        if (function_exists('tpi_cdn_effective_host')) {
            $host = (string) tpi_cdn_effective_host();
            if ($host !== '') {
                return $host;
            }
        }

        foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_HOST'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $host = (string) explode(',', (string) $_SERVER[$key])[0];
            $host = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', trim($host));
            $host = (string) preg_replace('~[/?#].*$~', '', $host);
            $host = (string) preg_replace('~:\d+$~', '', $host);
            $host = strtolower(trim($host, '.'));
            if ($host !== '' && $host !== 'localhost') {
                return $host;
            }
        }

        return (string) parse_url((string) get_option('siteurl', ''), PHP_URL_HOST);
    }
}

if (!function_exists('tpi_sitemap_scheme')) {
    /** 协议：与页面输出同一套判定（常量 > 后台配置 > 请求 > siteurl），主题不预设 https */
    function tpi_sitemap_scheme(): string
    {
        if (function_exists('tpi_cdn_public_scheme')) {
            $scheme = (string) tpi_cdn_public_scheme();
            if ($scheme !== '') {
                return $scheme;
            }
        }
        $scheme = (string) parse_url((string) get_option('siteurl', ''), PHP_URL_SCHEME);

        return $scheme !== '' ? $scheme : 'http';
    }
}

if (!function_exists('tpi_sitemap_apply_host')) {
    /**
     * 把 XML 里的站点域名统一换成对外域名，并校正协议。
     *
     * 只在字符串层面换主机名：<loc>、hreflang 的 href、x-default 一次全覆盖，
     * 不动标签结构（<xhtml:link … /> 的自闭合斜杠保持原样，XML 结构不受影响）。
     */
    function tpi_sitemap_apply_host(string $xml): string
    {
        $host = tpi_sitemap_public_host();
        if ($host === '') {
            return $xml;
        }

        $old = [];
        foreach (['home', 'siteurl'] as $option) {
            $one = (string) parse_url((string) get_option($option, ''), PHP_URL_HOST);
            if ($one !== '' && strcasecmp($one, $host) !== 0) {
                $old[$one] = true;
            }
        }
        // 请求里带的域名也要换掉：某条链路把 Host 改写成源站域名时，
        // 光靠 siteurl 匹配不到，这里一并兜住。
        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $one = strtolower(trim((string) explode(',', (string) $_SERVER[$key])[0]));
            $one = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $one);
            $one = (string) preg_replace('~[/?#].*$~', '', $one);
            $one = (string) preg_replace('~:\d+$~', '', $one);
            $one = trim($one, '.');
            if ($one !== '' && $one !== 'localhost' && strcasecmp($one, $host) !== 0) {
                $old[$one] = true;
            }
        }

        foreach (array_keys($old) as $one) {
            $pattern = '~(?<![A-Za-z0-9_.\-])' . preg_quote($one, '~') . '(?![A-Za-z0-9_\-])~i';
            $xml     = (string) preg_replace($pattern, $host, $xml);
        }

        // 协议对齐：siteurl 是 https 时，<loc> 不能出现 http://对外域名
        if (tpi_sitemap_scheme() === 'https') {
            $xml = str_replace('http://' . $host, 'https://' . $host, $xml);
        }

        return $xml;
    }
}

if (!function_exists('tpi_sitemap_send_xml')) {
    /** 输出 XML 并结束请求 */
    function tpi_sitemap_send_xml(string $xml): void
    {
        // 出口统一校正域名：不依赖输出缓冲，也不依赖 home_url 过滤器
        $xml = tpi_sitemap_apply_host($xml);

        if (!headers_sent()) {
            status_header(200); // 未命中 rewrite，WP 可能已经设过 404
            header('Content-Type: application/xml; charset=UTF-8');
            header('Cache-Control: public, max-age=600'); // 10 分钟；太长的话改了域名 CDN 边缘要等很久才更新
            header('X-Content-Type-Options: nosniff');
            header('X-TPI-Sitemap: ' . tpi_sitemap_public_host());
        }
        echo $xml;
        exit;
    }
}

if (!function_exists('tpi_sitemap_alt_link')) {
    /** 一条 xhtml:link，写法与 Microsoft / Google 的 sitemap 一致 */
    function tpi_sitemap_alt_link(string $hreflang, string $href): string
    {
        return '<xhtml:link rel="alternate" hreflang="' . esc_attr($hreflang) . '" href="' . esc_url($href) . '" />';
    }
}

add_action('template_redirect', 'tpi_sitemap_redirect_legacy', 1);

if (!function_exists('tpi_sitemap_redirect_legacy')) {
    /**
     * 旧地址 301：
     *   /wp-sitemap.xml、/wp-sitemap-posts-post-1.xml、/wp-sitemap.xsl  → /sitemap.xml
     *   /ja/wp-sitemap.xml（带语言前缀的写法）                        → /sitemap.xml
     *   /sitemap_index.xml、/sitemap-index.xml（插件时代老地址）        → /sitemap.xml
     *   /sitemap-ja.xml（上一版的写法）                                → /ja/sitemap.xml
     * 只要路径里出现 wp-sitemap 且以 .xml/.xsl 结尾就跳，不做行首匹配，
     * 免得用户在语言目录下访问时漏掉。
     */
    function tpi_sitemap_redirect_legacy(): void
    {
        $path = strtolower(tpi_sitemap_request_path());
        if ($path === '') {
            return;
        }

        // 上一版 /sitemap-{lang}.xml → /{lang}/sitemap.xml
        if (preg_match('#^sitemap-([a-z]{2,5})\.xml$#', $path, $matches)) {
            foreach (tpi_sitemap_languages() as $language) {
                if ($language['slug'] === $matches[1]) {
                    wp_safe_redirect(home_url('/' . $matches[1] . '/sitemap.xml'), 301);
                    exit;
                }
            }
            return;
        }

        $is_legacy = (bool) preg_match('#(^|/)wp-sitemap[a-z0-9._-]*\.(xml|xsl)$#', $path)
            || in_array($path, ['sitemap_index.xml', 'sitemap-index.xml'], true);
        if (!$is_legacy) {
            return;
        }
        wp_safe_redirect(home_url('/sitemap.xml'), 301);
        exit;
    }
}

add_action('template_redirect', 'tpi_sitemap_serve', 1);

if (!function_exists('tpi_sitemap_serve')) {
    /** 输出 /sitemap.xml（语言索引）与 /{lang}/sitemap.xml（单语言） */
    function tpi_sitemap_serve(): void
    {
        $path = strtolower(tpi_sitemap_request_path());
        if ($path === '') {
            return;
        }

        $is_index = ($path === 'sitemap.xml');
        $lang     = '';
        if (!$is_index) {
            if (!preg_match('#^([a-z]{2,5})/sitemap\.xml$#', $path, $matches)) {
                return;
            }
            $lang = $matches[1];
        }

        $languages = tpi_sitemap_languages();
        $rows      = tpi_sitemap_collect();

        if ($is_index) {
            $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($languages as $language) {
                $slug = $language['slug'];
                if (empty($rows[$slug])) {
                    continue;
                }
                $lastmods = array_column($rows[$slug], 'lastmod');
                $lastmod  = $lastmods ? max($lastmods) : '';
                $xml .= "\t<sitemap>\n";
                $xml .= "\t\t<loc>" . esc_xml(home_url('/' . $slug . '/sitemap.xml')) . "</loc>\n";
                if ($lastmod !== '') {
                    $xml .= "\t\t<lastmod>" . esc_xml($lastmod) . "</lastmod>\n";
                }
                $xml .= "\t</sitemap>\n";
            }
            $xml .= '</sitemapindex>' . "\n";
            tpi_sitemap_send_xml($xml);
        }

        // 不是站内语言就交给 WP 正常走 404
        $known = false;
        foreach ($languages as $language) {
            if ($language['slug'] === $lang) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return;
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($rows[$lang] ?? [] as $item) {
            $xml .= "\t<url>\n";
            $xml .= "\t\t<loc>" . esc_xml($item['loc']) . "</loc>\n";
            if ($item['lastmod'] !== '') {
                $xml .= "\t\t<lastmod>" . esc_xml($item['lastmod']) . "</lastmod>\n";
            }
            // x-default 放最前（与 Google 的写法一致），后面按语言顺序铺开
            if ($item['xdefault'] !== '') {
                $xml .= "\t\t" . tpi_sitemap_alt_link('x-default', $item['xdefault']) . "\n";
            }
            foreach ($item['alternates'] as $alternate) {
                $xml .= "\t\t" . tpi_sitemap_alt_link($alternate['hreflang'], $alternate['href']) . "\n";
            }
            $xml .= "\t</url>\n";
        }

        $xml .= '</urlset>' . "\n";
        tpi_sitemap_send_xml($xml);
    }
}
