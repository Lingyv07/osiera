<?php
/**
 * 获取产品完整信息
 * @param array $posts_page
 * @param array | null $term_ids
 * @return array
 */
function get_product_info_page(array $posts_page = ['posts_per_page' => 10, 'paged' => 1, 's' => ''], ?array $term_ids = null): array {
    return get_sql_data('product', $posts_page, $term_ids);
}

/**
 * 获取文章完整信息
 * @param array $posts_page
 * @param array|null $term_ids
 * @return array
 */
function get_article_info_page(array $posts_page = ['posts_per_page' => 10, 'paged' => 1, 's' => ''], ?array $term_ids = null): array {
    return get_sql_data('post', $posts_page, $term_ids);
}

/**
 * 获取应用领域完整信息
 * @param array $posts_page
 * @param array|null $term_ids
 * @return array
 */
function get_application_info_page(array $posts_page = ['posts_per_page' => 10, 'paged' => 1, 's' => ''], ?array $term_ids = null): array {
    return get_sql_data('application', $posts_page, $term_ids);
}

/**
 * 获取常见问题完整信息
 * @param array $posts_page
 * @param array|null $term_ids
 * @return array
 */
function get_faq_info_page(array $posts_page = ['posts_per_page' => 10, 'paged' => 1, 's' => ''], ?array $term_ids = null): array {
    return get_sql_data('faq', $posts_page, $term_ids);
}

/**
 * 获取页面数据
 * @param string $post_type //页面类型
 * @param array $posts_page
 * @param array|null $term_ids
 * @return array
 */
function get_sql_data(string $post_type, array $posts_page = ['posts_per_page' => 10, 'paged' => 1, 's' => ''], ?array $term_ids = null): array {
    $query = array(
        'post_type'      => $post_type,
        'posts_per_page' => $posts_page['posts_per_page'] ?? 10,
        'paged'          => $posts_page['paged'] ?? 1, // 当前页
        'post_status'    => 'publish',
        's'              => $posts_page['s'] ?? '', // 内置 LIKE 搜索
        'orderby' => array(
            'menu_order' => 'DESC', // 有 sort 按数值排序
            'post_date'             => 'DESC', // 空 sort 按 ID 排序
        ),
    );

    if (!empty($term_ids)) {
        $query['tax_query'] = array(
            array(
                'taxonomy' => $post_type === 'post' ? 'category' : $post_type. '_cat',
                'field'    => 'term_id',
                'terms'    => $term_ids,
                'operator' => 'IN'
            )
        );
    }

    $query = new WP_Query($query);
    // 产品 post 列表
    $posts = $query->posts;
    // 总页数
    $total_pages = $query->max_num_pages;
    // 总条数
    $total_posts = $query->found_posts;
    // 提取 ID
    $post_ids = wp_list_pluck($posts, 'ID');
    $post_info_list = [];

    if (!empty($post_ids)) {
        global $default_image;
        // 摘要 / 主图 / 正文等字段统一由内容字段层读取（WordPress 原生存储）
        $info_map = tpi_item_fields_map($post_ids, $post_type);

        foreach ($posts as $post) {
            $id = $post->ID;
            $info = $info_map[$id] ?? tpi_item_fields($id, $post_type);
            $image = (string) ($info['image'] ?? '');
            if ($image === '') {
                $image = (string) ($default_image[$post_type] ?? '');
            }

            $post_info_list[] = [
                'ID'          => $id,
                'title'       => get_the_title($id),
                'permalink'   => get_permalink($id),
                'image'       => $image,
                'info'        => $info,
            ];
        }
    }
    return [
        "data" => $post_info_list,
        "total_pages" => $total_pages,
        "total_posts" => $total_posts,
    ];
}

/**
 * 获取产品 JSON/LD 数据
 * @param $product_info
 * @param $post_id
 * @return array
 */
function get_product_jsonLd_data($product_info, $post_id): array {
    global $current_top_host, $current_url, $brand_name;
    $main_title = $product_info['main_title'] ?? '';
    $images = is_array($product_info['images'] ?? null)
        ? $product_info['images']
        : (json_decode((string) ($product_info['images'] ?? ''), true) ?: []);
    $filtered = array_values(array_filter($images, fn($img) => empty($img['iframe_src'])));
    $image_list = array_map(function ($img, $i) {
        global $current_url;
        // 库里存上传目录相对路径，JSON-LD 必须输出绝对地址
        $img_url = tpi_media_url((string) ($img['img_src'] ?? ''));
        if ($img_url === '') {
            $img_url = (string) ($img['img_src'] ?? '');
        }
        $size = custom_getimagesize($img_url) ?: [0, 0];
        $res = [
            '@type' => 'ImageObject',
            'url' => $img_url,
            'width' => $size[0],
            'height' => $size[1],
        ];
        if ($i === 0) {
            $res["@id"] = $current_url. "#primaryimage";
        }
        return $res;
    }, $filtered, array_keys($filtered));
    $parameters = is_array($product_info['parameters'] ?? null)
        ? $product_info['parameters']
        : (json_decode((string) ($product_info['parameters'] ?? ''), true) ?: []);
    $additionalProperty = array_map(function ($parameter) {
        return [
            '@type' => 'PropertyValue',
            'name' => $parameter['key'],
            'value' => $parameter['value'],
        ];
    }, $parameters);
    $summary = $product_info['summary'] ?? '';
    $description = $product_info['description'] ?? '';
    $title_name_parts = explode(" |", get_the_title());
    $title_name_string = $title_name_parts[0];

    $result = [
        '@type'    => 'Product',
        '@id'    => $current_url. '#product',
        'name'     => $main_title ?: $title_name_string,
        'description' => $summary ?: $description,
        'sku'      => (string) $post_id,
        "mpn" => (string) $post_id, /* 建议补上 mpn，通常和 sku 一样 */
        'brand' => [
            "@type" => "Brand",
            "@id" => $current_top_host. "#organization",
            "name" => $brand_name,
        ],
        'manufacturer' => [
            "@type" => "Organization",
            "@id" => $current_top_host. "#organization",
            "name" => $brand_name,
        ],
        'potentialAction' => [
            '@type' => 'AskAction',
            'name'  => 'Inquiry for quotation',
            'target' => get_url('contact?action=inquiry')
        ],
        'offers' => [
            '@type' => 'Offer',
            'url'   => get_permalink($post_id),
            'availability'  => 'https://schema.org/InStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'priceCurrency' => 'USD',
            "price" => 0.00,
            'priceValidUntil' => utc_time_format(date('Y-12-31', strtotime('+3 years', time()))),
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'priceCurrency' => 'USD',
                'price' => 0.00,
                'priceType' => 'RegularPrice',
                'referenceQuantity' => [
                    '@type' => 'QuantitativeValue',
                    'value' => 1,
                    'unitCode' => 'MTR'
                ]
            ],
            'eligibleQuantity' => [
                '@type' => 'QuantitativeValue',
                'value' => 1,
                'unitCode' => 'MTR'
            ],
            'seller' => [
                "@id" => $current_top_host. "#organization",
                "name" => $brand_name,
            ],
//            "hasMerchantReturnPolicy" => [
//                "@type" => "MerchantReturnPolicy",
//                "applicableCountry" => "CN",
//                "returnPolicyCategory" => "https://schema.org/MerchantReturnNotPermitted",
//                "merchantReturnDays" => 0,
//                "returnMethod" => "https://schema.org/ReturnByMail",
//                "returnFees" => "https://schema.org/ReturnShippingFees",
//                "returnShippingFeesAmount" => [
//                    "@type" => "MonetaryAmount",
//                    "value" => 0,
//                    "currency" => "CNY"
//                ]
//            ]
        ],
        'additionalProperty' => $additionalProperty,
//        'review' => [
//            '@type' => 'Review',
//            'reviewRating' => [
//                '@type' => 'Rating',
//                'ratingValue' => 5,
//                'bestRating'  => 5
//            ],
//            'author' => [
//                '@type' => 'Organization',
//                'name'  => $current_lang_json["footer"]['company']
//            ]
//        ],
//        'aggregateRating' => [
//            '@type'       => 'AggregateRating',
//            'ratingValue' => 5,
//            'bestRating' => 5,
//            'worstRating' => 0,
//            'reviewCount' => 1,
//        ],
    ];

    if (count($image_list)) {
        $result['image'] = $image_list;
    }

    return $result;
}

/**
 * 获取文章 JSON/LD 数据
 * @param $new_info
 * @param $post_id
 * @return array
 */
function get_new_jsonLd_data($new_info, $post_id): array {
    global $lang, $current_top_host, $current_url, $current_lang_json, $brand_name, $current_top_url;
    $main_title = ($new_info['main_title'] ?? '') ?: get_the_title($post_id);
    $content = $new_info['content'] ?? '';
    $description = $new_info['description'] ?? '';
    $terms = get_the_terms($post_id, 'category');
    $new_type = "";
    if ($terms && !is_wp_error($terms)) {
        $first_term = reset($terms);
        $new_type = esc_html($first_term->name);
    }
    $image_src_list = getImageSrcList($content);
    $image_list = array_map(function ($img) {
        global $current_url, $new_info;
        $size = custom_getimagesize($img) ?: [0, 0];
        $res = [
            '@type' => 'ImageObject',
            'url' => $img,
            'width' => $size[0],
            'height' => $size[1],
        ];
        if ($img === $new_info['image']) {
            $res["@id"] = $current_url. "#primaryimage";
        }
        return $res;
    }, $image_src_list) ?? [];

    $result = [
        '@type'    => $new_info['article_type'] ?? 'Article',
        '@id'    => $current_url. '#article',
        'headline' => $main_title,
        'description' => $description,
//        'articleBody' => wp_strip_all_tags($content),
        'datePublished' => utc_time_format(get_the_date('c', $post_id)),
        'dateModified'  => utc_time_format(get_the_modified_date('c', $post_id)),
        'articleSection'=> $new_type ?: $current_lang_json['footer']["news"],
        'mainEntityOfPage' => get_permalink($post_id),
//        "publicAccess" => true,
//        'isAccessibleForFree' => true,
        'author' => [
            "@type"=> "Organization",
            "@id" => $current_top_host. "#organization",
            "name" => $brand_name,
            "url" => $current_top_host
        ],
        'publisher' => [
            "@type"=> "Organization",
            "@id" => $current_top_host. "#organization",
            "name" => $brand_name,
            "url" => $current_top_host,
            "logo" => [
                "@id" => $current_top_url. "#logo",
            ]
        ],
        'inLanguage' => tpi_current_locale_tag(),
    ];

    if (count($image_list)) {
        $result['image'] = $image_list;
    }

    return $result;
}

/**
 * 获取应用领域 JSON/LD 数据
 * @param $application_info
 * @param $post_id
 * @return array
 */
function get_application_jsonLd_data($application_info, $post_id): array {
    global $lang, $current_top_host, $current_url, $current_lang_json, $brand_name, $current_top_url;
    $main_title = ($application_info['main_title'] ?? '') ?: get_the_title($post_id);
    $content = $application_info['content'] ?? '';
    $description = $application_info['description'] ?? '';
    $terms = get_the_terms($post_id, 'category');
    $application_type = "";
    if ($terms && !is_wp_error($terms)) {
        $first_term = reset($terms);
        $application_type = esc_html($first_term->name);
    }
    $image_src_list = getImageSrcList($content);
    $image_list = array_map(function ($img) {
        global $current_url, $application_info;
        $size = custom_getimagesize($img) ?: [0, 0];
        $res = [
            '@type' => 'ImageObject',
            'url' => $img,
            'width' => $size[0],
            'height' => $size[1],
        ];
        if ($img == $application_info['image']) {
            $res["@id"] = $current_url. "#primaryimage";
        }
        return $res;
    }, $image_src_list) ?? [];

    $result = [
        '@type'    => 'Article',
        '@id'    => $current_url. '#article',
        'headline' => $main_title,
        'description' => $description,
//        'articleBody' => wp_strip_all_tags($content),
        'datePublished' => utc_time_format(get_the_date('c', $post_id)),
        'dateModified'  => utc_time_format(get_the_modified_date('c', $post_id)),
        'articleSection'=> $application_type ?: $current_lang_json['home']["applicable"],
        'mainEntityOfPage' => get_permalink($post_id),
//        "publicAccess" => true,
//        'isAccessibleForFree' => true,
        'author' => [
            "@type"=> "Organization",
            "@id" => $current_top_host. "#organization",
            "name" => $brand_name,
            "url" => $current_top_host
        ],
        'publisher' => [
            "@type"=> "Organization",
            "@id" => $current_top_host. "#organization",
            "name" => $brand_name,
            "url" => $current_top_host,
            "logo" => [
                "@id" => $current_top_url. "#logo",
            ]
        ],
        'inLanguage' => tpi_current_locale_tag(),
    ];

    if (count($image_list)) {
        $result['image'] = $image_list;
    }

    return $result;
}

/**
 * 获取常见问题 JSON/LD 数据
 * @param $faq_info
 * @param $post_id
 * @return array
 */
function get_faq_jsonLd_data($faq_info, $post_id): array {
    global $lang, $current_url, $current_top_host;
    $content = $faq_info['content'] ?? '';
    $content_dom = wpautop($content);
    $description = '';

    if (preg_match('/<p>(.*?)<\/p>/is', $content_dom, $matches)) {
        $description = wp_strip_all_tags($matches[1]);
    }
    return [
        '@type'    => 'FAQPage',
        '@id'    => $current_url. '#webpage',
        'name' =>  get_the_title($post_id),
        'url' => get_permalink($post_id),
        'description' => $description,
        'inLanguage' => tpi_current_locale_tag(),
        'isPartOf' => [
            "@id" => $current_top_host. "#website"
        ],
        'about' => [
            "@id" => $current_top_host. "#organization"
        ],
        'breadcrumb' => [
            "@id" => $current_url. "#breadcrumb"
        ],
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => get_the_title($post_id),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $content
                ]
            ]
        ]
    ];
}
/**
 * 取页面要展示的认证图标
 *
 * 后台配了几个就返回几个，顺序就是后台拖动后的顺序——首页和关于我们共用同一套，
 * 不再按 scope 分流（老数据里的 scope 字段兼容但忽略，免得同一批图标在两个页面数量不一致）。
 *
 * @param array|null $credential certificates_json 解出来的数组
 * @param string     $scope      已废弃，保留参数只为兼容旧调用
 * @return array
 */
function get_credential_icons(?array $credential, string $scope = ''): array {
    $list = is_array($credential['icon_list'] ?? null) ? $credential['icon_list'] : [];
    $picked = [];
    foreach ($list as $icon) {
        if (is_array($icon) && trim((string) ($icon['image_url'] ?? '')) !== '') {
            $picked[] = $icon;
        }
    }
    return $picked;
}

/**
 * 图标的跳转地址：证书页里真有这个锚点才带 #id，否则直接进证书页
 *
 * @param array|null $credential certificates_json 解出来的数组
 * @param array      $icon       单个图标
 * @param string     $fallback    证书页地址
 * @return string
 */
function get_credential_icon_link(?array $credential, array $icon, string $fallback): string {
    $id = trim((string) ($icon['id'] ?? ''));
    if ($id === '') {
        return $fallback;
    }
    $ids = [(string) ($credential['hero_certificate']['id'] ?? '')];
    foreach ((array) ($credential['certificate_groups'] ?? []) as $group) {
        foreach ((array) ($group['items'] ?? []) as $item) {
            $ids[] = (string) ($item['id'] ?? '');
        }
    }
    return in_array($id, $ids, true) ? $fallback . '#' . $id : $fallback;
}
