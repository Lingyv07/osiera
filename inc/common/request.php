<?php
/**
* 请求与访客信息
 *
 * IP、签名 uuid、cookie 解析这类跟「这一次请求」相关的小工具。
 */

/**
 * 获取用户ip
 * @return string
 */
function get_client_ip(): string {
    $ip_keys = [
        'HTTP_X_REAL_IP',        // Nginx理服务器添加
        'HTTP_X_FORWARDED_FOR',  // 代理服务器或负载均衡转发的真实IP
        'REMOTE_ADDR'            // 直接连接服务器的IP
    ];

    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key]) && strtolower($_SERVER[$key]) !== 'unknown') {
            // 有时候 HTTP_X_FORWARDED_FOR 可能会有多个IP，用逗号分隔，取第一个
            $ip_list = explode(',', $_SERVER[$key]);
            foreach ($ip_list as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }

    return "";
}

/**
 *   随机id生成
 * @return string|null
 */
function generate_uuid_with_ip(): ?string {
    try {
        // 生成 UUID v4
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        // 准备待签名数据
        $to_sign = (get_client_ip() ?? "unk") . '|' . $uuid;

        // 使用服务器密钥
        $secret = defined('AUTH_KEY') ? AUTH_KEY : 'your_secret_key_here';

        // 计算 HMAC 签名
        return hash_hmac('sha256', $to_sign, $secret);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 地址是不是指向本机 / 内网
 *
 * 用来判断 HTTP 请求要不要绕开系统代理：开发机、容器、CI 里 php-cgi 进程经常带着
 * http_proxy / https_proxy 环境变量，libcurl 会照它走，于是「站内请求同机的另一个站」
 * 被代理挡下来，只回来一句空的 502，看不出是代理干的。
 *
 * 判断口径：localhost、单段主机名（局域网机器名）、私有网段 / 环回 / 链路本地地址，
 * 以及解析到这些地址的域名。只看目标本身，不写死任何具体域名或地址。
 *
 * @param string $url 完整地址
 * @return bool
 */
function tpi_url_is_local(string $url): bool {
    $host = strtolower(trim((string) wp_parse_url($url, PHP_URL_HOST)));
    if ($host === '') {
        return false;
    }
    $host = trim($host, '[]');          // IPv6 字面量
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return true;
    }
    if (!filter_var($host, FILTER_VALIDATE_IP) && strpos($host, '.') === false) {
        return true;                     // 单段主机名＝局域网机器名
    }
    // 域名先解析一次：hosts 文件里指向环回地址的本地域名也算内网
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (string) @gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    // 带 NO_PRIV_RANGE / NO_RES_RANGE 校验不通过 ＝ 私有 / 保留地址
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * 把一个远端地址下载到本地文件
 *
 * 内网目标显式关掉代理直连；公网目标保持代理可用（办公室 / CI 常常只有代理能出网）。
 * 走 cURL 是因为 WordPress 6.8 的 HTTP API 已经改用 Requests 传输层，那里没有
 * WP_PROXY_* 常量之外的开关，也不认 http_api_curl 钩子，环境变量代理根本压不住；
 * 而 cURL 在 CURLOPT_PROXY 为空串 + CURLOPT_NOPROXY=* 时才会绕过环境代理。
 *
 * @param string $url     远端地址
 * @param string $file    本地落盘路径（调用方负责清理半截文件）
 * @param int    $timeout 超时秒数
 * @return bool 只有 HTTP 200 且文件非空才算成功
 */
function tpi_http_download(string $url, string $file, int $timeout = 60): bool {
    if ($url === '' || $file === '') {
        return false;
    }
    $direct = tpi_url_is_local($url);

    if (function_exists('curl_init')) {
        $fp = @fopen($file, 'wb');
        if ($fp === false) {
            return false;
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
        ];
        // 证书校验用 WordPress 自带 CA 包，别用系统默认（phpstudy 下常常是空的）
        $ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if (is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        if ($direct) {
            $opts[CURLOPT_PROXY]   = '';
            $opts[CURLOPT_NOPROXY] = '*';
        }
        curl_setopt_array($ch, $opts);
        $ok   = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $ok && $code === 200 && (int) @filesize($file) > 0;
    }

    // 没装 cURL：退回 HTTP API（公网可用，内网在带代理的环境里可能被拦）
    if (!function_exists('wp_remote_get')) {
        return false;
    }
    $res = wp_remote_get($url, ['timeout' => $timeout, 'stream' => true, 'filename' => $file]);

    return !is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200;
}

/**
 * 获取解构后的cookies
 * @return array
 */
function parseCookies(): array {
    $cookies = [];
    $headers = getallheaders();
    if (isset($headers['Cookie'])) {
        foreach (explode(';', $headers['Cookie']) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2) {
                $cookies[$parts[0]] = $parts[1];
            }
        }
    }
    return $cookies;
}
