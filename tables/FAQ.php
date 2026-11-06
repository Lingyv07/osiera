<?php
// 注册 FAQ 自定义文章类型
$labels_faq = [
    'name'               => 'FAQ',
    'singular_name'      => 'FAQ',
    'menu_name'          => tpi_admin_ui('faq-info.menu-name'),
    'add_new'            => tpi_admin_ui('faq-info.labels.add-new'),
    'add_new_item'       => tpi_admin_ui('faq-info.labels.add-new-item'),
    'edit_item'          => tpi_admin_ui('faq-info.labels.edit-item'),
    'all_items'          => tpi_admin_ui('faq-info.labels.all-items'),
    'search_items'       => tpi_admin_ui('faq-info.labels.search-items'),
    'not_found'          => tpi_admin_ui('faq-info.labels.not-found'),
];
$args_faq = [
    'labels'             => $labels_faq,
    'public'             => true,
    'has_archive'        => true,
    'rewrite'            => ['slug' => 'faq'],
    'show_in_rest'       => true,
    'menu_position'      => 22,
    'menu_icon'          => 'dashicons-editor-help',
    'supports'           => ['title', 'editor', 'custom-fields'],
];
register_post_type('faq', $args_faq);

// 2注册 FAQ 分类法（可选）
function register_faq_taxonomy(): void {
    $labels = [
        'name'              => tpi_admin_ui('faq-info.tax.labels.name'),
        'singular_name'     => tpi_admin_ui('faq-info.tax.labels.singular-name'),
        'search_items'      => tpi_admin_ui('faq-info.tax.labels.search-items'),
        'all_items'         => tpi_admin_ui('faq-info.tax.labels.all-items'),
        'edit_item'         => tpi_admin_ui('faq-info.tax.labels.edit-item'),
        'update_item'       => tpi_admin_ui('faq-info.tax.labels.update-item'),
        'add_new_item'      => tpi_admin_ui('faq-info.tax.labels.add-new-item'),
        'new_item_name'     => tpi_admin_ui('faq-info.tax.labels.new-item-name'),
        'menu_name'         => tpi_admin_ui('faq-info.tax.labels.menu-name'),
    ];

    $args = [
        'hierarchical'      => true,
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'faq-category'],
        'show_in_rest'      => true,
    ];

    register_taxonomy('faq_cat', ['faq'], $args);
}
add_action('init', 'register_faq_taxonomy');

// 语言归属由主题自己的 tpi_lang 处理（已注册 faq），不再依赖 Polylang

// 后台 FAQ 列表添加“FAQ 信息编辑”操作
function add_faq_info_row_action($actions, $post) {
    if ($post->post_type === 'faq') {
        $url = admin_url('admin.php?page=faq-info&post_id=' . $post->ID);
        $action = [
            'faq_info_edit' => '<a href="' . esc_url($url) . '">' . esc_html(tpi_admin_ui('faq-info.page.title')) . '</a>',
        ];
        $actions = $action + $actions;
    }
    return $actions;
}
add_filter('post_row_actions', 'add_faq_info_row_action', 10, 2);

// 给后台列表每行添加自定义 class
function add_faq_row_class($classes, $class, $post_id) {
    if (get_post_type($post_id) === 'faq') {
        $classes[] = 'faq-row-item';
    }
    return $classes;
}
add_filter('post_class', 'add_faq_row_class', 10, 3);

// 注册隐藏的 FAQ 信息编辑页面
function register_faq_info_submenu(): void {
    add_submenu_page(
        null,
        tpi_admin_ui('faq-info.page.title'),
        tpi_admin_ui('faq-info.page.title'),
        'manage_options',
        'faq-info',
        'render_faq_info_page'
    );
}
add_action('admin_menu', 'register_faq_info_submenu');

// FAQ 字段直接存 WordPress 原生结构（正文 = 文章内容），无需自定义表

// 渲染 FAQ 信息编辑页面
function render_faq_info_page(): void {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_redirect(wp_login_url());
        exit;
    }

    if (!isset($_GET['post_id'])) wp_die(tpi_admin_ui('faq-info.error.missing-post-id'));

    global $wpdb;
    $post_id = intval($_GET['post_id']);
    $faq = get_post($post_id);
    $sort = $faq->menu_order;

    if (!$faq || $faq->post_type !== 'faq') wp_die(tpi_admin_ui('faq-info.error.invalid-post'));

    $faq_info = tpi_item_fields($post_id, 'faq');
    $content = $faq_info['content'] ?? '';

    $notices = [];

    // 保存数据
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('save_faq_info_' . $post_id);
        $content = isset($_POST['post_editor']) ? wp_unslash($_POST['post_editor']) : '';
        $sort = $_POST['sort'] ?? 0;
        $post_title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';

        tpi_save_item_fields($post_id, 'faq', [
            'post_title' => $post_title,
            'sort'       => $sort,
            'content'    => $content,
        ]);
        $faq = get_post($post_id);

        $notices[] = ['type' => 'ok', 'text' => tpi_admin_ui('faq-info.notice.saved')];
    }

    ?>
    <?php $ctx = tpi_info_context($post_id, 'faq-info', admin_url('edit.php?post_type=faq')); ?>
    <div class="faq-info-wrap pi-wrap">
        <?php tpi_info_head(tpi_admin_ui('faq-info.page.title'), $ctx, tpi_admin_ui('faq-info.page.view'), tpi_admin_ui('faq-info.page.back')); ?>
        <?php tpi_info_notices($notices); ?>

        <form method="post">
            <?php wp_nonce_field('save_faq_info_' . $post_id); ?>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('faq-info.card.basic-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('faq-info.card.basic-hint')); ?></p>
                    </div>
                </div>
                <div class="pi-card-body">
                    <div class="pi-fields">
                        <label class="pi-field pi-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('faq-info.field.title')); ?></span>
                            <input type="text" name="post_title" value="<?php echo esc_attr($faq->post_title); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('faq-info.field.title-placeholder')); ?>">
                        </label>
                        <label class="pi-field pi-field-full">
                            <span><?php echo esc_html(tpi_admin_ui('faq-info.field.sort')); ?></span>
                            <input name="sort" type="number" class="product-sort" value="<?php echo esc_attr($sort); ?>">
                        </label>
                    </div>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('faq-info.card.content-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('faq-info.card.content-hint')); ?></p>
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
                <span class="pi-savebar-hint"><?php echo esc_html(tpi_admin_ui('faq-info.savebar.hint')); ?></span>
                <button type="submit" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('faq-info.button.save')); ?></button>
            </div>
        </form>
    </div>
    <?php
}
