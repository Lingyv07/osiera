<?php
/**
 * 默认分类（Uncategorized）的隐性必选与显示规则
 *
 * 目标（四种内容类型：文章 / 产品 / FAQ / 应用产品）：
 *   1. 默认分类是所有内容的「隐性必选」：每条内容都挂着它，谁掉下来就补回去；
 *   2. 它不出现在任何选择列表里（编辑器分类框、快速编辑、批量编辑、列表页「所有分类」下拉），
 *      只在「分类管理」页可见可改；
 *   3. 列表的分类列里：这条内容还有别的分类时不显示它，只有它自己一个时才显示。
 *
 * 默认分类不跟语言混用：每个语言一份（默认语言 slug 是 uncategorized，
 * 其它语言是 uncategorized-{lang}，和 tpu-film-ja 的命名约定一致），
 * 内容按自己的语言挂对应那一份。
 *
 * 还有两条跟「新项目」有关的：
 *   4. 启用主题（或新增语言）时就要把四种类型 × 每个语言的默认分类建好 ——
 *      文章那条是 WordPress 装的，产品 / FAQ / 应用产品 WordPress 不管，得主题自己建；
 *   5. 默认分类不可删除（列表的「删除」、编辑页的「删除」、REST DELETE 全部拦掉）。
 */

if (!function_exists('tpi_default_term_map')) {
    /**
     * 内容类型 => 分类法
     */
    function tpi_default_term_map(): array
    {
        return [
            'post'        => 'category',
            'product'     => 'product_cat',
            'faq'         => 'faq_cat',
            'application' => 'application_cat',
        ];
    }
}

if (!function_exists('tpi_default_term_taxonomies')) {
    /** 需要处理默认分类的分类法 */
    function tpi_default_term_taxonomies(): array
    {
        return array_values(array_unique(tpi_default_term_map()));
    }
}

if (!function_exists('tpi_default_term_head_group')) {
    /**
     * 分类法 => 语言包里对应的列表页分组
     *
     * 默认分类没有自己的文案，它的 SEO 数据（title / description /
     * breadcrumb_list_name）直接取列表页那一套，改语言内容就跟着变。
     */
    function tpi_default_term_head_group(string $taxonomy): string
    {
        return [
            'category'        => 'news',
            'product_cat'     => 'product',
            'faq_cat'         => 'faq',
            'application_cat' => 'applications',
        ][$taxonomy] ?? '';
    }
}

if (!function_exists('tpi_default_term_description_json')) {
    /**
     * 按语言生成默认分类的描述 JSON（格式与分类管理页里其它分类一致）
     *
     * 值做 HTML 转义：描述存的是 HTML 字段，前端直接输出，
     * 这样 `&` 之类的字符不会把结构化数据弄坏。
     */
    function tpi_default_term_description_json(string $taxonomy, string $lang = ''): string
    {
        $group = tpi_default_term_head_group($taxonomy);
        if ($group === '') {
            return '';
        }
        $data = get_lang_json($lang !== '' ? $lang : null);
        $node = $data[$group] ?? [];
        if (!is_array($node)) {
            return '';
        }
        $title = trim((string) ($node['title'] ?? ''));
        $desc  = trim((string) ($node['description'] ?? ''));
        $bcn   = trim((string) ($node['breadcrumb-list-name'] ?? ''));
        if ($title === '' && $desc === '' && $bcn === '') {
            return '';
        }
        return (string) wp_json_encode([
            'title'                => esc_html($title),
            'description'          => esc_html($desc),
            'breadcrumb_list_name' => esc_html($bcn),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}

if (!function_exists('tpi_default_term_sync_description')) {
    /**
     * 把各语言默认分类的描述同步成语言包里的值（迁移 / 加语言 / 改语言内容后调用）
     *
     * @param string|null $taxonomy  只同步某个分类法，空表示全部
     * @param string      $only_lang 只同步某个语言，空表示全部
     * @return array 变更记录
     */
    function tpi_default_term_sync_description(?string $taxonomy = null, string $only_lang = ''): array
    {
        $report = [];
        $taxonomies = $taxonomy ? [$taxonomy] : tpi_default_term_taxonomies();
        $langs = $only_lang !== '' ? [$only_lang] : tpi_language_slugs();
        foreach ($taxonomies as $tax) {
            foreach ($langs as $lang) {
                $term_id = tpi_default_term_id($tax, $lang);
                if ($term_id <= 0) {
                    continue;
                }
                $json = tpi_default_term_description_json($tax, $lang);
                if ($json === '') {
                    continue;
                }
                $term = get_term($term_id, $tax);
                if (!$term || is_wp_error($term)) {
                    continue;
                }
                if (trim((string) $term->description) === trim($json)) {
                    continue;
                }
                $result = wp_update_term($term_id, $tax, ['description' => $json]);
                $report[] = [
                    'taxonomy' => $tax,
                    'lang'     => $lang,
                    'term_id'  => $term_id,
                    'ok'       => !is_wp_error($result),
                ];
            }
        }
        return $report;
    }
}

/*
 * 语言内容一改，默认分类的 SEO 数据跟着走 —— 两边永远同一份文案，
 * 不用在两个地方各维护一遍。
 */
add_action('tpi_lang_content_saved', 'tpi_default_term_on_lang_saved', 20, 1);

if (!function_exists('tpi_default_term_on_lang_saved')) {
    function tpi_default_term_on_lang_saved(string $lang): void
    {
        if ($lang === '') {
            return;
        }
        tpi_default_term_sync_description(null, $lang);
    }
}

if (!function_exists('tpi_default_term_bypass')) {
    /**
     * 内部改写 / 迁移时的开关：打开后不隐藏、不补写，避免自己跟自己打架
     */
    function tpi_default_term_bypass(?bool $set = null): bool
    {
        static $flag = false;
        if ($set !== null) {
            $flag = (bool) $set;
        }
        return $flag;
    }
}

if (!function_exists('tpi_default_term_names')) {
    /**
     * 各语言默认分类的兜底名称（数据库里已有同名术语时以数据库为准）
     */
    function tpi_default_term_names(): array
    {
        return [
            'en' => 'Uncategorized',
            'ru' => 'Без категории',
            'ja' => '未分類',
            'ko' => '미분류',
            'fr' => 'non classé',
            'hi' => 'अवर्गीकृत',
            'de' => 'Nicht kategorisiert',
            'th' => 'ยังไม่แยกหมวดหมู่',
        ];
    }
}

if (!function_exists('tpi_default_term_cache_ref')) {
    /** 默认分类 id 的请求内缓存（新建术语后要能清掉，所以用引用） */
    function &tpi_default_term_cache_ref(): array
    {
        static $cache = [];
        return $cache;
    }
}

if (!function_exists('tpi_default_term_flush_cache')) {
    /** 新建术语后清缓存，同一个请求里后续调用才能立刻拿到 */
    function tpi_default_term_flush_cache(): void
    {
        $cache = &tpi_default_term_cache_ref();
        $cache = [];
    }
}

if (!function_exists('tpi_default_term_ids')) {
    /**
     * 某个分类法下所有「默认分类」的 term_id（含各语言版本）
     *
     * 认 slug：uncategorized / uncategorized-{lang}。历史数据里有
     * uncategorized-th-ru 这种手滑生成的后缀，用前缀匹配一并认下。
     */
    function tpi_default_term_ids(string $taxonomy): array
    {
        $cache = &tpi_default_term_cache_ref();
        if (isset($cache[$taxonomy])) {
            return $cache[$taxonomy];
        }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT t.term_id
               FROM {$wpdb->terms} t
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
              WHERE tt.taxonomy = %s
                AND ( t.slug = %s OR t.slug LIKE %s )",
            $taxonomy,
            'uncategorized',
            $wpdb->esc_like('uncategorized-') . '%'
        ));
        return $cache[$taxonomy] = array_map('intval', $ids ?: []);
    }
}

if (!function_exists('tpi_default_term_name')) {
    /** 默认分类在某个语言下的名称：优先数据库里已有的，其次内置兜底 */
    function tpi_default_term_name(string $lang): string
    {
        static $cache = [];
        if (isset($cache[$lang])) {
            return $cache[$lang];
        }
        $name = '';
        foreach (tpi_default_term_ids('category') as $term_id) {
            if (tpi_get_term_language($term_id) !== $lang) {
                continue;
            }
            $term = get_term($term_id, 'category');
            if ($term && !is_wp_error($term) && $term->name !== '') {
                $name = (string) $term->name;
                break;
            }
        }
        if ($name === '') {
            $names = tpi_default_term_names();
            $name = $names[$lang] ?? $names['en'];
        }
        return $cache[$lang] = $name;
    }
}

if (!function_exists('tpi_default_term_id')) {
    /**
     * 取某个分类法 + 语言下的默认分类 term_id，没有就建一个
     */
    function tpi_default_term_id(string $taxonomy, string $lang = ''): int
    {
        // 缓存放在 tpi_default_term_cache_ref() 里（不是函数静态变量）：
        // 术语被删后要重建时，tpi_default_term_flush_cache() 能把它一起清掉。
        $cache = &tpi_default_term_cache_ref();

        $slugs = tpi_language_slugs();
        if ($lang === '' || !in_array($lang, $slugs, true)) {
            $lang = tpi_default_language();
        }
        $key = 'default:' . $taxonomy . ':' . $lang;
        if (isset($cache[$key])) {
            return (int) $cache[$key];
        }

        // 1) 已经存在：按语言对上号
        foreach (tpi_default_term_ids($taxonomy) as $term_id) {
            if (tpi_get_term_language($term_id) === $lang) {
                update_term_meta($term_id, '_tpi_default_term', 1);
                return $cache[$key] = $term_id;
            }
        }

        // 2) 没有就建：默认语言用 uncategorized，其它语言加 -{lang} 后缀
        $slug = $lang === tpi_default_language() ? 'uncategorized' : 'uncategorized-' . $lang;
        $base = $slug;
        $i = 2;
        while (term_exists($slug, $taxonomy)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        // 新建时连描述（列表页那套 SEO 文案）一起写上，别留个空白的默认分类
        $args = ['slug' => $slug];
        $description = tpi_default_term_description_json($taxonomy, $lang);
        if ($description !== '') {
            $args['description'] = $description;
        }

        $created = wp_insert_term(tpi_default_term_name($lang), $taxonomy, $args);
        if (is_wp_error($created)) {
            $data = $created->get_error_data();
            $term_id = (int) ($data['term_id'] ?? 0);
            if ($term_id <= 0) {
                return 0;
            }
        } else {
            $term_id = (int) $created['term_id'];
        }

        tpi_set_term_language($term_id, $lang);
        update_term_meta($term_id, '_tpi_default_term', 1);
        tpi_default_term_flush_cache();
        return $cache[$key] = $term_id;
    }
}

if (!function_exists('tpi_default_term_enforce_post')) {
    /**
     * 把一条内容的默认分类摆正：该有的一定有，别的语言的那份一定没有
     */
    function tpi_default_term_enforce_post($post_id): void
    {
        if (tpi_default_term_bypass()) {
            return;
        }
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        $map = tpi_default_term_map();
        $taxonomy = $map[$post->post_type] ?? '';
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return;
        }
        if (in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true)) {
            return;
        }

        $lang = (string) tpi_get_post_language($post_id);
        $default_id = tpi_default_term_id($taxonomy, $lang);
        if ($default_id <= 0) {
            return;
        }

        $current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($current)) {
            return;
        }
        $current = array_map('intval', $current);
        $others = array_values(array_diff(array_intersect($current, tpi_default_term_ids($taxonomy)), [$default_id]));

        $need_add = !in_array($default_id, $current, true);
        if (!$need_add && !$others) {
            return;
        }

        tpi_default_term_bypass(true);
        if ($need_add) {
            wp_set_object_terms($post_id, [$default_id], $taxonomy, true);
        }
        foreach ($others as $bad_id) {
            wp_remove_object_terms($post_id, [$bad_id], $taxonomy);
        }
        tpi_default_term_bypass(false);
    }
}

if (!function_exists('tpi_default_term_sync_all')) {
    /**
     * 一次性补齐：给四种内容类型的所有内容挂上默认分类（迁移时手动调）
     */
    function tpi_default_term_sync_all(): array
    {
        $report = ['taxonomy_terms' => [], 'posts' => [], 'missing' => []];
        foreach (tpi_default_term_taxonomies() as $taxonomy) {
            $report['taxonomy_terms'][$taxonomy] = [];
            foreach (tpi_language_slugs() as $lang) {
                $report['taxonomy_terms'][$taxonomy][$lang] = tpi_default_term_id($taxonomy, $lang);
            }
        }

        $map = tpi_default_term_map();
        global $wpdb;
        // 不用 get_posts：主题的「按语言筛选」查询钩子会把它限制成当前语言，
        // 迁移要的是全语言全量，所以直接查 ID。
        $placeholders = implode(',', array_fill(0, count($map), '%s'));
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_type IN ($placeholders)
                AND post_status NOT IN ('auto-draft', 'trash', 'inherit')",
            array_keys($map)
        ));
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true)) {
                continue;
            }
            $taxonomy = $map[$post->post_type] ?? '';
            $default_id = tpi_default_term_id($taxonomy, (string) tpi_get_post_language($post_id));
            if ($default_id <= 0) {
                continue;
            }
            $before = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            $before = is_wp_error($before) ? [] : array_map('intval', $before);
            tpi_default_term_enforce_post($post_id);
            $after = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            $after = is_wp_error($after) ? [] : array_map('intval', $after);
            if (!in_array($default_id, $before, true) && in_array($default_id, $after, true)) {
                $report['posts'][$post->post_type] = ($report['posts'][$post->post_type] ?? 0) + 1;
            }
            if (!in_array($default_id, $after, true)) {
                $report['missing'][] = $post_id;
            }
        }
        return $report;
    }
}

/* ---------------------------------------------------------
 * 一、隐性必选：保存时补回来
 * --------------------------------------------------------- */

add_action('set_object_terms', 'tpi_default_term_on_set_terms', 20, 6);

if (!function_exists('tpi_default_term_on_set_terms')) {
    /**
     * 快速编辑 / 批量编辑 / 编辑器保存都会走 wp_set_object_terms，
     * 这里在分类关系落库之后补一次（默认分类在选择列表里被隐藏，
     * 提交时不会带上它，所以必须由代码补回）。
     */
    function tpi_default_term_on_set_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void
    {
        if (!in_array($taxonomy, tpi_default_term_taxonomies(), true)) {
            return;
        }
        tpi_default_term_enforce_post($object_id);
    }
}

add_action('save_post', 'tpi_default_term_on_save', 99, 1);

if (!function_exists('tpi_default_term_on_save')) {
    /** 新建内容（含信息编辑页的保存）默认就要带上默认分类 */
    function tpi_default_term_on_save($post_id): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        tpi_default_term_enforce_post($post_id);
    }
}

/* ---------------------------------------------------------
 * 二、不出现在选择列表里（编辑器分类框 / 快速编辑 / 批量编辑 / 所有分类下拉）
 *    —— 「分类管理」页仍要能看到、能改，所以那里放行
 * --------------------------------------------------------- */

if (!function_exists('tpi_default_term_is_selection_ui')) {
    function tpi_default_term_is_selection_ui(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            // admin-ajax（快速编辑取行、批量编辑）等拿不到屏幕的地方，按选择列表处理
            return true;
        }
        if (in_array($screen->base, ['edit-tags', 'term'], true)) {
            return false;
        }
        return true;
    }
}

add_filter('get_terms_args', 'tpi_default_term_hide_from_selection', 20, 2);

if (!function_exists('tpi_default_term_hide_from_selection')) {
    function tpi_default_term_hide_from_selection($args, $taxonomies)
    {
        if (tpi_default_term_bypass()) {
            return $args;
        }
        // 带 object_ids 的是「某条内容挂着哪些分类」，属于展示不是选择，不能排除
        if (!empty($args['object_ids'])) {
            return $args;
        }
        // 按 slug / 名称 / 指定 id 的单条查询（分类归档页、按路径取分类）也放行，
        // 只拦「拉一整串分类做列表」的查询
        if (!empty($args['slug']) || !empty($args['name']) || !empty($args['term_taxonomy_id'])
            || !empty($args['term_id']) || !empty($args['include'])) {
            return $args;
        }
        // 后台只在「选择列表」里排掉；分类管理页要能看到、能改
        if (is_admin() && !tpi_default_term_is_selection_ui()) {
            return $args;
        }
        $exclude = [];
        foreach ((array) $taxonomies as $taxonomy) {
            if (in_array($taxonomy, tpi_default_term_taxonomies(), true)) {
                $exclude = array_merge($exclude, tpi_default_term_ids($taxonomy));
            }
        }
        if (!$exclude) {
            return $args;
        }
        $current = $args['exclude'] ?? [];
        if (is_string($current)) {
            $current = array_filter(array_map('intval', explode(',', $current)));
        } elseif (!is_array($current)) {
            $current = [];
        }
        $args['exclude'] = array_values(array_unique(array_merge(array_map('intval', $current), $exclude)));
        return $args;
    }
}

/* ---------------------------------------------------------
 * 二之二、REST 里也藏起来
 *
 * 区块编辑器（Gutenberg）的分类面板不走 get_terms，而是请求
 * /wp-json/wp/v2/categories —— 那边 is_admin() 是 false，
 * 上面那个钩子拦不到，得单独排掉。
 * --------------------------------------------------------- */

foreach (tpi_default_term_taxonomies() as $tpi_default_term_tax) {
    add_filter('rest_' . $tpi_default_term_tax . '_query', static function ($args) use ($tpi_default_term_tax) {
        if (!is_array($args) || tpi_default_term_bypass()) {
            return $args;
        }
        $ids = tpi_default_term_ids($tpi_default_term_tax);
        if (!$ids) {
            return $args;
        }
        $current = $args['exclude'] ?? [];
        $args['exclude'] = array_values(array_unique(array_merge((array) $current, $ids)));
        return $args;
    }, 20);
}

/* ---------------------------------------------------------
 * 二之三、默认分类排到最后
 *
 * 单页面包屑 / 结构化数据都是取「第一个分类」，默认分类按名字排序
 * 可能排到最前面（未分類、Без категории 之类），那样面包屑就没有真分类了。
 * 这里只调整顺序，不删不增。
 * --------------------------------------------------------- */

add_filter('get_the_terms', 'tpi_default_term_put_last', 20, 3);

if (!function_exists('tpi_default_term_put_last')) {
    function tpi_default_term_put_last($terms, $post_id, $taxonomy)
    {
        if (!is_array($terms) || count($terms) < 2 || !in_array($taxonomy, tpi_default_term_taxonomies(), true)) {
            return $terms;
        }
        $ids = tpi_default_term_ids($taxonomy);
        if (!$ids) {
            return $terms;
        }
        $real = [];
        $defaults = [];
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $ids, true)) {
                $defaults[] = $term;
            } else {
                $real[] = $term;
            }
        }
        return $real ? array_merge($real, $defaults) : $terms;
    }
}

/* ---------------------------------------------------------
 * 三、列表分类列：还有别的分类就不显示默认分类
 * --------------------------------------------------------- */
add_filter('post_column_taxonomy_links', 'tpi_default_term_hide_column_links', 20, 3);

if (!function_exists('tpi_default_term_hide_column_links')) {
    function tpi_default_term_hide_column_links($links, $taxonomy, $terms)
    {
        if (!in_array($taxonomy, tpi_default_term_taxonomies(), true)) {
            return $links;
        }
        if (!is_array($terms) || count($terms) < 2 || !is_array($links)) {
            return $links;
        }
        $defaults = tpi_default_term_ids($taxonomy);
        if (!$defaults) {
            return $links;
        }
        $kept = [];
        foreach ($terms as $index => $term) {
            if (in_array((int) $term->term_id, $defaults, true)) {
                continue;
            }
            if (isset($links[$index])) {
                $kept[] = $links[$index];
            }
        }
        return $kept ?: $links;
    }
}

/* ---------------------------------------------------------
 * 四、新项目启用主题就建好：四种类型 × 每个语言的默认分类
 *
 * 文章那条 WordPress 装机时会自己建（default_category），
 * 产品 / FAQ / 应用产品 WordPress 完全不管 —— 新项目启用主题后
 * 它们的分类目录是空的，得主题自己建。
 * --------------------------------------------------------- */

if (!function_exists('tpi_default_term_ensure_terms')) {
    /**
     * 保证每种内容类型在每个语言下都有一份默认分类（缺的建、已有的认）
     *
     * @param bool $sync_description 顺手把描述同步成语言包里的列表页文案
     * @return array{taxonomy_terms:array<string,array<string,int>>,created:int}
     */
    function tpi_default_term_ensure_terms(bool $sync_description = true): array
    {
        $report = ['taxonomy_terms' => [], 'created' => 0];

        // 语言表还没种子好（极新装）时先别建：这时建出来的默认分类没有语言归属，
        // 回头还得再建一份，平白多出 uncategorized-2 这种重复术语。
        $langs = tpi_language_slugs();
        if (!$langs) {
            return $report;
        }

        foreach (tpi_default_term_taxonomies() as $taxonomy) {
            // 主题还没注册成功的分类法跳过，别往不存在的分类法里塞术语
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $report['taxonomy_terms'][$taxonomy] = [];
            foreach ($langs as $lang) {
                $before = tpi_default_term_ids($taxonomy);
                $term_id = tpi_default_term_id($taxonomy, $lang);
                $report['taxonomy_terms'][$taxonomy][$lang] = $term_id;
                if ($term_id > 0 && !in_array($term_id, $before, true)) {
                    $report['created']++;
                }
            }
        }

        if ($sync_description) {
            tpi_default_term_sync_description();
        }

        return $report;
    }
}

if (!function_exists('tpi_default_term_ensure_complete')) {
    /**
     * 这一轮 create 是否真的到位了（四种类型 × 每个语言都拿到 id）
     *
     * 没到位就不写指纹，下次进后台再补 —— 比如启用主题那一刻分类法还没注册好
     * （插件/子主题顺序不同），只写指纹会让缺口永远补不上。
     */
    function tpi_default_term_ensure_complete(array $report): bool
    {
        $taxonomies = tpi_default_term_taxonomies();
        $langs = tpi_language_slugs();
        if (!$langs || count($report['taxonomy_terms'] ?? []) < count($taxonomies)) {
            return false;
        }
        foreach ($report['taxonomy_terms'] as $map) {
            if (count($map) < count($langs)) {
                return false;
            }
            foreach ($map as $term_id) {
                if ((int) $term_id <= 0) {
                    return false;
                }
            }
        }
        return true;
    }
}

if (!function_exists('tpi_default_term_signature')) {
    /**
     * 默认分类的「覆盖面指纹」：分类法清单 + 语言清单
     *
     * 只在指纹变化时才跑一遍 create（启用主题、加语言、加分类法），
     * 平时一次请求只读一个 autoload option，不做查询。
     */
    function tpi_default_term_signature(): string
    {
        if (!function_exists('tpi_language_slugs')) {
            return '';
        }
        return md5(implode(',', tpi_default_term_taxonomies()) . '|' . implode(',', tpi_language_slugs()));
    }
}

/*
 * 启用主题（含重新启用）：立刻补齐。
 * after_switch_theme 在 init 99 由 check_theme_switched() 触发，此时
 * 主题已加载、分类法也已注册，可以直接建术语。
 */
add_action('after_switch_theme', 'tpi_default_term_on_activate', 30);

if (!function_exists('tpi_default_term_on_activate')) {
    function tpi_default_term_on_activate(): void
    {
        if (!function_exists('tpi_default_term_ensure_terms')) {
            return;
        }
        $report = tpi_default_term_ensure_terms(true);
        // 只有真的建全了才记指纹，否则留给后台那次兜底重试
        if (tpi_default_term_ensure_complete($report)) {
            update_option('tpi_default_term_sig', tpi_default_term_signature(), false);
        }
    }
}

/*
 * 后台兜底：新增语言、或启用时语言表还没种子好（指纹对不上），
 * 下次进后台自动补一次。只在后台跑，前台不多做查询。
 */
add_action('init', 'tpi_default_term_maybe_ensure', 99);

if (!function_exists('tpi_default_term_maybe_ensure')) {
    function tpi_default_term_maybe_ensure(): void
    {
        if (!is_admin() || (function_exists('wp_installing') && wp_installing())) {
            return;
        }
        $sig = tpi_default_term_signature();
        if ($sig === '' || (string) get_option('tpi_default_term_sig') === $sig) {
            return;
        }
        // 分类法一个都没注册（主题没加载完 / 子主题顺序怪）就别白跑一遍
        if (!array_filter(tpi_default_term_taxonomies(), 'taxonomy_exists')) {
            return;
        }
        $report = tpi_default_term_ensure_terms(true);
        if (tpi_default_term_ensure_complete($report)) {
            update_option('tpi_default_term_sig', $sig, false);
        }
    }
}

/* ---------------------------------------------------------
 * 五、默认分类不可删除
 *
 * WP 里所有删除入口都先过 current_user_can('delete_term', $term_id)：
 *   - 分类目录列表行的「删除」；
 *   - 分类编辑页底部的「删除」；
 *   - edit-tags.php?action=delete 的提交；
 *   - REST 的 DELETE /wp-json/wp/v2/{taxonomy}/{id}。
 * 在这里统一拒掉，界面上那几个入口会跟着自己消失。
 * --------------------------------------------------------- */

add_filter('map_meta_cap', 'tpi_default_term_block_delete_cap', 20, 4);

if (!function_exists('tpi_default_term_block_delete_cap')) {
    function tpi_default_term_block_delete_cap($caps, $cap, $user_id, $args)
    {
        if ($cap !== 'delete_term' || empty($args[0]) || tpi_default_term_bypass()) {
            return $caps;
        }
        $term_id = (int) $args[0];
        if ($term_id <= 0) {
            return $caps;
        }
        $term = get_term($term_id);
        if (!$term || is_wp_error($term) || !in_array($term->taxonomy, tpi_default_term_taxonomies(), true)) {
            return $caps;
        }
        if (!in_array($term_id, tpi_default_term_ids($term->taxonomy), true)) {
            return $caps;
        }
        return ['do_not_allow'];
    }
}

if (!function_exists('tpi_default_term_lang_from_slug')) {
    /**
     * 从默认分类的 slug 反推它属于哪个语言（认不出来返回空串）
     *
     * 只认 `uncategorized` 与 `uncategorized-{已知语言}`，
     * 历史遗留的 uncategorized-th-ru 这类不动，避免误建。
     */
    function tpi_default_term_lang_from_slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return '';
        }
        if ($slug === 'uncategorized') {
            return tpi_default_language();
        }
        foreach (tpi_language_slugs() as $lang) {
            if ($slug === 'uncategorized-' . $lang) {
                return $lang;
            }
        }
        return '';
    }
}

/*
 * 最后一道保险：绕过界面直接调 wp_delete_term() 的（插件、脚本、WP-CLI），
 * 删完立刻按原 slug 重建，并把原来的内容重新挂回去。
 * 内部迁移要真删的话把 tpi_default_term_bypass(true) 打开。
 */
add_action('delete_term', 'tpi_default_term_restore_if_deleted', 10, 5);

if (!function_exists('tpi_default_term_restore_if_deleted')) {
    function tpi_default_term_restore_if_deleted($term_id, $tt_id, $taxonomy, $deleted_term, $object_ids): void
    {
        if (tpi_default_term_bypass() || !in_array($taxonomy, tpi_default_term_taxonomies(), true)) {
            return;
        }
        if (!is_object($deleted_term) || empty($deleted_term->slug)) {
            return;
        }
        $lang = tpi_default_term_lang_from_slug((string) $deleted_term->slug);
        if ($lang === '') {
            return;
        }

        tpi_default_term_flush_cache();
        tpi_default_term_bypass(true);
        $new_id = tpi_default_term_id($taxonomy, $lang);
        if ($new_id > 0 && !empty($object_ids)) {
            // 注意：wp_set_object_terms 的 $object_id 会被 (int) 强转，传数组只会挂到 id=1 上，
            // 必须一条一条来。计数由它自己维护，不用另外刷新。
            foreach ((array) $object_ids as $object_id) {
                $object_id = (int) $object_id;
                if ($object_id > 0) {
                    wp_set_object_terms($object_id, [$new_id], $taxonomy, true);
                }
            }
        }
        tpi_default_term_bypass(false);
    }
}
