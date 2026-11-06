<?php
/**
 * 结构化设置（JSON-LD 覆盖）
 *
 * 菜单在 inc/admin/admin-menu.php 的 Osiera 下注册。
 *
 * 一页只做一件事：按页面类型填 JSON，覆盖模板已经输出的 JSON-LD。
 * 品牌名、地址、电话这些站点信息请到「页面信息设置」与「多语言内容」里改。
 *
 * 交互与 SMTP / IndexNow 一致：
 *   「全部语言」页签 = 全站默认
 *   某个语言页签     = 只覆盖该语言，留空即继承
 *
 * 读取与合并逻辑在 inc/admin/structured-data.php。
 */

if (!function_exists('tpi_sd_type_labels')) {
    /** 页面类型的标题、说明与占位示例 */
    function tpi_sd_type_labels(): array
    {
        return [
            'common' => [
                'title' => tpi_admin_ui('structured.type.common'),
                'hint'  => tpi_admin_ui('structured.type.common-hint'),
                'demo'  => tpi_admin_ui('structured.demo.common'),
            ],
            'home' => [
                'title' => tpi_admin_ui('structured.type.home'),
                'hint'  => tpi_admin_ui('structured.type.home-hint'),
                'demo'  => tpi_admin_ui('structured.demo.home'),
            ],
            'product' => [
                'title' => tpi_admin_ui('structured.type.product'),
                'hint'  => tpi_admin_ui('structured.type.product-hint'),
                'demo'  => tpi_admin_ui('structured.demo.product'),
            ],
            'post' => [
                'title' => tpi_admin_ui('structured.type.post'),
                'hint'  => tpi_admin_ui('structured.type.post-hint'),
                'demo'  => tpi_admin_ui('structured.demo.post'),
            ],
            'application' => [
                'title' => tpi_admin_ui('structured.type.application'),
                'hint'  => tpi_admin_ui('structured.type.application-hint'),
                'demo'  => tpi_admin_ui('structured.demo.application'),
            ],
            'faq' => [
                'title' => tpi_admin_ui('structured.type.faq'),
                'hint'  => tpi_admin_ui('structured.type.faq-hint'),
                'demo'  => tpi_admin_ui('structured.demo.faq'),
            ],
            'page' => [
                'title' => tpi_admin_ui('structured.type.page'),
                'hint'  => tpi_admin_ui('structured.type.page-hint'),
                'demo'  => tpi_admin_ui('structured.demo.page'),
            ],
        ];
    }
}

if (!function_exists('display_structured_data_page')) {
    function display_structured_data_page(): void
    {
        // 当前编辑的语言：?lang=xx，空表示「全部语言」
        $lang = isset($_GET['lang']) ? strtolower(sanitize_text_field(wp_unslash($_GET['lang']))) : '';
        if ($lang !== '' && !in_array($lang, tpi_language_slugs(), true)) {
            $lang = '';
        }

        $notice = '';

        // ---- 保存 / 清空覆盖 ----
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sd_nonce'])) {
            if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sd_nonce'])), 'tpi_sd_save')) {
                $notice = ['type' => 'error', 'text' => tpi_admin_ui('structured.notice.no-permission')];
            } else {
                $action = sanitize_key(wp_unslash($_POST['sd_action'] ?? 'save'));

                if ($action === 'inherit') {
                    // 清空这个语言的覆盖（「全部语言」页签不提供，清空 textarea 保存即可）
                    if ($lang !== '') {
                        tpi_setting_delete('structured-data', $lang);
                        $notice = [
                            'type' => 'success',
                            'text' => sprintf(
                                tpi_admin_ui('structured.notice.reset-lang'),
                                tpi_language_name($lang)
                            ),
                        ];
                    }
                } else {
                    $base   = tpi_sd_settings('');   // 「全部语言」的生效值
                    $data   = [];
                    $errors = [];

                    foreach (tpi_sd_page_types() as $type) {
                        $key = 'json_' . $type;
                        $raw = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';

                        if (tpi_sd_json_error($raw) !== '') {
                            $errors[] = $raw;
                        }

                        if ($raw === '') {
                            continue;
                        }
                        // 单语言填成与「全部语言」一模一样 = 继承，不落库
                        if ($lang !== '' && $raw === trim((string) ($base[$key] ?? ''))) {
                            continue;
                        }
                        $data[$key] = $raw;
                    }

                    tpi_setting_write($lang === '' ? 0 : tpi_language_id($lang), 'structured-data', $data);

                    $notice = [
                        'type' => 'success',
                        'text' => $lang === ''
                            ? tpi_admin_ui('structured.notice.saved-all')
                            : sprintf(
                                tpi_admin_ui('structured.notice.saved-lang'),
                                tpi_language_name($lang)
                            ),
                    ];
                    if ($errors) {
                        $notice = [
                            'type' => 'warning',
                            'text' => $notice['text'] . ' ' . sprintf(
                                tpi_admin_ui('structured.notice.json-error'),
                                count($errors)
                            ),
                        ];
                    }
                }
            }
        }

        $settings = tpi_sd_settings($lang);
        $own      = tpi_setting_rows('structured-data', $lang === '' ? 0 : tpi_language_id($lang));
        $own_count = 0;
        foreach ($own as $value) {
            if (trim((string) $value) !== '') {
                $own_count++;
            }
        }

        $types = tpi_sd_type_labels();
        ?>
        <div class="ad-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('structured.page.title')); ?></h1>
                <p><?php echo esc_html(tpi_admin_ui('structured.page.hint')); ?></p>
            </div>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div>
            <?php endif; ?>

            <?php tpi_admin_lang_tabs('structured-data', $lang); ?>

            <form method="post">
                <?php wp_nonce_field('tpi_sd_save', 'sd_nonce'); ?>
                <input type="hidden" name="sd_action" value="save">

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('structured.card.help-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('structured.card.help-hint')); ?></p>
                        </div>
                        <div class="ad-card-actions">
                            <span class="ad-badge<?php echo $own_count ? '' : ' is-empty'; ?>">
                                <?php if ($lang === ''): ?>
                                    <?php echo sprintf(esc_html(tpi_admin_ui('structured.badge.set')), (int) $own_count); ?>
                                <?php else: ?>
                                    <?php echo sprintf(esc_html(tpi_admin_ui('structured.badge.customized')), (int) $own_count); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <p class="ad-hint">
                            <?php if ($lang === ''): ?>
                                <?php echo esc_html(tpi_admin_ui('structured.hint.scope-all')); ?>
                            <?php else: ?>
                                <?php echo sprintf(
                                    esc_html(tpi_admin_ui('structured.hint.scope-lang')),
                                    esc_html(tpi_language_name($lang))
                                ); ?>
                            <?php endif; ?>
                        </p>
                        <pre class="ad-code sd-preview"><?php echo esc_html(tpi_admin_ui('structured.card.help-example')); ?></pre>
                    </div>
                </section>

                <?php foreach ($types as $type => $meta):
                    $key     = 'json_' . $type;
                    $value   = (string) ($settings[$key] ?? '');
                    $is_own  = trim((string) ($own[$key] ?? '')) !== '';
                    $summary = tpi_sd_summary($value);
                    ?>
                    <section class="ad-card">
                        <header class="ad-card-head">
                            <div>
                                <h2>
                                    <?php echo esc_html($meta['title']); ?>
                                    <?php if ($lang !== ''): ?>
                                        <code class="sd-flag<?php echo $is_own ? ' is-own' : ''; ?>"><?php echo $is_own ? esc_html(tpi_admin_ui('structured.tag.custom')) : esc_html(tpi_admin_ui('structured.tag.inherit')); ?></code>
                                    <?php endif; ?>
                                </h2>
                                <p><?php echo esc_html($meta['hint']); ?></p>
                            </div>
                        </header>
                        <div class="ad-card-body">
                            <label class="ad-field ad-field-full">
                                <span><?php echo esc_html(tpi_admin_ui('structured.field.json')); ?></span>
                                <textarea name="<?php echo esc_attr($key); ?>" rows="6" class="ad-code sd-json" spellcheck="false" placeholder="<?php echo esc_attr($meta['demo']); ?>"><?php echo esc_textarea($value); ?></textarea>
                                <?php if ($summary['state'] === 'empty'): ?>
                                    <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('structured.status.empty')); ?></span>
                                <?php elseif ($summary['state'] === 'error'): ?>
                                    <span class="ad-hint sd-error"><?php echo sprintf(esc_html(tpi_admin_ui('structured.status.error')), esc_html($summary['error'])); ?></span>
                                <?php elseif ($summary['types']): ?>
                                    <span class="ad-hint"><?php echo sprintf(
                                        esc_html(tpi_admin_ui('structured.status.merge')),
                                        esc_html(implode('、', $summary['types']))
                                    ); ?></span>
                                <?php else: ?>
                                    <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('structured.status.position')); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($summary['root'])): ?>
                                    <span class="ad-hint"><?php echo sprintf(
                                        esc_html(tpi_admin_ui('structured.status.root')),
                                        (int) $summary['root']
                                    ); ?></span>
                                <?php endif; ?>
                            </label>
                        </div>
                    </section>
                <?php endforeach; ?>

                <div class="ad-savebar">
                    <span class="ad-savebar-hint">
                        <?php echo esc_html(tpi_admin_ui('structured.hint.savebar')); ?>
                    </span>
                    <?php if ($lang !== ''): ?>
                        <button type="submit" name="sd_action" value="inherit" class="button" onclick="return confirm('<?php echo esc_js(tpi_admin_ui('structured.confirm.reset-lang')); ?>');">
                            <?php echo esc_html(tpi_admin_ui('structured.button.reset-lang')); ?>
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="sd_action" value="save" class="button button-primary button-large">
                        <?php echo esc_html(tpi_admin_ui('structured.button.save')); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
