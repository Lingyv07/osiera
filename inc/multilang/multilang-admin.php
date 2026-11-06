<?php
/**
 * 多语言的后台部分：列表页语言列、按语言筛选、保存时写入语言归属。
 * 只依赖 theme 自己的 tpi_lang taxonomy，不依赖 Polylang。
 */

/* =========================================================
 * 一、列表页「语言」列
 * ========================================================= */

add_filter('manage_posts_columns', 'tpi_add_language_column', 20);
add_filter('manage_pages_columns', 'tpi_add_language_column', 20);
foreach (tpi_lang_post_types() as $tpi_cpt) {
    if (in_array($tpi_cpt, ['post', 'page'], true)) {
        continue;
    }
    add_filter('manage_' . $tpi_cpt . '_posts_columns', 'tpi_add_language_column', 20);
}

if (!function_exists('tpi_add_language_column')) {
    function tpi_add_language_column(array $columns): array
    {
        // 放在「日期」左边一列（日期是最后一列，语言跟它挨着最好找）
        $new = [];
        foreach ($columns as $key => $label) {
            // Polylang 自己的语言列（language_xx）不再展示，避免和本页重复
            if ($key !== 'tpi_lang' && strpos((string) $key, 'language') === 0) {
                continue;
            }
            if ($key === 'date') {
                $new['tpi_lang'] = tpi_admin_ui('multilang.column.lang');
            }
            $new[$key] = $label;
        }
        if (!isset($new['tpi_lang'])) {
            $new['tpi_lang'] = tpi_admin_ui('multilang.column.lang');
        }
        return $new;
    }
}

add_action('manage_posts_custom_column', 'tpi_render_language_column', 10, 2);
add_action('manage_pages_custom_column', 'tpi_render_language_column', 10, 2);

if (!function_exists('tpi_render_language_column')) {
    /**
     * 列表里的语言列：把 8 种语言全部列出来 ——
     * 已有该语言版本的显示为实色小旗（点进去就是那个版本的编辑页），
     * 还没有的显示为虚线框 + 号（点一下在当前内容基础上新建该语言草稿）。
     */
    function tpi_render_language_column(string $column, int $post_id): void
    {
        if ($column !== 'tpi_lang') {
            return;
        }
        $lang = tpi_get_post_language($post_id);
        if ($lang === '') {
            echo '<span class="tpi-lang-cell is-empty" data-lang="" data-name="">'
                . esc_html(tpi_admin_ui('multilang.column.unset'))
                . '</span>';
            return;
        }

        $translations = tpi_get_post_translations($post_id);
        $own_name     = tpi_language_name($lang);

        // data-lang / data-name 供「快速编辑」回填，必须留在 .tpi-lang-cell 上
        printf(
            '<div class="tpi-lang-cell tpi-lang-multi" data-lang="%s" data-name="%s">',
            esc_attr($lang),
            esc_attr($own_name)
        );

        foreach (tpi_languages() as $language) {
            $slug  = (string) $language['slug'];
            $name  = (string) $language['name'];
            $flag  = (string) $language['flag_url'];
            $id    = (int) ($translations[$slug] ?? 0);
            $is_on = $id > 0;

            if ($is_on) {
                // 已有版本：实色小旗，点进去切到那个版本
                $img = $flag !== ''
                    ? '<img src="' . esc_url($flag) . '" alt="" class="tpi-lb-flag">'
                    : '<span class="tpi-lb-code">' . esc_html(strtoupper($slug)) . '</span>';
                printf(
                    '<a class="tpi-lb is-on%s" href="%s" title="%s">%s<span class="tpi-lb-ico dashicons dashicons-edit" aria-hidden="true"></span></a>',
                    $slug === $lang ? ' is-active' : '',
                    esc_url(tpi_translation_admin_url($id)),
                    esc_attr(sprintf(tpi_admin_ui('multilang.action.edit-version'), $name)),
                    $img
                );
            } else {
                // 没有版本：浅灰小旗 + 浅色底 + 右下角加号（POST 提交，防链接预取）
                $img = $flag !== ''
                    ? '<img src="' . esc_url($flag) . '" alt="" class="tpi-lb-flag">'
                    : '<span class="tpi-lb-code">' . esc_html(strtoupper($slug)) . '</span>';
                echo tpi_new_translation_form(
                    $post_id,
                    $slug,
                    $img . '<span class="tpi-lb-plus" aria-hidden="true">+</span>',
                    'tpi-lb is-off',
                    sprintf(tpi_admin_ui('multilang.action.add-version'), $name)
                ); // phpcs:ignore WordPress.Security.EscapeOutput
            }
        }

        echo '</div>';
    }
}

/* 语言列的样式统一放在 assets/wp-assets/css/wp-manage.css 的 .tpi-lb 区块，
   这里不再输出内联 <style>，避免两处改不一致。 */

/* =========================================================
 * 二、列表页按语言筛选
 * ========================================================= */

add_action('restrict_manage_posts', 'tpi_render_language_filter');

if (!function_exists('tpi_render_language_filter')) {
    function tpi_render_language_filter(string $post_type = ''): void
    {
        if ($post_type !== '' && !in_array($post_type, tpi_lang_post_types(), true)) {
            return;
        }

        $current = tpi_admin_language_filter();
        $default = tpi_default_language();
        ?>
        <label class="screen-reader-text" for="tpi-lang-filter"><?php echo esc_html(tpi_admin_ui('multilang.filter.aria-label')); ?></label>
        <select name="lang" id="tpi-lang-filter">
            <option value="all" <?php selected($current, ''); ?>><?php echo esc_html(tpi_admin_ui('multilang.filter.all')); ?></option>
            <?php foreach (tpi_languages() as $item): ?>
                <option value="<?php echo esc_attr($item['slug']); ?>" <?php selected($current, $item['slug']); ?>>
                    <?php echo esc_html($item['name']); ?><?php echo $item['slug'] === $default ? esc_html(tpi_admin_ui('multilang.filter.default-suffix')) : ''; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
}

add_action('pre_get_posts', 'tpi_filter_admin_list_by_language');

if (!function_exists('tpi_filter_admin_list_by_language')) {
    function tpi_filter_admin_list_by_language(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        $post_type = $query->get('post_type') ?: 'post';
        if (!in_array($post_type, tpi_lang_post_types(), true)) {
            return;
        }

        // 顶部工具栏选的语言会记住，翻到别的列表页也继续生效
        $lang = tpi_admin_language_filter();
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return;
        }

        $tax_query = (array) $query->get('tax_query');
        $tax_query[] = [
            'taxonomy' => 'tpi_lang',
            'field'    => 'slug',
            'terms'    => $lang,
            'operator' => 'IN',
        ];
        $query->set('tax_query', $tax_query);
    }
}

/* =========================================================
 * 二之二、后台分类目录按语言筛选
 *
 * 后台所有「选分类」的地方（分类列表、分类的父级下拉、文章/产品的
 * 分类目录勾选列表、快速编辑里的分类目录）都走 get_terms()，
 * 统一在这里补上「只取当前语言」的条件，避免 8 种语言的分类混排。
 *
 * 优先用「正在编辑的内容自己的语言」，其次用顶部工具栏选的语言；
 * 顶部选「所有语言」时不做限制。
 * ========================================================= */

if (!function_exists('tpi_admin_terms_language')) {
    /**
     * 后台分类目录该显示哪个语言；空串 = 不筛选。
     * $allow_rest：区块编辑器的分类目录走 REST，那边 is_admin() 是 false。
     */
    function tpi_admin_terms_language(bool $allow_rest = false): string
    {
        if (!$allow_rest && !is_admin()) {
            return '';
        }
        $slugs = tpi_language_slugs();

        // 1) 正在编辑的内容 / 正在编辑的分类
        $post_id = 0;
        if (isset($_GET['post'])) {
            $post_id = (int) $_GET['post'];
        } elseif (isset($_POST['post_ID'])) {
            $post_id = (int) $_POST['post_ID'];
        } elseif ($allow_rest) {
            // REST 请求里没有 post 参数，从来源页（编辑器地址）里取
            $referer = (string) wp_get_referer();
            if ($referer !== '' && preg_match('/[?&](?:post|post_id)=(\d+)/', $referer, $m)) {
                $post_id = (int) $m[1];
            }
        }
        if ($post_id > 0) {
            $lang = tpi_get_post_language($post_id);
            if (in_array($lang, $slugs, true)) {
                return $lang;
            }
        }

        if (!$allow_rest) {
            global $tag, $term;
            $edit_term = $term ?? $tag ?? null;
            if (is_object($edit_term) && !empty($edit_term->term_id)) {
                $lang = tpi_get_term_language($edit_term);
                if (in_array($lang, $slugs, true)) {
                    return $lang;
                }
            }
        }

        // 2) 顶部工具栏 / 列表筛选（?lang= → 用户上次的选择）
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
        if (in_array($saved, $slugs, true)) {
            return $saved;
        }

        // REST 请求找不到任何线索时不做限制，避免编辑器里一个分类都没有
        return $allow_rest ? '' : tpi_admin_language_filter();
    }
}

add_filter('get_terms_args', 'tpi_filter_admin_terms_by_language', 20, 2);

if (!function_exists('tpi_filter_admin_terms_by_language')) {
    function tpi_filter_admin_terms_by_language(array $args, array $taxonomies): array
    {
        static $with_meta = [];

        $targets = ['category', 'product_cat', 'post_tag', 'application_cat', 'faq_cat'];
        if (!array_intersect($taxonomies, $targets)) {
            return $args;
        }
        // 调用方明确要求跨语言（例如数据修复脚本）
        if (!empty($args['tpi_skip_lang'])) {
            return $args;
        }
        // 已经有语言之外的自定义条件时不再叠加，交给调用方
        if (!empty($args['meta_query'])) {
            return $args;
        }

        $lang = tpi_admin_terms_language();
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return $args;
        }

        // 该分类法还没写过语言 meta 时不做限制，避免一条都查不出来
        foreach ($taxonomies as $taxonomy) {
            if (!isset($with_meta[$taxonomy])) {
                global $wpdb;
                $with_meta[$taxonomy] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
                     JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = '_tpi_term_lang'
                     WHERE tt.taxonomy = %s",
                    $taxonomy
                ));
            }
            if ($with_meta[$taxonomy] === 0) {
                return $args;
            }
        }

        $args['meta_query'] = [[
            'key'     => '_tpi_term_lang',
            'value'   => $lang,
            'compare' => '=',
        ]];

        return $args;
    }
}

/* 区块编辑器的「分类目录」不走 get_terms，而是走 REST（/wp/v2/categories），
   这条钩子把同一个语言条件补上，编辑器里也不会再冒出别的语言的分类。 */
foreach (['category', 'product_cat', 'post_tag', 'application_cat', 'faq_cat'] as $tpi_rest_tax) {
    add_filter('rest_' . $tpi_rest_tax . '_query', 'tpi_filter_rest_terms_by_language', 20, 2);
}
unset($tpi_rest_tax);

if (!function_exists('tpi_filter_rest_terms_by_language')) {
    function tpi_filter_rest_terms_by_language($args, $request)
    {
        if (!is_array($args) || !empty($args['meta_query'])) {
            return $args;
        }
        $lang = tpi_admin_terms_language(true);
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return $args;
        }
        $args['meta_query'] = [[
            'key'     => '_tpi_term_lang',
            'value'   => $lang,
            'compare' => '=',
        ]];
        return $args;
    }
}

/* =========================================================
 * 三、保存内容时写入语言归属
 * ========================================================= */

add_action('save_post', 'tpi_assign_language_on_save', 20, 3);

if (!function_exists('tpi_assign_language_on_save')) {
    function tpi_assign_language_on_save(int $post_id, $post, bool $update): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!($post instanceof WP_Post) || !in_array($post->post_type, tpi_lang_post_types(), true)) {
            return;
        }
        if ($post->post_status === 'auto-draft') {
            return;
        }

        $existing = tpi_get_post_language($post_id);
        if ($existing !== '') {
            return;
        }

        // 没有语言时落到「列表筛选语言」或当前语言
        $fallback = (string) get_user_meta(get_current_user_id(), 'tpi_filter_content', true);
        if (!in_array($fallback, tpi_language_slugs(), true)) {
            $fallback = tpi_current_language();
        }
        tpi_set_post_language($post_id, $fallback);
    }
}

/* =========================================================
 * 三之二、列表页直接改语言（快速编辑 / 批量编辑）
 * ========================================================= */

add_action('quick_edit_custom_box', 'tpi_quick_edit_language_box', 10, 2);
add_action('bulk_edit_custom_box', 'tpi_bulk_edit_language_box', 10, 2);

if (!function_exists('tpi_language_select_options')) {
    function tpi_language_select_options(string $selected = '', bool $with_keep = false): void
    {
        if ($with_keep) {
            echo '<option value="">' . esc_html(tpi_admin_ui('multilang.picker.keep')) . '</option>';
        }
        foreach (tpi_languages() as $item) {
            // data-flag 供旁边的小国旗预览联动（<option> 里放不了图片）
            printf(
                '<option value="%s"%s data-flag="%s">%s（%s）</option>',
                esc_attr($item['slug']),
                selected($selected, $item['slug'], false),
                esc_url($item['flag_url']),
                esc_html($item['name']),
                esc_html($item['slug'])
            );
        }
    }
}

if (!function_exists('tpi_language_picker')) {
    /**
     * 带国旗的语言选择器。
     *
     * 原生 <select> 的 <option> 里放不了图片（浏览器不支持），
     * 所以这里用「一个隐藏 input + 一个按钮 + 一个列表」自己画，
     * 提交的是隐藏 input 的值，和普通下拉完全等价。
     * 交互在 assets/wp-assets/js/wp-manage.js 里（事件委托，动态插入的也能用）。
     *
     * @param string $selected  选中值
     * @param string $name      提交用的字段名
     * @param string $id        隐藏 input 的 id；传 '-' 表示不输出 id（列表里会重复）
     * @param bool   $with_keep 是否加「— 不修改 —」这一项（批量编辑用）
     */
    function tpi_language_picker(string $selected = '', string $name = 'tpi_term_lang', string $id = '', bool $with_keep = false): void
    {
        if ($selected === '' && !$with_keep) {
            $selected = tpi_admin_language_context();
        }
        $current = $selected !== '' ? tpi_language_by_slug($selected) : null;
        $id = $id !== '' ? $id : $name;
        $id_attr = $id === '-' ? '' : ' id="' . esc_attr($id) . '"';
        ?>
        <div class="tpi-langpick" data-tpi-langpick>
            <input type="hidden" name="<?php echo esc_attr($name); ?>"<?php echo $id_attr; // phpcs:ignore WordPress.Security.EscapeOutput ?> value="<?php echo esc_attr($selected); ?>">
            <button type="button" class="tpi-langpick-toggle" aria-haspopup="listbox" aria-expanded="false">
                <?php if ($current) : ?>
                    <img class="tpi-langpick-flag" src="<?php echo esc_url((string) ($current['flag_url'] ?? '')); ?>" alt="">
                    <span class="tpi-langpick-name"><?php echo esc_html((string) ($current['name'] ?? strtoupper($selected))); ?></span>
                    <code class="tpi-langpick-code"><?php echo esc_html($selected); ?></code>
                <?php else : ?>
                    <span class="tpi-langpick-name is-empty"><?php echo esc_html(tpi_admin_ui('multilang.picker.keep')); ?></span>
                <?php endif; ?>
                <span class="tpi-langpick-caret dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
            <ul class="tpi-langpick-list" role="listbox" tabindex="-1">
                <?php if ($with_keep) : ?>
                    <li role="option"
                        class="tpi-langpick-item<?php echo $selected === '' ? ' is-selected' : ''; ?>"
                        data-value=""
                        data-name="<?php echo esc_attr(tpi_admin_ui('multilang.picker.keep')); ?>"
                        data-flag=""
                        aria-selected="<?php echo $selected === '' ? 'true' : 'false'; ?>">
                        <span class="tpi-langpick-flag is-blank" aria-hidden="true"></span>
                        <span class="tpi-langpick-name"><?php echo esc_html(tpi_admin_ui('multilang.picker.keep')); ?></span>
                        <span class="tpi-langpick-check dashicons dashicons-yes" aria-hidden="true"></span>
                    </li>
                <?php endif; ?>
                <?php foreach (tpi_languages() as $item) : ?>
                    <li role="option"
                        class="tpi-langpick-item<?php echo $item['slug'] === $selected ? ' is-selected' : ''; ?>"
                        data-value="<?php echo esc_attr($item['slug']); ?>"
                        data-name="<?php echo esc_attr($item['name']); ?>"
                        data-flag="<?php echo esc_url((string) $item['flag_url']); ?>"
                        aria-selected="<?php echo $item['slug'] === $selected ? 'true' : 'false'; ?>">
                        <img class="tpi-langpick-flag" src="<?php echo esc_url((string) $item['flag_url']); ?>" alt="">
                        <span class="tpi-langpick-name"><?php echo esc_html($item['name']); ?></span>
                        <code class="tpi-langpick-code"><?php echo esc_html($item['slug']); ?></code>
                        <span class="tpi-langpick-check dashicons dashicons-yes" aria-hidden="true"></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}

if (!function_exists('tpi_quick_edit_language_box')) {
    function tpi_quick_edit_language_box(string $column, string $post_type): void
    {
        if ($column !== 'tpi_lang' || !in_array($post_type, tpi_lang_post_types(), true)) {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right tpi-quick-lang-field">
            <div class="inline-edit-col">
                <label class="tpi-quick-lang-label">
                    <span class="title"><?php echo esc_html(tpi_admin_ui('multilang.field.lang')); ?></span>
                    <span class="tpi-quick-lang-pick">
                        <?php tpi_language_picker('', 'tpi_quick_lang', '-'); ?>
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }
}

if (!function_exists('tpi_bulk_edit_language_box')) {
    function tpi_bulk_edit_language_box(string $column, string $post_type): void
    {
        if ($column !== 'tpi_lang' || !in_array($post_type, tpi_lang_post_types(), true)) {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right tpi-quick-lang-field">
            <div class="inline-edit-col">
                <label class="tpi-quick-lang-label">
                    <span class="title"><?php echo esc_html(tpi_admin_ui('multilang.field.lang')); ?></span>
                    <span class="tpi-quick-lang-pick">
                        <?php tpi_language_picker('', 'tpi_bulk_lang', '-', true); ?>
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }
}

add_action('save_post', 'tpi_save_quick_edit_language', 30, 3);

if (!function_exists('tpi_save_quick_edit_language')) {
    function tpi_save_quick_edit_language(int $post_id, $post, bool $update): void
    {
        if (!isset($_POST['tpi_quick_lang'])) {
            return;
        }
        if (!($post instanceof WP_Post) || !in_array($post->post_type, tpi_lang_post_types(), true)) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $lang = strtolower(sanitize_text_field(wp_unslash($_POST['tpi_quick_lang'])));
        if (in_array($lang, tpi_language_slugs(), true)) {
            tpi_set_post_language($post_id, $lang);
        }
    }
}

add_action('bulk_edit_posts', 'tpi_save_bulk_edit_language');

if (!function_exists('tpi_save_bulk_edit_language')) {
    function tpi_save_bulk_edit_language($post_ids): void
    {
        if (!isset($_POST['tpi_bulk_lang'])) {
            return;
        }
        $lang = strtolower(sanitize_text_field(wp_unslash($_POST['tpi_bulk_lang'])));
        if (!in_array($lang, tpi_language_slugs(), true)) {
            return;
        }
        foreach ((array) $post_ids as $post_id) {
            if (current_user_can('edit_post', (int) $post_id)) {
                tpi_set_post_language((int) $post_id, $lang);
            }
        }
    }
}

/* =========================================================
 * 三之三、列表页「全部 / 已发布 / 草稿」计数按语言统计
 *
 * 列表被语言筛选后，顶部那排计数仍由 wp_count_posts() 算全站总数，
 * 于是列表只有 4 条、计数却是 28 / 23 / 5。这里把计数换成只算当前语言。
 * ========================================================= */

add_filter('wp_count_posts', 'tpi_count_posts_by_language', 20, 3);

if (!function_exists('tpi_count_posts_by_language')) {
    function tpi_count_posts_by_language($counts, string $type = 'post', string $perm = '')
    {
        if (!is_admin() || !in_array($type, tpi_lang_post_types(), true)) {
            return $counts;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== $type) {
            return $counts;
        }
        $lang = tpi_admin_language_filter();
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return $counts;
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_status AS st, COUNT(DISTINCT p.ID) AS num
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
              WHERE p.post_type = %s AND tt.taxonomy = 'tpi_lang' AND t.slug = %s
              GROUP BY p.post_status",
            $type,
            $lang
        ));

        $out = new stdClass();
        foreach ((array) $counts as $status => $unused) {
            $out->{$status} = 0;
        }
        foreach ((array) $rows as $row) {
            $out->{$row->st} = (int) $row->num;
        }
        return $out;
    }
}

/* 「我的」那一项核心用的是 class-wp-posts-list-table 里的原始 SQL 计数，
   没有过滤器可用，只能在 views_edit-{type} 里把标签里的数字换掉。 */
foreach (tpi_lang_post_types() as $tpi_count_type) {
    add_filter('views_edit-' . $tpi_count_type, 'tpi_fix_mine_count_by_language', 20);
}

if (!function_exists('tpi_fix_mine_count_by_language')) {
    function tpi_fix_mine_count_by_language($views)
    {
        if (!is_array($views) || !isset($views['mine'])) {
            return $views;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') {
            return $views;
        }
        $lang = tpi_admin_language_filter();
        if ($lang === '' || !in_array($lang, tpi_language_slugs(), true)) {
            return $views;
        }

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
              WHERE p.post_type = %s AND p.post_author = %d
                AND p.post_status NOT IN ( 'auto-draft', 'inherit', 'trash' )
                AND tt.taxonomy = 'tpi_lang' AND t.slug = %s",
            $screen->post_type,
            get_current_user_id(),
            $lang
        ));

        // 中文语言包用的是全角括号，两种都要认；带 /u 否则全角括号会被按字节拆开
        $views['mine'] = preg_replace(
            '/(<span class="count">[（(])\d+([）)]<\/span>)/u',
            '${1}' . number_format_i18n($count) . '${2}',
            $views['mine'],
            1
        );
        return $views;
    }
}

/* =========================================================
 * 三之四、编辑入口
 *
 * 列表点标题 → WordPress 标准编辑页 post.php?post=xx&action=edit；
 * 行内「编辑」→ 信息编辑页 admin.php?page=xxx-info（信息编辑页不变）；
 * 「添加新的」→ 先建一条草稿，再跳到信息编辑页继续填内容。
 * ========================================================= */

if (!function_exists('tpi_info_page_map')) {
    /**
     * post type => 信息编辑页 slug
     */
    function tpi_info_page_map(): array
    {
        return [
            'post'        => 'post-info',
            'product'     => 'product-info',
            'application' => 'application-info',
            'faq'         => 'faq-info',
        ];
    }
}

add_filter('post_row_actions', 'tpi_row_action_edit_to_info_page', 11, 2);

if (!function_exists('tpi_row_action_edit_to_info_page')) {
    /**
     * 行内「编辑」仍然进信息编辑页（标题走标准编辑器，两者分工不同）。
     * 只换 href，链接文案 / aria-label / target 全部沿用 WordPress 原本的。
     */
    function tpi_row_action_edit_to_info_page($actions, $post)
    {
        if (!is_array($actions) || !isset($actions['edit']) || !is_object($post)) {
            return $actions;
        }
        $map = tpi_info_page_map();
        if (!isset($map[$post->post_type])) {
            return $actions;
        }
        $args = ['page' => $map[$post->post_type], 'post_id' => $post->ID];
        $lang = tpi_get_post_language($post->ID);
        if ($lang !== '') {
            $args['lang'] = $lang;
        }
        $url = add_query_arg($args, admin_url('admin.php'));
        $actions['edit'] = preg_replace_callback(
            '/href="[^"]*"/',
            static fn() => 'href="' . esc_url($url) . '"',
            $actions['edit'],
            1
        );
        return $actions;
    }
}

add_action('admin_init', 'tpi_redirect_new_post_to_info_page', 5);

if (!function_exists('tpi_redirect_new_post_to_info_page')) {
    /** 列表页「添加新的」：post-new.php → 对应信息编辑页 */
    function tpi_redirect_new_post_to_info_page(): void
    {
        global $pagenow;
        if ($pagenow !== 'post-new.php') {
            return;
        }
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        $map  = tpi_info_page_map();
        if (!isset($map[$type])) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=' . $map[$type]));
        exit;
    }
}

add_action('admin_init', 'tpi_create_draft_for_info_page', 6);

if (!function_exists('tpi_create_draft_for_info_page')) {
    /**
     * 信息编辑页没带 post_id 时，先建一条草稿再重定向过去。
     * 效果和「添加新的」一样，只是少了编辑器那一层。
     */
    function tpi_create_draft_for_info_page(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '') {
            return;
        }
        $map = array_flip(tpi_info_page_map());
        if (!isset($map[$page])) {
            return;
        }
        if (!empty($_GET['post_id'])) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        $type  = $map[$page];
        // 新建草稿的默认标题按「默认语言」给，而不是按当前筛选的语言，
        // 否则在日语/韩语筛选下新建出来的标题会跟站点默认语言对不上。
        $lang = tpi_admin_language_context();

        $post_id = wp_insert_post([
            'post_type'    => $type,
            'post_status'  => 'draft',
            'post_title'   => tpi_default_type_title($type),
            'post_content' => '',
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id) || !$post_id) {
            return;
        }
        tpi_set_post_language((int) $post_id, $lang);
        wp_safe_redirect(add_query_arg(
            ['page' => $page, 'post_id' => (int) $post_id, 'lang' => $lang],
            admin_url('admin.php')
        ));
        exit;
    }
}

add_action('admin_post_tpi_publish_post', 'tpi_publish_post_from_info_page');

if (!function_exists('tpi_publish_post_from_info_page')) {
    /** 信息编辑页顶部的「发布」按钮：把草稿直接改成已发布 */
    function tpi_publish_post_from_info_page(): void
    {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : (int) ($_GET['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_die(esc_html(tpi_admin_ui('multilang.error.missing-post-id')));
        }
        check_admin_referer('tpi_publish_post_' . $post_id);
        if (!current_user_can('publish_post', $post_id) && !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html(tpi_admin_ui('multilang.error.permission')));
        }
        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);

        $map = tpi_info_page_map();
        $post = get_post($post_id);
        $page = $post && isset($map[$post->post_type]) ? $map[$post->post_type] : 'post-info';
        wp_safe_redirect(add_query_arg(
            ['page' => $page, 'post_id' => $post_id, 'lang' => tpi_get_post_language($post_id)],
            admin_url('admin.php')
        ));
        exit;
    }
}

/* =========================================================
 * 三之五、分类 / 标签的语言（产品分类、文章分类）
 * ========================================================= */

foreach (['product_cat', 'category'] as $tpi_tax) {
    add_filter('manage_edit-' . $tpi_tax . '_columns', 'tpi_add_term_language_column', 20);
    add_filter('manage_' . $tpi_tax . '_custom_column', 'tpi_render_term_language_column', 10, 3);
    add_action($tpi_tax . '_add_form_fields', 'tpi_render_term_language_add_field');
    add_action($tpi_tax . '_edit_form_fields', 'tpi_render_term_language_edit_field', 10, 2);
    add_action('created_' . $tpi_tax, 'tpi_save_term_language_from_form', 10, 2);
    add_action('edited_' . $tpi_tax, 'tpi_save_term_language_from_form', 10, 2);
}

if (!function_exists('tpi_add_term_language_column')) {
    function tpi_add_term_language_column(array $columns): array
    {
        $columns['tpi_term_lang'] = tpi_admin_ui('multilang.column.lang');
        return $columns;
    }
}

if (!function_exists('tpi_render_term_language_column')) {
    function tpi_render_term_language_column($content, string $column, int $term_id)
    {
        if ($column !== 'tpi_term_lang') {
            return $content;
        }
        $lang = tpi_get_term_language($term_id);
        $item = tpi_language_by_slug($lang);
        $flag = $item['flag_url'] ?? '';
        $name = $item['name'] ?? strtoupper($lang);

        $html  = '<span class="tpi-lang-cell">';
        if ($flag !== '') {
            $html .= '<img src="' . esc_url($flag) . '" alt="" class="tpi-lang-flag">';
        }
        $html .= '<span class="tpi-lang-name">' . esc_html($name) . '</span>';
        $html .= '<code class="tpi-lang-code">' . esc_html($lang) . '</code>';
        $html .= '</span>';

        return $html;
    }
}

if (!function_exists('tpi_render_term_language_add_field')) {
    function tpi_render_term_language_add_field(): void
    {
        ?>
        <div class="form-field tpi-term-lang-field">
            <label for="tpi_term_lang"><?php echo esc_html(tpi_admin_ui('multilang.field.lang')); ?></label>
            <?php tpi_language_picker(tpi_admin_language_context()); ?>
            <p><?php echo esc_html(tpi_admin_ui('multilang.term-lang.hint')); ?></p>
        </div>
        <?php
    }
}

if (!function_exists('tpi_render_term_language_edit_field')) {
    function tpi_render_term_language_edit_field($term): void
    {
        $current = tpi_get_term_language($term);
        ?>
        <tr class="form-field tpi-term-lang-field">
            <th scope="row"><label for="tpi_term_lang"><?php echo esc_html(tpi_admin_ui('multilang.field.lang')); ?></label></th>
            <td>
                <?php tpi_language_picker($current); ?>
                <p class="description"><?php echo esc_html(tpi_admin_ui('multilang.term-lang.hint')); ?></p>
            </td>
        </tr>
        <?php
    }
}

if (!function_exists('tpi_save_term_language_from_form')) {
    function tpi_save_term_language_from_form(int $term_id, int $tt_id = 0): void
    {
        if (!isset($_POST['tpi_term_lang'])) {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }
        $lang = strtolower(sanitize_text_field(wp_unslash($_POST['tpi_term_lang'])));
        if (in_array($lang, tpi_language_slugs(), true)) {
            tpi_set_term_language($term_id, $lang);
        }
    }
}

add_action('admin_head-term.php', 'tpi_term_language_column_style');
add_action('admin_head-edit-tags.php', 'tpi_term_language_column_style');

if (!function_exists('tpi_term_language_column_style')) {
    function tpi_term_language_column_style(): void
    {
        ?>
        <style>
            .column-tpi_term_lang { width: 132px; }
        </style>
        <?php
    }
}

/* =========================================================
 * 四、语言管理页导航（Polylang 关闭后仍能进入）
 * ========================================================= */

add_action('admin_menu', 'tpi_register_language_menu', 11);
add_action('admin_menu', 'tpi_hide_polylang_menu', 999);

if (!function_exists('tpi_register_language_menu')) {
    function tpi_register_language_menu(): void
    {
        // 语言统一由主题自己管，Polylang 开着也不再用它的语言页
        add_menu_page(
            tpi_admin_ui('multilang.menu.title'),
            tpi_admin_ui('multilang.menu.title'),
            'manage_options',
            'languages',
            'tpi_render_languages_page',
            'dashicons-translation',
            31
        );
    }
}

if (!function_exists('tpi_hide_polylang_menu')) {
    function tpi_hide_polylang_menu(): void
    {
        if (!defined('POLYLANG_VERSION')) {
            return;
        }
        // 去掉 Polylang 的「语言」菜单和「设置 → 语言」，避免两个入口说法不一致
        remove_menu_page('mlang');
        remove_submenu_page('options-general.php', 'mlang');
    }
}

add_action('admin_enqueue_scripts', 'tpi_languages_admin_assets');

if (!function_exists('tpi_languages_admin_assets')) {
    function tpi_languages_admin_assets(string $hook = ''): void
    {
        if (strpos($hook, 'languages') === false) {
            return;
        }
        global $version;
        wp_enqueue_media();
        wp_enqueue_script(
            'tpi-languages',
            get_template_directory_uri() . '/assets/wp-assets/js/wp-languages.js',
            ['jquery', 'jquery-ui-sortable'],
            $version ?? '1',
            true
        );
        wp_localize_script('tpi-languages', 'tpiLangI18n', [
            'dup'           => tpi_admin_ui('languages.js.dup'),
            'dupAlert'      => tpi_admin_ui('languages.js.dup-alert'),
            'lastOne'       => tpi_admin_ui('languages.js.last-one'),
            'delTitle'      => tpi_admin_ui('languages.js.del-title'),
            'delTitlePlain' => tpi_admin_ui('languages.js.del-title-plain'),
            'delTail'       => tpi_admin_ui('languages.js.del-tail'),
            'mediaFail'     => tpi_admin_ui('languages.js.media-fail'),
            'mediaTitle'    => tpi_admin_ui('languages.js.media-title'),
            'mediaButton'   => tpi_admin_ui('languages.js.media-button'),
            'rename'        => tpi_admin_ui('languages.js.rename'),
            'renameConfirm' => tpi_admin_ui('languages.js.rename-confirm'),
        ]);
    }
}
