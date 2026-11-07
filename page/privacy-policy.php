<?php
global $page_info_settings, $current_lang_json, $current_url, $lang, $current_top_host, $description_content;
$title_name = $current_lang_json['privacy-policy']["title"] ?? get_the_title();
$description_content = $current_lang_json['privacy-policy']["description"] ?? $description_content;
$home_url_path = go_url('home');
$contact_page_json_ld = [
    '@type'    => 'WebPage',
    "@id" => $current_url. "#webpage",
    'url'      => $current_url,
    'name'     => $title_name,
    'description' => $description_content,
    'inLanguage' => tpi_current_locale_tag(),
    'datePublished' => utc_time_format(get_the_date('c')),
    'dateModified'  => utc_time_format(get_the_modified_date('c')),
    'isPartOf' => [
        "@id" => $current_top_host. "#website"
    ],
    'about' => [
        "@id" => $current_top_host. "#organization"
    ],
    'mainEntity' => [
        "@id" => $current_top_host. "#organization"
    ],
    'breadcrumb' => [
        "@id" => $current_url. "#breadcrumb"
    ]
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
            'name' => get_the_title(),
            'item' => $current_url
        ]
    ],
];
$organization_json_ld = Organization_JSON_LD();
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$contact_page_json_ld, $breadcrumb_list_json_ld, $organization_json_ld]
];
add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('new-style', tpi_asset('/css/privacy-policy.css'), array(), $version);
//    wp_enqueue_script('new-js',tpi_asset('/js/privacy-policy.js'), array(), $version, true);
    wp_enqueue_style('editor-content-style', tpi_asset('/css/editor-content.css'), array(), $version);
});
get_header();
?>
    <div class="frame-wrap">
        <section class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("privacy-policy"); ?>">
                <img src="<?php echo esc_url($page_info_settings['post_top_image'] ?? ''); ?>" alt="<?php echo esc_attr(get_image_alt($page_info_settings['post_top_image'])); ?>">
                <h1 class="frame-top-title"><?php the_title(); ?></h1>
            </a>
        </section>
        <div class="frame-box">
            <div class="frame-nav-wrap" aria-label="Breadcrumb">
                <a class="point-to" href="<?php echo go_url("/home"); ?>">
                    <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                    <span class="title-menu"><?php echo $current_lang_json["footer"]["home"] ?? "";?></span>
                </a>
                <span aria-current="page"><?php the_title(); ?></span>
            </div>
            <div class="frame-content-box">
                <div class="frame-content-box-wrap editor-content">
                    <?php the_content(); ?>
                    <div class="privacy-policy-date">
                        <img src="<?php echo esc_url(tpi_svg_url('Update', '7b8896')); ?>" alt="" aria-hidden="true">
                        <time><?php echo get_the_modified_date('Y-m-d'); ?></time>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>