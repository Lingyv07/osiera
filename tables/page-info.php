<?php
// 1. 添加后台菜单
function add_info_menu(): void {
    add_menu_page(
        'PageInfo',               // 页面标题
        tpi_admin_ui('page-info.menu-title'),                   // 菜单标题
        'manage_options',          // 权限
        'page-settings',           // slug
        'display_page_info',       // 回调函数
        'dashicons-admin-generic',     // 图标
        22.5                       // 排序：紧跟应用产品管理，让 FAQ 起的那一列菜单连续
    );
}
add_action('admin_menu', 'add_info_menu');

/**
 * 后台资源：仅「页面信息设置」页加载
 */
function page_info_admin_assets($hook_suffix): void {
    global $version;
    if ($hook_suffix !== 'toplevel_page_page-settings') {
        return;
    }
    wp_enqueue_media();

    wp_enqueue_script(
        'wp-page-info',
        get_template_directory_uri() . '/assets/wp-assets/js/wp-page-info.js',
        array('jquery', 'jquery-ui-sortable'),
        $version,
        true
    );

    // 编辑器里的文案（证书 / 轮播 / 社媒 / 媒体库）走文案真源，
    // 不再写死在 JS 里：切换后台语言时跟着 admin-system-config-{zh|en}.json 变
    $admin_meta = tpi_admin_config('meta');
    $page_info_js_ui = $admin_meta['ui']['page-info']['js'] ?? [];
    if (!is_array($page_info_js_ui)) {
        $page_info_js_ui = [];
    }
    wp_localize_script('wp-page-info', 'tpiPI', $page_info_js_ui);
}
add_action('admin_enqueue_scripts', 'page_info_admin_assets');

// 2. 显示表单页面
function display_page_info(): void {
    // 当前编辑语言：跟随后台语言筛选（?lang=xx / 上次筛选）；留空 = 全部语言（写全站默认，lang_id 0）
    $lang = tpi_admin_language_filter();
    if ($lang !== '' && !in_array($lang, tpi_language_slugs(), true)) {
        $lang = '';
    }
    // 全站默认 → 当前语言覆盖 → 内置默认
    $page_info_settings = tpi_get_page_info_settings($lang);

    // 处理表单提交
    $cert_notice = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // 验证权限和 nonce
        if (!current_user_can('manage_options') || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'save_page_info_settings')) {
            echo '<div class="notice notice-error"><p>' . esc_html(tpi_admin_ui('page-info.notice.no-permission')) . '</p></div>';
        } else {

            $new_images = [];
            if (!empty($_POST['images_src']) && is_array($_POST['images_src'])) {
                foreach ($_POST['images_src'] as $index => $src) {
                    $src = sanitize_text_field($src);
                    // 轮播图统一存上传目录相对路径（2026/01/a.jpg），不写死域名
                    $src = tpi_media_store($src);
                    // 跳转路由存站内相对路径（/products），不存带域名的地址
                    $route = tpi_route_rel(sanitize_text_field($_POST['images_route'][$index] ?? ""));
                    $new_images[] = ['src' => $src, 'route' => $route];
                }
            }
            if (isset($_POST['page_info_save'])) {
                // 证书 JSON 原文
                // 注意：WP 已对 $_POST 做过 addslashes，这里只还原一次，切勿重复 stripslashes
                $cert_raw = isset($_POST['certificates_json']) ? wp_unslash($_POST['certificates_json']) : '';
                // 格式校验（仅提示，不阻断保存，避免写一半的内容丢失）
                $cert_is_valid = true;
                if (trim($cert_raw) !== '') {
                    json_decode($cert_raw, true);
                    $cert_is_valid = json_last_error() === JSON_ERROR_NONE;
                }

                // 社媒列表：表单按数组提交，这里合并成 JSON 存储
                $media_list_new = [];
                $media_names = (isset($_POST['media_name']) && is_array($_POST['media_name'])) ? $_POST['media_name'] : [];
                foreach ($media_names as $index => $media_name) {
                    $media_name = sanitize_text_field($media_name);
                    $media_url  = esc_url_raw($_POST['media_url'][$index] ?? '');
                    $media_icon = esc_url_raw($_POST['media_icon_url'][$index] ?? '');
                    if ($media_name === '' || $media_url === '') {
                        continue;
                    }
                    $media_list_new[] = [
                        'name' => $media_name,
                        'url' => $media_url,
                        'icon_url' => $media_icon,
                    ];
                }

                // 保存
                $page_info_settings_new = [
                    'images' => json_encode($new_images),
                    'company_introduction_video'     => sanitize_text_field($_POST['company_introduction_video'] ?? ''),
                    'company_video'     => sanitize_text_field($_POST['company_video'] ?? ''),
                    'custom_bg_video'     => sanitize_text_field($_POST['custom_bg_video'] ?? ''),
                    'product_top_image' => sanitize_text_field($_POST['product_top_image'] ?? ''),
                    'post_top_image' => sanitize_text_field($_POST['post_top_image'] ?? ''),
                    'application_top_image' => sanitize_text_field($_POST['application_top_image'] ?? ''),
                    'faq_top_image' => sanitize_text_field($_POST['faq_top_image'] ?? ''),
                    'about_top_image' => sanitize_text_field($_POST['about_top_image'] ?? ''),
                    'contact_top_image' => sanitize_text_field($_POST['contact_top_image'] ?? ''),
                    'certificates_top_image' => sanitize_text_field($_POST['certificates_top_image'] ?? ''),
                    'telephone'     => sanitize_text_field($_POST['telephone'] ?? ''),
                    'whats_app'     => sanitize_text_field($_POST['whats_app'] ?? ''),
                    'address'     => sanitize_text_field($_POST['address'] ?? ''),
                    'same_as'     => sanitize_text_field($_POST['same_as'] ?? ''),
                    'certificates_json'     => $cert_raw,
                    'email' => sanitize_text_field($_POST['email'] ?? ''),
                    'copy_info' => sanitize_text_field($_POST['copy_info'] ?? ''),
                    'home_main_image' => sanitize_text_field($_POST['home_main_image'] ?? ''),
                    'company_video_image' => sanitize_text_field($_POST['company_video_image'] ?? ''),
                    'about_culture_image' => sanitize_text_field($_POST['about_culture_image'] ?? ''),
                    // 社媒与地图
                    'media_list_json' => wp_json_encode($media_list_new),
                    'google_map_url' => esc_url_raw($_POST['google_map_url'] ?? ''),
                    'google_map_src' => esc_url_raw($_POST['google_map_src'] ?? ''),
                    'whatsapp_url' => esc_url_raw($_POST['whatsapp_url'] ?? ''),
                    'whatsapp_text' => sanitize_text_field($_POST['whatsapp_text'] ?? ''),
                    // 默认图片
                    'default_image_post' => esc_url_raw($_POST['default_image_post'] ?? ''),
                    'default_image_new' => esc_url_raw($_POST['default_image_new'] ?? ''),
                    'default_image_product' => esc_url_raw($_POST['default_image_product'] ?? ''),
                    'default_image_application' => esc_url_raw($_POST['default_image_application'] ?? ''),
                    'default_image_faq' => esc_url_raw($_POST['default_image_faq'] ?? ''),
                ];
                // 「全部语言」时写全站默认值，各语言只写自己的覆盖值
                $page_info_settings = $page_info_settings_new;
                tpi_setting_save('page-info', $page_info_settings_new, $lang);

                if ($cert_is_valid) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(tpi_admin_ui('page-info.notice.saved')) . '</p></div>';
                } else {
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(tpi_admin_ui('page-info.notice.saved-cert-invalid')) . '</p></div>';
                }
            } elseif (isset($_POST['page_info_reset'])) {
                // 重置：清掉这一层的配置，回落到全站默认 / 主题内置默认
                tpi_setting_delete('page-info', $lang);
                $page_info_settings = tpi_get_page_info_settings($lang);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(tpi_admin_ui('page-info.notice.reset-done')) . '</p></div>';
            }
        }
    }

    // ---- 准备渲染数据 ----
    $images = json_decode($page_info_settings["images"] ?? '[]', true);
    if (!is_array($images)) {
        $images = [];
    }

    // 单图配置项： [字段名, 名称, 说明]
    $media_images = [
        ['home_main_image', tpi_admin_ui('page-info.image.home-main-title'), tpi_admin_ui('page-info.image.home-main-hint')],
        ['company_video_image', tpi_admin_ui('page-info.image.video-title'), tpi_admin_ui('page-info.image.video-hint')],
        ['product_top_image', tpi_admin_ui('page-info.image.product-top-title'), tpi_admin_ui('page-info.image.product-top-hint')],
        ['post_top_image', tpi_admin_ui('page-info.image.post-top-title'), tpi_admin_ui('page-info.image.post-top-hint')],
        ['application_top_image', tpi_admin_ui('page-info.image.application-top-title'), tpi_admin_ui('page-info.image.application-top-hint')],
        ['faq_top_image', tpi_admin_ui('page-info.image.faq-top-title'), tpi_admin_ui('page-info.image.faq-top-hint')],
        ['about_top_image', tpi_admin_ui('page-info.image.about-top-title'), tpi_admin_ui('page-info.image.about-top-hint')],
        ['about_culture_image', tpi_admin_ui('page-info.image.about-culture-title'), tpi_admin_ui('page-info.image.about-culture-hint')],
        ['contact_top_image', tpi_admin_ui('page-info.image.contact-top-title'), tpi_admin_ui('page-info.image.contact-top-hint')],
        ['certificates_top_image', tpi_admin_ui('page-info.image.certificates-top-title'), tpi_admin_ui('page-info.image.certificates-top-hint')],
    ];

    // 视频配置项
    $media_videos = [
        ['company_video', tpi_admin_ui('page-info.video.company-title')],
        ['custom_bg_video', tpi_admin_ui('page-info.video.custom-bg-title')],
    ];

    // 默认图片配置项：[字段名, 名称, 说明]
    $default_images = [
        ['default_image_new', tpi_admin_ui('page-info.default-image.new-title'), tpi_admin_ui('page-info.default-image.new-hint')],
        ['default_image_product', tpi_admin_ui('page-info.default-image.product-title'), tpi_admin_ui('page-info.default-image.product-hint')],
        ['default_image_application', tpi_admin_ui('page-info.default-image.application-title'), tpi_admin_ui('page-info.default-image.application-hint')],
        ['default_image_faq', tpi_admin_ui('page-info.default-image.faq-title'), tpi_admin_ui('page-info.default-image.faq-hint')],
        ['default_image_post', tpi_admin_ui('page-info.default-image.post-title'), tpi_admin_ui('page-info.default-image.post-hint')],
    ];

    // 社媒列表：未配置时先展示主题内置，直接改完保存即可固化
    $media_list_render = tpi_page_info_media_list($page_info_settings);

    // 证书 JSON（用于可视化编辑器）
    $cert_json_raw = $page_info_settings['certificates_json'] ?? '';
    $cert_obj = json_decode($cert_json_raw, true);
    if (!is_array($cert_obj)) {
        $cert_obj = [];
    }
    $cert_json_safe = wp_json_encode($cert_obj, JSON_UNESCAPED_UNICODE);
    if (!$cert_json_safe) {
        $cert_json_safe = '{}';
    }
    // 防止 </script> 截断
    $cert_json_safe = str_replace('</', '<\\/', $cert_json_safe);

    // 外链认可
    $same_as_raw = $page_info_settings['same_as'] ?? '';
    ?>
    <div class="pi-wrap">
        <div class="pi-head">
            <div class="pi-head-main">
                <h1><?php echo esc_html(tpi_admin_ui('page-info.page.title')); ?></h1>
            </div>
            <p class="pi-head-desc"><?php echo esc_html(tpi_admin_ui('page-info.page.hint')); ?></p>
        </div>

        <?php tpi_admin_lang_tabs('page-settings', $lang); ?>

        <form method="post" id="pi-form">
            <?php wp_nonce_field('save_page_info_settings'); ?>
            <input type="hidden" name="company_introduction_video" value="<?php echo esc_attr($page_info_settings['company_introduction_video'] ?? ''); ?>">

            <!-- 首页轮播图 -->
            <section class="pi-card">
                <header class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('page-info.card.slides-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('page-info.card.slides-hint')); ?></p>
                    </div>
                    <button type="button" class="button pi-btn-add" id="pi-add-slide"><?php echo esc_html(tpi_admin_ui('page-info.button.add-image')); ?></button>
                </header>
                <div class="pi-card-body">
                    <ul class="pi-slides" id="pi-slides">
                        <?php foreach ($images as $img): ?>
                            <li class="pi-slide">
                                <div class="pi-slide-thumb" data-preview="slide">
                                    <img src="<?php echo esc_url(tpi_media_url($img['src'] ?? '')); ?>" alt="">
                                    <span class="pi-thumb-hint"><?php echo esc_html(tpi_admin_ui('page-info.label.change-image')); ?></span>
                                    <button type="button" class="pi-slide-del" title="<?php echo esc_attr(tpi_admin_ui('page-info.label.remove-image')); ?>">×</button>
                                </div>
                                <input type="hidden" name="images_src[]" value="<?php echo esc_attr($img['src'] ?? ''); ?>">
                                <label class="pi-slide-route">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.route')); ?></span>
                                    <input type="text" name="images_route[]" value="<?php echo esc_attr(tpi_route_rel($img['route'] ?? '')); ?>" placeholder="/products">
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="pi-empty-tip" id="pi-slides-empty" <?php echo $images ? 'hidden' : ''; ?>><?php echo esc_html(tpi_admin_ui('page-info.empty.slides')); ?></p>
                </div>
            </section>

            <!-- 页面图片 -->
            <section class="pi-card">
                <header class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('page-info.card.images-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('page-info.card.images-hint')); ?></p>
                    </div>
                </header>
                <div class="pi-card-body">
                    <div class="pi-media-grid">
                        <?php foreach ($media_images as $item):
                            $key = $item[0];
                            $val = $page_info_settings[$key] ?? '';
                            ?>
                            <div class="pi-media-item">
                                <div class="pi-media-title"><?php echo esc_html($item[1]); ?></div>
                                <div class="pi-media-desc"><?php echo esc_html($item[2]); ?></div>
                                <div class="pi-thumb pi-thumb-wide" data-preview="<?php echo esc_attr($key); ?>">
                                    <img src="<?php echo esc_url($val); ?>" alt="">
                                </div>
                                <input type="hidden" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_url($val); ?>">
                                <div class="pi-media-actions">
                                    <button type="button" class="button pi-upload" data-target="<?php echo esc_attr($key); ?>" data-library="image"><?php echo esc_html(tpi_admin_ui('page-info.button.choose-image')); ?></button>
                                    <button type="button" class="button-link pi-remove" data-target="<?php echo esc_attr($key); ?>"><?php echo esc_html(tpi_admin_ui('page-info.button.remove')); ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- 默认图片 -->
            <section class="pi-card">
                <header class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('page-info.card.default-images-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('page-info.card.default-images-hint')); ?></p>
                    </div>
                </header>
                <div class="pi-card-body">
                    <div class="pi-media-grid pi-media-grid-five">
                        <?php foreach ($default_images as $item):
                            $key = $item[0];
                            $val = $page_info_settings[$key] ?? '';
                            ?>
                            <div class="pi-media-item">
                                <div class="pi-media-title"><?php echo esc_html($item[1]); ?></div>
                                <div class="pi-media-desc"><?php echo esc_html($item[2]); ?></div>
                                <div class="pi-thumb pi-thumb-square" data-preview="<?php echo esc_attr($key); ?>">
                                    <img src="<?php echo esc_url($val); ?>" alt="">
                                </div>
                                <input type="hidden" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_url($val); ?>">
                                <div class="pi-media-actions">
                                    <button type="button" class="button pi-upload" data-target="<?php echo esc_attr($key); ?>" data-library="image"><?php echo esc_html(tpi_admin_ui('page-info.button.choose-image')); ?></button>
                                    <button type="button" class="button-link pi-remove" data-target="<?php echo esc_attr($key); ?>"><?php echo esc_html(tpi_admin_ui('page-info.button.remove')); ?></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- 两栏：视频 | 联系方式 -->
            <div class="pi-cols">
                <div class="pi-col">

                    <!-- 视频 -->
                    <section class="pi-card">
                        <header class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('page-info.card.video-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('page-info.card.video-hint')); ?></p>
                            </div>
                        </header>
                        <div class="pi-card-body">
                            <div class="pi-media-grid">
                                <?php foreach ($media_videos as $item):
                                    $key = $item[0];
                                    $val = $page_info_settings[$key] ?? '';
                                    ?>
                                    <div class="pi-media-item">
                                        <div class="pi-media-title"><?php echo esc_html($item[1]); ?></div>
                                        <div class="pi-media-desc"><?php echo esc_html(basename((string) parse_url($val, PHP_URL_PATH)) ?: tpi_admin_ui('page-info.video.none')); ?></div>
                                        <div class="pi-thumb pi-thumb-wide pi-thumb-video" data-preview="<?php echo esc_attr($key); ?>">
                                            <video src="<?php echo esc_url($val); ?>" controls preload="metadata"></video>
                                        </div>
                                        <input type="hidden" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_url($val); ?>">
                                        <div class="pi-media-actions">
                                            <button type="button" class="button pi-upload" data-target="<?php echo esc_attr($key); ?>" data-library="video"><?php echo esc_html(tpi_admin_ui('page-info.button.choose-video')); ?></button>
                                            <button type="button" class="button-link pi-remove" data-target="<?php echo esc_attr($key); ?>"><?php echo esc_html(tpi_admin_ui('page-info.button.remove')); ?></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                </div>
                <div class="pi-col">

                    <!-- 联系方式 -->
                    <section class="pi-card">
                        <header class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('page-info.card.contact-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('page-info.card.contact-hint')); ?></p>
                            </div>
                        </header>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.telephone')); ?></span>
                                    <input type="text" name="telephone" id="telephone" value="<?php echo esc_attr($page_info_settings['telephone'] ?? ''); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('page-info.field.telephone-placeholder')); ?>">
                                </label>
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.email')); ?></span>
                                    <input type="text" name="email" id="email" value="<?php echo esc_attr($page_info_settings['email'] ?? ''); ?>" placeholder="sales@example.com">
                                </label>
                                <label class="pi-field">
                                    <span>WhatsApp</span>
                                    <input type="text" name="whats_app" id="whats_app" value="<?php echo esc_attr($page_info_settings['whats_app'] ?? ''); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('page-info.field.whatsapp-placeholder')); ?>">
                                </label>
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.copy-info')); ?></span>
                                    <input type="text" name="copy_info" id="copy_info" value="<?php echo esc_attr($page_info_settings['copy_info'] ?? ''); ?>" placeholder="© 2026 Your Company. All rights reserved.">
                                </label>
                                <label class="pi-field pi-field-full pi-field-address">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.address')); ?></span>
                                    <textarea name="address" id="address" rows="5" placeholder="<?php echo esc_attr(tpi_admin_ui('page-info.field.address-placeholder')); ?>"><?php echo esc_textarea($page_info_settings['address'] ?? ''); ?></textarea>
                                </label>
                            </div>
                        </div>
                    </section>

                </div>
            </div>

            <!-- 社媒与地图 -->
            <section class="pi-card">
                <header class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('page-info.card.social-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('page-info.card.social-hint')); ?></p>
                    </div>
                    <button type="button" class="button pi-btn-add" id="pi-add-social"><?php echo esc_html(tpi_admin_ui('page-info.button.add-social')); ?></button>
                </header>
                <div class="pi-card-body">
                    <ul class="pi-social-list" id="pi-social-list">
                        <?php foreach ($media_list_render as $media_item): ?>
                            <li class="pi-social-row">
                                <span class="pi-social-drag" title="<?php echo esc_attr(tpi_admin_ui('page-info.label.drag-sort')); ?>" aria-hidden="true"></span>
                                <span class="pi-social-icon" title="<?php echo esc_attr(tpi_admin_ui('page-info.label.change-icon')); ?>">
                                    <img src="<?php echo esc_url($media_item['icon_url'] ?? ''); ?>" alt="">
                                </span>
                                <input type="hidden" name="media_icon_url[]" value="<?php echo esc_url($media_item['icon_url'] ?? ''); ?>">
                                <label class="pi-social-field">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.name')); ?></span>
                                    <input type="text" name="media_name[]" value="<?php echo esc_attr($media_item['name'] ?? ''); ?>" placeholder="YouTube">
                                </label>
                                <label class="pi-social-field pi-social-field-wide">
                                    <span><?php echo esc_html(tpi_admin_ui('page-info.field.link')); ?></span>
                                    <input type="url" name="media_url[]" value="<?php echo esc_url($media_item['url'] ?? ''); ?>" placeholder="https://www.youtube.com/@example">
                                </label>
                                <div class="pi-social-actions">
                                    <button type="button" class="button pi-social-pick"><?php echo esc_html(tpi_admin_ui('page-info.button.choose-icon')); ?></button>
                                    <button type="button" class="button-link pi-social-del"><?php echo esc_html(tpi_admin_ui('page-info.button.delete')); ?></button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="pi-empty-tip" id="pi-social-empty" <?php echo $media_list_render ? 'hidden' : ''; ?>><?php echo esc_html(tpi_admin_ui('page-info.empty.social')); ?></p>

                    <div class="pi-fields pi-social-map">
                        <label class="pi-field pi-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('page-info.field.map-embed')); ?></span>
                            <input type="text" name="google_map_url" id="google_map_url" value="<?php echo esc_attr($page_info_settings['google_map_url'] ?? ''); ?>" placeholder="https://maps.google.com/maps?q=...&output=embed">
                        </label>
                        <label class="pi-field pi-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('page-info.field.map-search')); ?></span>
                            <input type="text" name="google_map_src" id="google_map_src" value="<?php echo esc_attr($page_info_settings['google_map_src'] ?? ''); ?>" placeholder="https://www.google.com/maps/search/?api=1&query=...">
                        </label>
                        <label class="pi-field">
                            <span><?php echo esc_html(tpi_admin_ui('page-info.field.whatsapp-url')); ?></span>
                            <input type="text" name="whatsapp_url" id="whatsapp_url" value="<?php echo esc_attr($page_info_settings['whatsapp_url'] ?? ''); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('page-info.field.whatsapp-url-placeholder')); ?>">
                        </label>
                        <label class="pi-field">
                            <span><?php echo esc_html(tpi_admin_ui('page-info.field.whatsapp-text')); ?></span>
                            <input type="text" name="whatsapp_text" id="whatsapp_text" value="<?php echo esc_attr($page_info_settings['whatsapp_text'] ?? ''); ?>" placeholder="Hello">
                        </label>
                    </div>
                    <p class="pi-hint"><?php echo esc_html(tpi_admin_ui('page-info.hint.social-map')); ?></p>
                </div>
            </section>

            <!-- 结构化数据 -->
            <section class="pi-card">
                <header class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('page-info.card.schema-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('page-info.card.schema-hint')); ?></p>
                    </div>
                </header>
                <div class="pi-card-body">

                    <!-- 外链认可 -->
                    <div class="pi-subcard">
                        <div class="pi-subhead">
                            <div class="pi-subhead-main">
                                <h3><?php echo esc_html(tpi_admin_ui('page-info.schema.same-as-title')); ?> <code>sameAs</code></h3>
                                <p><?php echo esc_html(tpi_admin_ui('page-info.schema.same-as-hint')); ?></p>
                            </div>
                            <div class="pi-tabs" data-tabs="same_as">
                                <button type="button" class="pi-tab is-active" data-mode="visual"><?php echo esc_html(tpi_admin_ui('page-info.button.visual')); ?></button>
                                <button type="button" class="pi-tab" data-mode="code"><?php echo esc_html(tpi_admin_ui('page-info.button.code')); ?></button>
                            </div>
                        </div>
                        <div class="pi-pane is-active" data-pane="same_as" data-mode="visual">
                            <ul class="pi-link-list" id="pi-link-list"></ul>
                            <p class="pi-empty-tip" id="pi-links-empty"><?php echo esc_html(tpi_admin_ui('page-info.empty.links')); ?></p>
                            <button type="button" class="button" id="pi-add-link"><?php echo esc_html(tpi_admin_ui('page-info.button.add-link')); ?></button>
                        </div>
                        <div class="pi-pane" data-pane="same_as" data-mode="code">
                            <textarea id="pi-same-as-code" class="pi-code pi-code-sm" spellcheck="false" placeholder="https://example.com/company; https://example.com/cert"></textarea>
                            <p class="pi-hint"><?php echo esc_html(tpi_admin_ui('page-info.hint.links-separator-prefix')); ?> <code>;</code> <?php echo esc_html(tpi_admin_ui('page-info.hint.links-separator-suffix')); ?></p>
                        </div>
                        <input type="hidden" name="same_as" id="pi-same-as" value="<?php echo esc_attr($same_as_raw); ?>">
                    </div>

                    <!-- 证书 JSON -->
                    <div class="pi-subcard">
                        <div class="pi-subhead">
                            <div class="pi-subhead-main">
                                <h3><?php echo esc_html(tpi_admin_ui('page-info.schema.cert-title')); ?> <code>hasCredential</code></h3>
                                <p><?php echo esc_html(tpi_admin_ui('page-info.schema.cert-hint')); ?></p>
                            </div>
                            <div class="pi-tabs" data-tabs="cert">
                                <button type="button" class="pi-tab is-active" data-mode="visual"><?php echo esc_html(tpi_admin_ui('page-info.button.visual')); ?></button>
                                <button type="button" class="pi-tab" data-mode="code"><?php echo esc_html(tpi_admin_ui('page-info.button.code')); ?></button>
                            </div>
                        </div>
                        <div class="pi-pane is-active" data-pane="cert" data-mode="visual">
                            <div id="pi-cert-editor" class="pi-cert-editor"></div>
                        </div>
                        <div class="pi-pane" data-pane="cert" data-mode="code">
                            <textarea id="pi-cert-code" class="pi-code" spellcheck="false"></textarea>
                            <div class="pi-code-bar">
                                <button type="button" class="button" id="pi-cert-format"><?php echo esc_html(tpi_admin_ui('page-info.button.format')); ?></button>
                                <button type="button" class="button" id="pi-cert-reload"><?php echo esc_html(tpi_admin_ui('page-info.button.reset-to-saved')); ?></button>
                                <span class="pi-code-status" id="pi-cert-status"></span>
                            </div>
                        </div>
                        <textarea name="certificates_json" id="pi-cert-json" hidden><?php echo esc_textarea($cert_json_raw); ?></textarea>
                    </div>

                </div>
            </section>

            <div class="pi-savebar">
                <span class="pi-savebar-hint"><?php echo esc_html(tpi_admin_ui('page-info.hint.savebar')); ?></span>
                <button type="submit" name="page_info_save" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('page-info.button.save')); ?></button>
            </div>
        </form>
    </div>

    <script type="application/json" id="pi-cert-source"><?php echo $cert_json_safe; ?></script>
    <?php
}
