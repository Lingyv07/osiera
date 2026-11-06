<?php
global $page_info_settings, $pll_locale, $current_lang_json, $google_map_url, $whatsapp_url, $current_url, $lang, $current_top_host, $description_content, $google_map_src;
$title_name = $current_lang_json['contact-us']["title"] ?? get_the_title();
$description_content = $current_lang_json['contact-us']["description"] ?? $description_content;
$home_url_path = go_url('home');
$contact_page_json_ld = [
    '@type'    => 'ContactPage',
    "@id" => $current_url. "#webpage",
    'url'      => $current_url,
    'name'     => $title_name,
    'description' => $description_content,
    'inLanguage' => tpi_current_locale_tag(),
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
$organization_json_ld['location'] = [
    '@type' => 'Place',
    'hasMap' => $google_map_src,
    'address' => [
        "@type" => "PostalAddress",
        "streetAddress" => $page_info_settings['address'] ?? ''
    ]
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$contact_page_json_ld, $breadcrumb_list_json_ld, $organization_json_ld]
];

$copy_success = $current_lang_json["footer"]["copy-success"] ?? '';

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('contact-style', tpi_asset('/css/contact.css'), array(), $version);
    wp_enqueue_script('contact-js',tpi_asset('/js/contact.js'), array('functional-theme-js'), $version, true);
});
get_header();
?>
<div class="contact-wrap">
    <div class="frame-top-wrap" aria-label="News Top">
        <a href="<?php echo go_url("/contact");?>">
            <img src="<?php echo $page_info_settings['contact_top_image'] ?? '';?>"
                 alt="<?php echo esc_attr(get_image_alt($page_info_settings['contact_top_image'] ?? '')); ?>">
            <h1 class="frame-top-title">
                <?php the_title(); ?>
            </h1>
        </a>
    </div>
    <div class="frame-box">
        <div class="frame-nav-wrap" aria-label="Breadcrumb">
            <a class="point-to" href="<?php echo go_url("/home"); ?>">
                <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["home"];?></span>
            </a>
            <span aria-current="page"><?php the_title(); ?></span>
        </div>
        <div class="frame-content-box">
            <div class="frame-content-box-wrap">
                <ul class="contact-us-box" aria-label="Common Contact Us">
                    <li class="contact-telephone-info">
                        <div class="contact-icon-box">
                                <img src="<?php echo esc_url(tpi_svg_url('Telephone')); ?>" alt="" aria-hidden="true">
                        </div>
                        <div class="contact-info-wrap">
                            <em><?php echo $current_lang_json["footer"]["telephone-title"];?></em>
                            <div class="telephone-and-whatsApp">
                                <p  class="copy-info-box" data-message="<?php echo $copy_success. ': '. ($page_info_settings['telephone'] ?? '');?>">
                                    <a class="call-now" rel="nofollow" href="tel:<?php echo $page_info_settings['telephone'] ?? '';?>" aria-label="Call us at <?php echo $page_info_settings['telephone'] ?? '';?>1">
                                        <strong class="label"><?php echo $current_lang_json["footer"]["telephone-title"] ?? '';?>: </strong>
                                        <span class="info-desc  copy-info-desc"><?php echo $page_info_settings['telephone'] ?? '';?></span>
                                    </a>
                                </p>
                                <p>
                                    <a class="call-now" rel="nofollow" target="_blank" href="<?php echo $whatsapp_url;?>"  aria-label="Call us at <?php echo $page_info_settings['whats_app'] ?? '';?>">
                                        <strong class="label"><?php echo $current_lang_json["footer"]["whats-app-title"] ?? '';?>: </strong>
                                        <span class="info-desc"><?php echo $page_info_settings['whats_app'] ?? '';?></span>
                                    </a>
                                </p>
                            </div>
                        </div>
                    </li>
                    <li class="contact-mail-info copy-info-box copy-email-element" data-message="<?php echo $copy_success. ': '. ($page_info_settings['email'] ?? '');?>">
                        <div class="contact-icon-box">
                            <img src="<?php echo esc_url(tpi_svg_url('Email')); ?>" alt="" aria-hidden="true">
                        </div>
                        <div class="contact-info-wrap">
                            <em><?php echo $current_lang_json["footer"]["email-title"];?></em>
                            <p >
                                <a rel="nofollow" target="_blank" href="mailto:<?php echo $page_info_settings['email'] ?? '';?>"  aria-label="Email Contact">
                                    <span class="info-desc copy-info-desc email-info-text"><?php echo $page_info_settings['email'] ?? '';?></span>
                                </a>
                                <span class="copy-email-warning"><?php echo $current_lang_json["footer"]["copy-email"] ?? '';?></span>
                            </p>
                        </div>
                    </li>
                    <li class="contact-address-info">
                        <div class="contact-icon-box">
                                <img src="<?php echo esc_url(tpi_svg_url('Address')); ?>" alt="" aria-hidden="true">
                        </div>
                        <div class="contact-info-wrap">
                            <em><?php echo $current_lang_json["footer"]["address-title"];?></em>
                            <p class="address-desc copy-info-box" data-message="<?php echo $copy_success;?>">
                                <span class="address-desc copy-info-desc"><?php echo $page_info_settings["address"] ?? '';?></span>
                            </p>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="map-iframe">
                <iframe
                        src="<?php echo $google_map_url;?>"
                        width="100%"
                        height="500"
                        style="border:0;"
                        allowfullscreen
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="<?php echo $current_lang_json["footer"]["address-title"]. ':'. ($page_info_settings['address'] ?? '');?>"
                        rel="nofollow"
                >
                </iframe>
            </div>
            </div>
    </div>
</div>
<?php
get_footer();
?>