<?php
add_action('rest_api_init', function () {
    register_rest_route('api', '/set-cookies', array(
        'methods'  => 'POST',
        'callback' => 'handle_get_cookies_post',
        'permission_callback' => '__return_true', // 允许公开访问，如需权限请改这里
    ));
});

function handle_get_cookies_post(WP_REST_Request $request) {
    // 设置 Cookie，过期时间比如 1 年（31536000 秒）
    if (empty($_COOKIE['u_token'])) {
        $uid = generate_uuid_with_ip();
        if ($uid) {
            // 主机名：优先取 CDN/反代透传的真实域名
            $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
            $host = preg_replace('/:\d+$/', '', $host);

            // IP / localhost 不能带 Domain：一旦带上，浏览器会直接丢掉这个 Cookie
            $is_ip  = (bool) filter_var($host, FILTER_VALIDATE_IP) || strtolower($host) === 'localhost';
            $domain = '';
            if (!$is_ip) {
                // 取顶级域名（含二级后缀），CDN 与 origin 都能访问
                preg_match('/([^.]+\.[^.]+)$/', $host, $matches);
                $domain = isset($matches[1]) ? '.' . $matches[1] : '';
            }

            // 只在 https 下加 secure：本地/测试站是 http，加了 secure 浏览器根本不保存，
            // 前端就会每次刷新都重新请求一遍 set-cookies
            $is_https = is_ssl() || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            setcookie('u_token', $uid, [
                "expires"  => time() + 31536000,
                "path"     => "/",
                "domain"   => $domain,
                "secure"   => $is_https,
                "httponly" => false, // 前端要读 document.cookie 判断有没有 token
                "samesite" => "Lax",
            ]);
        }
    }
}

