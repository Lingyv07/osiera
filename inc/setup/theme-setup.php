<?php
/**
 * 新项目启用主题时的基础数据
 *
 * 启用主题（after_switch_theme）时，用默认语言建好一套骨架，
 * 顺序基本就是前台导航顺序：
 *   首页 / 产品 / 新闻 / 应用 / FAQ / 关于我们 / 证书 / 联系 / 隐私政策
 * 其中隐私政策照常发布、页脚底部有链接，但默认不进导航
 * （tpi_default_menu_excluded_slugs）。
 * 并给产品、应用、FAQ、文章各建一个默认分类，同时把首页设成静态首页。
 *
 * 规则：
 *   - 建过就写 option tpi_theme_setup_done，以后不再执行；
 *   - 同语言同 slug 的页面已存在就跳过，不会产生重复页面；
 *   - 只在默认语言下建，其它语言在后台「新增版本」里生成译文。
 *
 * 执行时机（重要）：
 *   after_switch_theme 在 init 之前触发，那时 tpi_lang / nav_menu 这些分类法还没注册，
 *   建页时写不进语言归属、菜单也建不出来。所以这里只在 after_switch_theme 里把版本
 *   清零，真正建数据放在 init 99 的 tpi_theme_setup_auto()（见文件末尾）。
 */

if (!defined('TPI_THEME_SETUP_VERSION')) {
    /**
     * 骨架版本号：改这个数字，所有站下一次请求会自动「补齐」一遍
     * （已存在的页面、分类、菜单项一律不动）。
     *
     *   v1 = 最初的 9 页骨架
     *   v2 = 修「新装站点被误判成老站，骨架页一个都不建」
     */
    define('TPI_THEME_SETUP_VERSION', 2);
}

add_action('after_switch_theme', function (): void {
    // 建表只依赖 $wpdb，不依赖 init 之后才注册的分类法，可以在这里立刻执行。
    // 启用主题 = 数据库结构必须是完整的，不能等到第一次 init 才补。
    if (function_exists('tpi_db_install')) {
        tpi_db_install();
    }

    // 重新启用主题 = 要求补齐一次骨架。真正的建数据交给 init 99，
    // 这里只把版本清零，避免在 init 之前操作分类法。
    update_option('tpi_theme_setup_version', 0);
}, 5);

if (!function_exists('tpi_theme_permalink_reset')) {
    /**
     * 固定链接结构改成「文章名」：/%postname%（结尾不带斜杠）
     *
     * 新装的 WordPress 默认可能是 ?p=123（朴素）或 /%year%/%monthnum%/%day%/%postname%/，
     * 这种结构下 /product/xxx、/application/xxx、/faq/xxx 全是 404，页面也拿不到干净地址，
     * 所以启用主题时统一改成 /%postname%（结尾不带斜杠，避免出现 /products/ 与 /products 两套地址）。
     *
     * 必须走 $wp_rewrite->set_permalink_structure()：
     * 直接 update_option('permalink_structure') 只改数据库，WP_Rewrite 实例里缓存的还是旧结构，
     * 紧接着 flush 会把旧规则原样写回去（选项对了、规则没对）。
     *
     * @param bool $force true = 覆盖已有的自定义结构（启用主题时）；false = 只在空结构时设置
     */
    function tpi_theme_permalink_reset(bool $force = true): bool
    {
        global $wp_rewrite;

        $target = '/%postname%';
        $current = (string) get_option('permalink_structure');

        if ($current === $target) {
            // 结构已是目标值，只在规则表缺失时补一次，避免每次启用都重算
            $rules = get_option('rewrite_rules');
            if (empty($rules) && $wp_rewrite instanceof WP_Rewrite) {
                $wp_rewrite->flush_rules(true);
            }
            return true;
        }

        if (!$force && $current !== '') {
            return false;
        }

        if (!($wp_rewrite instanceof WP_Rewrite)) {
            // 极端情况（后台异步请求里 WP_Rewrite 还没初始化）：只改选项，规则让下次访问重算
            update_option('permalink_structure', $target);
            return true;
        }

        // WP 6.8 的 set_permalink_structure() 只有在「对象里的结构和传入值不同」时才写库，
        // 对象里的结构是 init 时从 option 读的，可能已经过期 —— 先 init() 对齐，否则静默跳过。
        $wp_rewrite->init();
        $wp_rewrite->set_permalink_structure($target);
        // 硬刷新：自定义文章类型的规则跟着一起重建，不用再去「设置 → 固定链接」点保存
        $wp_rewrite->flush_rules(true);
        return true;
    }
}
// 启用主题：无条件改成 /%postname%
add_action('after_switch_theme', 'tpi_theme_permalink_reset', 20);

if (!function_exists('tpi_theme_default_options')) {
    /**
     * 老站兜底：主题一直启用着，after_switch_theme 不会触发，
     * 只在结构为空（朴素模式 = 站点从没配过）时补上，不动已经自定义过的结构。
     */
    function tpi_theme_default_options(): void
    {
        if ((string) get_option('permalink_structure') !== '') {
            return;
        }
        tpi_theme_permalink_reset(false);
    }
}
add_action('admin_init', 'tpi_theme_default_options', 21);
// 前台也要补：全新站启用主题时 after_switch_theme 里跑的是「旧主题」的代码，
// 新主题的钩子根本没注册，固定链接会一直是朴素结构（/products 全 404）。
add_action('init', 'tpi_theme_default_options', 99);

/**
 * 自动补齐骨架（幂等）
 *
 * 挂 init 99（分类法、语言表都已就绪）和 admin_init 20：
 *   - 版本已是最新 → 直接返回（只读一次 option）；
 *   - 版本旧、已建过且骨架页在 → 只补版本号，不重跑；
 *   - 版本旧、骨架页缺失（新站，或被旧逻辑误判过）→ 跑一遍 run 补齐。
 *
 * 老站判定**不能数「页面总数」**：新装 WordPress 自带 sample-page（publish），
 * 隐私政策页也会被发布，两页一凑就 >= 2，全新站会被当成老站，从此一个骨架页都不建
 * （本地站就是这么被卡住的）。只看主题自己的前台页面在不在。
 */
if (!function_exists('tpi_theme_setup_auto')) {
    function tpi_theme_setup_auto(): void
    {
        if ((int) get_option('tpi_theme_setup_version') >= TPI_THEME_SETUP_VERSION) {
            return;
        }
        if (get_option('tpi_theme_setup_done') && tpi_theme_setup_has_skeleton()) {
            update_option('tpi_theme_setup_version', TPI_THEME_SETUP_VERSION);
            return;
        }
        tpi_theme_setup_run();
    }
}
add_action('init', 'tpi_theme_setup_auto', 99);
add_action('admin_init', 'tpi_theme_setup_auto', 20);

if (!function_exists('tpi_theme_setup_skeleton_slugs')) {
    /**
     * 判断「站里已有骨架」用的页面 slug
     *
     * 不含 privacy-policy：新装 WordPress 自带一页同 slug 的页面，主题会采用它，
     * 拿它当依据同样会把新站误判成老站。
     */
    function tpi_theme_setup_skeleton_slugs(): array
    {
        return array_values(array_diff(array_keys(tpi_theme_setup_pages()), ['privacy-policy']));
    }
}

if (!function_exists('tpi_theme_setup_has_skeleton')) {
    /** 主题前台骨架页是否至少建过一页 */
    function tpi_theme_setup_has_skeleton(): bool
    {
        $lang = tpi_default_language();
        foreach (tpi_theme_setup_skeleton_slugs() as $slug) {
            if ((int) tpi_get_post_id_by_slug($slug, $lang, 'page') > 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tpi_theme_setup_pages')) {
    /**
     * slug => [页面标题, 是否设为静态首页]
     *
     * 这里的顺序同时决定三件事，改顺序 = 改前台导航顺序：
     *   1) 启用主题时的建页顺序；
     *   2) 页面 menu_order（page-order.php 按这个顺序落位）；
     *   3) 「Osiera Menu」里菜单项的先后（按页面 menu_order 排）。
     */
    function tpi_theme_setup_pages(): array
    {
        return [
            'home'           => ['Home', true],
            'products'       => ['Products', false],
            'news'           => ['News', false],
            'applications'   => ['Applications', false],
            'question'       => ['FAQ', false],
            'about-us'       => ['About Us', false],
            'certificates'   => ['Certificates', false],
            'contact'        => ['Contact', false],
            'privacy-policy' => ['Privacy Policy', false],
        ];
    }
}

if (!function_exists('tpi_theme_setup_taxonomies')) {
    /** 分类法 => 默认分类名（已存在同名同 slug 就跳过） */
    function tpi_theme_setup_taxonomies(): array
    {
        return [
            'product_cat'     => 'Uncategorized',
            'application_cat' => 'Uncategorized',
            'faq_cat'         => 'Uncategorized',
            'category'        => 'Uncategorized',
        ];
    }
}

if (!function_exists('tpi_theme_setup_wp_privacy_page')) {
    /**
     * 找出「WordPress 自己创建的那个隐私政策页」
     *
     * 新装 WordPress（含 6.8）会自动建一个 slug 为 privacy-policy 的页面，
     * 并把「设置 → 隐私」的 wp_page_for_privacy_policy 指向它。主题建页时
     * 必须优先采用这一页 —— 否则同 slug 建不了，WordPress 只能改名叫
     * privacy-policy-2，站里就多出一个重复页。
     *
     * 判定不看状态、不看内容（WP 自带的是 draft + 一份教程正文），只认：
     *   - 「设置 → 隐私」指向它；或 slug 正好是 privacy-policy；
     *   - 还没有语言归属（有归属说明已经被主题/用户接管过，不再抢）。
     *
     * @return int 找得到的页面 ID，0 = 没有
     */
    function tpi_theme_setup_wp_privacy_page(): int
    {
        $candidates = [];

        $option_id = (int) get_option('wp_page_for_privacy_policy');
        if ($option_id > 0) {
            $candidates[] = $option_id;
        }
        $by_slug = get_page_by_path('privacy-policy', OBJECT, 'page');
        if ($by_slug instanceof WP_Post) {
            $candidates[] = (int) $by_slug->ID;
        }

        foreach (array_unique($candidates) as $id) {
            $page = get_post($id);
            if (!$page instanceof WP_Post || $page->post_type !== 'page') {
                continue;
            }
            // slug 被改成别的（用户自己挪用过）就不当作 WordPress 那一页
            if ($page->post_name !== 'privacy-policy') {
                continue;
            }
            if (function_exists('tpi_get_post_language') && tpi_get_post_language($id) !== '') {
                continue;
            }
            return (int) $id;
        }

        return 0;
    }
}

if (!function_exists('tpi_theme_setup_privacy_align')) {
    /**
     * 把 WordPress 自带的隐私政策页收敛成站点的一个正式页面
     *
     *   1) 采用它（不新建）：publish + 标题 + 排序值 + 语言归属（默认语言，通常 en）；
     *   2) 清掉历史遗留的重复空页（privacy-policy-2 这类），菜单项指向改到保留的那一页；
     *   3) 正文保持原样，不清空 —— 要用主题自己的文案就在后台编辑这一页。
     *
     * 幂等：已经对齐过（有语言归属 + 已发布）就直接返回，不重复写库。
     *
     * @param bool $dry_run 只报告，不写库
     * @return int 采用/已存在的页面 ID，0 = 没有可采用的
     */
    function tpi_theme_setup_privacy_align(bool $dry_run = false): int
    {
        $lang = tpi_default_language();
        if ($lang === '') {
            return 0;
        }

        // 第一次采用：WordPress 自带的那一页（还没语言归属）
        $id      = tpi_theme_setup_wp_privacy_page();
        $adopted = $id > 0;

        // 已经对齐过（之前采用过，已发布 + 有语言归属）→ 用它做收尾，不再改标题
        if (!$adopted) {
            $id = (int) tpi_get_post_id_by_slug('privacy-policy', $lang, 'page');
        }
        if ($id <= 0) {
            return 0;
        }

        if ($dry_run) {
            return $id;
        }

        // 顺序很重要：先把重复页的菜单项挪到这一页、再发布。
        // 反过来的话，发布瞬间会被「自动补菜单项」当成新页面又追加一条，菜单里出现两条同样的项。
        tpi_theme_setup_privacy_dedupe($id);

        if ($adopted) {
            $page  = get_post($id);
            $needs = $page->post_status !== 'publish'
                || (string) $page->post_title !== 'Privacy Policy'
                || (int) $page->menu_order !== 9000;

            if ($needs) {
                wp_update_post([
                    'ID'          => $id,
                    'post_title'  => 'Privacy Policy',
                    'post_status' => 'publish',
                    'menu_order'  => function_exists('tpi_page_setup_order') ? tpi_page_setup_order('privacy-policy') : 9000,
                ]);
            }
        }
        if (function_exists('tpi_get_post_language') && function_exists('tpi_set_post_language')
            && tpi_get_post_language($id) !== $lang) {
            tpi_set_post_language($id, $lang);
        }

        // 收尾：万一自动补项还是追加了，同一菜单里指向这一页的多余项删掉（菜单项删了不影响页面）
        tpi_theme_setup_menu_dedupe($id);

        return (int) $id;
    }
}

if (!function_exists('tpi_theme_setup_menu_dedupe')) {
    /**
     * 同一页面在菜单里只保留一项
     *
     * 保留 menu_order 最小的那项（最早加进去的），其余直接删除。
     * 只处理导航菜单项本身，不碰页面数据。
     */
    function tpi_theme_setup_menu_dedupe(int $page_id): void
    {
        global $wpdb;

        $item_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'nav_menu_item'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND m.meta_key = '_menu_item_object_id'
               AND m.meta_value = %d
             ORDER BY p.menu_order ASC, p.ID ASC",
            $page_id
        ));
        if (count($item_ids) < 2) {
            return;
        }

        foreach (array_slice($item_ids, 1) as $dup_id) {
            wp_delete_post((int) $dup_id, true);
        }

        if (function_exists('tpi_primary_menu_reorder')) {
            tpi_primary_menu_reorder();
        }
    }
}

if (!function_exists('tpi_theme_setup_privacy_dedupe')) {
    /**
     * 处理历史遗留的重复隐私政策页（slug = privacy-policy-N）
     *
     * 只有在「内容为空」时才移入回收站（可恢复），菜单项先指向保留页；
     * 用户自己写过内容的重复页不动，避免误删。
     */
    function tpi_theme_setup_privacy_dedupe(int $keep_id): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_status NOT IN ('trash', 'auto-draft')
               AND ID <> " . (int) $keep_id . "
               AND post_name LIKE 'privacy-policy-%'"
        );
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $text = trim(str_replace(['&nbsp;', "\xC2\xA0"], '', wp_strip_all_tags((string) $row->post_content)));
            if ($text !== '') {
                continue; // 有正文，是用户自己的页，不动
            }

            // 菜单项（含各语言）里指向这页的，改指到保留页
            $item_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_menu_item_object_id' AND meta_value = %d",
                (int) $row->ID
            ));
            foreach ($item_ids as $item_id) {
                $item_id = (int) $item_id;
                if (get_post_type($item_id) !== 'nav_menu_item') {
                    continue;
                }
                update_post_meta($item_id, '_menu_item_object_id', (int) $keep_id);
                clean_post_cache($item_id);
            }

            // 进回收站，不物理删除
            wp_trash_post((int) $row->ID);
        }
    }
}

if (!function_exists('tpi_theme_setup_run')) {
    /**
     * 执行一次基础数据初始化
     *
     * @param bool $dry_run 只报告会建什么，不写库
     * @return array{pages:array,terms:array,front:int,skipped:bool}
     */
    function tpi_theme_setup_run(bool $dry_run = false): array
    {
        $report = ['pages' => [], 'terms' => [], 'front' => 0, 'skipped' => false];

        // 版本已是最新才整体跳过；骨架缺页时即使 done 已置也要补（幂等，已有的不会重建）
        if (!$dry_run && (int) get_option('tpi_theme_setup_version') >= TPI_THEME_SETUP_VERSION) {
            $report['skipped'] = true;
            return $report;
        }

        $lang = tpi_default_language();
        if ($lang === '') {
            return $report;
        }

        // 页头页脚这些文案分组要能读到，建好的页面才有内容可渲染
        foreach (tpi_theme_setup_pages() as $slug => $conf) {
            [$title, $is_front] = $conf;

            // 隐私政策：新装 WordPress 自带一页（slug 就是 privacy-policy，被「设置 → 隐私」指向），
            // 采用它、归到默认语言，绝不另建 privacy-policy-2。
            if ($slug === 'privacy-policy') {
                $adopted = tpi_theme_setup_privacy_align($dry_run);
                if ($adopted > 0) {
                    $report['pages'][] = 'privacy-policy:wp';
                    continue;
                }
            }

            $existing = (int) tpi_get_post_id_by_slug($slug, $lang, 'page');
            if ($existing > 0) {
                if ($is_front) {
                    $report['front'] = $existing;
                }
                continue;
            }

            $report['pages'][] = $slug;
            if ($dry_run) {
                continue;
            }

            $post_id = wp_insert_post([
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'post_title'     => $title,
                'post_name'      => $slug,
                'post_content'   => '',
                // 建页时就带上排序值，避免「首页最后才建 → 排到最后」
                'menu_order'     => function_exists('tpi_page_setup_order') ? tpi_page_setup_order($slug) : 0,
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ], true);

            if (is_wp_error($post_id) || !$post_id) {
                continue;
            }
            tpi_set_post_language((int) $post_id, $lang);
            if ($is_front) {
                $report['front'] = (int) $post_id;
            }
        }

        foreach (tpi_theme_setup_taxonomies() as $taxonomy => $name) {
            if (!taxonomy_exists($taxonomy) || term_exists('uncategorized', $taxonomy)) {
                continue;
            }
            $report['terms'][] = $taxonomy . ':uncategorized';
            if ($dry_run) {
                continue;
            }
            $term = wp_insert_term($name, $taxonomy, ['slug' => 'uncategorized']);
            if (!is_wp_error($term) && !empty($term['term_id']) && function_exists('tpi_set_term_language')) {
                tpi_set_term_language((int) $term['term_id'], $lang);
            }
        }

        if (!$dry_run) {
            // 首页必须是静态页面，否则刚建好的 Home 不会出现在 /
            // 已经是静态首页且指向本页时不动（老站多语言下 page_on_front 存的就是默认语言那页）
            if ($report['front'] > 0
                && ((string) get_option('show_on_front') !== 'page'
                    || (int) get_option('page_on_front') <= 0)) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', (int) $report['front']);
            }
            // 默认导航菜单：把刚建好的页面按声明顺序写进菜单名单。
            // 隐私政策这类页脚页面默认不加入（tpi_default_menu_excluded_slugs），
            // 页面照常访问，只是不在导航里 —— 不靠渲染时隐藏。
            if (function_exists('tpi_primary_menu_build')) {
                tpi_primary_menu_build();
            }
            update_option('tpi_theme_setup_done', 1);
            update_option('tpi_theme_setup_lang', $lang);
            // 记版本号：下次请求起 tpi_theme_setup_auto() 直接放行，不再查页面
            update_option('tpi_theme_setup_version', TPI_THEME_SETUP_VERSION);
        }

        return $report;
    }
}
