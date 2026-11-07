<?php
// 主题设置函数：注册主题支持功能和特性
function theme_register_menus():void {
//    add_theme_support( 'title-tag' ); // 支持动态标题
    add_theme_support( 'post-thumbnails' ); // 支持文章的特色图片
    add_theme_support( 'responsive-embeds' ); // 嵌入内容响应式支持
    add_theme_support( 'automatic-feed-links' ); // 自动为文章和评论添加 RSS 链接
    add_theme_support( 'html5', array( 'search-form', 'navigation-widgets' ) ); // 支持 HTML5 结构
    add_theme_support( 'appearance-tools' ); // 支持自定义工具，
    // 设置默认的内容宽度
//    global $content_width;
//    if ( !isset( $content_width ) ) { $content_width = 1920; }
    // 注册主菜单
    register_nav_menus( array( 'main-menu' => esc_html__( 'Main Menu', 'osiera' ) ) );
}
add_action('after_setup_theme', 'theme_register_menus');

// 在前端加载 CSS 和 JS 文件
//add_action( 'wp_enqueue_scripts', 'theme_enqueue' );
function theme_enqueue(): void {
    wp_enqueue_style( 'osiera-style', get_stylesheet_uri() ); // 加载主题样式表
    wp_enqueue_script( 'jquery' ); // 加载 jQuery
}

// 自定义“阅读更多”链接
add_filter( 'the_content_more_link', 'theme_read_more_link' );
function theme_read_more_link(): string {
    if (!is_admin() ) {
        return ' <a href="' . esc_url( get_permalink() ) . '" class="more-link">' . sprintf( __( '...%s', 'osiera' ), '<span class="screen-reader-text">  ' . esc_html( get_the_title() ) . '</span>' ) . '</a>';
    }
    return '';
}

// 自定义摘要的“阅读更多”链接
add_filter( 'excerpt_more', 'theme_excerpt_read_more_link' );
function theme_excerpt_read_more_link( $more ): string {
    if (!is_admin() ) {
        global $post;
        return ' <a href="' . esc_url( get_permalink( $post->ID ) ) . '" class="more-link">' . sprintf( __( '...%s', 'osiera' ), '<span class="screen-reader-text">  ' . esc_html( get_the_title() ) . '</span>' ) . '</a>';
    }
    return '';
}

// 移除 speculationrules
if (!is_admin() ) {
    add_action('init', function () {
        remove_action('wp_footer', 'wp_print_speculation_rules');
    });
}

// 编辑器加载时修复空内容
function fix_empty_post_content_admin_init() {
    global $pagenow;
    if ( in_array($pagenow, ['post.php', 'post-new.php']) ) {
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        if (!$post_id) return;

        $post = get_post($post_id);
        if ($post && ($post->post_content === null || trim($post->post_content) === '')) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
            ]);
        }
    }
}
add_action('admin_init', 'fix_empty_post_content_admin_init');

// 保存文章时修复空内容
function fix_empty_post_content_save_post($post_id, $post, $update) {
    // 只处理文章和页面
    if (!in_array($post->post_type, ['post', 'page'])) {
        return;
    }

    // 防止死循环
    remove_action('save_post', 'fix_empty_post_content_save_post', 10);

    if ($post->post_content === null || trim($post->post_content) === '') {
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
        ]);
    }

    // 恢复钩子
    add_action('save_post', 'fix_empty_post_content_save_post', 10, 3);
}
add_action('save_post', 'fix_empty_post_content_save_post', 10, 3);

// 禁用大图片尺寸
//add_filter( 'big_image_size_threshold', '__return_false' );

// 修改插入图片的默认尺寸
add_filter( 'intermediate_image_sizes_advanced', 'theme_image_insert_override' );
function theme_image_insert_override( $sizes ) {
    unset( $sizes['medium_large'] ); // 删除 medium_large 尺寸
    unset( $sizes['1536x1536'] ); // 删除 1536x1536 尺寸
    unset( $sizes['2048x2048'] ); // 删除 2048x2048 尺寸
    return $sizes;
}

// 1. 禁用 WordPress 自带的 MediaElement.js
function disable_wp_mediaelement(): void {
    wp_deregister_script('wp-mediaelement');
    wp_deregister_style('wp-mediaelement');
}
add_action('wp_enqueue_scripts', 'disable_wp_mediaelement', 100);

// 2. TinyMCE 允许 <video> 和 <source> 标签，同时保留默认标签
function allow_video_in_tinymce($init) {
    // img[*] 让 width/height/loading 等属性在保存时不被剥掉：
    // 少了 width/height，图片懒加载前高度为 0，前台会先塌一下再被图片顶开（布局抖动）
    $ext = 'video[*],source[*],track[*],img[*]';
    if ( isset($init['extended_valid_elements']) ) {
        $init['extended_valid_elements'] .= ',' . $ext;
    } else {
        // 保留常用块级和内联元素，避免 <p> 被干掉
        $init['extended_valid_elements'] = 'p[*],a[*],span[*],div[*],' . $ext;
    }
    return $init;
}
add_filter('tiny_mce_before_init', 'allow_video_in_tinymce');

// 3. 允许 WordPress KSES 不过滤 <video> 和 <source>
function allow_video_source_tags($tags, $context) {
    if (in_array($context, ['post', 'data'])) {
        $tags['video'] = [
            'controls' => true,
            'width'    => true,
            'height'   => true,
            'autoplay' => true,
            'loop'     => true,
            'muted'    => true,
            'poster'   => true,
        ];
        $tags['source'] = [
            'src'  => true,
            'type' => true,
        ];
        $tags['track'] = [
            'kind'    => true,
            'src'     => true,
            'srclang' => true,
            'label'   => true,
            'default' => true,
        ];
    }
    return $tags;
}
add_filter('wp_kses_allowed_html', 'allow_video_source_tags', 10, 2);

// 4. 插入媒体时直接生成原生 <video>，不走 shortcode
function insert_native_video_to_editor($html, $id, $attachment): string {
    $src = wp_get_attachment_url($id);
    if (preg_match('/\.mp4$/i', $src)) {
        return '<video controls width="640" height="360">'
            . '<source src="' . esc_url($src) . '" type="video/mp4">'
            . '<track kind="captions" srclang="en" label="English" src="">'
            . '</video>';
    }
    return $html;
}
add_filter('media_send_to_editor', 'insert_native_video_to_editor', 10, 3);

// 5. 禁用 [video] shortcode，确保前台不会被 WP 接管
remove_shortcode('video');

// 6. 禁用 oEmbed，避免粘贴 URL 被转成 <iframe>
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');
remove_filter('the_content', [$GLOBALS['wp_embed'], 'autoembed'], 8);

// 7. 把文章中的 .mp4 链接替换成 <video> 标签（保留 <p> 结构）
function replace_video_url_with_video_tag($content) {
    $pattern = '/https?:\/\/[^\s"]+\.mp4/';
    return preg_replace_callback($pattern, function($matches) {
        $src = esc_url($matches[0]);
        return '<video controls width="640" height="360">'
            . '<source src="' . $src . '" type="video/mp4">'
            . '</video>';
    }, $content);
}
add_filter('the_content', 'replace_video_url_with_video_tag', 20);

remove_action('wp_head', 'rel_canonical');

// canonical用户规范
add_action('wp_head', function() {
    global $main_host;

    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $redirect_url = $main_host. $request_uri;

    echo '<link rel="canonical" href="'.$redirect_url.'">
';
});

// 页面在不在导航里，由主题菜单名单决定（inc/content/primary-menu.php）：
// 默认菜单建的时候就没把隐私政策加进去，不再是「渲染时按 slug 排除」。

// 展示页关闭导航工具
add_action('after_setup_theme', function() {
    if (!is_admin()) {
        show_admin_bar(false);
    }
});

// 修改 RSS feed 的标题
function custom_rss_title(): string {
    global $title_name, $brand_name;
    if ($title_name) {
        $title = $title_name. ' | '. $brand_name;
    } else {
        $title = get_the_title(). ' | '. $brand_name;
    }

    return $title;
}
add_filter('wp_title_rss', 'custom_rss_title');

// 标题转码
add_filter('the_title', 'sanitize_all_titles');
add_filter('get_the_title', 'sanitize_all_titles');
function sanitize_all_titles($title, $post_id = null): string {
    return html_entity_decode($title);
}

/* ------------------------------------------------------------------
 * 编辑器样式（区块编辑器 + 经典编辑器）
 *
 * add_editor_style() 必须在 after_setup_theme 里调用：挂在
 * admin_enqueue_scripts 上时，区块编辑器已经收集完编辑画布的样式，
 * 一条都注入不进去（编辑框里只剩核心默认排版）。
 * 同时要声明 editor-styles 支持，区块编辑器才会把主题样式放进 iframe。
 * ------------------------------------------------------------------ */
add_action('after_setup_theme', 'tpi_register_editor_styles');

if (!function_exists('tpi_register_editor_styles')) {
    function tpi_register_editor_styles(): void
    {
        add_theme_support('editor-styles');

        // 只放主题内的相对路径：区块编辑器会直接读文件内容注入 iframe，
        // 换成绝对 URL 它会用 wp_remote_get 抓自己，慢且容易静默失败。
        add_editor_style([
            'assets/css/global-style-inline.css',
            'assets/css/global-style.css',
            'assets/css/editor.css',
        ]);
    }
}

/**
 * 经典编辑器（自建编辑页的 wp_editor）补上区块库的两份核心样式：
 * 内容里存的是 wp-block-quote 等区块 HTML，后台不加载区块样式就会塌成默认排版。
 * 区块编辑器自己本来就加载这两份，不用重复加。
 */
add_filter('mce_css', 'tpi_editor_mce_css');

if (!function_exists('tpi_editor_mce_css')) {
    function tpi_editor_mce_css(string $css): string
    {
        $extra = [
            includes_url('css/dist/block-library/style.min.css'),
            includes_url('css/dist/block-library/theme.min.css'),
        ];

        foreach ($extra as $url) {
            if (strpos($css, $url) === false) {
                $css .= ($css !== '' ? ',' : '') . $url;
            }
        }

        return $css;
    }
}