<?php
/**
 * 列表页「首图」列
 *
 * 文章 / 产品 / 应用 的后台列表里，在标题左边补一列封面缩略图，
 * 不用点进去就能认出是哪条内容。
 *
 * 取图和前台保持同一套优先级：特色图片 → 主图字段 → 产品图集第一张，
 * 所以列表里看到的就是前台列表/详情页会用到的第一张图。
 */

/* ---------- 一、加列：插在「标题」左边 ---------- */

foreach (['post', 'product', 'application'] as $tpi_thumb_type) {
    add_filter('manage_' . $tpi_thumb_type . '_posts_columns', 'tpi_add_thumb_column', 15);
}
unset($tpi_thumb_type);

if (!function_exists('tpi_add_thumb_column')) {
    function tpi_add_thumb_column(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $new['tpi_thumb'] = tpi_admin_ui('common.column.image', 'Image');
            }
            $new[$key] = $label;
        }
        if (!isset($new['tpi_thumb'])) {
            $new['tpi_thumb'] = tpi_admin_ui('common.column.image', 'Image');
        }
        return $new;
    }
}

/* ---------- 二、渲染单元格 ---------- */

foreach (['post', 'product', 'application'] as $tpi_thumb_type) {
    add_action('manage_' . $tpi_thumb_type . '_posts_custom_column', 'tpi_render_thumb_column', 10, 2);
}
unset($tpi_thumb_type);

if (!function_exists('tpi_item_thumb_url')) {
    /** 一条内容的首图地址：特色图片优先，其次主图字段 / 图集首图 */
    function tpi_item_thumb_url(int $post_id): string
    {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if ($thumb_id > 0) {
            $url = (string) wp_get_attachment_image_url($thumb_id, 'thumbnail');
            if ($url !== '') {
                return $url;
            }
        }

        $fields = tpi_item_fields($post_id);

        return (string) ($fields['image'] ?? '');
    }
}

if (!function_exists('tpi_render_thumb_column')) {
    function tpi_render_thumb_column(string $column, int $post_id): void
    {
        if ($column !== 'tpi_thumb') {
            return;
        }

        $url = tpi_item_thumb_url($post_id);

        if ($url === '') {
            // 没有首图：留一个灰底占位，行高和旁边带图的行保持一致
            echo '<span class="tpi-thumb-cell is-empty" aria-hidden="true">'
                . '<span class="dashicons dashicons-format-image"></span></span>';
            return;
        }

        printf(
            '<span class="tpi-thumb-cell"><img src="%1$s" alt="" width="60" height="60" loading="lazy" decoding="async"></span>',
            esc_url($url)
        );
    }
}
