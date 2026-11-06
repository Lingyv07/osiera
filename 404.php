<?php
global $page_info_settings, $current_lang, $current_lang_json;
// 404 时主查询没有 post，get_the_title() 会拿到上一条残留记录的标题，
// 这里显式给 <title> 一个不依赖查询的文案
$title_name = $current_lang_json['not-found']['not-found'] ?? 'Page not found';
add_action('wp_enqueue_scripts', function () {
    global $version;
    wp_enqueue_style('404-style', tpi_asset('/css/404.css'), array(), $version);
    wp_enqueue_style('products-style', tpi_asset('/css/products.css'), array(), $version);
});
get_header();
?>
    <div class="frame-wrap">
        <section class="frame-top-wrap" aria-label="News Top">
            <a href="<?php echo go_url("/news"); ?>">
                <img src="<?php echo $page_info_settings['product_top_image'] ?? '';?>"
                     alt="<?php echo esc_attr(get_image_alt($page_info_settings['product_top_image'] ?? '')); ?>">
                <h1 class="frame-top-title"><?php echo $current_lang_json["not-found"]["not-found"];?></h1>
            </a>
        </section>
        <div class="frame-box">
            <div class="frame-nav-wrap" aria-label="Breadcrumb">
                <a href="<?php echo go_url("/home"); ?>">
                    <?php echo tpi_svg_markup('Home', '8a97a3'); ?>
                    <span class="title-menu"><?php echo $current_lang_json["footer"]["home"];?></span>
                </a>
            </div>
            <div class="frame-content-box">
                <div class="frame-content-box-wrap">
                    <?php get_product_fixed(); ?>
                    <div class="frame-content">
                        <div class="new-title"><?php echo $current_lang_json["not-found"]["search-label"];?></div>
                        <div class="not-fund-search">
                            <form class="header-search" action="<?php echo '/'. ($current_lang ? $current_lang. '/' : '')?>" method="get" role="search" aria-label="Site search">
                                <label for="search-query" hidden><?php echo $current_lang_json["not-found"]["search-label"];?></label>
                                <input id="search-query" type="text" name="s" placeholder="<?php echo $current_lang_json["header"]["search-placeholder"];?>" aria-label="Search articles">
                                <button type="submit" aria-label="Submit search form">
                                    <?php echo tpi_svg_markup('Search', 'ffffff'); ?>
                                </button>
                            </form>
                        </div>
                        <div class="products-info-list-wrap">
                            <?php get_change_layout(); ?>
                            <div class="products-list-wrap">
                                <ul class="products-info-wrap-box" aria-label="Product List">
                                    <?php
                                    $product_info_page = get_product_info_page(['posts_per_page' => 12, 'paged' => 1]);
                                    $product_info_list = $product_info_page["data"];
                                    $total_num = $product_info_page['total_pages'];
                                    $pageArray = range(1, $total_num || 1) ;
                                    foreach ($product_info_list as $product_info):
                                        ?>
                                        <li>
                                            <a class="product-info-item" href="<?php echo esc_url($product_info['permalink']);?>" >
                                                <div class="product-info-item-image">
                                                    <img src="" data-lazy="<?php echo esc_url($product_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>">
                                                </div>
                                                <div class="product-info-item-desc">
                                                    <div class="product-info-item-title"><?php echo esc_html($product_info['title'] ?? ""); ?></div>
                                                    <div class="product-info-item-description"><?php echo esc_html($product_info['info']['description'] ?? ""); ?></div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php get_footer(); ?>