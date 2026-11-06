<?php
/**
 * 站点与路径
 *
 * 全站级配置（不分语言）：
 *   - 目标源：canonical / IndexNow 用的站点根
 *   - 主题资源 / 上传目录 / 核心资源 / 接口 的 URL 前缀
 *
 * 每一项都是「WordPress 原生（默认）/ 自定义前缀」二选一；
 * 选自定义后自己填前缀（可直接贴完整 URL，会自动截出路径），
 * **留空就等于原生**，不会替你塞一个默认前缀。
 * 页面底部的 nginx 片段跟着表单实时重算，不用先保存。
 *
 * 位置：Osiera → 站点与路径
 */

// 菜单注册在 inc/admin/admin-menu.php（Osiera → 站点与路径），这里只管页面

if (!function_exists('tpi_site_paths_field')) {
    /**
     * 一个前缀项的完整表单项：单选 + 填入框
     *
     * @param string $key      assets|uploads|includes|api
     * @param array  $meta     tpi_site_paths_modes() 里的一项
     * @param array  $paths    当前配置
     */
    function tpi_site_paths_field(string $key, array $meta, array $paths): void
    {
        $mode = $paths[$key . '_mode'] ?? 'native';
        // 原生模式下不显示前缀（也不落库），输入框保持空的
        $path = $mode === 'custom' ? (string) ($paths[$key . '_path'] ?? '') : '';
        $name = esc_attr($key);

        $hint = tpi_admin_ui('site_paths.hint.current');
        $hint = str_replace(
            '<code>%s</code>',
            '<code data-sp-current>' . esc_html(tpi_site_paths_current_url($key)) . '</code>',
            $hint
        );
        ?>
        <div class="sp-field" data-sp-field="<?php echo $name; ?>"
             data-sp-native="<?php echo esc_attr($meta['native_url']); ?>">
            <div class="sp-field-head">
                <span class="sp-label"><?php echo esc_html($meta['label']); ?></span>
                <span class="sp-desc"><?php echo esc_html($meta['desc']); ?></span>
            </div>

            <div class="ad-choice-row">
                <label class="ad-choice">
                    <input type="radio" name="<?php echo $name; ?>_mode" value="native"
                        <?php checked($mode, 'native'); ?> data-sp-mode>
                    <span><?php echo esc_html($meta['native_text']); ?></span>
                    <code><?php echo esc_html($meta['native_url']); ?></code>
                </label>
                <label class="ad-choice">
                    <input type="radio" name="<?php echo $name; ?>_mode" value="custom"
                        <?php checked($mode, 'custom'); ?> data-sp-mode>
                    <span><?php echo esc_html(tpi_admin_ui('site_paths.custom')); ?></span>
                </label>
            </div>

            <div class="sp-custom<?php echo $mode === 'custom' ? ' is-on' : ''; ?>" data-sp-custom>
                <input type="text" name="<?php echo $name; ?>_path" data-sp-path
                       value="<?php echo esc_attr($path); ?>"
                       placeholder="<?php echo esc_attr($meta['placeholder']); ?>"
                       spellcheck="false" autocomplete="off">
                <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('site_paths.hint.custom')); ?></span>
            </div>

            <span class="ad-hint"><?php echo wp_kses($hint, ['code' => ['data-sp-current' => true]]); ?></span>
        </div>
        <?php
    }
}

if (!function_exists('display_site_paths_page')) {
    function display_site_paths_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $main_host;

        // 保存
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_paths_save'])) {
            if (isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'save_site_paths')) {
                $input = [
                    'target_host' => wp_unslash($_POST['target_host'] ?? ''),
                ];
                foreach (['assets', 'uploads', 'includes', 'api'] as $key) {
                    $input[$key . '_mode'] = wp_unslash($_POST[$key . '_mode'] ?? 'native');
                    $input[$key . '_path'] = wp_unslash($_POST[$key . '_path'] ?? '');
                }
                $saved = tpi_site_paths_save($input);

                // 目标源空值时，$main_host 保持自动推导结果
                $target = $saved['target_host'];
                echo '<div class="notice notice-success"><p>'
                    . esc_html(tpi_admin_ui('site_paths.notice.saved'))
                    . ($target !== '' ? ' ' . esc_html(sprintf(tpi_admin_ui('site_paths.notice.target'), $target)) : '')
                    . '</p></div>';

                $main_host = $target !== '' ? $target : $main_host;
            } else {
                echo '<div class="notice notice-error"><p>'
                    . esc_html(tpi_admin_ui('site_paths.notice.expired'))
                    . '</p></div>';
            }
        }

        $paths = tpi_site_paths();
        $modes = tpi_site_paths_modes();

        // 给前端实时预览用：站点根 + 每项的原生地址
        $js_cfg = [
            'root'   => tpi_site_root(),
            // rewrite 目标是 URI，站点装在子目录时要把那一段带上
            'base'   => rtrim((string) wp_parse_url(tpi_site_root(), PHP_URL_PATH), '/'),
            'theme'  => basename(get_template_directory()),
            'fields' => [],
            // nginx 片段里的注释也走配置，和 PHP 的 tpi_nginx_snippet() 保持一致
            'nginx'  => [
                'root'     => tpi_admin_ui('site_paths.nginx.root-hint'),
                'includes' => tpi_admin_ui('site_paths.nginx.includes'),
                'assets'   => tpi_admin_ui('site_paths.nginx.assets'),
                'uploads'  => tpi_admin_ui('site_paths.nginx.uploads'),
                'api'      => tpi_admin_ui('site_paths.nginx.api'),
                'native'   => tpi_admin_ui('site_paths.nginx.native'),
            ],
        ];
        foreach ($modes as $k => $m) {
            $js_cfg['fields'][$k] = [
                'native'      => $m['native_url'],
                'placeholder' => $m['placeholder'],
            ];
        }
        ?>
        <div class="ad-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('site_paths.page.title')); ?></h1>
                <p><?php echo esc_html(tpi_admin_ui('site_paths.page.hint')); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('save_site_paths'); ?>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('site_paths.card.target-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('site_paths.card.target-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <label class="ad-field ad-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('site_paths.field.target')); ?></span>
                            <input type="text" name="target_host" value="<?php echo esc_attr($paths['target_host']); ?>"
                                   placeholder="https://www.example.com" spellcheck="false" autocomplete="off">
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('site_paths.hint.target-auto')); ?></span>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('site_paths.hint.target-fixed')); ?></span>
                            <span class="ad-hint"><?php echo sprintf(wp_kses_post(tpi_admin_ui('site_paths.hint.target-current')), esc_html((string) tpi_public_root())); ?></span>
                            <span class="ad-hint"><?php echo sprintf(wp_kses_post(tpi_admin_ui('site_paths.hint.hreflang-current')), esc_html((string) tpi_request_root())); ?></span>
                        </label>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('site_paths.card.prefix-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('site_paths.card.prefix-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <?php foreach ($modes as $key => $meta) { tpi_site_paths_field($key, $meta, $paths); } ?>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('site_paths.card.nginx-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('site_paths.card.nginx-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <label class="ad-field ad-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('site_paths.field.nginx')); ?></span>
                            <textarea class="ad-code" rows="16" readonly spellcheck="false" data-sp-snippet><?php echo esc_textarea(tpi_nginx_snippet($paths)); ?></textarea>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('site_paths.hint.nginx')); ?></span>
                        </label>
                    </div>
                </section>

                <div class="ad-savebar">
                    <span class="ad-savebar-hint"><?php echo esc_html(tpi_admin_ui('site_paths.hint.savebar')); ?></span>
                    <button type="submit" name="site_paths_save" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('site_paths.button.save')); ?></button>
                </div>
            </form>
        </div>

        <script>
        window.tpiSitePathsCfg = <?php echo wp_json_encode($js_cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        (function () {
            const cfg = window.tpiSitePathsCfg || { root: '', fields: {}, nginx: {} };
            const root = cfg.root || '';
            const base = cfg.base || '';
            const theme = cfg.theme || 'osiera';
            const ng = cfg.nginx || {};
            const boxes = Array.prototype.slice.call(document.querySelectorAll('[data-sp-field]'));
            const snippet = document.querySelector('[data-sp-snippet]');

            // 和 PHP 的 tpi_path_normalize() 保持一致
            function normalize(raw) {
                let p = (raw || '').trim();
                if (!p) { return ''; }
                p = p.replace(/^https?:\/\/[^/]+/i, '');
                p = p.split('?')[0].split('#')[0];
                p = '/' + p.replace(/^\/+/, '');
                p = p.replace(/\/{2,}/g, '/').replace(/\/+$/, '');
                return p;
            }

            function stateOf(box) {
                const on = box.querySelector('[data-sp-mode]:checked');
                const input = box.querySelector('[data-sp-path]');
                return {
                    mode: on ? on.value : 'native',
                    path: normalize(input ? input.value : '')
                };
            }

            function prefixOf(st) {
                return (st.mode === 'custom' && st.path) ? st.path : '';
            }

            function escRe(s) {
                return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            // 和 PHP 的 tpi_nginx_snippet() 保持一致
            function buildSnippet(states) {
                const lines = [ng.root || '# 推荐：静态资源用 rewrite 转发到真实目录，比 alias 稳，不用管尾部斜杠'];
                const block = (title, body) => {
                    lines.push('');
                    lines.push(title);
                    body.forEach((l) => lines.push(l));
                };

                const inc = prefixOf(states.includes || {});
                const ast = prefixOf(states.assets || {});
                const upl = prefixOf(states.uploads || {});
                const api = prefixOf(states.api || {});

                if (inc) {
                    block(ng.includes || '# WordPress 核心资源（把前缀指到站点自己的 wp-includes 目录）', [
                        'location ^~ ' + inc + '/ {',
                        '    rewrite ^' + escRe(inc) + '/(.*)$ ' + base + '/wp-includes/$1 last;',
                        '}'
                    ]);
                }
                if (ast) {
                    block(ng.assets || '# 主题静态资源', [
                        'location ^~ ' + ast + '/ {',
                        '    rewrite ^' + escRe(ast) + '/(.*)$ ' + base + '/wp-content/themes/' + theme + '/assets/$1 last;',
                        '}'
                    ]);
                }
                if (upl) {
                    block(ng.uploads || '# 上传目录', [
                        'location ^~ ' + upl + '/ {',
                        '    rewrite ^' + escRe(upl) + '/(.*)$ ' + base + '/wp-content/uploads/$1 last;',
                        '}'
                    ]);
                }
                if (api) {
                    block(ng.api || '# 接口（转给 WordPress 的 REST 路由，不要改 /wp-json 这一段）', [
                        'location ^~ ' + api + '/ {',
                        '    proxy_pass ' + root + '/wp-json/api/;',
                        '    proxy_set_header Host $host;',
                        '    proxy_set_header X-Real-IP $remote_addr;',
                        '    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
                        '    proxy_set_header X-Forwarded-Proto $scheme;',
                        '}'
                    ]);
                }

                if (lines.length === 1) {
                    lines.push('');
                    lines.push(ng.native || '# 当前全部使用 WordPress 原生路径，不需要额外配置');
                }
                return lines.join('\n');
            }

            function render() {
                const states = {};
                boxes.forEach((box) => {
                    const key = box.getAttribute('data-sp-field');
                    const st = stateOf(box);
                    states[key] = st;

                    const custom = box.querySelector('[data-sp-custom]');
                    if (custom) { custom.classList.toggle('is-on', st.mode === 'custom'); }

                    const out = box.querySelector('[data-sp-current]');
                    if (out) {
                        const nativeUrl = box.getAttribute('data-sp-native') || '';
                        out.textContent = prefixOf(st) ? root + prefixOf(st) : nativeUrl;
                    }
                });
                if (snippet) { snippet.value = buildSnippet(states); }
            }

            boxes.forEach((box) => {
                box.addEventListener('change', render);
                box.addEventListener('input', render);
            });
            render();
        })();
        </script>
        <?php
    }
}
