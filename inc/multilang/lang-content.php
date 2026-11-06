<?php
/**
 * 语言包：系统模板 + 读取 / 写入 / 合并
 *
 * 存储本体在 inc/multilang/lang-store.php（{prefix}osiera_languages + {prefix}osiera_language_data），
 * 这个文件只负责：
 *   - 读 data-init/data/lang-content.json（各语言的默认文案，结构与默认值都在这儿）
 *   - 三层合并：该语言默认值 → 该语言库内数据 → 默认语言兜底
 *   - 后台「语言内容」页需要的分组 / 字段名 / 统计
 *
 * 默认值文件与初始化数据同源：初始化时按它把英 / 日两套文案写进库，
 * 运行时又拿它当兜底，所以「新语言」不用预写库也能直接显示该语言的默认文案。
 *
 * @package Osiera
 */

/* =========================================================
 * 一、系统模板（按语言）
 * ========================================================= */

if (!function_exists('tpi_lang_template_cache_path')) {
    /**
     * 默认文案的本地缓存：新项目不带 data-init 时，从 init-config.json 指向的
     * 线上目录取一次，落到 uploads/osiera-cache/，之后照常当本地文件读
     */
    function tpi_lang_template_cache_path(): string
    {
        $uploads = wp_upload_dir(null, false);
        $dir     = trailingslashit((string) ($uploads['basedir'] ?? ''));
        // 固定文件名：主题加载早期取不到版本号，带版本会写出两个名字、缓存永远不命中
        return $dir . 'osiera-cache/lang-content.json';
    }
}

if (!function_exists('tpi_lang_template_path')) {
    /**
     * 默认语言内容的位置（主题根 default-lang-content.json）
     *
     * 一份文件同时承担两件事：声明全部分组与字段，并给出未导入数据时的中性文案。
     * 不含任何行业、品牌、公司或产品内容，与 data-init 的示例数据是两回事。
     *
     * 老版本主题没有这个文件时才退回 data-init 的示例数据，避免整站字段缺失。
     */
    function tpi_lang_template_path(): string
    {
        static $path = null;
        if (is_string($path)) {
            return $path;
        }
        $file = THEME_DIR . 'default-lang-content.json';
        if (is_file($file) && filesize($file) > 0) {
            return $path = $file;
        }
        $local = THEME_DIR . 'data-init/data/lang-content.json';
        return $path = (is_file($local) && filesize($local) > 0) ? $local : '';
    }
}

if (!function_exists('tpi_lang_template_all')) {
    /**
     * 读默认值文件的原始数组：['en' => [17 个分组]]
     *
     * 通常只维护一份（默认语言）；文件里顶层键是语言代码，万一换成只有一组文案的
     * 旧格式（顶层键是 header/home…），自动当成「默认语言」那一份，不影响老站。
     */
    function tpi_lang_template_all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $file  = tpi_lang_template_path();
        if (!is_file($file)) {
            return $cache;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json) || !$json) {
            return $cache;
        }
        $per_lang = true;
        foreach (array_keys($json) as $key) {
            if (!preg_match('/^[a-z]{2,5}$/', (string) $key)) {
                $per_lang = false;
                break;
            }
        }
        if ($per_lang) {
            $cache = $json;
        } else {
            $lang  = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
            $cache = [$lang => $json];
        }
        return $cache;
    }
}

if (!function_exists('tpi_lang_template')) {
    /**
     * 取某个语言的默认文案（纯语言包，后台信息在 languages/admin-system-config-{zh|en}.json）
     *
     * @param string $lang 语言代码，空=默认语言；该语言没有默认值时回落默认语言
     */
    function tpi_lang_template(string $lang = ''): array
    {
        $all     = tpi_lang_template_all();
        $default = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
        $lang    = strtolower(trim($lang));
        if ($lang === '') {
            $lang = $default;
        }
        if (!empty($all[$lang]) && is_array($all[$lang])) {
            return $all[$lang];
        }
        if (isset($all[$default]) && is_array($all[$default])) {
            return $all[$default];
        }
        // 文件里只有一份语言时（默认语言代码与它对不上），直接用唯一那一份，别返回空
        foreach ($all as $one) {
            if (is_array($one) && $one) {
                return $one;
            }
        }
        return [];
    }
}

if (!function_exists('tpi_lang_template_strings')) {
    /** 模板里真正的语言包部分（去掉 __ 开头的元信息） */
    function tpi_lang_template_strings(string $lang = ''): array
    {
        $out = [];
        foreach (tpi_lang_template($lang) as $key => $value) {
            if (strpos((string) $key, '__') === 0) {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}

if (!function_exists('tpi_lang_template_languages')) {
    /** 默认值文件里带了哪几种语言（后台「套用默认文案」只对这里有的语言有效） */
    function tpi_lang_template_languages(): array
    {
        return array_keys(tpi_lang_template_all());
    }
}

if (!function_exists('tpi_lang_demo_path')) {
    /**
     * 示例文案文件的位置（data-init/data/lang-content.json）
     *
     * 只在「套用默认文案」这类明确动作里用。运行时兜底不走它，
     * 免得没导入数据的站点凭空出现某个行业的示例内容。
     * 本地没有时按 init-config.json 取一次并缓存到 uploads，逻辑与原来一致。
     */
    function tpi_lang_demo_path(): string
    {
        static $path = null;
        if (is_string($path)) {
            return $path;
        }
        $local = THEME_DIR . 'data-init/data/lang-content.json';
        if (is_file($local) && filesize($local) > 0) {
            return $path = $local;
        }
        $cache = tpi_lang_template_cache_path();
        if (is_file($cache) && filesize($cache) > 0 && time() - (int) filemtime($cache) < 7 * DAY_IN_SECONDS) {
            return $path = $cache;
        }
        if (get_transient('tpi_lang_demo_miss')) {
            return $path = (is_file($cache) && filesize($cache) > 0) ? $cache : '';
        }
        $stale = (is_file($cache) && filesize($cache) > 0) ? $cache : '';

        if (!function_exists('tpi_data_init_fetch')) {
            $data_init = dirname(__DIR__) . '/setup/data-init.php';
            if (is_file($data_init)) {
                require_once $data_init;
            }
        }
        if (function_exists('tpi_data_init_fetch')) {
            $tmp = null;
            $src = tpi_data_init_fetch('data/lang-content.json', $tmp);
            if ($src !== '' && is_file($src)) {
                wp_mkdir_p(dirname($cache));
                if (@copy($src, $cache)) {
                    if (is_string($tmp) && is_file($tmp)) {
                        @unlink($tmp);
                    }
                    delete_transient('tpi_lang_demo_miss');
                    return $path = $cache;
                }
                delete_transient('tpi_lang_demo_miss');
                return $path = $src;
            }
        }
        set_transient('tpi_lang_demo_miss', 1, 300);
        return $path = $stale;
    }
}

if (!function_exists('tpi_lang_demo_all')) {
    /** 示例文案原始数组：['en' => [...], 'ja' => [...]] */
    function tpi_lang_demo_all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $file  = tpi_lang_demo_path();
        if ($file === '') {
            return $cache;
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (!is_array($json) || !$json) {
            return $cache;
        }
        $per_lang = true;
        foreach (array_keys($json) as $key) {
            if (!preg_match('/^[a-z]{2,5}$/', (string) $key)) {
                $per_lang = false;
                break;
            }
        }
        if ($per_lang) {
            $cache = $json;
        } else {
            $lang  = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
            $cache = [$lang => $json];
        }
        return $cache;
    }
}

if (!function_exists('tpi_lang_demo_strings')) {
    /** 某语言的示例文案（该语言没有时回落默认语言；完全没有则返回空） */
    function tpi_lang_demo_strings(string $lang = ''): array
    {
        $all     = tpi_lang_demo_all();
        $default = function_exists('tpi_default_language') ? tpi_default_language() : 'en';
        $lang    = strtolower(trim($lang));
        if ($lang === '') {
            $lang = $default;
        }
        if (!empty($all[$lang]) && is_array($all[$lang])) {
            return $all[$lang];
        }
        return isset($all[$default]) && is_array($all[$default]) ? $all[$default] : [];
    }
}

if (!function_exists('tpi_lang_template_meta')) {
    /** 后台界面用的分组/字段标签（存在 languages/admin-system-config-{zh|en}.json 的 meta 里） */
    function tpi_lang_template_meta(): array
    {
        $meta = tpi_admin_config('meta');
        return is_array($meta) ? $meta : [];
    }
}

if (!function_exists('tpi_lang_template_setting')) {
    /**
     * 取后台配置 settings 里的默认设置，支持 a.b 路径
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function tpi_lang_template_setting(string $key, $default = null)
    {
        $node = tpi_admin_config('settings');
        if (!is_array($node)) {
            return $default;
        }
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}

/* =========================================================
 * 二、合并工具
 * ========================================================= */

if (!function_exists('tpi_lang_deep_merge')) {
    /** 递归合并：$override 覆盖 $base；列表整体替换 */
    function tpi_lang_deep_merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !tpi_is_list($value)) {
                $base[$key] = tpi_lang_deep_merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

if (!function_exists('tpi_lang_fill_blank')) {
    /** 把空值补成兜底数据（数组按 key 递归） */
    function tpi_lang_fill_blank(array $data, array $fallback): array
    {
        foreach ($fallback as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
                continue;
            }
            $current = $data[$key];
            if (is_array($value) && is_array($current)) {
                $data[$key] = tpi_lang_fill_blank($current, $value);
                continue;
            }
            if (is_array($current)) {
                if (!$current) {
                    $data[$key] = $value;
                }
                continue;
            }
            if (!is_array($value) && trim((string) $current) === '') {
                $data[$key] = $value;
            }
        }
        return $data;
    }
}

if (!function_exists('tpi_lang_normalize_data')) {
    /** 规范化提交上来的数组：纯数字键的顺序重排，保持编辑器里的先后 */
    function tpi_lang_normalize_data(array $data): array
    {
        if (tpi_is_list($data)) {
            $out = [];
            foreach ($data as $item) {
                $out[] = is_array($item) ? tpi_lang_normalize_data($item) : $item;
            }
            return $out;
        }
        $keys = array_keys($data);
        $numeric = $keys === array_values(array_filter($keys, 'is_int'));
        if ($numeric) {
            ksort($data, SORT_NUMERIC);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = tpi_lang_normalize_data($value);
            }
        }
        if ($numeric) {
            $data = array_values($data);
        }
        return $data;
    }
}

/* =========================================================
 * 三、语言包读取
 * ========================================================= */

if (!function_exists('tpi_lang_content_raw')) {
    /** 某个语言自己存的数据（不合并模板） */
    function tpi_lang_content_raw(string $code): array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return [];
        }
        $rev   = tpi_lang_content_rev();
        $store = $GLOBALS['tpi_lang_content_raw'] ?? [];
        if (isset($store[$rev][$code])) {
            return $store[$rev][$code];
        }

        $data = [];
        $lang_id = tpi_language_id($code);
        if ($lang_id > 0) {
            $data = tpi_data_unflatten(tpi_data_rows($lang_id, 'string'));
        }

        $store[$rev][$code] = $data;
        $GLOBALS['tpi_lang_content_raw'] = $store;
        return $data;
    }
}

if (!function_exists('tpi_lang_content_rows')) {
    /**
     * 某个语言的原始分组数据（有默认值文件时只保留它里面存在的分组）
     *
     * 默认值文件（data-init / fallback）都删掉时不能再按它过滤：
     * array_intersect_key 会把整份库内数据滤成空，前台全变成 undefined key。
     * 那种情况以库为准，有什么用什么。
     */
    function tpi_lang_content_rows(string $lang): array
    {
        $rows     = tpi_lang_content_raw($lang);
        $template = tpi_lang_template_strings($lang);
        if (!$template) {
            return $rows;
        }
        return array_intersect_key($rows, $template);
    }
}

if (!function_exists('tpi_lang_content_get')) {
    /**
     * 取某语言的完整语言包（该语言默认值 → 该语言库内数据 → 默认语言 三层合并）
     */
    function tpi_lang_content_get(string $lang): array
    {
        $lang = strtolower(trim($lang));
        if ($lang === '') {
            $lang = tpi_default_language();
        }
        $cache_key = tpi_lang_content_rev();
        $store = $GLOBALS['tpi_lang_content_merged'] ?? [];
        if (isset($store[$cache_key][$lang])) {
            return $store[$cache_key][$lang];
        }

        // 默认值（default-lang-content.json，结构 + 中性文案）→ 库内数据（导入或手工维护），后一层覆盖前一层
        $template = tpi_lang_template_strings($lang);
        $result   = tpi_lang_deep_merge($template, tpi_lang_content_rows($lang));

        $default = tpi_default_language();
        if ($lang !== $default) {
            $fallback = tpi_lang_deep_merge(tpi_lang_template_strings($default), tpi_lang_content_rows($default));
            $result   = tpi_lang_fill_blank($result, $fallback);
        }

        $store = $GLOBALS['tpi_lang_content_merged'] ?? [];
        $store[$cache_key][$lang] = $result;
        $GLOBALS['tpi_lang_content_merged'] = $store;
        return $result;
    }
}

if (!function_exists('tpi_lang_content_exists')) {
    /** 该语言是否已有自己的语言包数据 */
    function tpi_lang_content_exists(string $lang): bool
    {
        return !empty(tpi_lang_content_raw($lang));
    }
}

/* =========================================================
 * 四、语言包写入
 * ========================================================= */

if (!function_exists('tpi_lang_content_write')) {
    /**
     * 把完整语言包写进配置表
     *
     * @param string $lang
     * @param array  $data 允许只传部分分组，会与已有数据合并
     * @return bool
     */
    function tpi_lang_content_write(string $lang, array $data): bool
    {
        $lang = strtolower(trim($lang));
        $lang_id = tpi_language_id($lang);
        if ($lang === '' || $lang_id <= 0) {
            return false;
        }
        // 分组结构与该语言的默认值同源，写库时按它过滤 + 排序
        $template = tpi_lang_template_strings($lang);
        $next = tpi_lang_content_raw($lang);
        foreach ($data as $section => $value) {
            if (array_key_exists((string) $section, $template)) {
                $next[(string) $section] = $value;
            }
        }
        // 按模板顺序重排，顺手丢掉模板里已经没有的分组；
        // 模板不存在（默认值文件被删）时不排序也不过滤，原样落库，免得把数据写空
        $ordered = [];
        if ($template) {
            foreach ($template as $section => $_) {
                if (array_key_exists($section, $next)) {
                    $ordered[$section] = $next[$section];
                }
            }
        } else {
            $ordered = $next;
        }
        $ok = tpi_data_write($lang_id, 'string', $ordered) >= 0;
        if ($ok) {
            // get_lang_json() 按语言缓存了整份语言包，这里先清掉，
            // 后面的联动（默认分类 SEO 等）才能读到刚写进去的值
            if (isset($GLOBALS['i18n_theme_page'])) {
                unset($GLOBALS['i18n_theme_page'][$lang]);
            }
            // 语言内容变了，跟着它走的页面级数据（如默认分类的 SEO 描述）一起刷新
            do_action('tpi_lang_content_saved', $lang);
        }
        return $ok;
    }
}

if (!function_exists('tpi_lang_content_save_section')) {
    /** 写一个分组 */
    function tpi_lang_content_save_section(string $lang, string $section, $data): bool
    {
        return tpi_lang_content_write($lang, [$section => $data]);
    }
}

if (!function_exists('tpi_lang_content_save_many')) {
    /** 批量写多个分组，返回写入的分组数 */
    function tpi_lang_content_save_many(string $lang, array $sections): int
    {
        $n = 0;
        foreach ($sections as $section => $_) {
            if (array_key_exists((string) $section, tpi_lang_template_strings($lang))) {
                $n++;
            }
        }
        if (!$n) {
            return 0;
        }
        return tpi_lang_content_write($lang, $sections) ? $n : 0;
    }
}

if (!function_exists('tpi_lang_content_delete')) {
    /** 清空某语言的语言包（回到模板兜底） */
    function tpi_lang_content_delete(string $lang): void
    {
        global $wpdb;
        $lang_id = tpi_language_id($lang);
        if ($lang_id <= 0) {
            return;
        }
        $wpdb->delete(tpi_lang_data_table(), ['lang_id' => $lang_id, 'scope' => 'string'], ['%d', '%s']);
        tpi_lang_content_flush();
    }
}

if (!function_exists('tpi_lang_content_seed')) {
    /**
     * 套用默认文案 / 其它语言的数据
     *
     * 来源语言为空时用**目标语言自己**的默认文案（data-init/data/lang-content.json 里
     * 那一份），这样日文不会先被英文默认值填满，只有该语言没带默认值时才回落默认语言。
     *
     * @param string $lang      目标语言
     * @param string $source    来源语言，空表示用该语言的默认文案
     * @param bool   $overwrite true=整份覆盖，false=只补缺失字段
     * @return array ['ok'=>bool,'sections'=>int,'message'=>string]
     */
    function tpi_lang_content_seed(string $lang, string $source = '', bool $overwrite = false): array
    {
        $lang = strtolower(trim($lang));
        if ($lang === '') {
            return ['ok' => false, 'sections' => 0, 'message' => tpi_admin_ui('language-content.error.no-code')];
        }

        $template = tpi_lang_template_strings($lang);
        if ($source !== '' && $source !== $lang) {
            $base = array_intersect_key(tpi_lang_content_raw($source), $template);
        } else {
            // 「套用默认文案」优先用 data-init 里的示例文案（有就套完整示例），
            // 没有示例时退回 default-lang-content.json 的中性文案，保证套出来的是一份可用的内容
            $demo = tpi_lang_demo_strings($lang);
            $base = $demo
                ? array_intersect_key(tpi_lang_deep_merge($template, $demo), $template)
                : $template;
        }
        if (!$base) {
            $base = $template;
        }
        if (!$base) {
            return ['ok' => false, 'sections' => 0, 'message' => tpi_admin_ui('language-content.error.template-empty')];
        }

        if ($overwrite) {
            tpi_lang_content_write($lang, $base);
            return ['ok' => true, 'sections' => count($base), 'message' => sprintf(tpi_admin_ui('language-content.notice.overwritten'), count($base))];
        }

        $current = array_intersect_key(tpi_lang_content_raw($lang), $template);
        if (!$current) {
            tpi_lang_content_write($lang, $base);
            return ['ok' => true, 'sections' => count($base), 'message' => sprintf(tpi_admin_ui('language-content.notice.applied'), count($base))];
        }
        $merged = tpi_lang_deep_merge($base, $current); // 已有内容优先，缺的从模板补
        if ($merged === $current) {
            return ['ok' => true, 'sections' => 0, 'message' => tpi_admin_ui('language-content.notice.nothing-to-fill')];
        }
        tpi_lang_content_write($lang, $merged);
        return ['ok' => true, 'sections' => count($merged), 'message' => tpi_admin_ui('language-content.notice.filled')];
    }
}

/* =========================================================
 * 五、语言表写入（后台「语言」页保存）
 * ========================================================= */

if (!function_exists('tpi_languages_sync')) {
    /**
     * 用提交上来的列表同步语言表
     *
     * - 列表里没有的语言行会被标记删除（内容保留在配置表里）
     * - 新出现的语言自动建好内容归属 term，文案按默认值文件里该语言那一份兜底
     *
     * @param array  $items   [['code','name','locale','flag_id','flag_url'], ...]
     * @param string $default 默认语言代码
     * @return array ['created'=>string[],'default'=>string]
     */
    function tpi_languages_sync(array $items, string $default): array
    {
        global $wpdb;
        $table = tpi_languages_table();
        $existing = [];
        // 连「已删除」的行一起读：语言代码有唯一索引，重新添加同代码要复用那一行
        foreach (tpi_languages_rows_all(true) as $row) {
            $existing[strtolower((string) $row['code'])] = $row;
        }

        $created  = [];
        $codes    = [];
        $position = 0;

        foreach ($items as $item) {
            // 兼容两种键名：表格页清洗后是 slug，数据层内部用 code
            $code = strtolower(trim((string) ($item['code'] ?? $item['slug'] ?? '')));
            if ($code === '' || !preg_match('/^[a-z]{2,5}$/', $code) || isset($codes[$code])) {
                continue;
            }
            $codes[$code] = true;
            $name = trim((string) ($item['name'] ?? ''));
            $data = [
                'code'       => $code,
                'name'       => $name !== '' ? $name : strtoupper($code),
                'locale'     => tpi_locale_normalize((string) ($item['locale'] ?? '')) ?: tpi_locale_default($code),
                'flag_id'    => (int) ($item['flag_id'] ?? 0),
                'flag_url'   => (string) ($item['flag_url'] ?? ''),
                'is_default' => 0,
                'status'     => 'active',
                'position'   => $position++,
                'updated_at' => current_time('mysql'),
            ];

            $is_new  = !isset($existing[$code]);
            $is_back = isset($existing[$code]) && strtolower((string) ($existing[$code]['status'] ?? 'active')) === 'deleted';

            if ($is_new) {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($table, $data);
            } else {
                $wpdb->update($table, $data, ['code' => $code]);
            }

            if ($is_new || $is_back) {
                // 新语言不预生成语言内容：读取时按「该语言默认值 → 库内数据 → 默认语言」兜底，
                // 默认值文件里有该语言（data-init/data/lang-content.json）就直接显示它自己那份，
                // 没有才显示默认语言的文案；后台「语言内容」页仍保持空白由使用者自己改。
                $created[] = $code;
                if (!term_exists($code, 'tpi_lang')) {
                    wp_insert_term($data['name'], 'tpi_lang', ['slug' => $code]);
                }
            }
        }

        // 一个有效语言都没有时不删任何东西，避免误清空整张表
        if (!$codes) {
            return ['created' => [], 'default' => $default];
        }

        // 列表里没有的语言：标记删除（保留行与语言内容，数据库里能看到 status=deleted）
        foreach ($existing as $code => $row) {
            if (isset($codes[$code]) || strtolower((string) ($row['status'] ?? 'active')) === 'deleted') {
                continue;
            }
            $wpdb->update(
                $table,
                ['status' => 'deleted', 'is_default' => 0, 'updated_at' => current_time('mysql')],
                ['code' => $code],
                ['%s', '%d', '%s'],
                ['%s']
            );
        }

        if (!isset($codes[$default])) {
            $default = (string) array_key_first($codes);
        }
        if ($default !== '') {
            $wpdb->query($wpdb->prepare("UPDATE $table SET is_default = (code = %s) WHERE status <> 'deleted'", $default));
            update_option('tpi_default_language', $default);
        }

        tpi_lang_content_flush();

        // option 只做镜像兜底（表万一没建起来时 tpi_languages() 还能用）
        $mirror = [];
        foreach (tpi_languages_rows(true) as $row) {
            $mirror[] = [
                'slug'     => (string) $row['code'],
                'name'     => (string) $row['name'],
                'locale'   => (string) $row['locale'],
                'flag_id'  => (int) $row['flag_id'],
                'flag_url' => (string) $row['flag_url'],
            ];
            if ((int) $row['is_default'] === 1) {
                $default = (string) $row['code'];
            }
        }
        update_option('tpi_languages', $mirror);

        return ['created' => $created, 'default' => $default];
    }
}

/* =========================================================
 * 六、后台编辑用的辅助
 * ========================================================= */

if (!function_exists('tpi_lang_section_labels')) {
    /** 分组中文名（取自 languages/admin-system-config-{zh|en}.json 的 meta） */
    function tpi_lang_section_labels(): array
    {
        $meta = tpi_lang_template_meta();
        $labels = $meta['sections'] ?? [];
        return is_array($labels) ? $labels : [];
    }
}

if (!function_exists('tpi_lang_section_groups')) {
    /**
     * 分组归类：返回 [ ['label'=>'首页','sections'=>['home','product']], ... ]
     * 模板没写归类时，按模板里的顺序每个分组一张卡
     */
    function tpi_lang_section_groups(): array
    {
        $meta   = tpi_lang_template_meta();
        $labels = tpi_lang_section_labels();
        $all    = array_keys(tpi_lang_template_strings());
        $groups = $meta['groups'] ?? [];
        if (!is_array($groups) || !$groups) {
            $groups = [['label' => tpi_admin_ui('language-content.group.all'), 'sections' => $all]];
        }

        $out  = [];
        $used = [];
        foreach ($groups as $group) {
            $sections = [];
            foreach ((array) ($group['sections'] ?? []) as $section) {
                if (isset($labels[$section]) || in_array($section, $all, true)) {
                    $sections[] = $section;
                    $used[$section] = true;
                }
            }
            if ($sections) {
                $out[] = [
                    'key'      => (string) ($group['key'] ?? ''),
                    'label'    => (string) ($group['label'] ?? tpi_admin_ui('language-content.group.other')),
                    'desc'     => (string) ($group['desc'] ?? ''),
                    'sections' => $sections,
                ];
            }
        }
        $rest = array_values(array_diff($all, array_keys($used)));
        if ($rest) {
            $out[] = ['key' => 'other', 'label' => tpi_admin_ui('language-content.group.other'), 'desc' => '', 'sections' => $rest];
        }
        return $out;
    }
}

if (!function_exists('tpi_lang_field_labels')) {
    /** 字段中文名映射：'home.our-products' => '产品区标题' */
    function tpi_lang_field_labels(): array
    {
        $meta = tpi_lang_template_meta();
        $labels = $meta['fields'] ?? [];
        return is_array($labels) ? $labels : [];
    }
}

if (!function_exists('tpi_lang_field_label')) {
    /** 取字段标签，没有就用键名美化一下 */
    function tpi_lang_field_label(string $section, string $path): string
    {
        $labels = tpi_lang_field_labels();
        $full   = $section . '.' . $path;
        if (isset($labels[$full])) {
            return (string) $labels[$full];
        }
        $plain = preg_replace('/\.\d+(?=\.|$)/', '', $path);
        if (isset($labels[$section . '.' . $plain])) {
            return (string) $labels[$section . '.' . $plain];
        }
        $leaf = $plain;
        if (strpos($plain, '.') !== false) {
            $leaf = substr($plain, strrpos($plain, '.') + 1);
        }
        return ucwords(str_replace(['-', '_'], ' ', $leaf));
    }
}

if (!function_exists('tpi_lang_content_stats')) {
    /** 语言包字段统计：分组数 / 叶子字段数 / 已填写数 */
    function tpi_lang_content_stats(string $lang): array
    {
        $data = tpi_lang_content_rows($lang);
        $filled = 0;
        $total  = 0;
        $walk = function ($node) use (&$walk, &$filled, &$total) {
            if (is_array($node)) {
                foreach ($node as $value) {
                    $walk($value);
                }
                return;
            }
            $total++;
            if (trim((string) $node) !== '') {
                $filled++;
            }
        };
        foreach ($data as $section) {
            $walk($section);
        }
        return ['sections' => count($data), 'fields' => $total, 'filled' => $filled];
    }
}

if (!function_exists('tpi_admin_lang_tabs')) {
    /**
     * 后台页面顶部的语言切换条（「全部语言」+ 各语言）
     */
    function tpi_admin_lang_tabs(string $page_slug, string $current = '', bool $with_all = true, array $extra = []): void
    {
        $items = [];
        if ($with_all) {
            $items[] = ['slug' => '', 'name' => tpi_admin_ui('language-content.tabs.all'), 'flag' => '', 'current' => $current === ''];
        }
        foreach (tpi_languages() as $language) {
            $items[] = [
                'slug'    => (string) $language['slug'],
                'name'    => (string) $language['name'],
                'flag'    => (string) $language['flag_url'],
                'current' => $current === (string) $language['slug'],
            ];
        }

        echo '<nav class="ad-langtabs" aria-label="' . esc_attr(tpi_admin_ui('language-content.tabs.aria-label')) . '">';
        foreach ($items as $item) {
            $args = array_merge($extra, ['page' => $page_slug]);
            if ($item['slug'] !== '') {
                $args['lang'] = $item['slug'];
            } else {
                // 「全部语言」必须显式带 lang=all：不带参数时
                // tpi_admin_language_filter() 会回落到上次记忆的语言，页面看起来点了没反应
                $args['lang'] = 'all';
            }
            $url = add_query_arg($args, admin_url('admin.php'));
            printf(
                '<a class="ad-langtab%s" href="%s"%s>',
                $item['current'] ? ' is-current' : '',
                esc_url($url),
                $item['current'] ? ' aria-current="page"' : ''
            );
            if ($item['flag'] !== '') {
                printf('<img src="%s" alt="">', esc_url($item['flag']));
            }
            printf('<span>%s</span>', esc_html($item['name']));
            if ($item['slug'] !== '') {
                printf('<code>%s</code>', esc_html($item['slug']));
            }
            echo '</a>';
        }
        echo '</nav>';
    }
}

if (!function_exists('tpi_languages_install')) {
    /** 兼容旧调用名 */
    function tpi_languages_install(): void
    {
        tpi_db_install();
    }
}
