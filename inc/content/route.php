<?php
/**
* 路由与导航判定
 *
 * 当前路由是不是某个父级 / 自身，以及把绝对地址还原成站内相对路径。
 */

/**
 * 匹配是否为当前父级下的或自身
 * @param $url
 * @return bool
 */
function is_current_route_parent($url): bool {
    global $post, $article_page_map_uri_list;
    $active_id = tpi_url_to_postid($url);
    $post_id = $post->ID;
    $parent_post_id = $post->post_parent;
    $uri = $article_page_map_uri_list[$post->post_type] ?? null;

    if (!$uri) {
        if ($post_id == $active_id || $parent_post_id == $active_id) {
            return true;
        }
    } else {
        $parent_id = tpi_url_to_postid(get_url($uri));
        if ($parent_id == $active_id) {
            return true;
        }
    }

    return false;
}

/**
 * 路由值归一：本站绝对地址 → 站内相对路径
 *
 * 设置类数据读出来时会被 tpi_media_full_deep() 统一补成绝对地址，
 * 路由字段（/products）也跟着被当成媒体路径补上了域名。
 * 这里还原成站内相对路径，交给 go_url() 拼当前语言前缀；
 * 外站链接、已是相对路径的值原样返回。
 *
 * @param mixed $route
 * @return string
 */
function tpi_route_rel($route): string {
    $route = trim((string) $route);
    if ($route === '' || !preg_match('#^(https?:)?//#i', $route)) {
        return $route;
    }
    // 外站链接不动
    if (function_exists('tpi_media_is_local_url') && !tpi_media_is_local_url($route)) {
        return $route;
    }
    $parts = wp_parse_url($route);
    $path  = (string) ($parts['path'] ?? '');
    if ($path === '') {
        return $route;
    }
    $out = $path;
    if (!empty($parts['query'])) {
        $out .= '?' . $parts['query'];
    }
    if (!empty($parts['fragment'])) {
        $out .= '#' . $parts['fragment'];
    }
    return $out;
}

/**
 * 分类在前台的地址：/{列表页}/{去掉语言后缀的分类 slug}
 *
 * 分类归档的默认地址是 /product-category/xxx、/category/xxx，跟站内
 * 约定的 /products/xxx 不一致，导航、侧栏、面包屑都用这个函数拼。
 *
 * @param WP_Term|int|string $term 分类对象 / term_id / slug
 * @param string             $base 列表页路径（products / news / applications / question）
 * @return string
 */
if (!function_exists('tpi_term_url')) {
    function tpi_term_url($term, string $base = 'products'): string
    {
        if (!$term instanceof WP_Term) {
            $term = is_numeric($term) ? get_term((int) $term) : get_term_by('slug', (string) $term, 'product_cat');
        }
        if (!($term instanceof WP_Term)) {
            return go_url('/' . trim($base, '/'));
        }
        return go_url('/' . trim($base, '/') . '/' . spliceSlugLang($term->slug));
    }
}

if (!function_exists('tpi_current_term_slug')) {
    /**
     * 当前地址里的分类 slug（没有任何分类时返回空串）
     *
     * 分类页走的是 /products/{slug} 的重写规则，is_tax() 为假，
     * 只能从 URL 上判断当前在哪个分类。
     */
    function tpi_current_term_slug(): string
    {
        $routeInfo = routeCategoryParse();
        $slug = (string) ($routeInfo['category'] ?? '');
        return $slug === '' ? '' : spliceSlugLang($slug);
    }
}

/**
 * 产品分类的地址：/products/{分类 slug}
 *
 * 主题的分类列表（侧栏、产品页下拉）都按这个地址链接，但 WordPress 只认
 * 页面层级，`/products/cnc-turning` 会当成 products 的子页面去查，直接 404。
 * 这里按**已存在的分类 slug** 逐条建规则，把请求交回 products 页面，
 * 分类 slug 仍由 routeCategoryParse() 从 URL 里解析，页面按分类过滤。
 *
 * 注意：产品详情页同样是 /products/{slug}。早先写成通配规则
 * `^products/([^/]+)` 会把详情页一起抢走（点产品进去显示的是列表页），
 * 所以这里只给分类 slug 建规则，并且同名产品存在时跳过、以产品为准。
 */
if (!function_exists('tpi_product_category_rewrite')) {
    function tpi_product_category_rewrite(): void
    {
        if (!get_page_by_path('products') || !taxonomy_exists('product_cat')) {
            return;
        }
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) {
            return;
        }
        // 多语言站带语言前缀（/en/products/xxx、/ja/products/xxx）也要能进
        $langs = array_merge([''], function_exists('tpi_language_slugs') ? tpi_language_slugs() : []);
        foreach ($terms as $term) {
            $slug = (string) $term->slug;
            if ($slug === '') {
                continue;
            }
            // 同名的产品详情优先，别把 /products/{产品 slug} 抢成列表页
            if (get_page_by_path('products/' . $slug, OBJECT, 'product')) {
                continue;
            }
            // 分类 slug 可能带语言后缀（cnc-turning-ja），URL 上用的是去掉后缀的那段
            $url_slug = function_exists('spliceSlugLang') ? spliceSlugLang($slug) : $slug;
            foreach ($langs as $lang) {
                $prefix = $lang === '' ? '' : preg_quote($lang, '/') . '/';
                add_rewrite_rule(
                    '^' . $prefix . 'products/' . preg_quote($url_slug, '/') . '/?$',
                    'index.php?pagename=products',
                    'top'
                );
            }
        }
    }
}
// 必须晚于分类注册（tables/products.php 在 init 默认优先级 10 注册 product_cat）
add_action('init', 'tpi_product_category_rewrite', 20);

if (!function_exists('tpi_product_category_rewrite_flush')) {
    /** 规则只刷一次，之后靠 option 记住（规则内容变了就把版本号 +1） */
    function tpi_product_category_rewrite_flush(): void
    {
        if ((string) get_option('tpi_product_cat_rewrite') === '3') {
            return;
        }
        flush_rewrite_rules(false);
        update_option('tpi_product_cat_rewrite', '3', false);
    }
}
add_action('init', 'tpi_product_category_rewrite_flush', 99);
