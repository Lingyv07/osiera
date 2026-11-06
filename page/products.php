<?php
global $post, $page_info_settings, $current_lang_json, $lang, $json_ld_data, $current_url, $current_top_host, $description_content;
$routeInfo = routeCategoryParse();
$term_slug = $routeInfo['category']. ($routeInfo['lang'] ? '-'. $routeInfo['lang'] : '');
$paged = get_query_var('paged');
$page_site = $paged ? ( ' | '. $current_lang_json['functional']['page'].' '. $paged) : '';
$paged = intval($paged ?: 1);
$posts_per_page = 12;
$parent_title = "";
if ($post->post_parent) { // 有父级
    $parent_id = $post->post_parent;
    $parent_title = get_the_title($parent_id);
}
$term = $term_slug !== '' ? get_term_by('slug', $term_slug, 'product_cat') : null; // 分类描述存 JSON 或纯文字
// 该语言下没有单独建分类时（slug 没带语言后缀），退回按 URL 里的 slug 再找一次
if (!$term && !empty($routeInfo['category'])) {
    $term = get_term_by('slug', (string) $routeInfo['category'], 'product_cat');
}
$product_info_page = get_product_info_page(['posts_per_page' => $posts_per_page, 'paged' => $paged], $term ? [$term->term_id] : null);
$product_info_list = $product_info_page["data"];
$total_num = $product_info_page['total_pages'] ?: 1;
$pageArray = range(1, $total_num);
$home_url_path = go_url('home');
// 标题 / 描述 / 面包屑名称：分类描述 JSON 优先，缺字段回落到语言包
$product_head = tpi_term_head($term, $current_lang_json['product'] ?? []);
$title_name = $product_head['title'] . $page_site;
$description_content = $product_head['description'] . $page_site;
$breadcrumb_list_name = $product_head['breadcrumb_list_name'] . $page_site;

$product_list_json_ld = [
    '@type' => 'CollectionPage',
    "@id" => $current_url. "#webpage",
    'url' => $current_url,
    'name' => $title_name,
    'description' => $description_content,
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
        "@id" => $current_url. "#itemlist"
    ],
];
$breadcrumb_list_json_ld = [
    '@type' => 'BreadcrumbList',
    "@id" => $current_url. "#breadcrumb",
    'name' => get_the_title(),
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
            'name' => $current_lang_json['footer']["products"] ?? '',
            'item' => get_url('products')
        ]
    ],
];
if ($parent_title) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => get_the_title(),
        'item' => $current_url
    ];
}
$products_list = [];
foreach ($product_info_list as $index => $product_info) {
    $products_list[] = [
        '@type'    => 'ListItem',
        'position' => $index + 1,
        'url'   => get_permalink($product_info['ID']),
        'name'   => get_the_title($product_info['ID'])
    ];
}
$item_list_json_ld = [
    '@type' => 'ItemList',
    "@id" => $current_url. "#itemlist",
    "name" => $breadcrumb_list_name,
    "numberOfItems" => count($products_list),
    'itemListElement' => $products_list
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$product_list_json_ld, $breadcrumb_list_json_ld, $item_list_json_ld]
];

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('products-style', tpi_asset('/css/products.css'), array(), $version);
//    wp_enqueue_script('products-js',tpi_asset('/js/products.js'), array(), $version, true);
});
get_header();
?>
    <div class="frame-wrap">
        <div class="frame-top-wrap" aria-label="Product Top">
            <a href="<?php echo go_url("/products");?>">
                <img src="<?php echo $page_info_settings['product_top_image'];?>"
                     alt="<?php echo esc_attr(get_image_alt($page_info_settings['product_top_image'])); ?>">
                <div class="frame-top-title">
                    <?php the_title(); ?>
                </div>
            </a>
        </div>
        <div class="frame-box">
            <div class="frame-nav-wrap" aria-label="Breadcrumb">
                <a class="point-to" href="<?php echo go_url("/home"); ?>">
                    <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                    <span class="title-menu"><?php echo $current_lang_json["footer"]["home"];?></span>
                </a>
                <?php if ($parent_title) { ?>
                    <a class="point-to" href="<?php echo go_url("/products"); ?>">
                        <span class="title-menu">
                            <?php echo esc_html($parent_title); ?>
                        </span>
                    </a>
                <?php } ?>
                <span aria-current="page"><?php the_title(); ?></span>
            </div>
            <div class="frame-content-box">
                <div class="frame-content-box-wrap">
                    <?php get_product_fixed(); ?>
                    <div class="frame-content">
                        <h1 class="page-title">
                            <?php echo $title_name; ?>
                        </h1>
                        <p class="p-desc"><?php echo $description_content;?></p>
                        <div class="products-info-list-wrap">
                            <?php get_change_layout(); ?>
                            <div class="products-list-wrap">
                                <ul class="products-info-wrap-box" aria-label="Product List">
                                    <?php foreach ($product_info_list as $product_info): ?>
                                    <li>
                                        <a class="product-info-item" href="<?php echo esc_url($product_info['permalink']);?>" >
                                            <div class="product-info-item-image">
                                                <img src="" data-lazy="<?php echo esc_url($product_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>">
                                            </div>
                                            <div class="product-info-item-desc">
                                                <div class="product-info-item-title"><?php echo esc_html($product_info['title'] ?? ""); ?></div>
                                                <div class="product-info-item-description"><?php echo esc_html($product_info['info']['description'] ?? ""); ?></div>
                                            </div>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php get_pagination(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>