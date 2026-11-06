<?php
/**
 * 允许上传 SVG
 *
 * WordPress 默认不放行 image/svg+xml，因为 SVG 本质是 XML，里面能塞 <script>、
 * onclick 之类的东西，浏览器直接打开就等于执行了同源脚本。但语言国旗、页面图标、
 * 导航图标都用 svg，所以这里放行，同时把关三件事：
 *
 *   1) 只对管理员开放（manage_options），其它角色维持 WordPress 默认限制；
 *   2) 文件类型判断：finfo 常把 svg 认成 text/plain，这里按后缀放行，
 *      内容是否真的是 svg 交给第 3 步检查；
 *   3) 上传前把文件洗干净：script / foreignObject / iframe 等标签、on* 事件属性、
 *      javascript: 链接、可用来改事件属性的动画指令全部去掉；洗不干净就拒收，
 *      原文件不会落地。
 *
 * 关掉这个文件就等于恢复 WordPress 默认（不允许上传 svg）。
 */

if (!function_exists('tpi_svg_allowed')) {
    /** 当前账号能不能传 svg（只给管理员） */
    function tpi_svg_allowed(): bool
    {
        return current_user_can('manage_options');
    }
}

if (!function_exists('tpi_svg_strip_doctype')) {
    /** 去掉 DOCTYPE（含内部子集），避免通过实体定义做注入 */
    function tpi_svg_strip_doctype(string $svg): string
    {
        return (string) preg_replace('/<!DOCTYPE[^>\[]*(?:\[[^\]]*\][^>]*)?>/is', '', $svg, 1);
    }
}

if (!function_exists('tpi_svg_is_dangerous_animation')) {
    /**
     * 动画指令能不能用来改事件属性
     *
     * <set attributeName="onmouseover" to="alert(1)"/> 是 svg 里经典的绕法，
     * 这类一律不留。
     */
    function tpi_svg_is_dangerous_animation(DOMElement $el): bool
    {
        $tag = strtolower($el->nodeName);
        if ($tag === 'set') {
            return true;
        }
        if (!in_array($tag, ['animate', 'animatetransform', 'animatemotion'], true)) {
            return false;
        }
        $attr = strtolower(trim((string) $el->getAttribute('attributeName')));
        if ($attr !== '' && strpos($attr, 'on') === 0) {
            return true;
        }
        $to = strtolower((string) $el->getAttribute('to'));
        return strpos($to, 'javascript:') !== false || strpos($to, 'vbscript:') !== false;
    }
}

if (!function_exists('tpi_svg_clean_attributes')) {
    /** 删掉危险属性：on* 事件、javascript: 链接、带 expression / @import 的内联样式 */
    function tpi_svg_clean_attributes(DOMElement $el): void
    {
        if (!$el->hasAttributes()) {
            return;
        }
        $remove = [];
        foreach ($el->attributes as $attr) {
            $name  = strtolower((string) $attr->nodeName);
            $value = (string) $attr->nodeValue;

            if (strpos($name, 'on') === 0) {
                $remove[] = $attr->nodeName;
                continue;
            }
            if (in_array($name, ['href', 'xlink:href', 'src', 'from', 'to', 'values'], true)) {
                $flat = strtolower((string) preg_replace('/\s+/', '', $value));
                if (strpos($flat, 'javascript:') === 0 || strpos($flat, 'vbscript:') === 0
                    || strpos($flat, 'data:text/html') === 0) {
                    $remove[] = $attr->nodeName;
                    continue;
                }
            }
            if ($name === 'style' && preg_match('/(expression\s*\(|javascript:|@import|behavior\s*:)/i', $value)) {
                $remove[] = $attr->nodeName;
            }
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }
}

if (!function_exists('tpi_svg_clean_css')) {
    /** 清掉 <style> 里能执行或能外联的写法（@import、expression、javascript: url） */
    function tpi_svg_clean_css(string $css): string
    {
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $css);
        $css = (string) preg_replace('/behavior\s*:[^;}]*;?/i', '', $css);
        return (string) preg_replace('/(javascript|vbscript)\s*:/i', '', $css);
    }
}

if (!function_exists('tpi_svg_clean_node')) {
    /** 递归清洗节点 */
    function tpi_svg_clean_node(DOMElement $el): void
    {
        tpi_svg_clean_attributes($el);

        // 先收集再处理：边遍历边删会漏掉后面的兄弟节点
        $children = [];
        foreach ($el->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);
                if (in_array($tag, ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener'], true)
                    || tpi_svg_is_dangerous_animation($child)) {
                    $el->removeChild($child);
                    continue;
                }
                if ($tag === 'style') {
                    // 样式本身要留（设计软件导出的 svg 常用 class），只清危险写法
                    $child->nodeValue = tpi_svg_clean_css((string) $child->textContent);
                    continue;
                }
                tpi_svg_clean_node($child);
            } elseif ($child instanceof DOMComment) {
                // 注释里也可能藏 <script>，直接删
                $el->removeChild($child);
            }
        }
    }
}

if (!function_exists('tpi_svg_sanitize_fallback')) {
    /** DOM 不可用时的兜底清洗（正则版，够用但不处理命名空间） */
    function tpi_svg_sanitize_fallback(string $svg): string
    {
        $svg = (string) preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $svg);
        $svg = (string) preg_replace('#<script\b[^>]*/?>#is', '', $svg);
        $svg = (string) preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject\s*>#is', '', $svg);
        $svg = (string) preg_replace('#<foreignObject\b[^>]*/?>#is', '', $svg);
        $svg = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg);
        $svg = (string) preg_replace('#(href|xlink:href|src)\s*=\s*(["\']?)\s*(javascript|vbscript):[^"\'>\s]*#i', 'href="#"', $svg);
        $svg = (string) preg_replace('/@import[^;]*;?/i', '', $svg);
        $svg = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $svg);
        return str_ireplace(['javascript:', 'vbscript:'], '', $svg);
    }
}

if (!function_exists('tpi_svg_sanitize_markup')) {
    /**
     * 清洗一段 svg 源码
     *
     * 优先用 DOM 解析（能正确处理命名空间、自闭合、大小写），解析失败再走正则。
     * 返回空字符串表示这段内容不是可用的 svg。
     */
    function tpi_svg_sanitize_markup(string $svg): string
    {
        $svg = tpi_svg_strip_doctype($svg);

        if (class_exists('DOMDocument')) {
            $prev = libxml_use_internal_errors(true);
            $doc  = new DOMDocument();
            $doc->preserveWhiteSpace = true;
            // 加 LIBXML_NONET：不联网取 DTD，避免 XXE
            $loaded = $doc->loadXML($svg, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            $root = $doc->documentElement;
            if ($loaded && $root instanceof DOMElement && strtolower($root->nodeName) === 'svg') {
                tpi_svg_clean_node($root);
                $out = (string) $doc->saveXML($root);
                if ($out !== '' && stripos($out, '<svg') !== false) {
                    return $out;
                }
            }
        }

        $out = tpi_svg_sanitize_fallback($svg);
        return stripos($out, '<svg') !== false ? $out : '';
    }
}

if (!function_exists('tpi_svg_sanitize_file')) {
    /**
     * 就地清洗上传到临时目录的 svg 文件
     *
     * @return true|WP_Error
     */
    function tpi_svg_sanitize_file(string $path)
    {
        $error = new WP_Error(
            'tpi_svg_invalid',
            tpi_admin_ui('media.svg.rejected')
        );

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $error;
        }
        // 先确认内容里真有 svg 标签，防止把别的文件改后缀骗过去
        if (stripos($raw, '<svg') === false) {
            return $error;
        }

        $clean = tpi_svg_sanitize_markup($raw);
        if ($clean === '') {
            return $error;
        }
        // 落盘前再查一遍：还有脚本 / 外联样式就再洗一次，洗完还有就拒收
        if (preg_match('/<script\b|javascript:|@import|expression\s*\(/i', $clean)) {
            $clean = tpi_svg_sanitize_fallback($clean);
            if (preg_match('/<script\b|javascript:|@import|expression\s*\(/i', $clean)) {
                return $error;
            }
        }

        return @file_put_contents($path, $clean) !== false ? true : $error;
    }
}

// 1) 放行 svg 后缀（仅管理员）
add_filter('upload_mimes', function ($mimes) {
    if (tpi_svg_allowed()) {
        $mimes['svg'] = 'image/svg+xml';
    }
    return $mimes;
});

// 2) 修正真实类型判断：finfo 常把 svg 认成 text/plain，导致「不允许上传该文件类型」
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    if (!tpi_svg_allowed()) {
        return $data;
    }
    if (strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION)) !== 'svg') {
        return $data;
    }
    if (!empty($data['ext']) && !empty($data['type'])) {
        return $data;
    }
    return [
        'ext'             => 'svg',
        'type'            => 'image/svg+xml',
        'proper_filename' => $data['proper_filename'] ?? false,
    ];
}, 10, 4);

// 3) 上传前洗干净（此时文件还在临时目录，洗完再让 WordPress 落盘）
//    WordPress 用动态钩子：媒体库上传走 wp_handle_upload_prefilter，
//    从远程地址/路径搬文件走 wp_handle_sideload_prefilter，两个都要挂。
if (!function_exists('tpi_svg_upload_prefilter')) {
    function tpi_svg_upload_prefilter($file)
    {
        if (!is_array($file) || empty($file['tmp_name']) || empty($file['name'])) {
            return $file;
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'svg') {
            return $file;
        }
        if (!tpi_svg_allowed()) {
            $file['error'] = tpi_admin_ui('media.svg.no-permission');
            return $file;
        }
        $result = tpi_svg_sanitize_file((string) $file['tmp_name']);
        if (is_wp_error($result)) {
            $file['error'] = $result->get_error_message();
        }
        return $file;
    }
}
add_filter('wp_handle_upload_prefilter', 'tpi_svg_upload_prefilter');
add_filter('wp_handle_sideload_prefilter', 'tpi_svg_upload_prefilter');
