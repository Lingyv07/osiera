<?php
// 在文章列表页的行操作里添加“文章信息编辑”
function add_post_info_row_action($actions, $post) {
    if ($post->post_type === 'post') {
        $url = admin_url('admin.php?page=post-info&post_id=' . $post->ID);
        $action = array(
            'post_info_edit' => '<a href="' . esc_url($url) . '">' . esc_html(tpi_admin_ui('news.page.title')) . '</a>',
        );
        $actions = $action + $actions;
    }
    return $actions;
}
add_filter('post_row_actions', 'add_post_info_row_action', 10, 2);

// 注册隐藏的“文章信息编辑”后台页面
function register_post_info_submenu(): void {
    add_submenu_page(
        null,
        tpi_admin_ui('news.page.title'),
        tpi_admin_ui('news.page.title'),
        'manage_options',
        'post-info',
        'render_post_info_page'
    );
}
add_action('admin_menu', 'register_post_info_submenu');

// 给文章列表添加自定义 class
function add_new_row_class($classes, $class, $post_id) {
    $post_type = get_post_type($post_id);

    if ($post_type === 'post') {
        $classes[] = 'new-row-item'; // 自定义 class
    }

    return $classes;
}
add_filter('post_class', 'add_new_row_class', 10, 3);

// 文章字段直接存 WordPress 原生结构（摘要 = 文章摘要，主图 = 特色图片），无需自定义表

// 渲染文章信息编辑页面
function render_post_info_page(): void {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_redirect(wp_login_url());
        exit;
    }

    if (!isset($_GET['post_id'])) {
        wp_die(esc_html(tpi_admin_ui('news.error.missing-post-id')));
    }

    global $wpdb;
    $post_id = intval($_GET['post_id']);
    $post = get_post($post_id);
    $sort = $post->menu_order;

    if (!$post || $post->post_type !== 'post') {
        wp_die(esc_html(tpi_admin_ui('news.error.invalid-post')));
    }

    // 当前文章数据（摘要 = post_excerpt，主图 = 特色图片，正文 = post_content）
    $post_info = tpi_item_fields($post_id, 'post');
    $image = $post_info['image'] ?? '';
    $main_title = $post_info['main_title'] ?? '';
    $article_type = $post_info['article_type'] ?? '';
    $description = $post_info['description'] ?? '';
    // 默认开启：摘要只用于列表展示，不拼进正文；存 '0' 才算关掉
    $no_description_push = ($post_info['no_description_push'] ?? '1') === '0' ? '0' : '1';
    $content = $post_info['content'] ?? '';

    $notices = [];

    // 保存数据
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('save_post_info_' . $post_id);
        $sort = $_POST['sort'] ?? 0;
        $image = isset($_POST['image']) ? esc_url_raw($_POST['image']) : '';
        $post_title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        $main_title = isset($_POST['main_title']) ? sanitize_textarea_field(wp_unslash($_POST['main_title'])) : '';
        $article_type = isset($_POST['article_type']) ? sanitize_text_field(wp_unslash($_POST['article_type'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $no_description_push = (isset($_POST['no_description_push']) && $_POST['no_description_push'] === '0') ? '0' : '1';
        $content = isset($_POST['post_editor']) ? wp_unslash($_POST['post_editor']) : '';

        tpi_save_item_fields($post_id, 'post', [
            'post_title'          => $post_title,
            'sort'                => $sort,
            'image'               => $image,
            'main_title'          => $main_title,
            'article_type'        => $article_type,
            'description'         => $description,
            'no_description_push' => $no_description_push,
            'content'             => $content,
        ]);
        $post = get_post($post_id);
        $notices[] = ['type' => 'ok', 'text' => tpi_admin_ui('news.notice.saved')];
    }

    $ctx = tpi_info_context($post_id, 'post-info', admin_url('edit.php'));
    ?>
    <div class="post-info-wrap pi-wrap">
        <?php tpi_info_head(tpi_admin_ui('news.page.title'), $ctx, tpi_admin_ui('news.page.view'), tpi_admin_ui('news.page.back')); ?>
        <?php tpi_info_notices($notices); ?>

        <form method="post">
            <?php wp_nonce_field('save_post_info_' . $post_id); ?>

            <div class="pi-cols">
                <div class="pi-col">
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('news.card.basic-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('news.card.basic-hint')); ?></p>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field pi-field-full">
                                    <span><?php echo esc_html(tpi_admin_ui('news.field.title')); ?></span>
                                    <input type="text" name="post_title" value="<?php echo esc_attr($post->post_title); ?>" placeholder="<?php echo esc_attr(tpi_admin_ui('news.field.title-placeholder')); ?>">
                                </label>
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('news.field.sort')); ?></span>
                                    <input type="number" name="sort" class="product-sort" value="<?php echo esc_attr($sort); ?>">
                                </label>
                                <label class="pi-field">
                                    <span><?php echo esc_html(tpi_admin_ui('news.field.article-type')); ?></span>
                                    <select name="article_type" id="article_type">
                                        <option value="Article" <?php selected($article_type, 'Article'); ?>><?php echo esc_html(tpi_admin_ui('news.option.article')); ?></option>
                                        <option value="NewsArticle" <?php selected($article_type, 'NewsArticle'); ?>><?php echo esc_html(tpi_admin_ui('news.option.news-article')); ?></option>
                                    </select>
                                </label>
                                <label class="pi-field pi-field-full">
                                    <span><?php echo esc_html(tpi_admin_ui('news.field.main-title')); ?></span>
                                    <textarea name="main_title" rows="3" placeholder="<?php echo esc_attr(tpi_admin_ui('news.field.main-title-placeholder')); ?>"><?php echo esc_textarea($main_title); ?></textarea>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('news.card.summary-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('news.card.summary-hint')); ?></p>
                            </div>
                            <div class="pi-card-actions">
                                <span class="pi-count" id="desc-counter">0 <?php echo esc_html(tpi_admin_ui('news.counter.unit')); ?></span>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-fields">
                                <label class="pi-field pi-field-full">
                                    <textarea name="description" id="post_description" rows="4" placeholder="<?php echo esc_attr(tpi_admin_ui('news.field.summary-placeholder')); ?>"><?php echo esc_textarea($description); ?></textarea>
                                </label>
                            </div>
                            <label class="pi-switch">
                                <input type="hidden" name="no_description_push" value="0">
                                <input type="checkbox" name="no_description_push" value="1" <?php checked($no_description_push, '1'); ?>>
                                <span class="pi-switch-track" aria-hidden="true"></span>
                                <span class="pi-switch-text">
                                    <strong><?php echo esc_html(tpi_admin_ui('news.switch.no-description')); ?></strong>
                                    <em><?php echo esc_html(tpi_admin_ui('news.switch.no-description-hint')); ?></em>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="pi-col">
                    <div class="pi-card">
                        <div class="pi-card-head">
                            <div class="pi-card-head-main">
                                <h2><?php echo esc_html(tpi_admin_ui('news.card.image-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('news.card.image-hint')); ?></p>
                            </div>
                        </div>
                        <div class="pi-card-body">
                            <div class="pi-thumb pi-thumb-hero<?php echo $image ? '' : ' is-empty'; ?>" id="post-image-thumb">
                                <img id="post_image_preview" src="<?php echo esc_url($image); ?>" alt="">
                            </div>
                            <input type="hidden" name="image" id="post_image" value="<?php echo esc_url($image); ?>">
                            <div class="pi-media-actions">
                                <button type="button" class="button button-primary" id="upload_post_image"><?php echo esc_html(tpi_admin_ui('news.button.pick-image')); ?></button>
                                <button type="button" class="button-link" id="remove_post_image"><?php echo esc_html(tpi_admin_ui('news.button.remove-image')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pi-card">
                <div class="pi-card-head">
                    <div class="pi-card-head-main">
                        <h2><?php echo esc_html(tpi_admin_ui('news.card.content-title')); ?></h2>
                        <p><?php echo esc_html(tpi_admin_ui('news.card.content-hint')); ?></p>
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
                            'teeny'         => true,
                            'textarea_rows' => 15,
                            'editor_height' => 420,
                            'tinymce' => [
                                'toolbar1' => 'formatselect,fontselect,fontsizeselect,bold,italic,underline,strikethrough,forecolor,backcolor,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,outdent,indent,blockquote,hr,table,link,unlink,image,media,code',
                                'toolbar2' => 'undo,redo,pastetext,removeformat,subscript,superscript,charmap,fullscreen,anchor,ltr,rtl,wp_help',
                                'wpautop' => true
                            ],
                        ]
                    );
                    ?>
                </div>
            </div>

            <div class="pi-savebar">
                <span class="pi-savebar-hint"><?php echo esc_html(tpi_admin_ui('news.savebar.hint')); ?></span>
                <button type="submit" class="button button-primary button-large" name="post_save"><?php echo esc_html(tpi_admin_ui('news.button.save')); ?></button>
            </div>
        </form>
    </div>
    <script>
        jQuery(document).ready(function ($) {
            let frame;

            function syncThumb(url) {
                $('#post_image').val(url || '');
                $('#post_image_preview').attr('src', url || '');
                $('#post-image-thumb').toggleClass('is-empty', !url);
            }

            $('#upload_post_image').on('click', function (e) {
                e.preventDefault();
                // 换图时先锁定当前这张（媒体库按 URL 反查附件）
                let current = ($('#post_image').val() || '').trim();
                if (frame) {
                    frame.open();
                    if (window.tpiMediaPreselect) { window.tpiMediaPreselect(frame, current); }
                    return;
                }
                frame = wp.media({
                    title: '<?php echo esc_js(tpi_admin_ui('news.media.title')); ?>',
                    button: { text: '<?php echo esc_js(tpi_admin_ui('news.media.button')); ?>' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function () {
                    let attachment = frame.state().get('selection').first().toJSON();
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
            let $desc = $('#post_description'), $count = $('#desc-counter');
            function updateCount() {
                let n = ($desc.val() || '').trim().length;
                $count.text(n + ' ' + '<?php echo esc_js(tpi_admin_ui('news.counter.unit')); ?>');
                $count.toggleClass('is-warn', n > 160);
            }
            $desc.on('input', updateCount);
            updateCount();
        });
    </script>
    <?php
}