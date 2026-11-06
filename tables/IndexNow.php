<?php
/**
 * IndexNow 设置（全站默认 + 单语言覆盖）
 *
 * 存储：{prefix}osiera_language_data，scope=setting、group_key=indexnow
 * 「获取全部 URL」只抓当前所选语言的内容，方便按语言分批推送。
 * 密钥文件 /{key}.txt 会匹配全部语言里配置过的任何一个 key。
 */

if (!function_exists('tpi_indexnow_defaults')) {
    function tpi_indexnow_defaults(): array
    {
        // 密钥属于站点自己的数据，不从配置里带默认值（新站点后台自己填）
        $defaults = ['index_now_key' => ''];
        $template = tpi_lang_template_setting('indexnow', []);
        if (is_array($template) && $template) {
            $defaults = array_merge($defaults, $template);
        }
        return $defaults;
    }
}

if (!function_exists('tpi_get_indexnow_settings')) {
    /**
     * @param string $lang 空 = 全部语言
     * @return array
     */
    function tpi_get_indexnow_settings(string $lang = ''): array
    {
        $settings = tpi_setting_get('indexnow', $lang, tpi_indexnow_defaults());

        // 兼容更早版本的 wp_common_option
        if (tpi_is_blank_setting($settings['index_now_key'] ?? '')) {
            $legacy = get_option('wp_common_option', []);
            $legacy_key = is_array($legacy) ? (string) ($legacy['index_now_key'] ?? '') : '';
            if (!tpi_is_blank_setting($legacy_key)) {
                $settings['index_now_key'] = $legacy_key;
            }
        }
        return $settings;
    }
}

if (!function_exists('tpi_indexnow_all_keys')) {
    /** 所有语言配置过的密钥（用于 /{key}.txt 校验） */
    function tpi_indexnow_all_keys(): array
    {
        $keys = [];
        $all = tpi_get_indexnow_settings('');
        if (!tpi_is_blank_setting($all['index_now_key'] ?? '')) {
            $keys[] = trim((string) $all['index_now_key']);
        }
        foreach (tpi_language_slugs() as $lang) {
            $own = tpi_setting_rows('indexnow', tpi_language_id($lang));
            if (!tpi_is_blank_setting($own['index_now_key'] ?? '')) {
                $keys[] = trim((string) $own['index_now_key']);
            }
        }
        return array_values(array_unique(array_filter($keys)));
    }
}

if (!function_exists('tpi_indexnow_url_list')) {
    /**
     * 已发布内容的 URL 列表
     * @param string $lang 空 = 全部语言
     * @return array
     */
    function tpi_indexnow_url_list(string $lang = ''): array
    {
        $ids = get_posts([
            'post_type'              => ['post', 'page', 'product', 'application', 'faq'],
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'update_post_term_cache' => false,
        ]);
        if (!$ids) {
            return [];
        }

        $map = [];
        if ($lang !== '') {
            // 一次取完所有内容的语言归属，避免逐篇查
            $terms = wp_get_object_terms($ids, 'tpi_lang', ['fields' => 'all_with_object_id']);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $map[(int) $term->object_id] = (string) $term->slug;
                }
            }
            $default = tpi_default_language();
        }

        $urls = [];
        foreach ($ids as $id) {
            if ($lang !== '') {
                $post_lang = $map[(int) $id] ?? $default;
                if ($post_lang !== $lang) {
                    continue;
                }
            }
            $permalink = get_permalink($id);
            if ($permalink) {
                $urls[] = $permalink;
            }
        }
        return $urls;
    }
}

// 菜单注册在 inc/admin/admin-menu.php（Osiera → IndexNow 设置），这里只管页面

// 密钥校验文件：/{key}.txt，任何语言配置过的 key 都认
add_action('init', function() {
    $keys = tpi_indexnow_all_keys();
    if (!$keys) {
        return;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $request_uri = trim((string) $path, '/');
    foreach ($keys as $key) {
        if ($key !== '' && $request_uri === $key . '.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $key;
            exit;
        }
    }
});

// 2. 显示表单页面
function display_index_now_page(): void {
    global $old_host, $main_domain, $main_host;

    // 当前编辑的语言：?lang=xx，空表示「全部语言」
    $lang = isset($_GET['lang']) ? strtolower(sanitize_text_field(wp_unslash($_GET['lang']))) : '';
    if ($lang !== '' && !in_array($lang, tpi_language_slugs(), true)) {
        $lang = '';
    }

    $settings = tpi_get_indexnow_settings($lang);
    $key = (string) ($settings['index_now_key'] ?? '');

    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // IndexNow 配置保存
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(tpi_admin_ui('indexnow.notice.no-permission')) . '</p></div>';
        } else if (wp_verify_nonce($_POST['_wpnonce'], 'save_index_now_settings')) {
            if (isset($_POST['index_now_save'])) {
                $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
                $notice_text = '';
                if ($lang === '') {
                    tpi_setting_write(0, 'indexnow', ['index_now_key' => $key]);
                    $notice_text = tpi_admin_ui('indexnow.notice.saved-all');
                } else {
                    $base_key = (string) (tpi_get_indexnow_settings('')['index_now_key'] ?? '');
                    if ($key === '' || $key === $base_key) {
                        tpi_setting_delete('indexnow', $lang);
                        $notice_text = sprintf(tpi_admin_ui('indexnow.notice.reset-inherit'), tpi_language_name($lang));
                    } else {
                        tpi_setting_write(tpi_language_id($lang), 'indexnow', ['index_now_key' => $key]);
                        $notice_text = sprintf(tpi_admin_ui('indexnow.notice.saved-lang'), tpi_language_name($lang));
                    }
                }
                echo '<div class="notice notice-success"><p>' . esc_html($notice_text) . '</p></div>';
            }
        } else if (wp_verify_nonce($_POST['_wpnonce'], 'submit_index_now_settings')) {
            // IndexNow 提交
            if (isset($_POST['url_urls_submit'])) {
                $url_arr = explode(';', $_POST['urls'] ?? '');
                $url_arr = array_filter(array_map('trim', $url_arr));
                $url_arr = array_values($url_arr);

                if (count($url_arr)) {
                    $keyLocation = "{$main_host}/{$key}.txt";

                    $payload = [
                        'host' => $main_domain,
                        'key' => $key,
                        'keyLocation' => $keyLocation,
                        'urlList' => $url_arr
                    ];

                    $response = wp_remote_post('https://api.indexnow.org/indexnow', [
                        'headers' => [
                            'Content-Type' => 'application/json; charset=utf-8'
                        ],
                        'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
                        'timeout' => 30
                    ]);

                    if (is_wp_error($response)) {
                        echo '<div class="notice notice-error"><p>' . esc_html(tpi_admin_ui('indexnow.notice.submit-failed')) . '</p></div>';
                    } else {
                        $code = $response['response']['code'];
                        if ($code == 200) {
                            echo '<div class="notice notice-success"><p>' . esc_html(tpi_admin_ui('indexnow.notice.submit-success')) . '</p></div>';
                        } else {
                            $body = json_decode($response['body'], true);
                            $message = $response['response']['message'];
                            echo '<div class="notice notice-error"><p>' . esc_html(tpi_admin_ui('indexnow.notice.submit-failed')) . '</p></div>';
                            echo '<div class="notice notice-error"><p>'. $message. ': '. ($body['message'] ?? $message) . '</p></div>';
                        }
                    }
                }
            }
        }
    }

    // URL 列表按当前所选语言过滤
    $url_list = tpi_indexnow_url_list($lang);
    $url_list = array_map(function ($url) use ($old_host, $main_host) {
        return str_replace((string) $old_host, (string) $main_host, $url);
    }, $url_list);
    $urls_str = $url_list ? implode(';', $url_list) . ';' : '';
    $url_count = count($url_list);
    $lang_text = $lang !== '' ? tpi_language_name($lang) : tpi_admin_ui('indexnow.label.all-languages');
    ?>
    <div class="ad-wrap">
        <div class="ad-head">
            <h1><?php echo esc_html(tpi_admin_ui('indexnow.page.title')); ?></h1>
            <p><?php echo esc_html(tpi_admin_ui('indexnow.page.hint')); ?></p>
        </div>

        <?php tpi_admin_lang_tabs('index-now-settings', $lang); ?>

        <form method="post">
            <?php wp_nonce_field('save_index_now_settings'); ?>
            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <h2><?php echo esc_html(tpi_admin_ui('indexnow.card.key-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('indexnow.card.key-hint')); ?></p>
                    </div>
                </header>
                <div class="ad-card-body">
                    <div class="ad-field ad-field-full">
                        <span><?php echo sprintf(esc_html(tpi_admin_ui('indexnow.label.key-with-lang')), esc_html($lang_text)); ?></span>
                        <div class="ad-inline">
                            <input type="text" name="key" id="key" value="<?php echo esc_attr($key); ?>" spellcheck="false" autocomplete="off" aria-label="<?php echo esc_attr(tpi_admin_ui('indexnow.label.key-aria')); ?>">
                            <button type="submit" name="index_now_save" class="button button-primary"><?php echo esc_html(tpi_admin_ui('indexnow.button.save')); ?></button>
                        </div>
                        <?php if ($key): ?>
                            <span class="ad-hint"><?php echo sprintf(wp_kses_post(tpi_admin_ui('indexnow.hint.key-file')), esc_html($key)); ?></span>
                        <?php else: ?>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('indexnow.hint.no-key')); ?></span>
                        <?php endif; ?>
                        <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('indexnow.hint.savebar')); ?></span>
                        <?php if ($lang !== ''): ?>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('indexnow.hint.inherit')); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </form>

        <form method="post">
            <?php wp_nonce_field('submit_index_now_settings'); ?>
            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <h2><?php echo esc_html(tpi_admin_ui('indexnow.card.submit-title')); ?></h2>
                        <p><?php echo sprintf(esc_html(tpi_admin_ui('indexnow.hint.url-list')), esc_html($lang_text)); ?></p>
                    </div>
                    <div class="ad-card-actions">
                        <span class="ad-badge" id="ix-url-count">0 <?php echo esc_html(tpi_admin_ui('indexnow.badge.url-unit')); ?></span>
                        <button type="button" id="get_urls_btn" class="button"><?php echo esc_html(tpi_admin_ui('indexnow.button.get-urls')); ?></button>
                    </div>
                </header>
                <div class="ad-card-body">
                    <label class="ad-field">
                        <span><?php echo esc_html(tpi_admin_ui('indexnow.field.urls')); ?></span>
                        <textarea name="urls" id="urls" class="ad-code" spellcheck="false" placeholder="https://example.com/page-1;&#10;https://example.com/page-2;"></textarea>
                        <span class="ad-hint"><?php echo sprintf(wp_kses_post(tpi_admin_ui('indexnow.hint.published')), esc_html($lang_text), (int) $url_count); ?></span>
                    </label>
                </div>
            </section>
            <div class="ad-savebar">
                <span class="ad-savebar-hint"><?php echo esc_html(tpi_admin_ui('indexnow.hint.submit')); ?></span>
                <button type="submit" name="url_urls_submit" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('indexnow.button.submit')); ?></button>
            </div>
        </form>

        <input id="all_url" type="hidden" value="<?php echo esc_attr($urls_str); ?>">
    </div>
    <?php
}
