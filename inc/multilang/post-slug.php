<?php
// 允许相同 slug（防止 -num）
add_filter('wp_unique_post_slug', function($slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug) {
    $lang = tpi_get_post_language($post_ID);
    if (!$lang) {
        return $slug;
    }

    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT p.ID
        FROM {$wpdb->posts} p
        JOIN {$wpdb->prefix}term_relationships tr ON p.ID = tr.object_id
        JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        JOIN {$wpdb->prefix}terms t ON tt.term_id = t.term_id
        WHERE p.post_name = %s
          AND p.post_type = %s
          AND p.ID != %d
          AND tt.taxonomy = 'tpi_lang'
          AND t.slug = %s
        LIMIT 1
    ", $original_slug, $post_type, $post_ID, $lang));

    if (!$exists) {
        return $original_slug; // 同语言下不存在则保留原 slug
    }

    return $slug;
}, 10, 6);

// 按当前语言匹配 slug（文章 + 页面 + 产品）
add_filter('request', function($query_vars) {
    global $wpdb, $post_page_list;

    // 带语言前缀的路径：/ja/home、/ja/product/xxx
    // 注意：重写规则里附件兜底规则会先吞掉多段路径（拿到的是 attachment 而不是 pagename），
    // 所以这里直接按 URL 路径解析，不依赖 rewrite 结果。
    $request_path = trim(tpi_request_path(), '/');
    if ($request_path !== '') {
        $path_parts = explode('/', $request_path);
        $path_lang = strtolower($path_parts[0]);
        if (in_array($path_lang, tpi_language_slugs(), true)) {
            array_shift($path_parts);

            // /ja/ ：语言首页（带搜索词时走搜索）
            if (!$path_parts) {
                if (isset($_GET['s']) && $_GET['s'] !== '') {
                    return ['s' => sanitize_text_field(wp_unslash($_GET['s']))];
                }
                // 不能把原来的 query_vars 交回去：语言代码常和媒体库里的国旗文件同名
                // （附件 slug = en / ja），会被 WordPress 当成附件跳到 xxx.svg。
                // 这里直接给 404，交给 tpi_redirect_language_root 跳到该语言首页。
                return ['error' => '404'];
            }

            // 分页：/ja/news/page/2
            $paged = 0;
            $last_index = count($path_parts) - 1;
            if ($last_index > 0 && ctype_digit((string) $path_parts[$last_index])
                && strtolower((string) $path_parts[$last_index - 1]) === 'page') {
                $paged = (int) $path_parts[$last_index];
                array_splice($path_parts, $last_index - 1, 2);
            }

            $path_slug = strtolower((string) end($path_parts));

            // 订阅：/ja/feed、/ja/comments/feed
            if ($path_slug === 'feed') {
                return ['feed' => 'feed'];
            }

            if ($path_slug !== '') {
                $try_types = array_merge(['page', 'post'], (array) $post_page_list);
                foreach ($try_types as $one_type) {
                    $found_id = tpi_get_post_id_by_slug($path_slug, $path_lang, $one_type);
                    if ($found_id > 0) {
                        $query_vars = [];
                        if ($one_type === 'page') {
                            $query_vars['page_id'] = $found_id;
                        } else {
                            $query_vars['p'] = $found_id;
                            $query_vars['post_type'] = $one_type;
                        }
                        if ($paged > 0) {
                            $query_vars['paged'] = $paged;
                        }
                        return $query_vars;
                    }
                }
            }

            // /ja/products/{分类}：产品列表页 + 分类。
            // 分类 slug 由 routeCategoryParse() 从 URL 解析，这里只要把请求交回该语言的
            // products 页面；不拦这一步，下面「按 slug 找页面/文章/产品」会把分类名当成
            // 文章 slug 去查，查不到就直接 404（英文走主题的重写规则，不受影响）。
            if (count($path_parts) >= 2 && strtolower((string) $path_parts[0]) === 'products') {
                $term = get_term_by('slug', $path_slug . '-' . $path_lang, 'product_cat');
                if (!($term instanceof WP_Term)) {
                    // 该语言没有单独建分类时按原 slug 再找一次（与 products 模板同一套回落）
                    $term = get_term_by('slug', $path_slug, 'product_cat');
                }
                $products_page_id = tpi_get_post_id_by_slug('products', $path_lang, 'page');
                if ($term instanceof WP_Term && $products_page_id > 0) {
                    $query_vars = ['page_id' => (int) $products_page_id];
                    if ($paged > 0) {
                        $query_vars['paged'] = $paged;
                    }
                    return $query_vars;
                }
            }

            return ['error' => '404'];
        }
    }

    $lang = tpi_current_language();
    $slug = '';
    $post_type = '';
    $post_id_key = '';
    $unset_query_vars_key = '';

    // 页面 slug（支持父/子结构）
    if (!empty($query_vars['pagename'])) {
        $slug_parts = explode('/', $query_vars['pagename']);
        $slug = end($slug_parts);
        $post_type = 'page';
        $post_id_key = 'page_id';
        $unset_query_vars_key = 'pagename';
    }

    // 文章 slug
    if (!empty($query_vars['name'])) {
        $slug = $query_vars['name'];
        $post_id_key = 'p';
        $unset_query_vars_key = 'name';

        if (empty($query_vars['pagename']) && empty($query_vars['post_type'])) {
            // 普通文章
            $post_type = 'post';
        } else if (isset($query_vars['post_type']) && in_array($query_vars['post_type'], $post_page_list, true)) {
            // 产品
            $post_type = $query_vars['post_type'];
        }
    }

    if ($slug && $post_type && $post_id_key && $unset_query_vars_key) {
        $post_id = $wpdb->get_var($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            JOIN {$wpdb->prefix}term_relationships tr ON p.ID = tr.object_id
            JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN {$wpdb->prefix}terms t ON tt.term_id = t.term_id
            WHERE p.post_name = %s
              AND p.post_status = 'publish'
              AND p.post_type = %s
              AND tt.taxonomy = 'tpi_lang'
              AND t.slug = %s
            LIMIT 1
        ", $slug, $post_type, $lang));

        if ($post_id) {
            $query_vars[$post_id_key] = $post_id;
            unset($query_vars[$unset_query_vars_key]);
        } else {
            $query_vars = ['error' => '404'];
        }
    }

    return $query_vars;
});

// 删除产品时，同时删除表数据和语言归属
function delete_completely($post_id): void {
    global $wpdb, $post_page_list;

    $post_type = get_post_type($post_id);

    // 只处理 product 类型
    if (in_array($post_type, $post_page_list, true)) {
        // 删除自定义表数据
        $wpdb->delete($wpdb->prefix . $post_type. '_info', ['page_id' => $post_id], ['%d']);

        // 语言归属：term 关系由 wp_delete_post 统一清理，这里只兜底删一次
        wp_delete_object_term_relationships($post_id, 'tpi_lang');
    }

}
add_action('before_delete_post', 'delete_completely');