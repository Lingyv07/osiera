<?php
add_action('wp_enqueue_scripts', function () {
    global $version, $default_language, $language_list;

    // 初始化脚本
    wp_enqueue_script(
        'initial-theme-js', // 句柄
        get_template_directory_uri() . '/assets/js/initial.js',
        array(), // 依赖
        $version, // 版本
        true // 放在 footer
    );

    // 语言列表交给前端脚本，切换器不再写死语言代码
    wp_add_inline_script(
        'initial-theme-js',
        'window.tpiI18n = ' . wp_json_encode([
            'default'    => $default_language ?: 'en',
            'others'     => array_values((array) $language_list),
            'all'        => tpi_language_slugs(),
            // 当前页在每个语言下的地址（slug => url）
            // <link rel="alternate"> 的 hreflang 是 BCP 47（ja-JP），和语言代码（ja）
            // 对不上，前端只能拿它兜底；这里按语言代码直接给，切换才不会掉回首页
            'urls'       => tpi_current_content_urls(),
            // 详情页（有对应译文）与列表页（按路径换前缀）的切换逻辑不一样
            'isSingular' => is_singular(),
        ]) . ';',
        'before'
    );

    // 功能脚本
    wp_enqueue_script(
        'functional-theme-js', // 句柄
        get_template_directory_uri() . '/assets/js/functional.js',
        array(), // 依赖
        $version, // 版本
        true // 放在 footer
    );

    // 接口地址交给前端脚本：按「站点与路径」生成，默认 /wp-json/api，
    // 不能再在 JS 里写死 /api/xxx —— 只有把 api 路径改成 custom（nginx 反代）的站点才有那个前缀，
    // 新项目/默认配置下写死就会 404。
    // 只取路径不取域名：页面可能通过 CDN 域名访问，用相对地址才能保证同源（Cookie 才写得进去）。
    $api_path = static function (string $route): string {
        $path = (string) wp_parse_url(tpi_api_url($route), PHP_URL_PATH);
        return $path !== '' ? $path : '/wp-json/api/' . $route;
    };
    wp_add_inline_script(
        'functional-theme-js',
        'window.tpiApi = ' . wp_json_encode([
            'setCookies' => $api_path('set-cookies'),
            'inquiry'    => $api_path('inquiry'),
        ]) . ';',
        'before'
    );

    // 统计与转化平台：平台 ID、事件映射全部来自后台「监控管理」，
    // 脚本里不再写死任何账号（见 inc/admin/monitor.php）。
    // Clarity 与 Google 都没启用、或本站没启用且不是 CDN 回源时是空数组，前端不会加载外部脚本。
    wp_add_inline_script(
        'functional-theme-js',
        'window.tpiMonitor = ' . wp_json_encode(
            tpi_monitor_front_config(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . ';',
        'before'
    );
});

/**
 * SearchAction 结构化数据
 * @return array
 */
function Organization_JSON_LD(): array {
    global $page_info_settings, $current_lang_json, $media_info_list, $icon_size, $icon_url, $current_top_url, $current_top_host, $has_credential;

    $org_data = [
        '@type'    => 'Organization',
        "@id" => $current_top_host. "#organization",
        'name'     => $current_lang_json["footer"]['brand'],
        "alternateName" => $current_lang_json["footer"]['company'],
        'url'      => home_url('/'),
        'foundingDate' => 2012,
        'logo'  => [
            '@type' => 'ImageObject',
            "@id" => $current_top_url. "#logo",
            'url'   => $icon_url,
            'width' => $icon_size[0],
            'height' => $icon_size[1],
            "caption" => $current_lang_json["organization"]['logo-name'] ?? ''
        ],
        'image' => [
            "@id" => $current_top_url. "#logo"
        ],
        'description' => $current_lang_json["footer"]['company-desc'],
        'address'  => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $current_lang_json["footer"]['street-address'] ?? '',
            'addressLocality' => $current_lang_json["footer"]['address-locality'] ?? '',
            'addressRegion'   => $current_lang_json["footer"]['address-region'] ?? '',
            'addressCountry'  => 'CN',
            'postalCode'      => '523000',
        ],
        'contactPoint' => [
            '@type'       => 'ContactPoint',
            'telephone'   => $page_info_settings['telephone'] ?? '',
            'email'       => $page_info_settings['email'] ?? '',
            'contactType' => $current_lang_json["organization"]['contact-type'] ?? '',
            "areaServed" => "World",
            "availableLanguage" => ["English", "Chinese"]
        ],
        "areaServed" => [
            "@type" => "Place",
            "name" => "Worldwide"
        ],
        "knowsAbout" => $current_lang_json['organization']['knows-about'],
        "sameAs" => [],
    ];
    if ($has_credential) {
        $org_data['hasCredential'] = [
            [
                '@type' => $has_credential['hero_certificate']['type'] ?? '',
                'credentialCategory' => $has_credential['hero_certificate']['credential_category'] ?? '',
                'name' => $has_credential['hero_certificate']['name'] ?? '',
                'url' => $has_credential['hero_certificate']['file_url'] ?? '',
            ]
        ];
    }

    // 添加社媒外链
    foreach ($media_info_list as $media_item) {
        $org_data['sameAs'][] = $media_item['url'];
    }
    // 其他外链认可
    $same_as_str = $page_info_settings['same_as'] ?? '';
    $same_as_list = explode(';', $same_as_str);
    $same_as_list = array_map('trim', $same_as_list);
    foreach ($same_as_list as $same_as_item) {
        if ($same_as_item) {
            $org_data['sameAs'][] = $same_as_item;
        }
    }

    return $org_data;
}

/**
 * 返回网站信息
 * @return array
 */
function WebSite_JSON_LD(): array {
    global $host_url, $current_lang, $lang, $current_lang_json, $current_top_host;
    // 构造结构化数据数组
    return [
        "@type"    => "WebSite",
        "@id" => $current_top_host. "#website",
        "url"      => home_url('/'),
        "name" => $current_lang_json["footer"]['brand'],
        "alternateName" => $current_lang_json["footer"]['company'],
        "description" => $current_lang_json["organization"]['web-site-description'],
        "inLanguage" => tpi_current_locale_tag(),
        "publisher" => [
            "@id" => $current_top_host. "#organization"
        ],
        "potentialAction" => [
            "@type"       => "SearchAction",
            "target"      => [
                "@type" => "EntryPoint",
                "urlTemplate" => $host_url. '/'. ($current_lang ? $current_lang. '/' : ''). '?s={search_term_string}'
            ],
            "query-input" => "required name=search_term_string",
        ],
    ];
}
add_action('wp_head', function () {
    global $json_ld_data;
    if (!$json_ld_data) {
        return;
    }

    // 结构化设置（后台 Osiera → 结构化设置）：
    // 逐项套用填写的 JSON（@type / @id 匹配，或按位置对位），@graph 同级的键并到根
    if (function_exists('tpi_sd_apply') && is_array($json_ld_data)) {
        $json_ld_data = tpi_sd_apply($json_ld_data);
    }

    echo '<script type="application/ld+json">
' . wp_json_encode($json_ld_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '
</script>
';
});

// 添加 speculationrules
//add_action('wp_footer', function() {
//    // 定义你的规则数据
//    $speculation_rules = [
//        'prefetch' => [ // prerender 快, prefetch 中
//            [
//                'source' => 'document',
//                'where' => [
//                    'and' => [
//                        [
//                            'href_matches' => '/*'
//                        ],
//                        [
//                            "not" => ["href_matches" => "/*\\.(pdf|zip|jpg|png|gif)*"]
//                        ]
//                    ]
//                ],
//                'eagerness' => 'moderate' // Eager 快, moderate 中, conservative 慢
//            ]
//        ]
//    ];
//    echo '<script type="speculationrules">
//'. wp_json_encode($speculation_rules, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT). '
//</script>
//';
//});

/**
 * 数据后台管理脚本
 * @return void
 */
function custom_admin_scripts(): void {
    global $version;
    if (is_user_logged_in()) {
        wp_enqueue_script(
            'custom-admin-js',
            get_template_directory_uri() . '/assets/wp-assets/js/wp-manage.js',
            array(),
            $version,
            true // 放在底部
        );

        // 后台脚本里的少量文案（计数单位、JSON 报错前缀）也走文案真源
        $admin_meta = tpi_admin_config('meta');
        $manage_ui = $admin_meta['ui']['manage'] ?? [];
        if (!is_array($manage_ui)) {
            $manage_ui = [];
        }
        wp_localize_script('custom-admin-js', 'tpiManage', $manage_ui);

        // 媒体选择一致性补丁：编辑器 Image details 的「选择图片」、Custom URL 解锁、
        // 各内容页媒体库预选当前图片。依赖 media-views 的页面自己会加载，这里只等它就位。
        wp_enqueue_script(
            'tpi-media-parity',
            get_template_directory_uri() . '/assets/wp-assets/js/wp-media-parity.js',
            array('jquery', 'wp-util'),
            $version,
            true
        );

        $manage_media_ui = $manage_ui['media'] ?? [];
        wp_localize_script('tpi-media-parity', 'tpiMediaParity', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tpi_media_lookup'),
            'i18n'    => is_array($manage_media_ui) ? $manage_media_ui : [],
        ]);
    }
}
add_action('admin_enqueue_scripts', 'custom_admin_scripts');