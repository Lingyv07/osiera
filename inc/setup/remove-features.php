<?php
function disable_wp_emojis() {
// 关闭前台 Emoji 脚本和样式
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');

// 关闭后台 Emoji
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');

// 关闭 TinyMCE 中的 Emoji 插件
    add_filter('tiny_mce_plugins', function($plugins) {
        return is_array($plugins) ? array_diff($plugins, ['wpemoji']) : [];
    });

// 禁用 Emoji DNS 预获取
    add_filter('emoji_svg_url', '__return_false');
}
add_action('init', 'disable_wp_emojis');

remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );

// 移除 Gutenberg 样式
function remove_block_library_style_when_not_logged_in() {
    // 判断用户是否已登录
    if ( ! is_user_logged_in() ) {
        // 删除 `style.min.css` 资源
        wp_dequeue_style( 'wp-block-library' );
    }
}
add_action( 'wp_enqueue_scripts', 'remove_block_library_style_when_not_logged_in', 100 );

add_action( 'wp_enqueue_scripts', function() {
    wp_dequeue_style( 'classic-theme-styles' ); // 队列中移除
    wp_deregister_style( 'classic-theme-styles' ); // 彻底注销
}, 20 );

// 隐藏后台评论菜单
add_action('admin_menu', function() {
    remove_menu_page('edit-comments.php');
});

// 移除 WordPress 版本号
remove_action('wp_head', 'wp_generator');

// 移除 RSD 链接
remove_action('wp_head', 'rsd_link');

// 移除 Windows Live Writer 链接
remove_action('wp_head', 'wlwmanifest_link');

// 可选：移除短链接
remove_action('wp_head', 'wp_shortlink_wp_head');

// ===============================
// 彻底禁用WordPress所有更新与提示
// ===============================

// 1. 禁用 WordPress 核心更新
add_filter( 'automatic_updater_disabled', '__return_true' );
add_filter( 'allow_major_auto_core_updates', '__return_false' );
add_filter( 'allow_minor_auto_core_updates', '__return_false' );
add_filter( 'allow_dev_auto_core_updates', '__return_false' );

// 2. 禁用插件和主题自动更新
add_filter( 'auto_update_plugin', '__return_false' );
add_filter( 'auto_update_theme', '__return_false' );

// 3. 阻止更新检测
remove_action( 'load-update-core.php', 'wp_update_plugins' );
remove_action( 'load-update-core.php', 'wp_update_themes' );
remove_action( 'admin_init', '_maybe_update_core' );
remove_action( 'admin_init', '_maybe_update_plugins' );
remove_action( 'admin_init', '_maybe_update_themes' );
remove_action( 'wp_update_plugins', 'wp_update_plugins' );
remove_action( 'wp_update_themes', 'wp_update_themes' );
remove_action( 'init', 'wp_version_check' );
remove_action( 'init', 'wp_update_plugins' );
remove_action( 'init', 'wp_update_themes' );

// 4. 屏蔽后台更新提示（完全不显示任何升级提醒）
add_filter('pre_site_transient_update_core', '__return_null');
add_filter('pre_site_transient_update_plugins', '__return_null');
add_filter('pre_site_transient_update_themes', '__return_null');

// 屏蔽顶部黄色更新提醒Banner
add_action('admin_menu', function() {
    remove_action('admin_notices', 'update_nag', 3);
});

// 隐藏后台左侧更新图标与红色数字角标
add_action('admin_menu', function(){
    global $menu;
    foreach ($menu as $k=>$item) {
        if ($item[2]=='update-core.php') unset($menu[$k]);
    }
});

// 隐藏后台插件页面更新信息
add_filter('site_transient_update_plugins', function($value){
    return null;
});
add_filter('site_transient_update_themes', function($value){
    return null;
});