<?php
/**
 * 页面信息设置：默认值 + 读取逻辑
 *
 * 数据存在 {prefix}osiera_language_data（scope=setting, group_key=page-info）。
 * 字段结构与默认值在主题根 default-page-info.json，这里只负责读取与合并，
 * 供「内置兜底 / 后台恢复默认」使用；换品牌改 JSON 即可，不用动 PHP。
 *
 * @package Osiera
 */

/**
 * 判断一个设置项是否为空（空字符串 / null / 空数组）
 * @param mixed $value
 * @return bool
 */
function tpi_is_blank_setting($value): bool {
    if (is_array($value)) {
        return count($value) === 0;
    }
    return trim((string) $value) === '';
}

/**
 * 页面信息设置的内置默认值
 *
 * 数据结构在主题根 default-page-info.json（框架字段清单，值一律留空，
 * 代码里不再写死任何品牌文案）。键与顺序以该文件为准：
 *   - 空字符串 = 该项留空，由后台自己填（图片、视频一律留空，主题不内置位图）；
 *   - {{year}} = 取当前年份，用于版权行；
 *   - 数组（社媒列表）按 JSON 字符串存回，与库里存字符串的约定一致。
 *
 * 文件被删掉时返回空数组：此时以数据库里的数据为准，前台照常运行。
 *
 * @return array
 */
function tpi_default_page_info_settings_base(): array {
    static $base = null;
    if (is_array($base)) {
        return $base;
    }
    $data = [];
    $file = THEME_DIR . 'default-page-info.json';
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        $data = is_array($decoded) ? $decoded : [];
    }
    $base = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $base[$key] = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            continue;
        }
        $base[$key] = str_replace('{{year}}', gmdate('Y'), (string) $value);
    }
    return $base;
}

/**
 * 页面信息设置的默认值
 *
 * 以代码内置值为主；若某个部署在 languages/admin-system-config-{zh|en}.json
 * 的 settings.page-info 里另写了默认值，则覆盖同名的内置键（向后兼容，默认没有这段）。
 *
 * @return array
 */
function tpi_default_page_info_settings(): array {
    $base = tpi_default_page_info_settings_base();
    $template = tpi_lang_template_setting('page-info', []);
    $settings = (is_array($template) && $template) ? array_merge($base, $template) : $base;
    if (tpi_is_blank_setting($settings['email'] ?? '')) {
        $settings['email'] = (string) get_option('admin_email');
    }
    return $settings;
}

/**
 * 读取某个语言的页面信息设置
 *
 * 读取顺序：全站默认 → 该语言覆盖 → 代码内置默认。
 * （默认语言自己的行天然覆盖全站默认，所以不用额外判断默认语言）
 *
 * @param string $lang
 * @param string $default_lang 保留参数，兼容旧调用
 * @return array
 */
function tpi_get_page_info_settings(string $lang = '', string $default_lang = 'en'): array {
    return tpi_setting_get('page-info', $lang, tpi_default_page_info_settings());
}

/**
 * 把页面信息设置里的社媒 JSON 解析成渲染用列表
 * @param array $settings
 * @return array
 */
function tpi_page_info_media_list(array $settings): array {
    $list = json_decode((string) ($settings['media_list_json'] ?? ''), true);
    if (!is_array($list)) {
        return [];
    }
    $resolved = [];
    foreach ($list as $item) {
        $media_name = trim((string) ($item['name'] ?? ''));
        $media_url  = trim((string) ($item['url'] ?? ''));
        if ($media_name === '' || $media_url === '') {
            continue;
        }
        $icon_url = tpi_media_url((string) ($item['icon_url'] ?? ''), false);
        if ($icon_url === '') {
            // 没选图标时按平台名去媒体库找（LinkedIn → linkedin.svg、X → x-social.svg），
            // 只认上传的图片，不回落到主题 assets/svg
            $icon_url = tpi_media_icon_by_name($media_name);
        }
        $resolved[] = [
            'name' => $media_name,
            'url' => $media_url,
            'icon_url' => $icon_url,
        ];
    }
    return $resolved;
}
