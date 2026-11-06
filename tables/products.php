<?php
// 注册产品
$labels_product = array(
    'name'               => tpi_admin_ui('products.labels.name'),
    'singular_name'      => tpi_admin_ui('products.labels.singular-name'),
    'menu_name'          => tpi_admin_ui('products.labels.menu-name'),
    'name_admin_bar'     => tpi_admin_ui('products.labels.admin-bar'),
    'add_new'            => tpi_admin_ui('products.labels.add-new'),
    'add_new_item'       => tpi_admin_ui('products.labels.add-new-item'),
    'new_item'           => tpi_admin_ui('products.labels.new-item'),
    'edit_item'          => tpi_admin_ui('products.labels.edit-item'),
    'view_item'          => tpi_admin_ui('products.labels.view-item'),
    'all_items'          => tpi_admin_ui('products.labels.all-items'),
    'search_items'       => tpi_admin_ui('products.labels.search-items'),
    'not_found'          => tpi_admin_ui('products.labels.not-found'),
    'not_found_in_trash' => tpi_admin_ui('products.labels.not-found-trash'),
);
$args_product = array(
    'labels'             => $labels_product,
    'public'             => true,
    'has_archive'        => true, // 会生成 /product/ 归档页
    'rewrite'            => array('slug' => 'product'),
    'show_in_rest'       => true, // 开启 Gutenberg & REST API
    'menu_icon'          => 'dashicons-archive', // 产品图标
    'menu_position'      => 20,
    'supports'           => array('title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'),
);
register_post_type('product', $args_product);

// 在产品列表页的行操作里添加“产品信息编辑”
function add_product_info_row_action($actions, $post) {
    if ($post->post_type === 'product') {
        // 自定义链接，可以跳转到你定义的页面
        $url = admin_url('admin.php?page=product-info&post_id=' . $post->ID);

        // 插入到最前面
        $action = array(
            'product_info_edit' => '<a href="' . esc_url($url) . '">' . esc_html(tpi_admin_ui('products.page.title')) . '</a>',
        );

        // 合并到现有 actions
        $actions = $action + $actions;
    }
    return $actions;
}
add_filter('post_row_actions', 'add_product_info_row_action', 10, 2);
// 给后台产品列表的每一行 <tr> 添加自定义 class
function add_product_row_class($classes, $class, $post_id) {
    $post_type = get_post_type($post_id);

    if ($post_type === 'product') {
        $classes[] = 'product-row-item'; // 自定义 class
    }

    return $classes;
}
add_filter('post_class', 'add_product_row_class', 10, 3);

// 产品分类法（语言归属由主题自己的 tpi_lang 处理，不再依赖 Polylang）
function register_product_taxonomy(): void {
    $labels = array(
        'name'              => tpi_admin_ui('products.tax.labels.name'),
        'singular_name'     => tpi_admin_ui('products.tax.labels.singular-name'),
        'search_items'      => tpi_admin_ui('products.tax.labels.search-items'),
        'all_items'         => tpi_admin_ui('products.tax.labels.all-items'),
        'edit_item'         => tpi_admin_ui('products.tax.labels.edit-item'),
        'update_item'       => tpi_admin_ui('products.tax.labels.update-item'),
        'add_new_item'      => tpi_admin_ui('products.tax.labels.add-new-item'),
        'new_item_name'     => tpi_admin_ui('products.tax.labels.new-item-name'),
        'menu_name'         => tpi_admin_ui('products.tax.labels.menu-name'),
    );

    $args = array(
        'hierarchical'      => true, // 类似文章分类（层级）
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => array('slug' => 'product-category'),
        'show_in_rest'      => true
    );

    register_taxonomy('product_cat', array('product'), $args);
}
add_action('init', 'register_product_taxonomy');

// 注册隐藏的“产品信息编辑”后台页面
function register_product_info_submenu(): void {
    add_submenu_page(
        null,                           // 不挂到任何菜单（隐藏）
        tpi_admin_ui('products.menu.page-title'),                  // 页面标题
        tpi_admin_ui('products.menu.menu-title'),                  // 菜单标题（无所谓，因为隐藏了）
        'manage_options',               // 权限
        'product-info',                 // slug（要和 page=product-info 对应）
        'render_product_info_page'      // 回调函数
    );
}
add_action('admin_menu', 'register_product_info_submenu');

// 产品字段直接存 WordPress 原生结构（摘要 = 文章摘要，主图 = 特色图片），无需自定义表
// 只有「产品模板」是独立的可复用素材库，保留单独一张表

function render_product_info_page(): void {
    if ( !is_user_logged_in() || !current_user_can('manage_options') ) {
        wp_redirect( wp_login_url() );
        exit;
    }

    if (!isset($_GET['post_id'])) {
        wp_die(tpi_admin_ui('products.notice.missing-post-id'));
    }

    global $wpdb;
    $post_id = intval($_GET['post_id']);
    $post = get_post($post_id);
    $sort = $post->menu_order;
    $product_template_table = $wpdb->prefix . 'product_template';

    if (!$post || $post->post_type !== 'product') {
        wp_die(tpi_admin_ui('products.notice.invalid-product'));
    }

    // 当前产品数据（摘要 = 文章摘要，主图 = 特色图片，正文 = 文章内容）
    $product = tpi_item_fields($post_id, 'product');
    $images = $product['images'] ?? [];
    $main_title = wp_unslash(wp_kses_post($product['main_title'] ?? ''));
    $summary = wp_unslash(wp_kses_post($product['summary'] ?? ''));
    $description = wp_unslash(wp_kses_post($product['description'] ?? ''));
    $warning = $product['warning'] ?? '';
    $parameters = $product['parameters'] ?? [];
    $content = $product['content'] ?? '';

    // 所有模板数据
    $templates = $wpdb->get_results("SELECT * FROM $product_template_table ORDER BY id DESC", ARRAY_A);

    $notices = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('save_product_info_' . $post_id);

        $sort = $_POST['sort'] ?? 0;
        $content = isset($_POST['product_editor']) ? wp_unslash($_POST['product_editor']) : '';
        $images = [];
        if (!empty($_POST['img_src']) && is_array($_POST['img_src'])) {
            foreach ($_POST['img_src'] as $index => $img_src) {
                // 入库只存上传目录相对路径（2025/10/a.jpg），不写域名；
                // 产品图集和产品模板共用这份数据，两处都不能带绝对路径。
                $img_src = tpi_media_store(sanitize_text_field($img_src));
                $iframe_src = sanitize_text_field($_POST['iframe_src'][$index] ?? "");
                // 老数据里 type 被 esc_url() 补成 http://image，这里还原并归一
                $type = preg_replace('#^https?://#i', '', sanitize_text_field($_POST['type'][$index] ?? ''));
                $type = $type === 'video' ? 'video' : 'image';
                $video_src = tpi_media_store(sanitize_text_field($_POST['video_src'][$index] ?? ""));
                $image_alt = sanitize_text_field($_POST['image_alt'][$index] ?? "");
                $images[] = ['img_src' => $img_src, 'iframe_src' => $iframe_src, 'video_src' => $video_src, 'image_alt' => wp_unslash(wp_kses_post($image_alt)), 'type' => $type];
            }
        }
        $main_title = isset($_POST['main_title']) ? sanitize_textarea_field(wp_unslash($_POST['main_title'])) : '';
        $summary = isset($_POST['summary']) ? sanitize_textarea_field(wp_unslash($_POST['summary'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $warning = isset($_POST['warning']) ? sanitize_textarea_field(wp_unslash($_POST['warning'])) : '';
        $post_title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        $parameters = [];
        if (!empty($_POST['param_key']) && is_array($_POST['param_key'])) {
            foreach ($_POST['param_key'] as $index => $key) {
                $key = sanitize_text_field($key);
                $value = isset($_POST['param_value'][$index]) ? sanitize_text_field($_POST['param_value'][$index]) : '';
                if ($key !== '' && $value !== '') {
                    $parameters[] = ['key' => wp_unslash(wp_kses_post($key)), 'value' => wp_unslash(wp_kses_post($value))];
                }
            }
        }
        // 保存当前产品信息（全部写进 WordPress 原生结构）
        if (isset($_POST['product_save'])) {
            // 主图取图集里的第一张静态图；没有就保留原特色图片
            $first_image = '';
            foreach ($images as $img) {
                if (!empty($img['img_src']) && empty($img['iframe_src']) && empty($img['video_src'])) {
                    $first_image = (string) $img['img_src'];
                    break;
                }
            }
            $save_data = [
                'post_title'  => $post_title,
                'sort'        => $sort,
                'main_title'  => $main_title,
                'summary'     => $summary,
                'description' => $description,
                'warning'     => $warning,
                'parameters'  => $parameters,
                'images'      => $images,
                'content'     => $content,
            ];
            if ($first_image !== '') {
                $save_data['image'] = $first_image;
            }
            tpi_save_item_fields($post_id, 'product', $save_data);
            $warning = wp_unslash(wp_kses_post($warning));
            $post = get_post($post_id);
            $notices[] = ['type' => 'ok', 'text' => tpi_admin_ui('products.notice.saved')];
        }

        if (isset($_POST['product_template_save'])) {
            $template_name = sanitize_text_field($_POST['template_name'] ?? '');
            if ($template_name !== '') {
                $inserted = $wpdb->insert(
                    $product_template_table,
                    [
                        'images' => json_encode($images),
                        'main_title' => $main_title,
                        'summary' => $summary,
                        'description' => $description,
                        'warning' => $warning,
                        'parameters' => json_encode($parameters),
                        // 正文里本站绝对地址 → 相对，模板换站也不用改数据
                        'content' => tpi_media_html_store($content),
                        'template_name' => $template_name
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
                );

                if ($inserted !== false) {
                    $notices[] = ['type' => 'ok', 'text' => tpi_admin_ui('products.notice.template-saved')];
                } else {
                    // 获取最后一次数据库错误
                    $error = $wpdb->last_error;
                    $notices[] = ['type' => 'error', 'text' => tpi_admin_ui('products.notice.template-save-failed') . $error];
                }
            } else {
                $notices[] = ['type' => 'error', 'text' => tpi_admin_ui('products.notice.template-name-empty')];
            }
        }

        // 删除模板
        if (isset($_POST['product_template_delete'])) {
            $template_id = intval($_POST['template_id'] ?? 0);
            if ($template_id > 0) {
                $deleted = $wpdb->delete(
                    $product_template_table,
                    ['id' => $template_id],
                    ['%d']
                );

                if ($deleted !== false && $deleted > 0) {
                    $notices[] = ['type' => 'ok', 'text' => tpi_admin_ui('products.notice.template-deleted')];
                } else if ($deleted === 0) {
                    $notices[] = ['type' => 'error', 'text' => tpi_admin_ui('products.notice.template-not-found')];
                } else {
                    $error = $wpdb->last_error;
                    $notices[] = ['type' => 'error', 'text' => tpi_admin_ui('products.notice.template-delete-failed') . $error];
                }
            } else {
                $notices[] = ['type' => 'error', 'text' => tpi_admin_ui('products.notice.template-select-required')];
            }
        }
    }
    ?>
    <?php
    // 图片 / 参数拖拽排序依赖 jQuery UI sortable（WP 自带）
    wp_enqueue_script('jquery-ui-sortable');
    $ctx = tpi_info_context($post_id, 'product-info', admin_url('edit.php?post_type=product'));
    ?>
    <div class="products-wrap pi-wrap">
        <?php tpi_info_head(tpi_admin_ui('products.page.title'), $ctx, tpi_admin_ui('products.page.view'), tpi_admin_ui('products.page.back')); ?>
        <?php tpi_info_notices($notices); ?>

        <form method="post">
            <?php wp_nonce_field('save_product_info_' . $post_id); ?>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('products.template.title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('products.template.hint')); ?></p>
                    </div>
                </div>
                <div class="pi-card-body">
                    <div class="pit-tpl-row">
                        <label class="pi-field">
                            <span><?php echo esc_html(tpi_admin_ui('products.template.select')); ?></span>
                            <select name="template_id" id="template-select">
                                <option value=""><?php echo esc_html(tpi_admin_ui('products.template.placeholder')); ?></option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option
                                            value="<?php echo esc_attr($tpl['id']); ?>"
                                            data-images='<?php echo esc_attr(tpi_media_full_deep((string) $tpl['images'])); ?>'
                                            data-main_title="<?php echo esc_attr($tpl['main_title']); ?>"
                                            data-summary="<?php echo esc_attr($tpl['summary']); ?>"
                                            data-description="<?php echo esc_attr($tpl['description']); ?>"
                                            data-warning="<?php echo esc_attr($tpl['warning']); ?>"
                                            data-parameters='<?php echo $tpl['parameters']; ?>'
                                            data-content="<?php echo esc_attr(tpi_media_html_full((string) $tpl['content'])); ?>"
                                    >
                                        <?php echo esc_html($tpl['template_name'] ?? sprintf(tpi_admin_ui('products.template.id-label'), $tpl['id'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="pi-field">
                            <span><?php echo esc_html(tpi_admin_ui('products.template.name-label')); ?></span>
                            <input type="text" class="template-name" name="template_name" placeholder="<?php echo esc_attr(tpi_admin_ui('products.template.name-placeholder')); ?>" autocomplete="off">
                        </label>
                        <div class="pit-tpl-actions">
                            <button type="submit" class="button button-primary save-template-btn" name="product_template_save"><?php echo esc_html(tpi_admin_ui('products.button.template-save')); ?></button>
                            <button type="submit" class="button button-primary delete-template-btn" name="product_template_delete"><?php echo esc_html(tpi_admin_ui('products.button.template-delete')); ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pi-cols">
                <div class="pi-col">
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('products.card.basic-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('products.card.basic-hint')); ?></p>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field pi-field-full">
                                    <span><?php echo esc_html(tpi_admin_ui('products.field.title')); ?></span>
                                    <input type="text" name="post_title" value="<?php echo esc_attr($post->post_title); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('products.field.title-placeholder')); ?>">
                                </label>
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('products.field.sort')); ?></span>
                                    <input name="sort" type="number" class="product-sort" value="<?php echo esc_attr($sort); ?>">
                                </label>
                                <label class="pi-field pi-field-full">
                                    <span><?php echo esc_html(tpi_admin_ui('products.field.main-title')); ?></span>
                                    <textarea name="main_title" rows="3" placeholder="<?php echo esc_attr(tpi_admin_ui('products.field.main-title-placeholder')); ?>"><?php echo html_entity_decode(esc_textarea($main_title)); ?></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="pi-col">
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('products.card.summary-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('products.card.summary-hint')); ?></p>
                            </div>
                            <div class="pi-card-actions">
                                <span class="pi-count" id="product-summary-counter">0 <?php echo esc_html(tpi_admin_ui('products.counter.char-unit')); ?></span>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field pi-field-full">
                                    <textarea name="summary" id="product_summary" rows="4" placeholder="<?php echo esc_attr(tpi_admin_ui('products.field.summary-placeholder')); ?>"><?php echo html_entity_decode(esc_textarea($summary)); ?></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('products.card.images-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('products.card.images-hint')); ?></p>
                    </div>
                    <div class="pi-card-actions">
                        <span class="pi-count" id="product-image-count">0 <?php echo esc_html(tpi_admin_ui('products.counter.image-unit')); ?></span>
                        <button type="button" id="add-image" class="button add-image-button"><?php echo esc_html(tpi_admin_ui('products.button.add-image')); ?></button>
                    </div>
                </div>
                <div class="pi-card-body">
                    <ul id="product-images-list" class="pit-media-grid">
                        <?php if (!empty($images)) foreach ($images as $img) :
                            // 库里是上传目录相对路径，后台预览/表单要补全成绝对地址
                            $img_src_raw = (string) ($img['img_src'] ?? '');
                            $img_src_view = tpi_media_url($img_src_raw) ?: $img_src_raw;
                            $video_raw = (string) ($img['video_src'] ?? '');
                            $video_view = $video_raw !== '' ? (tpi_media_url($video_raw) ?: $video_raw) : '';
                            $img_type = (isset($img['type']) && $img['type'] === 'video') ? 'video' : 'image';
                        ?>
                            <li class="image-item">
                                <div class="pit-img-head">
                                    <span class="pit-drag" title="<?php echo esc_attr(tpi_admin_ui('products.media.drag-hint')); ?>"></span>
                                    <span class="pit-img-idx"></span>
                                    <span class="pit-img-type"><?php echo $img_type === 'video' ? tpi_admin_ui('products.media.video') : tpi_admin_ui('products.media.image'); ?></span>
                                    <span class="pit-img-primary"><?php echo esc_html(tpi_admin_ui('products.media.primary-badge')); ?></span>
                                    <button type="button" class="remove-image" title="<?php echo esc_attr(tpi_admin_ui('products.media.remove-title')); ?>"><?php echo esc_html(tpi_admin_ui('products.button.delete')); ?></button>
                                </div>
                                <div class="item-img">
                                    <?php if ($img_type === 'video') : ?>
                                        <video src="<?php echo esc_url($img_src_view); ?>" class="product-image" autoplay loop preload="metadata" muted></video>
                                    <?php else : ?>
                                        <img src="<?php echo esc_url($img_src_view); ?>" class="product-image" alt="">
                                    <?php endif; ?>
                                    <input type="hidden" name="img_src[]" value="<?php echo esc_attr($img_src_view); ?>">
                                    <input type="hidden" name="type[]" value="<?php echo esc_attr($img_type); ?>">
                                    <span class="edit-overlay"><?php echo esc_html(tpi_admin_ui('products.media.edit-overlay')); ?></span>
                                </div>
                                <div class="pit-img-fields">
                                    <label class="pit-img-field">
                                        <span><?php echo esc_html(tpi_admin_ui('products.field.image-alt')); ?></span>
                                        <input type="text" name="image_alt[]" value="<?php echo esc_attr(empty($img['image_alt']) ? get_image_alt($img_src_view) : $img['image_alt']); ?>">
                                    </label>
                                    <label class="pit-img-field">
                                        <span><?php echo esc_html(tpi_admin_ui('products.field.video-src')); ?></span>
                                        <input type="text" name="video_src[]" value="<?php echo esc_attr($video_view); ?>">
                                    </label>
                                    <label class="pit-img-field">
                                        <span><?php echo esc_html(tpi_admin_ui('products.field.iframe-src')); ?></span>
                                        <input type="text" name="iframe_src[]" value="<?php echo esc_attr($img['iframe_src'] ?? ''); ?>">
                                    </label>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="pi-empty-tip" id="product-images-empty" <?php echo empty($images) ? '' : 'hidden'; ?>><?php echo esc_html(tpi_admin_ui('products.media.empty')); ?></p>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('products.card.desc-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('products.card.desc-hint')); ?></p>
                    </div>
                </div>
                <div class="pi-card-body">
                    <div class="pi-fields">
                        <label class="pi-field pi-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('products.field.description')); ?></span>
                            <textarea name="description" rows="5"><?php echo html_entity_decode(esc_textarea($description)); ?></textarea>
                        </label>
                        <label class="pi-field pi-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('products.field.warning')); ?></span>
                            <textarea name="warning" rows="3" placeholder="<?php echo esc_attr(tpi_admin_ui('products.field.warning-placeholder')); ?>"><?php echo esc_textarea($warning); ?></textarea>
                        </label>
                    </div>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('products.card.params-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('products.card.params-hint')); ?></p>
                    </div>
                    <div class="pi-card-actions">
                        <span class="pi-count" id="product-param-count">0 <?php echo esc_html(tpi_admin_ui('products.counter.param-unit')); ?></span>
                        <button type="button" id="param-json-toggle" class="button param-json-toggle" aria-expanded="false"><?php echo esc_html(tpi_admin_ui('products.button.json-toggle')); ?></button>
                        <button type="button" id="add-param" class="button add-param-button"><?php echo esc_html(tpi_admin_ui('products.button.add-param')); ?></button>
                    </div>
                </div>
                <div class="pi-card-body">
                    <div id="product-parameters" class="pit-param-list">
                        <?php if (!empty($parameters)) foreach ($parameters as $param) : ?>
                            <div class="param-item pit-param-row">
                                <span class="pit-drag" title="<?php echo esc_attr(tpi_admin_ui('products.params.drag-hint')); ?>"></span>
                                <span class="pit-param-idx"></span>
                                <input type="text" name="param_key[]" value="<?php echo esc_attr($param['key']); ?>" class="param-key" placeholder="<?php echo esc_attr(tpi_admin_ui('products.field.param-key')); ?>">
                                <input type="text" name="param_value[]" value="<?php echo esc_attr($param['value']); ?>" class="param-value" placeholder="<?php echo esc_attr(tpi_admin_ui('products.field.param-value')); ?>">
                                <button type="button" class="button-link remove-param"><?php echo esc_html(tpi_admin_ui('products.button.delete')); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="pi-empty-tip" id="product-params-empty" <?php echo empty($parameters) ? '' : 'hidden'; ?>><?php echo esc_html(tpi_admin_ui('products.params.empty')); ?></p>

                    <div class="pit-json" id="param-json-panel" hidden>
                        <div class="pit-json-head">
                            <span class="pit-json-hint"><?php echo esc_html(tpi_admin_ui('products.params.json-hint')); ?><code>[{"key":"参数名","value":"参数值"}]</code></span>
                            <span class="pit-json-msg" id="param-json-msg"></span>
                        </div>
                        <textarea id="param-json-input" class="pit-json-input" rows="12" spellcheck="false" placeholder="<?php echo esc_attr(tpi_admin_ui('products.params.json-placeholder')); ?>"></textarea>
                        <div class="pit-json-foot">
                            <button type="button" class="button" id="param-json-format"><?php echo esc_html(tpi_admin_ui('products.button.json-format')); ?></button>
                            <button type="button" class="button button-primary" id="param-json-apply"><?php echo esc_html(tpi_admin_ui('products.button.json-apply')); ?></button>
                            <button type="button" class="button-link" id="param-json-cancel"><?php echo esc_html(tpi_admin_ui('products.button.json-cancel')); ?></button>
                            <span class="pit-json-tip"><?php echo esc_html(tpi_admin_ui('products.params.json-tip')); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('products.card.content-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('products.card.content-hint')); ?></p>
                    </div>
                </div>
                <div class="pi-card-body pi-card-body-editor">
                    <?php
                    wp_editor(
                        $content,
                        'product_editor',
                        [
                            'textarea_name' => 'product_editor',
                            'default_editor' => 'tinymce',
                            'media_buttons' => true,
                            'teeny'         => false,
                            'textarea_rows' => 15,
                            'editor_height' => 500,
                            'tinymce' => [
                                'toolbar1' => 'formatselect,fontselect,fontsizeselect,bold,italic,underline,strikethrough,forecolor,backcolor,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,outdent,indent,blockquote,hr,table,link,unlink,image,media,code',
                                'toolbar2' => 'undo,redo,pastetext,removeformat,subscript,superscript,charmap,fullscreen,anchor,ltr,rtl,wp_help',
                                'wpautop' => false
                            ],
                        ]
                    );
                    ?>
                </div>
            </div>

            <div class="pi-savebar">
                <span class="pi-savebar-hint"><?php echo esc_html(tpi_admin_ui('products.savebar.hint')); ?></span>
                <button type="submit" class="button button-primary button-large" name="product_save"><?php echo esc_html(tpi_admin_ui('products.button.save')); ?></button>
            </div>
        </form>
    </div>
    <script>
        /* 后台界面文案统一从 languages/admin-system-config-{zh|en}.json 取，改文案不用动 JS */
        const osieraI18n = <?php echo wp_json_encode([
            'dragSort'      => tpi_admin_ui('products.media.drag-hint'),
            'video'         => tpi_admin_ui('products.media.video'),
            'image'         => tpi_admin_ui('products.media.image'),
            'primary'       => tpi_admin_ui('products.media.primary-badge'),
            'removeMedia'   => tpi_admin_ui('products.media.remove-title'),
            'editOverlay'   => tpi_admin_ui('products.media.edit-overlay'),
            'alt'           => tpi_admin_ui('products.field.image-alt'),
            'videoSrc'      => tpi_admin_ui('products.field.video-src'),
            'iframeSrc'     => tpi_admin_ui('products.field.iframe-src'),
            'paramKey'      => tpi_admin_ui('products.field.param-key'),
            'paramValue'    => tpi_admin_ui('products.field.param-value'),
            'del'           => tpi_admin_ui('products.button.delete'),
            'pickTitle'     => tpi_admin_ui('products.media.pick-title'),
            'pickButton'    => tpi_admin_ui('products.media.pick-button'),
            'imageUnit'     => tpi_admin_ui('products.counter.image-unit'),
            'paramUnit'     => tpi_admin_ui('products.counter.param-unit'),
            'charUnit'      => tpi_admin_ui('products.counter.char-unit'),
            'errSyntax'     => tpi_admin_ui('products.json.error-syntax'),
            'errArray'      => tpi_admin_ui('products.json.error-array'),
            'errShape'      => tpi_admin_ui('products.json.error-shape'),
            'errTypes'      => tpi_admin_ui('products.json.error-types'),
            'applied'       => tpi_admin_ui('products.json.applied'),
            'backToList'    => tpi_admin_ui('products.button.json-close'),
            'jsonEdit'      => tpi_admin_ui('products.button.json-toggle'),
            'okParse'       => tpi_admin_ui('products.json.ok-parse'),
            'okFormat'      => tpi_admin_ui('products.json.ok-format'),
            'confirmDelete' => tpi_admin_ui('products.confirm.delete-template'),
            'needName'      => tpi_admin_ui('products.alert.template-name'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function osieraTr(key, value) {
            return value === undefined ? osieraI18n[key] : String(osieraI18n[key]).replace('%d', value);
        }

        jQuery(document).ready(function ($) {
            let frame;

            function escapeAttr(value) {
                return String(value === undefined || value === null ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            // 图片卡片序号与数量
            function refreshImageMeta() {
                const $items = $('#product-images-list .image-item');
                $items.each(function (i) {
                    $(this).find('.pit-img-idx').text(i + 1);
                    $(this).toggleClass('is-primary', i === 0);
                });
                $('#product-image-count').text($items.length + ' ' + osieraTr('imageUnit'));
                $('#product-images-empty').prop('hidden', $items.length > 0);
            }

            // 参数行序号与数量
            function refreshParamMeta() {
                const $rows = $('#product-parameters .param-item');
                $rows.each(function (i) {
                    $(this).find('.pit-param-idx').text(i + 1);
                });
                $('#product-param-count').text($rows.length + ' ' + osieraTr('paramUnit'));
                $('#product-params-empty').prop('hidden', $rows.length > 0);
            }

            function buildImageItem(img) {
                img = img || {};
                const isVideo = img.type === 'video';
                const media = isVideo
                    ? '<video src="' + escapeAttr(img.img_src) + '" class="product-image" autoplay loop preload="metadata" muted></video>'
                    : '<img src="' + escapeAttr(img.img_src) + '" class="product-image" alt="">';
                return $(
                    '<li class="image-item">' +
                        '<div class="pit-img-head">' +
                            '<span class="pit-drag" title="' + osieraTr('dragSort') + '"></span>' +
                            '<span class="pit-img-idx"></span>' +
                            '<span class="pit-img-type">' + (isVideo ? osieraTr('video') : osieraTr('image')) + '</span>' +
                            '<span class="pit-img-primary">' + osieraTr('primary') + '</span>' +
                            '<button type="button" class="remove-image" title="' + osieraTr('removeMedia') + '">' + osieraTr('del') + '</button>' +
                        '</div>' +
                        '<div class="item-img">' + media +
                            '<input type="hidden" name="img_src[]" value="' + escapeAttr(img.img_src) + '">' +
                            '<input type="hidden" name="type[]" value="' + escapeAttr(img.type) + '">' +
                            '<span class="edit-overlay">' + osieraTr('editOverlay') + '</span>' +
                        '</div>' +
                        '<div class="pit-img-fields">' +
                            '<label class="pit-img-field"><span>' + osieraTr('alt') + '</span><input type="text" name="image_alt[]" value="' + escapeAttr(img.image_alt) + '"></label>' +
                            '<label class="pit-img-field"><span>' + osieraTr('videoSrc') + '</span><input type="text" name="video_src[]" value="' + escapeAttr(img.video_src) + '"></label>' +
                            '<label class="pit-img-field"><span>' + osieraTr('iframeSrc') + '</span><input type="text" name="iframe_src[]" value="' + escapeAttr(img.iframe_src) + '"></label>' +
                        '</div>' +
                    '</li>'
                );
            }

            function buildParamRow(param) {
                param = param || {};
                return $(
                    '<div class="param-item pit-param-row">' +
                        '<span class="pit-drag" title="' + osieraTr('dragSort') + '"></span>' +
                        '<span class="pit-param-idx"></span>' +
                        '<input type="text" name="param_key[]" value="' + escapeAttr(param.key) + '" class="param-key" placeholder="' + osieraTr('paramKey') + '">' +
                        '<input type="text" name="param_value[]" value="' + escapeAttr(param.value) + '" class="param-value" placeholder="' + osieraTr('paramValue') + '">' +
                        '<button type="button" class="button-link remove-param">' + osieraTr('del') + '</button>' +
                    '</div>'
                );
            }

            function openMedia(tag, add) {
                // 替换已有图片时把地址带上，媒体库打开后锁定当前这张
                const currentSrc = $.trim(tag.find('input[name="img_src[]"]').val() || '');
                frame = wp.media({
                    title: osieraTr('pickTitle'),
                    button: { text: osieraTr('pickButton') },
                    multiple: false
                });
                frame.on('select', function () {
                    const attachment = frame.state().get('selection').first().toJSON();
                    const srcTag = attachment.type === 'video'
                        ? $('<video class="product-image" autoplay loop preload="metadata" muted></video>')
                        : $('<img class="product-image" alt="">');
                    srcTag.attr('src', attachment.url);

                    const $thumb = tag.find('.item-img');
                    const $old = $thumb.find('.product-image');
                    if ($old.length) {
                        $old.replaceWith(srcTag);
                    } else {
                        $thumb.prepend(srcTag);
                    }

                    tag.find('input[name="img_src[]"]').val(attachment.url);
                    tag.find('input[name="type[]"]').val(attachment.type);
                    tag.find('input[name="image_alt[]"]').val(attachment.alt || '');
                    tag.find('.pit-img-type').text(attachment.type === 'video' ? osieraTr('video') : osieraTr('image'));

                    if (add) {
                        $('#product-images-list').append(tag);
                        refreshImageMeta();
                    }
                });
                frame.open();
                if (window.tpiMediaPreselect) {
                    window.tpiMediaPreselect(frame, currentSrc);
                }
            }

            $(document).on('click', '.image-item .product-image, .image-item .edit-overlay', function (e) {
                e.preventDefault();
                openMedia($(this).closest('.image-item'), false);
            });

            $(document).on('click', '.remove-image', function (e) {
                e.preventDefault();
                $(this).closest('.image-item').remove();
                refreshImageMeta();
            });

            $('#add-image').on('click', function (e) {
                e.preventDefault();
                openMedia(buildImageItem({}), true);
            });

            $('#add-param').on('click', function (e) {
                e.preventDefault();
                $('#product-parameters').append(buildParamRow({}));
                refreshParamMeta();
                if (!$pPanel.prop('hidden')) { jsonFromList(); }
            });

            $(document).on('click', '.remove-param', function (e) {
                e.preventDefault();
                $(this).closest('.param-item').remove();
                refreshParamMeta();
                if (!$pPanel.prop('hidden')) { jsonFromList(); }
            });

            // 摘要字数统计
            const $summary = $('#product_summary');
            function updateSummaryCount() {
                const n = ($summary.val() || '').trim().length;
                $('#product-summary-counter').text(n + ' ' + osieraTr('charUnit')).toggleClass('is-warn', n > 160);
            }
            $summary.on('input', updateSummaryCount);
            updateSummaryCount();
            refreshImageMeta();
            refreshParamMeta();

            /* ---------- 拖拽排序：产品图片 / 产品参数 ---------- */
            if ($.fn.sortable) {
                $('#product-images-list').sortable({
                    items: '.image-item',
                    handle: '.pit-img-head',
                    cancel: 'button, input, select, textarea',
                    placeholder: 'pit-sort-ph',
                    forcePlaceholderSize: true,
                    tolerance: 'pointer',
                    cursor: 'grabbing',
                    start: function (e, ui) {
                        ui.placeholder.height(ui.item.outerHeight());
                        ui.placeholder.width('auto');
                    },
                    update: refreshImageMeta
                });

                $('#product-parameters').sortable({
                    items: '.param-item',
                    handle: '.pit-drag',
                    placeholder: 'pit-sort-ph pit-sort-ph-row',
                    forcePlaceholderSize: true,
                    tolerance: 'pointer',
                    cursor: 'grabbing',
                    update: refreshParamMeta
                });
            }

            /* ---------- 产品参数 JSON 编辑 ---------- */
            const $pPanel = $('#param-json-panel');
            const $pInput = $('#param-json-input');
            const $pMsg = $('#param-json-msg');
            const $pToggle = $('#param-json-toggle');
            const $pList = $('#product-parameters');

            function jsonMsg(text, type) {
                $pMsg.text(text || '').removeClass('is-ok is-error').addClass(type ? 'is-' + type : '');
            }

            // 当前列表 → 数组，空行（参数名与参数值都为空）不参与
            function collectParams() {
                const list = [];
                $pList.find('.param-item').each(function () {
                    const key = $(this).find('.param-key').val() || '';
                    const value = $(this).find('.param-value').val() || '';
                    if (key.trim() !== '' || value.trim() !== '') {
                        list.push({ key: key, value: value });
                    }
                });
                return list;
            }

            function jsonFromList() {
                $pInput.val(JSON.stringify(collectParams(), null, 2));
                jsonMsg('');
            }

            // 文本 → 数组；兼容 [{key,value}] 与 [["参数名","参数值"]]
            function parseParamJson() {
                const raw = ($pInput.val() || '').trim();
                if (raw === '') return { list: [] };

                let data;
                try {
                    data = JSON.parse(raw);
                } catch (err) {
                    return { error: osieraTr('errSyntax') + err.message };
                }
                if (!Array.isArray(data)) {
                    return { error: osieraTr('errArray') };
                }

                const list = [];
                for (let i = 0; i < data.length; i++) {
                    const item = data[i];
                    if (Array.isArray(item)) {
                        list.push({
                            key: item[0] == null ? '' : String(item[0]),
                            value: item[1] == null ? '' : String(item[1])
                        });
                        continue;
                    }
                    if (!item || typeof item !== 'object') {
                        return { error: osieraTr('errShape', i + 1) };
                    }
                    const key = item.key != null ? item.key : (item.name != null ? item.name : '');
                    const value = item.value != null ? item.value : (item.val != null ? item.val : '');
                    if (typeof key === 'object' || typeof value === 'object') {
                        return { error: osieraTr('errTypes', i + 1) };
                    }
                    if (String(key).trim() === '' && String(value).trim() === '') continue;
                    list.push({ key: String(key), value: String(value) });
                }
                return { list: list };
            }

            // 把 JSON 写回列表行；失败时返回 false 并提示
            function applyParamJson() {
                const result = parseParamJson();
                if (result.error) {
                    jsonMsg(result.error, 'error');
                    $pInput.trigger('focus');
                    return false;
                }
                $pList.empty();
                result.list.forEach(function (row) { $pList.append(buildParamRow(row)); });
                refreshParamMeta();
                jsonMsg(osieraTr('applied', result.list.length), 'ok');
                return true;
            }

            function openJsonPanel() {
                jsonFromList();
                $pPanel.prop('hidden', false);
                $pList.prop('hidden', true);
                $('#product-params-empty').prop('hidden', true);
                $pToggle.text(osieraTr('backToList')).attr('aria-expanded', 'true');
                $pInput.trigger('focus');
                // 打开时回到开头，避免长 JSON 直接停在末尾
                if ($pInput[0] && $pInput[0].setSelectionRange) {
                    $pInput[0].setSelectionRange(0, 0);
                }
                $pInput[0].scrollTop = 0;
            }

            function closeJsonPanel() {
                $pPanel.prop('hidden', true);
                $pList.prop('hidden', false);
                refreshParamMeta();
                $pToggle.text(osieraTr('jsonEdit')).attr('aria-expanded', 'false');
                jsonMsg('');
            }

            $pToggle.on('click', function () {
                if ($pPanel.prop('hidden')) { openJsonPanel(); } else { closeJsonPanel(); }
            });

            $pInput.on('input', function () {
                const result = parseParamJson();
                if (result.error) {
                    jsonMsg(result.error, 'error');
                } else {
                    jsonMsg(osieraTr('okParse', result.list.length), 'ok');
                }
            });

            $('#param-json-format').on('click', function () {
                const result = parseParamJson();
                if (result.error) {
                    jsonMsg(result.error, 'error');
                    return;
                }
                $pInput.val(JSON.stringify(result.list, null, 2));
                jsonMsg(osieraTr('okFormat', result.list.length), 'ok');
            });

            $('#param-json-apply').on('click', applyParamJson);
            $('#param-json-cancel').on('click', closeJsonPanel);

            // JSON 面板展开时提交：先把 JSON 落成参数行，语法错误则拦住提交
            $('.products-wrap form').on('submit', function (e) {
                if (!$pPanel.prop('hidden') && !applyParamJson()) {
                    e.preventDefault();
                }
            });

            // 选择模板插入数据
            const templateSelect = document.getElementById('template-select');
            if (templateSelect) {
                templateSelect.addEventListener('change', function () {
                    const option = this.selectedOptions[0];
                    if (!option.value) return;

                    let images = [], parameters = [];
                    try { images = JSON.parse(option.dataset.images || '[]') || []; } catch (err) { images = []; }
                    try { parameters = JSON.parse(option.dataset.parameters || '[]') || []; } catch (err) { parameters = []; }

                    const $list = $('#product-images-list').empty();
                    images.forEach(function (img) { $list.append(buildImageItem(img)); });
                    refreshImageMeta();

                    const $params = $('#product-parameters').empty();
                    parameters.forEach(function (param) { $params.append(buildParamRow(param)); });
                    refreshParamMeta();
                    if (!$pPanel.prop('hidden')) { jsonFromList(); }

                    $('textarea[name="main_title"]').val(option.dataset.main_title || '');
                    $('textarea[name="summary"]').val(option.dataset.summary || '');
                    $('textarea[name="description"]').val(option.dataset.description || '');
                    $('textarea[name="warning"]').val(option.dataset.warning || '');
                    updateSummaryCount();

                    const content = option.dataset.content || '';
                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('product_editor')) {
                        const editor = tinyMCE.get('product_editor');
                        editor.setContent(content);
                        editor.save();
                    } else {
                        const editorTextarea = document.getElementById('product_editor');
                        if (editorTextarea) editorTextarea.value = content;
                    }
                });
            }

            // 删除模板提示
            const deleteTemplateBtn = document.querySelector('.delete-template-btn');
            if (deleteTemplateBtn) {
                deleteTemplateBtn.addEventListener('click', function (e) {
                    if (!confirm(osieraTr('confirmDelete'))) {
                        e.preventDefault();
                    }
                });
            }

            // 保存模板
            const saveTemplateBtn = document.querySelector('.save-template-btn');
            if (saveTemplateBtn) {
                saveTemplateBtn.addEventListener('click', function (e) {
                    const templateName = document.querySelector('.template-name');
                    if (templateName && !templateName.value.trim()) {
                        e.preventDefault();
                        alert(osieraTr('needName'));
                    }
                });
            }
        });
    </script>
    <?php
}
