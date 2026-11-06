<?php
global $post, $page_info_settings, $current_lang_json, $current_url, $lang, $current_top_host, $description_content, $brand_name;
$routeInfo = routeCategoryParse();
$term_slug = $routeInfo['category']. ($routeInfo['lang'] ? '-'. $routeInfo['lang'] : '');
$paged = get_query_var('paged');
$page_site = $paged ? ( ' | '. $current_lang_json['functional']['page'].' '. $paged) : '';
$paged = intval($paged ?: 1);
$posts_per_page = 12;
$new_type = "";
if ($post->post_parent) { // 有父级
    $parent_id = $post->post_parent;
    $new_type = get_the_title($parent_id);
}
$term = $term_slug !== '' ? get_term_by('slug', $term_slug, 'category') : null; // 分类描述存 JSON 或纯文字
// 该语言下没有单独建分类时（slug 没带语言后缀），退回按 URL 里的 slug 再找一次
if (!$term && !empty($routeInfo['category'])) {
    $term = get_term_by('slug', (string) $routeInfo['category'], 'category');
}
$new_info_page = get_article_info_page(['posts_per_page' => $posts_per_page, 'paged' => $paged], $term ? [$term->term_id] : null);
$new_info_list = $new_info_page["data"] ?? [];
$total_num = $new_info_page['total_pages'] ?: 1;
$pageArray = range(1, $total_num) ;
$home_url_path = go_url('home');
// 标题 / 描述 / 面包屑名称：分类描述 JSON 优先，缺字段回落到语言包
$news_head = tpi_term_head($term, $current_lang_json['news'] ?? []);
$title_name = $news_head['title'] . $page_site;
$description_content = $news_head['description'] . $page_site;
$breadcrumb_list_name = $news_head['breadcrumb_list_name'] . $page_site;

$news_list_json_ld = [
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
            'name' => $current_lang_json['footer']["news"] ?? '',
            'item' => get_url('news')
        ]
    ],
];
if ($new_type) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => get_the_title(),
        'item' => $current_url
    ];
}
$news_list = [];
foreach ($new_info_list as $index => $info_list) {
    $news_list[] = [
        '@type'    => 'ListItem',
        'position' => $index + 1,
        'url'   => get_permalink($info_list['ID']),
        'name'   => get_the_title($info_list['ID']),
    ];
}
$item_list_json_ld = [
    '@type' => 'ItemList',
    "@id" => $current_url. "#itemlist",
    "name" => $breadcrumb_list_name,
    "numberOfItems" => count($news_list),
    'itemListElement' => $news_list
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$news_list_json_ld, $breadcrumb_list_json_ld, $item_list_json_ld]
];

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('news-style', tpi_asset('/css/news.css'), array(), $version);
//    wp_enqueue_script('news-js',tpi_asset('/js/news.js'), array(), $version, true);
});
get_header();
?>
    <div class="new-wrap">
        <div class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("/news");?>">
                <img src="<?php echo $page_info_settings['post_top_image'] ?? '';?>"
                     alt="<?php echo esc_attr(get_image_alt($page_info_settings['post_top_image'] ?? '')); ?>">
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
                <?php if ($new_type) { ?>
                    <a class="point-to" href="<?php echo go_url("/news"); ?>">
                        <span class="title-menu">
                            <?php echo esc_html($new_type); ?>
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
                        <div class="news-info-list-wrap">
                            <?php get_change_layout(); ?>
                            <div class="news-list-wrap">
                                <ul class="news-info-wrap-box" aria-label="News List">
                                    <?php foreach ($new_info_list as $new_info): ?>
                                        <li>
                                            <a class="new-info-item" href="<?php echo esc_url($new_info['permalink']);?>" >
                                                <div class="new-info-item-image">
                                                    <img src="" data-lazy="<?php echo esc_url($new_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($new_info['image'], $new_info['title'] ?? 'News')); ?>">
                                                </div>
                                                <div class="new-info-item-desc">
                                                    <div class="new-info-item-title"><?php echo esc_html($new_info['title'] ?? ""); ?></div>
                                                    <div class="new-info-item-description"><?php echo esc_html($new_info['info']['description'] ?? ""); ?></div>
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