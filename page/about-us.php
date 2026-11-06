<?php
global $page_info_settings, $pll_locale, $current_lang_json, $host_url, $host_url_from, $current_url, $lang, $current_top_host, $current_top_url, $description_content, $has_credential;
$about_content = $current_lang_json['about-us'];
$title_name = $current_lang_json['about-us']["title"] ?? get_the_title();
$description_content = $current_lang_json['about-us']["desc"] ?? $description_content;
$home_url_path = go_url('home');
$contact_page_json_ld = [
    '@type'    => 'AboutPage',
    "@id" => $current_url. "#webpage",
    'url'      => $current_url,
    'name'     => $current_lang_json['about-us']["title"] ?? '',
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
    ],
    'video' => [
        "@id" => $current_top_url. "#video"
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
$attachment_id = attachment_url_to_postid($page_info_settings['company_video'] ?? '');
$home_video_json_ld = [
    '@type' => 'VideoObject',
    "@id" => $current_top_url. "#video",
    'name' => $current_lang_json['home']["video-title"] ?? '',
    'description' => $current_lang_json['home']['video-desc'] ?? '',
    'thumbnailUrl' => $page_info_settings['company_video_image'] ?? '',
    'uploadDate'   => get_the_date('c', $attachment_id),
    'contentUrl'     => $page_info_settings['company_video'] ?? '',
    'publisher' => [
        "@id" => $current_top_host. "#organization"
    ],
];
$json_ld_data = [
    "@context" => "https://schema.org",
    "@graph" => [$contact_page_json_ld, $breadcrumb_list_json_ld, $organization_json_ld, $home_video_json_ld]
];
// 首页 / 关于我们各用一组图标：icon_list 里的 scope 决定归属，两页不再共用同一排
$credential_icons = get_credential_icons($has_credential);

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('about-us-style', tpi_asset('/css/about-us.css'), array(), $version);
    wp_enqueue_style('element-style', tpi_asset('/css/element.css'), array(), $version);
    wp_enqueue_script('element-js',tpi_asset('/js/element.js'), array(), $version, true);
    wp_enqueue_script('abouts-us-js',tpi_asset('/js/about-us.js'), array('element-js'), $version, true);
});
get_header();
?>
    <div class="about-wrap">
        <div class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("/about-us");?>">
                <img src="<?php echo $page_info_settings['about_top_image'] ?? '';?>"
                     alt="<?php echo esc_attr(get_image_alt($page_info_settings['about_top_image'] ?? '')); ?>">
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
                <span aria-current="page"><?php the_title(); ?></span>
            </div>
            <div class="about-us-container">
                <section class="about-hero">
                    <div class="hero-text">
                        <h1><?php echo $about_content["title"] ?? ''; ?></h1>
                        <p class="hero-sub"><?php echo $about_content['summary-subtitle'] ?? '';?></p>
                    </div>
                    <div class="stats-bar">
                        <?php foreach ($about_content['summary-info-items'] as $summary_info_item) :?>
                        <div class="stat-item">
                            <span class="stat-number"><?php echo $summary_info_item["value"]; ?></span>
                            <span class="stat-label"><?php echo $summary_info_item["title"]; ?></span>
                        </div>
                        <?php endforeach;?>
                    </div>
                </section>
                <section class="about-profile-section">
                    <div class="profile-container">
                        <div class="profile-text">
                            <?php if (!empty($about_content['company-overview-items'])) : ?>
                                <?php foreach ($about_content['company-overview-items'] as $index => $overview_item) :?>
                                    <?php $title_class = ($index === 0) ? 'section-title' : 'sub-title'; ?>
                                    <?php if (!empty($overview_item['title'])): ?>
                                        <h2 class="<?php echo $title_class; ?>"><?php echo $overview_item['title']; ?></h2>
                                    <?php endif; ?>
                                    <p><?php echo $overview_item['text'] ?? ""; ?></p>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-media">
                            <?php
                            // 没配视频时不要渲染 <video src="">，否则页面上是一块
                            // 黑色空播放器（0:00 + 控制条）。退成封面图，封面也没配就整块不显示。
                            $about_video  = trim((string) ($page_info_settings['company_video'] ?? ''));
                            $about_poster = trim((string) ($page_info_settings['company_video_image'] ?? ''));
                            ?>
                            <?php if ($about_video !== '') : ?>
                                <video controls width="100%" height="100%"
                                       title="<?php echo $current_lang_json['home']["video-title"] ?? '';?>"
                                       aria-label="<?php echo $current_lang_json['home']['video-desc'] ?? '';?>"
                                       poster="<?php echo esc_url($about_poster); ?>"
                                >
                                    <source src="<?php echo esc_url($about_video); ?>" type="video/mp4">
                                </video>
                            <?php elseif ($about_poster !== '') : ?>
                                <img class="profile-media-image"
                                     src="<?php echo esc_url($about_poster); ?>"
                                     alt="<?php echo esc_attr(get_image_alt($about_poster)); ?>"
                                     loading="lazy">
                            <?php endif; ?>
                            <p class="caption"><?php echo $about_content['video-summary-desc'] ?? '';?></p>
                        </div>
                    </div>
                    <ul class="certificates-icon-wrap" aria-label="Certificates Icon">
                        <?php foreach ($credential_icons as $icon_info) :?>
                            <li aria-label="<?php echo $icon_info['alt'] ?? ''; ?>">
                                <a href="<?php echo esc_url(get_credential_icon_link($has_credential, $icon_info, go_url('/certificates'))); ?>">
                                    <img src="<?php echo esc_url($icon_info['image_url'] ?? ''); ?>" alt="<?php echo esc_attr($icon_info['alt'] ?? 'Certificate'); ?>">
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <section class="about-strengths">
                    <h2 class="section-title"><?php echo $about_content["our-strengths"] ?? "Why Choose Us"; ?></h2>
                    <div class="strengths-grid">
                        <?php if (!empty($about_content['our-strengths-items'])) : ?>
                            <?php foreach ($about_content['our-strengths-items'] as $strengths_item) : ?>
                                <div class="strength-card">
                                    <div class="icon-box">
                                        <img src="<?php echo tpi_media_url((string) ($strengths_item['icon'] ?? '')); ?>" alt="Strength Icon" aria-hidden="true">
                                    </div>
                                    <p><?php echo $strengths_item['text'] ?? ""; ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="about-culture">
                    <div class="culture-container">
                        <?php $culture_image = tpi_media_full((string) ($page_info_settings['about_culture_image'] ?? '')); ?>
                        <?php if ($culture_image !== '') : ?>
                            <div class="culture-image">
                                <img src="<?php echo esc_url($culture_image); ?>"
                                     alt="<?php echo esc_attr($about_content['company-culture'] ?? 'Company Culture'); ?>"
                                     loading="lazy">
                            </div>
                        <?php endif; ?>
                        <div class="culture-content">
                            <h2 class="section-title"><?php echo $about_content["company-culture"] ?? "Our Culture"; ?></h2>
                            <div class="culture-list">
                                <?php if (!empty($about_content['company-culture-items'])) : ?>
                                    <?php foreach ($about_content['company-culture-items'] as $culture_item) :?>
                                        <div class="culture-item">
                                            <div class="culture-icon"></div>
                                            <div class="text-wrap">
                                                <h3>
                                                    <?php echo $culture_item['title'] ?? ""; ?>
                                                </h3>
                                                <p><?php echo $culture_item['text'] ?? ""; ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="about-specs">
                    <h2 class="section-title need-to-know-title"><?php echo $about_content["need-to-know"] ?? "Product Capabilities"; ?></h2>
                    <div class="specs-grid">
                        <?php if (!empty($about_content['need-to-know-items'])) : ?>
                            <?php foreach ($about_content['need-to-know-items'] as $know_item) :?>
                                <div class="spec-item">
                                    <h3><?php echo $know_item['title'] ?? ""; ?></h3>
                                    <p><?php echo $know_item['text'] ?? ""; ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
    </div>
<?php
get_footer();
?>