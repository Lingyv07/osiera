<?php
/**
 * 站点目标源与 URL 前缀
 *
 * 线上站把静态资源收在 /assets、上传收在 /uploads/assets、接口收在 /api；
 * 新项目装主题时没有这些 nginx 规则，必须用 WordPress 原生路径才能开箱就跑。
 *
 * 目标源只管 canonical：页面里的其他地址（hreflang、结构化数据、静态资源）
 * 都跟访客当前访问的域名走，与目标源、与 CDN 都没有关系。
 *
 * 所以每一项都是「原生 / 自定义」二选一：
 *   native —— 用 WordPress 自己算出来的地址，不需要任何 nginx 配置（默认）
 *   custom —— 填一个前缀，前台按这个前缀输出，站点需要配对应的 nginx 规则
 *
 * 前缀默认空着，用户自己填；留空就等于 native（不会悄悄塞一个默认前缀）。
 *
 * 存储：option `tpi_site_paths`，全站一份，不按语言分。
 */

if (!function_exists('tpi_site_paths_defaults')) {
    /**
     * @return array<string,string>
     */
    function tpi_site_paths_defaults(): array
    {
        return [
            // 目标源：canonical / JSON-LD / IndexNow 使用的站点根，
            // 留空按当前访问域名自动推导。
            // CDN 域名不在这里填：CDN 回源时用 X-Forwarded-Host 带过来，
            // 页面里除 canonical 以外的域名都跟着它走（见 inc/output/cdn-host.php）。
            'target_host' => '',

            // 主题静态资源：native = 主题目录/assets，custom = 填入前缀
            'assets_mode' => 'native',
            'assets_path' => '',

            // 上传目录：native = /wp-content/uploads，custom = 填入前缀
            'uploads_mode' => 'native',
            'uploads_path' => '',

            // WordPress 核心资源：native = /wp-includes，custom = 填入前缀
            'includes_mode' => 'native',
            'includes_path' => '',

            // 接口：native = /wp-json/api，custom = 填入前缀
            'api_mode'  => 'native',
            'api_path'  => '',
        ];
    }
}

if (!function_exists('tpi_path_normalize')) {
    /**
     * 把用户填的前缀规范化：允许贴完整 URL，只留路径部分。
     *
     * 'https://www.example.com/assets/' -> '/assets'
     * 'assets'                         -> '/assets'
     *
     * @param string $raw
     * @param string $fallback 规范化后为空时的兜底
     * @return string
     */
    function tpi_path_normalize(string $raw, string $fallback = ''): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }
        if (preg_match('#^https?://#i', $raw)) {
            $raw = (string) wp_parse_url($raw, PHP_URL_PATH);
        }
        $raw = (string) preg_replace('~[?#].*$~', '', $raw);
        $raw = '/' . ltrim($raw, '/');
        $raw = (string) preg_replace('~/+~', '/', $raw);
        $raw = rtrim($raw, '/');
        return $raw === '' ? $fallback : $raw;
    }
}

if (!function_exists('tpi_site_paths')) {
    /**
     * @return array<string,string>
     */
    function tpi_site_paths(): array
    {
        if (isset($GLOBALS['tpi_site_paths_cache'])) {
            return $GLOBALS['tpi_site_paths_cache'];
        }

        $defaults = tpi_site_paths_defaults();
        $saved    = get_option('tpi_site_paths', []);
        $saved    = is_array($saved) ? $saved : [];
        $cache    = array_merge($defaults, array_intersect_key($saved, $defaults));

        // 老版本用 short 表示「自定义」，这里统一成 custom
        foreach (['assets', 'uploads', 'includes', 'api'] as $key) {
            $mode_key = $key . '_mode';
            if ($cache[$mode_key] === 'short') {
                $cache[$mode_key] = 'custom';
            }
            if (!in_array($cache[$mode_key], ['native', 'custom'], true)) {
                $cache[$mode_key] = 'native';
            }
            // 原生模式不保存前缀；自定义留空同样回落到原生
            $cache[$key . '_path'] = $cache[$mode_key] === 'custom'
                ? tpi_path_normalize((string) $cache[$key . '_path'])
                : '';
        }

        // 只保留 defaults 里的键，旧版本遗留的 cdn_host 等键在这一步自然丢掉
        $cache['target_host'] = trim((string) $cache['target_host']);

        $GLOBALS['tpi_site_paths_cache'] = $cache;
        return $cache;
    }
}

if (!function_exists('tpi_site_path')) {
    function tpi_site_path(string $key, string $fallback = ''): string
    {
        $paths = tpi_site_paths();
        return (string) ($paths[$key] ?? $fallback);
    }
}

if (!function_exists('tpi_site_path_prefix')) {
    /**
     * 某项实际生效的自定义前缀。
     *
     * 只有「选了自定义 + 填了值」才返回前缀，其余情况一律返回空串，
     * 调用方就用 WordPress 原生地址 —— 这就是「留空 = 原生」的落点。
     */
    function tpi_site_path_prefix(string $key, ?array $paths = null): string
    {
        $paths = $paths ?? tpi_site_paths();
        if ((string) ($paths[$key . '_mode'] ?? 'native') !== 'custom') {
            return '';
        }
        return rtrim((string) ($paths[$key . '_path'] ?? ''), '/');
    }
}

if (!function_exists('tpi_site_paths_save')) {
    /**
     * @param array<string,mixed> $input
     * @return array<string,string> 保存后的完整配置
     */
    function tpi_site_paths_save(array $input): array
    {
        $defaults = tpi_site_paths_defaults();
        $clean    = $defaults;

        $host = trim((string) ($input['target_host'] ?? ''));
        if ($host !== '') {
            if (!preg_match('#^https?://#i', $host)) {
                $host = 'https://' . $host;
            }
            $host = rtrim(esc_url_raw($host), '/');
        }
        $clean['target_host'] = $host;

        foreach (['assets', 'uploads', 'includes', 'api'] as $key) {
            $mode_key = $key . '_mode';
            $mode     = (string) ($input[$mode_key] ?? 'native');
            if ($mode === 'short') {
                $mode = 'custom';
            }
            $clean[$mode_key] = in_array($mode, ['native', 'custom'], true) ? $mode : 'native';
            // 原生模式不落前缀；自定义留空同样等于原生
            $clean[$key . '_path'] = $clean[$mode_key] === 'custom'
                ? tpi_path_normalize((string) ($input[$key . '_path'] ?? ''))
                : '';
        }

        update_option('tpi_site_paths', $clean);
        unset($GLOBALS['tpi_site_paths_cache']);

        return $clean;
    }
}

if (!function_exists('tpi_target_host')) {
    /**
     * 配置里的目标源（含协议、无尾斜杠）；没配置返回空串，由调用方回落到自动推导。
     */
    function tpi_target_host(): string
    {
        return (string) tpi_site_path('target_host');
    }
}

if (!function_exists('tpi_site_root')) {
    /** 站点根地址（site_url，不带尾斜杠） */
    function tpi_site_root(): string
    {
        return rtrim(site_url(), '/');
    }
}

if (!function_exists('tpi_public_root')) {
    /**
     * 对外公开的站点根（canonical / JSON-LD / IndexNow 用的那种）。
     *
     * 后台填了目标源就用目标源，否则用自动推导出来的 $main_host；
     * 两者都没有才回落到 site_url()。
     *
     * 注意：hreflang 不用这里，它跟「当前访问域名」走（见 tpi_request_root()）——
     * 访客从哪个域名进来，hreflang 就指向哪个；canonical 才用目标源。
     */
    function tpi_public_root(): string
    {
        $target = tpi_target_host();
        if ($target !== '') {
            return rtrim($target, '/');
        }
        global $main_host;
        $main = trim((string) ($main_host ?? ''));
        if ($main !== '') {
            return rtrim($main, '/');
        }
        return tpi_site_root();
    }
}

if (!function_exists('tpi_public_url')) {
    /**
     * 把内部算出来的地址换成对外公开域名，路径原样保留。
     *
     * get_permalink() 用的是 site_url()（本地就是 http://127.0.1.0），
     * 而 canonical / JSON-LD 必须落在目标源上，否则 view-source 里两套域名打架。
     */
    function tpi_public_url(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $public = tpi_public_root();
        $root   = tpi_site_root();
        if ($public === '' || $root === '' || $public === $root) {
            return $url;
        }
        if (strpos($url, $root) === 0) {
            return $public . substr($url, strlen($root));
        }
        return $url;
    }
}

if (!function_exists('tpi_request_root')) {
    /**
     * 当前请求实际访问的站点根（含协议，无尾斜杠）。
     *
     * 和 tpi_public_root() 的区别：这个跟着浏览器地址栏走 ——
     * 访客用哪个域名访问，它就是哪个域名（本地就是本地地址，线上就是线上域名）。
     * hreflang 必须用它 —— 指向访客正在访问的域名，而不是 CDN 的对外域名。
     */
    function tpi_request_root(): string
    {
        // 访客实际使用的域名：CDN 回源时 Host 是源站，X-Forwarded-Host 才是访客域名
        $host = function_exists('tpi_cdn_request_host') ? tpi_cdn_request_host() : '';
        if ($host === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        }
        if ($host === '') {
            return tpi_site_root();
        }

        $scheme = '';
        $proto  = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto !== '') {
            $one = strtolower(trim((string) explode(',', $proto)[0]));
            if ($one === 'https' || $one === 'http') {
                $scheme = $one;
            }
        }
        if ($scheme === '' && !empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
        } elseif ($scheme === '' && !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif ($scheme === '' && function_exists('is_ssl') && is_ssl()) {
            $scheme = 'https';
        }
        if ($scheme !== 'https' && $scheme !== 'http') {
            $scheme = 'http';
        }

        return $scheme . '://' . $host;
    }
}

if (!function_exists('tpi_url_on_root')) {
    /**
     * 把站点内部地址（site_url 那套）搬到指定站点根上，路径与参数原样保留。
     * hreflang 用它把 permalink 落到「当前访问域名」。
     */
    function tpi_url_on_root(string $url, string $root): string
    {
        if ($url === '' || $root === '') {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($path === '') {
            $path = '/';
        }
        // 公开地址统一不带尾斜杠（与线上 view-source 一致），根路径除外
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        if ($query !== '') {
            $path .= '?' . $query;
        }

        return rtrim($root, '/') . $path;
    }
}

if (!function_exists('tpi_asset_base')) {
    /**
     * 主题静态资源根地址。
     *
     * native：get_template_directory_uri()/assets —— 新项目不用配 nginx 就能用
     * custom：站点根 + 填的前缀                    —— 与线上一致，需要 nginx alias
     */
    function tpi_asset_base(): string
    {
        $prefix = tpi_site_path_prefix('assets');
        if ($prefix !== '') {
            return tpi_site_root() . $prefix;
        }
        return get_template_directory_uri() . '/assets';
    }
}

if (!function_exists('tpi_uploads_base')) {
    /** 上传目录根地址（媒体库文件拼接用） */
    function tpi_uploads_base(): string
    {
        $prefix = tpi_site_path_prefix('uploads');
        if ($prefix !== '') {
            return tpi_site_root() . $prefix;
        }
        return content_url('/uploads');
    }
}

if (!function_exists('tpi_includes_prefix')) {
    /**
     * 核心资源实际生效的前缀
     *
     * 与主题静态资源前缀相同时强制回落原生：nginx 里 /assets 已经 rewrite 到主题目录，
     * 核心文件放同一前缀下必然 404（/assets/css/dist/block-library/style.min.css
     * 在主题里根本不存在）。核心资源要用自定义前缀，直接填 /includes 这类
     * 独立路径即可，并在服务器上配一条对应的 rewrite。
     */
    function tpi_includes_prefix(?array $paths = null): string
    {
        $prefix = tpi_site_path_prefix('includes', $paths);
        if ($prefix !== '' && $prefix === tpi_site_path_prefix('assets', $paths)) {
            return '';
        }
        return $prefix;
    }
}

if (!function_exists('tpi_includes_base')) {
    /** WordPress 核心资源根地址 */
    function tpi_includes_base(): string
    {
        $prefix = tpi_includes_prefix();
        if ($prefix !== '') {
            return tpi_site_root() . $prefix;
        }
        return tpi_site_root() . '/wp-includes';
    }
}

if (!function_exists('tpi_api_base')) {
    /** 接口根地址（不带尾斜杠） */
    function tpi_api_base(): string
    {
        $prefix = tpi_site_path_prefix('api');
        if ($prefix !== '') {
            return tpi_site_root() . $prefix;
        }
        return tpi_site_root() . '/wp-json/api';
    }
}

if (!function_exists('tpi_api_url')) {
    /**
     * 拼一个接口地址，例如 tpi_api_url('inquiry')。
     */
    function tpi_api_url(string $route = ''): string
    {
        $base  = tpi_api_base();
        $route = ltrim(trim($route), '/');
        return $route === '' ? $base : $base . '/' . $route;
    }
}

if (!function_exists('tpi_nginx_re')) {
    /**
     * 把前缀转成 nginx 正则里可直接用的字面量：转义特殊字符，但不把 / 写成 \/。
     */
    function tpi_nginx_re(string $prefix): string
    {
        return str_replace('\\/', '/', preg_quote($prefix, '#'));
    }
}

if (!function_exists('tpi_nginx_snippet')) {
    /**
     * 按配置生成 nginx 片段，后台直接展示给用户复制。
     *
     * @param array|null $paths 不传就用已保存的配置；后台实时预览会传表单当前值
     */
    function tpi_nginx_snippet(?array $paths = null): string
    {
        $paths = $paths ?? tpi_site_paths();
        $root  = tpi_site_root();
        $lines = [tpi_admin_ui('site_paths.nginx.root-hint')];

        $assets_path   = rtrim(tpi_site_path_prefix('assets', $paths), '/');
        $includes_path = rtrim(tpi_includes_prefix($paths), '/');
        $uploads_path  = rtrim(tpi_site_path_prefix('uploads', $paths), '/');
        $api_path      = rtrim(tpi_site_path_prefix('api', $paths), '/');

        // rewrite 的目标是 URI 而不是磁盘路径，站点装在子目录时要把那一段带上
        $uri_base = rtrim((string) wp_parse_url($root, PHP_URL_PATH), '/');

        if ($includes_path !== '') {
            $lines[] = '';
            $lines[] = tpi_admin_ui('site_paths.nginx.includes');
            $lines[] = 'location ^~ ' . $includes_path . '/ {';
            $lines[] = '    rewrite ^' . tpi_nginx_re($includes_path) . '/(.*)$ ' . $uri_base . '/wp-includes/$1 last;';
            $lines[] = '}';
        }

        if ($assets_path !== '') {
            $lines[] = '';
            $lines[] = tpi_admin_ui('site_paths.nginx.assets');
            $lines[] = 'location ^~ ' . $assets_path . '/ {';
            $lines[] = '    rewrite ^' . tpi_nginx_re($assets_path) . '/(.*)$ '
                . $uri_base . '/wp-content/themes/' . basename(get_template_directory()) . '/assets/$1 last;';
            $lines[] = '}';
        }

        if ($uploads_path !== '') {
            $lines[] = '';
            $lines[] = tpi_admin_ui('site_paths.nginx.uploads');
            $lines[] = 'location ^~ ' . $uploads_path . '/ {';
            $lines[] = '    rewrite ^' . tpi_nginx_re($uploads_path) . '/(.*)$ ' . $uri_base . '/wp-content/uploads/$1 last;';
            $lines[] = '}';
        }

        if ($api_path !== '') {
            $lines[] = '';
            $lines[] = tpi_admin_ui('site_paths.nginx.api');
            $lines[] = 'location ^~ ' . $api_path . '/ {';
            $lines[] = '    proxy_pass ' . $root . '/wp-json/api/;';
            $lines[] = '    proxy_set_header Host $host;';
            $lines[] = '    proxy_set_header X-Real-IP $remote_addr;';
            $lines[] = '    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
            $lines[] = '    proxy_set_header X-Forwarded-Proto $scheme;';
            $lines[] = '}';
        }

        if (count($lines) === 1) {
            $lines[] = '';
            $lines[] = tpi_admin_ui('site_paths.nginx.native');
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('tpi_site_paths_modes')) {
    /**
     * 后台用的选项定义
     *
     * @return array<string,array>
     */
    function tpi_site_paths_modes(): array
    {
        return [
            'assets' => [
                'label'       => tpi_admin_ui('site_paths.assets.label'),
                'desc'        => tpi_admin_ui('site_paths.assets.desc'),
                'native_text' => tpi_admin_ui('site_paths.native.template'),
                'native_url'  => get_template_directory_uri() . '/assets',
                'placeholder' => '/assets',
            ],
            'uploads' => [
                'label'       => tpi_admin_ui('site_paths.uploads.label'),
                'desc'        => tpi_admin_ui('site_paths.uploads.desc'),
                'native_text' => tpi_admin_ui('site_paths.native.core'),
                'native_url'  => content_url('/uploads'),
                'placeholder' => '/uploads/assets',
            ],
            'includes' => [
                'label'       => tpi_admin_ui('site_paths.includes.label'),
                'desc'        => tpi_admin_ui('site_paths.includes.desc'),
                'native_text' => tpi_admin_ui('site_paths.native.core'),
                'native_url'  => tpi_site_root() . '/wp-includes',
                'placeholder' => '/includes',
            ],
            'api' => [
                'label'       => tpi_admin_ui('site_paths.api.label'),
                'desc'        => tpi_admin_ui('site_paths.api.desc'),
                'native_text' => tpi_admin_ui('site_paths.native.rest'),
                'native_url'  => tpi_site_root() . '/wp-json/api',
                'placeholder' => '/api',
            ],
        ];
    }
}

if (!function_exists('tpi_site_paths_current_url')) {
    /** 某个前缀项当前实际输出的完整示例地址 */
    function tpi_site_paths_current_url(string $key): string
    {
        $map = [
            'assets'   => tpi_asset_base(),
            'uploads'  => tpi_uploads_base(),
            'includes' => tpi_includes_base(),
            'api'      => tpi_api_base(),
        ];
        return (string) ($map[$key] ?? '');
    }
}

// ---------------------------------------------------------------- 生效部分

// 核心资源前缀（wp_register_script 等最终都走 includes_url）
add_filter('includes_url', function ($url) {
    if (tpi_includes_prefix() === '') {
        return $url;
    }
    $from = tpi_site_root() . '/wp-includes';
    $to   = tpi_includes_base();
    return str_replace($from, $to, (string) $url);
}, 10, 1);

// REST 地址前缀（前端/主题内部拿 rest_url 时同步）
// 只换本主题的 api 命名空间：/wp-json/api/xxx -> {自定义前缀}/xxx，
// 其它命名空间（wp/v2 等）没有对应的 nginx 规则，保持原样。
add_filter('rest_url', function ($url) {
    $prefix = tpi_site_path_prefix('api');
    if ($prefix === '') {
        return $url;
    }
    $from = tpi_site_root() . '/wp-json/api';
    $to   = tpi_site_root() . $prefix;
    return str_replace($from, $to, (string) $url);
}, 10, 1);

if (!function_exists('tpi_core_asset_mime')) {
    /** 核心静态资源的 Content-Type */
    function tpi_core_asset_mime(string $ext): string
    {
        $map = [
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'application/javascript; charset=UTF-8',
            'mjs'   => 'application/javascript; charset=UTF-8',
            'map'   => 'application/json; charset=UTF-8',
            'json'  => 'application/json; charset=UTF-8',
            'html'  => 'text/html; charset=UTF-8',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            'eot'   => 'application/vnd.ms-fontobject',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}

if (!function_exists('tpi_serve_core_asset')) {
    /**
     * 核心资源前缀的 PHP 兜底
     *
     * 「站点与路径 → 核心资源」选了自定义前缀（如 /includes）时，正常应由
     * nginx 的 `location ^~ /includes/ { rewrite ^/includes/(.*)$ /wp-includes/$1 last; }` 直接发文件。
     * 但新项目/Nginx 还没重载时，这条请求会落到 WordPress 变成 404。
     *
     * 这里在 init 提前截住 /{前缀}/xxx.css|js|... ，直接从 wp-includes 读出来发出去，
     * 保证「配了就能用」。nginx 规则生效后请求根本进不到 PHP，这段等于零成本。
     * 只放行静态后缀 + 拦 `..`，不代理任何 PHP/动态内容。
     */
    function tpi_serve_core_asset(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $prefix = tpi_includes_prefix();
        if ($prefix === '') {
            return; // 原生模式，nginx 直接发 /wp-includes
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($path === '' || strpos($path, $prefix . '/') !== 0) {
            return;
        }

        $rel = substr($path, strlen($prefix) + 1);
        if ($rel === '' || strpos($rel, '..') !== false) {
            return;
        }
        if (!preg_match('#^[A-Za-z0-9._/-]+\.(css|js|mjs|map|json|svg|png|gif|webp|jpe?g|ico|woff2?|ttf|otf|eot)$#i', $rel)) {
            return;
        }

        $base = ABSPATH . (defined('WPINC') ? WPINC : 'wp-includes');
        $file = $base . '/' . ltrim($rel, '/');
        if (!is_file($file)) {
            return; // 真没有这个文件，交回 WordPress 走正常 404
        }

        $ext  = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $mime = tpi_core_asset_mime($ext);
        $mtime = (int) filemtime($file);

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            header('Cache-Control: public, max-age=2592000'); // 30 天，与 nginx 一致
            header('X-TPI-Core-Asset: php-fallback');

            $ims = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
            if ($ims !== '' && strtotime($ims) >= $mtime) {
                http_response_code(304);
                exit;
            }
            header('Content-Length: ' . (string) filesize($file));
        }

        readfile($file);
        exit;
    }
}
add_action('init', 'tpi_serve_core_asset', 20);
