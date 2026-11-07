<?php
global $page_info_settings, $current_lang_json, $json_ld_data, $current_url, $lang, $current_top_host, $description_content, $has_credential;

$title_name = $current_lang_json['certificates']["title"] ?? get_the_title();
$description_content = $current_lang_json['certificates']["description"] ?? $description_content;
$home_url_path = go_url('home');
$web_page_json_ld = [
    '@type'    => 'WebPage',
    "@id" => $current_url. "#webpage",
    'url'      => $current_url,
    'name'     => $current_lang_json['certificates']["title"] ?? '',
    'description' => $description_content,
    'inLanguage' => tpi_current_locale_tag(),
    'isPartOf' => [
        "@id" => $current_top_host. "#website"
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
// 证书列表
$has_credential_list = [];
if ($has_credential) {
    $has_credential_list[] = [
        '@type' => $has_credential['hero_certificate']['type'] ?? '',
        'credentialCategory' => $has_credential['hero_certificate']['credential_category'] ?? '',
        'name' => $has_credential['hero_certificate']['name'] ?? '',
        'url' => $has_credential['hero_certificate']['file_url'] ?? '',
    ];
    foreach ($has_credential['certificate_groups'] as $certificate_group) {
        foreach ($certificate_group['items'] as $certificate_item) {
            $has_credential_list[] = [
                '@type' => $certificate_item['type'] ?? '',
                'credentialCategory' => $certificate_item['credential_category'] ?? '',
                'name' => $certificate_item['name'] ?? '',
                'url' => $certificate_item['file_url'] ?? '',
            ];
        }
    }

    $organization_json_ld['hasCredential'] = $has_credential_list;
}
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$web_page_json_ld, $breadcrumb_list_json_ld, $organization_json_ld]
];
add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('new-style', tpi_asset('/css/certificates.css'), array(), $version);
});
add_action('wp_head', function () {
    ?>
<script>
    let load_num = 0;
    let observer;
    function remove_load($event) {
        $event.parentElement.classList.remove("v-load");
        load_num++;
        let main_box = document?.querySelector('.frame-content-box');
        let image_list = main_box?.querySelectorAll('.image-preview-a img');
        if (observer && image_list.length && load_num === image_list.length) {
            observer.disconnect();
        }
    }
</script>
<?php
});
add_action('wp_footer', function () {
    ?>
    <script>
        let main_box = document.querySelector('.frame-content-box');
        let image_list = main_box.querySelectorAll('.image-preview-a img');
        let load_count = 0;
        observer = new ResizeObserver(entries => {
            if (entries && entries?.[0])
            for (let enter of entries) {
                if (enter.target.offsetHeight > 10) {
                    enter.target.parentElement.classList.remove("v-load");
                    observer.unobserve(enter.target);
                    load_count++;
                    if (load_count === image_list.length) {
                        observer.disconnect();
                    }
                }
            }
        });
        for (let img of image_list) {
            observer.observe(img);
        }
    </script>
    <?php
});
get_header();
?>
    <div class="frame-wrap">
        <section class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("privacy-policy"); ?>">
                <img src="<?php echo esc_url($page_info_settings['certificates_top_image'] ?? ''); ?>"
                     alt="<?php echo esc_attr(get_image_alt($page_info_settings['certificates_top_image'] ?? '')); ?>">
                <div class="frame-top-title"><?php the_title(); ?></div>
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
                <div class="frame-content-box-wrap">
                    <?php
                    $data = $has_credential;
                    if ($data) {
                        ?>
                    <div class="cert-page-container">
                        <section class="cert-header">
                            <h1><?php echo $data['page_header']['title']; ?></h1>
                            <p><?php echo $data['page_header']['description']; ?></p>
                        </section>
                        <section class="cert-hero-section">
                            <div class="iso-card" id="<?php echo $data['hero_certificate']['id']; ?>">
                                <div class="iso-image">
                                    <a class="image-preview-a v-load" href="<?php echo $data['hero_certificate']['file_url']; ?>" target="_blank">
                                        <img src="<?php echo $data['hero_certificate']['image_url']; ?>"
                                             alt="<?php echo esc_attr($data['hero_certificate']['image_alt'] ?: ($data['hero_certificate']['title'] ?? 'Certificate')); ?>"
                                             onload="remove_load(this)"
                                             onerror="remove_load(this)"
                                             width="800" height="auto">
                                    </a>
                                </div>
                                <div class="iso-content">
                                    <a href="<?php echo $data['hero_certificate']['target_href'] ?? ''; ?>" target="_blank" rel="noopener noreferrer nofollow" class="badge" aria-label="<?php echo $data['hero_certificate']['title']; ?>"><?php echo $data['hero_certificate']['badge_text']; ?></a>
                                    <h2><?php echo $data['hero_certificate']['title']; ?></h2>
                                    <ul class="cert-specs">
                                        <?php foreach ($data['hero_certificate']['specs'] as $spec): ?>
                                            <li><strong><?php echo $spec['label']; ?>:</strong> <?php echo $spec['value']; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <a href="<?php echo $data['hero_certificate']['file_url']; ?>" class="btn-download" target="_blank">
                                        <?php echo $data['hero_certificate']['button_text']; ?>
                                    </a>
                                </div>
                            </div>
                        </section>
                        <?php foreach ($data['certificate_groups'] as $group): ?>
                            <section class="cert-grid-section">
                                <h2 class="section-title"><?php echo $group['group_title']; ?></h2>
                                <div class="cert-grid">
                                    <?php foreach ($group['items'] as $item): ?>
                                        <div class="cert-card" <?php  if (isset($item['id'])) { echo 'id="'.$item['id'].'"';}?>>
                                            <div>
                                                <a class="image-preview-a v-load" href="<?php echo $item['file_url']; ?>" target="_blank">
                                                    <img onload="remove_load(this)" onerror="remove_load(this)" src="<?php echo esc_url($item['image_url']); ?>" alt="<?php echo esc_attr($item['image_alt'] ?: ($item['title'] ?? 'Certificate')); ?>">
                                                </a>
                                                <h3><?php echo $item['title']; ?></h3>
                                                <?php if($item['report_no'] ?? '') { ?>
                                                <p class="report-no"><?php echo $item['report_no']; ?></p>
                                                <?php } ?>
                                                <p class="cert-standard"><?php echo $item['standard_text']; ?></p>
                                                <p class="cert-desc"><?php echo $item['description_text']; ?></p>
                                            </div>
                                            <a href="<?php echo $item['file_url']; ?>" class="text-link" target="_blank">
                                                <?php echo $item['link_text']; ?>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endforeach; ?>
                        </div>
                        <?php };?>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>