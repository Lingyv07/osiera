<?php
/**
 * 富文本内联样式白名单
 *
 * WordPress 保存正文时会用 wp_kses_post() 逐条过滤 style 里的 CSS 声明：
 *   1. 默认白名单里没有 display / overflow-x / box-sizing / transform 等布局属性；
 *   2. 值里带括号（rgba()、hsla()、translate() 等）会被判为不安全整条丢弃。
 * 结果是编辑器里排好的 flex / grid / 阴影，一保存就塌成竖排。
 *
 * 这里补齐主题实际会用到的属性，并放行「括号配平」的函数值，
 * 反斜杠、& = }、注释、url()、expression() 仍然一律拒绝。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('tpi_safe_style_css_add')) {
    /**
     * 补齐 WordPress 默认 CSS 属性白名单里缺失的布局属性
     *
     * @param string[] $props
     * @return string[]
     */
    function tpi_safe_style_css_add(array $props): array
    {
        $extra = [
            'display',
            'overflow-x',
            'overflow-y',
            'box-sizing',
            'white-space',
            'word-break',
            'word-wrap',
            'overflow-wrap',
            'text-overflow',
            'transform',
            'transform-origin',
            'transition',
            'transition-property',
            'transition-duration',
            'transition-timing-function',
            'transition-delay',
            'order',
            'place-items',
            'place-content',
            'place-self',
            'inset',
            'inset-inline',
            'inset-inline-start',
            'inset-inline-end',
            'inset-block',
            'inset-block-start',
            'inset-block-end',
            'grid-auto-flow',
            'grid-template-areas',
            'grid-area',
            'table-layout',
            'backdrop-filter',
            'clip-path',
        ];

        return array_values(array_unique(array_merge($props, $extra)));
    }
}
add_filter('safe_style_css', 'tpi_safe_style_css_add');

if (!function_exists('tpi_safe_style_allow_css')) {
    /**
     * 放行括号配平的函数值（rgba() / hsla() / translate() / cubic-bezier() 等）
     *
     * 核心只认 var/calc/min/max/minmax/clamp/repeat，带其它函数的值整条丢弃，
     * 所以 box-shadow: 0 4px 10px rgba(0,0,0,.1) 这种最常见的写法会被吃掉。
     *
     * @param bool   $allow_css       核心的判定结果
     * @param string $css_test_string 待校验的声明原文
     * @return bool
     */
    function tpi_safe_style_allow_css($allow_css, $css_test_string): bool
    {
        if ($allow_css) {
            return true;
        }

        $css_test_string = (string) $css_test_string;

        // 危险字符一律不放过：反斜杠、&、等号、右花括号、注释
        if (preg_match('%[\\\\&}=]|/\*%', $css_test_string)) {
            return false;
        }
        // url() 交给核心的协议校验，这里不越权
        if (stripos($css_test_string, 'url(') !== false) {
            return false;
        }
        if (stripos($css_test_string, 'expression(') !== false) {
            return false;
        }

        // 把「函数名(...)」整体（含嵌套）挖掉，剩下的字符串不能再有括号
        $rest = preg_replace('/[a-zA-Z-]+\s*\((?:[^()]|(?R))*\)/', '', $css_test_string);
        if (!is_string($rest)) {
            return false;
        }

        return strpos($rest, '(') === false && strpos($rest, ')') === false;
    }
}
add_filter('safecss_filter_attr_allow_css', 'tpi_safe_style_allow_css', 10, 2);
