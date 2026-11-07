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
        // 编辑器（经典 + 区块）的样式统一在 inc/setup/theme-support.php 的
        // after_setup_theme 里注册：挂到这里已经错过区块编辑器收集样式的时机。
    }
}
add_action('admin_enqueue_scripts', "custom_manage_css");