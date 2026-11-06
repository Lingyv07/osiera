<?php
/**
 * 主题内置多语言（替代 Polylang 的语言归类部分）
 *
 * 只做三件事，不做翻译：
 *   1. 语言列表：后台「语言」页维护，一条一条加，不是从预置列表里选
 *   2. 内容归属：每篇文章 / 页面 / 产品 / 应用产品 / FAQ 挂一个语言
 *   3. 按语言访问：/ja/... 前缀、语言切换器、后台按语言筛选
 *
 * 数据全部在 WordPress 自己的表里：
 *   - option  tpi_languages    语言列表（含国旗附件 ID / URL）
 *   - option  tpi_default_language
 *   - taxonomy tpi_lang        内容归属（term slug = 语言代码）
 *
 * 关闭 Polylang 后本文件独立工作，不调用任何 pll_* 函数。
 */

/* =========================================================
 * 零、请求内缓存（保存语言后可整体失效）
 * ========================================================= */

if (!function_exists('tpi_lang_cache_flush')) {
    function tpi_lang_cache_flush(): void
    {
        $GLOBALS['tpi_lang_cache'] = [];
    }
}

if (!function_exists('tpi_lang_cache__cached')) {
    function tpi_lang_cache__cached(string $key)
    {
        return $GLOBALS['tpi_lang_cache'][$key] ?? null;
    }
}

if (!function_exists('tpi_lang_cache__store')) {
    function tpi_lang_cache__store(string $key, $value): void
    {
        $GLOBALS['tpi_lang_cache'][$key] = $value;
    }
}

/* =========================================================
 * 一、语言列表
 * ========================================================= */

if (!function_exists('tpi_builtin_languages')) {
    /**
     * 首次启用主题时的初始语言
     *
     * 有哪几种语言、哪种是默认，一律由数据源（data-init/data/languages.json）决定，
     * 不再在代码里写死「只有英语」——新项目换一套语言只要换 JSON，不用改 PHP。
     * 数据源取不到时（离线、init-config.json 路径写错）才兜底一条英语，免得前台空白。
     */
    function tpi_builtin_languages(): array
    {
        if (!function_exists('tpi_data_init_json')) {
            $setup = dirname(__DIR__) . '/setup/data-init.php';
            if (is_file($setup)) {
                require_once $setup;
            }
        }
        if (function_exists('tpi_data_init_json')) {
            $data  = tpi_data_init_json('languages');
            $items = (array) ($data['items'] ?? []);
            $default = strtolower(trim((string) ($data['default'] ?? '')));
            $list  = [];
            foreach ($items as $item) {
                $slug = strtolower(trim((string) ($item['code'] ?? $item['slug'] ?? '')));
                if ($slug === '' || !preg_match('/^[a-z]{2,5}$/', $slug)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                $list[] = [
                    'slug'       => $slug,
                    'name'       => $name !== '' ? $name : strtoupper($slug),
                    'locale'     => tpi_locale_normalize((string) ($item['locale'] ?? '')) ?: tpi_locale_default($slug),
                    'flag_id'    => 0,
                    'flag_url'   => tpi_builtin_flag_url($slug),
                    'is_default' => $slug === $default,
                ];
            }
            if ($list) {
                // JSON 没标默认时把第一条当默认，与 tpi_default_language() 的口径一致
                if (!in_array(true, array_column($list, 'is_default'), true)) {
                    $list[0]['is_default'] = true;
                }
                return $list;
            }
        }

        $list = [
            ['slug' => 'en', 'name' => 'English', 'locale' => tpi_locale_default('en')],
        ];
        foreach ($list as &$item) {
            $item['flag_id']  = 0;
            $item['flag_url'] = tpi_builtin_flag_url($item['slug']);
        }
        unset($item);
        return $list;
    }
}

if (!function_exists('tpi_builtin_flag_url')) {
    /** 内置国旗：优先取媒体库里的同名 svg，没有再用主题目录里的 */
    function tpi_builtin_flag_url(string $slug): string
    {
        $url = tpi_media_url($slug . '.svg');
        if ($url !== '') {
            return $url;
        }
        $file = tpi_asset('/svg/language/') . $slug . '.svg';
        return is_file(THEME_DIR . ltrim($file, '/')) ? $file : '';
    }
}

if (!function_exists('tpi_languages')) {
    /**
     * 读取语言列表
     *
     * 数据源是数据库表 {prefix}tpi_languages（一种语言一行）；
     * 表还没建好时依次回退 option tpi_languages、主题内置列表，保证前台不会空白。
     *
     * @param bool $refresh 强制重新读库
     */
    function tpi_languages(bool $refresh = false): array
    {
        if ($refresh) {
            tpi_lang_cache_flush();
        } else {
            $cached = tpi_lang_cache__cached('languages');
            if (is_array($cached)) {
                return $cached;
            }
        }

        $list = [];
        $rows = function_exists('tpi_languages_rows') ? tpi_languages_rows($refresh) : [];
        foreach ($rows as $row) {
            $slug = strtolower(trim((string) ($row['code'] ?? '')));
            if ($slug === '' || !preg_match('/^[a-z]{2,5}$/', $slug)) {
                continue;
            }
            $name     = trim((string) ($row['name'] ?? ''));
            $flag_id  = (int) ($row['flag_id'] ?? 0);
            $flag_url = (string) ($row['flag_url'] ?? '');
            if ($flag_id > 0) {
                $url = wp_get_attachment_image_url($flag_id, 'full');
                if ($url) {
                    $flag_url = $url;
                }
            }
            if ($flag_url === '') {
                $flag_url = tpi_builtin_flag_url($slug);
            }
            // 库里是根相对路径，展示层补成当前域名的绝对地址
            $flag_url = tpi_media_full($flag_url);
            $list[] = [
                'slug'       => $slug,
                'name'       => $name !== '' ? $name : strtoupper($slug),
                'locale'     => tpi_locale_normalize((string) ($row['locale'] ?? '')) ?: tpi_locale_default($slug),
                'flag_id'    => $flag_id,
                'flag_url'   => $flag_url,
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            ];
        }

        if (!$list) {
            $stored = get_option('tpi_languages', []);
            if (!is_array($stored) || !$stored) {
                $list = tpi_builtin_languages();
                tpi_lang_cache__store('languages', $list);
                return $list;
            }
            $option_default = (string) get_option('tpi_default_language', '');
            foreach ($stored as $item) {
                $slug = strtolower(trim((string) ($item['slug'] ?? '')));
                if ($slug === '' || !preg_match('/^[a-z]{2,5}$/', $slug)) {
                    continue;
                }
                $name     = trim((string) ($item['name'] ?? ''));
                $flag_id  = (int) ($item['flag_id'] ?? 0);
                $flag_url = (string) ($item['flag_url'] ?? '');
                if ($flag_id > 0) {
                    $url = wp_get_attachment_image_url($flag_id, 'full');
                    if ($url) {
                        $flag_url = $url;
                    }
                }
                if ($flag_url === '') {
                    $flag_url = tpi_builtin_flag_url($slug);
                }
                $flag_url = tpi_media_full($flag_url);
                $list[] = [
                    'slug'       => $slug,
                    'name'       => $name !== '' ? $name : strtoupper($slug),
                    'locale'     => tpi_locale_normalize((string) ($item['locale'] ?? '')) ?: tpi_locale_default($slug),
                    'flag_id'    => $flag_id,
                    'flag_url'   => $flag_url,
                    'is_default' => ($slug === $option_default),
                ];
            }
        }

        $list = $list ?: tpi_builtin_languages();
        tpi_lang_cache__store('languages', $list);
        return $list;
    }
}

if (!function_exists('tpi_flush_languages_cache')) {
    function tpi_flush_languages_cache(): void
    {
        tpi_languages(true);
    }
}

if (!function_exists('tpi_language_slugs')) {
    /** 全部语言代码，顺序按后台列表 */
    function tpi_language_slugs(): array
    {
        return array_column(tpi_languages(), 'slug');
    }
}

if (!function_exists('tpi_language_slugs_other')) {
    /** 除默认语言外的语言代码（前台换取 URL 时用） */
    function tpi_language_slugs_other(): array
    {
        $default = tpi_default_language();
        return array_values(array_filter(tpi_language_slugs(), static fn($s) => $s !== $default));
    }
}

if (!function_exists('tpi_default_language')) {
    function tpi_default_language(): string
    {
        $cached = tpi_lang_cache__cached('default');
        if (is_string($cached)) {
            return $cached;
        }
        $slugs = array_column(tpi_languages(), 'slug');

        // 优先用语言表里的「默认」标记
        $default = '';
        foreach (tpi_languages() as $item) {
            if (!empty($item['is_default'])) {
                $default = (string) $item['slug'];
                break;
            }
        }
        if ($default === '' || !in_array($default, $slugs, true)) {
            $default = (string) get_option('tpi_default_language', '');
        }
        if ($default === '' || !in_array($default, $slugs, true)) {
            // 默认语言必须是列表里的第一个
            $default = $slugs[0] ?? 'en';
        }
        tpi_lang_cache__store('default', $default);
        return $default;
    }
}

if (!function_exists('tpi_language_by_slug')) {
    function tpi_language_by_slug(string $slug): ?array
    {
        foreach (tpi_languages() as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }
        return null;
    }
}

if (!function_exists('tpi_language_name')) {
    function tpi_language_name(string $slug): string
    {
        $item = tpi_language_by_slug($slug);
        return $item ? $item['name'] : strtoupper($slug);
    }
}

/* =========================================================
 * 二、当前语言
 * ========================================================= */

if (!function_exists('tpi_request_path')) {
    function tpi_request_path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }
}

if (!function_exists('tpi_language_from_path')) {
    /** 从 URL 第一段识别语言；不是语言则返回空串 */
    function tpi_language_from_path(string $path = ''): string
    {
        $path = $path !== '' ? $path : tpi_request_path();
        $parts = explode('/', trim($path, '/'));
        $first = $parts[0] ?? '';
        return in_array($first, tpi_language_slugs(), true) ? $first : '';
    }
}

if (!function_exists('tpi_current_language')) {
    /**
     * 当前语言：前台看 URL 前缀 → cookie；后台看 ?lang= → 用户上次筛选 → 默认
     */
    function tpi_current_language(): string
    {
        $cached = tpi_lang_cache__cached('current');
        if (is_string($cached)) {
            return $cached;
        }

        $slugs   = tpi_language_slugs();
        $default = tpi_default_language();

        $from_path = tpi_language_from_path();
        if ($from_path !== '') {
            tpi_lang_cache__store('current', $from_path);
            return $from_path;
        }

        if (is_admin()) {
            $lang = tpi_admin_language_context();
            tpi_lang_cache__store('current', $lang);
            return $lang;
        }
        // 前台只认 URL 前缀：没有前缀就是默认语言。
        // 以前这里会读 cookie，导致默认语言首页的导航跟着上次浏览过的语言走，
        // 出现「页面是英文、菜单是德语」这种前后不一致。

        tpi_lang_cache__store('current', $default);
        return $default;
    }
}

if (!function_exists('tpi_current_locale')) {
    function tpi_current_locale(): string
    {
        $item = tpi_language_by_slug(tpi_current_language());
        return $item ? $item['locale'] : tpi_current_language();
    }
}

if (!function_exists('tpi_current_locale_tag')) {
    /**
     * 当前语言的本地化标记（en-US），给 <html lang>、hreflang、JSON-LD inLanguage 用。
     * 取不到 locale 时退回语言代码，保证总有值。
     */
    function tpi_current_locale_tag(): string
    {
        $tag = tpi_locale_tag(tpi_current_locale());
        return $tag !== '' ? $tag : tpi_current_language();
    }
}

/* =========================================================
 * 三、内容归属（taxonomy tpi_lang）
 * ========================================================= */

if (!function_exists('tpi_lang_post_types')) {
    /** 需要按语言归类的文章类型 */
    function tpi_lang_post_types(): array
    {
        return ['post', 'page', 'product', 'application', 'faq'];
    }
}

add_action('init', function () {
    register_taxonomy('tpi_lang', tpi_lang_post_types(), [
        'labels'            => [
            'name'          => tpi_admin_ui('multilang.tax.name'),
            'singular_name' => tpi_admin_ui('multilang.tax.name'),
        ],
        // 完全走主题自己的界面，不在后台单独露出术语管理
        'public'            => false,
        'publicly_queryable' => false,
        'show_ui'           => false,
        'show_in_menu'      => false,
        'show_in_nav_menus' => false,
        'show_in_rest'      => false,
        'show_admin_column' => false,
        'hierarchical'      => false,
        'rewrite'           => false,
        'query_var'         => false,
    ]);
}, 9);

if (!function_exists('tpi_get_post_language')) {
    /**
     * 内容语言；未归属时回退默认语言
     */
    function tpi_get_post_language($post_id): string
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }
        $terms = get_the_terms($post_id, 'tpi_lang');
        if (is_array($terms) && $terms) {
            $slug = (string) $terms[0]->slug;
            if (in_array($slug, tpi_language_slugs(), true)) {
                return $slug;
            }
        }
        return '';
    }
}

if (!function_exists('tpi_set_post_language')) {
    /**
     * 设置内容语言
     */
    function tpi_set_post_language($post_id, string $slug): void
    {
        static $busy = false;

        $post_id = (int) $post_id;
        if ($post_id <= 0 || !in_array($slug, tpi_language_slugs(), true)) {
            return;
        }
        // term 不存在就补一个，保证后台新加的语言立刻能用
        if (!term_exists($slug, 'tpi_lang')) {
            wp_insert_term(tpi_language_name($slug), 'tpi_lang', ['slug' => $slug]);
        }
        wp_set_object_terms($post_id, $slug, 'tpi_lang', false);

        // 区块编辑器的语言面板读的是 post meta，这里同步一下，
        // 避免「快速编辑改了语言、打开编辑器还是旧值」
        if (!$busy && (string) get_post_meta($post_id, '_tpi_post_lang', true) !== $slug) {
            $busy = true;
            update_post_meta($post_id, '_tpi_post_lang', $slug);
            $busy = false;
        }

        unset($GLOBALS['tpi_lang_cache']['translations_' . $post_id]);
    }
}

if (!function_exists('tpi_default_post_language')) {
    /** 未归属内容的默认归属：后台当前语言，前台用默认语言 */
    function tpi_default_post_language(): string
    {
        return is_admin() ? tpi_current_language() : tpi_default_language();
    }
}

if (!function_exists('tpi_post_language_or_default')) {
    function tpi_post_language_or_default($post_id): string
    {
        $lang = tpi_get_post_language($post_id);
        return $lang !== '' ? $lang : tpi_default_language();
    }
}

/* =========================================================
 * 三之二、后台当前语言（筛选 / 新建内容）
 * ========================================================= */

if (!function_exists('tpi_admin_language_filter')) {
    /**
     * 后台列表的「语言筛选」：空串表示全部语言。
     * 优先 ?lang=，其次用户上次的选择。
     */
    function tpi_admin_language_filter(): string
    {
        if (!is_admin()) {
            return '';
        }
        $slugs = tpi_language_slugs();

        if (isset($_GET['lang'])) {
            $query = strtolower((string) $_GET['lang']);
            if ($query === 'all') {
                return '';
            }
            if (in_array($query, $slugs, true)) {
                return $query;
            }
        }

        $saved = (string) get_user_meta(get_current_user_id(), 'tpi_filter_content', true);
        return in_array($saved, $slugs, true) ? $saved : '';
    }
}

if (!function_exists('tpi_admin_language_context')) {
    /** 后台新建内容默认落到哪个语言：筛选语言 → 默认语言 */
    function tpi_admin_language_context(): string
    {
        $filter = tpi_admin_language_filter();
        return $filter !== '' ? $filter : tpi_default_language();
    }
}

/* =========================================================
 * 三之三、术语（分类 / 标签）的语言
 *
 * 原来 Polylang 把术语语言放在 term_language 这个 taxonomy 的关联里，
 * 这里先读主题自己的 term meta（迁移时写入），再兼容遗留数据，
 * 最后按 slug 的 -xx 后缀猜，保证分类能按语言分开显示。
 * ========================================================= */

if (!function_exists('tpi_get_term_language')) {
    function tpi_get_term_language($term): string
    {
        if (!is_object($term)) {
            $term = get_term((int) $term);
        }
        if (!$term || is_wp_error($term) || empty($term->term_id)) {
            return '';
        }

        $term_id = (int) $term->term_id;
        $cache_key = 'term_lang_' . $term_id;
        $cached = tpi_lang_cache__cached($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $slugs = tpi_language_slugs();
        $lang  = strtolower((string) get_term_meta($term_id, '_tpi_term_lang', true));

        if (!in_array($lang, $slugs, true)) {
            global $wpdb;
            // Polylang 遗留：term_language 关联，slug 形如 pll_ja
            $legacy = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT t.slug
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tr.object_id = %d AND tt.taxonomy = 'term_language'
                 LIMIT 1",
                $term_id
            ));
            $legacy = strtolower(preg_replace('/^pll_/', '', $legacy));
            if (in_array($legacy, $slugs, true)) {
                $lang = $legacy;
            }
        }

        if (!in_array($lang, $slugs, true)) {
            // slug 后缀约定：tpu-film-ja → ja（默认语言的分类没有后缀）
            $slug = (string) $term->slug;
            foreach ($slugs as $one) {
                if ($one !== tpi_default_language() && str_ends_with($slug, '-' . $one)) {
                    $lang = $one;
                    break;
                }
            }
        }

        if (!in_array($lang, $slugs, true)) {
            $lang = tpi_default_language();
        }

        tpi_lang_cache__store($cache_key, $lang);
        return $lang;
    }
}

if (!function_exists('tpi_set_term_language')) {
    function tpi_set_term_language(int $term_id, string $lang): void
    {
        if ($term_id <= 0 || !in_array($lang, tpi_language_slugs(), true)) {
            return;
        }
        update_term_meta($term_id, '_tpi_term_lang', $lang);
        tpi_lang_cache__store('term_lang_' . $term_id, $lang);
    }
}

if (!function_exists('tpi_get_terms_by_language')) {
    /**
     * 取某个语言下的分类/标签（前台侧栏用）
     */
    function tpi_get_terms_by_language(string $taxonomy, array $args = [], string $lang = ''): array
    {
        $args['taxonomy'] = $taxonomy;
        $terms = get_terms($args);
        if (is_wp_error($terms) || !$terms) {
            return [];
        }

        $lang = $lang !== '' ? $lang : tpi_current_language();
        $matched = [];
        foreach ($terms as $term) {
            if (tpi_get_term_language($term) === $lang) {
                $matched[] = $term;
            }
        }
        return $matched;
    }
}

/* =========================================================
 * 三之四、各语言版本之间的对应关系
 *
 * Polylang 的翻译分组存在 post_translations 这个 taxonomy 的
 * description 里（序列化数组 lang => post_id）。迁移时复制到主题
 * 自己的 post meta，插件删了也不会丢；没有分组的再按同 slug 补。
 * ========================================================= */

if (!function_exists('tpi_get_post_translations')) {
    /**
     * @return array lang => post_id
     */
    function tpi_get_post_translations(int $post_id): array
    {
        if ($post_id <= 0) {
            return [];
        }

        $cache_key = 'translations_' . $post_id;
        $cached = tpi_lang_cache__cached($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $slugs = tpi_language_slugs();
        $map   = [];

        // 1) 主题自己的 meta
        $stored = get_post_meta($post_id, '_tpi_translations', true);
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        // 2) Polylang 遗留的分组
        if (!$map) {
            global $wpdb;
            $desc = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT tt.description
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = %d AND tt.taxonomy = 'post_translations'
                 LIMIT 1",
                $post_id
            ));
            if ($desc !== '') {
                $parsed = maybe_unserialize($desc);
                if (is_array($parsed)) {
                    $map = $parsed;
                }
            }
        }

        // 3) 同 slug + 同类型：产品、文章各语言基本都用同一个 slug
        $slug = (string) get_post_field('post_name', $post_id);
        $type = (string) get_post_type($post_id);
        if ($slug !== '' && $type !== '') {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_name = %s AND post_type = %s
                   AND post_status IN ('publish', 'future', 'draft', 'pending', 'private')",
                $slug,
                $type
            ));
            foreach ((array) $ids as $id) {
                $id  = (int) $id;
                $one = tpi_get_post_language($id);
                if ($one !== '' && empty($map[$one])) {
                    $map[$one] = $id;
                }
            }
        }

        $clean = [];
        foreach ((array) $map as $lang => $id) {
            $lang = strtolower((string) $lang);
            if (in_array($lang, $slugs, true) && (int) $id > 0) {
                $clean[$lang] = (int) $id;
            }
        }

        $own = tpi_get_post_language($post_id);
        if ($own !== '') {
            $clean[$own] = $post_id;
        }

        tpi_lang_cache__store($cache_key, $clean);
        return $clean;
    }
}

/* =========================================================
 * 四、按语言取内容
 * ========================================================= */

if (!function_exists('tpi_get_post_id_by_slug')) {
    /**
     * 按 slug + 语言查已发布内容的 ID（直接查语言归属表，不依赖任何插件）
     */
    function tpi_get_post_id_by_slug(string $slug, string $lang, string $post_type = ''): int
    {
        global $wpdb;
        if ($slug === '') {
            return 0;
        }

        $sql = "SELECT p.ID
                FROM {$wpdb->posts} p
                JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                WHERE p.post_name = %s
                  AND p.post_status = 'publish'
                  AND tt.taxonomy = 'tpi_lang'
                  AND t.slug = %s";
        $params = [$slug, $lang];
        if ($post_type !== '') {
            $sql .= " AND p.post_type = %s";
            $params[] = $post_type;
        }
        // 同一个 slug 在页面和文章里都可能存在（例如 /about-us 与某篇同名文章），
        // 页面优先，其次是菜单里常见的文章类型，避免命中随机一条
        $sql .= " ORDER BY (p.post_type = 'page') DESC, p.ID ASC LIMIT 1";

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }
}

if (!function_exists('tpi_url_to_postid')) {
    /**
     * URL → post_id（考虑语言前缀与语言归属）
     *
     * 带语言前缀的地址必须先按语言解析：/ja/home 与 /home 的 slug 一模一样，
     * 但指向两个不同的页面。交给核心 url_to_postid() 会逐段剥离路径，
     * 最后匹配到默认语言的同 slug 页面（/ja/home → 英文 Home），
     * 于是底部导航、面包屑这类「比 ID」的地方在非默认语言下永远对不上。
     */
    function tpi_url_to_postid($url): int
    {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);

        // WordPress 装在子目录时，先把站点子目录摘掉
        $home_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path !== '' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }

        $path = trim($path, '/');

        if ($path !== '') {
            $parts = explode('/', $path);
            $first = (string) ($parts[0] ?? '');
            $slugs = tpi_language_slugs();

            if (in_array($first, $slugs, true)) {
                array_shift($parts);
                // /ja/news/page/2 这类分页地址：去掉分页段，落到列表页
                $count = count($parts);
                if ($count >= 2
                    && ctype_digit((string) $parts[$count - 1])
                    && strtolower((string) $parts[$count - 2]) === 'page') {
                    array_splice($parts, $count - 2, 2);
                }
                $slug = (string) end($parts);
                if ($slug === '') {
                    return 0;
                }
                // 带前缀就只认这个语言下的内容，查不到宁可返回 0，
                // 也不要让核心函数猜成默认语言的同名页面
                return tpi_get_post_id_by_slug($slug, $first);
            }
        }

        $post_id = (int) url_to_postid((string) $url);
        return $post_id > 0 ? $post_id : 0;
    }
}

/* =========================================================
 * 五、从 Polylang 迁移（只在第一次运行）
 * ========================================================= */

if (!function_exists('tpi_maybe_migrate_polylang')) {
    /**
     * 把 Polylang 已有的「语言」term 与内容归属搬到主题自己的 tpi_lang 上。
     * 迁移一次后打标记，之后不再执行；Polylang 在不在都不影响。
     */
    function tpi_maybe_migrate_polylang(): void
    {
        if (get_option('tpi_lang_migrated') === 'yes') {
            return;
        }

        global $wpdb;

        // 1) Polylang 的语言 term
        $rows = $wpdb->get_results(
            "SELECT t.slug, t.name, tt.count
             FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'language'
             ORDER BY t.term_id"
        );

        if ($rows) {
            $languages = [];
            foreach ($rows as $row) {
                $slug = strtolower(trim((string) $row->slug));
                if ($slug === '') {
                    continue;
                }
                $languages[] = [
                    'slug'     => $slug,
                    'name'     => (string) $row->name,
                    'locale'   => tpi_locale_default($slug),
                    'flag_id'  => 0,
                    'flag_url' => tpi_builtin_flag_url($slug),
                ];
            }

            if ($languages && !get_option('tpi_languages')) {
                // Polylang 的默认语言排到第一个
                $pll_option  = get_option('polylang');
                $pll_default = (is_array($pll_option) && !empty($pll_option['default_lang']))
                    ? (string) $pll_option['default_lang']
                    : '';
                if ($pll_default !== '') {
                    usort($languages, static function ($a, $b) use ($pll_default) {
                        if ($a['slug'] === $pll_default) return -1;
                        if ($b['slug'] === $pll_default) return 1;
                        return 0;
                    });
                }
                update_option('tpi_languages', $languages);
                update_option('tpi_default_language', $pll_default !== '' ? $pll_default : $languages[0]['slug']);
            }
        }

        // 2) 内容归属：把 language taxonomy 的关联复制到 tpi_lang
        foreach (tpi_language_slugs() as $slug) {
            $term = term_exists($slug, 'tpi_lang');
            if (!$term) {
                $term = wp_insert_term(tpi_language_name($slug), 'tpi_lang', ['slug' => $slug]);
            }
            if (is_wp_error($term)) {
                continue;
            }
            $tt_id = (int) (is_array($term) ? $term['term_taxonomy_id'] : get_term($term, 'tpi_lang')->term_taxonomy_id);
            if ($tt_id <= 0) {
                continue;
            }

            // 已经迁过的语言跳过，避免重复插入
            $done = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                $tt_id
            ));
            if ($done > 0) {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order)
                 SELECT tr.object_id, %d, 0
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = 'language' AND t.slug = %s",
                $tt_id,
                $slug
            ));

            // 同步 term 计数
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                $tt_id
            ));
            $wpdb->update($wpdb->term_taxonomy, ['count' => $count], ['term_taxonomy_id' => $tt_id], ['%d'], ['%d']);
        }

        update_option('tpi_lang_migrated', 'yes');
    }
}
add_action('init', 'tpi_maybe_migrate_polylang', 20);

if (!function_exists('tpi_maybe_migrate_relations')) {
    /**
     * 迁移「语言之外」的关联数据（各语言版本对应关系、分类的语言），
     * 全部落到主题自己的 term meta / post meta，之后删掉插件也不影响。
     */
    function tpi_maybe_migrate_relations(): void
    {
        if (get_option('tpi_relations_migrated') === 'yes') {
            return;
        }

        global $wpdb;
        $slugs = tpi_language_slugs();

        // 1) 术语语言：term_language 关联 → term meta
        $term_rows = $wpdb->get_results(
            "SELECT tr.object_id AS term_id, t.slug
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'term_language'"
        );
        foreach ((array) $term_rows as $row) {
            $lang = strtolower(preg_replace('/^pll_/', '', (string) $row->slug));
            if ((int) $row->term_id > 0 && in_array($lang, $slugs, true)) {
                update_term_meta((int) $row->term_id, '_tpi_term_lang', $lang);
            }
        }

        // 2) 各语言版本分组：post_translations 的 description → post meta
        $groups = $wpdb->get_col(
            "SELECT tt.description FROM {$wpdb->term_taxonomy} tt
             WHERE tt.taxonomy = 'post_translations' AND tt.description <> ''"
        );
        foreach ((array) $groups as $desc) {
            $map = maybe_unserialize($desc);
            if (!is_array($map)) {
                continue;
            }
            $clean = [];
            foreach ($map as $lang => $id) {
                $lang = strtolower((string) $lang);
                if (in_array($lang, $slugs, true) && (int) $id > 0) {
                    $clean[$lang] = (int) $id;
                }
            }
            if (count($clean) < 2) {
                continue;
            }
            foreach ($clean as $id) {
                update_post_meta($id, '_tpi_translations', wp_json_encode($clean));
            }
        }

        // 3) 没有分组的：按同 slug + 同类型补齐一份
        $dupes = $wpdb->get_results(
            "SELECT post_name, post_type, GROUP_CONCAT(ID) ids, COUNT(*) c
             FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_name <> ''
               AND post_type IN ('post', 'page', 'product', 'application', 'faq')
             GROUP BY post_name, post_type
             HAVING c > 1"
        );
        foreach ((array) $dupes as $row) {
            $map = [];
            foreach (explode(',', (string) $row->ids) as $id) {
                $id = (int) $id;
                $one = tpi_get_post_language($id);
                if ($one !== '' && empty($map[$one])) {
                    $map[$one] = $id;
                }
            }
            if (count($map) < 2) {
                continue;
            }
            foreach ($map as $id) {
                if (!get_post_meta($id, '_tpi_translations', true)) {
                    update_post_meta($id, '_tpi_translations', wp_json_encode($map));
                }
            }
        }

        update_option('tpi_relations_migrated', 'yes');
        tpi_lang_cache_flush();
    }
}
add_action('init', 'tpi_maybe_migrate_relations', 21);

/* =========================================================
 * 六、URL：非默认语言加前缀
 * ========================================================= */

if (!function_exists('tpi_localize_url')) {
    /**
     * 给站内链接补上语言前缀；默认语言、已带前缀的链接原样返回
     */
    function tpi_localize_url($url, string $lang): string
    {
        $url = (string) $url;
        if ($url === '' || $lang === '' || $lang === tpi_default_language()) {
            return $url;
        }

        $home = untrailingslashit(home_url('/'));
        if (strpos($url, $home) === 0) {
            $rest = substr($url, strlen($home));
        } elseif (($url[0] ?? '') === '/') {
            $rest = $url;
        } else {
            return $url; // 外链、锚点、mailto 之类不动
        }

        $rest = $rest === '' ? '/' : $rest;
        $first = strtok(ltrim($rest, '/'), '/');
        if ($first !== false && in_array(strtolower((string) $first), tpi_language_slugs(), true)) {
            return $url; // 已经有语言前缀
        }

        return $home . '/' . $lang . ($rest === '/' ? '/' : $rest);
    }
}

if (!function_exists('tpi_localize_post_link')) {
    /** 按内容自己的语言给链接加前缀 */
    function tpi_localize_post_link($link, $post)
    {
        $post = get_post($post);
        if (!$post || empty($post->ID)) {
            return $link;
        }
        return tpi_localize_url($link, tpi_get_post_language($post->ID));
    }
}
add_filter('page_link', 'tpi_localize_post_link', 20, 2);
add_filter('post_link', 'tpi_localize_post_link', 20, 2);
add_filter('post_type_link', 'tpi_localize_post_link', 20, 2);

if (!function_exists('tpi_raw_page_on_front')) {
    /**
     * 读「设置 → 阅读」里配置的静态首页 ID（绕过下面按语言改写的过滤器）
     */
    function tpi_raw_page_on_front(): int
    {
        $GLOBALS['tpi_front_filter_off'] = true;
        $id = (int) get_option('page_on_front');
        $GLOBALS['tpi_front_filter_off'] = false;
        return $id;
    }
}

if (!function_exists('tpi_language_home_url')) {
    /** 某语言的首页地址（首页按语言各有一个译文页面） */
    function tpi_language_home_url(string $lang): string
    {
        // 默认语言的首页就是站点根地址（/），这样 x-default 指向干净
        if ($lang === tpi_default_language()) {
            return home_url('/');
        }
        $front_id = tpi_raw_page_on_front();
        if ($front_id > 0) {
            $front_slug = (string) get_post_field('post_name', $front_id);
            if ($front_slug !== '') {
                $translated = tpi_get_post_id_by_slug($front_slug, $lang, 'page');
                if ($translated > 0) {
                    return (string) get_permalink($translated);
                }
            }
            // 该语言还没有首页内容：退回站点首页（不能退成 /xx/ 自己，会死循环）
            return home_url('/');
        }
        return tpi_localize_url(home_url('/'), $lang);
    }
}

if (!function_exists('tpi_filter_page_on_front')) {
    /**
     * 静态首页跟着「当前语言」走
     *
     * 换默认语言后，page_on_front 里存的还是原来那个语言的首页页面：
     *   - 默认语言：换成该语言自己的首页页面，/ 才不会显示上一个语言的首页；
     *   - 其它语言：返回 0，让该语言的首页页面当成普通页面，
     *     这样 /en/home 不会被 WordPress 重定向回 /（否则英文站直接失效）。
     */
    function tpi_filter_page_on_front($value)
    {
        static $busy = false;
        $id = (int) $value;
        if ($id <= 0 || $busy || !empty($GLOBALS['tpi_front_filter_off']) || is_admin() || wp_doing_ajax()) {
            return $value;
        }

        $busy = true;
        $slug  = (string) get_post_field('post_name', $id);
        $busy = false;
        if ($slug === '') {
            return $value;
        }

        $lang    = tpi_current_language();
        $default = tpi_default_language();
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return $value;
        }

        if ($lang !== $default) {
            return 0; // 非默认语言：首页页面按普通页面渲染
        }

        $translated = tpi_get_post_id_by_slug($slug, $lang, 'page');
        return $translated > 0 ? $translated : $value;
    }
}
add_filter('option_page_on_front', 'tpi_filter_page_on_front');

if (!function_exists('tpi_content_translation_map')) {
    /**
     * 一条内容的「语言 => post_id」映射
     *
     * 译文 slug 可以完全不同于原文（产品/文章常见），所以不能用「同 slug 反查」
     * 当主路径，必须以 `_tpi_translations` 为准。这里同时补两个方向：
     * 自身语言、以及对方只记了半边时的反向引用。
     *
     * @param int $post_id
     * @return array<string,int>
     */
    function tpi_content_translation_map(int $post_id): array
    {
        if ($post_id <= 0) {
            return [];
        }

        $map = [];
        $raw = get_post_meta($post_id, '_tpi_translations', true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        } elseif (is_array($raw)) {
            $map = $raw;
        }

        // 自身语言
        $own = function_exists('tpi_get_post_language') ? (string) tpi_get_post_language($post_id) : '';
        if ($own !== '' && empty($map[$own])) {
            $map[$own] = $post_id;
        }

        // 反向补全：目标内容只记了自己那一侧时，从对方 meta 里捞回来
        foreach ($map as $one_lang => $one_id) {
            $one_id = (int) $one_id;
            if ($one_id <= 0 || $one_id === $post_id) {
                continue;
            }
            $other = get_post_meta($one_id, '_tpi_translations', true);
            if (is_string($other) && $other !== '') {
                $other = json_decode($other, true);
            }
            if (!is_array($other)) {
                continue;
            }
            foreach ($other as $other_lang => $other_id) {
                $other_id = (int) $other_id;
                if ($other_id > 0 && empty($map[$other_lang])) {
                    $map[$other_lang] = $other_id;
                }
            }
        }

        return array_map('intval', $map);
    }
}

if (!function_exists('tpi_bare_content_path')) {
    /**
     * 当前请求的「去掉语言前缀的路径」，给缺失译文补 URL 用。
     *
     * /ja/product/x → /product/x，/product/x → /product/x，/ → /
     */
    function tpi_bare_content_path(string $current_lang, string $default_lang): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        // WordPress 装在子目录时，先把站点子目录摘掉
        $home_path = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path !== '' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }
        if ($path === '') {
            $path = '/';
        }

        if ($current_lang !== $default_lang) {
            $prefix = '/' . $current_lang;
            if ($path === $prefix || $path === $prefix . '/') {
                $path = '/';
            } elseif (strpos($path, $prefix . '/') === 0) {
                $path = substr($path, strlen($prefix));
            }
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }
}

if (!function_exists('tpi_is_language_home_page')) {
    /**
     * 这个页面是不是某个语言的「首页」（默认语言 page_on_front 及其各语言译文）
     */
    function tpi_is_language_home_page(int $post_id): bool
    {
        if ($post_id <= 0) {
            return false;
        }
        $front_id = (int) tpi_raw_page_on_front();
        if ($front_id <= 0) {
            return false;
        }
        if ($post_id === $front_id) {
            return true;
        }
        $map = tpi_content_translation_map($front_id);

        return in_array($post_id, array_map('intval', $map), true);
    }
}

if (!function_exists('tpi_current_content_urls')) {
    /**
     * 当前页面在各语言下的地址（每个已启用语言都有一条）
     *
     * 域名用「当前访问域名」（tpi_request_root()），跟随访客实际访问的那个：
     *   hreflang  → 访客地址栏里是什么域名，就是什么域名
     *   canonical → 后台「站点与路径 → 目标源」配置的域名（没填就跟 hreflang 一致）
     *
     * 取址顺序：
     *   1. `_tpi_translations` 映射里的已发布译文（各语言 slug 可以不同）；
     *   2. 同 slug 兜底（页面这类各语言 slug 相同的）；
     *   3. 都没有 → 按「语言前缀 + 当前路径」补齐（线上详情页就是这种形态，
     *      这样每个语言都有一条 hreflang，而不是只剩默认语言一条）。
     *
     * 第 3 步可以用过滤器 tpi_hreflang_fill_missing 关掉（关掉就只输出真实存在的译文）。
     */
    function tpi_current_content_urls(): array
    {
        $languages = tpi_language_slugs();
        if (!$languages) {
            return [];
        }

        $default = tpi_default_language();
        $origin  = tpi_request_root();
        $fill    = (bool) apply_filters('tpi_hreflang_fill_missing', true);
        $urls    = [];

        // 404 页面不该给 hreflang
        if (is_404()) {
            return [];
        }

        // 首页 / 博客首页：每个语言取该语言自己的首页
        if (is_front_page() || is_home()) {
            foreach ($languages as $one_lang) {
                $urls[$one_lang] = tpi_url_on_root(tpi_language_home_url($one_lang), $origin);
            }
            return $urls;
        }

        $bare         = tpi_bare_content_path(tpi_current_language(), $default);
        $post_id      = is_singular() ? (int) get_queried_object_id() : 0;
        $map          = $post_id > 0 ? tpi_content_translation_map($post_id) : [];
        $post_slug    = $post_id > 0 ? (string) get_post_field('post_name', $post_id) : '';
        $post_type    = $post_id > 0 ? (string) get_post_type($post_id) : '';

        // 某个语言的「首页」页面（/ru/home 这类）：按首页处理，
        // 否则会拼出 /home 这种根本不存在的地址。
        if ($post_id > 0 && tpi_is_language_home_page($post_id)) {
            foreach ($languages as $one_lang) {
                $urls[$one_lang] = tpi_url_on_root(tpi_language_home_url($one_lang), $origin);
            }
            return $urls;
        }

        foreach ($languages as $one_lang) {
            // 1) 译文映射
            $target = (int) ($map[$one_lang] ?? 0);
            if (($target <= 0 || get_post_status($target) !== 'publish')
                && $post_id > 0 && $post_slug !== '' && $post_type !== '') {
                // 2) 同 slug 兜底
                $same = tpi_get_post_id_by_slug($post_slug, $one_lang, $post_type);
                if ($same > 0 && get_post_status($same) === 'publish') {
                    $target = $same;
                }
            }
            if ($target > 0 && get_post_status($target) === 'publish') {
                $urls[$one_lang] = tpi_url_on_root((string) get_permalink($target), $origin);
                continue;
            }

            // 3) 补齐：语言前缀 + 当前路径
            if (!$fill) {
                continue;
            }
            $urls[$one_lang] = rtrim($origin, '/') . ($one_lang === $default ? $bare : '/' . $one_lang . $bare);
        }

        return $urls;
    }
}

if (!function_exists('tpi_filter_locale')) {
    /** 前台按当前语言切换 locale（<html lang>、日期格式等） */
    function tpi_filter_locale($locale)
    {
        if (is_admin() || $locale === '' ) {
            return $locale;
        }
        $current = tpi_current_language();
        if ($current === tpi_default_language()) {
            return $locale;
        }
        $item = tpi_language_by_slug($current);
        $target = $item ? (string) $item['locale'] : $current;
        return $target !== '' ? $target : $locale;
    }
}
add_filter('locale', 'tpi_filter_locale', 5);

if (!function_exists('tpi_language_attributes')) {
    /**
     * <html lang> 跟随「当前内容语言」，而不是 WP 后台的站点语言
     *
     * language_attributes() 取的是 get_bloginfo('language')，即「设置 → 常规 → 站点语言」。
     * 后台是简体中文时，所有语言版本的源码都会写成 <html lang="zh-Hans">，
     * 英文 / 日文页面也不例外，对 SEO 与读屏软件都是错的。
     * 这里按当前语言包里的 locale 覆盖掉，locale 的下划线换成 BCP 47 的连字符。
     */
    function tpi_language_attributes($output, $doctype = 'html')
    {
        // 后台不跟内容语言走：后台界面语言固定由「设置 → 常规 → 站点语言」决定。
        // 后台带 ?lang=ja 的页签（邮箱设置、语言内容等）会让 tpi_current_language()
        // 返回 ja，这里一改就成了 <html lang="ja">，浏览器随即改用日文字体渲染
        // 简体中文界面，同码点汉字全变成日文字形，看起来像整页繁体。
        if (is_admin() || $doctype === 'xhtml' || !is_string($output) || $output === '') {
            return $output;
        }
        $locale = trim(tpi_current_locale());
        if ($locale === '') {
            return $output;
        }
        $lang = tpi_locale_tag($locale);
        if ($lang === '') {
            return $output;
        }
        $replaced = preg_replace('/\blang=(["\']).*?\1/i', 'lang="' . esc_attr($lang) . '"', $output, 1);
        return $replaced !== null ? $replaced : $output;
    }
}
add_filter('language_attributes', 'tpi_language_attributes', 10, 2);

if (!function_exists('tpi_redirect_language_root')) {
    /**
     * /ja/ 这类「只有语言前缀」的地址，跳到该语言首页（原来是 Polylang 在做）
     */
    function tpi_redirect_language_root(): void
    {
        if (!is_404()) {
            return;
        }
        $path = trim(tpi_request_path(), '/');
        if ($path === '' || strpos($path, '/') !== false) {
            return;
        }
        $slug = strtolower($path);
        if (!in_array($slug, tpi_language_slugs(), true)) {
            return;
        }
        $target = tpi_language_home_url($slug);
        // 目标还是自己（该语言没有首页内容）时退回站点首页，别打转
        if (untrailingslashit($target) === untrailingslashit('http://' . ($_SERVER['HTTP_HOST'] ?? '') . '/' . $slug)) {
            $target = home_url('/');
        }
        wp_safe_redirect($target, 301);
        exit;
    }
}
add_action('template_redirect', 'tpi_redirect_language_root', 1);

/* =========================================================
 * 六、前台列表按语言过滤
 *
 * 内容的语言归属是 taxonomy（tpi_lang），但前台所有列表查询
 * （产品页、文章页、侧栏「新製品」、搜索结果……）原来都没有加这个条件，
 * 于是 8 种语言的内容混在一个列表里。
 * 这里统一给前台查询补上「只取当前语言」的条件，一次管住所有入口。
 *
 * 注意：REST / AJAX 请求 is_admin() 也是 false，但那两种场景
 * （区块编辑器搜索、后台下拉）不应该被前台语言限制，直接跳过。
 * ========================================================= */

add_action('pre_get_posts', 'tpi_filter_front_query_by_language', 20);

if (!function_exists('tpi_filter_front_query_by_language')) {
    function tpi_filter_front_query_by_language($query): void
    {
        if (!($query instanceof WP_Query)) {
            return;
        }
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        // 允许个别查询显式跨语言（例如版本对照、全站统计）
        if ($query->get('tpi_all_languages')) {
            return;
        }

        $types = tpi_lang_post_types();
        $type  = $query->get('post_type');

        if ($type === '' || $type === 'any') {
            // 没指定类型 = 默认的 post，属于要按语言区分的内容
            $matched = true;
        } elseif (is_array($type)) {
            $matched = (bool) array_intersect(array_map('strval', $type), $types);
        } else {
            $matched = in_array((string) $type, $types, true);
        }
        if (!$matched) {
            return;
        }

        $lang = tpi_current_language();
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return;
        }

        $tax_query = (array) $query->get('tax_query');
        foreach ($tax_query as $clause) {
            if (!is_array($clause)) {
                continue;
            }
            if (($clause['taxonomy'] ?? '') === 'tpi_lang') {
                return; // 已经有语言条件，交给调用方
            }
        }
        $tax_query[] = [
            'taxonomy'         => 'tpi_lang',
            'field'            => 'slug',
            'terms'            => $lang,
            'operator'         => 'IN',
            'include_children' => false,
        ];
        $query->set('tax_query', $tax_query);
    }
}

/* =========================================================
 * 七、站点 logo 指向当前语言首页
 *
 * the_custom_logo() 输出的链接永远是 home_url('/')，在 /ja/、/ko/
 * 这些页面上点 logo 会掉回默认语言。这里把 href 换成当前语言首页。
 * ========================================================= */

add_filter('get_custom_logo', 'tpi_localize_custom_logo', 10, 2);

if (!function_exists('tpi_localize_custom_logo')) {
    function tpi_localize_custom_logo($html, $blog_id = 0)
    {
        $html = (string) $html;
        if ($html === '' || is_admin() || wp_doing_ajax()) {
            return $html;
        }
        $current = tpi_current_language();
        if ($current === '' || $current === tpi_default_language()) {
            return $html;
        }
        $home  = tpi_language_home_url($current);
        $plain = untrailingslashit(home_url('/'));
        if ($home === '' || $home === home_url('/')) {
            return $html;
        }
        // 只替换指向站点根的那一个链接
        return str_replace(
            ['href="' . home_url('/') . '"', 'href="' . $plain . '"'],
            'href="' . esc_url($home) . '"',
            $html
        );
    }
}
