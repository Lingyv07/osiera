<?php
/**
 * 基础数据（starter content）入口
 *
 * 数据本体在主题根目录 `data-init/`：
 *   data-init/assets/{images,svg}  演示位图、内容型图标、语言旗
 *   data-init/data/*.json          语言 / 分类 / 菜单 / 语言内容 / 页面信息 / 四类内容
 *
 * 本文件只做三件事：
 *   1. 版本控制 —— option tpi_starter_content 记版本，跑过就不再跑；
 *   2. 时机 —— after_switch_theme 与 init 各挂一次（新装站切换瞬间加载的是旧主题，
 *      只有下一次请求的 init 才真正生效）；
 *   3. 判断该不该建内容 —— 站里已经有客户内容时只补语言 / 页面 / 菜单 / 文案，
 *      不往里塞演示内容（可用 filter 打开）。
 *
 * 实际建库逻辑见 inc/setup/data-init.php（tpi_data_init_run()）。
 *
 * 想关掉整套初始化：add_filter('tpi_starter_content_enabled', '__return_false');
 *
 * @package Osiera
 */

if (!function_exists('tpi_starter_content_version')) {
    /** 数据版本：结构有调整时递增，老站下次进入后台会按新版本补一遍缺的部分 */
    function tpi_starter_content_version(): string
    {
        return '4';
    }
}

if (!function_exists('tpi_starter_content_enabled')) {
    function tpi_starter_content_enabled(): bool
    {
        return (bool) apply_filters('tpi_starter_content_enabled', true);
    }
}

if (!function_exists('tpi_starter_content_has_content')) {
    /** 站里是否已经有某类内容（有就只补结构，不塞演示内容） */
    function tpi_starter_content_has_content(string $post_type): bool
    {
        $ids = get_posts([
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        return !empty($ids);
    }
}

if (!function_exists('tpi_starter_content_has_any')) {
    /** 站里是否已经有任意一类真实内容 */
    function tpi_starter_content_has_any(): bool
    {
        foreach (['product', 'post', 'application', 'faq'] as $type) {
            if (tpi_starter_content_has_content($type)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tpi_starter_content_seed')) {
    /**
     * 跑一遍初始化
     *
     * @param bool $force            true = 站里有内容也照样建（调试 / 新项目补数据）
     * @param bool $with_content     是否建四类内容（false = 只补语言、页面、菜单、文案、页面信息）
     * @param bool $dry              只统计不写库
     * @return array
     */
    function tpi_starter_content_seed(bool $force = false, bool $with_content = true, bool $dry = false): array
    {
        if (!function_exists('tpi_data_init_run')) {
            return [];
        }
        // WordPress 自带的示例内容：Hello world! 会混进新闻列表，Sample Page 在主题的
        // 页面路由里是 404（只认自定义页面），直接丢进回收站
        if (!$dry) {
            foreach ([['hello-world', 'post'], ['sample-page', 'page']] as $demo) {
                $demo_post = get_page_by_path($demo[0], OBJECT, $demo[1]);
                if ($demo_post instanceof WP_Post && $demo_post->post_status !== 'trash') {
                    wp_trash_post($demo_post->ID);
                }
            }
        }
        return tpi_data_init_run($dry, $with_content && ($force || !tpi_starter_content_has_any()));
    }
}

/* =========================================================
 * 导入状态（存数据库，r161）
 *
 *   pending   未导入（新装站默认；前台只有框架结构，没有任何演示内容）
 *   importing 正在导入
 *   done      已导入
 *   failed    上次失败（原因在 option tpi_data_init_error）
 *   dismissed 关掉这个提示，但随时可以回来手动导入
 *   disabled  永久关闭导入，不再提示
 *
 * 默认不自动跑：启用主题、访问前台都不会去拉数据源。
 * 只有在后台「初始数据」页点了「导入」才真正执行 —— 远端地址写错时
 * 不会再出现「每次访问都卡一遍超时」的情况。
 * ========================================================= */

if (!function_exists('tpi_data_init_states')) {
    /** 全部合法状态 */
    function tpi_data_init_states(): array
    {
        return ['pending', 'importing', 'done', 'failed', 'dismissed', 'disabled'];
    }
}

if (!function_exists('tpi_data_init_state')) {
    /**
     * 当前导入状态
     *
     * 老站（r160 以前跑过自动初始化）没有这个 option，但 tpi_starter_content
     * 里已经写了版本号 —— 那种情况当作 done，不要突然提示「还没导入」。
     */
    function tpi_data_init_state(): string
    {
        $state = get_option('tpi_data_init_state', null);
        if (is_string($state) && in_array($state, tpi_data_init_states(), true)) {
            return $state;
        }
        return (string) get_option('tpi_starter_content', '') !== '' ? 'done' : 'pending';
    }
}

if (!function_exists('tpi_data_init_set_state')) {
    function tpi_data_init_set_state(string $state): void
    {
        if (!in_array($state, tpi_data_init_states(), true)) {
            $state = 'pending';
        }
        update_option('tpi_data_init_state', $state, false);
    }
}

if (!function_exists('tpi_data_init_skip_key')) {
    /** 「本次已关闭提醒」标记的 key（按用户区分） */
    function tpi_data_init_skip_key(): string
    {
        return 'tpi_data_init_skip_' . (int) get_current_user_id();
    }
}

if (!function_exists('tpi_data_init_mark_skip')) {
    /**
     * 记下「刚刚点了关闭提醒」
     *
     * 只压住重定向回来这一次的横幅。标记读取即失效，所以下次进后台
     * 还会照常提醒；不想再看到请用「永久关闭导入」(disabled)。
     */
    function tpi_data_init_mark_skip(): void
    {
        set_transient(tpi_data_init_skip_key(), 1, 60);
    }
}

if (!function_exists('tpi_data_init_take_skip')) {
    /** 取出并清掉「本次已关闭」标记，同一次只生效一次 */
    function tpi_data_init_take_skip(): bool
    {
        $key = tpi_data_init_skip_key();
        if (!get_transient($key)) {
            return false;
        }
        delete_transient($key);
        return true;
    }
}

if (!function_exists('tpi_data_init_is_closed')) {
    /**
     * 后台是否不再自动露横幅
     *
     * disabled = 永久关闭，一直不提示；
     * dismissed = 只是关掉刚才那一次，读完标记就失效，下次进后台继续提醒。
     */
    function tpi_data_init_is_closed(): bool
    {
        $state = tpi_data_init_state();
        if ($state === 'disabled') {
            return true;
        }
        if ($state === 'dismissed') {
            return tpi_data_init_take_skip();
        }
        return false;
    }
}

if (!function_exists('tpi_data_init_report')) {
    /** 上次导入的统计（语言 / 页面 / 分类 / 菜单 / 四类内容） */
    function tpi_data_init_report(): array
    {
        $raw = get_option('tpi_data_init_report', '');
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }
}

if (!function_exists('tpi_data_init_import')) {
    /**
     * 手动导入一次初始数据
     *
     * 只由后台「初始数据」页的按钮触发（AJAX 或表单），不做任何自动调用。
     * 站里已有的内容不会被覆盖：同 slug / 同语言已存在就跳过（tpi_data_init_run 的约定）。
     *
     * @return array{ok:bool,error:string,report:array,state:string}
     */
    function tpi_data_init_import(): array
    {
        if (tpi_data_init_state() === 'disabled') {
            return ['ok' => false, 'error' => tpi_admin_ui('data_init.error.disabled'), 'report' => [], 'state' => 'disabled'];
        }

        // 并发锁：导入要跑十几秒，重复点按钮会建出两套页面（home / home-2）
        $lock = (int) get_option('tpi_data_init_lock', 0);
        if ($lock > 0 && $lock > time() - 600) {
            return ['ok' => false, 'error' => tpi_admin_ui('data_init.error.busy'), 'report' => [], 'state' => tpi_data_init_state()];
        }
        if ($lock > 0) {
            delete_option('tpi_data_init_lock');
        }
        if (!add_option('tpi_data_init_lock', time(), '', 'no')) {
            return ['ok' => false, 'error' => tpi_admin_ui('data_init.error.busy'), 'report' => [], 'state' => tpi_data_init_state()];
        }

        if (function_exists('tpi_data_init_flush')) {
            tpi_data_init_flush();
        }
        tpi_data_init_set_state('importing');
        delete_option('tpi_data_init_error');

        try {
            $report = tpi_starter_content_seed(true, true);
        } finally {
            delete_option('tpi_data_init_lock');
        }

        if (!empty($report['error'])) {
            $error = (string) $report['error'];
            update_option('tpi_data_init_error', $error, false);
            tpi_data_init_set_state('failed');
            return ['ok' => false, 'error' => $error, 'report' => $report, 'state' => 'failed'];
        }

        update_option('tpi_data_init_report', wp_json_encode($report, JSON_UNESCAPED_UNICODE), false);
        update_option('tpi_data_init_done_at', current_time('mysql'), false);
        update_option('tpi_starter_content', tpi_starter_content_version(), false);
        tpi_data_init_set_state('done');
        do_action('tpi_starter_content_seeded', $report);

        return ['ok' => true, 'error' => '', 'report' => $report, 'state' => 'done'];
    }
}

if (!function_exists('tpi_data_init_probe')) {
    /**
     * 探测数据地址是否可用（后台「检测」按钮）
     *
     * 只读 data/languages.json：能读到且解析出 items 才算通，
     * 这样「地址填对了但里面没数据」也能提前发现。
     *
     * @return array{ok:bool,message:string,mode:string,where:string,languages:int}
     */
    function tpi_data_init_probe(): array
    {
        if (function_exists('tpi_data_init_flush')) {
            tpi_data_init_flush();
        }
        $base  = tpi_data_init_base();
        $where = $base['mode'] === 'remote' ? $base['url'] : $base['path'];
        $data  = tpi_data_init_json('languages');
        $items = (array) ($data['items'] ?? []);

        if (!$items) {
            return [
                'ok'        => false,
                'message'   => sprintf(tpi_admin_ui('data_init.error.not-found'), $where),
                'mode'      => $base['mode'],
                'where'     => $where,
                'languages' => 0,
            ];
        }
        return [
            'ok'        => true,
            'message'   => sprintf(tpi_admin_ui('data_init.probe.ok'), count($items)),
            'mode'      => $base['mode'],
            'where'     => $where,
            'languages' => count($items),
        ];
    }
}

if (!function_exists('tpi_starter_content_maybe_seed')) {
    /**
     * 兼容旧调用：不再挂在任何钩子上
     *
     * r161 起默认不自动导入（远端地址写错会让整站每次访问都卡超时）。
     * 需要导入请到后台「初始数据」页手动点按钮；确实要恢复自动导入的老项目，
     * 可以自己 add_action('init', 'tpi_starter_content_maybe_seed', 100)。
     */
    function tpi_starter_content_maybe_seed(): void
    {
        if (!tpi_starter_content_enabled()) {
            return;
        }
        if (tpi_data_init_state() === 'done' || tpi_data_init_state() === 'disabled') {
            return;
        }
        if (function_exists('wp_installing') && wp_installing()) {
            return;
        }
        if (!post_type_exists('product') || !post_type_exists('faq')) {
            return;
        }
        tpi_data_init_import();
    }
}
