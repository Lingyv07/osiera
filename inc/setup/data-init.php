<?php
/**
 * 主题初始化数据（data-init）
 *
 * 新项目启用主题时，把 data-init/data/*.json 里这套演示数据一次建好：
 * 语言、页面、分类、菜单、语言内容、页面信息、产品 / 应用 / FAQ / 文章。
 *
 * 目录约定（资源与数据分开，换项目只改目录内容，不改代码）：
 *
 *   init-config.json   数据来源开关，默认 { "data_init_url": "data-init" }
 *                      可写主题相对路径、站根路径、Windows 绝对路径，或
 *                      http(s) 线上目录；远端模式按 URL 拉 json 与资源。
 *                      后台「初始数据」页保存的地址存在 option tpi_data_init_url，
 *                      优先级高于这个文件；这里只是出厂默认值（旧名 api-init.json 仍兼容）。
 *   data-init/
 *     assets/images/   演示位图（.jpg/.png/.webp），导入时进媒体库
 *     assets/svg/      内容型图标与语言旗（导航、搜索等界面图标仍在主题 assets/svg）
 *     data/*.json      语言 / 分类 / 菜单 / 语言内容 / 页面信息 / 四类内容
 *
 * JSON 里的图片、图标一律写相对 data-init 的路径（`assets/images/x.jpg`），
 * 导入时替换成媒体库地址；数据库里存的仍是「上传目录相对路径」，换域名不用改数据。
 *
 * 四条约束（与旧 starter-content 一致）：
 *   1. 只跑一次 —— option tpi_starter_content 记版本号；
 *   2. 不覆盖已有内容 —— 同 slug + 同语言已存在就跳过，站里已有的数据不动；
 *   3. 幂等 —— 分类 / 菜单项 / 附件按 slug、文件名复用，重复执行不会翻倍；
 *   4. 媒体按文件名复用 —— 已经有同名附件就不再复制一份。
 *
 * @package Osiera
 */

if (!defined('TPI_DATA_INIT_DIR')) {
    define('TPI_DATA_INIT_DIR', 'data-init');
}

if (!function_exists('tpi_data_init_config')) {
    /**
     * 主题根目录的出厂配置（init-config.json，旧名 api-init.json 仍兼容）
     *
     * 只读一次：{ "data_init_url": "data-init" }
     *
     * @return array{data_init_url:string}
     */
    function tpi_data_init_config(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }
        $config = ['data_init_url' => ''];
        $theme  = trailingslashit(get_template_directory());
        foreach (['init-config.json', 'api-init.json'] as $name) {
            $file = $theme . $name;
            if (!is_file($file)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($file), true);
            if (!is_array($json)) {
                continue;
            }
            $raw = trim((string) ($json['data_init_url'] ?? $json['data_init'] ?? ''));
            if ($raw !== '') {
                $config['data_init_url'] = $raw;
                break;
            }
        }
        return $config;
    }
}

if (!function_exists('tpi_data_init_url_setting')) {
    /**
     * 当前生效的数据地址
     *
     * 顺序：后台保存的值（option tpi_data_init_url）→ init-config.json → 空串（回落 data-init）。
     * 后台保存空串等于「恢复出厂地址」，不是「没有地址」。
     */
    function tpi_data_init_url_setting(): string
    {
        $saved = get_option('tpi_data_init_url', null);
        if (is_string($saved) && trim($saved) !== '') {
            return trim($saved);
        }
        return (string) (tpi_data_init_config()['data_init_url'] ?? '');
    }
}

if (!function_exists('tpi_data_init_flush')) {
    /** 清掉 base/json 的静态缓存（后台保存地址后立刻导入时用） */
    function tpi_data_init_flush(): void
    {
        // 静态变量没法从外部重置，用一个全局代次让下次调用重新计算
        $GLOBALS['tpi_data_init_rev'] = (int) ($GLOBALS['tpi_data_init_rev'] ?? 0) + 1;
    }
}

if (!function_exists('tpi_data_init_base')) {
    /**
     * data-init 的来源
     *
     * 后台「初始数据」页保存的地址（option）优先，其次是主题根的
     * init-config.json（旧名 api-init.json 兼容）：
     *
     *   { "data_init_url": "data-init" }
     *   { "data_init_url": "/wp-content/themes/osiera/data-init/" }
     *   { "data_init_url": "https://cdn.example.com/osiera/data-init/" }
     *
     * 相对路径按主题目录解析，绝对路径直接认，http(s) 走远端拉取。
     * 地址为空时回退到 <主题>/data-init。
     *
     * @return array{mode:string,path:string,url:string}
     */
    function tpi_data_init_base(): array
    {
        static $base = null;
        static $rev  = null;
        $now_rev = (int) ($GLOBALS['tpi_data_init_rev'] ?? 0);
        if (is_array($base) && $rev === $now_rev) {
            return $base;
        }
        $rev = $now_rev;

        $theme     = trailingslashit(get_template_directory());
        $base      = ['mode' => 'local', 'path' => $theme . TPI_DATA_INIT_DIR . '/', 'url' => ''];

        $raw = tpi_data_init_url_setting();
        if ($raw === '') {
            return $base;
        }

        if (preg_match('#^https?://#i', $raw)) {
            $base = ['mode' => 'remote', 'path' => '', 'url' => untrailingslashit($raw) . '/'];
            return $base;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $raw)) {
            // Windows 绝对路径
            $path = $raw;
        } elseif (str_starts_with($raw, '/')) {
            // 站根相对路径，如 /wp-content/themes/osiera/data-init/
            $path = ABSPATH . ltrim($raw, '/');
        } else {
            // 主题目录相对路径（默认）
            $path = $theme . $raw;
        }
        $base = ['mode' => 'local', 'path' => untrailingslashit($path) . '/', 'url' => ''];

        return $base;
    }
}

if (!function_exists('tpi_data_init_path')) {
    /** data-init 下的相对路径 → 绝对路径（远端模式返回空串） */
    function tpi_data_init_path(string $rel = ''): string
    {
        $base = tpi_data_init_base();
        if ($base['mode'] !== 'local' || $base['path'] === '') {
            return '';
        }
        return $rel === '' ? $base['path'] : $base['path'] . ltrim(str_replace('\\', '/', $rel), '/');
    }
}

if (!function_exists('tpi_data_init_fetch')) {
    /**
     * 取一个 data-init 文件到本地路径（远端模式下载到临时文件）
     *
     * @param string      $rel   data-init 相对路径，如 assets/images/a.jpg
     * @param string|null $tmp   出参：远端模式下的临时文件，用完需删
     * @return string 本地绝对路径；取不到返回空串
     */
    function tpi_data_init_fetch(string $rel, ?string &$tmp = null): string
    {
        $tmp  = null;
        $base = tpi_data_init_base();
        if ($base['mode'] === 'local') {
            $path = tpi_data_init_path($rel);
            return ($path !== '' && is_file($path)) ? $path : '';
        }

        $url = esc_url_raw($base['url'] . ltrim(str_replace('\\', '/', $rel), '/'));
        if ($url === '') {
            return '';
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = wp_tempnam(basename($rel));
        if ($file === '') {
            return '';
        }
        // 下载走 tpi_http_download()：内网目标会自动绕开 php-cgi 继承的系统代理，
        // 否则「拉同机另一个站的 data-init」会被代理回一句空 502（HTTP API 压不住环境变量代理）
        if (!function_exists('tpi_http_download')) {
            require_once dirname(__DIR__) . '/common/request.php';
        }
        if (!tpi_http_download($url, $file, 60)) {
            @unlink($file);
            return '';
        }
        $tmp = $file;
        return $file;
    }
}

if (!function_exists('tpi_data_init_json')) {
    /**
     * 读一个数据文件（data/{name}.json），非法或缺失返回空数组
     *
     * @return array
     */
    function tpi_data_init_json(string $name): array
    {
        static $cache = [];
        static $rev   = null;
        // 后台改了数据地址后（tpi_data_init_flush）不能再吐旧缓存
        $now_rev = (int) ($GLOBALS['tpi_data_init_rev'] ?? 0);
        if ($rev !== $now_rev) {
            $cache = [];
            $rev   = $now_rev;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }
        $cache[$name] = [];
        $rel = 'data/' . $name . '.json';
        $tmp = null;
        $file = tpi_data_init_fetch($rel, $tmp);
        $data = [];
        if ($file !== '') {
            $raw = (string) file_get_contents($file);
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        $cache[$name] = $data;
        return $cache[$name];
    }
}

if (!function_exists('tpi_data_init_asset_alt')) {
    /**
     * 资源文件对应的 alt 文案
     *
     * 来源 data/assets-alt.json，键是 data-init 相对路径（也接受去掉 assets/ 前缀、
     * 或只写文件名）。没配到返回空串，调用方再退回按文件名自动生成。
     *
     * @param string $rel data-init 相对路径，如 assets/images/a.jpg
     * @return string
     */
    function tpi_data_init_asset_alt(string $rel): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ((array) tpi_data_init_json('assets-alt') as $k => $v) {
                if (!is_string($k) || !is_string($v)) {
                    continue;
                }
                $v = trim(wp_strip_all_tags($v));
                if ($v === '') {
                    continue;
                }
                $k = ltrim(str_replace('\\', '/', trim($k)), '/');
                $map[$k] = $v;
            }
        }
        if (!$map) {
            return '';
        }

        $rel = ltrim(str_replace('\\', '/', preg_replace('/[?#].*$/', '', $rel)), '/');
        if (isset($map[$rel])) {
            return $map[$rel];
        }
        // 兼容三种写法：assets/images/a.jpg / images/a.jpg / a.jpg
        $short = (string) preg_replace('#^assets/#', '', $rel);
        if (isset($map[$short])) {
            return $map[$short];
        }
        $name = basename($rel);
        if (isset($map[$name])) {
            return $map[$name];
        }
        foreach ($map as $k => $v) {
            if (basename($k) === $name) {
                return $v;
            }
        }
        return '';
    }
}

if (!function_exists('tpi_data_init_media')) {
    /**
     * 把 data-init/assets 里的一个文件导入媒体库（按文件名复用）
     *
     * @param string $rel data-init 相对路径，如 assets/images/a.jpg
     * @param bool   $dry 只算不写
     * @return array{id:int,url:string,store:string}
     */
    function tpi_data_init_media(string $rel, bool $dry = false): array
    {
        static $cache = [];
        $key  = ($dry ? 'dry:' : '') . $rel;
        $none = ['id' => 0, 'url' => '', 'store' => ''];
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (!preg_match('#^assets/#', $rel)) {
            $cache[$key] = $none;
            return $none;
        }
        // 本地直接读；远端（init-config.json 指向线上目录）先落到临时文件
        $tmp = null;
        if ($dry && tpi_data_init_base()['mode'] === 'remote') {
            // 干跑不下载：只按相对路径回填
            $cache[$key] = ['id' => 0, 'url' => $rel, 'store' => $rel];
            return $cache[$key];
        }
        $src = tpi_data_init_fetch($rel, $tmp);
        if ($src === '') {
            $cache[$key] = $none;
            return $none;
        }
        $name = basename($rel);
        // assets-alt.json 里配了就用它；没配就沿用按文件名生成的读法
        $alt = tpi_data_init_asset_alt($rel);
        // 远端模式的文件落在临时目录，函数退出前统一删掉
        $cleanup = static function () use (&$tmp): void {
            if ($tmp !== null && is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
            $tmp = null;
        };

        // 已经有同名附件：直接复用（换过域名、换过上传目录的老站也认）
        // 但必须确认磁盘文件还在：附件记录残留而文件已被删时，wp_get_attachment_url()
        // 照样返回地址，读写链路会把它当空值（tpi_media_path_full 查不到文件返回空），
        // 结果是字段全空却查不出原因。这种坏记录先删掉，重新导入。
        global $wpdb;
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC",
            '%' . $wpdb->esc_like($name)
        ));
        $existing = 0;
        foreach ($candidates as $cand_id) {
            $cand_id = (int) $cand_id;
            $cand    = (string) get_attached_file($cand_id);
            if ($cand !== '' && is_file($cand)) {
                $existing = $cand_id;
                break;
            }
            if (!$dry) {
                wp_delete_attachment($cand_id, true);
            }
        }
        if ($existing > 0) {
            // 已有附件默认不动它的 alt（可能是使用者手改过的）。
            // 只在「alt 空着」或「还是按文件名自动生成的值」时补上 assets-alt.json 的文案，
            // 这样重跑导入能把旧的自动 alt 升级成正式描述，又不会覆盖人工编辑。
            if ($alt !== '' && !$dry) {
                $current = trim((string) get_post_meta($existing, '_wp_attachment_image_alt', true));
                $auto    = function_exists('tpi_media_alt_from_name') ? tpi_media_alt_from_name($name) : '';
                if ($current === '' || $current === $auto) {
                    update_post_meta($existing, '_wp_attachment_image_alt', $alt);
                }
            }
            $store = (string) get_post_meta($existing, '_wp_attached_file', true);
            $cache[$key] = [
                'id'    => $existing,
                'url'   => (string) wp_get_attachment_url($existing),
                'store' => ltrim(wp_normalize_path($store), '/'),
            ];
            $cleanup();
            return $cache[$key];
        }

        if ($dry) {
            $cache[$key] = ['id' => 0, 'url' => $rel, 'store' => $rel];
            $cleanup();
            return $cache[$key];
        }

        $uploads = wp_upload_dir();
        $dir     = (string) ($uploads['path'] ?? '');
        if ($dir === '') {
            $cache[$key] = $none;
            $cleanup();
            return $none;
        }
        wp_mkdir_p($dir);
        $dest = trailingslashit($dir) . wp_unique_filename($dir, $name);
        if (!@copy($src, $dest)) {
            $cache[$key] = $none;
            $cleanup();
            return $none;
        }
        $cleanup();

        $attach_id = wp_insert_attachment([
            'post_mime_type' => (string) (wp_check_filetype($dest)['type'] ?? ''),
            'post_title'     => sanitize_file_name(pathinfo($name, PATHINFO_FILENAME)),
            'post_status'    => 'inherit',
            'post_content'   => '',
        ], $dest, 0, true);
        if (is_wp_error($attach_id) || !$attach_id) {
            $cache[$key] = $none;
            return $none;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        // 视频附件要读时长、尺寸，wp_read_video_metadata() 在 media.php 里
        require_once ABSPATH . 'wp-admin/includes/media.php';
        wp_update_attachment_metadata((int) $attach_id, wp_generate_attachment_metadata((int) $attach_id, $dest));
        // alt：优先 assets-alt.json；没配才退回文件名（图片和视频都写，视频前台也能读到）
        $final_alt = $alt;
        if ($final_alt === '' && function_exists('tpi_media_alt_from_name')) {
            $final_alt = tpi_media_alt_from_name($name);
        }
        if ($final_alt !== '') {
            update_post_meta((int) $attach_id, '_wp_attachment_image_alt', $final_alt);
        }

        $store = ltrim(wp_normalize_path((string) str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path($dest))), '/');
        $cache[$key] = [
            'id'    => (int) $attach_id,
            'url'   => (string) wp_get_attachment_url((int) $attach_id),
            'store' => $store,
        ];
        return $cache[$key];
    }
}

if (!function_exists('tpi_data_init_fill_missing')) {
    /**
     * 重新导入时只补缺，不覆盖已有内容
     *
     * 后台那句「已存在的会跳过」要真做到：库里已经有值的键一律保留，
     * 只把还没有的键补进来（数组逐层递归）。空串 / 空数组 / '[]' 视为没值，
     * 允许导入数据把它填上。
     *
     * @param array $incoming 导入数据
     * @param array $existing 库里现有数据
     * @return array
     */
    function tpi_data_init_fill_missing(array $incoming, array $existing): array
    {
        $out = [];
        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $existing)) {
                $out[$key] = $value;
                continue;
            }
            $have = $existing[$key];
            if (is_array($value) && is_array($have)) {
                $out[$key] = tpi_data_init_fill_missing($value, $have);
                continue;
            }
            $empty = !is_array($have) && in_array(trim((string) $have), ['', '[]', '{}'], true);
            if (!$empty && !is_array($have)) {
                $out[$key] = $have;   // 已有值：保留使用者的
                continue;
            }
            $out[$key] = $value;      // 空着：用导入值补上
        }
        return $out;
    }
}

if (!function_exists('tpi_data_init_resolve')) {
    /**
     * 递归把 `assets/...` 路径换成媒体库地址
     *
     * @param mixed  $value
     * @param string $mode  url = 绝对地址；store = 上传目录相对路径
     * @param bool   $dry
     * @return mixed
     */
    function tpi_data_init_resolve($value, string $mode = 'url', bool $dry = false)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = tpi_data_init_resolve($v, $mode, $dry);
            }
            return $out;
        }
        if (!is_string($value) || strpos($value, 'assets/') === false) {
            return $value;
        }
        // 整串就是资源路径：直接换
        if (preg_match('#^assets/#', $value)) {
            $media  = tpi_data_init_media($value, $dry);
            $picked = $mode === 'store' ? $media['store'] : $media['url'];
            return $picked !== '' ? $picked : $value;
        }
        // 正文 HTML：只换属性里的资源路径（src / href / poster / data-src / srcset），
        // 正文图片要能直接渲染，所以这里固定给绝对地址，不看 $mode
        return preg_replace_callback(
            '#\b(src|href|poster|data-src|data-original)=(["\'])(assets/[^"\']+)\2#i',
            static function ($m) use ($dry) {
                $media = tpi_data_init_media($m[3], $dry);
                $url   = $media['url'] !== '' ? $media['url'] : $m[3];
                return $m[1] . '=' . $m[2] . $url . $m[2];
            },
            $value
        );
    }
}

if (!function_exists('tpi_data_init_page_id')) {
    /** 按 slug + 语言找页面 */
    function tpi_data_init_page_id(string $slug, string $lang): int
    {
        if (function_exists('tpi_get_post_id_by_slug')) {
            $id = (int) tpi_get_post_id_by_slug($slug, $lang, 'page');
            if ($id > 0) {
                return $id;
            }
        }
        // 兜底：页面还没来得及打语言标记时按 slug 认，但语言必须对得上
        // （否则 /ja/home 会命中英文 Home，ja 页面永远建不出来）
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page instanceof WP_Post) {
            $own = function_exists('tpi_get_post_language') ? (string) tpi_get_post_language($page->ID) : '';
            $default = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
            if ($own === '' || $own === $lang || $lang === $default) {
                return (int) $page->ID;
            }
        }
        return 0;
    }
}

if (!function_exists('tpi_data_init_term')) {
    /**
     * 取/建某个语言的分类
     *
     * 非默认语言的 term slug 用「默认 slug + -语言」（与后台新建译文的规则一致）。
     *
     * @return int term_id
     */
    function tpi_data_init_term(string $taxonomy, string $slug, string $lang, string $name, string $desc, bool $dry = false, int $order = 0): int
    {
        if (!taxonomy_exists($taxonomy) || $slug === '') {
            return 0;
        }
        $default = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
        $term_slug = $lang === $default ? $slug : $slug . '-' . $lang;

        $found = get_term_by('slug', $term_slug, $taxonomy);
        if ($found && !is_wp_error($found)) {
            if (function_exists('tpi_set_term_language') && function_exists('tpi_get_term_language')
                && tpi_get_term_language($found) === '') {
                tpi_set_term_language((int) $found->term_id, $lang);
            }
            if (!$dry && $order > 0 && (int) get_term_meta((int) $found->term_id, '_tpi_term_order', true) !== $order) {
                update_term_meta((int) $found->term_id, '_tpi_term_order', $order);
            }
            return (int) $found->term_id;
        }
        if ($dry) {
            return -1;
        }

        $created = wp_insert_term($name, $taxonomy, [
            'slug'        => $term_slug,
            'description' => $desc,
        ]);
        if (is_wp_error($created)) {
            $data = $created->get_error_data();
            return (int) ($data['term_id'] ?? 0);
        }
        $term_id = (int) $created['term_id'];
        if (function_exists('tpi_set_term_language')) {
            tpi_set_term_language($term_id, $lang);
        }
        if ($order > 0) {
            update_term_meta($term_id, '_tpi_term_order', $order);
        }
        return $term_id;
    }
}

if (!function_exists('tpi_data_init_insert')) {
    /**
     * 插入内容并强制使用 JSON 里写的 slug
     *
     * 非默认语言的页面和默认语言同 slug（/ja/home 与 /home 是两个页面），
     * 而 WordPress 插入时内容还没有语言标记，唯一化会把 home 改成 home-2。
     * 这里临时给一个 pre_wp_unique_post_slug，插完就摘掉。
     *
     * @param array $postarr
     * @return int 新 ID（失败 0）
     */
    function tpi_data_init_insert(array $postarr): int
    {
        $slug  = (string) ($postarr['post_name'] ?? '');
        // apply_filters('pre_wp_unique_post_slug', null, $slug, $post_ID, $post_status, $post_type, $post_parent)
        $force = static function ($override, $orig_slug = '', $post_id = 0, $post_status = '', $post_type = '', $post_parent = 0) use ($slug) {
            // 附件 / 自动草稿不碰
            if ($slug === '' || in_array($post_status, ['auto-draft', 'inherit'], true)) {
                return $override;
            }
            return $slug;
        };
        add_filter('pre_wp_unique_post_slug', $force, 999, 6);
        $post_id = wp_insert_post($postarr, true);
        remove_filter('pre_wp_unique_post_slug', $force, 999);
        return is_wp_error($post_id) ? 0 : (int) $post_id;
    }
}

if (!function_exists('tpi_data_init_fix_slug')) {
    /**
     * 语言标记写好之后，把 slug 钉回 JSON 里的值
     *
     * 非默认语言页面和默认语言同 slug，插入那一刻内容还没有语言标记，
     * 唯一化会把它改成 home-1；设好语言之后 post-slug.php 的过滤器才会放行同名。
     */
    function tpi_data_init_fix_slug(int $post_id, string $slug): void
    {
        if ($post_id <= 0 || $slug === '') {
            return;
        }
        if ((string) get_post_field('post_name', $post_id) === $slug) {
            return;
        }
        wp_update_post(['ID' => $post_id, 'post_name' => $slug]);
    }
}

if (!function_exists('tpi_data_init_page_needs_content')) {
    /**
     * 页面正文要不要由 pages.json 写入
     *
     * - 正文为空（或只剩一个空段落、占位符）→ 要写
     * - WordPress 新装自带的隐私政策草稿（正文里全是 privacy-policy-tutorial 的 Suggested text）→ 覆盖
     * - 已经有真实正文 → 不动，别盖掉后台改过的内容
     */
    function tpi_data_init_page_needs_content(int $post_id): bool
    {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        $content = (string) $post->post_content;
        if (trim($content) === '') {
            return true;
        }
        if (trim(wp_strip_all_tags($content)) === '' && strpos($content, '<img') === false) {
            return true;
        }
        return strpos($content, 'privacy-policy-tutorial') !== false;
    }
}

if (!function_exists('tpi_data_init_pages')) {
    /**
     * 建骨架页面（每语言一份，标题取菜单标题 / 语言内容）
     *
     * 正文来自 data/pages.json（键：语言 → slug → {title, excerpt, content}）。
     * 已存在的页面只在「没有正文」或「还是 WP 默认隐私草稿」时补写，不覆盖人工编辑过的内容。
     *
     * @param int $written 被写入/补写正文的页面数（引用传出，用于报告）
     * @return array<string,int> "lang:slug" => post_id
     */
    function tpi_data_init_pages(array $page_slugs, array $titles, string $lang, bool $dry = false, array $pages = [], int &$written = 0): array
    {
        $ids     = [];
        $default = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
        $front   = 'home';

        foreach ($page_slugs as $slug) {
            $title = (string) ($titles[$slug][$lang] ?? $titles[$slug][$default] ?? ucfirst($slug));
            $row = (array) ($pages[$lang][$slug] ?? $pages[$default][$slug] ?? []);
            if (trim($title) === '' && !empty($row['title'])) {
                $title = (string) $row['title'];
            }
            $content = (string) ($row['content'] ?? '');
            $excerpt = (string) ($row['excerpt'] ?? '');

            $existing = tpi_data_init_page_id($slug, $lang);
            if ($existing > 0) {
                $ids[$slug] = $existing;
                // 老站/新装站点：页面已经在，但正文是空的或还是 WP 的隐私政策草稿
                if ($content !== '' && tpi_data_init_page_needs_content((int) $existing)) {
                    if (!$dry) {
                        $update = ['ID' => (int) $existing, 'post_content' => $content];
                        if ($excerpt !== '' && trim((string) get_post($existing)->post_excerpt) === '') {
                            $update['post_excerpt'] = $excerpt;
                        }
                        wp_update_post($update);
                        $written++;
                    }
                }
                continue;
            }
            if ($dry) {
                $ids[$slug] = -1;
                continue;
            }
            $post_id = tpi_data_init_insert([
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'post_title'     => $title,
                'post_name'      => $slug,
                'post_content'   => $content,
                'post_excerpt'   => $excerpt,
                'menu_order'     => function_exists('tpi_page_setup_order') ? tpi_page_setup_order($slug) : 0,
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ]);
            if ($post_id > 0 && $content !== '') {
                $written++;
            }
            if ($post_id <= 0) {
                continue;
            }
            if (function_exists('tpi_set_post_language')) {
                tpi_set_post_language((int) $post_id, $lang);
            }
            tpi_data_init_fix_slug((int) $post_id, $slug);
            $ids[$slug] = (int) $post_id;

            if ($slug === $front && $lang === $default) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', (int) $post_id);
            }
        }
        return $ids;
    }
}

if (!function_exists('tpi_data_init_menu')) {
    /**
     * 建菜单项（每个语言一套，标题带 (lang) 后缀，与后台手工加项一致）
     *
     * @param array $items   menus.json 的 items
     * @param array $page_ids  "lang:slug" => id
     * @param array $term_ids  "lang:taxonomy:slug" => id
     */
    function tpi_data_init_menu(array $items, array $page_ids, array $term_ids, array $langs, bool $dry = false): int
    {
        global $wpdb;
        $menu_id = function_exists('tpi_primary_menu_create') ? (int) tpi_primary_menu_create() : 0;
        if ($menu_id <= 0) {
            return 0;
        }
        $added = 0;
        $used  = [];
        foreach (function_exists('tpi_primary_menu_items') ? tpi_primary_menu_items(true) : [] as $row) {
            $used[(int) $row->object_id . ':' . (string) $row->object] = (int) $row->ID;
        }

        foreach ($langs as $lang_index => $lang) {
            foreach ($items as $item) {
                $type = (string) ($item['type'] ?? 'page');
                $order = (int) ($item['order'] ?? 0) + 1;
                if ($type === 'page') {
                    $slug    = (string) ($item['page'] ?? '');
                    $object  = 'page';
                    $obj_id  = (int) ($page_ids[$lang . ':' . $slug] ?? 0);
                    $nav_type = 'post_type';
                } elseif ($type === 'product_cat') {
                    $slug    = (string) ($item['term'] ?? '');
                    $object  = 'product_cat';
                    $obj_id  = (int) ($term_ids[$lang . ':product_cat:' . $slug] ?? 0);
                    $nav_type = 'taxonomy';
                } else {
                    continue;
                }
                if ($obj_id <= 0) {
                    continue;
                }
                $key = $obj_id . ':' . $object;
                $position = $lang_index * 1000 + max(1, $order - 1);
                if (isset($used[$key])) {
                    // 已经在菜单里：只把顺序对齐到 JSON（不覆盖手工改过的标题）
                    if (!$dry) {
                        $wpdb->update($wpdb->posts, ['menu_order' => $position], ['ID' => (int) $used[$key]]);
                    }
                    continue;
                }
                if ($dry) {
                    $added++;
                    continue;
                }
                $title = (string) ($item['title'][$lang] ?? $item['title'] ?? '');
                if ($title === '') {
                    $title = (string) get_the_title($obj_id);
                }
                $item_id = wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title'     => $title . ' (' . $lang . ')',
                    'menu-item-object'    => $object,
                    'menu-item-object-id' => $obj_id,
                    'menu-item-type'      => $nav_type,
                    'menu-item-status'    => 'publish',
                    'menu-item-parent-id' => 0,
                ]);
                if (!is_wp_error($item_id)) {
                    $item_id = (int) $item_id;
                    $used[$key] = $item_id;
                    $added++;
                    // 顺序直接写 menu_order：wp_update_nav_menu_item 的 position 参数
                    // 会按已有项重排，多个语言插同一位置时结果不可控
                    $wpdb->update($wpdb->posts, ['menu_order' => $position], ['ID' => $item_id]);
                }
            }
        }
        if (!$dry && function_exists('tpi_primary_menu_items')) {
            tpi_primary_menu_items(true);
        }
        return $added;
    }
}

if (!function_exists('tpi_data_init_content')) {
    /**
     * 建一类内容（产品 / 文章 / 应用 / FAQ）
     *
     * 同 slug + 同语言已存在就跳过，绝不覆盖站里已有的数据。
     *
     * @return array{created:int,skipped:int,ids:array<string,int>}
     */
    function tpi_data_init_content(string $post_type, string $taxonomy, array $rows, string $lang, array $term_ids, bool $dry = false): array
    {
        $report = ['created' => 0, 'skipped' => 0, 'ids' => []];
        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $existing = function_exists('tpi_get_post_id_by_slug') ? (int) tpi_get_post_id_by_slug($slug, $lang, $post_type) : 0;
            if ($existing > 0) {
                $report['skipped']++;
                $report['ids'][$slug] = $existing;
                continue;
            }
            if ($dry) {
                $report['created']++;
                continue;
            }
            $title   = (string) ($row['title'] ?? '');
            $content = (string) ($row['content'] ?? '');
            $post_id = tpi_data_init_insert([
                'post_type'    => $post_type,
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_excerpt' => (string) ($row['excerpt'] ?? ''),
                'post_content' => tpi_data_init_resolve($content, 'store', false),
                'menu_order'   => (int) ($row['order'] ?? 0),
            ]);
            if ($post_id <= 0) {
                continue;
            }
            $post_id = (int) $post_id;
            if (function_exists('tpi_set_post_language')) {
                tpi_set_post_language($post_id, $lang);
            }
            tpi_data_init_fix_slug($post_id, $slug);

            $ids = [];
            foreach ((array) ($row['terms'] ?? []) as $term_slug) {
                $tid = (int) ($term_ids[$lang . ':' . $taxonomy . ':' . $term_slug] ?? 0);
                // 非默认语言的 term slug 带语言后缀，这里按「原 slug → 带后缀」再兜一次
                if ($tid <= 0 && $term_slug !== '') {
                    $fallback = get_term_by('slug', $term_slug . '-' . $lang, $taxonomy);
                    if ($fallback && !is_wp_error($fallback)) {
                        $tid = (int) $fallback->term_id;
                    }
                }
                if ($tid > 0) {
                    $ids[] = $tid;
                }
            }
            if ($ids && taxonomy_exists($taxonomy)) {
                wp_set_object_terms($post_id, $ids, $taxonomy, false);
            }

            $fields = [];
            if (array_key_exists('main_title', $row)) {
                // 详情页 h1 用 main_title：比列表标题长、更具体，两者不重复
                $fields['main_title'] = (string) $row['main_title'];
            }
            if (array_key_exists('excerpt', $row)) {
                $fields['description'] = (string) $row['excerpt'];
            }
            if (array_key_exists('summary', $row)) {
                $fields['summary'] = (string) $row['summary'];
            }
            if (array_key_exists('warning', $row)) {
                $fields['warning'] = (string) $row['warning'];
            }
            if (array_key_exists('params', $row)) {
                $fields['parameters'] = (array) $row['params'];
            }
            if (array_key_exists('answer', $row)) {
                $fields['answer'] = (string) $row['answer'];
            }
            if (!empty($row['gallery'])) {
                $gallery = [];
                foreach ((array) $row['gallery'] as $i => $rel) {
                    $media = tpi_data_init_media((string) $rel);
                    if ($media['store'] === '') {
                        continue;
                    }
                    $gallery[] = [
                        'img_src'   => $media['store'],
                        'image_alt' => $title . ' — ' . ($i + 1),
                        'type'      => 'image',
                    ];
                }
                if ($gallery) {
                    $fields['images'] = $gallery;
                    $fields['image']  = $gallery[0]['img_src'];
                }
            } elseif (!empty($row['image'])) {
                $media = tpi_data_init_media((string) $row['image']);
                if ($media['store'] !== '') {
                    $fields['image'] = $media['store'];
                }
            }
            if ($fields && function_exists('tpi_save_item_fields')) {
                tpi_save_item_fields($post_id, $post_type, $fields);
            }
            $report['created']++;
            $report['ids'][$slug] = $post_id;
        }
        return $report;
    }
}

if (!function_exists('tpi_data_init_run')) {
    /**
     * 跑一遍初始化
     *
     * @param bool $dry          只统计不写库（后台/命令行排查用）
     * @param bool $with_content 是否建四类内容（老站升级时传 false，只补语言/页面/菜单/文案）
     * @return array 报告
     */
    function tpi_data_init_run(bool $dry = false, bool $with_content = true): array
    {
        $report = [
            'languages' => 0, 'pages' => 0, 'pages_new' => 0, 'pages_content' => 0, 'terms' => 0, 'terms_new' => 0,
            'menu' => 0, 'lang-content' => 0, 'page-info' => 0,
            'product' => 0, 'post' => 0, 'application' => 0, 'faq' => 0,
            'skipped' => 0, 'dry' => $dry, 'error' => '',
        ];

        if (!$dry) {
            // 远端目录要下载几十个文件，php-cgi 的 30s 上限撑不住；
            // 同时别让浏览器断开把初始化半途掐死（半成品比没跑更麻烦）
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            @ignore_user_abort(true);
        }

        $languages = tpi_data_init_json('languages');
        $base      = tpi_data_init_base();
        $items     = (array) ($languages['items'] ?? []);
        if (!$items) {
            // 一个语言都读不到 = 数据源本身有问题（路径写错、线上目录 404、站没起）
            // 这里明确报出来，别再假装初始化成功
            $where = $base['mode'] === 'remote' ? $base['url'] : $base['path'];
            // 文案跟后台「初始数据」页共用一套（后台是英文就是英文，中文就是中文）
            $report['error'] = sprintf(
                function_exists('tpi_admin_ui') ? tpi_admin_ui('data_init.error.not-found') : 'data/languages.json could not be read from: %s',
                $where . 'data/languages.json'
            );
            if ($base['mode'] === 'remote' && function_exists('tpi_admin_ui')) {
                $report['error'] .= ' ' . tpi_admin_ui('data_init.error.remote-hint');
            }
            if (!$dry) {
                update_option('tpi_data_init_error', $report['error'], false);
            }
            return $report;
        }
        if (!$dry) {
            delete_option('tpi_data_init_error');
        }
        $default   = (string) ($languages['default'] ?? '');
        if ($default === '') {
            // JSON 没写默认语言时才退回当前站点设置，最后才是 en
            $default = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
        }
        $langs     = [];
        foreach ($items as $item) {
            $code = strtolower((string) ($item['code'] ?? ''));
            if ($code !== '') {
                $langs[] = $code;
            }
        }
        if (!$langs) {
            $langs = [function_exists('tpi_default_language') ? tpi_default_language() : 'en'];
        }
        $default = in_array($default, $langs, true) ? $default : $langs[0];

        // 1) 语言表
        if (!$dry && function_exists('tpi_languages_sync') && $items) {
            $sync = [];
            foreach ($items as $item) {
                $code = strtolower((string) ($item['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $flag = (string) ($item['flag'] ?? '');
                $media = $flag !== '' ? tpi_data_init_media($flag) : ['id' => 0, 'url' => ''];
                $sync[] = [
                    'code'     => $code,
                    'name'     => (string) ($item['name'] ?? strtoupper($code)),
                    'locale'   => (string) ($item['locale'] ?? ''),
                    'flag_id'  => (int) $media['id'],
                    'flag_url' => (string) $media['url'],
                ];
            }
            $result = tpi_languages_sync($sync, $default);
            $report['languages'] = count($result['created'] ?? []);
            // 语言表刚建好，同一次请求里后面的 set_post_language 要能立刻看到新语言，
            // 否则非默认语言的内容会被判成非法代码丢掉（页面挤成同一个语言的 -2 副本）
            if (function_exists('tpi_languages')) {
                tpi_languages(true);
            }
        }

        // 2) 站点标题不在这里动
        // data-init 只负责「内容数据」：图片进上传目录、页面与菜单建好就结束。
        // 站点标题、副标题、日期格式、每页篇数、首页指向、时区都是站点配置，
        // 一律留给使用者在「设置 → 常规」里填，导入演示数据时不覆盖。
        // （原 data-init/data/site.json 已移除，站点名不再由数据包决定。）

        // 3) 页面（标题优先取菜单标题，其次语言内容里的 title）
        // 建页面期间先摘掉「新页面自动进菜单」的钩子：它挂在 wp_after_insert_post 上，
        // 那一刻语言还没写，标题会变成「ホーム (en)」，顺序也按插入先后。
        // 菜单统一由 menus.json 决定（本轮第 5 步），建完再把钩子放回去。
        $auto_menu = has_action('wp_after_insert_post', 'tpi_primary_menu_sync_page');
        if ($auto_menu) {
            remove_action('wp_after_insert_post', 'tpi_primary_menu_sync_page', 20);
        }
        $menus       = tpi_data_init_json('menus');
        $menu_items  = (array) ($menus['items'] ?? []);
        $lang_content = tpi_data_init_json('lang-content');
        $page_titles = [];
        foreach ($menu_items as $item) {
            if ((string) ($item['type'] ?? '') === 'page') {
                $slug = (string) ($item['page'] ?? '');
                if ($slug !== '') {
                    $page_titles[$slug] = (array) ($item['title'] ?? []);
                }
            }
        }
        $page_slugs = array_keys($page_titles);
        // 不进菜单的页面（隐私政策这类）同样由数据源声明：menus.json 的 extra_pages。
        // 标题依次取 extra_pages.title → 语言内容里同名节点 → slug 转标题，
        // 代码里不再写死任何具体文案，换语言只改 JSON。
        foreach ((array) ($menus['extra_pages'] ?? []) as $extra) {
            $slug = is_array($extra)
                ? (string) ($extra['page'] ?? $extra['slug'] ?? '')
                : (string) $extra;
            if ($slug === '' || isset($page_titles[$slug])) {
                continue;
            }
            $slug_titles = [];
            foreach ($langs as $lang) {
                $one = is_array($extra) ? (string) ($extra['title'][$lang] ?? '') : '';
                if ($one === '') {
                    $one = (string) ($lang_content[$lang][$slug]['title'] ?? '');
                }
                if ($one === '') {
                    $one = ucwords(str_replace(['-', '_'], ' ', $slug));
                }
                $slug_titles[$lang] = $one;
            }
            $page_titles[$slug] = $slug_titles;
            $page_slugs[]       = $slug;
        }
        // 首页固定放最前（page_order 里有固定序位，这里只保证存在）
        usort($page_slugs, static function ($a, $b) {
            return ($a === 'home' ? -1 : 0) <=> ($b === 'home' ? -1 : 0);
        });

        $page_ids = [];
        $page_bodies = tpi_data_init_json('pages');
        $page_written = 0;
        foreach ($langs as $lang) {
            $made = tpi_data_init_pages($page_slugs, $page_titles, $lang, $dry, $page_bodies, $page_written);
            foreach ($made as $slug => $id) {
                $page_ids[$lang . ':' . $slug] = $id;
                if ($id > 0) {
                    $report['pages']++;
                } else {
                    $report['pages_new']++;
                }
            }
        }
        $report['pages_content'] = (int) $page_written;

        // 4) 分类
        $taxonomies = tpi_data_init_json('taxonomies');
        $term_ids   = [];
        foreach ($taxonomies as $taxonomy => $terms) {
            foreach ((array) $terms as $term) {
                $slug = (string) ($term['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                foreach ($langs as $lang) {
                    $name = (string) ($term['name'][$lang] ?? $term['name'][$default] ?? $slug);
                    $desc = (string) ($term['description'][$lang] ?? $term['description'][$default] ?? '');
                    $tid  = tpi_data_init_term($taxonomy, $slug, $lang, $name, $desc, $dry, (int) ($term['order'] ?? 0));
                    $term_ids[$lang . ':' . $taxonomy . ':' . $slug] = $tid;
                    if ($tid > 0) {
                        $report['terms']++;
                    } else {
                        $report['terms_new']++;
                    }
                }
            }
        }

        // 5) 菜单
        $report['menu'] = tpi_data_init_menu($menu_items, $page_ids, $term_ids, $langs, $dry);
        if ($auto_menu) {
            add_action('wp_after_insert_post', 'tpi_primary_menu_sync_page', 20, 4);
        }

        // 6) 语言内容（图标路径换成上传路径）
        if (!$dry && function_exists('tpi_lang_content_save_many')) {
            foreach ($langs as $lang) {
                $sections = (array) ($lang_content[$lang] ?? []);
                if (!$sections) {
                    continue;
                }
                $resolved = tpi_data_init_resolve($sections, 'store', false);
                // 重新导入时按「已有值优先」合并，别把使用者改过的文案冲掉
                $resolved = tpi_data_init_fill_missing($resolved, tpi_lang_content_raw($lang));
                $report['lang-content'] += tpi_lang_content_save_many($lang, $resolved);
            }
        }

        // 7) 页面信息（shared + 各语言覆盖）
        $page_info = tpi_data_init_json('page-info');
        $shared    = (array) ($page_info['shared'] ?? []);
        if (!$dry && function_exists('tpi_setting_save')) {
            foreach ($langs as $lang) {
                $own = (array) ($page_info[$lang] ?? []);
                if (!$own && !$shared) {
                    continue;
                }
                $settings = array_merge($shared, $own);
                $settings = tpi_data_init_resolve($settings, 'url', false);
                foreach (['images', 'certificates_json', 'media_list_json'] as $json_key) {
                    if (!isset($settings[$json_key])) {
                        continue;
                    }
                    $value = $settings[$json_key];
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        $value   = is_array($decoded) ? $decoded : [];
                    }
                    if (is_array($value)) {
                        foreach ($value as $i => $row) {
                            if (is_array($row) && isset($row['route']) && is_string($row['route']) && str_starts_with($row['route'], '/')) {
                                $value[$i]['route'] = home_url($row['route']);
                            }
                        }
                    }
                    $settings[$json_key] = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                // 已有设置优先：只补库里还没有（或为空）的键，联系方式这类手填内容不会被重置
                $lang_id = function_exists('tpi_language_id') ? tpi_language_id($lang) : 0;
                $existing = (array) tpi_setting_rows('page-info', 0);
                if ($lang_id > 0) {
                    $existing = array_merge($existing, (array) tpi_setting_rows('page-info', $lang_id));
                }
                $settings = tpi_data_init_fill_missing($settings, $existing);

                tpi_setting_save('page-info', $settings, $lang);
                $report['page-info']++;
            }
        }

        // 8) 四类内容
        $types = [
            'products'     => ['post_type' => 'product', 'taxonomy' => 'product_cat'],
            'posts'        => ['post_type' => 'post', 'taxonomy' => 'category'],
            'applications' => ['post_type' => 'application', 'taxonomy' => 'application_cat'],
            'faqs'         => ['post_type' => 'faq', 'taxonomy' => 'faq_cat'],
        ];
        $made_ids = [];
        foreach ($types as $file => $conf) {
            if (!$with_content) {
                break;
            }
            $data = tpi_data_init_json($file);
            if (!$data) {
                continue;
            }
            foreach ($langs as $lang) {
                $rows  = (array) ($data[$lang] ?? []);
                if (!$rows) {
                    continue;
                }
                $one = tpi_data_init_content($conf['post_type'], $conf['taxonomy'], $rows, $lang, $term_ids, $dry);
                $report[$conf['post_type']] += $one['created'];
                $report['skipped'] += $one['skipped'];
                foreach ($one['ids'] as $slug => $id) {
                    $made_ids[$conf['post_type'] . ':' . $slug][$lang] = $id;
                }
            }
        }

        // 9) 同 slug 的两种语言互相关联（语言切换才跳得对）
        if (!$dry && function_exists('tpi_save_post_translations')) {
            foreach ($made_ids as $map) {
                if (count($map) > 1) {
                    foreach ($map as $lang => $id) {
                        tpi_save_post_translations((int) $id, $map);
                    }
                }
            }
            // 页面同样按 slug 关联
            foreach ($page_slugs as $slug) {
                $map = [];
                foreach ($langs as $lang) {
                    $id = (int) ($page_ids[$lang . ':' . $slug] ?? 0);
                    if ($id > 0) {
                        $map[$lang] = $id;
                    }
                }
                if (count($map) > 1) {
                    foreach ($map as $id) {
                        tpi_save_post_translations((int) $id, $map);
                    }
                }
            }
        }

        return $report;
    }
}
