<?php
/**
 * 页面输出压缩兜底（gzip）
 *
 * 压缩本来是 Web 服务器该干的事：`Nginx1.25.2/conf/nginx.conf` 里的 `gzip on;`
 * 已经打开（`nginx -t` 通过，重启 Nginx 生效，首页 85KB → 16.7KB）。
 *
 * 但换环境时经常遇到「服务器根本没开 gzip」——HTML / CSS / JS 全部裸传，
 * 首页 Doc 就从二十几 KB 变成一百多 KB。这里在 PHP 层兜一层：
 * 客户端支持 gzip 就压，不支持就原样输出。
 *
 * 服务器已经压过一遍时不会压第二次（响应里带了 Content-Encoding，nginx 会跳过自己的 gzip）。
 * 想彻底关掉：`add_filter('osiera_php_gzip', '__return_false');`
 */

if (!function_exists('osiera_output_gzip')) {
    function osiera_output_gzip(): void
    {
        // 后台 / AJAX / REST / 定时任务不碰（REST 走不到 template_redirect，这里只是兜底判断）
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        // 服务器或 php.ini 已经开了压缩（zlib.output_compression），不再叠一层
        if (ini_get('zlib.output_compression')) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        // 客户端不接受 gzip 就直接明文（老 UA、命令行工具）
        $accept = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
        if ($accept === '' || stripos($accept, 'gzip') === false) {
            return;
        }
        if (!function_exists('ob_gzhandler')) {
            return;
        }
        /**
         * 是否启用 PHP 兜底压缩
         * @param bool $enabled
         */
        if (!apply_filters('osiera_php_gzip', true)) {
            return;
        }
        ob_start('ob_gzhandler');
    }
}
add_action('template_redirect', 'osiera_output_gzip', 1);
