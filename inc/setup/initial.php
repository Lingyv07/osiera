<?php
// 资源版本号：全站 CSS/JS 的 ?ver= 都用它，值在后台「Osiera → 资源更新」里改，
// 没设过时是 1.0.0（tpi_asset_version()，见 inc/admin/asset-manager.php）
$version = tpi_asset_version();
// 站点标题
$site_name = get_bloginfo('name');
// 当前route
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// 其他语言列表（默认语言之外，顺序跟后台「语言」页一致）
$language_list = tpi_language_slugs_other();
$default_language = tpi_default_language();
// 当前使用语言
$lang = get_regexp_lang($_SERVER['REQUEST_URI']);
$pll_lang = tpi_current_language();
$pll_locale = tpi_current_locale();
// 域名链接（CLI / 部分 FastCGI 环境没有 REQUEST_SCHEME，按 is_ssl() 兜底，避免 Notice）
$host_url = (($_SERVER['REQUEST_SCHEME'] ?? '') ?: (is_ssl() ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$custom_logo_id = get_theme_mod('custom_logo'); // 获取 logo 附件 ID
// 主题logo
$logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';
$site_icon_id = get_option('site_icon'); // 获取 Site Icon 的 attachment ID
$icon_url = $site_icon_id ? wp_get_attachment_image_url($site_icon_id, 'full') : '';
$icon_size = [50, 50];
if ($icon_url) {
    // 站点图标在本机：直接读磁盘路径。以前把 URL 丢给 getimagesize()，
    // 单进程 PHP（php-cgi / php -S）会请求自己，卡满 default_socket_timeout 才返回。
    $icon_file = '';
    $icon_id   = (int) attachment_url_to_postid($icon_url);
    if ($icon_id > 0) {
        $icon_file = (string) get_attached_file($icon_id);
    }
    if ($icon_file === '' || !is_file($icon_file)) {
        $uploads   = wp_get_upload_dir();
        $icon_file = str_replace((string) ($uploads['baseurl'] ?? ''), (string) ($uploads['basedir'] ?? ''), $icon_url);
    }
    if ($icon_file !== '' && is_file($icon_file)) {
        $size = @getimagesize($icon_file);
        if (is_array($size)) {
            $icon_size = $size;
        }
    }
}
// 当前使用语言 去除默认
$current_lang = get_regexp_lang($_SERVER['REQUEST_URI'], false);
// 域名前前置
$host_url_from = $host_url. '/'. ($current_lang ? $current_lang. '/' : '');
// 所有语言映射列表（后台「语言」页维护，国旗取上传图 / 主题内置 svg）
$language_map_list = [];
foreach (tpi_languages() as $language_item) {
    $language_icon_url = (string) ($language_item['flag_url'] ?? '');
    $language_icon = '';
    // 站内相对路径才内联 svg 源码，外链图只给 URL
    if ($language_icon_url !== '' && strpos($language_icon_url, 'http') !== 0) {
        $language_icon_file = THEME_DIR . ltrim($language_icon_url, '/');
        if (is_file($language_icon_file)) {
            $language_icon = (string) file_get_contents($language_icon_file);
        }
    }
    $language_map_list[] = [
        'lang'     => $language_item['slug'],
        'text'     => $language_item['name'],
        'icon'     => $language_icon,
        'icon_url' => $language_icon_url,
    ];
}
// 页面名称
$custom_page_list = [
    "home", "products", "news", "contact", "about-us", "applications", "question", "privacy-policy", "certificates"
];
// 文章类型页面
$post_page_list = ['product', 'application', 'faq'];
// 文章类分类页面，作用页面列表判断
$article_page_list = [
    "products" => "page/products.php",
    "news" => "page/news.php",
    "applications" => "page/applications.php",
    "question" => "page/question.php",
    "certificates" => "page/certificates.php",
];
// 用于活跃菜单匹配
$article_page_map_uri_list = [
    "product" => "/products",
    "application" => "/applications",
    "faq" => "/question",
    "post" => "/news",
];
// 主题默认菜单「不加入」的页面（只影响建菜单和新页面自动追加，不影响渲染）
$exclude_menu_list = function_exists('tpi_default_menu_excluded_slugs')
    ? tpi_default_menu_excluded_slugs()
    : ['privacy-policy'];
// 多语言内容， 关联$language_list
$i18n_theme_page = [];
// 页面路径关联
$custom_page_array = [
    "/" => "page/home.php",
    "/products" => "page/products.php",
    "/news" => "page/news.php",
    "/contact" => "page/contact.php",
    "/about-us" => "page/about-us.php",
    "/applications" => "page/applications.php",
    "/question" => "page/question.php",
    "/privacy-policy" => "page/privacy-policy.php",
];
// 网站信息设置（按语言独立保存，后台「页面信息设置」维护；当前语言没填的项回退默认语言）
$page_info_settings = tpi_get_page_info_settings($lang, $default_language);
$page_info_settings['whats_app_number'] = preg_replace('/\D/', '', $page_info_settings['whats_app'] ?? '');

// 社媒信息列表（页面信息设置 → 社媒与地图）
$media_info_list = tpi_page_info_media_list($page_info_settings);
$all_pages = get_posts([
    'post_type' => 'page',
    'posts_per_page' => -1, // 获取全部页面
    'post_status' => 'publish',
]);
// 当前语言菜单列表
$pages_by_lang = [];
foreach ($all_pages as $page) {
    $pages_by_lang[] = [
        'ID'    => $page->ID,
        'title' => get_the_title($page->ID),
        'url'   => get_permalink($page->ID),
    ];
}

// 默认图片（页面信息设置 → 默认图片，1:1 方图）
$default_image = [];
foreach (['post', 'new', 'product', 'application', 'faq'] as $default_image_type) {
    $default_image_value = trim((string) ($page_info_settings['default_image_'. $default_image_type] ?? ''));
    // 站内相对路径补成完整地址，方便 JSON-LD / RSS 输出
    if ($default_image_value !== '' && str_starts_with($default_image_value, '/')) {
        $default_image_value = $host_url. $default_image_value;
    }
    $default_image[$default_image_type] = $default_image_value;
}
unset($default_image_type, $default_image_value);

// 多语言加入页面
foreach ($language_list as $item_lang) {
    foreach ($custom_page_list as $item_page) {
        $custom_page_array["/" . $item_lang . "/" . $item_page] = "page/" . $item_page . ".php";
    }
}

/**
 * 获取url中前置语言
 * @param $final_url
 * @param bool $return_def_lang // 返回默认语言吗
 * @return string 返回语言类型
 */
function get_regexp_lang($final_url, bool $return_def_lang = true): string {
    global $default_language;
    // 只看 URL 第一段是不是语言代码：/ja/home、/ja/、/ja/?s=x 都算日语。
    // 原来用 ^/([a-z]{2})/ 的正则，遇到 /ja/ 或 /ja 这种没有后续路径的地址
    // 会退回默认语言，导致页面上的语言切换器又跳回英文。
    $path  = (string) parse_url($final_url, PHP_URL_PATH);
    $parts = explode('/', trim($path, '/'));
    $first = strtolower((string) ($parts[0] ?? ''));
    if ($first !== '' && in_array($first, tpi_language_slugs(), true) && $first !== $default_language) {
        return $first;
    }
    return $return_def_lang ? $default_language : ""; // 没找到使用默认语言
}

/**
 * 分类路由拆解
 * @return array
 */
function routeCategoryParse(): array {
    global $language_list, $path;
    $route = $path;
    // 去掉两边的斜杠并拆分
    $parts = explode('/', trim($route, '/'));
    $lang_as = "";

    // 如果第一个段落在语言列表里，就认定它是语言
    if (!empty($parts) && in_array($parts[0], $language_list)) {
        $lang_as = array_shift($parts);  // 取出语言
    }

    return [
        "lang" => $lang_as,
        "route" => $parts[0] ?? "",
        "category" => $parts[1] ?? "",
    ];
}

/**
 * 路由指向对应页面
 * @return string|null
 */
function routeCallPage(): string | null {
    global $article_page_list;
    $routeInfo = routeCategoryParse();
    foreach ($article_page_list as $parentRoute => $routePath) {
        if ($routeInfo["route"] === $parentRoute) {
            return $routePath;
        }
    }
    return null;
}

/**
 * 切割分类语言
 * @param $slug
 * @return mixed|string
 */
function spliceSlugLang($slug): mixed {
    global $language_list;
    foreach ($language_list as $code) {
        $suffix = '-' . $code;
        if (str_ends_with($slug, $suffix)) {
            // 去掉语言后缀，保留完整分类
            return substr($slug, 0, -strlen($suffix));
        }
    }
    // 默认返回原 slug
    return $slug;
}

// 多语言内容：存在语言表 wp_tpi_languages 的 content 列里（后台「语言内容」维护），
// 默认值与缺失字段的兜底都来自 data-init/data/lang-content.json（按语言取那一份）
$i18n_theme_page = [];

/**
 * 获取语言数据
 *
 * 取数顺序：系统模板 → 该语言自己的数据 → 默认语言（空值兜底），
 * 所以删掉某个字段、或者新加的语言还没翻译，前台都不会报错。
 *
 * @param string|null $use_lang
 * @return array
 */
function get_lang_json(?string $use_lang = null): array {
    global $i18n_theme_page, $default_language, $lang;
    $lang_as = $use_lang ?: $lang;
    if ($lang_as === '' || $lang_as === null) {
        $lang_as = $default_language;
    }
    if (isset($i18n_theme_page[$lang_as])) {
        return $i18n_theme_page[$lang_as];
    }
    $data = tpi_lang_content_get($lang_as);
    $i18n_theme_page[$lang_as] = $data;
    return $data;
}
$current_lang_json = get_lang_json();
$current_lang_json['footer']['brand'] = $site_name ?: $current_lang_json['footer']['brand'];
$brand_name = $current_lang_json['footer']['brand'];

/**
 * 获取语言信息
 * @param string|null $use_lang
 * @return array
 */
function get_lang_info(?string $use_lang = null): array {
    global $language_map_list, $lang;
    $lang_as = $lang;
    if ($use_lang) {
        $lang_as = $use_lang;
    }
    return arr_shallow_find_item($language_map_list, "lang", $lang_as);
}
$current_lang_info = get_lang_info();

/**
 * 一维数组查找对应数据
 * @param array $list
 * @param string $key
 * @param string $value
 * @return array|null
 */
function arr_shallow_find_item(array $list, string $key,string $value): ?array {
    foreach ($list as $item) {
        if ($item[$key] === $value) {
            return $item;
        }
    }
    return null; // 没找到返回 null
}

/**
 * 当前语言下路径返回
 * @param $url
 * @param string|null $use_lang
 * @return string
 */
function go_url($url, ?string $use_lang = null): string {
    global $host_url, $language_list, $lang;
    $lang_as = $lang;
    if ($use_lang) {
        $lang_as = $use_lang;
    }
    // 去掉开头的斜杠，防止双斜杠
    $url = ltrim($url, '/');
    if ($url === "home" && !in_array($lang_as, $language_list, true)) {
        $url = ""; // 主语言首页
    }
    $uri = (in_array($lang_as, $language_list, true) ? "/". $lang_as. "/" : "/"). $url;
    return $host_url. $uri;
}

/**
 * 返回路径
 * @param string $path
 * @return string
 */
function get_url(string $path): string {
    global $host_url_from, $current_lang;
    $path = ltrim($path, '/');
    if ($path === 'home' && !$current_lang) {
        $path = "";
    }
    return $host_url_from. ($path ?: '');
}

// 谷歌地图地址（页面信息设置 → 社媒与地图）
$google_map_url = trim((string) ($page_info_settings['google_map_url'] ?? ''));
$google_map_src = trim((string) ($page_info_settings['google_map_src'] ?? ''));
// WhatsApp 地址：优先用设置里的完整链接；留空时由号码 + 默认消息生成
$whatsapp_url = trim((string) ($page_info_settings['whatsapp_url'] ?? ''));
$whatsapp_text = trim((string) ($page_info_settings['whatsapp_text'] ?? ''));
if ($whatsapp_url === '' && ($page_info_settings['whats_app_number'] ?? '') !== '') {
    $whatsapp_url = 'https://api.whatsapp.com/send?phone='. $page_info_settings['whats_app_number'];
    if ($whatsapp_text !== '') {
        $whatsapp_url .= '&text='. rawurlencode($whatsapp_text);
    }
}

// CDN 回源时，CDN 会在请求头里带 X-Forwarded-Host（访客实际用的域名）；
// 直连源站时没有这个头，一切按访问域名来。
$cdn_host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? false;
$host_scheme = (string) ($_SERVER['REQUEST_SCHEME'] ?? '');
if ($host_scheme === '') {
    $host_scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
}
$old_host = $host_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$new_host = $cdn_host ? $host_scheme . '://' . $cdn_host : false;

// 当前访问域名（走 CDN 时 X-Forwarded-Host 就是 CDN 域名）
$site_host = $new_host ?: $old_host;

// 目标源（canonical / JSON-LD / IndexNow 用）：
//   1. 后台「站点与路径 → 目标源」填了 target_host，就以它为准；
//   2. 没填就直接用当前访问域名，不做任何替换。
// 不再按访问域名自己补 www / 取顶级域名 —— 那套只有「线上固定 www」才成立，
// 本地 IP 会推成 http://1.0 这种死链，新项目也会无端被改成 www 版本。
$configured_target = function_exists('tpi_target_host') ? tpi_target_host() : '';
$main_host = $configured_target !== '' ? rtrim($configured_target, '/') : $site_host;
// 纯主机名（去协议、去路径）：IndexNow 的 host 字段、接口兜底都用它
$main_domain = (string) preg_replace('#^https?://#i', '', $main_host);
$main_domain = (string) preg_replace('~[/?#].*$~', '', $main_domain);

// JSON_LD 数据
$json_ld_data = null;

// 文档内地址（JSON-LD、og、面包屑等）统一用「当前访问域名」：
// 走 CDN 时它就是 X-Forwarded-Host 带过来的 CDN 域名，
// inc/output/cdn-host.php 的输出缓冲还会把残留的源站域名整体换掉。
// 唯一的例外是 <link rel="canonical"> —— 它不参与替换，始终用 $main_host（目标源）。
// $site_host 已在上面算出，这里不再重复计算。

// 主顶级路径（JSON-LD 的 WebSite/Organization @id 用它）
$current_top_host = $site_host. '/';
// 多语言下的顶级url
$current_top_url = $site_host. (($lang === $default_language) ? '/' : '/'. $lang. '/');
// 当前页面地址：JSON-LD 的 @id / url / breadcrumb 都用它
$current_url = $site_host. $_SERVER['REQUEST_URI'];
// 页面title标签内容
$title_name = '';
// <meta name="description"> 内容
$description_content = '';
// 证书信息
$has_credential = json_decode($page_info_settings['certificates_json'] ?? '', true);