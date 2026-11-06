<?php
add_action('template_redirect', function() {
    global $old_host;

    ob_start(function($html) use ($old_host) {
        // 1. 上传路径：/wp-content/uploads -> 自定义前缀
        $to = tpi_site_path_prefix('uploads');
        if ($to !== '') {
            $html = str_replace(
                [$old_host . '/wp-content/uploads', '/wp-content/uploads'],
                [$old_host . $to, $to],
                $html
            );
        }

        // 2. 主题静态资源：/wp-content/themes/{theme}/assets -> 自定义前缀
        //    正常情况下 tpi_asset() 已经直接输出自定义前缀，这里只兜底硬编码的地址
        $to = tpi_site_path_prefix('assets');
        if ($to !== '') {
            $theme_assets = '/wp-content/themes/' . get_template() . '/assets';
            $html         = str_replace(
                [$old_host . $theme_assets, $theme_assets],
                [$old_host . $to, $to],
                $html
            );
        }

        // 3. WordPress 核心资源：/wp-includes -> 自定义前缀
        $to = tpi_site_path_prefix('includes');
        if ($to !== '') {
            $html = str_replace(
                [$old_host . '/wp-includes', '/wp-includes'],
                [$old_host . $to, $to],
                $html
            );
        }

        // 4. 域名替换统一交给 inc/output/cdn-host.php 的输出缓冲：
        //    它只在 CDN 回源（带 X-Forwarded-Host）时动作，并且会把
        //    <link rel="canonical"> 单独保留成目标源域名。这里再替换一遍
        //    会把 canonical 一起改成 CDN 域名，所以这一步已经删掉。

        // 5. 标签属性一律用双引号
        //    WP 核心输出的这几类是单引号，和模板里的双引号混在一起：
        //      <meta name='robots' content='…'>
        //      <link rel='stylesheet' id='…' href='…' media='all'>
        //      <style id='global-styles-inline-css' type='text/css'>
        //    只改开标签上的属性，<style> / <script> 的内容一个字都不动。
        $html = preg_replace_callback(
            '#<(meta|link|style|script)\b[^>]*>#i',
            static function (array $m): string {
                return (string) preg_replace("#=\s*'([^']*)'#", '="$1"', $m[0]);
            },
            $html
        );

        // 6. HTML5 的 void 元素不要自闭合斜杠
        //    <meta … /> <link … /> <img … /> <input … /> <br /> 都是 HTML5 空元素，
        //    规范写法是 <meta …>、<img …>，多加的「/」是 XHTML 遗留物，解析器直接忽略。
        //    只处理这 14 个空元素；<script>/<style>/<textarea> 先整块取出来占位，
        //    里面的字符串（JSON-LD、内联脚本）一个字都不动；SVG 的 <path/> <use/>
        //    也不在名单里（SVG 是 XML，自闭合斜杠是必须的，去掉会画不出图）。
        $keep = [];
        $html = (string) preg_replace_callback(
            '#<(script|style|textarea)\b[^>]*>.*?</\1>#is',
            static function (array $m) use (&$keep): string {
                $key        = "\x02OSIERA_KEEP_" . count($keep) . "\x03";
                $keep[$key] = $m[0];
                return $key;
            },
            $html
        );
        // 属性里带「>」的极少数标签匹配不到，原样保留，不影响页面
        $fixed = preg_replace(
            '#<(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)\b([^<>]*?)\s*/>#i',
            '<$1$2>',
            $html
        );
        if (is_string($fixed)) {
            $html = $fixed;
        }
        if ($keep) {
            $html = strtr($html, $keep);
        }

        return html_entity_decode($html);
    });
});

// 自定义 robots.txt 内容
// 域名直接从 CDN 判定里取（走 CDN 时就是 X-Forwarded-Host 带过来的域名），
// 不靠输出缓冲事后替换 —— robots.txt 是给搜索引擎读的，源头就得是对的。
// 后台「本站 SEO」关闭时源站直接禁止抓取；经 CDN 回源时仍按正常内容输出，
// 这样本地 / 预览站在 Sitemap 与收录上不会污染线上。
add_filter('robots_txt', function ($output, $public) {
    if (function_exists('tpi_monitor_seo_allowed') && !tpi_monitor_seo_allowed()) {
        return "User-agent: *\n".
            "Disallow: /\n";
    }

    $scheme = function_exists('tpi_cdn_public_scheme')
        ? tpi_cdn_public_scheme()
        : ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http');
    $host = function_exists('tpi_cdn_effective_host')
        ? (string) tpi_cdn_effective_host()
        : (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return $output;
    }
    return "User-agent: *\n".
        "Allow: /\n".
        "Sitemap: {$scheme}://{$host}/sitemap.xml\n";
}, 10, 2);

//add_action('template_redirect', function() {
//    if (is_404()) { // 判断是否 404 页面
//        global $cdn_host, $new_host, $current_lang;
//        $home_url = home_url();
//        if ($cdn_host) {
//            $home_url = $new_host. '/'. ($current_lang ? $current_lang. '/home' : '');
//        }
//        wp_redirect($home_url, 301); // 301 永久重定向到首页
//        exit;
//    }
//});

// 跳转地址的域名替换在 inc/output/cdn-host.php 的 tpi_cdn_filter_redirect 里做，
// 那里覆盖的源域名更全（站点域名 + Host + X-Original-Host + 历史域名）。
