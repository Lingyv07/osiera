<?php
// 启用主题的自定义 Logo 支持
function theme_custom_logo_setup(): void {
    // 调用 `add_theme_support` 函数来启用自定义 Logo 支持
    add_theme_support( 'custom-logo', array(
        'height'      => 60,  // 设置 Logo 的默认高度为 60px
        'width'       => 0,  // 设置 Logo 的默认宽度为 0
        'flex-width'  => true, // 允许 Logo 在宽度上自适应调整，开启此选项后，Logo 宽度可以根据需求灵活调整
        'flex-height' => true, // 允许 Logo 在高度上自适应调整，开启此选项后，Logo 高度可以根据需求灵活调整
        'header-text' => array( 'site-title', 'site-description' ), // 当没有设置 Logo 时，允许显示站点标题和描述
    ) );
}
// 将函数 `theme_custom_logo_setup` 挂载到 `after_setup_theme` 动作钩子上
add_action( 'after_setup_theme', 'theme_custom_logo_setup' );

/**
 * 移除 head 里的 RSS / 评论 Feed 的 <link> 标签
 *
 * WP 默认在 wp_head 里输出两条：
 *   feed_links       （优先级 2）<link rel="alternate" type="application/rss+xml" title="站点 » Feed">
 *   feed_links_extra （优先级 3）分类 / 标签 / 作者 / 单篇评论的 Feed
 * 前台没有订阅入口，也用不到这些链接；而且标题里的「评论 Feed」会跟着 WP 后台语言变中文，
 * 混在英文页面的源码里，所以整体摘掉。
 */
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);

/**
 * robots：全站只输出一条，交给核心 wp_robots 统一生成
 *
 * 规则：
 *   404 / 搜索结果        → noindex, nofollow, noarchive
 *   后台「本站 SEO」关闭  → noindex, follow（只屏蔽源站，经 CDN 访问照常收录）
 *   其它页面              → index, follow
 *
 * 两条都只是在核心已生成的指令上「增删」，不再整份 return 覆盖 ——
 * 核心默认的 max-image-preview:large / max-snippet / max-video-preview
 * 必须原样保留，否则搜索结果里的大图预览会失效。
 */
add_filter('wp_robots', function (array $robots): array {
    $drop = ['index', 'follow', 'noindex', 'nofollow', 'noarchive'];

    if (is_404() || is_search()) {
        $head = ['noindex' => true, 'nofollow' => true, 'noarchive' => true];
    } elseif (function_exists('tpi_monitor_seo_allowed') && !tpi_monitor_seo_allowed()) {
        // 「本站 SEO」关闭：源站不让收录，链接仍可跟随，CDN 域名不受影响
        $head = ['noindex' => true, 'follow' => true];
    } else {
        $head = ['index' => true, 'follow' => true];
    }

    foreach ($drop as $key) {
        unset($robots[$key]);
    }

    // 只调整 index / follow 这一组，其余核心指令保持原顺序追加在后面
    return $head + $robots;
}, 20);

/**
 * 站点地图交给 inc/output/sitemap-lang.php 自己输出
 *
 * 核心那套 /wp-sitemap.xml 只懂「一张地图装全站」，没法按语言拆开，
 * 而且分类 / 标签 / 用户这些 provider 对本站没意义。
 * 这里直接关掉核心 sitemaps，改由主题输出：
 *   /sitemap.xml        → 语言索引
 *   /sitemap-{lang}.xml → 每种语言一份
 * /wp-sitemap.xml 及旧的子地图地址会 301 到 /sitemap.xml。
 */
add_filter('wp_sitemaps_enabled', '__return_false');

// 自动清理 7 天前的自动草稿：
add_action('wp_scheduled_delete', function() {
    global $wpdb;
    $wpdb->query("
        DELETE FROM $wpdb->posts 
        WHERE post_status = 'auto-draft' 
        AND post_date < NOW() - INTERVAL 7 DAY
    ");
});

// 禁用 xmlrpc.php
add_filter('xmlrpc_enabled', '__return_false');

// 禁止 REST API 提交评论
add_filter('rest_allow_anonymous_comments', '__return_false');

// REST API 发送评论时直接返回错误
// 只拦写操作（POST/PUT/PATCH/DELETE）。读请求要放行：
// 文章 / 产品 / 应用的区块编辑器会自己发
// GET /wp/v2/comments?context=edit&type=note&status=all 去取编辑器笔记，
// 一并拦掉会在控制台刷 403（rest_cookie_invalid_nonce 之外的另一条红字），
// 编辑器右上角的笔记面板也一直转圈。
add_filter('rest_pre_dispatch', function($response, $server, $request) {
    if (!str_contains($request->get_route(), '/comments')) {
        return $response;
    }
    $method = strtoupper((string) $request->get_method());
    if ($method === 'GET' || $method === 'HEAD') {
        return $response;
    }
    return new WP_Error('comments_disabled', 'Comments are disabled.', array('status' => 403));
}, 10, 3);

// 禁止直接提交评论
function disable_comment_posting(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'wp-comments-post.php')) {
        wp_die('Comments are closed.', 'Comments Disabled', array('response' => 403));
    }
}
add_action('init', 'disable_comment_posting');