<?php
/**
 * 资源更新：自定义 CSS / JS + 资源版本号
 *
 * 解决两件事：
 *   1) 想加一点自己的样式/脚本，不必改模板；
 *   2) 改完样式后不想一个个页面按 Ctrl+F5，改个版本号让浏览器重新拉资源。
 *
 * 存法：
 *   option  tpi_assets_version  资源版本号，默认 1.0.0，全站 CSS/JS 的 ?ver= 都用它
 *   option  tpi_assets_css      自定义 CSS 源码
 *   option  tpi_assets_js       自定义 JS 源码
 *   文件    assets/css/add-custom.css / assets/js/add-custom.js（主题目录内）
 *
 * 为什么放主题的 assets 目录：
 *   和主题自己的样式/脚本在同一个目录、同一个地址前缀（tpi_asset_base()），
 *   Nginx 的 /assets 一条规则就全带缓存，不会出现「主题样式缓存 30 天、
 *   自定义样式没有缓存」这种两套策略。
 *
 * 版本号完全手动：只有你在后台改了「版本号」并保存，地址才会变。改内容但没改
 * 版本号时，浏览器可能仍用旧缓存（assets 目录是强缓存），记得一并改一下。
 * 主题更新覆盖掉这两个文件时，读不到文件会按 option 里的源码自动重建。
 *
 * 内容留空保存 = 删除对应文件，前台不再引用。
 * 后台页面在 tables/assets.php（Osiera → 资源更新）。
 */

if (!defined('TPI_ASSET_VERSION_OPTION')) {
    define('TPI_ASSET_VERSION_OPTION', 'tpi_assets_version');
}
if (!defined('TPI_ASSET_DIR_NAME')) {
    // 主题内的资源目录名（css / js 都在这下面）
    define('TPI_ASSET_DIR_NAME', 'assets');
}
if (!defined('TPI_ASSET_FILE_BASE')) {
    // 生成的文件名：add-custom.css / add-custom.js
    define('TPI_ASSET_FILE_BASE', 'add-custom');
}
// 老版本用的文件名，读不到新文件时自动改名过来
if (!defined('TPI_ASSET_LEGACY_BASE')) {
    define('TPI_ASSET_LEGACY_BASE', 'custom');
}
if (!defined('TPI_ASSET_LEGACY_DIR')) {
    // 老版本生成的文件在上传目录里，读到就搬到主题目录，避免两份各留一份
    define('TPI_ASSET_LEGACY_DIR', 'osiera-assets');
}
if (!defined('TPI_ASSET_HANDLE')) {
    // 前台句柄，样式与脚本共用（各自注册表独立）。
    // 最终 id：osiera-add-custom-css / osiera-add-custom-js
    define('TPI_ASSET_HANDLE', 'osiera-add-custom');
}

if (!function_exists('tpi_asset_version')) {
    /**
     * 当前资源版本号（全站 CSS/JS 的 ?ver=）
     *
     * 空值或非法值回落到 1.0.0，避免出现 ?ver= 或带空格的地址。
     */
    function tpi_asset_version(): string
    {
        // 不缓存：后台保存后同一次请求里要立刻读到新值（get_option 自己有对象缓存，开销可忽略）
        $raw = trim((string) get_option(TPI_ASSET_VERSION_OPTION, ''));
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $raw)) {
            $raw = '1.0.0';
        }
        return $raw;
    }
}

if (!function_exists('tpi_asset_code')) {
    /** 取某个类型的自定义源码（css|js） */
    function tpi_asset_code(string $type): string
    {
        $type = $type === 'js' ? 'js' : 'css';
        return (string) get_option('tpi_assets_' . $type, '');
    }
}

if (!function_exists('tpi_asset_type_dir')) {
    /** 类型 -> 子目录名（css|js） */
    function tpi_asset_type_dir(string $type): string
    {
        return $type === 'js' ? 'js' : 'css';
    }
}

if (!function_exists('tpi_asset_dir')) {
    /**
     * 自定义资源目录与 URL（主题内的 assets 目录）
     *
     * 地址走 tpi_asset_base()，也就是「站点与路径 → 主题资源」的前缀：
     * 原生是 {主题地址}/assets，自定义前缀就是例如 /assets。
     *
     * @return array{dir:string,url:string}
     */
    function tpi_asset_dir(): array
    {
        $base_url = function_exists('tpi_asset_base')
            ? (string) tpi_asset_base()
            : get_template_directory_uri() . '/assets';

        return [
            'dir' => trailingslashit(get_template_directory()) . TPI_ASSET_DIR_NAME,
            'url' => trailingslashit($base_url),
        ];
    }
}

if (!function_exists('tpi_asset_file_path')) {
    /** 某个类型的文件绝对路径：assets/css/add-custom.css */
    function tpi_asset_file_path(string $type): string
    {
        $dir = tpi_asset_dir();
        return $dir['dir'] . '/' . tpi_asset_type_dir($type) . '/' . TPI_ASSET_FILE_BASE . '.' . tpi_asset_type_dir($type);
    }
}

if (!function_exists('tpi_asset_legacy_paths')) {
    /**
     * 老位置（上传目录）里的残留文件，仅用于迁移与清尾
     *
     * 老版本写在 uploads/osiera-assets/ 下，文件名先后用过 custom.* 和 add-custom.*。
     *
     * @return string[]
     */
    function tpi_asset_legacy_paths(string $type): array
    {
        $type    = tpi_asset_type_dir($type);
        $uploads = wp_get_upload_dir();
        $base    = trailingslashit((string) ($uploads['basedir'] ?? ''));
        $dir     = $base . TPI_ASSET_LEGACY_DIR;

        return [
            $dir . '/' . TPI_ASSET_FILE_BASE . '.' . $type,
            $dir . '/' . TPI_ASSET_LEGACY_BASE . '.' . $type,
        ];
    }
}

if (!function_exists('tpi_asset_cleanup_legacy')) {
    /**
     * 清掉老位置的文件；整个目录空了就一起删掉
     *
     * @param string[] $paths
     */
    function tpi_asset_cleanup_legacy(array $paths): void
    {
        $dirs = [];
        foreach ($paths as $path) {
            if (is_file($path) && @unlink($path)) {
                $dirs[dirname($path)] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            $items = @scandir($dir);
            if (is_array($items) && count($items) === 2) { // 只剩 . 和 ..
                @rmdir($dir);
            }
        }
    }
}

if (!function_exists('tpi_asset_migrate_legacy')) {
    /**
     * 老位置的文件搬到主题目录
     *
     * 只在主题目录里读不到文件时搬内容；两种情况都清掉老文件，
     * 免得同一份代码在两处各留一份、以后分不清哪个在生效。
     */
    function tpi_asset_migrate_legacy(string $type): void
    {
        $type     = tpi_asset_type_dir($type);
        $file     = tpi_asset_file_path($type);
        $legacies = tpi_asset_legacy_paths($type);

        $found = false;
        foreach ($legacies as $legacy) {
            if (!is_file($legacy)) {
                continue;
            }
            if (!is_file($file)) {
                $dir = dirname($file);
                if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                    continue;
                }
                if (!@copy($legacy, $file)) {
                    continue;
                }
            }
            $found = true;
        }

        if ($found) {
            tpi_asset_cleanup_legacy($legacies);
        }
    }
}

if (!function_exists('tpi_asset_file_url')) {
    /**
     * 某个类型的可访问地址
     *
     * 文件不存在时按 option 里的源码补写一份（主题更新会覆盖 assets 目录，
     * 源码还在就不会丢）；补不出来就返回空字符串，前台不引用。
     */
    function tpi_asset_file_url(string $type): string
    {
        static $tried = [];

        $type = tpi_asset_type_dir($type);
        $path = tpi_asset_file_path($type);

        if (!is_file($path)) {
            tpi_asset_migrate_legacy($type);
        }
        if (!is_file($path) && empty($tried[$type])) {
            $tried[$type] = true;
            $code = tpi_asset_code($type);
            if (trim($code) !== '') {
                tpi_asset_write($type, $code);
            }
        }
        if (!is_file($path)) {
            return '';
        }

        $dir = tpi_asset_dir();
        return $dir['url'] . $type . '/' . TPI_ASSET_FILE_BASE . '.' . $type;
    }
}

if (!function_exists('tpi_asset_write')) {
    /**
     * 把源码写进文件；内容为空则删除文件
     *
     * @return bool 写入/删除是否成功
     */
    function tpi_asset_write(string $type, string $code): bool
    {
        $type = tpi_asset_type_dir($type);
        $file = tpi_asset_file_path($type);
        $code = (string) $code;

        if (trim($code) === '') {
            tpi_asset_cleanup_legacy(tpi_asset_legacy_paths($type));
            if (is_file($file)) {
                return @unlink($file);
            }
            return true;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        // 直接写文件，不加 BOM、不转义：CSS/JS 原样输出
        $ok = @file_put_contents($file, $code, LOCK_EX) !== false;
        if ($ok) {
            tpi_asset_cleanup_legacy(tpi_asset_legacy_paths($type));
        }
        return $ok;
    }
}

if (!function_exists('tpi_asset_save')) {
    /**
     * 保存版本号与自定义代码
     *
     * 版本号只用表单里填的值，不做任何自动改动：写什么就是什么，留空回落 1.0.0。
     * 想让访客重新加载，改内容时把版本号一起改一下。
     *
     * @param array{css:string,js:string,version:string} $data
     * @return array{version:string,ok:bool}
     */
    function tpi_asset_save(array $data): array
    {
        $version = trim((string) ($data['version'] ?? ''));
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $version)) {
            $version = '1.0.0';
        }

        $ok = true;
        foreach (['css', 'js'] as $type) {
            $code = (string) ($data[$type] ?? '');
            update_option('tpi_assets_' . $type, $code, false);
            if (!tpi_asset_write($type, $code)) {
                $ok = false;
            }
        }

        update_option(TPI_ASSET_VERSION_OPTION, $version, false);

        return ['version' => $version, 'ok' => $ok];
    }
}

if (!function_exists('tpi_asset_enqueue_custom')) {
    /**
     * 前台引用自定义 CSS / JS
     *
     * 优先级 30：排在主题自己的样式/脚本之后，自定义内容能覆盖默认样式。
     */
    function tpi_asset_enqueue_custom(): void
    {
        $version = tpi_asset_version();

        // 句柄不加 -css / -js 后缀：WordPress 输出时自己会拼（style 拼 -css、script 拼 -js），
        // 写了就变成 osiera-add-custom-css-css 这种双后缀。样式与脚本各用一套注册表，同名不冲突。
        $css = tpi_asset_file_url('css');
        if ($css !== '') {
            wp_enqueue_style(TPI_ASSET_HANDLE, $css, [], $version);
        }

        $js = tpi_asset_file_url('js');
        if ($js !== '') {
            wp_enqueue_script(TPI_ASSET_HANDLE, $js, [], $version, true);
        }
    }
}
add_action('wp_enqueue_scripts', 'tpi_asset_enqueue_custom', 30);

/* ============================================================================
 * JS 压缩混淆（js-dist）
 *
 * 做什么：
 *   把主题 assets/js 下的脚本做字符串数组混淆并压掉注释空白，输出到同级的
 *   assets/js-dist，前台 <script src> 改指 js-dist；源文件保持原样，随时可以重新生成。
 *
 * 开关（资源更新页里的「本站启用」）：
 *   启用   —— 本站所有访问都引用 js-dist；
 *   未启用 —— 只有走 CDN 访问时才引用 js-dist，直连源站仍用源文件（方便线上调试）。
 *   默认启用。
 *
 * 为什么只换地址、不改文件名：
 *   模板里用的是 tpi_asset('/js/xxx.js') 与 wp_enqueue_script，全部经过
 *   script_loader_src 过滤器，地址一换就生效，模板一行都不用动。
 * ========================================================================== */

if (!defined('TPI_JSDIST_DIR_NAME')) {
    // 主题 assets 下的输出目录名
    define('TPI_JSDIST_DIR_NAME', 'js-dist');
}
if (!defined('TPI_JSDIST_ENABLED_OPTION')) {
    define('TPI_JSDIST_ENABLED_OPTION', 'tpi_assets_jsdist_enabled');
}
if (!defined('TPI_JSDIST_BUILD_OPTION')) {
    // 上次生成结果：time / files / before / after / fallback
    define('TPI_JSDIST_BUILD_OPTION', 'tpi_assets_jsdist_build');
}
if (!function_exists('tpi_jsdist_obfuscator')) {
    /** 引入纯 PHP 混淆器；引不到就返回 false，构建时退回只压缩 */
    function tpi_jsdist_obfuscator(): bool
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }
        $file = __DIR__ . '/js-obfuscator.php';
        if (!is_file($file)) {
            return $loaded = false;
        }
        require_once $file;

        return $loaded = class_exists('TPI_JS_Obfuscator');
    }
}

if (!function_exists('tpi_jsdist_dirs')) {
    /**
     * 源目录、输出目录、输出地址
     *
     * @return array{src:string,dist:string,url:string}
     */
    function tpi_jsdist_dirs(): array
    {
        $assets = trailingslashit(get_template_directory()) . TPI_ASSET_DIR_NAME;
        $base   = function_exists('tpi_asset_base')
            ? rtrim((string) tpi_asset_base(), '/')
            : rtrim(get_template_directory_uri(), '/') . '/' . TPI_ASSET_DIR_NAME;

        return [
            'src'  => $assets . '/js',
            'dist' => $assets . '/' . TPI_JSDIST_DIR_NAME,
            'url'  => $base . '/' . TPI_JSDIST_DIR_NAME,
        ];
    }
}

if (!function_exists('tpi_jsdist_enabled')) {
    /** 本站是否启用（默认启用；option 里存 '1' / '0'） */
    function tpi_jsdist_enabled(): bool
    {
        return (string) get_option(TPI_JSDIST_ENABLED_OPTION, '1') !== '0';
    }
}

if (!function_exists('tpi_jsdist_is_cdn')) {
    /**
     * 这次请求是不是走 CDN / 反代进来的
     *
     * 判据：代理透传了 Host，或者访问域名和数据库 siteurl 不一致（CDN 域名与源站域名不同）。
     * 直连源站（Host 就是 siteurl）视为「不是 CDN」。
     */
    function tpi_jsdist_is_cdn(): bool
    {
        $site = [];
        foreach (['home', 'siteurl'] as $option) {
            $host = (string) wp_parse_url((string) get_option($option, ''), PHP_URL_HOST);
            if ($host !== '') {
                $site[] = strtolower($host);
            }
        }

        foreach (['HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST'] as $key) {
            if (!empty($_SERVER[$key])) {
                return true;
            }
        }

        $current = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $current = (string) preg_replace('~:\d+$~', '', $current);
        if ($current === '') {
            return false;
        }
        if (strpos($current, 'localhost') === 0 || preg_match('~^\d+\.\d+\.\d+\.\d+$~', $current)) {
            return false;
        }

        return !in_array($current, $site, true);
    }
}

if (!function_exists('tpi_jsdist_active')) {
    /** 本次请求是否引用 js-dist：启用就一直引用，未启用则只在 CDN 访问时引用 */
    function tpi_jsdist_active(): bool
    {
        static $active = null;
        if ($active === null) {
            $active = tpi_jsdist_enabled() || tpi_jsdist_is_cdn();
        }
        return $active;
    }
}

if (!function_exists('tpi_jsdist_need_space')) {
    /** 两个字符之间不加空格会不会粘成一个 token */
    function tpi_jsdist_need_space(string $prev, string $next): bool
    {
        if ($prev === '' || $next === '') {
            return false;
        }
        $word = static function (string $ch): bool {
            return (bool) preg_match('~[A-Za-z0-9_$\x80-\xff]~', $ch);
        };
        if ($word($prev) && $word($next)) {
            return true;
        }
        // x + +y / x - -y 这类两个同号运算符相邻时必须留空格
        if ($prev === $next && ($prev === '+' || $prev === '-')) {
            return true;
        }
        // 1 .toString() 不能压成 1.toString()
        if ($next === '.' && preg_match('~[0-9]~', $prev)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('tpi_jsdist_minify')) {
    /**
     * 保守压缩：去注释、去缩进、合并空白、删多余换行
     *
     * 故意做了三件「不省」的事，换取不改语义：
     *   1) 字符串 / 模板字符串原样保留（含其中的注释符）；
     *   2) 两个标识符之间一定留一个空格；
     *   3) 可能影响自动分号插入（ASI）的换行保留，只在明确安全的位置删。
     * 所以不追求极限压缩率，界面里也会显示压缩前后体积。
     */
    function tpi_jsdist_minify(string $code): string
    {
        $len     = strlen($code);
        $out     = '';
        $i       = 0;
        $prevSig = ''; // 上一个有意义的字符，判断 `/` 是正则还是除号
        $dropSet = '{;,([:|&+-*/%<>=!~?';

        while ($i < $len) {
            $c = $code[$i];
            $n = ($i + 1 < $len) ? $code[$i + 1] : '';

            // 块注释 → 用一个空格顶替，避免 token 粘连
            if ($c === '/' && $n === '*') {
                $end = strpos($code, '*/', $i + 2);
                $i   = ($end === false) ? $len : $end + 2;
                if ($out !== '' && substr($out, -1) !== ' ') {
                    $out .= ' ';
                }
                continue;
            }

            // 行注释 → 整行丢弃（换行交给空白分支处理）
            if ($c === '/' && $n === '/') {
                $end = strpos($code, "\n", $i + 2);
                $i   = ($end === false) ? $len : $end;
                continue;
            }

            // 字符串 / 模板字符串：原样搬到结尾
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $out  .= $c;
                $i++;
                while ($i < $len) {
                    $ch   = $code[$i];
                    $out .= $ch;
                    if ($ch === '\\' && $i + 1 < $len) {
                        $out .= $code[$i + 1];
                        $i   += 2;
                        continue;
                    }
                    $i++;
                    if ($ch === $quote) {
                        break;
                    }
                }
                $prevSig = $quote;
                continue;
            }

            // 正则字面量：按前一个字符判断，扫到未转义的 / 为止
            if ($c === '/' && ($prevSig === '' || strpos($dropSet, $prevSig) !== false)) {
                $out .= $c;
                $i++;
                $inClass = false;
                while ($i < $len) {
                    $ch   = $code[$i];
                    $out .= $ch;
                    if ($ch === '\\' && $i + 1 < $len) {
                        $out .= $code[$i + 1];
                        $i   += 2;
                        continue;
                    }
                    $i++;
                    if ($ch === '[') {
                        $inClass = true;
                        continue;
                    }
                    if ($ch === ']') {
                        $inClass = false;
                        continue;
                    }
                    if ($ch === '/' && !$inClass) {
                        break;
                    }
                    if ($ch === "\n") {
                        break; // 不闭合就退回当除号处理，别把后面全吃掉
                    }
                }
                $prevSig = '/';
                continue;
            }

            // 空白：合并成「一个空格 / 一个换行 / 什么都不留」
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $j          = $i;
                $hasNewline = false;
                while ($j < $len) {
                    $w = $code[$j];
                    if ($w === ' ' || $w === "\t" || $w === "\r") {
                        $j++;
                        continue;
                    }
                    if ($w === "\n") {
                        $hasNewline = true;
                        $j++;
                        continue;
                    }
                    break;
                }
                $nextChar = ($j < $len) ? $code[$j] : '';
                $prevChar = ($out === '') ? '' : substr($out, -1);

                if (tpi_jsdist_need_space($prevChar, $nextChar)) {
                    $out .= ' ';
                } elseif ($hasNewline && $nextChar !== '') {
                    $safeDrop = ($prevChar !== '' && strpos($dropSet, $prevChar) !== false)
                        || strpos('})];,:', $nextChar) !== false;
                    if (!$safeDrop) {
                        $out .= "\n";
                    }
                }
                $i = $j;
                continue;
            }

            $out .= $c;
            if ($c !== ' ' && $c !== "\n") {
                $prevSig = $c;
            }
            $i++;
        }

        $out = (string) preg_replace("~[ \t]+\n~", "\n", $out);
        $out = (string) preg_replace("~\n{2,}~", "\n", $out);

        return trim($out);
    }
}

if (!function_exists('tpi_jsdist_build')) {
    /**
     * 重新生成 js-dist
     *
     * 源目录里删掉的脚本，输出目录里也一并删掉，避免残留旧文件被引用。
     *
     * @return array{ok:bool,files:int,before:int,after:int,removed:int,time:int}
     */
    function tpi_jsdist_build(): array
    {
        $dirs = tpi_jsdist_dirs();
        $src  = $dirs['src'];
        $dist = $dirs['dist'];

        $result = ['ok' => false, 'files' => 0, 'before' => 0, 'after' => 0, 'removed' => 0, 'fallback' => 0, 'time' => time()];

        if (!is_dir($src)) {
            return $result;
        }
        if (!is_dir($dist) && !wp_mkdir_p($dist)) {
            return $result;
        }

        $made = [];
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Exception $e) {
            return $result;
        }

        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $rel  = ltrim(substr($path, strlen($src)), '/');
            if ($rel === '') {
                continue;
            }
            $code = (string) @file_get_contents($path);
            if ($code === '') {
                continue;
            }

            // 先混淆；混淆器自检不通过时会抛异常，这时退回只压缩，绝不写出坏脚本
            $min = '';
            if (tpi_jsdist_obfuscator()) {
                try {
                    $min = TPI_JS_Obfuscator::run($code, [
                        'mangle' => (bool) apply_filters('tpi_jsdist_mangle', true),
                    ]);
                } catch (Throwable $e) {
                    $min = '';
                    $result['fallback']++;
                }
            }
            if ($min === '') {
                $min = tpi_jsdist_minify($code);
            }
            if ($min === '') {
                continue;
            }
            $target = $dist . '/' . $rel;
            $tdir   = dirname($target);
            if (!is_dir($tdir) && !wp_mkdir_p($tdir)) {
                continue;
            }
            if (@file_put_contents($target, $min, LOCK_EX) === false) {
                continue;
            }
            $made[$rel] = true;
            $result['files']++;
            $result['before'] += strlen($code);
            $result['after']  += strlen($min);
        }

        // 清掉源里已经不存在的文件
        try {
            $it2 = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it2 as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $rel  = ltrim(substr($path, strlen($dist)), '/');
                if ($rel !== '' && !isset($made[$rel]) && @unlink($path)) {
                    $result['removed']++;
                }
            }
        } catch (Exception $e) {
            // 清理失败不影响主流程
        }

        $result['ok'] = $result['files'] > 0;
        update_option(TPI_JSDIST_BUILD_OPTION, $result, false);

        return $result;
    }
}

if (!function_exists('tpi_jsdist_state')) {
    /**
     * 界面用状态：开关、上次生成结果、磁盘上的实际文件
     *
     * @return array{enabled:bool,build:array,files:int,size:int}
     */
    function tpi_jsdist_state(): array
    {
        $build = get_option(TPI_JSDIST_BUILD_OPTION, []);
        $build = is_array($build) ? $build : [];

        $dirs  = tpi_jsdist_dirs();
        $files = 0;
        $size  = 0;
        if (is_dir($dirs['dist'])) {
            foreach ((array) glob($dirs['dist'] . '/*.js') as $one) {
                if (is_file($one)) {
                    $files++;
                    $size += (int) filesize($one);
                }
            }
        }

        return [
            'enabled' => tpi_jsdist_enabled(),
            'build'   => $build,
            'files'   => $files,
            'size'    => $size,
        ];
    }
}

if (!function_exists('tpi_jsdist_rewrite')) {
    /**
     * 把 .../js/xxx.js 换成 .../js-dist/xxx.js
     *
     * 只在两种前提下换：
     *   1) 地址前缀就是主题的 assets 根（tpi_asset_base 或主题目录 /assets），
     *      插件、核心的 /js/ 不会被误伤；
     *   2) 输出目录里真的存在这个文件，没生成过就老实走源文件。
     */
    function tpi_jsdist_rewrite(string $url): string
    {
        if ($url === '' || strpos($url, '/js/') === false) {
            return $url;
        }
        if (!tpi_jsdist_active()) {
            return $url;
        }

        $dirs    = tpi_jsdist_dirs();
        $bases   = [];
        if (function_exists('tpi_asset_base')) {
            $bases[] = rtrim((string) tpi_asset_base(), '/');
        }
        $bases[] = rtrim(get_template_directory_uri(), '/') . '/' . TPI_ASSET_DIR_NAME;
        $bases   = array_values(array_unique(array_filter($bases, static function ($one) {
            return $one !== '';
        })));

        $rewritten = preg_replace_callback(
            '~^(.*?)/js/([^?#]+\.js)(\?[^#]*)?$~i',
            static function (array $m) use ($dirs, $bases): string {
                $prefix = $m[1];
                if (!in_array($prefix, $bases, true)) {
                    return $m[0];
                }
                $rel = str_replace('\\', '/', $m[2]);
                if ($rel === '' || strpos($rel, '..') !== false) {
                    return $m[0];
                }
                if (!is_file($dirs['dist'] . '/' . $rel)) {
                    return $m[0];
                }
                // 源文件比产物新：说明刚改过还没重建（例如只传了源文件、没去后台点保存），
                // 这时先用源文件，宁可少一层混淆，也不要让前台一直跑旧代码
                $src_file = $dirs['src'] . '/' . $rel;
                if (is_file($src_file) && filemtime($src_file) > filemtime($dirs['dist'] . '/' . $rel)) {
                    return $m[0];
                }
                return $prefix . '/' . TPI_JSDIST_DIR_NAME . '/' . $m[2] . ($m[3] ?? '');
            },
            $url
        );

        return is_string($rewritten) ? $rewritten : $url;
    }
}

if (!function_exists('tpi_jsdist_filter_src')) {
    /** 前台脚本地址统一走这里换目录；后台保持源文件，方便调试 */
    function tpi_jsdist_filter_src($src, $handle = '')
    {
        if (!is_string($src) || $src === '') {
            return $src;
        }
        if (is_admin()) {
            return $src;
        }
        return tpi_jsdist_rewrite($src);
    }
}
add_filter('script_loader_src', 'tpi_jsdist_filter_src', 20, 2);
