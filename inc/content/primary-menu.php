<?php
/**
 * 主题菜单（外观 → 菜单里的 Osiera Menu / slug: osiera-menu）
 *
 * 前台主导航是「页面列表」（Custom_Walker_Nav_Menu 继承 Walker_Page），
 * 但「哪些页面在导航里、按什么顺序」以一条真实的 WP 菜单为准：
 *
 *   外观 → 菜单 → Osiera Menu
 *
 * 约定：
 *   1) 启用主题时建好这条菜单，并按 tpi_theme_setup_pages() 的顺序把页面
 *      加成菜单项（整组页面都进导航，隐私政策这种页脚页面默认不进）；
 *   2) 一个菜单装所有语言的项，前台按「页面自己的语言」取当前语言那一套；
 *      菜单项标题后面带语言代码，方便在后台一眼认出；
 *   3) 新建页面 / 草稿首次发布 → 自动追加菜单项；
 *      换语言不用搬（项指向页面，语言跟着页面走）；
 *   4) 菜单被删掉或某个语言一项都没有 → 前台退回「默认口径」：本语言已发布
 *      页面减去默认不加入的那几个，导航不会突然空掉。
 *
 * 后台「页面」列表的「导航」列就是这条菜单的增删入口。
 */

if (!defined('TPI_PRIMARY_MENU_VERSION')) {
    define('TPI_PRIMARY_MENU_VERSION', 3);
}

if (!function_exists('tpi_default_menu_excluded_slugs')) {
    /**
     * 默认菜单「不加入」的页面 slug
     *
     * 隐私政策属于页脚页面：页面照常发布、页脚底部有链接，但不占导航位，
     * 所以默认排除。要再加别的页默认不进导航，挂 filter 追加即可：
     *   add_filter('tpi_default_menu_excluded_slugs', fn($s) => array_merge($s, ['refund-policy']));
     * 反过来想让隐私政策回到导航，就把它从这个数组里摘掉：
     *   add_filter('tpi_default_menu_excluded_slugs', fn($s) => array_values(array_diff($s, ['privacy-policy'])));
     * 这些只在建菜单 / 自动追加 / 一次性清理时生效，手工加的项不会被删。
     */
    function tpi_default_menu_excluded_slugs(): array
    {
        return (array) apply_filters('tpi_default_menu_excluded_slugs', ['privacy-policy']);
    }
}

if (!function_exists('tpi_primary_menu_slug')) {
    /** 菜单 slug（后台显示名是 Osiera Menu） */
    function tpi_primary_menu_slug(): string
    {
        return 'osiera-menu';
    }
}

if (!function_exists('tpi_primary_menu_menu')) {
    /**
     * 菜单对象；没建过返回 null
     *
     * @param bool $reset 清缓存（刚建完菜单要清一次）
     */
    function tpi_primary_menu_menu(bool $reset = false)
    {
        static $menu = null;

        if ($reset) {
            $menu = null;
        }
        if ($menu !== null) {
            return $menu === false ? null : $menu;
        }

        $found = wp_get_nav_menu_object(tpi_primary_menu_slug());
        $menu  = ($found && !is_wp_error($found)) ? $found : false;
        return $menu === false ? null : $menu;
    }
}

if (!function_exists('tpi_primary_menu_menu_id')) {
    function tpi_primary_menu_menu_id(): int
    {
        $menu = tpi_primary_menu_menu();
        return $menu ? (int) $menu->term_id : 0;
    }
}

if (!function_exists('tpi_primary_menu_create')) {
    /**
     * 建菜单并挂到 main-menu 位置
     *
     * 已经有就复用；位置已经被别的菜单占着就不抢，避免盖掉用户自己的设置。
     *
     * @return int 菜单 ID（失败 0）
     */
    function tpi_primary_menu_create(): int
    {
        $menu_id = tpi_primary_menu_menu_id();
        if ($menu_id > 0) {
            return $menu_id;
        }

        $name    = (string) tpi_admin_ui('menu.name');
        $created = wp_create_nav_menu($name);

        if (!is_wp_error($created)) {
            $menu_id = (int) $created;
        } else {
            // 同名/同 slug 的菜单已经存在（wp_create_nav_menu 会报 menu_exists）
            foreach ([tpi_primary_menu_slug(), $name] as $needle) {
                $existing = wp_get_nav_menu_object($needle);
                if ($existing && !is_wp_error($existing)) {
                    $menu_id = (int) $existing->term_id;
                    break;
                }
            }
        }

        tpi_primary_menu_menu(true); // 清静态缓存，后面要读到新菜单
        tpi_primary_menu_items(true);

        if ($menu_id <= 0) {
            return 0;
        }

        $locations = (array) get_theme_mod('nav_menu_locations', []);
        if (empty($locations['main-menu'])) {
            $locations['main-menu'] = $menu_id;
            set_theme_mod('nav_menu_locations', $locations);
        }

        return $menu_id;
    }
}

if (!function_exists('tpi_primary_menu_items')) {
    /**
     * 菜单里的全部项（按菜单顺序；没菜单返回空数组）
     *
     * @param bool $reset 清缓存（增删项之后要清）
     */
    function tpi_primary_menu_items(bool $reset = false): array
    {
        static $items = null;

        if ($reset) {
            $items = null;
        }
        if ($items !== null) {
            return $items;
        }

        $menu_id = tpi_primary_menu_menu_id();
        if ($menu_id <= 0) {
            $items = [];
            return $items;
        }

        $found = wp_get_nav_menu_items($menu_id, ['post_status' => 'publish']);
        $items = is_array($found) ? $found : [];
        return $items;
    }
}

if (!function_exists('tpi_primary_menu_item_for')) {
    /** 某个页面在菜单里的项 ID；没有返回 0 */
    function tpi_primary_menu_item_for(int $post_id): int
    {
        if ($post_id <= 0) {
            return 0;
        }
        foreach (tpi_primary_menu_items() as $item) {
            if ($item->type === 'post_type' && $item->object === 'page' && (int) $item->object_id === $post_id) {
                return (int) $item->ID;
            }
        }
        return 0;
    }
}

if (!function_exists('tpi_primary_menu_has_page')) {
    function tpi_primary_menu_has_page(int $post_id): bool
    {
        return tpi_primary_menu_item_for($post_id) > 0;
    }
}

if (!function_exists('tpi_primary_menu_page_ids')) {
    /**
     * 菜单里属于某个语言的页面 ID（顺序 = 菜单顺序）
     *
     * 语言取页面自己的 tpi_lang；批量查一次，避免上百个项逐个查术语。
     */
    function tpi_primary_menu_page_ids(string $lang): array
    {
        if ($lang === '') {
            return [];
        }

        $items      = tpi_primary_menu_items();
        $object_ids = [];
        foreach ($items as $item) {
            if ($item->type === 'post_type' && $item->object === 'page') {
                $object_ids[] = (int) $item->object_id;
            }
        }
        if (!$object_ids) {
            return [];
        }

        $langs = [];
        $terms = wp_get_object_terms($object_ids, 'tpi_lang', ['fields' => 'all_with_object_id']);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $langs[(int) $term->object_id] = (string) $term->slug;
            }
        }

        $ids = [];
        foreach ($items as $item) {
            if ($item->type !== 'post_type' || $item->object !== 'page') {
                continue;
            }
            $object_id = (int) $item->object_id;
            if (($langs[$object_id] ?? '') !== $lang) {
                continue;
            }
            if (get_post_status($object_id) !== 'publish' || get_post_type($object_id) !== 'page') {
                continue;
            }
            $ids[] = $object_id;
        }

        return array_values(array_unique($ids));
    }
}

if (!function_exists('tpi_primary_menu_default_ids')) {
    /**
     * 某个语言的「默认菜单成员」：该语言已发布页面里去掉默认不加入的那几个
     *
     * 菜单没建、被删、或者这个语言一项都没有时的兜底口径。
     */
    function tpi_primary_menu_default_ids(string $lang): array
    {
        $excluded = tpi_default_menu_excluded_slugs();
        $ids      = [];

        foreach (tpi_page_ids_by_language($lang) as $id) {
            if (in_array((string) get_post_field('post_name', $id), $excluded, true)) {
                continue;
            }
            $ids[] = $id;
        }
        return $ids;
    }
}

if (!function_exists('tpi_primary_menu_add_page')) {
    /**
     * 把页面加成菜单项（追加到末尾）
     *
     * @return int 新项 ID；失败 0
     */
    function tpi_primary_menu_add_page(int $post_id, string $lang = ''): int
    {
        if ($post_id <= 0 || get_post_type($post_id) !== 'page') {
            return 0;
        }
        if ($lang === '') {
            $lang = (string) tpi_get_post_language($post_id);
        }

        $menu_id = tpi_primary_menu_create();
        if ($menu_id <= 0) {
            return 0;
        }

        $title = (string) get_the_title($post_id);
        if ($lang !== '') {
            $title .= ' (' . $lang . ')';
        }

        $item_id = wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title'     => $title,
            'menu-item-object'    => 'page',
            'menu-item-object-id' => $post_id,
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => 0,
        ]);

        tpi_primary_menu_items(true);

        if (is_wp_error($item_id)) {
            return 0;
        }
        return (int) $item_id;
    }
}

if (!function_exists('tpi_primary_menu_remove_page')) {
    /** 把页面从菜单里摘掉 —— 只删指向它的菜单项，不碰页面本身 */
    function tpi_primary_menu_remove_page(int $post_id): bool
    {
        $item_id = tpi_primary_menu_item_for($post_id);
        if ($item_id <= 0) {
            return false;
        }
        $done = (bool) wp_delete_post($item_id, true);
        tpi_primary_menu_items(true);
        return $done;
    }
}

if (!function_exists('tpi_primary_menu_prune_excluded')) {
    /**
     * 把「默认不进导航」的页面的菜单项摘掉（只删菜单项，页面本身还在）
     *
     * 菜单名单以前是按整组页面全量建的，老站升级后菜单里还留着隐私政策，
     * 这里一次性收敛。返回删掉了几项。
     */
    function tpi_primary_menu_prune_excluded(): int
    {
        $excluded = tpi_default_menu_excluded_slugs();
        if (!$excluded) {
            return 0;
        }

        $removed = 0;
        foreach (tpi_primary_menu_items() as $item) {
            if ($item->type !== 'post_type' || $item->object !== 'page') {
                continue;
            }
            $slug = (string) get_post_field('post_name', (int) $item->object_id);
            if (!in_array($slug, $excluded, true)) {
                continue;
            }
            if (wp_delete_post((int) $item->ID, true)) {
                $removed++;
            }
        }

        if ($removed) {
            tpi_primary_menu_items(true);
        }
        return $removed;
    }
}

if (!function_exists('tpi_primary_menu_reorder')) {
    /**
     * 按「语言分组 + 页面 menu_order」把菜单项顺序理顺
     *
     * 只调整 menu_order（直接 UPDATE，不触发保存钩子）：语言按语言表顺序排，
     * 组内按页面自己的排序值，自定义链接等非页面项留在最后。
     *
     * 只在这种场景调用：补项之后（不然后补的项会落在菜单最末尾，和前台
     * 原先的视觉顺序不一致）。已成型的菜单不会被动，手工拖过的顺序保留。
     */
    function tpi_primary_menu_reorder(): void
    {
        global $wpdb;

        $items = tpi_primary_menu_items();
        if (!$items) {
            return;
        }

        $lang_order = [];
        foreach (tpi_languages() as $i => $language) {
            $lang_order[(string) ($language['slug'] ?? '')] = (int) $i;
        }

        $object_ids = [];
        foreach ($items as $item) {
            if ($item->type === 'post_type' && $item->object === 'page') {
                $object_ids[] = (int) $item->object_id;
            }
        }
        $langs = [];
        if ($object_ids) {
            $terms = wp_get_object_terms($object_ids, 'tpi_lang', ['fields' => 'all_with_object_id']);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $langs[(int) $term->object_id] = (string) $term->slug;
                }
            }
        }

        $rows = [];
        foreach ($items as $item) {
            if ($item->type === 'post_type' && $item->object === 'page') {
                $object_id = (int) $item->object_id;
                $rows[]    = [
                    'id'    => (int) $item->ID,
                    'group' => $lang_order[$langs[$object_id] ?? ''] ?? 90,
                    'order' => (int) get_post_field('menu_order', $object_id),
                    'title' => (string) get_post_field('post_title', $object_id),
                ];
            } else {
                $rows[] = [
                    'id'    => (int) $item->ID,
                    'group' => 99,
                    'order' => (int) $item->menu_order,
                    'title' => (string) $item->title,
                ];
            }
        }

        usort($rows, static function ($a, $b) {
            if ($a['group'] !== $b['group']) {
                return $a['group'] <=> $b['group'];
            }
            if ($a['order'] !== $b['order']) {
                return $a['order'] <=> $b['order'];
            }
            return strcmp($a['title'], $b['title']);
        });

        $pos = 1;
        foreach ($rows as $row) {
            $wpdb->update($wpdb->posts, ['menu_order' => $pos], ['ID' => $row['id']]);
            clean_post_cache($row['id']);
            $pos++;
        }

        tpi_primary_menu_items(true);
    }
}

if (!function_exists('tpi_primary_menu_build')) {
    /**
     * 建/补菜单：每个语言按 menu_order 顺序补齐缺的项，已有的项一律不动
     *
     * @param bool $force 清空重建（会丢手工加的项，慎用）
     * @return array{menu:int,added:array}
     */
    function tpi_primary_menu_build(bool $force = false): array
    {
        $report = ['menu' => 0, 'added' => []];

        $menu_id = tpi_primary_menu_create();
        if ($menu_id <= 0) {
            return $report;
        }
        $report['menu'] = $menu_id;

        if ($force) {
            foreach (tpi_primary_menu_items() as $item) {
                wp_delete_post((int) $item->ID, true);
            }
            tpi_primary_menu_items(true);
        }

        $existing = [];
        foreach (tpi_primary_menu_items() as $item) {
            if ($item->type === 'post_type' && $item->object === 'page') {
                $existing[(int) $item->object_id] = true;
            }
        }

        foreach (tpi_languages() as $language) {
            $lang = (string) ($language['slug'] ?? '');
            if ($lang === '') {
                continue;
            }
            foreach (tpi_primary_menu_default_ids($lang) as $id) {
                if (isset($existing[$id])) {
                    continue;
                }
                if (tpi_primary_menu_add_page($id, $lang) > 0) {
                    $existing[$id]     = true;
                    $report['added'][] = $id;
                }
            }
        }

        // 补过项才理顺顺序：后补的项默认落在菜单末尾，和前台原先的视觉顺序
        // 不一致；没有补项时不动，手工拖过的顺序原样保留。
        if ($report['added']) {
            tpi_primary_menu_reorder();
        }

        return $report;
    }
}

if (!function_exists('tpi_primary_menu_sync_page')) {
    /**
     * 新页面进菜单：只在「新建」或「草稿首次发布」时追加，
     * 手动移出的页面不会因为后续编辑被塞回去。
     */
    function tpi_primary_menu_sync_page($post_id, $post = null, $update = false, $post_before = null): void
    {
        static $deferred = [];

        if (!($post instanceof WP_Post) || $post->post_type !== 'page') {
            return;
        }
        if ($post->post_status !== 'publish') {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($update && $post_before instanceof WP_Post && $post_before->post_status === 'publish') {
            return; // 已发布页面改内容：不动菜单
        }
        if (in_array((string) $post->post_name, tpi_default_menu_excluded_slugs(), true)) {
            return;
        }
        if (tpi_primary_menu_has_page((int) $post_id)) {
            return;
        }

        $lang = function_exists('tpi_get_post_language') ? (string) tpi_get_post_language($post_id) : '';
        if ($lang === '') {
            // 语言归属通常在 wp_insert_post 之后才写入，推到请求末尾再判；
            // 到那时还没有语言归属的页面不进菜单。
            if (!isset($deferred[$post_id])) {
                $deferred[$post_id] = true;
                add_action('shutdown', static function () use ($post_id): void {
                    if (get_post_type($post_id) !== 'page') {
                        return;
                    }
                    tpi_primary_menu_sync_page($post_id, get_post($post_id), true, null);
                }, 5);
            }
            return;
        }

        tpi_primary_menu_add_page((int) $post_id, $lang);
    }
}
add_action('wp_after_insert_post', 'tpi_primary_menu_sync_page', 20, 4);

/**
 * 老站兜底：主题早就启用着（after_switch_theme 不会再触发），
 * 用 option 记一版，只跑一次；想重建把 option 删掉即可。
 */
add_action('admin_init', function (): void {
    $built = (int) get_option('tpi_primary_menu_built');
    if ($built >= TPI_PRIMARY_MENU_VERSION) {
        return;
    }
    if ($built > 0) {
        // 升级清理：以前菜单是全量建的，把现在默认不进导航的页面（隐私政策）摘掉
        tpi_primary_menu_prune_excluded();
    }
    tpi_primary_menu_build();
    update_option('tpi_primary_menu_built', TPI_PRIMARY_MENU_VERSION);
}, 23);

/* =========================================================
 * 后台：「页面」列表的「导航」列
 *
 * 增删的就是 Osiera Menu 里的菜单项，和外观 → 菜单是同一份数据，
 * 在哪边改效果都一样。
 * ========================================================= */

add_filter('manage_pages_columns', 'tpi_add_nav_column', 21);

if (!function_exists('tpi_add_nav_column')) {
    function tpi_add_nav_column(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'tpi_lang') {
                $new['tpi_nav'] = tpi_admin_ui('multilang.column.nav');
            }
        }
        if (!isset($new['tpi_nav'])) {
            $new['tpi_nav'] = tpi_admin_ui('multilang.column.nav');
        }
        return $new;
    }
}

add_action('manage_pages_custom_column', 'tpi_render_nav_column', 11, 2);

if (!function_exists('tpi_render_nav_column')) {
    function tpi_render_nav_column(string $column, int $post_id): void
    {
        if ($column !== 'tpi_nav') {
            return;
        }

        if (get_post_status($post_id) !== 'publish') {
            echo '<span class="tpi-nav-cell is-muted" title="'
                . esc_attr(tpi_admin_ui('multilang.column.nav-draft')) . '">—</span>';
            return;
        }

        $in  = tpi_primary_menu_has_page($post_id);
        $url = wp_nonce_url(
            add_query_arg([
                'action' => 'tpi_nav_toggle',
                'post'   => $post_id,
            ], admin_url('admin-post.php')),
            'tpi_nav_toggle_' . $post_id
        );

        printf(
            '<a class="tpi-nav-cell %1$s" href="%2$s" aria-label="%3$s"><span class="dashicons %4$s" aria-hidden="true"></span>%5$s</a>',
            $in ? 'is-in' : 'is-out',
            esc_url($url),
            esc_attr($in
                ? tpi_admin_ui('multilang.column.nav-remove')
                : tpi_admin_ui('multilang.column.nav-add')),
            $in ? 'dashicons-visibility' : 'dashicons-hidden',
            esc_html($in
                ? tpi_admin_ui('multilang.column.nav-in')
                : tpi_admin_ui('multilang.column.nav-out'))
        );
    }
}

add_action('admin_post_tpi_nav_toggle', 'tpi_handle_nav_toggle');

if (!function_exists('tpi_handle_nav_toggle')) {
    function tpi_handle_nav_toggle(): void
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        check_admin_referer('tpi_nav_toggle_' . $post_id);

        if ($post_id <= 0 || get_post_type($post_id) !== 'page' || !current_user_can('edit_page', $post_id)) {
            wp_die(esc_html(tpi_admin_ui('multilang.column.nav-denied')));
        }

        if (tpi_primary_menu_has_page($post_id)) {
            tpi_primary_menu_remove_page($post_id);
        } else {
            tpi_primary_menu_add_page($post_id);
        }

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url('edit.php?post_type=page'));
        exit;
    }
}
