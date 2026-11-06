<?php
/**
 * 访问域名统一（CDN / 反向代理下的输出层）
 *
 * 判定只有一个依据：请求头里有没有 X-Forwarded-Host。
 *   有 —— 说明这次请求是 CDN 回源的，X-Forwarded-Host 就是访客用的域名。
 *         文档里所有域名一律覆盖成它：不只是数据库里那一个域名，而是
 *         「本站主域名 + 任意子域」（apex、www、en、其他子域）全部收敛过来，
 *         含 sitemap、JSON-LD、og、静态资源地址。
 *   没有 —— 就是直接访问源站，一个字节都不改，零开销。
 * 不需要在后台填任何「对外域名」，也不需要在 wp-config 里定义任何域名：
 * CDN 域名由 CDN 自己在请求头里带过来，主题只管照着输出。
 *
 * 唯一的例外是 <link rel="canonical">：
 *   canonical 跟 CDN 没有关系，它只认后台「站点与路径 → 目标源」
 *   （没填就跟当前访问域名一致）。做法是替换前先把它摘出来、按目标源写好、
 *   整篇替换完再放回 —— 顺序上「覆盖后再次覆盖」，保证它不被 XFH 冲掉。
 *
 * 为什么需要输出缓冲：
 *   主页面的地址是 REQUEST_SCHEME://HTTP_HOST 拼的，本来就跟随访问域名；
 *   但 sitemap / home_url() / get_permalink() 读的是数据库 siteurl（源站域名），
 *   而且 tpi_sitemap_serve() 挂在 template_redirect 优先级 1，输出完直接 exit，
 *   挂在优先级 10 的缓冲追不上它。所以在 after_setup_theme 就开一层，
 *   只替换主机名，不做任何 HTML 结构改写（自闭合斜杠等由
 *   inc/output/prefix-replace.php 负责）。
 *
 * 开关（写在 wp-config.php 里，可不填）：
 *   TPI_CDN_ADMIN   true 时后台也换域名，默认 false（防止登录跳转异常）
 *   TPI_CDN_LEGACY  需要一并清掉的历史域名，逗号分隔
 *   TPI_CDN_DEBUG   true 时响应头带 X-CDN-Hosts，便于确认命中
 *
 * 响应头 X-TPI-Cdn 只带「这次识别到的域名」：有值说明拿到了 X-Forwarded-Host，
 * 没有这个头就是直连源站。不带版本号，版本靠代码本身，不靠响应头。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TPI_CDN_ADMIN')) {
    define('TPI_CDN_ADMIN', false);
}
if (!defined('TPI_CDN_LEGACY')) {
    define('TPI_CDN_LEGACY', '');
}
if (!defined('TPI_CDN_DEBUG')) {
    define('TPI_CDN_DEBUG', false);
}

/**
 * 任意形态的地址归一成纯主机名：去协议、去路径、去端口，逗号只取第一个。
 */
function tpi_cdn_norm_host($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (strpos($value, ',') !== false) {
        $parts = explode(',', $value);
        $value = trim($parts[0]);
    }
    $value = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $value); // 去协议
    $value = (string) preg_replace('~[/?#].*$~', '', $value);                 // 去路径
    $value = (string) preg_replace('~:\d+$~', '', $value);                    // 去端口
    return strtolower(trim($value, '.'));
}

/**
 * 本次请求是不是 CDN 回源：只看 X-Forwarded-Host。
 * CDN 回源时会把它设成访客实际使用的域名，没有这个头就是直连源站。
 */
function tpi_cdn_is_cdn()
{
    static $is = null;
    if ($is !== null) {
        return $is;
    }
    $is = tpi_cdn_norm_host(isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : '') !== '';
    return $is;
}

/**
 * 本次请求「访客实际使用的域名」：CDN 透传的优先，其次访问域名。
 */
function tpi_cdn_request_host()
{
    static $host = null;
    if ($host !== null) {
        return $host;
    }
    $host = '';
    foreach (array('HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST', 'HTTP_HOST') as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $one = tpi_cdn_norm_host($_SERVER[$key]);
        if ($one !== '') {
            $host = $one;
            break;
        }
    }
    return $host;
}

/**
 * 对外协议：CDN 透传的协议 > 数据库 siteurl > 请求协议。
 *
 * 数据库 siteurl 排在请求协议前面：https 站点回源时是明文 http，
 * 不能因为它就把对外地址降级成 http://。
 */
function tpi_cdn_public_scheme()
{
    static $scheme = null;
    if ($scheme !== null) {
        return $scheme;
    }
    $scheme = '';
    $proto  = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($proto !== '') {
        $one = strtolower(trim((string) explode(',', $proto)[0]));
        if ($one === 'https' || $one === 'http') {
            $scheme = $one;
        }
    }
    if ($scheme === '') {
        $one = strtolower((string) parse_url((string) get_option('siteurl', ''), PHP_URL_SCHEME));
        if ($one === 'https' || $one === 'http') {
            $scheme = $one;
        }
    }
    if ($scheme === '') {
        if (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
        } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        }
    }
    if ($scheme !== 'https' && $scheme !== 'http') {
        $scheme = 'http';
    }
    return $scheme;
}

/**
 * 本次请求实际应当对外输出的主机名。
 *
 * 和 tpi_cdn_target() 的区别：那个表示「要把源站域名换成什么」，不换时是空串；
 * 这个一定给出一个可用主机名，sitemap 的 <loc>、hreflang 用它。
 */
function tpi_cdn_effective_host()
{
    $target = tpi_cdn_target();
    if ($target !== '') {
        return $target;
    }
    $requested = tpi_cdn_request_host();
    if ($requested !== '') {
        return $requested;
    }
    return (string) parse_url((string) get_option('siteurl', ''), PHP_URL_HOST);
}

/**
 * 数据库里配置的站点主机名（home / siteurl）。
 * 这里读原始 option，不能走 home_url()，否则会被下面的过滤器绕成环。
 */
function tpi_cdn_site_hosts()
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }
    $list = array();
    foreach (array('home', 'siteurl') as $option) {
        $host = tpi_cdn_norm_host(get_option($option, ''));
        if ($host !== '') {
            $list[$host] = true;
        }
    }
    $hosts = array_keys($list);
    return $hosts;
}

/**
 * 本次请求应当对外使用的域名；不需要替换时返回空串。
 *
 *   1) 没有 X-Forwarded-Host —— 直连源站，不替换；
 *   2) 有 —— 就整篇换成它，包括它刚好等于数据库站点域名的情况
 *      （那种情况下 www / apex / 其他子域仍要收敛到它，不能跳过）。
 */
function tpi_cdn_target()
{
    static $target = null;
    if ($target !== null) {
        return $target;
    }
    $target = '';
    if (!tpi_cdn_is_cdn()) {
        return $target;
    }
    $target = tpi_cdn_norm_host($_SERVER['HTTP_X_FORWARDED_HOST']);
    return $target;
}

/**
 * 本站主域名：数据库站点域名去掉开头的 www。
 *
 *   数据库是 www.example.com → example.com
 *   用它一次覆盖 www.example.com、example.com、en.example.com、任意子域，
 *   不依赖「文档里出现的到底是哪个变体」。
 */
function tpi_cdn_base_domain()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = '';
    foreach (array('home', 'siteurl') as $option) {
        $host = tpi_cdn_norm_host(get_option($option, ''));
        if ($host === '') {
            continue;
        }
        $one = preg_replace('~^www\.~i', '', $host);
        if (is_string($one) && $one !== '') {
            $base = $one;
            break;
        }
    }
    return $base;
}

/**
 * 精确匹配某个主机名的正则（前后加界，避免 www.a.com 里的 a.com 被二次替换）。
 */
function tpi_cdn_exact_pattern($host)
{
    return '~(?<![A-Za-z0-9_.\-@])' . preg_quote($host, '~') . '(?![A-Za-z0-9_\-])~i';
}

/**
 * 匹配「主域名 + 任意子域前缀」的正则：a.com / www.a.com / en.a.com 全中。
 * IP 不参与子域前缀匹配，免得把 10.0.127.0.1 这类地址当成自己的变体。
 */
function tpi_cdn_variant_pattern($base)
{
    $prefix = preg_match('~^\d{1,3}(?:\.\d{1,3}){3}$~', $base) ? '' : '(?:[A-Za-z0-9\-]+\.)*';
    return '~(?<![A-Za-z0-9_.\-@])' . $prefix . preg_quote($base, '~') . '(?![A-Za-z0-9_\-])~i';
}

/**
 * canonical 应当使用的站点根（后台「站点与路径 → 目标源」）。
 * 没配就返回空串，表示保持页面自己算好的写法。
 */
function tpi_cdn_canonical_root()
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $root       = '';
    $configured = function_exists('tpi_target_host') ? trim((string) tpi_target_host()) : '';
    if ($configured === '') {
        return $root;
    }
    $host = tpi_cdn_norm_host($configured);
    if ($host === '') {
        return $root;
    }
    $url  = strpos($configured, '://') === false ? 'http://' . $configured : $configured;
    $port = parse_url($url, PHP_URL_PORT);
    if (!empty($port)) {
        $host .= ':' . $port;
    }
    $root = tpi_cdn_public_scheme() . '://' . $host;
    return $root;
}

/**
 * 需要被替换掉的源域名，长的在前，避免子串误伤。
 */
function tpi_cdn_sources($target)
{
    $candidates = tpi_cdn_site_hosts();

    // 访问域名、代理透传的域名都算源域名：CDN 回源时 Host 可能是源站域名，
    // 也可能是 CDN 自己改写过的域名，这些都要换成 X-Forwarded-Host。
    foreach (array('HTTP_HOST', 'HTTP_X_ORIGINAL_HOST') as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $one = tpi_cdn_norm_host($_SERVER[$key]);
        if ($one !== '') {
            $candidates[] = $one;
        }
    }
    if (TPI_CDN_LEGACY !== '') {
        foreach (explode(',', TPI_CDN_LEGACY) as $legacy) {
            $candidates[] = $legacy;
        }
    }

    $list = array();
    foreach ($candidates as $host) {
        $host = tpi_cdn_norm_host($host);
        if ($host === '' || $host === $target) {
            continue;
        }
        $list[$host] = true;
    }

    $list = array_keys($list);
    usort($list, static function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    return $list;
}

/**
 * 把 canonical 先按「目标源」写死，再临时藏起来，整篇替换完放回。
 *
 * 顺序很关键：X-Forwarded-Host 会把文档里所有域名换成访客域名，
 * canonical 必须在那之后仍然停在目标源上，所以先摘出来、替换、再放回。
 * canonical 跟 CDN 没有任何关系，它只认目标源。
 */
function tpi_cdn_mask_canonical($buffer, array &$masked)
{
    if (stripos($buffer, 'canonical') === false) {
        return $buffer;
    }
    $root = tpi_cdn_canonical_root();
    $out  = preg_replace_callback(
        '~<link\b[^>]*\brel\s*=\s*(["\'])canonical\1[^>]*>~i',
        static function (array $m) use (&$masked, $root): string {
            $tag = $m[0];
            if ($root !== '') {
                // 主机名换成目标源，路径原样保留
                $fixed = preg_replace_callback(
                    '~(\bhref\s*=\s*["\'])https?://[^/"\'\s>]*~i',
                    static function (array $mm) use ($root): string {
                        return $mm[1] . $root;
                    },
                    $tag
                );
                if (is_string($fixed)) {
                    $tag = $fixed;
                }
            }
            // 协议跟对外协议一致：CDN 回源是明文 http，不能把 https 站点写成 http
            if (tpi_cdn_public_scheme() === 'https') {
                $fixed = preg_replace('~(\bhref\s*=\s*["\'])http://~i', '$1https://', $tag);
                if (is_string($fixed)) {
                    $tag = $fixed;
                }
            }
            $key          = "\x01TPI_CANON_" . count($masked) . "\x01";
            $masked[$key] = $tag;
            return $key;
        },
        $buffer
    );
    return is_string($out) ? $out : $buffer;
}

/**
 * 只按主机名替换：http/https、//、JSON 里的 \/\/ 都能一次覆盖。
 *
 *   1) 已知源域名（数据库站点域名、本次 Host、历史域名）逐个换掉；
 *   2) 再按「本站主域名 + 任意子域」兜一遍，把 apex / www / en / 其他子域
 *      全部收敛到 X-Forwarded-Host，不依赖文档里出现的具体变体；
 *   3) canonical 最后放回，保持目标源不被覆盖。
 */
function tpi_cdn_apply($buffer, $sources, $target)
{
    if ($target === '' || $buffer === '') {
        return $buffer;
    }

    $masked = array();
    $buffer = tpi_cdn_mask_canonical($buffer, $masked);

    foreach ($sources as $source) {
        if ($source === '' || $source === $target) {
            continue;
        }
        $replaced = preg_replace(tpi_cdn_exact_pattern($source), $target, $buffer);
        if (is_string($replaced)) {
            $buffer = $replaced;
        }
    }

    $base = tpi_cdn_base_domain();
    if ($base !== '') {
        $replaced = preg_replace(tpi_cdn_variant_pattern($base), $target, $buffer);
        if (is_string($replaced)) {
            $buffer = $replaced;
        }
    }

    // 协议对齐：对外是 https 站点时，不能让 http://对外域名 混进来（混内容 + 搜索引擎两个版本）
    if (tpi_cdn_public_scheme() === 'https') {
        $buffer = str_replace('http://' . $target, 'https://' . $target, $buffer);
    }

    if ($masked) {
        $buffer = strtr($buffer, $masked);
    }

    return $buffer;
}

/**
 * 后台 / 登录请求判定。
 */
function tpi_cdn_is_admin_request()
{
    if (defined('WP_ADMIN') && WP_ADMIN) {
        return true;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    foreach (array('/wp-admin/', '/wp-login.php', '/wp-signup.php', '/wp-activate.php') as $path) {
        if ($uri !== '' && stripos($uri, $path) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 当前响应是否值得改写。
 */
function tpi_cdn_content_allowed()
{
    if (!TPI_CDN_ADMIN && tpi_cdn_is_admin_request()) {
        return false;
    }
    $content_type = '';
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0) {
            $content_type = strtolower($header);
        } elseif (stripos($header, 'content-disposition:') === 0 && stripos($header, 'attachment') !== false) {
            return false;
        }
    }
    if ($content_type === '') {
        return true;
    }
    foreach (array('text/html', 'text/xml', 'text/plain', 'text/css', 'application/xml', '+xml', 'application/json', 'javascript', 'ecmascript') as $needle) {
        if (strpos($content_type, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 输出缓冲回调：exit 也会触发，所以能覆盖 sitemap 这种 echo 完就退出的场景。
 * 这里只改域名，不做任何 HTML 结构改写（那些在 inc/output/prefix-replace.php 里）。
 */
function tpi_cdn_buffer($buffer)
{
    if (!is_string($buffer) || $buffer === '') {
        return $buffer;
    }
    $target = tpi_cdn_target();
    if ($target === '' || !tpi_cdn_content_allowed()) {
        return $buffer;
    }

    // inc/output/gzip.php 的 ob_start('ob_gzhandler') 挂在 template_redirect，
    // 是这一层的内层：浏览器发 Accept-Encoding: gzip 时，这里拿到的是压缩后的二进制，
    // 直接替换会被 "\0" 判断挡掉（真实浏览器全都带 gzip，等于整站不替换）。
    // 先解压再改，改完按原样压回去。
    $gzipped = false;
    if (strncmp($buffer, "\x1f\x8b", 2) === 0) {
        $plain = @gzdecode($buffer);
        if (!is_string($plain)) {
            return $buffer; // 解不开就原样放行，不冒险动内容
        }
        $buffer  = $plain;
        $gzipped = true;
    }

    if (strpos($buffer, "\0") !== false) {
        return $gzipped ? (string) gzencode($buffer, 6) : $buffer; // 其它二进制不碰
    }
    // gzip 之外的压缩格式（ob_gzhandler 在只收到 deflate 时会吐 zlib 流）：
    // 压缩数据里必然夹着控制字符，看到就整段放行，宁可不替换也不能改坏。
    if (preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', substr($buffer, 0, 2048))) {
        return $gzipped ? (string) gzencode($buffer, 6) : $buffer;
    }

    $sources = tpi_cdn_sources($target);
    $output  = tpi_cdn_apply($buffer, $sources, $target);

    if ($output !== $buffer) {
        if (function_exists('header_remove')) {
            header_remove('Content-Length'); // 长度变了，旧的长度头会把响应截断
        }
    }
    if (!headers_sent()) {
        // 只带识别到的域名：能确认这次请求有没有拿到 X-Forwarded-Host
        header('X-TPI-Cdn: ' . $target);
    }
    if (TPI_CDN_DEBUG && $output !== $buffer && !headers_sent()) {
        header('X-CDN-Hosts: ' . $target . ' <- ' . implode(',', $sources));
    }

    if ($gzipped) {
        $packed = gzencode($output, 6);
        if (is_string($packed)) {
            $output = $packed;
        }
    }

    return $output;
}

/**
 * 开缓冲。after_setup_theme 早于 init / template_redirect，
 * 所以 tpi_sitemap_serve()（template_redirect 优先级 1 + exit）也能抓到。
 */
function tpi_cdn_boot()
{
    static $started = false;
    if ($started) {
        return;
    }
    if (!TPI_CDN_ADMIN && tpi_cdn_is_admin_request()) {
        return;
    }
    if (tpi_cdn_target() === '') {
        return; // 直连源站，零开销
    }
    ob_start('tpi_cdn_buffer');
    $started = true;
}
add_action('after_setup_theme', 'tpi_cdn_boot', 0);
add_action('init', 'tpi_cdn_boot', 0);

/**
 * 源头修正：home_url() / site_url() 直接返回对外域名。
 * sitemap 的 <loc>、hreflang、索引就是靠这两个函数拼的。
 * 只在「访问域名本身就是对外域名」时启用；若命中来自 CDN 转发头（Host 还是源站），
 * 改源码会触发 WP 规范化 301，这种情况交给输出缓冲兜底。
 */
add_filter('home_url', 'tpi_cdn_filter_url', 0);
add_filter('site_url', 'tpi_cdn_filter_url', 0);
function tpi_cdn_filter_url($url)
{
    if (!is_string($url) || $url === '') {
        return $url;
    }
    if (!TPI_CDN_ADMIN && tpi_cdn_is_admin_request()) {
        return $url;
    }
    $target = tpi_cdn_target();
    if ($target === '') {
        return $url;
    }
    $current = tpi_cdn_norm_host(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    if ($current !== $target) {
        return $url;
    }
    return tpi_cdn_apply($url, tpi_cdn_sources($target), $target);
}

/**
 * 跳转地址同步替换。
 */
add_filter('wp_redirect', 'tpi_cdn_filter_redirect', 10, 2);
function tpi_cdn_filter_redirect($location, $status)
{
    if (!is_string($location) || $location === '') {
        return $location;
    }
    $target = tpi_cdn_target();
    if ($target === '') {
        return $location;
    }
    return tpi_cdn_apply($location, tpi_cdn_sources($target), $target);
}
