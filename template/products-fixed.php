<?php
global $current_lang_json;
$new_product_per_page = 6;
?>
<aside class="product-more-box">
    <div class="product-more-all-wrap" align="Other Product">
        <div class="product-cat-box">
            <div class="product-cat-title">
                <span><?php echo $current_lang_json["product"]["product-categories"];?></span>
                <img src="<?php echo esc_url(tpi_asset('/svg/List.svg')); ?>" alt="" aria-hidden="true">
            </div>
            <ul class="product-categories" aria-label="Product Categories">
                <?php
                // 只列当前语言的分类（8 种语言的分类混在一起是原来最大的问题）
                $product_cats = tpi_get_terms_by_language('product_cat', array(
                    'hide_empty' => false, // 如果设置为 true，只显示有产品的分类
                    'orderby'    => 'slug', // 'name', 'term_id', 'slug', 'count', 'term_order'
                    'order'      => 'ASC',  // 'ASC' 或 'DESC'
                ), tpi_current_language());
                $current_cat_slug = function_exists('tpi_current_term_slug') ? tpi_current_term_slug() : '';
                foreach ($product_cats as $term):
                    $cat_active = ($current_cat_slug !== '' && $current_cat_slug === spliceSlugLang($term->slug)) ? ' class="is-current"' : '';
                    ?>
                    <li<?php echo $cat_active; ?>>
                        <a href="<?php echo esc_url(tpi_term_url($term, 'products')); ?>"><?php echo esc_html($term->name); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="new-product-box">
            <div class="new-product-cat-title"><?php echo $current_lang_json["product"]["new-products"] ?? '';?></div>
            <ul class="new-product-list" aria-label="New Product List">
                <?php
                $product_info_list = get_product_info_page(['posts_per_page' => $new_product_per_page, 'paged' => 1])["data"];
                ?>
                <?php
                foreach ($product_info_list as $product_info):
                    ?>
                    <li>
                        <a href="<?php echo esc_url($product_info['permalink'] ?? '');?>">
                            <div class="new-product-image">
                                <img src="" data-lazy="<?php echo esc_url($product_info['image']);?>" alt="<?php echo esc_attr(get_image_alt($product_info['image'], $product_info['title'] ?? 'Product')); ?>">
                            </div>
                            <div class="new-product-desc">
                                <div class="new-product-title" data-nosnippet><?php echo esc_html($product_info['title'] ?? ''); ?></div>
                                <div class="new-product-read-more"><?php echo $current_lang_json["product"]["read-more"] ?? '';?></div>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</aside>