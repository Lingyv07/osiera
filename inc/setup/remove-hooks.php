<?php
// 移除 WordPress 默认的 global styles inline CSS
remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_styles', 'print_emoji_styles');
remove_filter('the_content_feed', 'wp_staticize_emoji');
remove_filter('comment_text_rss', 'wp_staticize_emoji');
remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

// 移除 REST API 链接头
remove_action('template_redirect', 'rest_output_link_header', 11);
remove_action('wp_head', 'rest_output_link_wp_head', 10);
remove_action('xmlrpc_rsd_apis', 'rest_output_link_xmlrpc_rsd');
remove_action('wp_head', 'wp_oembed_add_discovery_links');

// 移除短链接 header
remove_action('template_redirect', 'wp_shortlink_header', 11);
remove_action('wp_head', 'wp_shortlink_wp_head', 10);