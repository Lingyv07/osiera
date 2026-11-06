<?php
/**
 * 各语言版本之间「切换 / 添加」的能力。
 *
 * 目标行为（对齐 Polylang 的功能形态，但只用主题自己的数据）：
 *   某语言已有版本 → 点进去就是切换到那个版本的编辑地址（URL 变化）
 *   某语言还没有版本 → 点「+」创建一个该语言的草稿副本，再进它的编辑页
 *
 * 语言归属仍然只写 tpi_lang 这个 taxonomy，版本对应关系写 post meta
 * `_tpi_translations`（lang => post_id）。
 */

if (!function_exists('tpi_default_type_title')) {
    /**
     * 某种内容在「默认语言」下的初始标题。
     *
     * 新建草稿、以及从别的语言复制出新版本时都用它，
     * 保证标题永远是默认语言那一套，而不是当前筛选/源内容的语言。
     *
     * 文案真源：languages/admin-system-config-{zh,en}.json 的
     * meta.ui.multilang.default-title.{类型} —— 跟着后台语言走，
     * 中文后台给中文标题，英文后台给英文标题。
     */
    function tpi_default_type_title(string $type): string
    {
        $title = tpi_admin_ui('multilang.default-title.' . $type);
        if ($title === '') {
            $title = tpi_admin_ui('multilang.label.untitled');
        }

        return $title;
    }
}

/* =========================================================
 * 一、编辑地址 / 新增地址
 * ========================================================= */

if (!function_exists('tpi_translation_admin_url')) {
    /**
     * 某条内容的「编辑页」地址。
     * 产品 / 应用产品 / FAQ 用的是主题自建的信息编辑页，文章和页面走原生编辑器。
     */
    function tpi_translation_admin_url(int $post_id): string
    {
        if ($post_id <= 0) {
            return '';
        }

        $type = (string) get_post_type($post_id);
        $map  = [
            'post'        => 'post-info',
            'product'     => 'product-info',
            'application' => 'application-info',
            'faq'         => 'faq-info',
        ];

        if (isset($map[$type])) {
            return add_query_arg(
                ['page' => $map[$type], 'post_id' => $post_id],
                admin_url('admin.php')
            );
        }

        return add_query_arg(
            ['post' => $post_id, 'action' => 'edit'],
            admin_url('post.php')
        );
    }
}

if (!function_exists('tpi_register_new_translation_form')) {
    /** 收集「添加语言版本」的隐藏表单，页面底部统一输出 */
    function tpi_register_new_translation_form(string $form_id, int $source_id, string $lang): void
    {
        if (!isset($GLOBALS['tpi_new_translation_forms'])) {
            $GLOBALS['tpi_new_translation_forms'] = [];
        }
        $GLOBALS['tpi_new_translation_forms'][$form_id] = [
            'from' => $source_id,
            'lang' => $lang,
        ];
    }
}

add_action('admin_footer', 'tpi_print_new_translation_forms');

if (!function_exists('tpi_print_new_translation_forms')) {
    function tpi_print_new_translation_forms(): void
    {
        $forms = (array) ($GLOBALS['tpi_new_translation_forms'] ?? []);
        if (!$forms) {
            return;
        }
        echo '<div class="tpi-ntr-forms">';
        foreach ($forms as $form_id => $data) {
            $action = 'tpi_new_translation_' . (int) $data['from'] . '_' . $data['lang'];
            echo '<form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="tpi_new_translation">'
                . '<input type="hidden" name="from" value="' . (int) $data['from'] . '">'
                . '<input type="hidden" name="lang" value="' . esc_attr($data['lang']) . '">'
                . wp_nonce_field($action, '_wpnonce', false, false)
                . '</form>';
        }
        echo '</div>';
    }
}

if (!function_exists('tpi_new_translation_form')) {
    /**
     * 输出「添加某个语言版本」的按钮。
     *
     * 用 POST 表单而不是 <a href>：这是个会写数据的操作，
     * 浏览器 / 扩展的链接预取（prefetch、instant page）会把 <a> 的地址
     * 直接 GET 一遍，结果凭空多出一条草稿 —— 用 form 提交就不会。
     *
     * 表单本身挂到页面底部（admin_footer）再用 button 的 form 属性关联：
     * 列表页的 <table> 在 <form id="posts-filter"> 里面，HTML 不允许嵌套表单，
     * 浏览器会把内层 <form> 直接丢掉 —— 按钮就变成了「提交筛选表单」，
     * 于是点「+」只会刷新列表（这就是之前跳转错误的原因）。
     *
     * @param string $inner 按钮内部 HTML
     * @param string $class 按钮 class
     * @param string $title 悬停提示
     */
    function tpi_new_translation_form(int $source_id, string $lang, string $inner = '<span class="tpi-lb-plus" aria-hidden="true">+</span>', string $class = 'tpi-lb is-off', string $title = ''): string
    {
        if ($source_id <= 0 || $lang === '') {
            return '';
        }
        $form_id = 'tpi-ntr-' . $source_id . '-' . $lang;
        tpi_register_new_translation_form($form_id, $source_id, $lang);

        return '<button type="submit" form="' . esc_attr($form_id) . '" class="' . esc_attr($class) . '" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '">' . $inner . '</button>';
    }
}

/* =========================================================
 * 二、版本对应关系的写入
 * ========================================================= */

if (!function_exists('tpi_save_post_translations')) {
    /**
     * 保存一组语言版本（lang => post_id），并让每个成员都持有一份，
     * 这样从任何一个语言版本进去都能看到完整的一组。
     */
    function tpi_save_post_translations(int $post_id, array $map): void
    {
        $slugs = tpi_language_slugs();
        $own   = tpi_get_post_language($post_id);
        if ($own !== '') {
            $map[$own] = $post_id;
        }

        $clean = [];
        foreach ($map as $lang => $id) {
            $lang = strtolower((string) $lang);
            $id   = (int) $id;
            if ($id > 0 && in_array($lang, $slugs, true)) {
                $clean[$lang] = $id;
            }
        }
        if (!$clean) {
            return;
        }

        $json = wp_json_encode($clean);
        foreach ($clean as $id) {
            update_post_meta($id, '_tpi_translations', $json);
            unset($GLOBALS['tpi_lang_cache']['translations_' . $id]);
        }
    }
}

/* =========================================================
 * 三、新建某个语言的版本
 * ========================================================= */

if (!function_exists('tpi_translate_term_for_language')) {
    /**
     * 把一个分类术语映射到目标语言的同义术语。
     * 该站非默认语言的术语 slug 基本是「默认 slug + -语言」，
     * 所以按「后缀匹配 → 同 slug」两轮找，找不到就跳过（宁缺不错）。
     */
    function tpi_translate_term_for_language(int $term_id, string $lang): int
    {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            return 0;
        }

        $taxonomy = (string) $term->taxonomy;
        $slug     = (string) $term->slug;

        $candidates = [$slug . '-' . $lang, $slug . '_' . $lang, $slug];
        if ($lang === tpi_default_language()) {
            $candidates = [$slug];
        }

        foreach ($candidates as $candidate) {
            $found = get_term_by('slug', $candidate, $taxonomy);
            if ($found && (int) $found->term_id !== $term_id && tpi_get_term_language($found) === $lang) {
                return (int) $found->term_id;
            }
        }

        return 0;
    }
}

if (!function_exists('tpi_create_translation')) {
    /**
     * 以 $source_id 为蓝本，在 $lang 下创建一条草稿副本。
     *
     * @return int|WP_Error 新（或已存在）的内容 ID
     */
    function tpi_create_translation(int $source_id, string $lang)
    {
        $lang = strtolower($lang);
        if (!in_array($lang, tpi_language_slugs(), true)) {
            return new WP_Error('tpi_bad_lang', tpi_admin_ui('multilang.error.bad-lang'));
        }

        $source = get_post($source_id);
        if (!$source || !in_array($source->post_type, tpi_lang_post_types(), true)) {
            return new WP_Error('tpi_bad_source', tpi_admin_ui('multilang.error.source-missing'));
        }
        if (!current_user_can('edit_post', $source_id)) {
            return new WP_Error('tpi_forbidden', tpi_admin_ui('multilang.error.permission'));
        }

        // 已经有该语言的版本，直接返回
        $map = tpi_get_post_translations($source_id);
        if (!empty($map[$lang])) {
            return (int) $map[$lang];
        }

        // 父级（仅页面需要）跟着换成目标语言的同组页面
        $parent = 0;
        if ($source->post_parent) {
            $parent_map = tpi_get_post_translations((int) $source->post_parent);
            $parent     = (int) ($parent_map[$lang] ?? 0);
        }

        // 新版本的标题取「默认语言」那条的标题：目标语言本身是默认语言时就用源标题；
        // 否则沿译文映射找默认语言版本，找不到默认版本则回落到默认语言的通用标题。
        $default_lang = tpi_default_language();
        $title        = $source->post_title;
        if ($lang !== $default_lang) {
            $default_id   = (int) ($map[$default_lang] ?? 0);
            $from_default = $default_id > 0 ? trim((string) get_post_field('post_title', $default_id)) : '';
            $title        = $from_default !== '' ? $from_default : tpi_default_type_title((string) $source->post_type);
        }

        $new_id = wp_insert_post([
            'post_type'      => $source->post_type,
            'post_status'    => 'draft',
            'post_title'     => $title,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_author'    => get_current_user_id(),
            'post_parent'    => $parent,
            'menu_order'     => $source->menu_order,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
            'post_name'      => $source->post_name,
        ], true);

        if (is_wp_error($new_id)) {
            return $new_id;
        }
        $new_id = (int) $new_id;

        // 语言先落库，后面才敢把 slug 改回和来源一致
        tpi_set_post_language($new_id, $lang);

        // 同 slug 跨语言允许共存：/products/x 与 /ja/products/x
        if ($source->post_name !== '') {
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_name' => $source->post_name], ['ID' => $new_id]);
            clean_post_cache($new_id);
        }

        // 分类 / 标签：换成目标语言里对应的术语
        $skip_tax = ['tpi_lang', 'post_translations', 'language', 'term_language', 'term_translations'];
        foreach (get_object_taxonomies($source->post_type) as $taxonomy) {
            if (in_array($taxonomy, $skip_tax, true)) {
                continue;
            }
            $terms = wp_get_object_terms($source_id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($terms) || !$terms) {
                continue;
            }
            $targets = [];
            foreach ($terms as $term_id) {
                $mapped = tpi_translate_term_for_language((int) $term_id, $lang);
                if ($mapped) {
                    $targets[] = $mapped;
                }
            }
            if ($targets) {
                wp_set_object_terms($new_id, $targets, $taxonomy);
            }
        }

        // 特色图片 + 其它 post meta
        $thumb = (int) get_post_thumbnail_id($source_id);
        if ($thumb) {
            set_post_thumbnail($new_id, $thumb);
        }

        $skip_meta = ['_tpi_post_lang', '_tpi_translations', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_thumbnail_id'];
        foreach ((array) get_post_meta($source_id) as $key => $values) {
            if (in_array($key, $skip_meta, true)) {
                continue;
            }
            foreach ((array) $values as $value) {
                update_post_meta($new_id, $key, maybe_unserialize($value));
            }
        }

        // 结构化字段（摘要 / 正文 / 图集 / 参数）在上面已经随文章与 meta 一起复制过了

        // 双向记录版本对应关系
        $map[$lang] = $new_id;
        tpi_save_post_translations($source_id, $map);

        return $new_id;
    }
}

add_action('admin_post_tpi_new_translation', 'tpi_handle_new_translation_request');

if (!function_exists('tpi_handle_new_translation_request')) {
    function tpi_handle_new_translation_request(): void
    {
        // 只接受 POST：避免被链接预取 / 爬虫凭空建草稿
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html(tpi_admin_ui('multilang.error.post-only')), '', ['response' => 400]);
        }

        $from = isset($_POST['from']) ? (int) $_POST['from'] : 0;
        $lang = isset($_POST['lang']) ? strtolower(sanitize_text_field(wp_unslash($_POST['lang']))) : '';

        check_admin_referer('tpi_new_translation_' . $from . '_' . $lang);

        if ($from <= 0 || $lang === '') {
            wp_die(esc_html(tpi_admin_ui('multilang.error.missing-param')));
        }

        $result = tpi_create_translation($from, $lang);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(tpi_translation_admin_url((int) $result));
        exit;
    }
}

/* =========================================================
 * 四、允许不同语言共用同一个 slug
 *
 * 核心的 wp_unique_post_slug() 会保证同一 post type 下 slug 唯一，
 * 于是日语版本会被改成 xxx-2，前台 /ja/xxx 就取不到了。
 * 这里在「冲突的是一条别的语言的内容」时原样返回 slug。
 * ========================================================= */

add_filter('pre_wp_unique_post_slug', 'tpi_allow_shared_slug_across_languages', 10, 6);

if (!function_exists('tpi_allow_shared_slug_across_languages')) {
    function tpi_allow_shared_slug_across_languages($override, $slug, $post_id, $post_status, $post_type, $post_parent)
    {
        if ($override !== null || !$post_id || !$slug) {
            return $override;
        }
        if (!in_array($post_type, tpi_lang_post_types(), true)) {
            return $override;
        }

        $lang = tpi_get_post_language((int) $post_id);
        if ($lang === '') {
            return $override;
        }

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_name = %s AND post_type = %s AND ID <> %d
               AND post_status NOT IN ('trash', 'auto-draft')
             LIMIT 5",
            $slug,
            $post_type,
            $post_id
        ));

        foreach ((array) $ids as $id) {
            $other = tpi_get_post_language((int) $id);
            if ($other !== '' && $other !== $lang) {
                return $slug;
            }
        }

        return $override;
    }
}

/* =========================================================
 * 五、同语言 slug 撞车时补 -1 / -2
 *
 * wp_unique_post_slug() 对 draft / auto-draft 直接原样返回，不查重；
 * 而「添加新产品」时标题就定了 slug，于是连着建两个 New Product，
 * 两条都会停在 /product/new-product，前台指向同一条。
 * 这里在内容落库后再查一次：同类型、同语言已有同名 → 依次加 -1、-2……
 * 跨语言共用 slug 不受影响（/product/x 与 /ja/product/x 各走各的）。
 * ========================================================= */

add_action('wp_after_insert_post', 'tpi_unique_slug_on_publish', 20, 2);

if (!function_exists('tpi_unique_slug_on_publish')) {
    function tpi_unique_slug_on_publish($post_id, $post): void
    {
        $post_id = (int) $post_id;
        if (!($post instanceof WP_Post) || $post_id <= 0) {
            return;
        }
        if ($post->post_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, tpi_lang_post_types(), true)) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $slug = (string) $post->post_name;
        if ($slug === '' || !tpi_slug_taken_in_language($slug, (string) $post->post_type, $post_id)) {
            return;
        }

        // 已经带 -3 这类尾巴的，先剥掉再往后数，免得叠成 a-3-1
        $base = preg_replace('/-\d+$/', '', $slug);
        if ($base === '') {
            $base = $slug;
        }

        $new_slug = '';
        for ($i = 1; $i <= 99; $i++) {
            $candidate = $base . '-' . $i;
            if (!tpi_slug_taken_in_language($candidate, (string) $post->post_type, $post_id)) {
                $new_slug = $candidate;
                break;
            }
        }
        if ($new_slug === '') {
            return;
        }

        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_name' => $new_slug], ['ID' => $post_id]);
        clean_post_cache($post_id);
    }
}

if (!function_exists('tpi_slug_taken_in_language')) {
    /**
     * 这个 slug 在同语言、同类型下是否已被别人占用。
     */
    function tpi_slug_taken_in_language(string $slug, string $post_type, int $post_id): bool
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_name = %s AND post_type = %s AND ID <> %d
                AND post_status NOT IN ('trash', 'auto-draft', 'draft', 'pending')
              LIMIT 10",
            $slug,
            $post_type,
            $post_id
        ));
        if (!$ids) {
            return false;
        }

        $lang = tpi_get_post_language($post_id);
        foreach ((array) $ids as $id) {
            if (tpi_get_post_language((int) $id) === $lang) {
                return true;
            }
        }

        return false;
    }
}
