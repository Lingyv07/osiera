<?php
/**
 * 后台标语（页面提示条）配置
 *
 * 页面里的提示语不再写死在各个页面模板里，统一放在 option tpi_admin_slogans，
 * 默认值来自 languages/admin-system-config-{zh|en}.json 的 slogans，可后台「后台标语」页编辑。
 *
 * 一条标语的结构：
 *   {
 *     "type":    "info | warning | danger | success",
 *     "title":   "可选，加粗的小标题",
 *     "text":    "正文",
 *     "enabled": true/false
 *   }
 *
 * 页面里调用 tpi_admin_slogan('language-content') 即可输出。
 */

if (!defined('TPI_ADMIN_SLOGAN_OPTION')) {
    define('TPI_ADMIN_SLOGAN_OPTION', 'tpi_admin_slogans');
}

if (!function_exists('tpi_admin_slogan_types')) {
    function tpi_admin_slogan_types(): array
    {
        return [
            'info'    => tpi_admin_ui('slogans.type.info'),
            'warning' => tpi_admin_ui('slogans.type.warning'),
            'danger'  => tpi_admin_ui('slogans.type.danger'),
            'success' => tpi_admin_ui('slogans.type.success'),
        ];
    }
}

if (!function_exists('tpi_admin_slogan_defaults')) {
    /** 后台配置里的默认标语（languages/admin-system-config-{zh|en}.json → slogans） */
    function tpi_admin_slogan_defaults(): array
    {
        $slogans = tpi_admin_config('slogans');
        return is_array($slogans) ? $slogans : [];
    }
}

if (!function_exists('tpi_admin_slogans')) {
    /**
     * 当前生效的标语：模板默认值 → option 覆盖
     *
     * @param bool $refresh
     */
    function tpi_admin_slogans(bool $refresh = false): array
    {
        static $cache = null;
        if (!$refresh && is_array($cache)) {
            return $cache;
        }

        $defaults = tpi_admin_slogan_defaults();
        $stored   = get_option(TPI_ADMIN_SLOGAN_OPTION, '');
        $custom   = [];
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $custom = $decoded;
            }
        } elseif (is_array($stored)) {
            $custom = $stored;
        }

        $out = [];
        foreach ($defaults as $key => $item) {
            $out[$key] = tpi_admin_slogan_normalize($item);
        }
        foreach ($custom as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[$key] = tpi_admin_slogan_normalize($item, $out[$key] ?? []);
        }

        $cache = $out;
        return $out;
    }
}

if (!function_exists('tpi_admin_slogan_normalize')) {
    /** 补齐一条标语的字段 */
    function tpi_admin_slogan_normalize($item, array $base = []): array
    {
        $item = is_array($item) ? $item : [];
        $types = tpi_admin_slogan_types();
        $type = (string) ($item['type'] ?? $base['type'] ?? 'info');
        if (!isset($types[$type])) {
            $type = 'info';
        }
        return [
            'type'    => $type,
            'title'   => (string) ($item['title'] ?? $base['title'] ?? ''),
            'text'    => (string) ($item['text'] ?? $base['text'] ?? ''),
            'enabled' => array_key_exists('enabled', $item) ? (bool) $item['enabled'] : (bool) ($base['enabled'] ?? true),
        ];
    }
}

if (!function_exists('tpi_admin_slogan_save')) {
    /** 保存标语（整份 JSON 覆盖），返回是否成功 */
    function tpi_admin_slogan_save(array $slogans): bool
    {
        $clean = [];
        foreach ($slogans as $key => $item) {
            $key = trim((string) $key);
            if ($key === '' || !is_array($item)) {
                continue;
            }
            $clean[$key] = tpi_admin_slogan_normalize($item);
        }
        $json = wp_json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }
        update_option(TPI_ADMIN_SLOGAN_OPTION, $json, false);
        tpi_admin_slogans(true);
        return true;
    }
}

if (!function_exists('tpi_admin_slogan_reset')) {
    /** 恢复模板默认标语 */
    function tpi_admin_slogan_reset(): void
    {
        delete_option(TPI_ADMIN_SLOGAN_OPTION);
        tpi_admin_slogans(true);
    }
}

if (!function_exists('tpi_admin_slogan')) {
    /**
     * 输出一条标语
     *
     * @param string $key     标语键，如 language-content-json
     * @param bool   $echo    是否直接输出
     * @return string
     */
    function tpi_admin_slogan(string $key, bool $echo = true): string
    {
        $all = tpi_admin_slogans();
        $item = $all[$key] ?? null;
        if (!$item || empty($item['enabled']) || trim($item['text']) === '') {
            return '';
        }
        $types = tpi_admin_slogan_types();
        $html  = '<div class="ad-slogan ad-slogan-' . esc_attr($item['type']) . '">';
        $html .= '<span class="ad-slogan-badge">' . esc_html($types[$item['type']] ?? tpi_admin_ui('slogans.type.info')) . '</span>';
        $html .= '<div class="ad-slogan-body">';
        if ($item['title'] !== '') {
            $html .= '<strong class="ad-slogan-title">' . esc_html($item['title']) . '</strong>';
        }
        $html .= '<p>' . wp_kses_post($item['text']) . '</p>';
        $html .= '</div></div>';

        if ($echo) {
            echo $html;
        }
        return $html;
    }
}
