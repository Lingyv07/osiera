<?php
/**
 * 媒体路径层（Osiera）
 *
 * 一条规则：**数据库只存「上传目录相对路径」，页面输出时补全成绝对地址。**
 *
 *   入库：http://example.com/wp-content/uploads/2026/10/a.jpg
 *         → 2026/10/a.jpg
 *
 *   出库：2026/10/a.jpg
 *         → http://example.com/wp-content/uploads/2026/10/a.jpg
 *
 * 存的形态和 WordPress 核心 `_wp_attached_file` 完全一致（2026/10/a.jpg，
 * 相对 wp-content/uploads、不带前导斜杠），整库看着统一，也不依赖上传目录叫
 * 什么名字 —— 生产把上传目录换成 /uploads/assets、或者挂到 CDN 上，
 * 这里只要改 tpi_uploads_base()，数据一个字都不用动。
 *
 * 好处：换域名、换 http/https、换上传目录前缀都不用改数据；
 * 数据库里不会残留任何域名，也不会残留 /wp-content/uploads 这个固定前缀。
 *
 * 对外函数：
 *
 *   tpi_media_store($v)         任意形式 → 入库形式（上传目录相对），外站地址原样保留
 *   tpi_media_full($v)          任意形式 → 展示形式（绝对地址）
 *   tpi_media_store_deep($v)    同上，递归数组 / JSON 字符串（图集、参数这类字段用）
 *   tpi_media_full_deep($v)     同上
 *   tpi_media_html_store($html) 正文里的本站绝对地址 → 上传目录相对
 *   tpi_media_html_full($html)  正文里的上传目录相对 → 绝对
 *   tpi_media_migrate()         把库里已有的绝对地址一次性改成相对（幂等）
 *   tpi_media_migrate_uploads_rel() 把老的 /wp-content/uploads/… 改成 2026/10/a.jpg
 *
 * 只处理「本站地址」：外站图床、CDN、data: URI、纯文件名一律不动。
 * 主题自身的静态资源（/assets/…）不归上传目录管，保持根相对。
 *
 * @package Osiera
 */

if (!function_exists('tpi_media_hosts')) {
    /**
     * 本站可能出现过的主机名
     *
     * 当前 home / siteurl 的 host（含 www 互认）+ filter 追加的历史域名。
     * 只用于「判断这个地址是不是本站的」，判断完统一转成根相对。
     *
     * @return string[]
     */
    function tpi_media_hosts(): array
    {
        static $hosts = null;
        if (is_array($hosts)) {
            return $hosts;
        }

        $hosts = [];
        $add   = static function (string $url) use (&$hosts): void {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                return;
            }
            $hosts[$host] = true;
            $bare = (string) preg_replace('/^www\./', '', $host);
            if ($bare !== '') {
                $hosts[$bare] = true;
            }
        };

        $add(home_url('/'));
        $add(site_url('/'));
        if (defined('WP_HOME')) {
            $add((string) WP_HOME);
        }
        if (defined('WP_SITEURL')) {
            $add((string) WP_SITEURL);
        }

        /**
         * 追加历史域名（换过域名的老站用）
         * @param string[] $list
         */
        foreach ((array) apply_filters('tpi_media_hosts', []) as $extra) {
            $extra = strtolower(trim((string) $extra));
            if ($extra !== '') {
                $hosts[$extra] = true;
            }
        }

        $hosts = array_keys($hosts);
        return $hosts;
    }
}

if (!function_exists('tpi_media_is_local_url')) {
    /** 是不是本站地址（根相对、纯文件名、本站绝对地址都算） */
    function tpi_media_is_local_url(string $url): bool
    {
        if (!preg_match('#^(https?:)?//#i', $url)) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }
        $hosts = tpi_media_hosts();
        if (in_array($host, $hosts, true)) {
            return true;
        }
        return in_array((string) preg_replace('/^www\./', '', $host), $hosts, true);
    }
}

if (!function_exists('tpi_media_upload_dirs')) {
    /**
     * 上传目录可能用过的所有根前缀（长的排前面）
     *
     * 原生 /wp-content/uploads；站点配置里的自定义前缀（生产是 /uploads/assets）；
     * 历史遗留的 /uploads/assets。入库时统一归一成 /wp-content/uploads。
     *
     * @return string[]
     */
    function tpi_media_upload_dirs(): array
    {
        static $dirs = null;
        if (is_array($dirs)) {
            return $dirs;
        }

        $dirs = ['/wp-content/uploads'];
        if (function_exists('tpi_site_path_prefix')) {
            $prefix = trim((string) tpi_site_path_prefix('uploads'), '/');
            if ($prefix !== '') {
                $dirs[] = '/' . $prefix;
            }
        }
        $dirs[] = '/uploads/assets';

        $dirs = array_values(array_unique($dirs));
        usort($dirs, static fn($a, $b) => strlen($b) <=> strlen($a));
        return $dirs;
    }
}

if (!function_exists('tpi_media_is_uploads_rel')) {
    /**
     * 是不是「上传目录相对路径」：2026/10/a.jpg
     *
     * 和核心 _wp_attached_file 同一个形态。只认「年/月」开头的，
     * 纯文件名（Logistics.png）不算 —— 那个要走媒体库反查。
     */
    function tpi_media_is_uploads_rel(string $value): bool
    {
        return (bool) preg_match('#^\d{4}/\d{2}/[^\s<>"\'\\\\]+$#', $value);
    }
}

if (!function_exists('tpi_media_uploads_url_for')) {
    /**
     * 上传目录相对路径 → 绝对地址
     *
     * 文件不存在时返回空串，宁可不输出也不要给页面留一个 404 地址
     * （tpi_media_check_exists() 挂 filter 关掉检查时直接输出）。
     */
    function tpi_media_uploads_url_for(string $rel): string
    {
        $rel = ltrim(trim($rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) {
            return '';
        }
        if (tpi_media_check_exists() && !is_file(tpi_media_uploads_basedir() . '/' . $rel)) {
            return '';
        }
        return tpi_media_uploads_base() . '/' . $rel;
    }
}

if (!function_exists('tpi_media_path_store')) {
    /**
     * 根相对路径归一：/wp-content/uploads/… 与自定义上传前缀 → 上传目录相对
     *
     *   /wp-content/uploads/2026/10/a.jpg → 2026/10/a.jpg
     *   /uploads/assets/2026/10/a.jpg     → 2026/10/a.jpg
     *   /assets/svg/Address.svg           → 原样（主题资源不归上传目录管）
     */
    function tpi_media_path_store(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            return $path;
        }
        $path = '/' . ltrim($path, '/');
        foreach (tpi_media_upload_dirs() as $dir) {
            if ($path === $dir) {
                return '';
            }
            if (strpos($path, $dir . '/') === 0) {
                return ltrim(substr($path, strlen($dir)), '/');
            }
        }
        return $path;
    }
}

if (!function_exists('tpi_media_fix_bogus_scheme')) {
    /**
     * 还原被 esc_url() 加了假协议的相对路径
     *
     * WordPress 的 esc_url('2026/01/a.jpg') 会把年份当域名，补成
     * http://2026/01/a.jpg，这种地址入库后前台就取不到图。
     * 这里把「协议 + 纯数字年份」的伪地址还原成上传目录相对路径。
     */
    function tpi_media_fix_bogus_scheme(string $value): string
    {
        if (preg_match('#^https?://(\d{4}/\d{2}/.+)$#i', $value, $m)) {
            return $m[1];
        }
        return $value;
    }
}

if (!function_exists('tpi_media_store')) {
    /**
     * 任意形式的媒体地址 → 入库形式
     *
     * 本站绝对地址 / 自定义前缀根相对 → /wp-content/uploads/…
     * 外站地址、data:、纯文件名 → 原样返回（不做任何猜测）
     */
    function tpi_media_store(string $value): string
    {
        $value = tpi_media_fix_bogus_scheme(trim($value));
        if ($value === '' || strpos($value, 'data:') === 0) {
            return $value;
        }

        // 本站绝对地址：只留 path + query
        if (preg_match('#^(https?:)?//#i', $value)) {
            if (!tpi_media_is_local_url($value)) {
                return $value;
            }
            $parts = parse_url($value);
            $path  = (string) ($parts['path'] ?? '');
            if ($path === '') {
                return $value;
            }
            $out = tpi_media_path_store($path);
            if (!empty($parts['query'])) {
                $out .= '?' . $parts['query'];
            }
            // 锚点必须保留：正文里 /certificates#fai-report 这类链接被抹掉 # 后就跳到页首了
            if (!empty($parts['fragment'])) {
                $out .= '#' . $parts['fragment'];
            }
            return $out;
        }

        // 根相对
        if ($value[0] === '/') {
            return tpi_media_path_store($value);
        }

        // 已经是上传目录相对（2026/10/a.jpg）：原样存
        if (tpi_media_is_uploads_rel($value)) {
            return $value;
        }

        // 纯文件名 / 其它：交给媒体库反查，存储层不猜
        return $value;
    }
}

if (!function_exists('tpi_media_uploads_basedir')) {
    /** 上传目录的物理根（follows 站点自定义 basedir） */
    function tpi_media_uploads_basedir(): string
    {
        static $dir = null;
        if (is_string($dir)) {
            return $dir;
        }
        $uploads = wp_get_upload_dir();
        $dir     = rtrim((string) ($uploads['basedir'] ?? (ABSPATH . 'wp-content/uploads')), '/\\');
        return $dir;
    }
}

if (!function_exists('tpi_media_path_full')) {
    /**
     * 路径 → 绝对地址
     *
     * 2026/10/a.jpg          上传目录相对（核心同款形态）→ 上传目录根 + 路径
     * /wp-content/uploads/…  老数据，先归一再拼上传目录（生产是 /uploads/assets）
     * /assets/…              主题静态资源，走 tpi_asset()
     * /wp-includes/…         核心资源前缀
     * 其它                   当前站点根 + 路径
     */
    function tpi_media_path_full(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // 上传目录相对：直接补上传目录根
        if ($path[0] !== '/') {
            return tpi_media_is_uploads_rel($path) ? tpi_media_uploads_url_for($path) : $path;
        }

        $path = tpi_media_path_store($path);
        if ($path === '') {
            return '';
        }

        // 归一后成了上传目录相对（老数据 /wp-content/uploads/… 与自定义前缀）
        if ($path[0] !== '/') {
            return tpi_media_uploads_url_for($path);
        }

        // 主题资源：历史数据里写的是 /assets/…
        if (strpos($path, '/assets/') === 0) {
            $rel = substr($path, strlen('/assets'));
            if (is_file(THEME_DIR . 'assets' . $rel)) {
                return tpi_asset($rel);
            }
            return '';
        }

        // 核心资源
        if (strpos($path, '/wp-includes/') === 0 || $path === '/wp-includes') {
            return tpi_includes_base() . substr($path, strlen('/wp-includes'));
        }

        // 主题目录里的其它文件（css / js / 字体）
        if (strpos($path, '/wp-content/themes/') === 0) {
            return home_url($path);
        }

        // 站内其它根相对地址（页面链接等）
        return home_url($path);
    }
}

if (!function_exists('tpi_media_uploads_base')) {
    /**
     * 上传目录根地址（绝对）
     *
     * content_url() 会按「当前请求协议」改写地址，代理终结 https 时 PHP 看到的
     * 还是 http，输出就会变成 http 图片（混合内容告警）。这里统一跟站点地址
     * （siteurl）的协议走：后台里把站点填成 https，前台图就一定是 https。
     */
    function tpi_media_uploads_base(): string
    {
        $base   = tpi_uploads_base();
        $scheme = (string) wp_parse_url(site_url(), PHP_URL_SCHEME);
        if ($scheme !== '' && $scheme !== (string) wp_parse_url($base, PHP_URL_SCHEME)) {
            $base = set_url_scheme($base, $scheme);
        }
        return $base;
    }
}

if (!function_exists('tpi_media_check_exists')) {
    /**
     * 输出上传目录地址前是否检查文件存在
     *
     * 默认检查：路径写错时直接不输出，避免前台出现 404 图。
     * 上传目录被 CDN 或独立存储接管时，挂 filter 返回 false 关掉检查。
     */
    function tpi_media_check_exists(): bool
    {
        return (bool) apply_filters('tpi_media_check_exists', true);
    }
}

if (!function_exists('tpi_media_looks_like_path')) {
    /**
     * 这个字符串像不像「媒体地址」
     *
     * 结构化字段里混着很多普通文本（alt 文案、参数值、电话、type=image 这种枚举），
     * 不先判断就一律当文件名去媒体库反查，会把它们改成空白或误配成别的图。
     * 只认三种形态：绝对地址 / 根相对路径 / 带扩展名的纯文件名。
     */
    function tpi_media_looks_like_path(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (preg_match('#^(https?:)?//#i', $value)) {
            return true;
        }
        // 根相对：不能有空格、换行
        if ($value[0] === '/') {
            return !preg_match('/[\s<>"\']/', $value);
        }
        // 纯文件名：必须带扩展名，且不含空白（否则是句子）
        if (preg_match('/[\s<>"\']/', $value)) {
            return false;
        }
        return (bool) preg_match('/\.[A-Za-z0-9]{1,6}$/', $value);
    }
}

if (!function_exists('tpi_media_full')) {
    /**
     * 任意形式的媒体地址 → 展示形式（绝对地址）
     *
     * 外站地址、data: 原样返回；纯文件名走媒体库反查；
     * 不像地址的普通文本（alt、参数值、电话…）一个字都不动。
     */
    function tpi_media_full(string $value): string
    {
        $value = tpi_media_fix_bogus_scheme(trim($value));
        if ($value === '' || strpos($value, 'data:') === 0) {
            return $value;
        }

        if (preg_match('#^(https?:)?//#i', $value)) {
            if (!tpi_media_is_local_url($value)) {
                return $value;
            }
            // 本站地址统一刷成当前域名（旧域名、http/https 混用都能纠正）
            $parts = parse_url($value);
            $path  = (string) ($parts['path'] ?? '/');
            $out   = tpi_media_path_full($path);
            if (!empty($parts['query'])) {
                $out .= '?' . $parts['query'];
            }
            if (!empty($parts['fragment'])) {
                $out .= '#' . $parts['fragment'];
            }
            return $out;
        }

        if ($value[0] === '/') {
            return tpi_media_path_full($value);
        }

        // 上传目录相对（2026/10/a.jpg）：补上传目录根
        if (tpi_media_is_uploads_rel($value)) {
            return tpi_media_path_full($value);
        }

        // 纯文件名：媒体库按文件名反查；不像文件名的文本原样返回
        if (tpi_media_looks_like_path($value) && function_exists('tpi_media_url')) {
            $url = tpi_media_url($value);
            return $url !== '' ? $url : $value;
        }
        return $value;
    }
}

if (!function_exists('tpi_media_is_json')) {
    /** 字符串是不是 JSON 对象 / 数组 */
    function tpi_media_is_json(string $value): bool
    {
        $value = ltrim($value);
        if ($value === '' || ($value[0] !== '{' && $value[0] !== '[')) {
            return false;
        }
        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

if (!function_exists('tpi_media_store_deep')) {
    /**
     * 递归归一（入库用）
     *
     * 数组逐层处理；JSON 字符串解析后处理后重新编码；
     * 含 HTML 标签的字符串按属性处理；普通字符串按单值处理。
     *
     * @param mixed $value
     * @return mixed
     */
    function tpi_media_store_deep($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = tpi_media_store_deep($item);
            }
            return $out;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (tpi_media_is_json($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return wp_json_encode(
                    tpi_media_store_deep($decoded),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }
        if (strpos($value, '<') !== false) {
            return tpi_media_html_store($value);
        }
        return tpi_media_store($value);
    }
}

if (!function_exists('tpi_media_full_deep')) {
    /**
     * 递归补全（展示用）
     *
     * @param mixed $value
     * @return mixed
     */
    function tpi_media_full_deep($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = tpi_media_full_deep($item);
            }
            return $out;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (tpi_media_is_json($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return wp_json_encode(
                    tpi_media_full_deep($decoded),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }
        if (strpos($value, '<') !== false) {
            return tpi_media_html_full($value);
        }
        return tpi_media_full($value);
    }
}

if (!function_exists('tpi_media_html_store')) {
    /**
     * 正文 HTML：属性里的本站绝对地址 → 根相对
     *
     * 只动这些属性：src / href / poster / data-src / data-href / srcset / content。
     * 外站地址与锚点不动。
     */
    function tpi_media_html_store(string $html): string
    {
        return tpi_media_html_map($html, 'tpi_media_store');
    }
}

if (!function_exists('tpi_media_html_full')) {
    /**
     * 正文 HTML：属性里的本站根相对地址 → 绝对
     */
    function tpi_media_html_full(string $html): string
    {
        return tpi_media_html_map($html, 'tpi_media_full');
    }
}

if (!function_exists('tpi_media_html_map')) {
    /**
     * HTML 属性地址批量替换
     *
     * @param string $html
     * @param string $fn tpi_media_store | tpi_media_full
     */
    function tpi_media_html_map(string $html, string $fn): string
    {
        if ($html === '' || !function_exists($fn)) {
            return $html;
        }

        // srcset 单独处理：逗号分隔的「地址 宽度」列表
        $html = (string) preg_replace_callback(
            '#\bsrcset\s*=\s*(["\'])([^"\']*)\1#i',
            static function (array $m) use ($fn): string {
                $items = [];
                foreach (explode(',', $m[2]) as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $bits     = preg_split('/\s+/', $part, 2);
                    $url      = $fn((string) $bits[0]);
                    $items[]  = trim($url . (isset($bits[1]) ? ' ' . $bits[1] : ''));
                }
                return 'srcset=' . $m[1] . implode(', ', $items) . $m[1];
            },
            $html
        );

        return (string) preg_replace_callback(
            '#\b(src|href|poster|data-src|data-href|data-lazy|content)\s*=\s*(["\'])([^"\']*)\2#i',
            static function (array $m) use ($fn): string {
                $url = $m[3];
                if ($url === '' || $url[0] === '#' || strpos($url, 'data:') === 0) {
                    return $m[0];
                }
                $new = $fn($url);
                if ($new === $url) {
                    return $m[0];
                }
                return $m[1] . '=' . $m[2] . $new . $m[2];
            },
            $html
        );
    }
}

if (!function_exists('tpi_media_has_local_url')) {
    /** 字符串里是否含本站绝对地址（用于迁移时先筛行，避免全表扫描后逐行处理） */
    function tpi_media_has_local_url(string $value): bool
    {
        if ($value === '' || stripos($value, '//') === false) {
            return false;
        }
        foreach (tpi_media_hosts() as $host) {
            if ($host !== '' && stripos($value, '//' . $host) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tpi_media_migrate')) {
    /**
     * 把库里已经存成绝对地址的图片改成根相对（幂等，跑完写 option 标记）
     *
     * 覆盖：文章正文 / 主题结构化字段（图集、参数、主图）/ 语言配置表 / 语言表国旗。
     *
     * @param bool $force 忽略已迁移标记强制再跑一次
     * @return array<string,int> 各类改动条数
     */
    function tpi_media_migrate(bool $force = false): array
    {
        global $wpdb;

        $report = [
            'posts'     => 0,
            'postmeta'  => 0,
            'settings'  => 0,
            'langs'     => 0,
            'options'   => 0,
            'templates' => 0,
        ];

        if (!$force && get_option('osiera_media_rel_migrated') === '1') {
            return $report;
        }

        $hosts = tpi_media_hosts();
        if (!$hosts) {
            return $report;
        }

        // 拼 LIKE 条件：只筛含本站域名的行，避免逐行处理整表
        $likes = [];
        foreach ($hosts as $host) {
            $likes[] = $wpdb->prepare('post_content LIKE %s', '%//' . $wpdb->esc_like($host) . '%');
        }

        // ---- 1. 文章正文 ----
        $sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE " . implode(' OR ', $likes);
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $new = tpi_media_html_store((string) $row->post_content);
            if ($new !== (string) $row->post_content) {
                $wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => (int) $row->ID]);
                $report['posts']++;
                clean_post_cache((int) $row->ID);
            }
        }

        // ---- 2. 主题结构化字段（图集 / 参数 / 主图兜底）----
        $meta_prefix = defined('TPI_META_PREFIX') ? TPI_META_PREFIX : '_osiera_';
        $meta_likes  = [];
        foreach ($hosts as $host) {
            $meta_likes[] = $wpdb->prepare('meta_value LIKE %s', '%//' . $wpdb->esc_like($host) . '%');
        }
        $sql = $wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND ("
            . implode(' OR ', $meta_likes) . ')',
            $wpdb->esc_like($meta_prefix) . '%'
        );
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $old = (string) $row->meta_value;
            $new = tpi_media_store_deep($old);
            if (!is_string($new)) {
                $new = wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($new !== $old) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => (int) $row->meta_id]);
                $report['postmeta']++;
            }
        }

        // ---- 3. 语言配置表（页面信息设置 / 站点配置）----
        $data_table = $wpdb->prefix . 'osiera_language_data';
        if (tpi_table_exists($data_table, true)) {
            $data_likes = [];
            foreach ($hosts as $host) {
                $data_likes[] = $wpdb->prepare('value LIKE %s', '%//' . $wpdb->esc_like($host) . '%');
            }
            $sql = "SELECT id, value FROM `$data_table` WHERE " . implode(' OR ', $data_likes);
            foreach ((array) $wpdb->get_results($sql) as $row) {
                $old = (string) $row->value;
                $new = tpi_media_store_deep($old);
                if (!is_string($new)) {
                    $new = wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($new !== $old) {
                    $wpdb->update($data_table, ['value' => $new], ['id' => (int) $row->id]);
                    $report['settings']++;
                }
            }
        }

        // ---- 4. 语言表国旗 ----
        $lang_table = $wpdb->prefix . 'osiera_languages';
        if (tpi_table_exists($lang_table, true)) {
            $lang_likes = [];
            foreach ($hosts as $host) {
                $lang_likes[] = $wpdb->prepare('flag_url LIKE %s', '%//' . $wpdb->esc_like($host) . '%');
            }
            $sql = "SELECT id, flag_url FROM `$lang_table` WHERE " . implode(' OR ', $lang_likes);
            foreach ((array) $wpdb->get_results($sql) as $row) {
                $old = (string) $row->flag_url;
                $new = tpi_media_store($old);
                if ($new !== $old) {
                    $wpdb->update($lang_table, ['flag_url' => $new], ['id' => (int) $row->id]);
                    $report['langs']++;
                }
            }
        }

        // ---- 5. 老 option（my_page_info_settings / my_smtp_settings…）----
        $option_likes = [];
        foreach ($hosts as $host) {
            $option_likes[] = $wpdb->prepare('option_value LIKE %s', '%//' . $wpdb->esc_like($host) . '%');
        }
        $sql = "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE "
            . implode(' OR ', $option_likes);
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $name = (string) $row->option_name;
            // 站点地址 / 转存数据这类不能动
            if (in_array($name, ['siteurl', 'home', 'recently_edited', 'cron'], true)) {
                continue;
            }
            if (strpos($name, '_transient_') === 0 || strpos($name, '_site_transient_') === 0) {
                continue;
            }
            $old = (string) $row->option_value;
            $new = tpi_media_store_deep($old);
            if (!is_string($new)) {
                $new = wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($new !== $old) {
                $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => (int) $row->option_id]);
                $report['options']++;
            }
        }

        // ---- 6. 产品模板表（可复用的素材库，图集 / 正文同样不能存绝对地址）----
        $tpl_table = $wpdb->prefix . 'product_template';
        if (function_exists('tpi_table_exists') && tpi_table_exists($tpl_table, true)) {
            $tpl_cols = ['images', 'parameters', 'content', 'description', 'main_title', 'summary', 'warning'];
            foreach ((array) $wpdb->get_results("SELECT * FROM `$tpl_table`", ARRAY_A) as $row) {
                $update = [];
                foreach ($tpl_cols as $col) {
                    if (!array_key_exists($col, $row) || (string) $row[$col] === '') {
                        continue;
                    }
                    $old = (string) $row[$col];
                    if ($col === 'images') {
                        // 图集是 JSON：地址转相对，顺便把被 esc_url() 污染的 type 还原
                        $list = json_decode($old, true);
                        if (!is_array($list)) {
                            continue;
                        }
                        foreach ($list as $i => $img) {
                            if (!is_array($img)) {
                                continue;
                            }
                            if (array_key_exists('type', $img)) {
                                $type = preg_replace('#^https?://#i', '', (string) $img['type']);
                                $list[$i]['type'] = $type === 'video' ? 'video' : 'image';
                            }
                            foreach (['img_src', 'video_src'] as $key) {
                                if (!empty($img[$key]) && is_string($img[$key])) {
                                    $list[$i][$key] = tpi_media_store($img[$key]);
                                }
                            }
                        }
                        $new = wp_json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        // JSON 里存的是 http:\/\/…，直接用 SQL LIKE 查不到，这里逐行解析
                        $new = tpi_media_store_deep($old);
                        if (!is_string($new)) {
                            $new = wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    }
                    if ($new !== $old) {
                        $update[$col] = $new;
                    }
                }
                if ($update) {
                    $wpdb->update($tpl_table, $update, ['id' => (int) $row['id']]);
                    $report['templates']++;
                }
            }
        }

        // 缓存全部失效：语言内容、设置、语言行
        if (function_exists('tpi_lang_content_flush')) {
            tpi_lang_content_flush();
        }
        if (function_exists('tpi_flush_languages_cache')) {
            tpi_flush_languages_cache();
        }
        $GLOBALS['osiera_setting_cache'] = [];
        wp_cache_flush();

        update_option('osiera_media_rel_migrated', '1', false);
        return $report;
    }
}

if (!function_exists('tpi_media_migrate_uploads_rel')) {
    /**
     * 把库里老的 /wp-content/uploads/2026/10/a.jpg 改成 2026/10/a.jpg
     *
     * 和核心 _wp_attached_file 对齐：只留「年/月/文件名」，上传目录那一层不再写进数据。
     * 幂等，跑完写 option 标记；换站点、换上传目录都不用再动数据。
     *
     * @param bool $force 忽略已迁移标记强制再跑一次
     * @return array<string,int> 各类改动条数
     */
    function tpi_media_migrate_uploads_rel(bool $force = false): array
    {
        global $wpdb;

        $report = [
            'posts'    => 0,
            'postmeta' => 0,
            'settings' => 0,
            'langs'    => 0,
            'options'  => 0,
        ];

        if (!$force && get_option('osiera_media_upload_rel_migrated') === '1') {
            return $report;
        }

        // 需要被收掉的根前缀（原生 + 自定义 + 历史遗留）
        $dirs = [];
        foreach (tpi_media_upload_dirs() as $dir) {
            $dirs[] = $dir . '/';
        }
        if (!$dirs) {
            return $report;
        }

        $like = static function (string $column) use ($wpdb, $dirs): string {
            $bits = [];
            foreach ($dirs as $dir) {
                $bits[] = $wpdb->prepare($column . ' LIKE %s', '%' . $wpdb->esc_like($dir) . '%');
            }
            return '(' . implode(' OR ', $bits) . ')';
        };

        $normalize = static function ($value) {
            $new = tpi_media_store_deep($value);
            return is_string($new) ? $new : wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        // ---- 1. 文章正文 ----
        $sql = "SELECT ID, post_content FROM {$wpdb->posts} WHERE " . $like('post_content');
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $old = (string) $row->post_content;
            $new = tpi_media_html_store($old);
            if ($new !== $old) {
                $wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => (int) $row->ID]);
                clean_post_cache((int) $row->ID);
                $report['posts']++;
            }
        }

        // ---- 2. 主题结构化字段（图集 / 参数 / 主图）----
        // 只跳过核心自己的附件字段：_wp_attached_file 本来就是 2026/10/a.jpg，不能动
        $sql = "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE '\_wp\_%' AND " . $like('meta_value');
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $old = (string) $row->meta_value;
            $new = $normalize($old);
            if ($new !== $old) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $new], ['meta_id' => (int) $row->meta_id]);
                $report['postmeta']++;
            }
        }

        // ---- 3. 语言配置表（页面信息设置 / 站点配置）----
        $data_table = $wpdb->prefix . 'osiera_language_data';
        if (tpi_table_exists($data_table, true)) {
            $sql = "SELECT id, value FROM `$data_table` WHERE " . $like('value');
            foreach ((array) $wpdb->get_results($sql) as $row) {
                $old = (string) $row->value;
                $new = $normalize($old);
                if ($new !== $old) {
                    $wpdb->update($data_table, ['value' => $new], ['id' => (int) $row->id]);
                    $report['settings']++;
                }
            }
        }

        // ---- 4. 语言表国旗 ----
        $lang_table = $wpdb->prefix . 'osiera_languages';
        if (tpi_table_exists($lang_table, true)) {
            $sql = "SELECT id, flag_url FROM `$lang_table` WHERE " . $like('flag_url');
            foreach ((array) $wpdb->get_results($sql) as $row) {
                $old = (string) $row->flag_url;
                $new = tpi_media_store($old);
                if ($new !== $old) {
                    $wpdb->update($lang_table, ['flag_url' => $new], ['id' => (int) $row->id]);
                    $report['langs']++;
                }
            }
        }

        // ---- 5. 老 option（my_page_info_settings / my_smtp_settings…）----
        // 站点地址不能动；tpi_site_paths 存的是「前缀配置本身」（如 /uploads/assets），
        // 拿去做归一会被当成上传目录前缀而清空，必须跳过。
        $skip = ['siteurl', 'home', 'recently_edited', 'cron', 'tpi_site_paths', 'upload_path', 'upload_url_path'];
        $sql  = "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE " . $like('option_value');
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $name = (string) $row->option_name;
            if (in_array($name, $skip, true)) {
                continue;
            }
            if (strpos($name, '_transient_') === 0 || strpos($name, '_site_transient_') === 0) {
                continue;
            }
            $old = (string) $row->option_value;
            $new = $normalize($old);
            if ($new !== $old) {
                $wpdb->update($wpdb->options, ['option_value' => $new], ['option_id' => (int) $row->option_id]);
                $report['options']++;
            }
        }

        if (function_exists('tpi_lang_content_flush')) {
            tpi_lang_content_flush();
        }
        if (function_exists('tpi_flush_languages_cache')) {
            tpi_flush_languages_cache();
        }
        $GLOBALS['osiera_setting_cache'] = [];
        wp_cache_flush();

        update_option('osiera_media_upload_rel_migrated', '1', false);
        return $report;
    }
}

/**
 * 前台正文输出：库里存的 2026/10/a.jpg 补成完整地址
 *
 * 正文入库统一存上传目录相对路径，直接丢给浏览器会按当前页地址解析成
 * /de/product/2026/10/a.jpg，所以输出前必须补全。优先级 9 在 wpautop 之前，
 * 只改属性值，不碰段落结构。
 */
add_filter('the_content', 'tpi_media_html_full', 9);

/**
 * 写入侧统一归一：任何内容进库前把本站绝对地址改成根相对
 *
 * 走 wp_insert_post_data 而不是 content_save_pre：
 * 它在核心完成 slashes 处理之后，拿到的就是最终要落库的字符串。
 */
add_filter('wp_insert_post_data', static function ($data, $postarr) {
    if (!is_array($data)) {
        return $data;
    }
    if (isset($data['post_content']) && is_string($data['post_content']) && $data['post_content'] !== '') {
        // 这个钩子里 $data 是 slashed 的（核心在过滤之后才 wp_unslash），
        // 属性引号是 \"，不先还原正则根本匹配不到。
        $data['post_content'] = wp_slash(tpi_media_html_store(wp_unslash($data['post_content'])));
    }
    return $data;
}, 20, 2);

/**
 * 后台读一次，顺便把历史数据里的绝对地址收干净
 *
 * 老站升级上来时不用手动跑脚本；已经迁过的站点这行直接返回。
 */
add_action('admin_init', static function (): void {
    if (get_option('osiera_media_rel_migrated') === '1') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    tpi_media_migrate();
}, 5);

/**
 * 再收一层：/wp-content/uploads/2026/10/a.jpg → 2026/10/a.jpg
 *
 * 和核心 _wp_attached_file 对齐，上传目录那一段不再写进数据。
 */
add_action('admin_init', static function (): void {
    if (get_option('osiera_media_upload_rel_migrated') === '1') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    tpi_media_migrate_uploads_rel();
}, 6);

/* =========================================================
 * 区块编辑器（Gutenberg）的正文媒体地址
 *
 * 库里统一存相对地址（2026/08/a.jpg）。区块编辑器通过 REST 读 content.raw，
 * 拿到相对地址后浏览器按 /wp-admin/ 解析，图片、附件全部裂开；
 * 换回保存时又会把相对地址原样写回。所以两头各挂一个过滤器：
 *   读（rest_prepare_*）→ 补成绝对地址，编辑器里正常显示；
 *   写（wp_insert_post_data）→ 转回相对，库里保持干净。
 * ========================================================= */

if (!function_exists('tpi_rest_fill_media_fields')) {
    /**
     * REST 输出：content.raw / excerpt.raw 里的相对媒体地址补成绝对地址
     *
     * @param mixed $response WP_REST_Response
     * @return mixed
     */
    function tpi_rest_fill_media_fields($response)
    {
        if (!is_object($response) || !method_exists($response, 'get_data')) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $changed = false;
        foreach (['content', 'excerpt'] as $field) {
            if (isset($data[$field]['raw']) && is_string($data[$field]['raw'])) {
                $full = tpi_media_html_full($data[$field]['raw']);
                if ($full !== $data[$field]['raw']) {
                    $data[$field]['raw'] = $full;
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $response->set_data($data);
        }
        return $response;
    }
}

add_action('rest_api_init', static function (): void {
    // 只挂到区块编辑器会读的类型上，其它 REST 端点不掺和
    foreach (get_post_types(['show_in_rest' => true], 'names') as $type) {
        add_filter('rest_prepare_' . $type, 'tpi_rest_fill_media_fields', 10, 1);
    }
});

if (!function_exists('tpi_store_post_media_fields')) {
    /**
     * 入库：正文 / 摘要里的本站地址转回相对，避免编辑器保存一次就写死域名
     *
     * @param array $data
     * @return array
     */
    function tpi_store_post_media_fields($data)
    {
        foreach (['post_content', 'post_excerpt'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $data[$key] = tpi_media_html_store($data[$key]);
            }
        }
        return $data;
    }
}

add_filter('wp_insert_post_data', 'tpi_store_post_media_fields', 10, 1);
