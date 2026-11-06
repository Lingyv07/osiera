<?php
global $page_info_settings, $current_url, $lang, $current_top_host;
$routeInfo = routeCategoryParse();
$term_slug = $routeInfo['category']. ($routeInfo['lang'] ? '-'. $routeInfo['lang'] : '');
$new_page = $_GET['new-page'] ?? 1;
$new_page = intval($new_page ?: 1);
$product_page = $_GET['product-page'] ?? 1;
$product_page = intval($product_page ?: 1);
$application_page = $_GET['application-page'] ?? 1;
$application_page = intval($application_page ?: 1);
$faq_page = $_GET['faq-page'] ?? 1;
$faq_page = intval($faq_page ?: 1);
$posts_per_page = 12;
$query_key = esc_html(get_search_query());
$home_url_path = go_url('home');
$search_title = $current_lang_json["search"]["title"] ?? '';
$title_name = $search_title. ' | '. $query_key;

$product_info_page = get_product_info_page(['posts_per_page' => $posts_per_page, 'paged' => $product_page, 's' => $query_key]);
$product_info_list = $product_info_page["data"];
$new_info_page = get_article_info_page(['posts_per_page' => $posts_per_page, 'paged' => $new_page, 's' => $query_key]);
$new_info_list = $new_info_page["data"];
$application_info_page = get_application_info_page(['posts_per_page' => $posts_per_page, 'paged' => $application_page, 's' => $query_key]);
$application_info_list = $application_info_page["data"];
$faq_info_page = get_faq_info_page(['posts_per_page' => $posts_per_page, 'paged' => $faq_page, 's' => $query_key]);
$faq_info_list = $faq_info_page["data"];

$page_json_ld = [
    '@type'    => 'SearchResultsPage',
    "@id" => $current_url. "#webpage",
    'url'      => $current_url,
    'name'     => $search_title,
    'description' => $title_name,
    'inLanguage' => tpi_current_locale_tag(),
    'isPartOf' => [
        "@id" => $current_top_host. "#website"
    ],
    'breadcrumb' => [
        "@id" => $current_url. "#breadcrumb"
    ],
    'hasPart' => [],
];
$breadcrumb_list_json_ld = [
    '@type' => 'BreadcrumbList',
    "@id" => $current_url. "#breadcrumb",
    'name' => $search_title,
    'itemListElement' => [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => $current_lang_json['footer']["home"] ?? '',
            'item' => $home_url_path
        ],
        [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $title_name,
            'item' => $current_url
        ]
    ],
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$page_json_ld, $breadcrumb_list_json_ld]
];
// 产品列表
if (count($product_info_list)) {
    $list_json_ld = [
        '@type' => 'ItemList',
        "@id" => $current_url. "#productlist",
        'name' => $current_lang_json["product"]["title"] ?? '',
        'description' => $current_lang_json["product"]["description"] ?? '',
        'numberOfItems' => count($product_info_list),
        'itemListElement' => []
    ];
    foreach ($product_info_list as $index => $item) {
        $list_json_ld['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => html_entity_decode($item['title']),
            'url' => $item['permalink'],
        ];
    }
    $json_ld_data['@graph'][] = $list_json_ld;
    $json_ld_data['@graph'][0]['hasPart'][] = [
        "@id" => $current_url. "#productlist",
    ];
}

// 文章列表
if (count($new_info_list)) {
    $list_json_ld = [
        '@type' => 'ItemList',
        "@id" => $current_url. "#newslist",
        'name' => $current_lang_json["news"]["title"] ?? '',
        'description' => $current_lang_json["news"]["description"] ?? '',
        'numberOfItems' => count($new_info_list),
        'itemListElement' => []
    ];
    foreach ($new_info_list as $index => $item) {
        $list_json_ld['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => html_entity_decode($item['title']),
            'url' => $item['permalink'],
        ];
    }
    $json_ld_data['@graph'][] = $list_json_ld;
    $json_ld_data['@graph'][0]['hasPart'][] = [
        "@id" => $current_url. "#newslist",
    ];
}

// 产品应用列表
if (count($application_info_list)) {
    $list_json_ld = [
        '@type' => 'ItemList',
        "@id" => $current_url. "#applicationslist",
        'name' => $current_lang_json["applications"]["title"] ?? '',
        'description' => $current_lang_json["applications"]["description"] ?? '',
        'numberOfItems' => count($application_info_list),
        'itemListElement' => []
    ];
    foreach ($application_info_list as $index => $item) {
        $list_json_ld['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => html_entity_decode($item['title']),
            'url' => $item['permalink'],
        ];
    }
    $json_ld_data['@graph'][] = $list_json_ld;
    $json_ld_data['@graph'][0]['hasPart'][] = [
        "@id" => $current_url. "#applicationslist",
    ];
}

// 常见问题列表
if (count($faq_info_list)) {
    $list_json_ld = [
        '@type' => 'ItemList',
        "@id" => $current_url. "#faqlist",
        'name' => $current_lang_json["faq"]["title"] ?? '',
        'description' => $current_lang_json["faq"]["description"] ?? '',
        'numberOfItems' => count($faq_info_list),
        'itemListElement' => []
    ];
    foreach ($faq_info_list as $index => $item) {
        $list_json_ld['itemListElement'][] = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => html_entity_decode($item['title']),
            'url' => $item['permalink'],
        ];
    }
    $json_ld_data['@graph'][] = $list_json_ld;
    $json_ld_data['@graph'][0]['hasPart'][] = [
        "@id" => $current_url. "#faqlist",
    ];
}

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('search-style', tpi_asset('/css/search.css'), array(), $version);
    wp_enqueue_script('search-js',tpi_asset('/js/search.js'), array(), $version, true);
});
get_header();
?>
    <div class="search-wrap">
        <div class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("/news");?>">
                <img src="<?php echo $page_info_settings['post_top_image'];?>"
                     alt="<?php echo esc_attr(get_image_alt($page_info_settings['post_top_image'])); ?>">
                <div class="frame-top-title">
                    <?php echo $query_key; ?>
                </div>
            </a>
        </div>
        <div class="frame-box">
            <div class="frame-nav-wrap" aria-label="Breadcrumb">
                <a class="point-to" href="<?php echo go_url("/home"); ?>">
                    <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                    <span class="title-menu"><?php echo $current_lang_json["footer"]["home"] ?? "";?></span>
                </a>
                <span aria-current="page"><?php echo $query_key; ?></span>
            </div>
            <div class="news-content-box">
                <div class="news-content-box-wrap">
                    <?php get_product_fixed(); ?>
                    <div class="search-s-content">
                        <h1>
                            <?php echo $search_title; ?>
                        </h1>
                        <div class="search-s-info-list-wrap product-list-wrap-box">
                            <h2 class="search-type page-title"><?php echo $current_lang_json["product"]["title"] ?? '';?></h2>
                            <p class="p-desc"><?php echo $current_lang_json["product"]["description"] ?? '';?></p>
                            <?php
                            $total_num = $product_info_page['total_pages'] ?: 1;
                            $pageArray = range(1, $total_num);
                            $paged = $product_page;
                            get_change_layout();
                            ?>
                            <div class="search-s-list-wrap">
                                <ul class="search-s-info-wrap-box" aria-label="Products List">
                                    <?php foreach ($product_info_list as $product_info): ?>
                                        <li>
                                            <a class="search-info-item" href="<?php echo esc_url($product_info['permalink']);?>" >
                                                <div class="search-info-item-image">
                                                    <img src="" data-lazy="<?php echo esc_url($product_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>">
                                                </div>
                                                <div class="search-info-item-desc">
                                                    <div class="search-info-item-title"><?php echo esc_html($product_info['title'] ?? ""); ?></div>
                                                    <div class="search-info-item-description"><?php echo esc_html($product_info['info']['description'] ?? ""); ?></div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php get_search_pagination(); ?>
                            </div>
                        </div>
                        <div class="search-s-info-list-wrap news-list-wrap-box">
                            <h2 class="search-type page-title"><?php echo $current_lang_json["news"]["title"] ?? '';?></h2>
                            <p class="p-desc"><?php echo $current_lang_json["news"]["description"] ?? '';?></p>
                            <?php
                            $total_num = $new_info_page['total_pages'] ?: 1;
                            $pageArray = range(1, $total_num);
                            $paged = $new_page;
                            get_change_layout();
                            ?>
                            <div class="search-s-list-wrap">
                                <ul class="search-s-info-wrap-box" aria-label="News List">
                                    <?php foreach ($new_info_list as $new_info): ?>
                                        <li>
                                            <a class="search-info-item" href="<?php echo esc_url($new_info['permalink']);?>" >
                                                <div class="search-info-item-image">
                                                    <img src="" data-lazy="<?php echo esc_url($new_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($new_info['image'], $new_info['title'] ?? 'News')); ?>">
                                                </div>
                                                <div class="search-info-item-desc">
                                                    <div class="search-info-item-title"><?php echo esc_html($new_info['title'] ?? ""); ?></div>
                                                    <div class="search-info-item-description"><?php echo esc_html($new_info['info']['description'] ?? ""); ?></div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php get_search_pagination(); ?>
                            </div>
                        </div>
                        <div class="search-s-info-list-wrap application-list-wrap-box">
                            <h2 class="search-type page-title"><?php echo $current_lang_json["applications"]["title"] ?? '';?></h2>
                            <p class="p-desc"><?php echo $current_lang_json["product"]["applications"] ?? '';?></p>
                            <?php
                            $total_num = $application_info_page['total_pages'] ?: 1;
                            $pageArray = range(1, $total_num);
                            $paged = $application_page;
                            get_change_layout();
                            ?>
                            <div class="search-s-list-wrap">
                                <ul class="search-s-info-wrap-box" aria-label="Applications List">
                                    <?php
                                    foreach ($application_info_list as $new_info):
                                        ?>
                                        <li>
                                            <a class="search-info-item" href="<?php echo esc_url($new_info['permalink']);?>" >
                                                <div class="search-info-item-image">
                                                    <img src="" data-lazy="<?php echo esc_url($new_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($new_info['image'], $new_info['title'] ?? 'News')); ?>">
                                                </div>
                                                <div class="search-info-item-desc">
                                                    <div class="search-info-item-title"><?php echo esc_html($new_info['title'] ?? ""); ?></div>
                                                    <div class="search-info-item-description"><?php echo esc_html($new_info['info']['description'] ?? ""); ?></div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php get_search_pagination(); ?>
                            </div>
                        </div>
                        <div class="search-s-info-list-wrap faq-list-wrap-box">
                            <h2 class="search-type page-title"><?php echo $current_lang_json["faq"]["title"] ?? '';?></h2>
                            <p class="p-desc"><?php echo $current_lang_json["faq"]["description"] ?? '';?></p>
                            <?php
                            get_change_layout();
                            $total_num = $faq_info_page['total_pages'] ?: 1;
                            $pageArray = range(1, $total_num);
                            $paged = $faq_page;
                            ?>
                            <div class="question-list-wrap">
                                <ul class="question-info-wrap-box" aria-label="Question List">
                                    <?php
                                    foreach ($faq_info_list as $faq_info):
                                        ?>
                                        <li>
                                            <a class="faq-info-item" href="<?php echo esc_url($faq_info['permalink']);?>" >
                                                <div class="faq-info-item-desc">
                                                    <div class="faq-info-item-title"><?php echo esc_html($faq_info['title'] ?? ''); ?></div>
                                                    <div class="faq-info-item-description"><?php echo wp_strip_all_tags($faq_info['info']['content'] ?? '', true); ?></div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php get_search_pagination(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>