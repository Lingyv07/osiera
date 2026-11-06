<?php
/**
 * 邮箱设置（内容异常拦截开关 + SMTP 全站默认 / 单语言覆盖）
 *
 * 存储：{prefix}osiera_language_data，scope=setting、group_key=smtp
 *   lang_id = 0  全站默认
 *   lang_id > 0  单语言覆盖（只存与全站默认不同的项）
 * 读取：内置默认 → 全站默认 → 单语言覆盖
 */

if (!function_exists('tpi_smtp_defaults')) {
    /** 默认配置：来自 languages/admin-system-config-{zh|en}.json 的 settings.smtp */
    function tpi_smtp_defaults(): array
    {
        $defaults = [
            'host'     => '',
            'port'     => '',
            'username' => '',
            'password' => '',
            'secure'   => 'ssl',
            'from'     => '',
            'to'       => '',
            // 询盘内容异常拦截：跟语言走（0 = 不拦截，全放行），没配的语言沿用「全部语言」
            'abnormal_block' => '1',
        ];
        $template = tpi_lang_template_setting('smtp', []);
        if (is_array($template) && $template) {
            $defaults = array_merge($defaults, $template);
        }
        if (tpi_is_blank_setting($defaults['to'] ?? '')) {
            $defaults['to'] = (string) get_option('admin_email');
        }
        return $defaults;
    }
}

if (!function_exists('tpi_get_smtp_settings')) {
    /**
     * 取某个语言生效的 SMTP 配置
     * @param string $lang 空 = 全部语言（基础配置）
     * @return array
     */
    function tpi_get_smtp_settings(string $lang = ''): array
    {
        $settings = tpi_setting_get('smtp', $lang, tpi_smtp_defaults());
        if (tpi_is_blank_setting($settings['to'] ?? '')) {
            $settings['to'] = (string) get_option('admin_email');
        }
        return $settings;
    }
}

// 菜单注册在 inc/admin/admin-menu.php（Osiera → 邮箱设置），这里只管页面

// 2. 显示表单页面
function display_smtp_page(): void {
    // 当前编辑的语言：?lang=xx，空表示「全部语言」
    $lang = isset($_GET['lang']) ? strtolower(sanitize_text_field(wp_unslash($_GET['lang']))) : '';
    if ($lang !== '' && !in_array($lang, tpi_language_slugs(), true)) {
        $lang = '';
    }
    $smtp_settings = tpi_get_smtp_settings($lang);

    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 验证权限和 nonce
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'save_smtp_settings')) {
            echo '<div class="notice notice-error"><p>' . esc_html(tpi_admin_ui('smtp.notice.no-permission')) . '</p></div>';
        } else {
            if (isset($_POST['smtp_save'])) {
                $port_raw = trim((string) sanitize_text_field(wp_unslash($_POST['port'] ?? '')));
                $port     = ($port_raw === '' || (int) $port_raw < 1) ? '' : (string) min(65535, (int) $port_raw);
                $smtp_settings_new = [
                    'host'     => sanitize_text_field($_POST['host'] ?? ''),
                    'port'     => $port,
                    'username' => sanitize_text_field($_POST['username'] ?? ''),
                    'password' => sanitize_text_field($_POST['password'] ?? ''),
                    'secure'   => sanitize_text_field($_POST['secure'] ?? 'ssl'),
                    'from'     => sanitize_email($_POST['from'] ?? ''),
                    'to'       => sanitize_email($_POST['to'] ?? ''),
                    // 内容异常拦截：跟 SMTP 一起按语言存，单语言只记与「全部语言」不同的值
                    'abnormal_block' => isset($_POST['abnormal_block']) ? '1' : '0',
                ];

                if ($lang === '') {
                    // 全部语言：直接作为基础配置
                    tpi_setting_save('smtp', $smtp_settings_new, '');
                    echo '<div class="notice notice-success"><p>' . esc_html(tpi_admin_ui('smtp.notice.saved-all')) . '</p></div>';
                } else {
                    // 单语言：只存跟基础配置不一样的项，其余继续继承
                    $base = tpi_get_smtp_settings('');
                    $own  = [];
                    foreach ($smtp_settings_new as $key => $value) {
                        if (tpi_is_blank_setting($value)) {
                            continue;
                        }
                        if ((string) ($base[$key] ?? '') !== (string) $value) {
                            $own[$key] = $value;
                        }
                    }
                    tpi_setting_save('smtp', $own, $lang);
                    if ($own) {
                        echo '<div class="notice notice-success"><p>' . sprintf(wp_kses_post(tpi_admin_ui('smtp.notice.saved-lang')), esc_html(tpi_language_name($lang)), count($own)) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>' . sprintf(wp_kses_post(tpi_admin_ui('smtp.notice.same-as-all')), esc_html(tpi_language_name($lang))) . '</p></div>';
                    }
                }

                $smtp_settings = tpi_get_smtp_settings($lang);
            }
        }
    }
    // 内容异常拦截开关：取当前语言生效值（该语言没单独配就继承「全部语言」）
    $abnormal_block = (string) ($smtp_settings['abnormal_block'] ?? '1') !== '0';
    // 单语言页面上标一下开关是「继承」还是「已单独设置」，布尔值光看开关看不出来
    $abnormal_block_own = false;
    if ($lang !== '') {
        $lang_id = tpi_language_id($lang);
        $abnormal_block_own = $lang_id > 0 && array_key_exists('abnormal_block', tpi_setting_rows('smtp', $lang_id));
    }
    // 表单 HTML
    ?>
    <div class="ad-wrap">
        <div class="ad-head">
            <h1><?php echo esc_html(tpi_admin_ui('smtp.page.title')); ?></h1>
            <p><?php echo esc_html(tpi_admin_ui('smtp.page.hint')); ?></p>
        </div>

        <?php tpi_admin_lang_tabs('smtp-settings', $lang); ?>

        <?php if ($lang !== ''): ?>
            <div class="notice notice-info inline">
                <p><?php echo sprintf(wp_kses_post(tpi_admin_ui('smtp.hint.editing-lang')), esc_html(tpi_language_name($lang))); ?></p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('save_smtp_settings'); ?>

            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <h2><?php echo esc_html(tpi_admin_ui('smtp.filter.card-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('smtp.filter.card-hint')); ?></p>
                    </div>
                    <?php if ($lang !== ''): ?>
                        <div class="ad-card-actions">
                            <span class="ad-badge"><?php echo esc_html(tpi_admin_ui($abnormal_block_own ? 'smtp.filter.badge-own' : 'smtp.filter.badge-inherit')); ?></span>
                        </div>
                    <?php endif; ?>
                </header>
                <div class="ad-card-body">
                    <label class="pi-switch ad-switch">
                        <input type="checkbox" name="abnormal_block" value="1" <?php checked($abnormal_block); ?>>
                        <span class="pi-switch-track" aria-hidden="true"></span>
                        <span class="pi-switch-text">
                            <strong><?php echo esc_html(tpi_admin_ui('smtp.filter.switch')); ?></strong>
                            <em><?php echo esc_html(tpi_admin_ui('smtp.filter.hint')); ?></em>
                        </span>
                    </label>
                </div>
            </section>

            <h2 class="ad-group-title"><?php echo esc_html(tpi_admin_ui('smtp.group.smtp')); ?></h2>

            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <h2><?php echo esc_html(tpi_admin_ui('smtp.card.server-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('smtp.card.server-hint')); ?></p>
                    </div>
                </header>
                <div class="ad-card-body">
                    <div class="ad-grid">
                        <label class="ad-field">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.host')); ?></span>
                            <input type="text" name="host" id="host" value="<?php echo esc_attr($smtp_settings['host'] ?? ''); ?>" placeholder="smtp.example.com" spellcheck="false" autocomplete="off">
                        </label>
                        <label class="ad-field">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.port')); ?></span>
                            <input type="number" name="port" id="port" value="<?php echo esc_attr($smtp_settings['port'] ?? ''); ?>" min="1" max="65535">
                            <span class="ad-hint"><?php echo wp_kses_post(tpi_admin_ui('smtp.hint.port-common')); ?></span>
                        </label>
                        <label class="ad-field">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.username')); ?></span>
                            <input type="text" name="username" id="username" value="<?php echo esc_attr($smtp_settings['username'] ?? ''); ?>" placeholder="no-reply@example.com" spellcheck="false" autocomplete="off">
                        </label>
                        <label class="ad-field">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.secure')); ?></span>
                            <select name="secure" id="secure">
                                <option value="ssl" <?php selected($smtp_settings['secure'] ?? '', 'ssl'); ?>><?php echo esc_html(tpi_admin_ui('smtp.option.ssl')); ?></option>
                                <option value="tls" <?php selected($smtp_settings['secure'] ?? '', 'tls'); ?>>TLS</option>
                                <option value="none" <?php selected($smtp_settings['secure'] ?? '', 'none'); ?>><?php echo esc_html(tpi_admin_ui('smtp.option.none')); ?></option>
                            </select>
                        </label>
                        <div class="ad-field ad-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.password')); ?></span>
                            <div class="ad-inline">
                                <input type="password" name="password" id="password" value="<?php echo esc_attr($smtp_settings['password'] ?? ''); ?>" autocomplete="new-password" aria-label="<?php echo esc_attr(tpi_admin_ui('smtp.label.password-aria')); ?>">
                                <button type="button" class="button" data-toggle-password="password"><?php echo esc_html(tpi_admin_ui('smtp.button.show')); ?></button>
                            </div>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('smtp.hint.password')); ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <h2><?php echo esc_html(tpi_admin_ui('smtp.card.sender-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('smtp.card.sender-hint')); ?></p>
                    </div>
                </header>
                <div class="ad-card-body">
                    <div class="ad-grid">
                        <label class="ad-field">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.from')); ?></span>
                            <input type="email" name="from" id="from" value="<?php echo esc_attr($smtp_settings['from'] ?? ''); ?>" placeholder="no-reply@example.com" autocomplete="off">
                        </label>
                        <label class="ad-field">
                            <span><?php echo esc_html(tpi_admin_ui('smtp.field.to')); ?></span>
                            <input type="email" name="to" id="to" value="<?php echo esc_attr($smtp_settings['to'] ?? ''); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('smtp.field.to-placeholder')); ?>" autocomplete="off">
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('smtp.hint.to')); ?></span>
                        </label>
                    </div>
                </div>
            </section>

            <div class="ad-savebar">
                <span class="ad-savebar-hint">
                    <?php echo esc_html($lang === '' ? tpi_admin_ui('smtp.hint.savebar-all') : tpi_admin_ui('smtp.hint.savebar-lang')); ?>
                </span>
                <button type="submit" name="smtp_save" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('smtp.button.save')); ?></button>
            </div>
        </form>
    </div>
    <?php
}

// 3. PHPMailer 使用配置（按语言取）
add_action('phpmailer_init', function($phpmailer) {
    // 询盘邮件：语言在发信前已经定好（REST 请求没有语言前缀），
    // 服务器 / 账号 / 发件邮箱都跟这条询盘的语言走，而不是跟默认语言走
    $mail_lang = isset($GLOBALS['tpi_inquiry_mail_lang']) ? (string) $GLOBALS['tpi_inquiry_mail_lang'] : '';
    $settings  = tpi_get_smtp_settings($mail_lang !== '' ? $mail_lang : tpi_current_language());

    if (empty($settings['host']) || empty($settings['username'])) {
        return; // 未配置 SMTP
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = $settings['host'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = (int) ($settings['port'] ?: 465);
    $phpmailer->Username   = $settings['username'];
    $phpmailer->Password   = $settings['password'];
    $phpmailer->SMTPSecure = $settings['secure'] ?? 'ssl';
    $phpmailer->From       = $settings['from'] ?? $settings['username'];
    // 发件人名称用站点名称：收件箱里显示的是网站名，而不是「WordPress」或「询盘」
    $from_name = trim(wp_strip_all_tags((string) get_bloginfo('name')));
    if ($from_name === '') {
        $from_name = tpi_admin_ui('smtp.mail.from-name');
    }
    $phpmailer->FromName = $from_name;
});
