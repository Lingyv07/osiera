<?php
global $post, $page_info_settings, $current_lang_json, $description_content, $current_url, $lang, $current_top_host;
$routeInfo = routeCategoryParse();
$term_slug = $routeInfo['category']. ($routeInfo['lang'] ? '-'. $routeInfo['lang'] : '');
$paged = get_query_var('paged');
$page_site = $paged ? ( ' | '. $current_lang_json['functional']['page'].' '. $paged) : '';
$paged = intval($paged ?: 1);
$posts_per_page = 12;
$faq_type = "";
if ($post->post_parent) { // 有父级
    $parent_id = $post->post_parent;
    $faq_type = get_the_title($parent_id);
}
$term = $term_slug !== '' ? get_term_by('slug', $term_slug, 'faq_cat') : null; // 分类描述存 JSON 或纯文字
// 该语言下没有单独建分类时（slug 没带语言后缀），退回按 URL 里的 slug 再找一次
if (!$term && !empty($routeInfo['category'])) {
    $term = get_term_by('slug', (string) $routeInfo['category'], 'faq_cat');
}
$faq_info_page = get_faq_info_page(['posts_per_page' => $posts_per_page, 'paged' => $paged], $term ? [$term->term_id] : null);
$faq_info_list = $faq_info_page["data"] ?? [];
$total_num = $faq_info_page['total_pages'] ?: 1;
$pageArray = range(1, $total_num) ;
$home_url_path = go_url('home');
// 标题 / 描述 / 面包屑名称：分类描述 JSON 优先，缺字段回落到语言包
$faq_head = tpi_term_head($term, $current_lang_json['faq'] ?? []);
$title_name = $faq_head['title'] . $page_site;
$description_content = $faq_head['description'] . $page_site;
$breadcrumb_list_name = $faq_head['breadcrumb_list_name'] . $page_site;

$faq_list_json_ld = [
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
            'name' => $current_lang_json['home']["faq"] ?? '',
            'item' => get_url('question')
        ]
    ],
];
if ($faq_type) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => get_the_title(),
        'item' => $current_url
    ];
}
$faq_list = [];
foreach ($faq_info_list as $index => $info_list) {
    $faq_list[] = [
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
    "numberOfItems" => count($faq_list),
    'itemListElement' => $faq_list
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$faq_list_json_ld, $breadcrumb_list_json_ld, $item_list_json_ld]
];
add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('question-style', tpi_asset('/css/question.css'), array(), $version);
//    wp_enqueue_script('question-js',tpi_asset('/js/question.js'), array(), $version, true);
});
get_header();
// 常见问题顶部图未设置时沿用文章顶部图，老站不会突然空一块
$faq_top_image = $page_info_settings['faq_top_image'] ?? '';
if ($faq_top_image === '') {
    $faq_top_image = $page_info_settings['post_top_image'] ?? '';
}
?>
    <div class="faq-wrap">
        <div class="frame-top-wrap" aria-label="FAQ Top">
            <a href="<?php echo go_url("/question");?>">
                <img src="<?php echo esc_url($faq_top_image);?>"
                     alt="<?php echo esc_attr(get_image_alt($faq_top_image)); ?>">
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
                <?php if ($faq_type) { ?>
                    <a class="point-to" href="<?php echo go_url("/question"); ?>">
                        <span class="title-menu">
                            <?php echo esc_html($faq_type); ?>
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
                        <div class="question-info-list-wrap">
                            <?php get_change_layout(); ?>
                            <div class="question-list-wrap">
                                <ul class="question-info-wrap-box" aria-label="Question List">
                                    <?php foreach ($faq_info_list as $faq_info): ?>
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
                                <?php get_pagination(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>