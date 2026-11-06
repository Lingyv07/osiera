<?php
/**
 * SVG 取色规则（Osiera）
 *
 * 一条规则：**文件名决定填充色。**
 *
 *   assets/svg 里只存 `#ffffff` 母版，颜色不再复制文件；
 *   URL 末尾带 `-RRGGBB` 就按该色输出，不带就是白色。
 *
 *   .../assets/svg/WhatsApp.svg            → fill="#ffffff"
 *   .../assets/svg/WhatsApp-000000.svg     → fill="#000000"
 *   .../assets/svg/Calendar-7b8896.svg     → fill="#7b8896"
 *
 * 好处：
 *   1. 加一个颜色不用再加一份文件，也不会出现 `-white` / `-black` / `-gray`
 *      这种同图多份、改一处忘一处的情况；
 *   2. 主题主色变了只改调用处，母版永远是白的；
 *   3. 变体由 PHP 按需输出（带一年缓存 + ETag），没有文件也能访问。
 *
 * 对外函数：
 *   tpi_svg_url($name, $hex)     取 URL（默认主题目录地址，不吃 /assets 别名）
 *   tpi_svg_markup($name, $hex)  取 svg 源码（内联用，比如 require 的位置）
 *   tpi_svg_colorize($svg, $hex) 把任意 svg 的全部颜色换成 $hex
 *   tpi_svg_parse_name($file)    解析 "WhatsApp-000000.svg" → ['WhatsApp', '000000']
 *
 * @package Osiera
 */

if (!function_exists('tpi_svg_dir')) {
    /** SVG 母版目录（磁盘） */
    function tpi_svg_dir(): string
    {
        return THEME_DIR . 'assets/svg/';
    }
}

if (!function_exists('tpi_svg_base_url')) {
    /**
     * SVG 目录的公开地址
     *
     * 固定用主题目录，不走 tpi_asset_base()：自定义 /assets 前缀是 Nginx 别名，
     * 指向不存在的变体文件会直接 404，请求根本进不到 PHP，取色规则就失效了。
     */
    function tpi_svg_base_url(): string
    {
        return untrailingslashit(get_template_directory_uri()) . '/assets/svg/';
    }
}

if (!function_exists('tpi_svg_parse_name')) {
    /**
     * 拆文件名：把颜色后缀和文件名分开
     *
     * @param string $file 可带 .svg，也可不带
     * @return array{0:string,1:string} [文件名, 十六进制色（无则空）]
     */
    function tpi_svg_parse_name(string $file): array
    {
        $file = basename(trim($file));
        $file = (string) preg_replace('/\.svg$/i', '', $file);
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*?)-([0-9a-fA-F]{6})$/', $file, $m)) {
            return [$m[1], strtolower($m[2])];
        }
        return [$file, ''];
    }
}

if (!function_exists('tpi_svg_url')) {
    /**
     * 取一个图标的地址
     *
     * @param string $name 母版名（可带 .svg，也可带已有颜色后缀）
     * @param string $hex  颜色，不带 #；传空 = 白色母版
     * @return string
     */
    function tpi_svg_url(string $name, string $hex = ''): string
    {
        [$base] = tpi_svg_parse_name($name);
        $hex    = strtolower(trim($hex));
        $suffix = preg_match('/^[0-9a-f]{6}$/', $hex) ? '-' . $hex : '';
        return tpi_svg_base_url() . $base . $suffix . '.svg';
    }
}

if (!function_exists('tpi_svg_colorize')) {
    /**
     * 把 svg 里所有可见颜色换成指定色
     *
     * 只换 fill / stroke / stop-color / flood-color；`none`、`transparent`、
     * `url(#渐变)` 一律保留，否则描边图形和渐变图标会被压成一块实心色。
     *
     * @param string $svg
     * @param string $hex 6 位十六进制（不含 #）
     * @return string
     */
    function tpi_svg_colorize(string $svg, string $hex): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $hex) ?? '');
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return $svg;
        }

        $keep = static function (string $value): bool {
            $value = strtolower(trim($value));
            return $value === '' || $value === 'none' || $value === 'transparent'
                || $value === 'inherit' || strpos($value, 'url(') === 0;
        };

        $svg = preg_replace_callback(
            '/\b(fill|stroke|stop-color|flood-color)\s*=\s*(["\'])([^"\']*)\2/i',
            static function (array $m) use ($hex, $keep): string {
                return $keep($m[3]) ? $m[0] : $m[1] . '="' . '#' . $hex . '"';
            },
            $svg
        ) ?? $svg;

        $svg = preg_replace_callback(
            '/\b(fill|stroke|stop-color)\s*:\s*([^;"\']+)/i',
            static function (array $m) use ($hex, $keep): string {
                return $keep($m[2]) ? $m[0] : $m[1] . ':#' . $hex;
            },
            $svg
        ) ?? $svg;

        // 完全没有 fill 的图（默认黑）补到根节点上
        if (!preg_match('/\bfill\s*=/i', $svg) && stripos($svg, 'fill:') === false) {
            $svg = (string) preg_replace('/<svg\b/i', '<svg fill="#' . $hex . '"', $svg, 1);
        }

        return $svg;
    }
}

if (!function_exists('tpi_svg_markup')) {
    /**
     * 取内联 svg 源码（模板里代替 `require .../X.svg`）
     *
     * @param string $name
     * @param string $hex 空 = 白色
     * @return string
     */
    function tpi_svg_markup(string $name, string $hex = ''): string
    {
        static $cache = [];
        [$base, $suffix] = tpi_svg_parse_name($name);
        $hex  = $hex !== '' ? strtolower(preg_replace('/[^0-9a-fA-F]/', '', $hex) ?? '') : $suffix;
        $key  = $base . '|' . $hex;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $file = tpi_svg_dir() . $base . '.svg';
        if (!is_file($file)) {
            return $cache[$key] = '';
        }
        $svg = (string) file_get_contents($file);
        if ($hex !== '') {
            $svg = tpi_svg_colorize($svg, $hex);
        }
        return $cache[$key] = $svg;
    }
}

if (!function_exists('tpi_svg_serve')) {
    /**
     * 按需输出取色后的 SVG
     *
     * 只在「变体文件本来就不存在」时接管，母版仍由 Web 服务器直接发。
     * 挂在 init，早于 WordPress 的 404 判定。
     */
    function tpi_svg_serve(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = (string) parse_url(tpi_svg_base_url(), PHP_URL_PATH);
        if ($path === '' || $base === '' || strpos($path, $base) !== 0) {
            return;
        }

        $file = substr($path, strlen($base));
        if ($file === '' || strpos($file, '/') !== false || strpos($file, '..') !== false) {
            return;
        }
        if (!preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]*?)-([0-9a-fA-F]{6})\.svg$/', $file, $m)) {
            return; // 不带颜色后缀：母版文件，交给 Web 服务器
        }

        $source = tpi_svg_dir() . $m[1] . '.svg';
        if (!is_file($source)) {
            return; // 没有这个母版，正常走 404
        }

        $svg  = tpi_svg_colorize((string) file_get_contents($source), strtolower($m[2]));
        $etag = 'W/"' . substr(md5($svg), 0, 20) . '"';

        if (!headers_sent()) {
            $ims = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
            $lm  = (int) filemtime($source);

            header('Content-Type: image/svg+xml; charset=UTF-8');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lm) . ' GMT');
            header('X-TPI-SVG: colorized');

            if ($ims !== '' && trim($ims) === $etag) {
                http_response_code(304);
                exit;
            }
            header('Content-Length: ' . (string) strlen($svg));
        }

        if ($method === 'HEAD') {
            exit;
        }
        echo $svg;
        exit;
    }
}
add_action('init', 'tpi_svg_serve', 5);
