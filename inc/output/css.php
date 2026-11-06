<?php
// 添加默认语言
function  x_default_func (): void {
    // 每个已启用语言一条（域名用当前访问域名，和线上 origin 一致）
    $urls = tpi_current_content_urls();
    if (!$urls) {
        return;
    }

    $default_lang = tpi_default_language();
    // x-default 永远跟着后台设置的「默认语言」走：默认语言换成 ru，
    // 它就指向 ru 那个版本的地址，而不是原地不动指向旧的默认语言。
    $default_url = (string) ($urls[$default_lang] ?? '');

    foreach ($urls as $one_lang => $one_url) {
        if ($one_url === '') {
            continue;
        }
        // hreflang 用本地化标记（en-US），不是语言代码（en）
        $one_item = tpi_language_by_slug((string) $one_lang);
        $one_tag  = $one_item ? tpi_locale_tag((string) $one_item['locale']) : tpi_locale_tag((string) $one_lang);
        if ($one_tag === '') {
            $one_tag = (string) $one_lang;
        }
        echo '<link rel="alternate" href="'.esc_url($one_url).'" hreflang="'.esc_attr($one_tag).'">
';
    }

    // x-default 放最后，和线上 view-source 的顺序一致
    if ($default_url !== '') {
        echo '<link rel="alternate" href="'.esc_url($default_url).'" hreflang="x-default">
';
    }
}
add_action( 'wp_head', 'x_default_func', 1);

// 添加全局内联
function custom_global_inline_css(): void
{
    // 内联全局 媒体查询
    $custom_css = file_get_contents(get_template_directory() . '/assets/css/global-style-inline.css');
    if ($custom_css) {
        echo "<style id='global-styles-inline-css' type='text/css'>\n" . $custom_css . "\n</style>\n";
    }
}
add_action( 'wp_head', 'custom_global_inline_css', 1);

// 添加全局外联 CSS 替代
function custom_global_external_relations_css(): void {
    global $version;
    wp_enqueue_style('global-styles-css', get_template_directory_uri() . '/assets/css/global-style.css', array(), $version);
    wp_enqueue_style('global-styles-media-css', get_template_directory_uri() . '/assets/css/global-style-media.css', array(), $version);
}
add_action('wp_enqueue_scripts', "custom_global_external_relations_css");

/**
 * 数据后台管理样式
 * @return void
 */
function custom_manage_css(): void {
    global $version;
    if (is_user_logged_in()) {
        wp_enqueue_style('custom-manage-css', get_template_directory_uri() . '/assets/wp-assets/css/wp-manage.css', array(), $version);
        // 自带编辑器的内容（产品 / FAQ / 应用 / 资讯）里存的是区块 HTML（wp-block-quote 等），
        // 后台页面本身不加载区块样式，编辑器里就只剩浏览器默认样式。
        // 两份核心样式都要：style 管结构（按钮圆角、图片对齐），theme 管外观（引用块左边框）。
        add_editor_style(includes_url('css/dist/block-library/style.min.css'));
        add_editor_style(includes_url('css/dist/block-library/theme.min.css'));
        // 主题这几份编辑器样式要带版本号，否则改了 CSS 浏览器仍用旧缓存，编辑器里看到的还是旧样式
        add_editor_style(add_query_arg('ver', $version, get_template_directory_uri() . '/assets/css/global-style-inline.css'));
        add_editor_style(add_query_arg('ver', $version, get_template_directory_uri() . '/assets/css/global-style.css'));
        add_editor_style(add_query_arg('ver', $version, get_template_directory_uri() . '/assets/css/editor.css'));
    }
}
add_action('admin_enqueue_scripts', "custom_manage_css");