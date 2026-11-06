<?php
/**
* 媒体库工具
 *
 * 按文件名或相对路径取媒体地址、图片 alt、正文 img 列表、本地图片宽高。
 * 路径归一化本体在 media/media-path.php。
 */

/**
 * 获取html src列表
 * @param $html
 * @return array
 */
function getImageSrcList($html): array {
    $dom = new DOMDocument();

    // 避免HTML解析警告
    libxml_use_internal_errors(true);

    // 指定 UTF-8 编码
    $html = '<?xml encoding="utf-8" ?>' . $html;

    $dom->loadHTML($html);
    libxml_clear_errors();

    $images = $dom->getElementsByTagName('img');

    $srcList = [];
    foreach ($images as $img) {
        $src = (string) $img->getAttribute('src');
        // 正文里存的是上传目录相对路径，结构化数据 / 提取结果统一给绝对地址
        if ($src !== '' && function_exists('tpi_media_url')) {
            $full = tpi_media_url($src);
            if ($full !== '') {
                $src = $full;
            }
        }
        $srcList[] = $src;
    }

    return $srcList;
}

/**
 * 取媒体库资源地址（按文件名）
 *
 * 主题不再自带图片，图片/国旗都放在媒体库里，这里按文件名反查附件，
 * 不写死域名，换域名或换环境只要媒体库里有同名文件就能取到。
 *
 * 兼容四种入参：
 * - 纯文件名（Logistics.png）：查媒体库 `_wp_attached_file` 的 basename，命中返回附件 URL
 * - 上传目录相对路径（2026/10/a.jpg，库里统一存这个）：拼上上传目录根
 * - /assets/ 开头的主题内资源（/assets/svg/Address-000000.svg）：转成主题目录地址
 * - 站点根相对路径（/wp-content/uploads/2026/10/X.svg，老数据）：补全成当前域名的绝对地址
 * - http(s) 绝对地址：本站地址按当前域名重建，外站原样返回
 *
 * 返回值一律是**绝对地址**（展示用）；要存库请用 tpi_media_rel() / tpi_media_store()。
 *
 * @param string $file
 * @param bool   $theme_fallback 媒体库里没有同名文件时，是否退回主题自带图标。
 *                               「必须是上传图片」的场景（社媒图标等）传 false，
 *                               避免前台又指回 themes/osiera/assets/svg。
 * @return string 取不到时返回空字符串
 */
function tpi_media_url(string $file, bool $theme_fallback = true): string {
    $file = trim($file);
    if ($file === '') {
        return '';
    }
    if (strpos($file, 'data:') === 0) {
        return $file;
    }
    if (preg_match('#^(https?:)?//#i', $file)) {
        return tpi_media_full($file);
    }
    if ($file[0] === '/') {
        // 主题内资源或媒体库根相对路径；文件确实存在才返回，避免输出 404 地址
        if (strpos($file, '..') !== false) {
            return '';
        }
        // 历史数据里写成 /assets/... 的主题资源，转成真实主题目录地址，
        // 这样不依赖 Nginx 里那条 `location /assets { rewrite ... }`
        if (strpos($file, '/assets/svg/') === 0) {
            $name = basename($file);
            [$base, $hex] = tpi_svg_parse_name($name);
            if (is_file(tpi_svg_dir() . $base . '.svg')) {
                return tpi_svg_url($base, $hex);
            }
        }
        if (strpos($file, '/assets/') === 0 && is_file(THEME_DIR . $file)) {
            return tpi_asset(substr($file, strlen('/assets')));
        }
        // 上传目录：/wp-content/uploads/… 与站点自定义前缀（生产 /uploads/assets/…）
        // 归一后路径变了就说明属于上传目录，交给 tpi_media_path_full 拼地址并检查文件
        if (tpi_media_path_store($file) !== $file) {
            return tpi_media_path_full($file);
        }
        if (is_file(THEME_DIR . $file) || is_file(ABSPATH . ltrim($file, '/'))) {
            return home_url($file);
        }
        return '';
    }

    // 上传目录相对路径（2026/10/a.jpg，与 _wp_attached_file 同形）：
    // 带目录的路径本身就是完整信息，直接拼上传目录根，不走媒体库反查
    if (tpi_media_is_uploads_rel($file)) {
        return tpi_media_path_full($file);
    }

    static $cache = [];
    $key = strtolower($file);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) LIKE %s ORDER BY post_id DESC",
        '%' . $wpdb->esc_like($key)
    ));

    $url = '';
    foreach ($rows as $row) {
        if (strtolower(basename((string) $row->meta_value)) === $key) {
            $url = (string) wp_get_attachment_url((int) $row->post_id);
            break;
        }
    }

    // 媒体库里没有时，退回主题自带的同名图标（统一放 assets/svg，不再分子目录）：
    // 新项目刚装好主题、媒体库还是空的，WhatsApp / YouTube / 国旗 / 界面图标
    // 这些结构性图标不能跟着变空白，后台换过图的话仍以媒体库为准。
    // 社媒图标（linkedin / x-social）不在此列 —— 它们是上传件，调用方传
    // $theme_fallback = false，主题里没有副本可退。
    // 文件名可带 -RRGGBB 颜色后缀，母版只有白色一份，颜色由 svg-color.php 输出。
    if ($url === '' && $theme_fallback) {
        $name = basename($file);
        [$base, $hex] = tpi_svg_parse_name($name);
        if (is_file(tpi_svg_dir() . $base . '.svg')) {
            $url = tpi_svg_url($base, $hex);
        }
    }

    $cache[$key] = $url;
    return $url;
}

/**
 * 按「名称」在媒体库里找图标（只认上传的 svg，不回落到主题）
 *
 * 社媒图标在后台可以留空，前台按平台名去媒体库找：
 * `LinkedIn` → linkedin.svg、`X` → x-social.svg。
 * 先比完整同名，再接受以「名称-」开头或以「-名称」结尾的文件名，
 * 这样 x-social / logo-x 这类命名也能命中。
 *
 * @param string $name 平台名称（后台填的显示名）
 * @return string 附件地址，找不到返回空字符串
 */
function tpi_media_icon_by_name(string $name): string {
    $key = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    if ($key === '') {
        return '';
    }

    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) LIKE %s ORDER BY post_id DESC",
        '%' . $wpdb->esc_like($key) . '%'
    ));

    foreach ($ids as $id) {
        $file = (string) get_post_meta((int) $id, '_wp_attached_file', true);
        if (!preg_match('/\.svg$/i', $file)) {
            continue;
        }
        $base = strtolower(basename($file, '.svg'));
        if ($base === $key || strpos($base, $key . '-') === 0 || substr($base, -strlen('-' . $key)) === '-' . $key) {
            return (string) wp_get_attachment_url((int) $id);
        }
    }

    return '';
}

/**
 * 取媒体库资源的入库形式（上传目录相对路径）
 *
 * 存进 option / post meta 时用它，避免把域名和上传目录写死：
 *   http://example.com/wp-content/uploads/2026/10/a.jpg
 *     → 2026/10/a.jpg
 * 和核心 _wp_attached_file 同一个形态；外站地址原样返回；取不到返回空字符串。
 *
 * @param string $file
 * @return string
 */
function tpi_media_rel(string $file): string {
    $url = tpi_media_url($file);
    if ($url === '') {
        return '';
    }
    return tpi_media_store($url);
}

/**
 * 取图片对应的附件 ID
 *
 * 主题里的图片地址有好几种形态：附件 ID、绝对地址、站点根相对、
 * 上传目录相对（2026/11/a.png）、纯文件名。这里统一收敛成附件 ID，
 * 查不到返回 0（早期演示图只复制了文件、没登记附件）。
 *
 * @param mixed $image
 * @return int
 */
function tpi_media_attachment_id($image): int {
    static $cache = [];

    if (is_int($image) || (is_string($image) && ctype_digit($image))) {
        $id = (int) $image;
        return get_post_type($id) === 'attachment' ? $id : 0;
    }

    $key = trim((string) $image);
    if ($key === '') {
        return 0;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cache[$key] = 0;

    $image = function_exists('tpi_media_fix_bogus_scheme') ? tpi_media_fix_bogus_scheme($key) : $key;

    // 绝对地址先交给核心：同域名、带 -WxH 后缀都能命中
    if (preg_match('#^(https?:)?//#i', $image)) {
        $id = (int) attachment_url_to_postid($image);
        if ($id > 0) {
            return $cache[$key] = $id;
        }
    }

    global $wpdb;
    $rel = function_exists('tpi_media_store') ? tpi_media_store($image) : $image;
    $rel = ltrim((string) preg_replace('/[?#].*$/', '', (string) $rel), '/');
    if ($rel !== '' && strpos($rel, '..') === false) {
        // 原图 + 去掉 -scaled / -1024x683 后缀的缩略图，都试一次
        $candidates = [$rel];
        if (preg_match('#^(.*)-(?:scaled|\d+x\d+)(\.[a-z0-9]+)$#i', $rel, $m)) {
            $candidates[] = $m[1] . $m[2];
        }
        foreach ($candidates as $candidate) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                $candidate
            ));
            if ($id > 0) {
                return $cache[$key] = $id;
            }
        }
    }

    // 只剩文件名：按 basename 反查（换过上传目录、库里只存文件名的情况）
    $name = strtolower(basename((string) preg_replace('/[?#].*$/', '', $image)));
    if ($name !== '') {
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND LOWER(meta_value) LIKE %s ORDER BY post_id DESC",
            '%' . $wpdb->esc_like($name)
        ));
        foreach ($ids as $id) {
            if (strtolower(basename((string) get_post_meta((int) $id, '_wp_attached_file', true))) === $name) {
                return $cache[$key] = (int) $id;
            }
        }
    }

    return 0;
}

/**
 * 由文件名生成可读的 alt
 *
 * 媒体库没填「Alternative Text」、图片又没登记成附件时用它兜底：
 * 2026/11/cnc-machining-center.png → CNC Machining Center
 *
 * @param string $image
 * @return string
 */
function tpi_media_alt_from_name(string $image): string {
    $path = (string) preg_replace('/[?#].*$/', '', trim($image));
    if ($path === '') {
        return '';
    }
    $name = (string) preg_replace('/\.(png|jpe?g|webp|avif|gif|svg)$/i', '', basename($path));
    $name = (string) preg_replace('/-(?:scaled|\d+x\d+)$/i', '', $name);
    $name = (string) preg_replace('/-[0-9a-f]{6}$/i', '', $name); // 取色后缀 -RRGGBB

    $known = [
        'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español',
        'linkedin' => 'LinkedIn', 'x-social' => 'X', 'whatsapp' => 'WhatsApp', 'youtube' => 'YouTube',
    ];
    $key = strtolower($name);
    if (isset($known[$key])) {
        return $known[$key];
    }

    $acronyms = ['cnc', 'faq', 'seo', 'api', 'pdf', 'iso', 'cad', 'cam', 'qa', 'led'];
    $words    = [];
    foreach (preg_split('/[-_\s+]+/', $name) ?: [] as $word) {
        if ($word === '') {
            continue;
        }
        $lower = strtolower($word);
        if ($lower === 'x') {
            $words[] = 'X';
        } elseif (in_array($lower, $acronyms, true)) {
            $words[] = strtoupper($word);
        } else {
            $words[] = ucfirst($lower);
        }
    }

    return trim(implode(' ', $words));
}

/**
 * 获取图片 alt
 *
 * 取值顺序：媒体库「Alternative Text」→ 调用方给的 $fallback → 文件名生成的可读文本。
 * 入参兼容附件 ID、绝对地址、站点根相对、上传目录相对、纯文件名；
 * 换域名 / 走 CDN 也能查到（先归一成上传目录相对路径，再比 `_wp_attached_file`）。
 *
 * @param mixed  $image
 * @param string $fallback 取不到 alt 时的兜底文案（一般传标题）
 * @return string
 */
function get_image_alt($image, string $fallback = ''): string {
    static $cache = [];

    $key = is_scalar($image) ? (string) $image : '';
    $ck  = $key . '|' . $fallback;
    if ($key !== '' && array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $alt = '';
    $id  = tpi_media_attachment_id($image);

    if ($id > 0) {
        $alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
        // 附件存在但没填 alt：先用调用方给的标题，再退回附件文件名（不是传进来的 ID 数字）
        if ($alt === '') {
            $alt = trim($fallback);
        }
        if ($alt === '') {
            $alt = tpi_media_alt_from_name((string) get_post_meta($id, '_wp_attached_file', true));
        }
    } else {
        $alt = trim($fallback);
        if ($alt === '' && is_scalar($image)) {
            $alt = tpi_media_alt_from_name((string) $image);
        }
    }

    // 主题的输出缓冲会整页 html_entity_decode（inc/output/prefix-replace.php），
    // esc_attr 转义过的引号会被还原，alt 里带双引号会把 img 标签截断；
    // 这里提前把引号换成撇号、去掉尖括号，保证输出永远是合法属性值
    $alt = str_replace(['"', '<', '>'], ["'", '', ''], $alt);

    $cache[$ck] = $alt;
    return $alt;
}

/**
 * 只准许项目域名下图片获取宽高信息
 * @param string $filename
 * @return array|false
 */
function custom_getimagesize(string $filename): array|false {
    global $old_host, $new_host;
    if (!(($old_host && str_starts_with($filename, $old_host)) || ($new_host && str_starts_with($filename, $new_host)))) {
        return false;
    }

    // 本站图片落盘就在服务器上：直接读文件。以前把 URL 直接丢给 getimagesize()，
    // 单进程 PHP（php-cgi / php -S）会「请求自己」卡满 default_socket_timeout（60s）才返回，
    // 表现就是首页偶发一分钟才出来。
    $size = tpi_image_size_local($filename);
    return $size !== null ? $size : false;
}

if (!function_exists('tpi_image_size_local')) {
    /**
     * 取本站图片宽高：优先按附件 / 上传目录换算本地文件，取不到返回 null
     *
     * @param string $url 本站图片地址（可带 -WxH 尺寸后缀）
     * @return array{0:int,1:int}|null
     */
    function tpi_image_size_local(string $url): ?array
    {
        if ($url === '') {
            return null;
        }
        $uploads = wp_get_upload_dir();
        $baseurl = (string) ($uploads['baseurl'] ?? '');
        $basedir = (string) ($uploads['basedir'] ?? '');

        $candidates = [$url];
        $no_size = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $url);
        if (is_string($no_size) && $no_size !== $url) {
            $candidates[] = $no_size;
        }

        foreach ($candidates as $candidate) {
            $file = '';
            $id   = (int) attachment_url_to_postid($candidate);
            if ($id > 0) {
                $file = (string) get_attached_file($id);
            }
            if (($file === '' || !is_file($file)) && $baseurl !== '' && str_starts_with($candidate, $baseurl)) {
                $file = str_replace($baseurl, $basedir, $candidate);
            }
            if ($file !== '' && is_file($file)) {
                $size = @getimagesize($file);
                if (is_array($size)) {
                    return [(int) $size[0], (int) $size[1]];
                }
            }
        }
        return null;
    }
}

if (!function_exists('tpi_ajax_media_lookup')) {
    /**
     * 后台：图片地址 → 附件 ID
     *
     * 后台的媒体选择器只存了 URL；要让媒体库弹出时「锁定当前这张图」，
     * 得先拿到附件 ID 才能在建好 frame 后预选。
     *
     * POST url + nonce（tpi_media_lookup）
     */
    function tpi_ajax_media_lookup(): void
    {
        check_ajax_referer('tpi_media_lookup', 'nonce');

        if (!current_user_can('upload_files') && !current_user_can('edit_posts')) {
            wp_send_json_error(['id' => 0], 403);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash((string) $_POST['url'])) : '';
        if ($url === '') {
            wp_send_json_error(['id' => 0]);
        }

        $id = function_exists('tpi_attachment_id_from_url')
            ? (int) tpi_attachment_id_from_url($url)
            : (int) attachment_url_to_postid($url);
        if ($id <= 0) {
            wp_send_json_error(['id' => 0]);
        }

        wp_send_json_success([
            'id'  => $id,
            'url' => (string) wp_get_attachment_url($id),
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ]);
    }
}
add_action('wp_ajax_tpi_media_lookup', 'tpi_ajax_media_lookup');
