<?php
global $wpdb, $page_info_settings, $current_lang_json, $current_url, $lang, $current_top_host;
$related_product_per_page = 10;
$post_id = get_the_ID();
$product = tpi_item_fields($post_id, 'product');
$main_title = ($product['main_title'] ?? '') ?: get_the_title();
$images = $product['images'] ?? [];
$summary = $product['summary'] ?? '';
$description = $product['description'] ?? '';
$warning = $product['warning'] ?? '';
$parameters = $product['parameters'] ?? [];
$content = $product['content'] ?? "";
$terms = get_the_terms(get_the_ID(), 'product_cat');
$product_type = "";
$product_slug = "";
if ($terms && !is_wp_error($terms)) {
    $first_term = reset($terms);
    $product_type = esc_html($first_term->name);
    $product_slug = spliceSlugLang(esc_html($first_term->slug));
}
$valid_slug = $product_slug !== 'uncategorized';
$description_content = $summary ?: $description;
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
        '@id' => $current_url. '#product',
    ],
    'breadcrumb' => [
        "@id" => $current_url. "#breadcrumb"
    ],
];
$product_json_ld = get_product_jsonLd_data($product, $post_id);
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
            'name' => $current_lang_json['footer']["products"] ?? '',
            'item' => get_url('products')
        ]
    ],
];
if ($valid_slug && $product_type) {
    $breadcrumb_list_json_ld['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => count($breadcrumb_list_json_ld['itemListElement']) + 1,
        'name' => $product_type ?? '',
        'item' => go_url("/products/". $product_slug)
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
    "@graph" => [$page_json_ld, $product_json_ld, $breadcrumb_list_json_ld]
];

add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('product-style', tpi_asset('/css/product.css'), array(), $version);
    wp_enqueue_style('element-style', tpi_asset('/css/element.css'), array(), $version);
    wp_enqueue_style('editor-content-style', tpi_asset('/css/editor-content.css'), array(), $version);
    wp_enqueue_script('element-js',tpi_asset('/js/element.js'), array(), $version, true);
    wp_enqueue_script('product-js',tpi_asset('/js/product.js'), array('element-js'), $version, true);
});
get_header();
?>
<article class="frame-wrap">
    <div class="frame-top-wrap" aria-label="Product Top">
        <a href="<?php echo go_url("/products");?>">
            <img src="<?php echo $page_info_settings['product_top_image'];?>"
                 alt="<?php echo esc_attr(get_image_alt($page_info_settings['product_top_image'])); ?>">
            <div class="frame-top-title">
                <?php the_title(); ?>
            </div>
        </a>
    </div>
    <div class="frame-box">
        <div class="frame-nav-wrap" aria-label="Breadcrumb">
            <a class="point-to" href="<?php echo go_url("/home"); ?>">
                <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                <span class="title-menu"><?php echo $current_lang_json["footer"]["home"] ?? "";?></span>
            </a>
            <a class="point-to" href="<?php echo go_url("/products"); ?>">
                <span class="title-menu">
                    <?php echo esc_html($current_lang_json["footer"]["products"]); ?>
                </span>
            </a>
            <?php if ($valid_slug && $product_type) { ?>
                <a class="point-to" href="<?php echo go_url("/products/". $product_slug); ?>">
                    <span class="title-menu">
                        <?php echo esc_html($product_type); ?>
                    </span>
                </a>
            <?php } ?>
            <span aria-current="page"><?php the_title(); ?></span>
        </div>
        <div class="product-info-wrap">
            <div class="product-image-wrap" aria-label="product Info">
                <div class="product-img-visible" aria-label="product Images">
                    <div class="product-img-visible-wrap">
                        <?php if(!empty($images)) foreach ($images as $index => $img):
                            // 库里存上传目录相对路径，输出前补全成绝对地址
                            $item_src = tpi_media_url((string) ($img['img_src'] ?? ''));
                            $item_src = $item_src !== '' ? $item_src : (string) ($img['img_src'] ?? '');
                            $item_video = !empty($img['video_src']) ? tpi_media_url((string) $img['video_src']) : '';
                            $item_iframe = (string) ($img['iframe_src'] ?? '');
                            $item_alt = (string) ($img['image_alt'] ?? '');
                        ?>
                            <div data-href="<?php echo esc_url($item_src); ?>"
                                 class="product-img-item<?php if ($item_iframe) echo ' iframe-item';?><?php if (!$index) echo ' product-img-item-active';?>"
                                 data-src="<?php echo esc_url(!$item_iframe ? $item_src : $item_iframe); ?>"
                                 data-type="<?php echo (!$item_iframe && $item_video === '') ? 'img' : 'video'?>"
                            >
                                <?php if ($item_video !== '') { ?>
                                    <video controls width="100%" height="100%"
                                           title="Product Video"
                                           aria-label="Product Video"
                                    >
                                        <source src="<?php echo esc_url($item_video);?>" type="video/mp4">
                                        <track kind="captions" srclang="en" label="English" src="">
                                    </video>
                                <?php } else { ?>
                                    <img src="<?php echo esc_url($item_src); ?>"
                                         class="product-image"
                                         alt="<?php echo esc_attr($item_alt); ?>">
                                <?php } ?>
                                <?php if ($item_video === '' && $item_iframe) { ?>
                                    <img class="play-video" src="<?php echo esc_url(tpi_media_url('YouTube.svg')); ?>" alt="YouTube Play Button" aria-label="Play Video Button">
                                <?php } ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mask"></div>
                </div>
                <div class="product-img-list-wrap swipe">
                    <div class="swipe-track">
                        <?php if(!empty($images)) foreach ($images as $index => $img):
                            $thumb_src = tpi_media_url((string) ($img['img_src'] ?? ''));
                            $thumb_src = $thumb_src !== '' ? $thumb_src : (string) ($img['img_src'] ?? '');
                        ?>
                            <div class="swipe-slide image-item<?php if (!$index) echo ' image-item-active';?>">
                                <img src="<?php echo esc_url($thumb_src); ?>"
                                     class="product-image-btn"
                                     alt="<?php echo esc_attr((string) ($img['image_alt'] ?? '')); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="zoom-view">
                    <?php
                    $zoom_src = isset($images[0]) ? tpi_media_url((string) ($images[0]['img_src'] ?? '')) : '';
                    $zoom_src = $zoom_src !== '' ? $zoom_src : (string) ($images[0]['img_src'] ?? '');
                    ?>
                    <img src="<?php echo esc_url($zoom_src);?>"
                         class="zoom-img"
                         alt="<?php echo isset($images[0]) ? esc_attr((string) ($images[0]['image_alt'] ?? '')) : ''; ?>">
                </div>
            </div>
            <div class="product-info-box">
                <h1 class="product-title"><?php echo $main_title; ?></h1>
                <p class="product-content-desc">
                    <?php echo esc_html($description); ?>
                </p>
                <?php if(!empty($parameters)): ?>
                    <div class="product-specs">
                        <ul class="product-warning-params" aria-label="Product Parameters">
                            <?php foreach ($parameters as $param): ?>
                                <li>
                                    <span class="product-warning-params-key"><?php echo esc_html($param["key"]); ?>: </span>
                                    <span class="product-warning-params-value"><?php echo esc_html($param["value"]); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if(!empty($warning)): ?>
                    <div class="product-warning">
                        <?php /* 图标路径来自语言包 product.warning-icon（默认模板里已配好），可按语言替换 */ ?>
                        <img class="product-warning-icon" src="<?php echo esc_url(tpi_media_url((string) ($current_lang_json['product']['warning-icon'] ?? '') ?: 'warning-icon.svg')); ?>" alt="Notice icon" aria-hidden="true">
                        <div class="product-warning-text"><?php echo esc_html($warning); ?></div>
                    </div>
                <?php endif; ?>
                <div class="back-scroll-bottom-wrap">
                    <button class="back-scroll-bottom button-flash"
                            aria-label="Request quotation for <?php echo esc_attr(get_the_title()); ?>">
                        <img src="<?php echo esc_url(tpi_asset('/svg/Message.svg')); ?>" alt="" aria-hidden="true">
                        <?php echo $current_lang_json["product"]["inquire-new"];?>
                    </button>
                </div>
            </div>
        </div>
        <div class="frame-content-box">
            <div class="frame-content-box-wrap">
                <?php get_product_fixed(); ?>
                <div class="product-content editor-content">
                    <?php echo $content; ?>
                </div>
            </div>
            <div class="related-product-content-box">
                <div class="related-product-title">
                    <?php echo $current_lang_json["product"]["related-products"];?>
                </div>
                <div class="related-product-wrap">
                    <div class="related-product-swipe swipe">
                        <div class="swipe-track" role="list">
                            <?php
                            $product_some_cat_info_list = get_product_info_page(['posts_per_page' => $related_product_per_page, 'paged' => 1], $terms ? array_map(fn($img) => $img->term_id, $terms) : [])["data"];
                            foreach ($product_some_cat_info_list as $product_info):
                            ?>
                            <div class="swipe-slide" role="listitem">
                                <a class="related-product-item" href="<?php echo esc_url($product_info['permalink']);?>">
                                    <img src="<?php echo esc_url($product_info['image']);?>" class="related-product-image" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>">
                                    <div class="related-product-item-info">
                                        <div class="related-product-item-title" data-nosnippet><?php echo esc_html($product_info['title']); ?></div>
                                        <div class="related-product-item-desc" <?php echo $product_info['info']['description'] !== $description ? 'data-nosnippet' : '' ?>><?php echo esc_html($product_info['info']['description']); ?></div>
                                        <button class="related-product-item-btn"><?php echo $current_lang_json["product"]["read-more"];?></button>
                                    </div>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="carousel-btn prev">‹</button>
                        <button class="carousel-btn next">›</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</article>
<?php
get_footer();
?>