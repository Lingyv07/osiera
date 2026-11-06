<?php
global $wpdb, $page_info_settings, $current_lang_json, $article_page_map_uri_list, $post, $current_url, $current_top_host, $lang;
$post_id = get_the_ID();
$terms = get_the_terms($post_id, 'category');
$new_type = "";
$new_slug = "";
if ($terms && !is_wp_error($terms)) {
    $first_term = reset($terms);
    $new_type = esc_html($first_term->name);
    $new_slug = spliceSlugLang(esc_html($first_term->slug));
}
$new_info = tpi_item_fields($post_id, 'post');
$main_title = ($new_info['main_title'] ?? '') ?: get_the_title();
$image = $new_info['image'] ?? '';
$description = $new_info['description'] ?? '';
$content = $new_info['content'] ?? "";
$valid_slug = $new_slug !== 'uncategorized';
$description_content = $description;
$home_url_path = go_url('home');
$title_name_parts = explode(" |", get_the_title());
$title_name_string = $title_name_parts[0];
$page_json_ld = [
    '@type'    => 'WebPage',
    "@id" => $current_url. "#webpage",
    'url'      => $current_url,
    'name'     => get_the_title(),
    'description' => $description_content,
    'inLanguage' => tpi_current_locale_tag(),
    'isPartOf' => [
        "@id" => $current_top_host. "#website"
    ],
    'primaryImageOfPage' => [
        "@id" => $current_url. "#primaryimage"
    ],
    'mainEntity' => [
        '@id' => $current_url. '#article',
    ],
    'breadcrumb' => [
        "@id" => $current_url. "#breadcrumb"
    ],
];
$new_json_ld = get_new_jsonLd_data($new_info, $post_id);
$breadcrumb_list_json_ld = [
    '@type' => 'BreadcrumbList',
    "@id" => $current_url. "#breadcrumb",
    'name' => $title_name_string,
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
if ($valid_slug) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => $new_type ?? '',
        'item' => go_url("/news/". $new_slug),
    ];
}
$breadcrumb_list_json_ld['itemListElement'][] = [
    '@type' => 'ListItem',
    'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
    'name' => $title_name_string,
    'item' => $current_url
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$page_json_ld, $new_json_ld, $breadcrumb_list_json_ld]
];

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('new-style', tpi_asset('/css/new.css'), array(), $version);
    wp_enqueue_style('editor-content-style', tpi_asset('/css/editor-content.css'), array(), $version);
//    wp_enqueue_script('new-js',tpi_asset('/js/new.js'), array(), $version, true);
});
get_header();
?>
    <div class="frame-wrap">
        <section class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("/news"); ?>">
                <img src="<?php echo esc_url($page_info_settings['post_top_image']); ?>" alt="<?php echo esc_attr(get_image_alt($page_info_settings['post_top_image'])); ?>">
                <div class="frame-top-title"><?php the_title(); ?></div>
            </a>
        </section>
        <div class="frame-box">
            <div class="frame-nav-wrap" aria-label="Breadcrumb">
                <a class="point-to" href="<?php echo go_url("/home"); ?>">
                    <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                    <span class="title-menu"><?php echo $current_lang_json["footer"]["home"] ?? "";?></span>
                </a>
                <a class="point-to" href="<?php echo go_url("/news"); ?>">
                    <span class="title-menu">
                        <?php echo esc_html($current_lang_json["footer"]["news"]); ?>
                    </span>
                </a>
                <?php if ($valid_slug) { ?>
                    <a class="point-to" href="<?php echo go_url("/news/". $new_slug); ?>">
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
                    <article class="frame-content">
                        <h1 class="new-title"><?php echo $main_title; ?></h1>
                        <?php if (!($new_info['no_description_push'] ?? false)) { ?>
                        <p class="new-content-desc">
                            <?php echo esc_html($description); ?>
                        </p>
                        <?php } ?>
                        <div class="editor-content">
                            <?php echo $content; ?>
                        </div>
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
                    </article>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>