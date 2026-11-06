<?php
/**
 * 页面排序（menu_order）
 *
 * 问题：页面默认 menu_order 全是 0，而前台导航 wp_list_pages 用的是
 * sort_column = 'menu_order, post_title' —— 排序值全一样时就退化成按标题
 * 字母序，于是「首页最后才建」就会排到中间甚至末尾。
 *
 * 这里管三件事：
 *   1) 已知 slug 有一套固定序位（首页恒为 10，永远最前），与建页先后无关；
 *   2) 新建页面 menu_order 为 0 时自动落位：已知 slug 用固定序位，
 *      其它页面顺延到已有页面之后；
 *   3) 幂等回填：整组页面都没排过序（全是 0）的老站，按固定序位补一遍。
 *
 * 约定：menu_order = 0 视为「自动排序」，保存时会被落位；
 * 想手动指定就把值改成非 0（列表页快速编辑 / 页面属性 → 排序）。
 * 已经有非 0 排序值的站不会被改写，手动排过的一律尊重。
 */

if (!function_exists('tpi_page_order_tail')) {
    /**
     * 尾部区起始序位
     *
     * 隐私政策属于尾部页面：它默认不进导航（见 tpi_default_menu_excluded_slugs），
     * 但排序值仍压在最后，页面列表 / 后台都排在末尾；
     * 新加的页面顺延到正文页之后、尾部之前。
     */
    function tpi_page_order_tail(): int
    {
        return 9000;
    }
}

if (!function_exists('tpi_page_order_slots')) {
    /**
     * slug => 固定序位
     *
     * 顺序直接取自 tpi_theme_setup_pages() 的声明顺序，保证「建页顺序」
     * 与「导航顺序」只有一份来源；隐私政策是页脚页面、不进导航，
     * 排序值仍单独压在最后。
     */
    function tpi_page_order_slots(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $tail   = ['privacy-policy'];
        $slots  = [];
        $step   = 0;

        $pages = function_exists('tpi_theme_setup_pages') ? tpi_theme_setup_pages() : [];
        foreach (array_keys($pages) as $slug) {
            if (in_array($slug, $tail, true)) {
                continue;
            }
            $step += 10;
            $slots[$slug] = $step;
        }
        foreach ($tail as $i => $slug) {
            $slots[$slug] = tpi_page_order_tail() + $i * 10;
        }

        $cache = (array) apply_filters('tpi_page_order_slots', $slots);
        return $cache;
    }
}

if (!function_exists('tpi_page_next_order')) {
    /**
     * 顺延序位：已有页面里（小于尾部区）的最大排序值 + 10
     *
     * 用于「主题不认识的自定义页面」——新加的页面排在所有正文页之后、
     * 尾部页面之前，既不会插到首页前面，也不会跑到隐私政策后面。
     */
    function tpi_page_next_order(int $exclude_id = 0): int
    {
        global $wpdb;

        $tail = tpi_page_order_tail();
        $sql  = "SELECT MAX(menu_order) FROM {$wpdb->posts}"
              . " WHERE post_type = 'page'"
              . " AND post_status NOT IN ('auto-draft','trash','inherit')"
              . " AND menu_order > 0 AND menu_order < %d";
        $args = [$tail];
        if ($exclude_id > 0) {
            $sql   .= ' AND ID <> %d';
            $args[] = $exclude_id;
        }

        $max  = (int) $wpdb->get_var($wpdb->prepare($sql, $args));
        $next = $max + 10;

        // 逼近尾部区就收住，避免和 privacy-policy 撞位
        return $next >= $tail ? $tail - 10 : $next;
    }
}

if (!function_exists('tpi_page_setup_order')) {
    /**
     * 某个 slug 建页时该用的排序值
     *
     * 已知 slug 用固定序位（首页 = 10，永远最前），主题不认识的顺延到末尾。
     */
    function tpi_page_setup_order(string $slug, int $exclude_id = 0): int
    {
        $slots = tpi_page_order_slots();
        $order = (int) ($slots[$slug] ?? 0);
        return $order > 0 ? $order : tpi_page_next_order($exclude_id);
    }
}

if (!function_exists('tpi_page_slug_of')) {
    /** 取一个待写入页面的 slug：post_name 优先，没有就用标题推 */
    function tpi_page_slug_of(array $data): string
    {
        $slug = (string) ($data['post_name'] ?? '');
        if ($slug === '' || $slug === 'auto-draft') {
            $slug = sanitize_title((string) ($data['post_title'] ?? ''));
        }
        return $slug;
    }
}

if (!function_exists('tpi_page_language_unordered')) {
    /**
     * 该页面所在语言里，除它自己以外是否全都没排过序
     *
     * 用来保护「已经手排过顺序的站」：只要同语言有任何一个页面是非 0，
     * 就不去改这条 0 —— 否则把首页从 0 改成 10，反而会掉到 products 后面。
     */
    function tpi_page_language_unordered(int $post_id): bool
    {
        if ($post_id <= 0 || !function_exists('tpi_page_ids_by_language')) {
            return false;
        }
        $lang = function_exists('tpi_get_post_language') ? (string) tpi_get_post_language($post_id) : '';
        if ($lang === '') {
            return false;
        }
        foreach (tpi_page_ids_by_language($lang) as $id) {
            if ($id === $post_id) {
                continue;
            }
            if ((int) get_post_field('menu_order', $id) > 0) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('tpi_page_default_menu_order')) {
    /**
     * 页面保存时给 menu_order = 0 自动落位
     *
     * - 新建页面（ID 为空）：已知 slug 用固定序位，其它顺延；
     * - 更新页面：只有「同语言全组都没排过序」时才补，避免毁掉手排顺序。
     */
    function tpi_page_default_menu_order(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'page') {
            return $data;
        }
        if (in_array((string) ($data['post_status'] ?? ''), ['auto-draft', 'inherit', 'trash'], true)) {
            return $data;
        }
        if ((int) ($data['menu_order'] ?? 0) !== 0) {
            return $data;   // 已经有排序值，用户自己管
        }

        $post_id = (int) ($postarr['ID'] ?? 0);

        if ($post_id > 0) {
            // 记录里已经有排序值 → 用户自己排的，一律不动
            if ((int) get_post_field('menu_order', $post_id) !== 0) {
                return $data;
            }
            // 草稿首次发布：算新页面，照常落位
            $stored_status = (string) get_post_field('post_status', $post_id);
            $first_publish = in_array($stored_status, ['auto-draft', 'draft', 'pending'], true)
                && (string) ($data['post_status'] ?? '') === 'publish';
            // 老页面（本来就是 0）：只有整组都没排过序才补，避免把首页从 0 改到
            // 10 之后掉到 products 后面，毁掉手排过的站
            if (!$first_publish && !tpi_page_language_unordered($post_id)) {
                return $data;
            }
        }

        $slug = tpi_page_slug_of($data);

        $data['menu_order'] = tpi_page_setup_order($slug, $post_id);
        return $data;
    }
}
add_filter('wp_insert_post_data', 'tpi_page_default_menu_order', 20, 2);

if (!function_exists('tpi_page_order_backfill')) {
    /**
     * 老站回填：整组页面都没排过序时，按固定序位补一遍
     *
     * 只写 menu_order（直接 UPDATE，不触发 wp_update_post），
     * 避免改掉 post_modified、也避免再走一次 wp_insert_post_data。
     *
     * @return int 实际改动的页面数
     */
    function tpi_page_order_backfill(): int
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, post_name, menu_order FROM {$wpdb->posts}"
            . " WHERE post_type = 'page'"
            . " AND post_status NOT IN ('auto-draft','trash','inherit')"
            . " ORDER BY ID ASC",
            ARRAY_A
        );
        if (!$rows) {
            return 0;
        }

        // 按语言分组；语言取不到的归到 _ 组，单独判断
        $groups = [];
        foreach ($rows as $row) {
            $id   = (int) $row['ID'];
            $lang = function_exists('tpi_get_post_language') ? (string) tpi_get_post_language($id) : '';
            $groups[$lang !== '' ? $lang : '_'][$id] = [
                'slug'  => (string) $row['post_name'],
                'order' => (int) $row['menu_order'],
            ];
        }

        $slots       = tpi_page_order_slots();
        $tail        = tpi_page_order_tail();
        $changed     = 0;

        foreach ($groups as $items) {
            // 组内只要有一个非 0，说明这个站是手排的，整组不动
            $ordered = false;
            foreach ($items as $item) {
                if ($item['order'] > 0) {
                    $ordered = true;
                    break;
                }
            }
            if ($ordered) {
                continue;
            }

            $max     = 0;
            $unknown = [];

            foreach ($items as $id => $item) {
                $slot = (int) ($slots[$item['slug']] ?? 0);
                if ($slot <= 0) {
                    $unknown[] = $id;
                    continue;
                }
                if ($slot < $tail) {
                    $max = max($max, $slot);
                }
                $wpdb->update($wpdb->posts, ['menu_order' => $slot], ['ID' => $id]);
                clean_post_cache($id);
                $changed++;
            }

            // 主题不认识的自定义页面：顺延到正文页之后
            foreach ($unknown as $id) {
                $max += 10;
                if ($max >= $tail) {
                    $max = $tail - 10;
                }
                $wpdb->update($wpdb->posts, ['menu_order' => $max], ['ID' => $id]);
                clean_post_cache($id);
                $changed++;
            }
        }

        return $changed;
    }
}

/**
 * 只在后台跑一次；用 option 记住版本，改了序位想重新回填就把它删掉，
 * 或者把 option 的值改成小于 TPI_PAGE_ORDER_VERSION 的数。
 */
if (!defined('TPI_PAGE_ORDER_VERSION')) {
    define('TPI_PAGE_ORDER_VERSION', 1);
}

add_action('admin_init', function (): void {
    if ((int) get_option('tpi_page_order_filled') >= TPI_PAGE_ORDER_VERSION) {
        return;
    }
    tpi_page_order_backfill();
    update_option('tpi_page_order_filled', TPI_PAGE_ORDER_VERSION);
}, 22);
