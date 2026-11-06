<?php
/**
 * 评论相关的后台清理。
 *
 * 这个站不使用评论（数据库里没有任何评论数据），
 * 所以列表页不再显示「评论」列，编辑页也不再显示「讨论」面板，
 * 免得占位置。以后真的开始用评论（有已批准/待审评论）时会自动恢复。
 */

if (!function_exists('tpi_comments_in_use')) {
    /** 站点是否真的在用评论：有已批准或待审的评论才算 */
    function tpi_comments_in_use(): bool
    {
        static $in_use = null;
        if ($in_use !== null) {
            return $in_use;
        }
        $counts = wp_count_comments();
        $in_use = ((int) $counts->approved + (int) $counts->moderated) > 0;
        return $in_use;
    }
}

/* ---------- 一、列表页去掉评论列 ---------- */

add_filter('manage_posts_columns', 'tpi_maybe_remove_comments_column', 30);
add_filter('manage_pages_columns', 'tpi_maybe_remove_comments_column', 30);

if (!function_exists('tpi_maybe_remove_comments_column')) {
    function tpi_maybe_remove_comments_column(array $columns): array
    {
        if (!tpi_comments_in_use()) {
            unset($columns['comments']);
        }
        return $columns;
    }
}

/* ---------- 二、经典编辑器去掉「讨论」框 ---------- */

add_action('add_meta_boxes', 'tpi_maybe_remove_comment_meta_boxes', 20);

if (!function_exists('tpi_maybe_remove_comment_meta_boxes')) {
    function tpi_maybe_remove_comment_meta_boxes(): void
    {
        if (tpi_comments_in_use()) {
            return;
        }
        $types = array_merge(['post', 'page'], array_values(get_post_types(['public' => true])));
        foreach (array_unique($types) as $type) {
            remove_meta_box('commentstatusdiv', $type, 'normal');
            remove_meta_box('commentsdiv', $type, 'normal');
        }
    }
}

/* ---------- 三、区块编辑器去掉「讨论」面板 ---------- */

add_action('enqueue_block_editor_assets', 'tpi_block_editor_hide_discussion');

if (!function_exists('tpi_block_editor_hide_discussion')) {
    function tpi_block_editor_hide_discussion(): void
    {
        if (tpi_comments_in_use()) {
            return;
        }

        $js = <<<'JS'
(function () {
    if (!window.wp || !wp.data || !wp.domReady) {
        var n = 0, t = setInterval(function () {
            n++;
            if (window.wp && wp.data && wp.domReady) { clearInterval(t); start(); }
            else if (n > 40) { clearInterval(t); }
        }, 100);
        return;
    }
    start();

    function start() {
        wp.domReady(function () {
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                var done = false;
                var stores = ['core/edit-post', 'core/editor'];
                for (var i = 0; i < stores.length; i++) {
                    var store = wp.data.dispatch(stores[i]);
                    if (store && typeof store.removeEditorPanel === 'function') {
                        // 面板真实名字是 discussion-panel，两个都试一遍
                        store.removeEditorPanel('discussion-panel');
                        store.removeEditorPanel('discussion');
                        done = true;
                    }
                }
                if (done || tries > 30) {
                    clearInterval(timer);
                }
            }, 100);
        });
    }
})();
JS;

        wp_add_inline_script('wp-edit-post', $js);
    }
}
