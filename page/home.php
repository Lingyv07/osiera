<?php
global $current_lang_json, $page_info_settings, $lang, $json_ld_data, $current_url, $current_top_host, $current_top_url, $brand_name, $has_credential;
$images = json_decode($page_info_settings["images"] ?? '[]', true);
$product_per_page = 4; // 桌面一行四个；第 5 类留在 /products，避免第二行只挂一张卡
$application_per_page = 12;
$news_posts_per_page = 6;
$product_info_list = get_product_info_page(['posts_per_page' => $product_per_page, 'paged' => 1])["data"] ?? [];
$application_info_list = get_application_info_page(['posts_per_page' => $application_per_page, 'paged' => 1])["data"] ?? [];
$new_info_list = get_article_info_page(['posts_per_page' => $news_posts_per_page, 'paged' => 1])["data"] ?? [];
$attachment_id = attachment_url_to_postid($page_info_settings['company_video'] ?? '');
$banner_image = $page_info_settings['home_main_image'] ?? '';
// 走本地文件取宽高，别对自家 URL 发 HTTP 请求（单进程 PHP 会卡满 60s 超时）
$banner_image_size = $banner_image !== '' ? (tpi_image_size_local($banner_image) ?? [0, 0]) : [0, 0];
$title_name = $current_lang_json['home']["title"];
$description_content = $current_lang_json['home']["description"];

$webSite_json_ld = WebSite_JSON_LD();
$organization_json_ld = Organization_JSON_LD();
$home_json_ld = [
    '@type'    => 'WebPage',
    "@id" => $current_url. "#webpage",
    'url'      => home_url('/'),
    'name'     => $current_lang_json['home']["title"] ?? '',
    'description' => $current_lang_json['home']['description'] ?? '',
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
    'primaryImageOfPage' => [
        "@id" => $current_url. "#primaryimage"
    ],
    'image' => [
        '@type' => 'ImageObject',
        "@id" => $current_url. "#primaryimage",
        'url'   => $banner_image,
        'width' => $banner_image_size[0],
        'height' => $banner_image_size[1],
        'caption' => get_image_alt($banner_image),
    ],
    'video' => [
        "@id" => $current_top_url. "#video"
    ]
];
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
    "@graph" => [$webSite_json_ld, $organization_json_ld, $home_json_ld, $home_video_json_ld]
];
// 首页 / 关于我们各用一组图标：icon_list 里的 scope 决定归属，两页不再共用同一排
$credential_icons = get_credential_icons($has_credential);

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('home-style', tpi_asset('/css/home.css'), array(), $version);
    wp_enqueue_style('element-style', tpi_asset('/css/element.css'), array(), $version);
    wp_enqueue_script('element-js',tpi_asset('/js/element.js'), array(), $version, true);
    wp_enqueue_script('home-js',tpi_asset('/js/home.js'), array("element-js"), $version, true);
});
get_header();
?>
    <section class="images" aria-label="Home Swipe Image">
        <div id="swipe" class="swipe">
            <div class="swipe-track">
                <?php if (!empty($images)): foreach($images as $img):
                    $slide_src = tpi_media_url($img['src'] ?? '');
                    if ($slide_src === '') { continue; }
                    // 站内路径拼当前语言前缀；外链原样（go_url 只认站内路径）
                    $slide_route = tpi_route_rel($img['route'] ?? '');
                    $slide_href  = preg_match('#^https?://#i', $slide_route) ? $slide_route : go_url($slide_route);
                    ?>
                        <div class="swipe-slide">
                            <a href="<?php echo esc_url($slide_href); ?>">
                                <img src="<?php echo esc_url($slide_src); ?>" class="home-image" alt="<?php echo esc_attr(get_image_alt($slide_src)); ?>">
                            </a>
                        </div>
                <?php endforeach; endif; ?>
            </div>
            <button class="carousel-btn prev">‹</button>
            <button class="carousel-btn next">›</button>
            <div class="swipe-indicators"></div>
        </div>
    </section>
    <section class="about-hero">
        <div class="hero-text">
            <h1><?php echo $current_lang_json['home']["page-title"] ?? ''; ?></h1>
            <p class="section-description hero-sub"><?php echo $current_lang_json['home']['description'] ?? '';?></p>
            <p class="hero-sub"><?php echo $current_lang_json['home']['summary-subtitle'] ?? '';?></p>
        </div>
        <div class="stats-bar">
            <?php foreach ($current_lang_json['about-us']['summary-info-items'] as $summary_info_item) :?>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $summary_info_item["value"]; ?></span>
                    <span class="stat-label"><?php echo $summary_info_item["title"]; ?></span>
                </div>
            <?php endforeach;?>
        </div>
    </section>
    <section class="our-products-wrap" aria-label="Our Products">
        <h2 class="top-h2"><?php echo $current_lang_json['home']["our-products"] ?? ""; ?></h2>
        <p class="section-description">
            <?php echo $current_lang_json['home']["our-products-desc"] ?? ""; ?>
        </p>
        <div class="home-product-wrap pc-products-wrap" aria-label="PC Our Products">
            <div class="swipe-track" role="list">
            <?php foreach ($product_info_list as $product_info):?>
                    <div class="swipe-slide" role="listitem">
                        <a href="<?php echo esc_url($product_info['permalink']); ?>">
                            <div>
                                <div class="home-product-image">
                                    <?php if (!empty($product_info['image'])): ?><img src="<?php echo esc_url($product_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>"><?php endif; ?>
                                </div>
                                <div class="home-product-desc-wrap">
                                    <div class="home-product-title" data-nosnippet><?php echo esc_html($product_info['title'] ?? ''); ?></div>
                                    <div class="home-product-desc" data-nosnippet><?php echo esc_html($product_info['info']['description'] ?? '');?></div>
                                    <button class="home-product-item-btn"><?php echo $current_lang_json["product"]["read-more"];?></button>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="home-product-wrap mobile-products-wrap swipe" aria-label="PC Our Products">
           <div class="swipe-track" role="list">
            <?php foreach ($product_info_list as $product_info): ?>
               <div class="swipe-slide" role="listitem">
                    <a href="<?php echo esc_url($product_info['permalink']);?>">
                        <article>
                            <div class="home-product-image">
                                <?php if (!empty($product_info['image'])): ?><img src="<?php echo esc_url($product_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>"><?php endif; ?>
                            </div>
                            <div class="home-product-desc-wrap">
                                <h3 class="home-product-title" data-nosnippet><?php echo esc_html($product_info['title'] ?? ''); ?></h3>
                                <p class="home-product-desc" data-nosnippet><?php echo esc_html($product_info['info']['description'] ?? '');?></p>
                                <button class="home-product-item-btn"><?php echo $current_lang_json["product"]["read-more"];?></button>
                            </div>
                        </article>
                    </a>
               </div>
            <?php endforeach; ?>
            </div>
            <div class="swipe-indicators"></div>
        </div>
    </section>
    <section class="applicable-wrap" aria-label="Applicable">
        <h2 class="top-h2"><?php echo $current_lang_json['home']["applicable"] ?? ""; ?></h2>
        <p class="section-description">
            <?php echo $current_lang_json['home']["applicable-desc"] ?? ""; ?>
        </p>
        <div class="home-application-wrap pc-applications-wrap" aria-label="PC Our Applications">
            <div class="swipe-track" role="list">
                <?php foreach ($application_info_list as $application_info):?>
                    <div class="swipe-slide" role="listitem">
                        <a href="<?php echo esc_url($application_info['permalink']); ?>">
                            <div>
                                <div class="home-application-image">
                                    <?php if (!empty($application_info['image'])): ?><img src="<?php echo esc_url($application_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($application_info['image'], $application_info['title'] ?? 'Application')); ?>"><?php endif; ?>
                                </div>
                                <div class="home-application-desc-wrap">
                                    <div class="home-application-title" data-nosnippet><?php echo esc_html($application_info['title'] ?? ''); ?></div>
                                    <div class="home-application-desc" data-nosnippet><?php echo esc_html($application_info['info']['description'] ?? '');?></div>
                                    <button class="home-application-item-btn"><?php echo $current_lang_json["product"]["read-more"];?></button>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="home-application-wrap mobile-applications-wrap swipe" aria-label="PC Our Applications">
            <div class="swipe-track" role="list">
                <?php foreach ($application_info_list as $application_info): ?>
                    <div class="swipe-slide" role="listitem">
                        <a href="<?php echo esc_url($application_info['permalink']);?>">
                            <article>
                                <div class="home-application-image">
                                    <?php if (!empty($application_info['image'])): ?><img src="<?php echo esc_url($application_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($application_info['image'], $application_info['title'] ?? 'Application')); ?>"><?php endif; ?>
                                </div>
                                <div class="home-application-desc-wrap">
                                    <h3 class="home-application-title" data-nosnippet><?php echo esc_html($application_info['title'] ?? ''); ?></h3>
                                    <p class="home-application-desc" data-nosnippet><?php echo esc_html($application_info['info']['description'] ?? '');?></p>
                                    <button class="home-application-item-btn"><?php echo $current_lang_json["product"]["read-more"];?></button>
                                </div>
                            </article>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="swipe-indicators"></div>
        </div>
    </section>
    <?php
    // 自定义区块背景视频：后台「页面信息设置 → 视频」里配了才渲染，
    // 没配就保持原来的渐变 + 网格底，别留一个空 video 层
    $custom_bg_video_src = trim((string) ($page_info_settings['custom_bg_video'] ?? ''));
    $custom_bg_video_url = $custom_bg_video_src !== '' ? tpi_media_url($custom_bg_video_src) : '';
    ?>
    <section class="contact-us-info-wrap<?php echo $custom_bg_video_url !== '' ? ' has-bg-video' : ''; ?>" aria-label="<?php echo esc_attr($current_lang_json['home']['custom-title'] ?? 'Contact US'); ?>">
        <?php if ($custom_bg_video_url !== '') : ?>
            <video class="contact-us-bg-video" autoplay muted loop playsinline preload="metadata" tabindex="-1" aria-hidden="true">
                <source src="<?php echo esc_url($custom_bg_video_url); ?>" type="video/mp4">
            </video>
        <?php endif; ?>
        <div class="contact-us-info-desc">
            <h2><?php echo $current_lang_json['home']["custom-title"] ?? ""; ?></h2>
            <p><?php echo $current_lang_json['home']["custom-desc"] ?? ""; ?></p>
            <div class="flex">
                <a href="<?php echo go_url('/contact'); ?>" class="contact-us-btn">
                    <?php echo $current_lang_json['home']["custom-btn-text"] ?? ""; ?>
                </a>
            </div>
        </div>
    </section>
    <section aria-label="About US">
        <div class="about-us-info-wrap">
            <div class="about-us-iframe-wrap">
                <?php
                // 与 about-us 同一套逻辑：没配视频就只显示封面图，别留一个空播放器
                $home_video  = trim((string) ($page_info_settings['company_video'] ?? ''));
                $home_poster = trim((string) ($page_info_settings['company_video_image'] ?? ''));
                ?>
                <?php if ($home_video !== '') : ?>
                    <video controls width="100%" height="100%"
                           title="<?php echo $current_lang_json['home']["video-title"] ?? '';?>"
                           aria-label="<?php echo $current_lang_json['home']['video-desc'] ?? '';?>"
                           poster="<?php echo esc_url($home_poster); ?>"
                    >
                        <source src="<?php echo esc_url($home_video); ?>" type="video/mp4">
                    </video>
                <?php elseif ($home_poster !== '') : ?>
                    <img src="<?php echo esc_url($home_poster); ?>"
                         alt="<?php echo esc_attr(get_image_alt($home_poster)); ?>"
                         loading="lazy">
                <?php endif; ?>
            </div>
            <div class="about-us-desc-wrap">
                <h2><?php echo $current_lang_json['about-us']["title"] ?? ""; ?></h2>
                <?php foreach ($current_lang_json['about-us']['company-overview-items'] as $overview_item) :?>
                    <p><?php echo $overview_item['text'] ?? ""; ?></p>
                <?php endforeach; ?>
                <div class="flex">
                    <a href="<?php echo go_url('/about-us'); ?>" class="about-us-btn">
                        <?php echo $current_lang_json['home']['about-us-more'] ?? ""; ?>
                    </a>
                </div>
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
    <section class="our-strengths-info-wrap" aria-label="About US">
        <h2 class="top-h2"><?php echo $current_lang_json['about-us']["our-strengths"] ?? ""; ?></h2>
        <div class="strengths-grid">
            <?php if (!empty($current_lang_json['about-us']['our-strengths-items'])) : ?>
                <?php foreach ($current_lang_json['about-us']['our-strengths-items'] as $strengths_item) :?>
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
    <section class="latest-news-wrap" aria-label="About US">
        <h2 class="top-h2"><?php echo $current_lang_json['home']['latest-news'] ?? ""; ?></h2>
        <div class="news-info-wrap-box" aria-label="Latest News List" role="list">
            <?php foreach ($new_info_list as $new_info): ?>
                <div class="news-info-item" role="listitem">
                    <a href="<?php echo esc_url($new_info['permalink']);?>">
                        <article class="new-info-item-desc">
                            <div class="new-left-time">
                                <span class="new-left-time-d"><?php echo get_the_date('d', $new_info["ID"]); ?></span>
                                <span class="new-left-time-y-m"><?php echo get_the_date('Y-m', $new_info["ID"]); ?></span>
                            </div>
                            <div class="new-info-item-text-wrap">
                                <h3 class="new-info-item-title" data-nosnippet><?php echo esc_html($new_info['title'] ?? ""); ?></h3>
                                <p class="new-info-item-description" data-nosnippet><?php echo esc_html($new_info['info']['description'] ?? ""); ?></p>
                            </div>
                        </article>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php get_footer(); ?>
