<?php
/**
* 语言标记（locale）规范化
 *
 * 语言代码 ↔ en_US / zh_Hans_CN 这类标记，供 <html lang>、hreflang、存库使用。
 */

/* =========================================================
 * 语言标记（locale）
 * ========================================================= */

if (!function_exists('tpi_locale_default_region')) {
    /**
     * 语言代码 → 常用地区代码
     *
     * 只给「本地化标记」用，目的是别再生出 ko_ko 这种不存在的地区。
     */
    function tpi_locale_default_region(string $code): string {
        $map = [
            'en' => 'US', 'zh' => 'CN', 'ja' => 'JP', 'ko' => 'KR', 'ru' => 'RU',
            'de' => 'DE', 'fr' => 'FR', 'th' => 'TH', 'hi' => 'IN', 'es' => 'ES',
            'it' => 'IT', 'pt' => 'PT', 'ar' => 'SA', 'vi' => 'VN', 'id' => 'ID',
            'tr' => 'TR', 'nl' => 'NL', 'pl' => 'PL', 'sv' => 'SE', 'ms' => 'MY',
        ];
        return $map[strtolower(trim($code))] ?? '';
    }
}

if (!function_exists('tpi_locale_normalize')) {
    /**
     * 把任意写法规范成 {语言}_{地区}（存库用）
     *
     * 大小写按 BCP 47 的惯例：语言小写、地区大写、脚本首字母大写。
     *   en-US / en_us / EN-us → en_US
     *   zh-hans-cn          → zh_Hans_CN
     *   ko_ko               → ko_KR（地区段和语言段相同＝没填地区，补常用地区）
     *   ja                  → ja_JP
     *
     * @param string $locale 语言标记
     */
    function tpi_locale_normalize(string $locale): string {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        $parts = preg_split('/[_-]+/', $locale);
        $lang  = strtolower((string) array_shift($parts));
        if ($lang === '' || !preg_match('/^[a-z]{2,3}$/', $lang)) {
            return '';
        }

        $out        = [$lang];
        $has_region = false;
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || strcasecmp($part, $lang) === 0) {
                continue; // 空的、或 ko_ko 这种把语言当地区的写法
            }
            if (preg_match('/^[a-zA-Z]{4}$/', $part)) {
                $out[] = ucfirst(strtolower($part));   // 脚本：Hans / Hant / Latn
                continue;
            }
            if (preg_match('/^([a-zA-Z]{2}|[0-9]{3})$/', $part)) {
                $out[]      = strtoupper($part);       // 地区：US / CN / 419
                $has_region = true;
                continue;
            }
            $out[] = strtolower($part);
        }

        // 只写了语言（或地区被判定为无效）时补一个常用地区
        if (!$has_region && count($out) === 1) {
            $region = tpi_locale_default_region($lang);
            if ($region !== '') {
                $out[] = $region;
            }
        }

        return implode('_', $out);
    }
}

if (!function_exists('tpi_locale_default')) {
    /**
     * 新语言的默认语言标记
     *
     * 走 tpi_locale_normalize：en → en_US、ko → ko_KR、ja → ja_JP
     *
     * @param string $code 语言代码（en / ru / ja …）
     */
    function tpi_locale_default(string $code): string {
        return tpi_locale_normalize($code);
    }
}

if (!function_exists('tpi_locale_language')) {
    /** 语言标记 → 两字母语言码（en_US → en、zh-CN → zh） */
    function tpi_locale_language(string $locale): string {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        $parts = preg_split('/[_-]/', $locale);
        return strtolower((string) ($parts[0] ?? ''));
    }
}

if (!function_exists('tpi_locale_tag')) {
    /**
     * 语言标记 → <html lang> / hreflang 的写法
     *
     * en_us → en-US、zh_Hans_CN → zh-Hans-CN。
     * 先规范大小写再换连字符，所以旧数据里的 en_us 也能输出成 en-US。
     */
    function tpi_locale_tag(string $locale): string {
        $normalized = tpi_locale_normalize($locale);
        if ($normalized === '') {
            return '';
        }
        return str_replace('_', '-', $normalized);
    }
}
