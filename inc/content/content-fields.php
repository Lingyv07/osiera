<?php
/**
 * 内容字段层（Osiera）
 *
 * 产品 / 应用 / FAQ / 文章四类内容，历史上把摘要、主图、正文存放在各自的
 * {prefix}_*_info 表里，跟 WordPress 自带的 post_excerpt / 特色图片 / post_content
 * 重复，属于历史包袱。这里统一收口：
 *
 *   post_title                标题
 *   post_content              正文（纯正文，不再拼 H1 / 摘要）
 *   post_excerpt              摘要（列表与搜索结果用）
 *   _thumbnail_id             主图（特色图片）
 *   post_meta  _osiera_*      其余结构化字段（H1、图集、参数、提示…）
 *
 * 全站的读写都必须经过本文件的函数：
 *
 *   tpi_item_fields($post_id, $type)      读单条（返回的数组保留历史键名，模板无需改动）
 *   tpi_item_fields_map($post_ids, $type) 批量读
 *   tpi_save_item_fields($post_id, $type, $data) 写
 *   tpi_item_migrate_legacy()             把旧 _info 表搬进原生结构（只跑一次）
 *
 * @package Osiera
 */

if (!defined('TPI_META_PREFIX')) {
    define('TPI_META_PREFIX', '_osiera_');
}

if (!function_exists('tpi_meta_key')) {
    /** 结构化字段的 meta key */
    function tpi_meta_key(string $name): string
    {
        return TPI_META_PREFIX . $name;
    }
}

if (!function_exists('tpi_clean_editor_html')) {
    /**
     * 保存富文本前的清洗
     *
     * 只对没有 unfiltered_html 权限的角色跑 wp_kses_post（与核心
     * wp_filter_post_kses() 的判定一致）。管理员/编辑的正文必须原样保留，
     * 否则 kses 会把 display / overflow-x / rgba() 这类声明整条删掉，
     * 编辑器里排好的 flex / grid / 阴影一保存就塌掉。
     *
     * @param string $html
     * @return string
     */
    function tpi_clean_editor_html(string $html): string
    {
        if (current_user_can('unfiltered_html')) {
            return $html;
        }

        return wp_kses_post($html);
    }
}

if (!function_exists('tpi_item_types')) {
    /**
     * 支持结构化字段的内容类型
     *
     * legacy 是旧表的表名后缀与主键列名，仅在迁移时用得到。
     */
    function tpi_item_types(): array
    {
        return [
            'post'        => ['legacy' => 'post_info', 'column' => 'post_id'],
            'product'     => ['legacy' => 'product_info', 'column' => 'page_id'],
            'application' => ['legacy' => 'application_info', 'column' => 'post_id'],
            'faq'         => ['legacy' => 'faq_info', 'column' => 'post_id'],
        ];
    }
}

/* =========================================================
 * 一、读
 * ========================================================= */

if (!function_exists('tpi_attachment_id_from_url')) {
    /**
     * 图片地址 → 附件 ID
     *
     * attachment_url_to_postid() 只认「原图地址」，带尺寸后缀（-300x300）会失配，
     * 这里去掉尺寸后缀再试一次，最后按文件名兜底。
     */
    function tpi_attachment_id_from_url(string $url): int
    {
        $url = trim($url);
        if ($url === '') {
            return 0;
        }
        // 库里存的是根相对路径，核心函数只认绝对地址，先补全
        $url = tpi_media_full($url);
        if ($url === '') {
            return 0;
        }
        $id = (int) attachment_url_to_postid($url);
        if ($id > 0) {
            return $id;
        }
        $base = preg_replace('/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $url);
        if ($base !== $url) {
            $id = (int) attachment_url_to_postid($base);
            if ($id > 0) {
                return $id;
            }
        }
        $name = basename((string) parse_url($base, PHP_URL_PATH));
        if ($name === '') {
            return 0;
        }
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($name)
        ));
        return (int) $found;
    }
}

if (!function_exists('tpi_decode_json_field')) {
    /** 安全解析 JSON 字段，失败给空数组 */
    function tpi_decode_json_field($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = (string) $raw;
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('tpi_item_fields')) {
    /**
     * 读取一条内容的完整字段
     *
     * 键名保持历史命名（main_title / image / images / summary / description /
     * warning / parameters / content / article_type / no_description_push / answer），
     * 这样模板里的 $info['xxx'] 不需要改动。
     *
     * @param int    $post_id
     * @param string $type 空则按文章类型自动判断
     * @return array
     */
    function tpi_item_fields(int $post_id, string $type = ''): array
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        if ($type === '') {
            $type = (string) $post->post_type;
        }

        $thumb_id  = (int) get_post_thumbnail_id($post_id);
        $thumb_url = $thumb_id > 0 ? (string) wp_get_attachment_image_url($thumb_id, 'full') : '';
        // 兜底主图：库里存的是根相对路径，读出来补成当前域名的绝对地址
        $legacy_url = tpi_media_full((string) get_post_meta($post_id, tpi_meta_key('image'), true));

        $gallery = [];
        if ($type === 'product') {
            // 图集 / 视频封面同样补全：模板、JSON-LD、getimagesize 都要绝对地址
            $gallery = tpi_media_full_deep(
                tpi_decode_json_field(get_post_meta($post_id, tpi_meta_key('gallery'), true))
            );
        }

        $image = $thumb_url !== '' ? $thumb_url : $legacy_url;
        if ($image === '') {
            foreach ($gallery as $item) {
                if (!is_array($item) || !empty($item['iframe_src']) || !empty($item['video_src'])) {
                    continue;
                }
                if (!empty($item['img_src'])) {
                    $image = (string) $item['img_src'];
                    break;
                }
            }
        }
        // 库里存的是上传目录相对路径，对外统一给绝对地址（模板都用 esc_url 直接输出）
        if ($image !== '') {
            $image_full = tpi_media_url($image);
            if ($image_full !== '') {
                $image = $image_full;
            }
        }

        $description = (string) $post->post_excerpt;
        $summary = (string) get_post_meta($post_id, tpi_meta_key('summary'), true);
        if ($summary === '') {
            $summary = $description;
        }

        $fields = [
            'ID'                  => $post_id,
            'post_id'             => $post_id, // 历史键名
            'page_id'             => $post_id, // 历史键名（产品）
            'post_type'           => $type,
            'title'               => get_the_title($post_id),
            'main_title'          => (string) get_post_meta($post_id, tpi_meta_key('main_title'), true),
            'image'               => $image,
            'image_id'            => $thumb_id,
            'images'              => $gallery,
            'summary'             => (string) tpi_media_full_deep($summary),
            'description'         => (string) tpi_media_full_deep($description),
            // 正文里的图片同样补绝对：模板有直接 echo 的，不一定走 the_content
            'content'             => (string) tpi_media_html_full((string) $post->post_content),
            'warning'             => (string) tpi_media_full_deep((string) get_post_meta($post_id, tpi_meta_key('warning'), true)),
            'parameters'          => tpi_media_full_deep(tpi_decode_json_field(get_post_meta($post_id, tpi_meta_key('parameters'), true))),
            'article_type'        => (string) get_post_meta($post_id, tpi_meta_key('article_type'), true),
            'no_description_push' => (string) get_post_meta($post_id, tpi_meta_key('no_description_push'), true),
            'answer'              => (string) tpi_media_full_deep((string) get_post_meta($post_id, tpi_meta_key('answer'), true)),
        ];

        if ($fields['article_type'] === '') {
            $fields['article_type'] = 'Article';
        }
        // 摘要不参入内容：默认开（历史空值也算开），只有显式 '0' 才算关
        if ($fields['no_description_push'] === '') {
            $fields['no_description_push'] = '1';
        }
        if ($type === 'faq' && $fields['answer'] === '') {
            $fields['answer'] = $fields['content'];
        }

        /**
         * 允许外部按类型微调字段
         * @param array  $fields
         * @param int    $post_id
         * @param string $type
         */
        return (array) apply_filters('osiera_item_fields', $fields, $post_id, $type);
    }
}

if (!function_exists('tpi_item_fields_map')) {
    /**
     * 批量读取（先把 meta 缓存预热，避免每条一次查询）
     *
     * @param int[]  $post_ids
     * @param string $type
     * @return array post_id => fields
     */
    function tpi_item_fields_map(array $post_ids, string $type = ''): array
    {
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if (!$post_ids) {
            return [];
        }
        update_meta_cache('post', $post_ids);

        $out = [];
        foreach ($post_ids as $id) {
            $out[$id] = tpi_item_fields($id, $type);
        }
        return $out;
    }
}

/* =========================================================
 * 二、写
 * ========================================================= */

if (!function_exists('tpi_set_item_thumbnail')) {
    /**
     * 设置主图：接受附件 ID，也接受图片地址
     *
     * 地址解析不到附件时，把地址存进 meta 兜底（前台仍然能显示），
     * 避免「填了图却因为没进媒体库而丢掉」。
     *
     * @param int        $post_id
     * @param int|string $image
     */
    function tpi_set_item_thumbnail(int $post_id, $image): void
    {
        $attachment_id = 0;
        $url = '';

        if (is_numeric($image) && (int) $image > 0) {
            $attachment_id = (int) $image;
        } elseif (is_string($image) && trim($image) !== '') {
            $url = trim($image);
            // 传进来的可能是绝对地址：解析附件用完整地址，落库用根相对
            $attachment_id = tpi_attachment_id_from_url($url);
            $url           = tpi_media_store($url);
        }

        if ($attachment_id > 0) {
            set_post_thumbnail($post_id, $attachment_id);
            delete_post_meta($post_id, tpi_meta_key('image'));
            return;
        }

        delete_post_thumbnail($post_id);
        if ($url !== '') {
            // 存上传目录相对路径，不把域名写进数据库
            update_post_meta($post_id, tpi_meta_key('image'), tpi_media_store($url));
        } else {
            delete_post_meta($post_id, tpi_meta_key('image'));
        }
    }
}

if (!function_exists('tpi_save_item_fields')) {
    /**
     * 保存一条内容的字段
     *
     * 只处理 $data 里出现的键，没出现的保持原样。
     *
     * 支持的键：
     *   post_title / content / description(摘要) / sort / image(主图)
     *   main_title / summary / warning / parameters(array) / images(array)
     *   article_type / no_description_push / answer
     *
     * @param int    $post_id
     * @param string $type
     * @param array  $data
     * @return int 受影响的文章 ID（失败为 0）
     */
    function tpi_save_item_fields(int $post_id, string $type, array $data): int
    {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $postarr = ['ID' => $post_id];
        if (array_key_exists('post_title', $data)) {
            $title = trim((string) $data['post_title']);
            $postarr['post_title'] = $title !== '' ? $title : $post->post_title;
        }
        if (array_key_exists('content', $data)) {
            // 正文入库只留相对路径（the_content / tpi_item_fields 输出时补全）
            $postarr['post_content'] = tpi_clean_editor_html(tpi_media_html_store((string) $data['content']));
        }
        if (array_key_exists('description', $data)) {
            $postarr['post_excerpt'] = tpi_clean_editor_html(tpi_media_html_store((string) $data['description']));
        }
        if (array_key_exists('sort', $data)) {
            $postarr['menu_order'] = (int) $data['sort'];
        }

        if (count($postarr) > 1) {
            $result = wp_update_post($postarr, true);
            if (is_wp_error($result)) {
                return 0;
            }
        }

        if (array_key_exists('image', $data)) {
            tpi_set_item_thumbnail($post_id, $data['image']);
        }

        $meta_map = [
            'main_title'          => 'main_title',
            'summary'             => 'summary',
            'warning'             => 'warning',
            'article_type'        => 'article_type',
            'no_description_push' => 'no_description_push',
            'answer'              => 'answer',
        ];
        foreach ($meta_map as $field => $meta) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = is_string($data[$field]) ? trim($data[$field]) : (string) $data[$field];
            // 摘要 / 提示 / 答案里也可能有图片：入库统一去掉域名
            $value = (string) tpi_media_store_deep($value);
            if ($value === '') {
                delete_post_meta($post_id, tpi_meta_key($meta));
            } else {
                update_post_meta($post_id, tpi_meta_key($meta), $value);
            }
        }

        foreach (['parameters' => 'parameters', 'images' => 'gallery'] as $field => $meta) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $list = is_array($data[$field]) ? array_values($data[$field]) : [];
            // 入库前统一去掉域名和上传目录：图集存 2026/10/a.jpg，换域名不用改数据
            $list = $list ? tpi_media_store_deep($list) : [];
            if ($field === 'images') {
                // type 曾被 esc_url() 补成 http://image / http://video，这里还原成 image / video
                foreach ($list as $i => $img) {
                    if (!is_array($img) || !array_key_exists('type', $img)) {
                        continue;
                    }
                    $type = preg_replace('#^https?://#i', '', (string) $img['type']);
                    $list[$i]['type'] = $type === 'video' ? 'video' : 'image';
                }
            }
            if (!$list) {
                delete_post_meta($post_id, tpi_meta_key($meta));
            } else {
                update_post_meta($post_id, tpi_meta_key($meta), wp_json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return $post_id;
    }
}

if (!function_exists('tpi_duplicate_item_fields')) {
    /**
     * 复制内容字段（新建译文时用）
     *
     * @param int    $from_id 源文章
     * @param int    $to_id   目标文章
     * @param string $type
     */
    function tpi_duplicate_item_fields(int $from_id, int $to_id, string $type = ''): void
    {
        $fields = tpi_item_fields($from_id, $type);
        if (!$fields) {
            return;
        }
        tpi_save_item_fields($to_id, $type, [
            'description'         => $fields['description'],
            'content'             => $fields['content'],
            'image'               => (int) $fields['image_id'] > 0 ? (int) $fields['image_id'] : $fields['image'],
            'main_title'          => $fields['main_title'],
            'summary'             => $fields['summary'],
            'warning'             => $fields['warning'],
            'parameters'          => $fields['parameters'],
            'images'              => $fields['images'],
            'article_type'        => $fields['article_type'],
            'no_description_push' => $fields['no_description_push'],
            'answer'              => $fields['answer'],
        ]);
    }
}

/* =========================================================
 * 三、旧表迁移
 * ========================================================= */

if (!function_exists('tpi_strip_content_prefix')) {
    /**
     * 摘掉历史版本拼接进正文的「H1 主题 + 摘要段」
     *
     * 老逻辑每次保存都往 post_content 前面拼 <h1>主题</h1><p>摘要</p>，
     * 而模板本身又会输出一次 H1，页面上等于重复了一遍。迁移时统一剥掉。
     */
    function tpi_strip_content_prefix(string $content, string $main_title, string $description): string
    {
        $strippers = [];
        if ($main_title !== '') {
            $strippers[] = '<h1>' . $main_title . '</h1><p>' . $description . '</p>';
            $strippers[] = '<h1>' . $main_title . '</h1>';
        }
        $strippers = array_values(array_filter($strippers));
        usort($strippers, static fn($a, $b) => strlen($b) <=> strlen($a));

        $trimmed = ltrim($content);
        foreach ($strippers as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return ltrim(substr($trimmed, strlen($prefix)));
            }
        }
        return $content;
    }
}

if (!function_exists('tpi_item_migrate_legacy')) {
    /**
     * 把四张旧 _info 表的数据搬进 WordPress 原生结构，搬完删表
     *
     * 幂等：迁移完成后写 option osiera_content_migrated，之后不再执行。
     *
     * @param bool $dry_run 只统计不写库
     * @return array{ok:bool,migrated:array,dropped:array,skipped:string}
     */
    function tpi_item_migrate_legacy(bool $dry_run = false): array
    {
        global $wpdb;
        $report = ['ok' => true, 'migrated' => [], 'dropped' => [], 'skipped' => ''];

        if (get_option('osiera_content_migrated') === '1') {
            return ['ok' => true, 'migrated' => [], 'dropped' => [], 'skipped' => tpi_admin_ui('common.migrate.done')];
        }

        foreach (tpi_item_types() as $type => $conf) {
            $table = $wpdb->prefix . $conf['legacy'];
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                continue;
            }
            $column = $conf['column'];
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
            $count = 0;

            foreach ((array) $rows as $row) {
                $post_id = (int) ($row[$column] ?? 0);
                if ($post_id <= 0 || !get_post($post_id)) {
                    continue;
                }

                $main_title  = (string) ($row['main_title'] ?? '');
                $description = (string) ($row['description'] ?? '');
                $content     = (string) ($row['content'] ?? '');
                if ($content !== '') {
                    $content = tpi_strip_content_prefix($content, $main_title, $description);
                }

                $data = [
                    'description' => $description,
                    'content'     => $content,
                    'main_title'  => $main_title,
                ];

                if (array_key_exists('image', $row) && (string) $row['image'] !== '') {
                    $data['image'] = (string) $row['image'];
                }
                if (array_key_exists('summary', $row)) {
                    $data['summary'] = (string) $row['summary'];
                }
                if (array_key_exists('warning', $row)) {
                    $data['warning'] = (string) $row['warning'];
                }
                if (array_key_exists('parameters', $row)) {
                    $data['parameters'] = tpi_decode_json_field($row['parameters']);
                }
                if (array_key_exists('images', $row)) {
                    $data['images'] = tpi_decode_json_field($row['images']);
                }
                if (array_key_exists('article_type', $row) && (string) $row['article_type'] !== '') {
                    $data['article_type'] = (string) $row['article_type'];
                }
                if (array_key_exists('no_description_push', $row) && (string) $row['no_description_push'] !== '') {
                    $data['no_description_push'] = (string) $row['no_description_push'];
                }
                if (array_key_exists('answer', $row) && (string) $row['answer'] !== '') {
                    $data['answer'] = (string) $row['answer'];
                }

                if ($dry_run) {
                    $count++;
                    continue;
                }

                // 正文为空时不要把已存在的正文覆盖掉
                if ($data['content'] === '') {
                    unset($data['content']);
                }
                tpi_save_item_fields($post_id, $type, $data);

                // 产品主图：旧表没有 image 列，从图集第一张图补
                if ($type === 'product' && (int) get_post_thumbnail_id($post_id) === 0) {
                    foreach ($data['images'] ?? [] as $img) {
                        if (!is_array($img) || !empty($img['iframe_src']) || empty($img['img_src'])) {
                            continue;
                        }
                        tpi_set_item_thumbnail($post_id, (string) $img['img_src']);
                        break;
                    }
                }
                $count++;
            }

            $report['migrated'][$type] = $count;

            if (!$dry_run) {
                $wpdb->query("DROP TABLE IF EXISTS `$table`");
                $report['dropped'][] = $table;
            }
        }

        if (!$dry_run) {
            update_option('osiera_content_migrated', '1', false);
        }
        return $report;
    }
}

/**
 * 后台加载时顺便补一次迁移（老站从旧版本升级上来时自动完成）
 */
add_action('admin_init', function () {
    if (get_option('osiera_content_migrated') === '1') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    tpi_item_migrate_legacy();
}, 4);

if (!function_exists('tpi_gallery_fix_type')) {
    /**
     * 修复图集里被 esc_url() 污染的字段（一次性，幂等）
     *
     * 老版本后台把 type 和地址一起过了 esc_url()：
     *   type = image → http://image
     *   img_src = 2025/10/a.jpg → http://2025/10/a.jpg
     * 会导致后台判断不出视频、前台取不到图。这里统一还原成
     * type = image|video、地址 = 上传目录相对路径。
     *
     * @param bool $force 忽略已修复标记
     * @return int 改动条数
     */
    function tpi_gallery_fix_type(bool $force = false): int
    {
        if (!$force && get_option('osiera_gallery_type_fixed') === '1') {
            return 0;
        }
        global $wpdb;
        $key  = function_exists('tpi_meta_key') ? tpi_meta_key('gallery') : '_osiera_gallery';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            $key,
            '%http%'
        ));
        $changed = 0;
        foreach ((array) $rows as $row) {
            $list = tpi_decode_json_field((string) $row->meta_value);
            if (!$list) {
                continue;
            }
            $dirty = false;
            foreach ($list as $i => $img) {
                if (!is_array($img)) {
                    continue;
                }
                if (array_key_exists('type', $img)) {
                    $type = preg_replace('#^https?://#i', '', (string) $img['type']);
                    $type = $type === 'video' ? 'video' : 'image';
                    if ($type !== (string) $img['type']) {
                        $list[$i]['type'] = $type;
                        $dirty = true;
                    }
                }
                foreach (['img_src', 'video_src'] as $field) {
                    if (empty($img[$field]) || !is_string($img[$field])) {
                        continue;
                    }
                    $rel = tpi_media_store($img[$field]);
                    if ($rel !== $img[$field]) {
                        $list[$i][$field] = $rel;
                        $dirty = true;
                    }
                }
            }
            if (!$dirty) {
                continue;
            }
            $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => wp_json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ['meta_id' => (int) $row->meta_id]
            );
            $changed++;
        }
        update_option('osiera_gallery_type_fixed', '1', false);
        return $changed;
    }
}

/**
 * 后台加载时补一次图集字段修复
 */
add_action('admin_init', function () {
    if (get_option('osiera_gallery_type_fixed') === '1') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }
    tpi_gallery_fix_type();
}, 5);
