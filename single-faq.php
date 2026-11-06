<?php
global $wpdb, $page_info_settings, $current_lang_json, $current_url;
$post_id = get_the_ID();
$terms = get_the_terms($post_id, 'applicable');
$faq_type = "";
$faq_slug = "";
if ($terms && !is_wp_error($terms)) {
    $first_term = reset($terms);
    $faq_type = esc_html($first_term->name);
    $faq_slug = esc_html($first_term->slug);
}
$faq_info = tpi_item_fields($post_id, 'faq');
$image = $faq_info['image'] ?? '';
$content = $faq_info['content'] ?? "";
// h1 用 main_title：比列表里的短问题更完整；没配时回退标题
$faq_heading = trim((string) ($faq_info['main_title'] ?? '')) ?: get_the_title($post_id);
$slug = spliceSlugLang($faq_slug);
$valid_slug = $slug !== 'uncategorized' && $slug !== '';
// 摘要用 answer（一两句答案），比整段正文更适合做搜索结果描述
$description_content = trim(wp_strip_all_tags((string) ($faq_info['answer'] ?? ''), true));
if ($description_content === '') {
    $description_content = wp_strip_all_tags($content, true);
}
$home_url_path = go_url('home');

$faq_json_ld = get_faq_jsonLd_data($faq_info, $post_id);
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
            'item' => get_url('/question'),
        ]
    ],
];
if ($valid_slug) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => $faq_type ?? '',
        'item' => go_url("/question/". $faq_slug),
    ];
}
$breadcrumb_list_json_ld['itemListElement'][] = [
    '@type' => 'ListItem',
    'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
    'name' => get_the_title(),
    'item' => $current_url
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$faq_json_ld, $breadcrumb_list_json_ld]
];
add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('faq-style', tpi_asset('/css/faq.css'), array(), $version);
    wp_enqueue_style('editor-content-style', tpi_asset('/css/editor-content.css'), array(), $version);
//    wp_enqueue_script('faq-js',tpi_asset('/js/faq.js'), array(), $version, true);
});
//add_action('wp_head', function() {
//    global $faq_info, $post_id, $current_lang_json, $valid_slug, $faq_slug, $faq_type;
//    $data = get_faq_jsonLd_data($faq_info, $post_id);
//    $data['breadcrumb'] = [
//        '@type' => 'BreadcrumbList',
//        'name' => get_the_title($post_id),
//        'itemListElement' => [
//            [
//                '@type'    => 'ListItem',
//                'position' => 1,
//                'item'     => [
//                    '@id' => get_url('/question'),
//                    'name' => $current_lang_json['home']["faq"] ?? ''
//                ]
//            ]
//        ]
//    ];
//    if ($valid_slug) {
//        $data['breadcrumb']['itemListElement'][] = [
//            '@type'    => 'ListItem',
//            'position' => count($data['breadcrumb']['itemListElement'])+1,
//            'item'     => [
//                '@id' => go_url("/question/". $faq_slug),
//                'name' => $faq_type ?? ''
//            ]
//        ];
//    }
//    $data['breadcrumb']['itemListElement'][] = [
//        '@type'    => 'ListItem',
//        'position' => count($data['breadcrumb']['itemListElement'])+1,
//        'item'     => [
//            '@id' => get_permalink($post_id),
//            'name' => get_the_title($post_id)
//        ]
//    ];
//    echo '
//<script type="application/ld+json">
//'. wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT). '
//</script>';
//});
get_header();
// 与常见问题列表页保持一致：没单独设置时沿用文章顶部图
$faq_top_image = $page_info_settings['faq_top_image'] ?? '';
if ($faq_top_image === '') {
    $faq_top_image = $page_info_settings['post_top_image'] ?? '';
}
?>
    <div class="frame-wrap">
        <section class="frame-top-wrap" aria-label="FAQ Top">
            <a href="<?php echo go_url("/question"); ?>">
                <img src="<?php echo esc_url($faq_top_image); ?>" alt="<?php echo esc_attr(get_image_alt($faq_top_image)); ?>">
                <div class="frame-top-title"><?php the_title(); ?></div>
            </a>
        </section>
        <div class="frame-box">
            <div class="frame-nav-wrap" aria-label="Breadcrumb">
                <a class="point-to" href="<?php echo $home_url_path;; ?>">
                    <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                    <span class="title-menu"><?php echo $current_lang_json["footer"]["home"] ?? "";?></span>
                </a>
                <a class="point-to" href="<?php echo go_url("/question"); ?>">
                    <span class="title-menu">
                        <?php echo esc_html($current_lang_json["home"]["faq"]); ?>
                    </span>
                </a>
                <?php if ($valid_slug) { ?>
                    <a class="point-to" href="<?php echo go_url("/question/". $faq_slug); ?>">
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
                    <section class="frame-content">
                        <h1 class="faq-title"><?php echo esc_html($faq_heading); ?></h1>
                        <article class="editor-content">
                            <dl>
                                <dt class="faq-question">
                                    <button aria-expanded="false" aria-controls="faq-answer" class="faq-toggle">
                                        <?php echo esc_html(get_the_title($post_id)); ?>
                                    </button>
                                </dt>
                                <dd class="faq-answer" hidden>
                                    <?php echo $content; ?>
                                </dd>
                            </dl>
                        </article>
                        <div class="article-date-wrap">
                            <div class="article-create-date">
                                <img src="<?php echo esc_url(tpi_svg_url('Update', '7b8896')); ?>" alt="" aria-hidden="true">
                                <time><?php echo get_the_modified_date('Y-m-d', $post_id); ?></time>
                            </div>
                            <div class="article-up-date">
                                <img src="<?php echo esc_url(tpi_svg_url('Calendar', '7b8896')); ?>" alt="" aria-hidden="true">
                                <time><?php echo get_the_date('Y-m-d', $post_id); ?></time>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>