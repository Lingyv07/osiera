<?php
/**
 * 资源更新（Osiera → 资源更新）
 *
 * 一页做两件事：
 *   1) 改资源版本号 —— 就是主题里所有 CSS/JS 的 ?ver=，改完保存即刷新浏览器缓存；
 *   2) 写自定义 CSS / JS —— 保存时生成主题里的 assets/css|js/add-custom.css|js，
 *      和主题自己的资源同一个目录、同一套缓存规则，前台用 <link> / <script src> 引用。
 *
 * 内容留空保存 = 删除对应文件，前台不再引用。
 * 读写逻辑在 inc/admin/asset-manager.php。
 */

if (!function_exists('tpi_asset_file_state')) {
    /**
     * 一个类型当前的落地状态
     *
     * @return array{url:string,path:string,size:int,time:int}
     */
    function tpi_asset_file_state(string $type): array
    {
        $path = tpi_asset_file_path($type);
        $exists = is_file($path);

        return [
            'url'  => $exists ? tpi_asset_file_url($type) : '',
            'path' => $path,
            'size' => $exists ? (int) filesize($path) : 0,
            'time' => $exists ? (int) filemtime($path) : 0,
        ];
    }
}

if (!function_exists('display_assets_page')) {
    function display_assets_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(tpi_admin_ui('assets.notice.no-permission'));
        }

        $notice = null;

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['assets_nonce'])) {
            $nonce_ok = wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['assets_nonce'])),
                'tpi_assets_save'
            );

            if (!$nonce_ok) {
                $notice = ['type' => 'error', 'text' => tpi_admin_ui('assets.notice.nonce-expired')];
            } else {
                $result = tpi_asset_save([
                    'version' => sanitize_text_field(wp_unslash($_POST['asset_version'] ?? '')),
                    'css'     => (string) wp_unslash($_POST['asset_css'] ?? ''),
                    'js'      => (string) wp_unslash($_POST['asset_js'] ?? ''),
                ]);

                if (!$result['ok']) {
                    $notice = ['type' => 'warning', 'text' => tpi_admin_ui('assets.notice.write-failed')];
                } else {
                    $notice = ['type' => 'success', 'text' => sprintf(tpi_admin_ui('assets.notice.saved'), $result['version'])];

                    // JS 压缩：先记开关，再按源文件重新生成 js-dist
                    update_option(TPI_JSDIST_ENABLED_OPTION, isset($_POST['jsdist_enabled']) ? '1' : '0', false);
                    $build = tpi_jsdist_build();
                    if ($build['ok']) {
                        $notice['text'] .= ' ' . sprintf(
                            tpi_admin_ui('assets.jsdist.notice-built'),
                            (int) $build['files'],
                            size_format((int) $build['before']),
                            size_format((int) $build['after'])
                        );
                        if (!empty($build['fallback'])) {
                            $notice['type']  = 'warning';
                            $notice['text'] .= ' ' . sprintf(
                                tpi_admin_ui('assets.jsdist.notice-fallback'),
                                (int) $build['fallback']
                            );
                        }
                    } else {
                        $notice['type']  = 'warning';
                        $notice['text'] .= ' ' . tpi_admin_ui('assets.jsdist.notice-build-failed');
                    }
                }
            }
        }

        $version = tpi_asset_version();
        $css     = tpi_asset_code('css');
        $js      = tpi_asset_code('js');
        $states  = [
            'css' => tpi_asset_file_state('css'),
            'js'  => tpi_asset_file_state('js'),
        ];

        // JS 压缩：开关 + 上次生成结果 + 磁盘上实际的文件
        $jsdist = function_exists('tpi_jsdist_state')
            ? tpi_jsdist_state()
            : ['enabled' => true, 'build' => [], 'files' => 0, 'size' => 0];
        $jsdist_build = is_array($jsdist['build']) ? $jsdist['build'] : [];

        // 前台实际输出的样子（给个直观的对照，不用去 view-source）
        // id 由 WordPress 拼：句柄 + -css / -js，所以这里只拼一次后缀
        $handle   = defined('TPI_ASSET_HANDLE') ? TPI_ASSET_HANDLE : 'osiera-add-custom';
        $snippets = [];
        foreach (['css', 'js'] as $type) {
            if ($states[$type]['url'] === '') {
                continue;
            }
            $href = $states[$type]['url'] . '?ver=' . $version;
            $snippets[] = $type === 'css'
                ? '<link rel="stylesheet" id="' . esc_attr($handle . '-css') . '" href="' . esc_url($href) . '" media="all">'
                : '<script src="' . esc_url($href) . '" id="' . esc_attr($handle . '-js') . '"></script>';
        }
        ?>
        <div class="ad-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('assets.page.title')); ?></h1>
                <p><?php echo esc_html(tpi_admin_ui('assets.page.hint')); ?></p>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('tpi_assets_save', 'assets_nonce'); ?>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('assets.card.version-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('assets.card.version-hint')); ?></p>
                        </div>
                        <div class="ad-card-actions">
                            <span class="ad-badge"><?php echo esc_html($version); ?></span>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="ad-grid">
                            <label class="ad-field">
                                <span><?php echo esc_html(tpi_admin_ui('assets.field.version')); ?></span>
                                <input type="text" name="asset_version" value="<?php echo esc_attr($version); ?>"
                                       placeholder="1.0.0" spellcheck="false" autocomplete="off">
                                <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('assets.hint.version')); ?></span>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('assets.card.code-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('assets.card.code-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="ad-grid">
                            <label class="ad-field ad-field-full">
                                <span><?php echo esc_html(tpi_admin_ui('assets.field.css')); ?></span>
                                <textarea name="asset_css" rows="10" class="ad-code" spellcheck="false"
                                          placeholder="<?php echo esc_attr(tpi_admin_ui('assets.demo.css')); ?>"><?php echo esc_textarea($css); ?></textarea>
                                <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('assets.hint.css')); ?></span>
                            </label>
                            <label class="ad-field ad-field-full">
                                <span><?php echo esc_html(tpi_admin_ui('assets.field.js')); ?></span>
                                <textarea name="asset_js" rows="10" class="ad-code" spellcheck="false"
                                          placeholder="<?php echo esc_attr(tpi_admin_ui('assets.demo.js')); ?>"><?php echo esc_textarea($js); ?></textarea>
                                <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('assets.hint.js')); ?></span>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('assets.jsdist.card-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('assets.jsdist.card-hint')); ?></p>
                        </div>
                        <div class="ad-card-actions">
                            <span class="ad-badge">
                                <?php echo esc_html(tpi_admin_ui($jsdist['enabled'] ? 'assets.jsdist.badge-on' : 'assets.jsdist.badge-cdn-only')); ?>
                            </span>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <label class="pi-switch ad-switch">
                            <input type="checkbox" name="jsdist_enabled" value="1" <?php checked($jsdist['enabled']); ?>>
                            <span class="pi-switch-track" aria-hidden="true"></span>
                            <span class="pi-switch-text">
                                <strong><?php echo esc_html(tpi_admin_ui('assets.jsdist.switch')); ?></strong>
                                <em><?php echo esc_html(tpi_admin_ui('assets.jsdist.switch-hint')); ?></em>
                            </span>
                        </label>

                        <?php
                        $dirs     = function_exists('tpi_jsdist_dirs') ? tpi_jsdist_dirs() : ['dist' => '', 'url' => ''];
                        $before   = (int) ($jsdist_build['before'] ?? 0);
                        $after    = (int) ($jsdist_build['after'] ?? 0);
                        // 混淆后体积会变大，这里显示「体积变化」而不是「压缩率」
                        $delta    = $before > 0 ? (int) round(($after - $before) / $before * 100) : 0;
                        $deltaTxt = ($delta > 0 ? '+' : '') . $delta . '%';
                        ?>
                        <p class="ad-hint">
                            <strong><?php echo esc_html(tpi_admin_ui('assets.jsdist.status-title')); ?></strong>
                            —— <?php echo esc_html(sprintf(tpi_admin_ui('assets.jsdist.status-dir'), $dirs['dist'])); ?>
                        </p>
                        <p class="ad-hint">
                            <?php if ($jsdist['files'] > 0): ?>
                                <?php echo esc_html(sprintf(
                                    tpi_admin_ui('assets.jsdist.status-files'),
                                    (int) $jsdist['files'],
                                    size_format((int) $jsdist['size'])
                                )); ?>
                                <?php if ($before > 0): ?>
                                    · <?php echo esc_html(sprintf(
                                        tpi_admin_ui('assets.jsdist.status-ratio'),
                                        size_format($before),
                                        size_format($after),
                                        $deltaTxt
                                    )); ?>
                                <?php endif; ?>
                                <?php if (!empty($jsdist_build['time'])): ?>
                                    · <?php echo esc_html(sprintf(
                                        tpi_admin_ui('assets.jsdist.status-time'),
                                        wp_date('Y-m-d H:i', (int) $jsdist_build['time'])
                                    )); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo esc_html(tpi_admin_ui('assets.jsdist.status-empty')); ?>
                            <?php endif; ?>
                        </p>
                        <p class="ad-hint"><?php echo esc_html(tpi_admin_ui('assets.jsdist.hint-rebuild')); ?></p>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('assets.card.status-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('assets.card.status-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <?php foreach (['css', 'js'] as $type): $state = $states[$type]; ?>
                            <p class="ad-hint">
                                <strong><?php echo esc_html($type === 'css' ? tpi_admin_ui('assets.field.css') : tpi_admin_ui('assets.field.js')); ?></strong>
                                <?php if ($state['url'] === ''): ?>
                                    —— <?php echo esc_html(tpi_admin_ui('assets.status.empty')); ?>
                                <?php else: ?>
                                    —— <code><?php echo esc_html($state['url'] . '?ver=' . $version); ?></code>
                                    · <?php echo esc_html(size_format($state['size'])); ?>
                                    · <?php echo esc_html(wp_date('Y-m-d H:i', $state['time'])); ?>
                                <?php endif; ?>
                            </p>
                        <?php endforeach; ?>

                        <?php if ($snippets): ?>
                            <pre class="ad-code"><?php echo esc_html(implode("\n", $snippets)); ?></pre>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="ad-savebar">
                    <span class="ad-savebar-hint"><?php echo esc_html(tpi_admin_ui('assets.hint.savebar')); ?></span>
                    <button type="submit" class="button button-primary button-large">
                        <?php echo esc_html(tpi_admin_ui('assets.button.save')); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
