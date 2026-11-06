<?php
// 注册 Application 自定义文章类型
$labels_application = [
    'name'               => tpi_admin_ui('application-info.labels.name'),
    'singular_name'      => tpi_admin_ui('application-info.labels.singular-name'),
    'menu_name'          => tpi_admin_ui('application-info.menu-name'),
    'add_new'            => tpi_admin_ui('application-info.labels.add-new'),
    'add_new_item'       => tpi_admin_ui('application-info.labels.add-new-item'),
    'edit_item'          => tpi_admin_ui('application-info.labels.edit-item'),
    'all_items'          => tpi_admin_ui('application-info.labels.all-items'),
    'search_items'       => tpi_admin_ui('application-info.labels.search-items'),
    'not_found'          => tpi_admin_ui('application-info.labels.not-found'),
];
$args_application = [
    'labels'             => $labels_application,
    'public'             => true,
    'has_archive'        => true,
    'rewrite'            => ['slug' => 'application'],
    'show_in_rest'       => true,
    'menu_position'      => 21,
    'menu_icon'          => 'dashicons-analytics',
    'supports'           => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
];
register_post_type('application', $args_application);

// 注册 Application 分类法
function register_application_taxonomy(): void {
    $labels = [
        'name'              => tpi_admin_ui('application-info.tax.labels.name'),
        'singular_name'     => tpi_admin_ui('application-info.tax.labels.singular-name'),
        'search_items'      => tpi_admin_ui('application-info.tax.labels.search-items'),
        'all_items'         => tpi_admin_ui('application-info.tax.labels.all-items'),
        'edit_item'         => tpi_admin_ui('application-info.tax.labels.edit-item'),
        'update_item'       => tpi_admin_ui('application-info.tax.labels.update-item'),
        'add_new_item'      => tpi_admin_ui('application-info.tax.labels.add-new-item'),
        'new_item_name'     => tpi_admin_ui('application-info.tax.labels.new-item-name'),
        'menu_name'         => tpi_admin_ui('application-info.tax.labels.menu-name'),
    ];

    $args = [
        'hierarchical'      => true,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'application-category'],
        'show_in_rest'      => true,
    ];

    register_taxonomy('application_cat', ['application'], $args);
}
add_action('init', 'register_application_taxonomy');


// 语言归属由主题自己的 tpi_lang 处理（已注册 application），不再依赖 Polylang

// 后台 Application 列表添加“应用产品信息编辑”操作
function add_application_info_row_action($actions, $post) {
    if ($post->post_type === 'application') {
        $url = admin_url('admin.php?page=application-info&post_id=' . $post->ID);
        $action = [
            'application_info_edit' => '<a href="' . esc_url($url) . '">' . esc_html(tpi_admin_ui('application-info.page.title')) . '</a>',
        ];
        $actions = $action + $actions;
    }
    return $actions;
}
add_filter('post_row_actions', 'add_application_info_row_action', 10, 2);

// 给后台列表每行添加自定义 class
function add_application_row_class($classes, $class, $post_id) {
    if (get_post_type($post_id) === 'application') {
        $classes[] = 'application-row-item';
    }
    return $classes;
}
add_filter('post_class', 'add_application_row_class', 10, 3);

// 注册隐藏的 Application 信息编辑页面
function register_application_info_submenu(): void {
    add_submenu_page(
        null,
        tpi_admin_ui('application-info.page.title'),
        tpi_admin_ui('application-info.page.title'),
        'manage_options',
        'application-info',
        'render_application_info_page'
    );
}
add_action('admin_menu', 'register_application_info_submenu');

// 应用产品字段直接存 WordPress 原生结构（摘要 = 文章摘要，主图 = 特色图片），无需自定义表

// 渲染 Application 信息编辑页面
function render_application_info_page(): void {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_redirect(wp_login_url());
        exit;
    }

    if (!isset($_GET['post_id'])) wp_die(tpi_admin_ui('application-info.error.missing-post-id'));

    global $wpdb;
    $post_id = intval($_GET['post_id']);
    $post = get_post($post_id);
    $sort = $post->menu_order;

    if (!$post || $post->post_type !== 'application') wp_die(tpi_admin_ui('application-info.error.invalid-post'));

    $info = tpi_item_fields($post_id, 'application');
    $image = $info['image'] ?? '';
    $main_title = $info['main_title'] ?? '';
    $description = $info['description'] ?? '';
    $no_description_push = $info['no_description_push'] ?? '';
    $content = $info['content'] ?? '';

    $notices = [];

    // 保存数据
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('save_application_info_' . $post_id);
        $sort = $_POST['sort'] ?? 0;
        $image = isset($_POST['image']) ? esc_url_raw($_POST['image']) : '';
        $main_title = isset($_POST['main_title']) ? sanitize_textarea_field(wp_unslash($_POST['main_title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $post_title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        $no_description_push = $_POST['no_description_push'] ?? '';
        $content = isset($_POST['post_editor']) ? wp_unslash($_POST['post_editor']) : '';

        tpi_save_item_fields($post_id, 'application', [
            'post_title'          => $post_title,
            'sort'                => $sort,
            'image'               => $image,
            'main_title'          => $main_title,
            'description'         => $description,
            'no_description_push' => $no_description_push,
            'content'             => $content,
        ]);
        $post = get_post($post_id);

        $notices[] = ['type' => 'ok', 'text' => tpi_admin_ui('application-info.notice.saved')];
    }
    ?>
    <?php $ctx = tpi_info_context($post_id, 'application-info', admin_url('edit.php?post_type=application')); ?>
    <div class="application-info-wrap pi-wrap">
        <?php tpi_info_head(tpi_admin_ui('application-info.page.title'), $ctx, tpi_admin_ui('application-info.page.view'), tpi_admin_ui('application-info.page.back')); ?>
        <?php tpi_info_notices($notices); ?>

        <form method="post">
            <?php wp_nonce_field('save_application_info_' . $post_id); ?>

            <div class="pi-cols">
                <div class="pi-col">
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('application-info.card.basic-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('application-info.card.basic-hint')); ?></p>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field pi-field-full">
                                    <span><?php echo esc_html(tpi_admin_ui('application-info.field.title')); ?></span>
                                    <input type="text" name="post_title" value="<?php echo esc_attr($post->post_title); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('application-info.field.title-placeholder')); ?>">
                                </label>
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('application-info.field.sort')); ?></span>
                                    <input name="sort" type="number" class="product-sort" value="<?php echo esc_attr($sort); ?>">
                                </label>
                                <label class="pi-field pi-field-full">
                                    <span><?php echo esc_html(tpi_admin_ui('application-info.field.main-title')); ?></span>
                                    <textarea name="main_title" rows="3" placeholder="<?php echo esc_attr(tpi_admin_ui('application-info.field.main-title-placeholder')); ?>"><?php echo esc_textarea($main_title); ?></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('application-info.card.excerpt-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('application-info.card.excerpt-hint')); ?></p>
                            </div>
                            <div class="pi-card-actions">
                                <span class="pi-count" id="app-desc-counter">0 <?php echo esc_html(tpi_admin_ui('application-info.counter.unit')); ?></span>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field pi-field-full">
                                    <textarea name="description" id="app_description" rows="4" placeholder="<?php echo esc_attr(tpi_admin_ui('application-info.field.excerpt-placeholder')); ?>"><?php echo esc_textarea($description); ?></textarea>
                                </label>
                            </div>
                            <label class="pi-switch">
                                <input type="checkbox" name="no_description_push" value="1" <?php checked($no_description_push, 1); ?>>
                                <span class="pi-switch-track" aria-hidden="true"></span>
                                <span class="pi-switch-text">
                                    <strong><?php echo esc_html(tpi_admin_ui('application-info.switch.no-description')); ?></strong>
                                    <em><?php echo esc_html(tpi_admin_ui('application-info.switch.no-description-hint')); ?></em>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="pi-col">
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('application-info.card.image-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('application-info.card.image-hint')); ?></p>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-thumb pi-thumb-hero<?php echo $image ? '' : ' is-empty'; ?>" id="app-image-thumb">
                                <img id="post_image_preview" src="<?php echo esc_url($image); ?>" alt="">
                            </div>
                            <input type="hidden" name="image" id="post_image" value="<?php echo esc_url($image); ?>">
                            <div class="pi-media-actions">
                                <button type="button" class="button button-primary" id="upload_post_image"><?php echo esc_html(tpi_admin_ui('application-info.button.pick-image')); ?></button>
                                <button type="button" class="button-link" id="remove_post_image"><?php echo esc_html(tpi_admin_ui('application-info.button.remove-image')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('application-info.card.content-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('application-info.card.content-hint')); ?></p>
                    </div>
                </div>
                <div class="pi-card-body pi-card-body-editor">
                    <?php
                    wp_editor(
                        $content,
                        'post_editor',
                        [
                            'textarea_name' => 'post_editor',
                            'default_editor' => 'tinymce',
                            'media_buttons' => true,
                            'teeny'         => false,
                            'textarea_rows' => 15,
                            'editor_height' => 400,
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
                <span class="pi-savebar-hint"><?php echo esc_html(tpi_admin_ui('application-info.savebar.hint')); ?></span>
                <button type="submit" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('application-info.button.save')); ?></button>
            </div>
        </form>
    </div>
    <script>
        jQuery(document).ready(function ($) {
            let frame;

            function syncThumb(url) {
                $('#post_image').val(url || '');
                $('#post_image_preview').attr('src', url || '');
                $('#app-image-thumb').toggleClass('is-empty', !url);
            }

            $('#upload_post_image').on('click', function (e) {
                e.preventDefault();
                // 换图时先锁定当前这张（媒体库按 URL 反查附件）
                const current = ($('#post_image').val() || '').trim();
                if (frame) {
                    frame.open();
                    if (window.tpiMediaPreselect) { window.tpiMediaPreselect(frame, current); }
                    return;
                }
                frame = wp.media({
                    title: '<?php echo esc_js(tpi_admin_ui('application-info.media.title')); ?>',
                    button: { text: '<?php echo esc_js(tpi_admin_ui('application-info.media.button')); ?>' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function () {
                    const attachment = frame.state().get('selection').first().toJSON();
                    syncThumb(attachment.url);
                });
                frame.open();
                if (window.tpiMediaPreselect) { window.tpiMediaPreselect(frame, current); }
            });

            $('#remove_post_image').on('click', function (e) {
                e.preventDefault();
                syncThumb('');
            });

            // 摘要字数统计
            const $desc = $('#app_description');
            function updateCount() {
                const n = ($desc.val() || '').trim().length;
                $('#app-desc-counter').text(n + ' <?php echo esc_js(tpi_admin_ui('application-info.counter.unit')); ?>').toggleClass('is-warn', n > 160);
            }
            $desc.on('input', updateCount);
            updateCount();
        });
    </script>
    <?php
}
