<?php
global $wpdb, $page_info_settings, $current_lang_json, $current_url, $lang, $current_top_host;
$post_id = get_the_ID();
$terms = get_the_terms($post_id, 'applicable');
$application_type = "";
$application_slug = "";
if ($terms && !is_wp_error($terms)) {
    $first_term = reset($terms);
    $application_type = esc_html($first_term->name);
    $application_slug = esc_html($first_term->slug);
}
$application_info = tpi_item_fields($post_id, 'application');
$main_title = ($application_info['main_title'] ?? '') ?: get_the_title();
$image = $application_info['image'] ?? '';
$description = $application_info['description'] ?? '';
$content = $application_info['content'] ?? "";
$slug = spliceSlugLang($application_slug);
$valid_slug = $slug !== 'uncategorized' && $slug !== '';
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
$application_json_ld = get_application_jsonLd_data($application_info, $post_id);
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
            'name' => $current_lang_json['home']["applicable"] ?? '',
            'item' => get_url('applications')
        ]
    ],
];
if ($valid_slug) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => $application_type ?? '',
        'item' => go_url("/applications/". $application_slug)

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
    "@graph" => [$page_json_ld, $application_json_ld, $breadcrumb_list_json_ld]
];

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('application-style', tpi_asset('/css/application.css'), array(), $version);
    wp_enqueue_style('editor-content-style', tpi_asset('/css/editor-content.css'), array(), $version);
//    wp_enqueue_script('application-js',tpi_asset('/js/application.js'), array(), $version, true);
});
get_header();
?>
    <div class="frame-wrap">
        <section class="frame-top-wrap" aria-label="application Top">
            <a href="<?php echo go_url("/applications"); ?>">
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
                <a class="point-to" href="<?php echo go_url("/applications"); ?>">
                    <span class="title-menu">
                        <?php echo esc_html($current_lang_json["home"]["applicable"]); ?>
                    </span>
                </a>
                <?php if ($valid_slug) { ?>
                    <a class="point-to" href="<?php echo go_url("/applications/". $application_slug); ?>">
                        <span class="title-menu">
                            <?php echo esc_html($application_type); ?>
                        </span>
                    </a>
                <?php } ?>
                <span aria-current="page"><?php the_title(); ?></span>
            </div>
            <div class="frame-content-box">
                <div class="frame-content-box-wrap">
                    <?php get_product_fixed(); ?>
                    <article class="frame-content">
                        <h1 class="application-title"><?php echo $main_title; ?></h1>
                        <?php if (!($application_info['no_description_push'] ?? false)) { ?>
                            <p class="application-content-desc">
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