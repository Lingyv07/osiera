<?php
/**
 * 后台信息系统配置（languages/admin-system-config-{zh|en}.json）
 *
 * 这个文件只管「后台长什么样、默认值是多少」，跟语言包内容无关：
 *
 *   meta.groups     语言内容页的分组归类
 *   meta.sections   分组名
 *   meta.fields     字段标签（a.b.c 路径）
 *   meta.tags       这些字段用「输入即生成标签块」的形式编辑
 *   meta.files      这些字段用「媒体库选文件」的形式编辑
 *   meta.shapes     列表项的字段结构（语言包模板里不再放示例内容）
 *   meta.ui         后台界面上自己用到的按钮 / 提示文案
 *   settings        页面信息 / SMTP / IndexNow / 资源 的默认值
 *   slogans         后台各页面的提示条
 *   slogan_labels   提示条在「后台标语」页里显示的名字
 *
 * 两份配置（中文 / 英文）键结构必须完全一致，靠语言切换：
 *   WordPress 站点或用户语言是 zh_* → admin-system-config-zh.json
 *   其它语言 → admin-system-config-en.json
 *
 * 语言包（前台文案）在 data-init/data/lang-content.json（英 / 日各一份默认值），两者互不干扰。
 */

if (!function_exists('tpi_admin_config_lang')) {
    /**
     * 后台配置该用哪种语言：中文站点用 zh，其余一律 en
     *
     * 后台优先跟「用户语言」（个人资料里设的语言），没有登录态时退回站点语言。
     * 需要强制某一种可以挂 filter：add_filter('tpi_admin_config_lang', fn() => 'en');
     */
    function tpi_admin_config_lang(): string
    {
        // 调试 / 排障用：在 wp-load 之前 define('TPI_ADMIN_CONFIG_LANG', 'en') 可强制语言
        if (defined('TPI_ADMIN_CONFIG_LANG')) {
            return TPI_ADMIN_CONFIG_LANG === 'zh' ? 'zh' : 'en';
        }

        static $lang = null;
        if ($lang !== null) {
            return $lang;
        }

        $locale = '';
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_user_locale')) {
            $locale = (string) get_user_locale();
        }
        if ($locale === '') {
            $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        }

        $lang = stripos($locale, 'zh') === 0 ? 'zh' : 'en';
        $lang = (string) apply_filters('tpi_admin_config_lang', $lang);

        return $lang === 'zh' ? 'zh' : 'en';
    }
}

if (!function_exists('tpi_admin_config_path')) {
    /**
     * 当前语言对应的配置文件路径
     *
     * 顺序：languages/admin-system-config-{lang}.json → 另一种语言 → 主题根目录旧文件
     * 这样只放一份文件也能正常跑，不会因为缺文件把后台变成空白。
     */
    function tpi_admin_config_path(): string
    {
        static $path = null;
        if ($path !== null) {
            return $path;
        }

        $lang = tpi_admin_config_lang();
        $dir  = THEME_DIR . 'languages/';

        foreach ([$lang, $lang === 'zh' ? 'en' : 'zh'] as $try) {
            $file = $dir . 'admin-system-config-' . $try . '.json';
            if (is_file($file)) {
                return $path = $file;
            }
        }

        $legacy = THEME_DIR . 'admin-system-config.json';
        if (is_file($legacy)) {
            return $path = $legacy;
        }

        return $path = $dir . 'admin-system-config-' . $lang . '.json';
    }
}

if (!function_exists('tpi_admin_config')) {
    /**
     * 读后台配置
     *
     * @param string $key 顶层键，空表示整份配置
     * @param mixed  $default
     * @return mixed
     */
    function tpi_admin_config(string $key = '', $default = [])
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $file  = tpi_admin_config_path();
            if (is_file($file)) {
                $json = json_decode((string) file_get_contents($file), true);
                if (is_array($json)) {
                    $cache = $json;
                }
            }
        }
        if ($key === '') {
            return $cache;
        }
        return array_key_exists($key, $cache) && is_array($cache[$key]) ? $cache[$key] : $default;
    }
}

if (!function_exists('tpi_admin_config_list')) {
    /** 取 meta 里的一个字符串列表（tags / files） */
    function tpi_admin_config_list(string $name): array
    {
        $meta = tpi_admin_config('meta');
        $list = $meta[$name] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}

if (!function_exists('tpi_admin_config_has')) {
    /** 某个字段路径（分组.path）是否在 tags / files 列表里 */
    function tpi_admin_config_has(string $name, string $path): bool
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $cache[$name] = array_flip(tpi_admin_config_list($name));
        }
        return isset($cache[$name][$path]);
    }
}

if (!function_exists('tpi_admin_shape')) {
    /**
     * 列表项的字段结构（meta.shapes，键是「分组.路径」）
     * 语言包模板里不再放示例内容，新增一项时靠这里决定有哪些字段。
     */
    function tpi_admin_shape(string $path): array
    {
        $meta   = tpi_admin_config('meta');
        $shapes = isset($meta['shapes']) && is_array($meta['shapes']) ? $meta['shapes'] : [];
        $shape  = $shapes[$path] ?? [];
        return is_array($shape) ? $shape : [];
    }
}

if (!function_exists('tpi_admin_ui')) {
    /**
     * 后台界面文案
     *
     * 唯一真源是 languages/admin-system-config-{lang}.json 的 meta.ui：
     *   'languages.title' → ui.languages.title
     * 兼容早期的扁平静态（'list-drag' → ui['list-drag']）。
     *
     * 第二个参数只为兼容旧调用保留，新代码不要传 —— 文案只写在 JSON 里。
     */
    function tpi_admin_ui(string $key, string $default = ''): string
    {
        static $ui = null;
        if ($ui === null) {
            $meta = tpi_admin_config('meta');
            $ui = isset($meta['ui']) && is_array($meta['ui']) ? $meta['ui'] : [];
        }
        if (isset($ui[$key]) && is_string($ui[$key])) {
            return $ui[$key] !== '' ? $ui[$key] : $default;
        }
        $node = $ui;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return is_string($node) && $node !== '' ? $node : $default;
    }
}
