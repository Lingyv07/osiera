<?php
/**
 * 初始数据（Osiera → 初始数据）
 *
 * 把 data-init 里那套演示数据（语言 / 页面 / 菜单 / 分类 / 文案 / 产品与新闻等）
 * 一次性导进来，供新项目参考；导入后可以随便改。
 *
 * 设计原则（r161）：
 *   1. 默认不导入 —— 启用主题、访问前台都不去拉数据源，远端地址写错也不会拖慢整站；
 *   2. 地址可改 —— 后台保存的值存在 option tpi_data_init_url，优先级高于主题根的
 *      init-config.json（旧名 api-init.json 仍兼容），改完可以立刻重试；
 *   3. 状态进库 —— 未导入 / 已导入 / 失败 / 关闭提示 / 永久关闭 都存在数据库里；
 *   4. 失败说清楚 —— 显示原因，并给「改地址重试」和「永久关闭」两条出路；
 *   5. 不覆盖已有内容 —— 同 slug、同语言已存在就跳过（见 tpi_data_init_run()）。
 *
 * @package Osiera
 */

/* =========================================================
 * 一、AJAX
 * ========================================================= */

if (!function_exists('tpi_data_init_verify_ajax')) {
    /** AJAX 公共校验：权限 + nonce */
    function tpi_data_init_verify_ajax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => tpi_admin_ui('data_init.error.forbidden')], 403);
        }
        check_ajax_referer('tpi_data_init_ajax', 'nonce');
    }
}

if (!function_exists('tpi_data_init_ajax_import')) {
    /** 导入：一次跑完，返回统计 */
    function tpi_data_init_ajax_import(): void
    {
        tpi_data_init_verify_ajax();

        // 远端要下载几十个文件，php-cgi 的 30s 上限撑不住；断开浏览器也别半途掐死
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);

        $result = tpi_data_init_import();
        $report = (array) ($result['report'] ?? []);

        // 没读到一个语言，等于数据地址有问题：把地址一起带回去，前端好提示改哪里
        $base = tpi_data_init_base();

        if (empty($result['ok'])) {
            wp_send_json_error([
                'message' => (string) ($result['error'] ?? tpi_admin_ui('data_init.error.unknown')),
                'state'   => (string) ($result['state'] ?? 'failed'),
                'where'   => $base['mode'] === 'remote' ? $base['url'] : $base['path'],
                'mode'    => $base['mode'],
            ]);
        }

        wp_send_json_success([
            'message' => tpi_admin_ui('data_init.notice.done'),
            'state'   => 'done',
            'report'  => $report,
            'stats'   => tpi_data_init_stats_rows($report),
        ]);
    }
}
add_action('wp_ajax_tpi_data_init_import', 'tpi_data_init_ajax_import');

if (!function_exists('tpi_data_init_ajax_probe')) {
    /** 检测地址：只读 data/languages.json，不改任何数据 */
    function tpi_data_init_ajax_probe(): void
    {
        tpi_data_init_verify_ajax();
        $probe = tpi_data_init_probe();
        if (empty($probe['ok'])) {
            wp_send_json_error($probe);
        }
        wp_send_json_success($probe);
    }
}
add_action('wp_ajax_tpi_data_init_probe', 'tpi_data_init_ajax_probe');

/* =========================================================
 * 二、统计与状态展示
 * ========================================================= */

if (!function_exists('tpi_data_init_stats_rows')) {
    /**
     * 把导入报告转成「项目 / 数量」两列，前端直接渲染成表格
     *
     * @return array<int,array{label:string,value:int}>
     */
    function tpi_data_init_stats_rows(array $report): array
    {
        // 页面 / 分类取 *_new（本次真正新建的数量）：
        // 重新导入时它们会是 0，正说明「已存在，跳过」，配一行「已跳过」更好懂
        $map = [
            'languages'    => ['languages', 'data_init.stat.languages'],
            'pages'        => ['pages_new', 'data_init.stat.pages'],
            'terms'        => ['terms_new', 'data_init.stat.terms'],
            'menu'         => ['menu', 'data_init.stat.menu'],
            'lang-content' => ['lang-content', 'data_init.stat.lang-content'],
            'page-info'    => ['page-info', 'data_init.stat.page-info'],
            'product'      => ['product', 'data_init.stat.product'],
            'post'         => ['post', 'data_init.stat.post'],
            'application'  => ['application', 'data_init.stat.application'],
            'faq'          => ['faq', 'data_init.stat.faq'],
            'skipped'      => ['skipped', 'data_init.stat.skipped'],
        ];
        $rows = [];
        foreach ($map as $row_key => $item) {
            $rows[] = [
                'key'   => $row_key,
                'label' => tpi_admin_ui($item[1]),
                'value' => (int) ($report[$item[0]] ?? 0),
            ];
        }
        return $rows;
    }
}

if (!function_exists('tpi_data_init_state_label')) {
    /** 状态徽章的文字与色调 */
    function tpi_data_init_state_label(string $state): array
    {
        $map = [
            'pending'   => ['data_init.state.pending', 'info'],
            'importing' => ['data_init.state.importing', 'warning'],
            'done'      => ['data_init.state.done', 'success'],
            'failed'    => ['data_init.state.failed', 'danger'],
            'dismissed' => ['data_init.state.dismissed', 'info'],
            'disabled'  => ['data_init.state.disabled', 'warning'],
        ];
        $item = $map[$state] ?? $map['pending'];
        return ['label' => tpi_admin_ui($item[0]), 'tone' => $item[1]];
    }
}

/* =========================================================
 * 三、页面
 * ========================================================= */

if (!function_exists('tpi_data_init_handle_post')) {
    /** 处理页面上的表单动作（保存地址 / 关闭 / 永久关闭 / 重新启用） */
    function tpi_data_init_handle_post(): void
    {
        $action = (string) ($_POST['tpi_data_init_action'] ?? '');
        if ($action === '') {
            return;
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'tpi_data_init_page')) {
            echo '<div class="notice notice-error"><p>' . esc_html(tpi_admin_ui('data_init.notice.expired')) . '</p></div>';
            return;
        }

        if ($action === 'save') {
            $raw = trim((string) wp_unslash($_POST['data_init_url'] ?? ''));
            // 只接受「路径 / http(s) 地址」两种形态，别的一律当空（回落 init-config.json）
            if ($raw !== '' && !preg_match('#^(https?://|[A-Za-z]:[\\\\/]|/)#i', $raw)) {
                $raw = trim($raw, " \t\n\r\0\x0B/");
            }
            if ($raw === '') {
                delete_option('tpi_data_init_url');
            } else {
                update_option('tpi_data_init_url', $raw, false);
            }
            tpi_data_init_flush();
            echo '<div class="notice notice-success"><p>' . esc_html(tpi_admin_ui('data_init.notice.saved')) . '</p></div>';
            return;
        }

        if ($action === 'dismiss') {
            tpi_data_init_set_state('dismissed');
            // 只压住重定向回来这一次的横幅，下次进后台照常提醒
            tpi_data_init_mark_skip();
            echo '<div class="notice notice-success"><p>' . esc_html(tpi_admin_ui('data_init.notice.dismissed')) . '</p></div>';
            return;
        }

        if ($action === 'disable') {
            tpi_data_init_set_state('disabled');
            echo '<div class="notice notice-success"><p>' . esc_html(tpi_admin_ui('data_init.notice.disabled')) . '</p></div>';
            return;
        }

        if ($action === 'enable') {
            tpi_data_init_set_state(tpi_data_init_report() ? 'done' : 'pending');
            echo '<div class="notice notice-success"><p>' . esc_html(tpi_admin_ui('data_init.notice.enabled')) . '</p></div>';
        }
    }
}

if (!function_exists('display_data_init_page')) {
    function display_data_init_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        tpi_data_init_handle_post();

        $state   = tpi_data_init_state();
        $base    = tpi_data_init_base();
        $where   = $base['mode'] === 'remote' ? $base['url'] : $base['path'];
        $is_path = $base['mode'] === 'local';
        $exists  = $is_path && $where !== '' && is_dir($where);
        $url_val = tpi_data_init_url_setting();
        $error   = (string) get_option('tpi_data_init_error', '');
        $report  = tpi_data_init_report();
        $done_at = (string) get_option('tpi_data_init_done_at', '');
        $badge   = tpi_data_init_state_label($state);

        $config = [
            'nonce'   => wp_create_nonce('tpi_data_init_ajax'),
            'ajax'    => admin_url('admin-ajax.php'),
            'state'   => $state,
            'i18n'    => [
                'importing'  => tpi_admin_ui('data_init.progress.importing'),
                'stages'     => [
                    tpi_admin_ui('data_init.progress.stage-lang'),
                    tpi_admin_ui('data_init.progress.stage-page'),
                    tpi_admin_ui('data_init.progress.stage-term'),
                    tpi_admin_ui('data_init.progress.stage-content'),
                    tpi_admin_ui('data_init.progress.stage-media'),
                    tpi_admin_ui('data_init.progress.stage-finish'),
                ],
                'done'       => tpi_admin_ui('data_init.notice.done'),
                'failed'     => tpi_admin_ui('data_init.error.unknown'),
                'network'    => tpi_admin_ui('data_init.error.network'),
                'probe-ok'   => tpi_admin_ui('data_init.probe.ok'),
                'probe-fail' => tpi_admin_ui('data_init.error.not-found'),
                'checking'   => tpi_admin_ui('data_init.probe.checking'),
                'reload'     => tpi_admin_ui('data_init.progress.reload'),
            ],
        ];
        ?>
        <div class="ad-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('data_init.page.title')); ?></h1>
                <p><?php echo esc_html(tpi_admin_ui('data_init.page.hint')); ?></p>
            </div>

            <div class="ad-slogan ad-slogan-<?php echo esc_attr($badge['tone']); ?>">
                <span class="ad-slogan-badge"><?php echo esc_html($badge['label']); ?></span>
                <div class="ad-slogan-body">
                    <p><?php echo esc_html(tpi_admin_ui('data_init.state.hint-' . $state)); ?></p>
                    <?php if ($done_at !== '' && $state === 'done') : ?>
                        <p class="ad-hint"><?php echo esc_html(sprintf(tpi_admin_ui('data_init.state.done-at'), $done_at)); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 一、数据来源 -->
            <form method="post">
                <?php wp_nonce_field('tpi_data_init_page'); ?>
                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('data_init.card.source-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('data_init.card.source-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <label class="ad-field ad-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('data_init.field.url')); ?></span>
                            <input type="text" name="data_init_url" value="<?php echo esc_attr($url_val); ?>"
                                   placeholder="data-init" spellcheck="false" autocomplete="off">
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('data_init.hint.url')); ?></span>
                        </label>

                        <div class="di-current">
                            <span class="di-current-label"><?php echo esc_html(tpi_admin_ui('data_init.current.title')); ?></span>
                            <code><?php echo esc_html($where !== '' ? $where : tpi_admin_ui('data_init.current.empty')); ?></code>
                            <?php if ($is_path && $where !== '') : ?>
                                <span class="di-tag <?php echo $exists ? 'is-ok' : 'is-bad'; ?>">
                                    <?php echo esc_html($exists ? tpi_admin_ui('data_init.current.exists') : tpi_admin_ui('data_init.current.missing')); ?>
                                </span>
                            <?php elseif (!$is_path) : ?>
                                <span class="di-tag"><?php echo esc_html(tpi_admin_ui('data_init.current.remote')); ?></span>
                            <?php endif; ?>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('data_init.current.hint')); ?></span>
                        </div>

                        <div class="ad-card-actions">
                            <button type="button" class="button" data-di-probe><?php echo esc_html(tpi_admin_ui('data_init.button.probe')); ?></button>
                            <span class="di-probe-result" data-di-probe-result></span>
                        </div>

                        <div class="ad-savebar">
                            <span class="ad-savebar-hint"><?php echo esc_html(tpi_admin_ui('data_init.hint.savebar')); ?></span>
                            <button type="submit" name="tpi_data_init_action" value="save" class="button button-primary">
                                <?php echo esc_html(tpi_admin_ui('data_init.button.save')); ?>
                            </button>
                        </div>
                    </div>
                </section>
            </form>

            <!-- 二、导入 -->
            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <h2><?php echo esc_html(tpi_admin_ui('data_init.card.import-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('data_init.card.import-hint')); ?></p>
                    </div>
                </header>
                <div class="ad-card-body">
                    <ul class="di-list">
                        <li><?php echo esc_html(tpi_admin_ui('data_init.import.what')); ?></li>
                        <li><?php echo esc_html(tpi_admin_ui('data_init.import.alt')); ?></li>
                        <li><?php echo esc_html(tpi_admin_ui('data_init.import.safe')); ?></li>
                        <li><?php echo esc_html(tpi_admin_ui('data_init.import.site-name')); ?></li>
                    </ul>

                    <?php if ($state === 'failed' && $error !== '') : ?>
                        <div class="di-error">
                            <strong><?php echo esc_html(tpi_admin_ui('data_init.error.title')); ?></strong>
                            <p><?php echo esc_html($error); ?></p>
                            <p class="ad-hint"><?php echo esc_html(tpi_admin_ui('data_init.error.tip')); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="di-progress" data-di-progress hidden>
                        <div class="di-bar"><i data-di-bar></i></div>
                        <p class="di-stage" data-di-stage></p>
                    </div>

                    <div class="di-result" data-di-result hidden></div>

                    <div class="ad-card-actions">
                        <button type="button" class="button button-primary button-large" data-di-import
                            <?php echo $state === 'disabled' ? 'disabled' : ''; ?>>
                            <?php echo esc_html($state === 'done' ? tpi_admin_ui('data_init.button.reimport') : tpi_admin_ui('data_init.button.import')); ?>
                        </button>
                        <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('data_init.hint.import-busy')); ?></span>
                    </div>
                </div>
            </section>

            <!-- 三、提示与关闭 -->
            <form method="post">
                <?php wp_nonce_field('tpi_data_init_page'); ?>
                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('data_init.card.close-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('data_init.card.close-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="ad-card-actions">
                            <?php if ($state === 'disabled') : ?>
                                <button type="submit" name="tpi_data_init_action" value="enable" class="button">
                                    <?php echo esc_html(tpi_admin_ui('data_init.button.enable')); ?>
                                </button>
                            <?php else : ?>
                                <button type="submit" name="tpi_data_init_action" value="dismiss" class="button">
                                    <?php echo esc_html(tpi_admin_ui('data_init.button.dismiss')); ?>
                                </button>
                                <button type="submit" name="tpi_data_init_action" value="disable" class="button di-danger">
                                    <?php echo esc_html(tpi_admin_ui('data_init.button.disable')); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </form>

            <?php if ($report) : ?>
                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('data_init.card.stats-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('data_init.card.stats-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <table class="di-table">
                            <tbody>
                            <?php foreach (tpi_data_init_stats_rows($report) as $row) : ?>
                                <tr>
                                    <th><?php echo esc_html($row['label']); ?></th>
                                    <td><?php echo (int) $row['value']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <script>
        window.tpiDataInitCfg = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        </script>
        <?php
    }
}

/* =========================================================
 * 四、后台资源（只在本页加载）
 * ========================================================= */

if (!function_exists('tpi_data_init_admin_assets')) {
    function tpi_data_init_admin_assets(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'data-init') {
            return;
        }
        $version = function_exists('tpi_asset_version') ? tpi_asset_version() : null;
        wp_enqueue_script(
            'tpi-data-init',
            get_template_directory_uri() . '/assets/wp-assets/js/wp-data-init.js',
            [],
            $version,
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'tpi_data_init_admin_assets');

/* =========================================================
 * 五、外观 → 主题 上的引导条
 *
 * 不是报错，是「还没导入初始数据」的提醒：
 *   - 未导入 / 上次失败 才出现；
 *   - 用户点过「关闭提示」或「永久关闭」就不再出现；
 *   - 直接给「去导入」和「永久关闭」两个出口，不用去别处找。
 * ========================================================= */

if (!function_exists('tpi_data_init_themes_notice')) {
    function tpi_data_init_themes_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'themes') {
            return;
        }
        if (tpi_data_init_is_closed() || tpi_data_init_state() === 'done') {
            return;
        }

        $state = tpi_data_init_state();
        $error = (string) get_option('tpi_data_init_error', '');
        $link  = admin_url('admin.php?page=data-init');
        $off   = wp_nonce_url(admin_url('admin-post.php?action=tpi_data_init_dismiss_notice'), 'tpi_data_init_notice');
        ?>
        <div class="notice notice-info is-dismissible di-themes-notice">
            <p>
                <strong><?php echo esc_html(tpi_admin_ui('data_init.notice.themes-title')); ?></strong>
                <?php echo esc_html($state === 'failed'
                    ? tpi_admin_ui('data_init.notice.themes-failed')
                    : tpi_admin_ui('data_init.notice.themes-pending')); ?>
            </p>
            <?php if ($state === 'failed' && $error !== '') : ?>
                <p class="di-themes-error"><code><?php echo esc_html($error); ?></code></p>
            <?php endif; ?>
            <p class="di-themes-actions">
                <a class="button button-primary" href="<?php echo esc_url($link); ?>">
                    <?php echo esc_html(tpi_admin_ui('data_init.button.go')); ?>
                </a>
                <a class="button-link" href="<?php echo esc_url($off); ?>">
                    <?php echo esc_html(tpi_admin_ui('data_init.button.never')); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'tpi_data_init_themes_notice');

if (!function_exists('tpi_data_init_dismiss_notice')) {
    /** 主题页的「永久关闭」：记进数据库，之后不再出现在外观页 */
    function tpi_data_init_dismiss_notice(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(tpi_admin_ui('data_init.error.forbidden')));
        }
        check_admin_referer('tpi_data_init_notice');
        tpi_data_init_set_state('disabled');
        wp_safe_redirect(admin_url('themes.php'));
        exit;
    }
}
add_action('admin_post_tpi_data_init_dismiss_notice', 'tpi_data_init_dismiss_notice');
