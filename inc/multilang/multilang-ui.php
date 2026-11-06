<?php
/**
 * 多语言的界面部分（不依赖 Polylang）：
 *
 *   1. 编辑页「语言」面板：文章 / 页面 / 产品 / 应用产品 / FAQ 新增与编辑时选语言
 *   2. 顶部工具栏语言切换：后台任何页面都能看到当前语言并一键切换
 *   3. 前台导航菜单按当前语言过滤（一个菜单里放各语言的项，不串味）
 *   4. 外观 → 菜单：按语言筛选「添加菜单项」列表，并给每个菜单项标语言
 *
 * 数据仍然只写 tpi_lang（taxonomy）与用户 meta tpi_filter_content。
 */

/* =========================================================
 * 零、后台「当前语言」的取值
 * ========================================================= */

if (!function_exists('tpi_admin_current_url')) {
    /** 当前后台地址（去掉 lang 参数），用于语言切换 */
    function tpi_admin_current_url(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '/wp-admin/';
        if ($host === '') {
            return admin_url();
        }
        $scheme = is_ssl() ? 'https://' : 'http://';
        return remove_query_arg('lang', $scheme . $host . $uri);
    }
}

add_action('admin_init', 'tpi_remember_admin_language');

if (!function_exists('tpi_remember_admin_language')) {
    /**
     * 通过 ?lang= 切换后记住这个语言：
     *   1. tpi_filter_content —— 后台列表的语言筛选、新建内容默认语言；
     *   2. tpi_lc_last_lang   —— 「语言内容」页默认打开哪个语言。
     * 两个 meta 必须一起写，否则顶栏切了语言、点「语言内容」还是上一次的语言。
     */
    function tpi_remember_admin_language(): void
    {
        if (!isset($_GET['lang']) || !get_current_user_id()) {
            return;
        }
        $lang = strtolower((string) $_GET['lang']);
        if ($lang === 'all') {
            delete_user_meta(get_current_user_id(), 'tpi_filter_content');
            return;
        }
        if (in_array($lang, tpi_language_slugs(), true)) {
            update_user_meta(get_current_user_id(), 'tpi_filter_content', $lang);
            update_user_meta(get_current_user_id(), 'tpi_lc_last_lang', $lang);
        }
    }
}

/* =========================================================
 * 一、编辑页「语言」面板
 * ========================================================= */

add_action('add_meta_boxes', 'tpi_register_language_metabox');

if (!function_exists('tpi_register_language_metabox')) {
    function tpi_register_language_metabox(): void
    {
        foreach (tpi_lang_post_types() as $post_type) {
            // 区块编辑器用侧栏原生面板；再注册 meta box 的话，
            // Gutenberg 保存后会额外提交一次 metabox 表单，用旧值把语言覆盖回去
            if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type($post_type)) {
                continue;
            }
            add_meta_box(
                'tpi-language-box',
                tpi_admin_ui('multilang.field.lang'),
                'tpi_render_language_metabox',
                $post_type,
                'side',
                'high'
            );
        }
    }
}

if (!function_exists('tpi_editor_pending_language')) {
    /** 新建内容时预选的语言 */
    function tpi_editor_pending_language(): string
    {
        $lang = isset($_GET['lang']) ? strtolower((string) $_GET['lang']) : '';
        if (in_array($lang, tpi_language_slugs(), true)) {
            return $lang;
        }
        return tpi_admin_language_context();
    }
}

if (!function_exists('tpi_render_language_metabox')) {
    function tpi_render_language_metabox($post): void
    {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        $current = $post_id > 0 ? tpi_get_post_language($post_id) : '';
        if ($current === '') {
            $current = tpi_editor_pending_language();
        }

        wp_nonce_field('tpi_lang_box', 'tpi_lang_nonce');

        echo '<div class="tpi-lang-panel">';
        echo '<p class="tpi-lang-panel-hint">'
            . esc_html(tpi_admin_ui('multilang.panel.hint'))
            . '</p>';
        echo '<div class="tpi-lang-grid">';

        foreach (tpi_languages() as $language) {
            $slug = $language['slug'];
            $flag = (string) $language['flag_url'];
            $name = (string) $language['name'];
            $checked = ($slug === $current);

            printf(
                '<label class="tpi-lang-option%s" for="tpi-lang-%s">',
                $checked ? ' is-active' : '',
                esc_attr($slug)
            );
            printf(
                '<input type="radio" id="tpi-lang-%s" name="tpi_post_lang" value="%s"%s>',
                esc_attr($slug),
                esc_attr($slug),
                $checked ? ' checked' : ''
            );
            echo '<span class="tpi-lang-flag">';
            if ($flag !== '') {
                printf('<img src="%s" alt="">', esc_url($flag));
            }
            echo '</span>';
            printf('<span class="tpi-lang-text">%s<code>%s</code></span>', esc_html($name), esc_html($slug));
            echo '</label>';
        }

        echo '</div>';

        if ($post_id > 0 && $current !== '') {
            $home = tpi_language_home_url($current);
            if ($home !== '') {
                printf(
                    '<p class="tpi-lang-panel-foot"><a href="%s" target="_blank" rel="noopener">' . esc_html(tpi_admin_ui('multilang.panel.preview')) . '</a></p>',
                    esc_url($home),
                    esc_html(tpi_language_name($current))
                );
            }
        } else {
            echo '<p class="tpi-lang-panel-foot">'
                . esc_html(tpi_admin_ui('multilang.panel.foot-new'))
                . '</p>';
        }

        echo '</div>';
    }
}

add_action('save_post', 'tpi_save_editor_language', 5, 3);

if (!function_exists('tpi_save_editor_language')) {
    /** 保存「语言」面板的选择 */
    function tpi_save_editor_language(int $post_id, $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!isset($_POST['tpi_post_lang'])) {
            return;
        }
        $nonce = isset($_POST['tpi_lang_nonce']) ? (string) wp_unslash($_POST['tpi_lang_nonce']) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'tpi_lang_box')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $lang = strtolower(sanitize_text_field(wp_unslash($_POST['tpi_post_lang'])));
        if (in_array($lang, tpi_language_slugs(), true)) {
            tpi_set_post_language($post_id, $lang);
        }
    }
}

/* =========================================================
 * 一之二、Gutenberg 原生侧栏面板
 *
 * meta box 在区块编辑器里会被排到侧栏最底部，容易看不见。
 * 所以注册一个 document setting panel 放到「文章」标签顶部，
 * 它写 post meta，再由下面的同步钩子落到 tpi_lang。
 * meta box 保留，作为脚本没加载时的兜底（脚本成功才隐藏它）。
 * ========================================================= */

add_action('init', 'tpi_register_editor_language_meta', 15);

if (!function_exists('tpi_register_editor_language_meta')) {
    function tpi_register_editor_language_meta(): void
    {
        foreach (tpi_lang_post_types() as $post_type) {
            register_post_meta($post_type, '_tpi_post_lang', [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => true,
                'auth_callback'     => function ($allowed, $meta_key = '', $post_id = 0) {
                    return $post_id ? current_user_can('edit_post', $post_id) : $allowed;
                },
                'sanitize_callback' => function ($value) {
                    $value = strtolower((string) $value);
                    return in_array($value, tpi_language_slugs(), true) ? $value : '';
                },
            ]);
        }
    }
}

add_action('added_post_meta', 'tpi_sync_language_from_meta', 10, 4);
add_action('updated_post_meta', 'tpi_sync_language_from_meta', 10, 4);

if (!function_exists('tpi_sync_language_from_meta')) {
    /** REST 保存 meta 之后，把语言落到 taxonomy 上 */
    function tpi_sync_language_from_meta($meta_id, $post_id, $meta_key, $meta_value): void
    {
        if ($meta_key !== '_tpi_post_lang') {
            return;
        }
        $lang = strtolower((string) $meta_value);
        if (in_array($lang, tpi_language_slugs(), true)) {
            tpi_set_post_language($post_id, $lang);
        }
    }
}

add_action('enqueue_block_editor_assets', 'tpi_editor_language_assets');

if (!function_exists('tpi_editor_language_assets')) {
    function tpi_editor_language_assets(): void
    {
        global $version, $post;

        $post_id   = ($post instanceof WP_Post) ? (int) $post->ID : 0;
        $post_type = ($post instanceof WP_Post) ? (string) $post->post_type : 'post';
        $is_saved  = $post_id > 0 && get_post_status($post_id) !== 'auto-draft';

        $current = $is_saved ? tpi_get_post_language($post_id) : '';
        if ($current === '') {
            $current = isset($_GET['lang']) && in_array(strtolower((string) $_GET['lang']), tpi_language_slugs(), true)
                ? strtolower((string) $_GET['lang'])
                : tpi_admin_language_context();
        }

        // 每个语言版本：已存在的给编辑地址，没有的给「添加」地址
        $translations = $is_saved ? tpi_get_post_translations($post_id) : [];

        $langs = [];
        foreach (tpi_languages() as $language) {
            $slug = (string) $language['slug'];
            $id   = (int) ($translations[$slug] ?? 0);
            $name = (string) $language['name'];

            $langs[] = [
                'slug'      => $slug,
                'name'      => $name,
                'flag'      => (string) $language['flag_url'],
                'id'        => $id,
                'title'     => $id ? wp_strip_all_tags(get_the_title($id)) : '',
                'status'    => $id ? (get_post_status($id) ?: '') : '',
                'href'      => $id ? tpi_translation_admin_url($id) : '',
                // 没有版本时由 PHP 生成 POST 表单（含 nonce），前端直接插入
                'addForm'   => ($id === 0 && $is_saved)
                    ? tpi_new_translation_form(
                        $post_id,
                        $slug,
                        '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span>'
                            . esc_html(tpi_admin_ui('multilang.panel.add'))
                            . '</span>',
                        'tpi-tr-act is-add',
                        sprintf(tpi_admin_ui('multilang.action.new-version'), $name)
                    )
                    : '',
                'isCurrent' => ($slug === $current),
            ];
        }

        $data = [
            'postId'   => $post_id,
            'postType' => $post_type,
            'hasId'    => $is_saved,
            'current'  => $current,
            'langs'    => $langs,
        ];

        wp_enqueue_script(
            'tpi-editor-language',
            get_template_directory_uri() . '/assets/wp-assets/js/wp-editor-language.js',
            ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-data', 'wp-element', 'wp-components', 'wp-i18n'],
            $version ?? '1',
            true
        );

        wp_add_inline_script(
            'tpi-editor-language',
            'window.tpiEditorLang = ' . wp_json_encode($data) . ';',
            'before'
        );
    }
}

/* =========================================================
 * 二、顶部工具栏语言切换
 * ========================================================= */
add_action('admin_bar_menu', 'tpi_admin_bar_language_switcher', 41);

if (!function_exists('tpi_admin_bar_language_switcher')) {
    function tpi_admin_bar_language_switcher($wp_admin_bar): void
    {
        if (!is_admin() || !is_user_logged_in()) {
            return;
        }

        // 空串 = 全部语言（不筛选）
        $filter = tpi_admin_language_filter();
        $info   = $filter !== '' ? tpi_language_by_slug($filter) : null;

        if ($info) {
            $flag  = (string) $info['flag_url'];
            $name  = (string) $info['name'];
        } else {
            $flag  = '';
            $name  = tpi_admin_ui('multilang.filter.all');
        }

        $icon = $flag !== ''
            ? '<span class="tpi-ab-flag"><img src="' . esc_url($flag) . '" alt=""></span>'
            : '<span class="ab-icon dashicons-translation"></span>';

        $wp_admin_bar->add_node([
            'id'    => 'tpi-language',
            'title' => $icon . '<span class="tpi-ab-lang-name">' . esc_html($name) . '</span>',
            // 不跳转，鼠标移上去展开语言列表
            'href'  => false,
            'meta'  => ['title' => tpi_admin_ui('multilang.bar.hint')],
        ]);

        // 「所有语言」：清掉筛选，列表恢复显示全部
        $wp_admin_bar->add_node([
            'id'     => 'tpi-language-all',
            'parent' => 'tpi-language',
            'title'  => '<span class="tpi-ab-lang-item">' . esc_html(tpi_admin_ui('multilang.bar.all')) . '</span>'
                      . '<code>all</code>'
                      . ($filter === '' ? '<span class="tpi-ab-check">✓</span>' : ''),
            'href'   => add_query_arg('lang', 'all', tpi_admin_current_url()),
            'meta'   => ['class' => $filter === '' ? 'tpi-ab-lang-current' : ''],
        ]);

        foreach (tpi_languages() as $language) {
            $is_current = ($language['slug'] === $filter);
            $url = add_query_arg('lang', $language['slug'], tpi_admin_current_url());
            $flag = (string) $language['flag_url'];

            $wp_admin_bar->add_node([
                'id'     => 'tpi-language-' . $language['slug'],
                'parent' => 'tpi-language',
                'title'  => ($flag !== '' ? '<span class="tpi-ab-flag"><img src="' . esc_url($flag) . '" alt=""></span>' : '')
                          . '<span class="tpi-ab-lang-item">' . esc_html($language['name']) . '</span>'
                          . '<code>' . esc_html($language['slug']) . '</code>'
                          . ($is_current ? '<span class="tpi-ab-check">✓</span>' : ''),
                'href'   => $url,
                'meta'   => [
                    'class' => $is_current ? 'tpi-ab-lang-current' : '',
                    'title' => $language['slug'] === tpi_default_language() ? tpi_admin_ui('multilang.bar.default') : '',
                ],
            ]);
        }
    }
}

/* =========================================================
 * 三、前台导航只显示当前语言
 *
 * 这套主题的导航不是 WP 菜单，而是页面列表（Custom_Walker_Nav_Menu
 * 继承 Walker_Page）。所以两条路都要管：
 *   1) 页面列表 → 限定 include 为当前语言的「菜单成员」
 *   2) 以后真建了菜单 → 按菜单项语言过滤
 *
 * 「在不在导航里」由主题菜单名单决定（inc/content/primary-menu.php 的
 * option tpi_primary_menu）：启用主题时建好默认菜单，隐私政策这类页面
 * 一开始就没被加进去，而不是渲染时按 slug 隐藏。
 * ========================================================= */

if (!function_exists('tpi_page_ids_by_language')) {
    /** 某个语言下所有已发布页面的 ID */
    function tpi_page_ids_by_language(string $lang): array
    {
        if ($lang === '') {
            return [];
        }
        $ids = get_posts([
            'post_type'              => 'page',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => ['menu_order' => 'ASC', 'post_title' => 'ASC'],
            'tax_query'              => [[
                'taxonomy' => 'tpi_lang',
                'field'    => 'slug',
                'terms'    => $lang,
            ]],
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ]);
        return array_map('intval', (array) $ids);
    }
}

if (!function_exists('tpi_nav_page_ids_by_language')) {
    /**
     * 导航成员的页面 ID：主题菜单（Osiera Menu）里属于当前语言、且已发布的页面
     *
     * 顺序就是菜单里的顺序（外观 → 菜单能拖）；菜单没建、被删、或这个语言
     * 一项都没有时，退回「默认口径」：本语言已发布页面减去默认不加入的页面，
     * 什么都不隐藏，导航不会莫名空掉。
     */
    function tpi_nav_page_ids_by_language(string $lang): array
    {
        if (!function_exists('tpi_primary_menu_page_ids')) {
            return tpi_page_ids_by_language($lang);
        }

        $ids = tpi_primary_menu_page_ids($lang);
        if ($ids) {
            return $ids;
        }

        return tpi_primary_menu_default_ids($lang);
    }
}

add_filter('wp_page_menu_args', 'tpi_limit_page_menu_by_language');

if (!function_exists('tpi_limit_page_menu_by_language')) {
    /** 页面列表兜底时，只列出当前语言的页面（原来会把 8 种语言的页面全列进来） */
    function tpi_limit_page_menu_by_language($args)
    {
        if (is_admin()) {
            return $args;
        }
        $ids = tpi_nav_page_ids_by_language(tpi_current_language());
        $args['include'] = $ids ? implode(',', $ids) : '0';
        return $args;
    }
}

if (!function_exists('tpi_nav_item_language')) {
    /**
     * 菜单项的语言：按指向的对象判断；自定义链接看 URL 前缀
     */
    function tpi_nav_item_language($item): string
    {
        if (!($item instanceof WP_Post)) {
            return '';
        }

        if ($item->type === 'post_type') {
            return tpi_get_post_language((int) $item->object_id);
        }

        if ($item->type === 'custom') {
            $path = (string) wp_parse_url((string) $item->url, PHP_URL_PATH);
            return $path !== '' ? tpi_language_from_path($path) : '';
        }

        return '';
    }
}

add_filter('wp_nav_menu_objects', 'tpi_filter_nav_menu_by_language', 20, 2);

if (!function_exists('tpi_filter_nav_menu_by_language')) {
    /**
     * 一个菜单里可以同时放所有语言的项，前台只输出当前语言那一套。
     * 没有语言归属的项（自定义链接、分类等）原样保留，避免老数据突然消失。
     */
    function tpi_filter_nav_menu_by_language($items, $args = null)
    {
        if (is_admin() || !is_array($items) || !$items) {
            return $items;
        }

        $current = tpi_current_language();
        $remove  = [];

        foreach ($items as $item) {
            $lang = tpi_nav_item_language($item);
            if ($lang !== '' && $lang !== $current) {
                $remove[$item->ID] = true;
            }
        }

        if (!$remove) {
            return $items;
        }

        // 父项被移除时，子项一起移除，避免变成孤儿顶级项
        $parents = [];
        foreach ($items as $item) {
            $parents[$item->ID] = (int) $item->menu_item_parent;
        }
        foreach ($items as $item) {
            $parent = (int) $item->menu_item_parent;
            $guard  = 0;
            while ($parent > 0 && $guard < 20) {
                if (isset($remove[$parent])) {
                    $remove[$item->ID] = true;
                    break;
                }
                $parent = (int) ($parents[$parent] ?? 0);
                $guard++;
            }
        }

        $kept = [];
        foreach ($items as $item) {
            if (!isset($remove[$item->ID])) {
                $kept[] = $item;
            }
        }
        return $kept;
    }
}

/* =========================================================
 * 四、外观 → 菜单：按语言筛选
 * ========================================================= */

if (!function_exists('tpi_menu_admin_language')) {
    /** 菜单页当前筛选的语言；空串 = 全部语言 */
    function tpi_menu_admin_language(): string
    {
        $lang = isset($_GET['tpi_menu_lang']) ? strtolower((string) $_GET['tpi_menu_lang']) : '';
        if ($lang === 'all') {
            return '';
        }
        if (in_array($lang, tpi_language_slugs(), true)) {
            return $lang;
        }
        // 没选过时默认只看当前语言
        return tpi_admin_language_context();
    }
}

add_filter('nav_menu_meta_box_object', 'tpi_filter_nav_menu_metabox_query', 20);

if (!function_exists('tpi_filter_nav_menu_metabox_query')) {
    /** 「添加菜单项」里的内容列表只显示选中语言的条目 */
    function tpi_filter_nav_menu_metabox_query($object)
    {
        if (!($object instanceof WP_Post_Type)) {
            return $object;
        }
        if (!in_array($object->name, tpi_lang_post_types(), true)) {
            return $object;
        }

        $lang = tpi_menu_admin_language();
        if ($lang === '') {
            return $object;
        }

        $default_query = (array) ($object->_default_query ?? []);
        $tax_query     = (array) ($default_query['tax_query'] ?? []);
        $tax_query[]   = [
            'taxonomy' => 'tpi_lang',
            'field'    => 'slug',
            'terms'    => $lang,
        ];
        $default_query['tax_query'] = $tax_query;
        $object->_default_query     = $default_query;

        return $object;
    }
}

add_action('admin_notices', 'tpi_render_menu_language_bar');

if (!function_exists('tpi_render_menu_language_bar')) {
    /** 菜单页顶部语言标签，点了刷新页面只显示该语言的内容 */
    function tpi_render_menu_language_bar(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'nav-menus') {
            return;
        }

        // 还没选菜单时页面只有「创建菜单」表单，没有「添加菜单项」可筛
        $menu_id = isset($_REQUEST['menu']) ? (int) $_REQUEST['menu'] : 0;
        if ($menu_id <= 0) {
            return;
        }

        $current = tpi_menu_admin_language();
        $base    = tpi_admin_current_url();

        echo '<div class="tpi-menu-langbar" id="tpi-menu-langbar">';
        echo '<span class="tpi-menu-langbar-title">' . esc_html(tpi_admin_ui('multilang.menu-bar.title')) . '</span>';

        $all_active = ($current === '') ? ' is-active' : '';
        printf(
            '<a class="tpi-menu-langtab%s" href="%s">%s</a>',
            $all_active,
            esc_url(add_query_arg('tpi_menu_lang', 'all', $base)),
            esc_html(tpi_admin_ui('multilang.filter.all'))
        );

        foreach (tpi_languages() as $language) {
            $active = ($language['slug'] === $current) ? ' is-active' : '';
            $flag   = (string) $language['flag_url'];
            printf(
                '<a class="tpi-menu-langtab%s" href="%s">%s<span>%s</span></a>',
                $active,
                esc_url(add_query_arg('tpi_menu_lang', $language['slug'], $base)),
                $flag !== '' ? '<img src="' . esc_url($flag) . '" alt="">' : '',
                esc_html($language['name'])
            );
        }

        echo '<span class="tpi-menu-langbar-tip">'
            . esc_html(tpi_admin_ui('multilang.menu-bar.tip'))
            . '</span>';
        echo '</div>';
    }
}

add_action('wp_nav_menu_item_custom_fields', 'tpi_render_menu_item_language', 10, 4);

if (!function_exists('tpi_render_menu_item_language')) {
    /** 菜单结构里每个项标出语言，方便核对 */
    function tpi_render_menu_item_language($item_id, $item, $depth = 0, $args = null): void
    {
        if (!($item instanceof WP_Post)) {
            return;
        }
        $lang = tpi_nav_item_language($item);
        if ($lang === '') {
            return;
        }
        $info = tpi_language_by_slug($lang);
        $flag = $info ? (string) $info['flag_url'] : '';
        $name = $info ? (string) $info['name'] : strtoupper($lang);
        $is_default = ($lang === tpi_default_language());

        echo '<p class="tpi-menu-item-lang">';
        echo '<span class="tpi-menu-item-lang-label">' . esc_html(tpi_admin_ui('multilang.field.lang')) . '</span>';
        echo '<span class="tpi-menu-item-lang-value">';
        if ($flag !== '') {
            printf('<img src="%s" alt="">', esc_url($flag));
        }
        echo esc_html($name);
        printf('<code>%s</code>', esc_html($lang));
        if ($is_default) {
            echo '<em>' . esc_html(tpi_admin_ui('multilang.menu-item.default')) . '</em>';
        }
        echo '</span>';
        echo '</p>';
    }
}

/* =========================================================
 * 五、后台小脚本：面板交互 + 把语言条挪进「添加菜单项」
 * ========================================================= */

add_action('admin_footer', 'tpi_lang_ui_script');

if (!function_exists('tpi_lang_ui_script')) {
    function tpi_lang_ui_script(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }
        $is_editor = in_array($screen->base, ['post', 'post-new'], true);
        $is_menu   = ($screen->id === 'nav-menus');
        if (!$is_editor && !$is_menu) {
            return;
        }
        ?>
        <script>
        (function () {
            // 1) 语言面板：选中态
            function syncOptions() {
                document.querySelectorAll('.tpi-lang-option').forEach(function (label) {
                    var input = label.querySelector('input[type="radio"]');
                    label.classList.toggle('is-active', !!(input && input.checked));
                });
            }
            document.addEventListener('change', function (e) {
                if (e.target && e.target.name === 'tpi_post_lang') {
                    syncOptions();
                }
            });
            syncOptions();

            // 2) 菜单页：把语言标签条挪到「添加菜单项」最上方
            var bar = document.getElementById('tpi-menu-langbar');
            var meta = document.getElementById('nav-menu-meta');
            if (bar && meta) {
                meta.insertBefore(bar, meta.firstChild);
                bar.classList.add('is-in-meta');
                var notice = bar.parentElement;
                if (notice && notice.classList.contains('notice')) {
                    notice.style.display = 'none';
                }
            }
        })();
        </script>
        <?php
    }
}

/* =========================================================
 * 六、前台主导航输出
 *
 * 主题导航是「页面列表」结构（Custom_Walker_Nav_Menu 继承 Walker_Page），
 * wp_page_menu 会把 ul 套两层，所以这里自己拼。
 *
 * 成员和顺序都来自主题菜单（外观 → 菜单 → Osiera Menu，见
 * inc/content/primary-menu.php）：按菜单顺序取页面对象，再交给同一个 walker，
 * 所以输出结构和以前用 wp_list_pages 完全一致（父子层级仍按页面的 parent）。
 * ========================================================= */

if (!function_exists('tpi_render_primary_menu')) {
    function tpi_render_primary_menu(array $args = []): void
    {
        $args = wp_parse_args($args, [
            'menu_class'          => 'main-menu mobile-main-menu',
            'children_link_after' => '',
            'walker'              => new Custom_Walker_Nav_Menu(),
        ]);

        $ids = tpi_nav_page_ids_by_language(tpi_current_language());

        echo '<ul class="' . esc_attr($args['menu_class']) . '">';

        $pages = [];
        foreach ($ids as $id) {
            $page = get_post($id);
            if ($page instanceof WP_Post && $page->post_type === 'page' && $page->post_status === 'publish') {
                $pages[] = $page;   // 顺序 = 菜单顺序
            }
        }

        if ($pages) {
            // wp_list_pages 内部走的 walk_page_tree 就是这两步：
            // 先标记谁有子页，再把当前对象 ID 一起交给 walker
            $args['pages_with_children'] = [];
            foreach ($pages as $page) {
                if ($page->post_parent) {
                    $args['pages_with_children'][$page->post_parent] = true;
                }
            }
            echo $args['walker']->walk($pages, 0, $args, get_queried_object_id());
        }

        echo '</ul>';
    }
}
