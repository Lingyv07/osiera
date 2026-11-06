<?php
/**
 * 后台「信息编辑」页共用部件。
 * 文章 / 产品 / 应用产品 / FAQ 四个自建编辑页共用同一套页头与语言版本条，
 * 保证新增内容类型的编辑页也能保持一致的样式与语言切换行为。
 */

if (!function_exists('tpi_info_context')) {
    /**
     * 收集编辑页需要的通用上下文：语言、译文列表、状态、预览与返回链接。
     *
     * @param int    $post_id   当前编辑的内容 ID
     * @param string $page_slug 当前页面的 admin 页面 slug（post-info / product-info / ...）
     * @param string $list_url  该内容类型的列表页地址
     */
    function tpi_info_context(int $post_id, string $page_slug, string $list_url): array
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $lang = tpi_get_post_language($post_id);

        $status_obj   = get_post_status_object($post->post_status);
        $status_label = $status_obj ? $status_obj->label : $post->post_status;

        // 语言版本导航：先按各语言版本对应关系找（迁移自 Polylang 的分组 +
        // 同 slug 同类型兜底），找到的直接可点，找不到的显示为半透明
        $translations = tpi_get_post_translations($post_id);

        $lang_items = [];
        foreach (tpi_languages() as $language) {
            $slug      = (string) $language['slug'];
            $target_id = (int) ($translations[$slug] ?? 0);
            if ($slug === $lang) {
                $target_id = (int) $post_id;
            }

            $flag_url = (string) $language['flag_url'];

            $lang_items[] = [
                'slug' => $slug,
                'name' => (string) $language['name'],
                // 直接输出 img（flag_url 是地址，不是内联 svg）
                'flag' => $flag_url !== '' ? '<img src="' . esc_url($flag_url) . '" alt="">' : '',
                'id'   => $target_id,
                'href' => $target_id ? add_query_arg(
                    ['page' => $page_slug, 'post_id' => $target_id, 'lang' => $slug],
                    admin_url('admin.php')
                ) : '',
                // 没有该语言版本时可以「添加」：以当前内容为蓝本建草稿
                'can_add' => ($target_id === 0),
            ];
        }

        return [
            'post'         => $post,
            'lang'         => $lang,
            'lang_items'   => $lang_items,
            'status_label' => $status_label,
            'is_publish'   => ($post->post_status === 'publish'),
            'view_link'    => get_permalink($post_id),
            'back_url'     => $lang ? add_query_arg('lang', $lang, $list_url) : $list_url,
        ];
    }
}

if (!function_exists('tpi_info_head')) {
    /**
     * 输出统一页头（标题 + 胶囊 + 副标题 + 操作链接）与语言版本条。
     */
    function tpi_info_head(string $title, array $ctx, string $view_label = '', string $back_label = ''): void
    {
        if (empty($ctx['post'])) {
            return;
        }
        if ($view_label === '') { $view_label = tpi_admin_ui('common.page.view'); }
        if ($back_label === '') { $back_label = tpi_admin_ui('common.page.back'); }
        $post = $ctx['post'];
        ?>
        <div class="pi-head">
            <div class="pi-head-main">
                <h1><?php echo esc_html($title); ?></h1>
                <span class="pi-chip">ID <?php echo (int) $post->ID; ?></span>
                <?php if (!empty($ctx['lang'])) : ?>
                    <span class="pi-chip pi-chip-lang"><?php echo esc_html(strtoupper($ctx['lang'])); ?></span>
                <?php endif; ?>
                <span class="pi-chip <?php echo $ctx['is_publish'] ? 'pi-chip-ok' : 'pi-chip-muted'; ?>"><?php echo esc_html($ctx['status_label']); ?></span>
            </div>
            <div class="pi-head-sub">
                <p class="pi-head-desc"><?php echo esc_html(get_the_title($post->ID)); ?></p>
                <div class="pi-head-links">
                    <?php if (!empty($ctx['view_link'])) : ?>
                        <a href="<?php echo esc_url($ctx['view_link']); ?>" target="_blank" rel="noopener"><?php echo esc_html($view_label); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($ctx['back_url']); ?>"><?php echo esc_html($back_label); ?></a>
                    <?php if (empty($ctx['is_publish'])) : ?>
                        <form class="pi-head-publish" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="tpi_publish_post">
                            <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>">
                            <?php wp_nonce_field('tpi_publish_post_' . $post->ID); ?>
                            <button type="submit" class="button button-primary"><?php echo esc_html(tpi_admin_ui('common.button.publish')); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($ctx['lang_items'])) : ?>
            <div class="pi-langbar">
                <span class="pi-langbar-label"><?php echo esc_html(tpi_admin_ui('common.langbar.label')); ?></span>
                <div class="pi-langbar-list">
                    <?php foreach ($ctx['lang_items'] as $item) :
                        $is_current = ($item['slug'] === $ctx['lang']);
                        ?>
                        <?php if (!empty($item['href'])) : ?>
                            <a class="pi-lang<?php echo $is_current ? ' is-current' : ''; ?>"
                               href="<?php echo esc_url($item['href']); ?>"
                               title="<?php echo esc_attr($item['name'] . ($is_current ? tpi_admin_ui('common.langbar.current') : ' · ID ' . $item['id'])); ?>">
                                <span class="pi-lang-flag"><?php echo $item['flag']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                <span class="pi-lang-name"><?php echo esc_html($item['name']); ?></span>
                            </a>
                        <?php else : ?>
                            <?php if (!empty($item['can_add'])) : ?>
                                <?php
                                // 用 POST 表单提交，避免被浏览器预取链接凭空建草稿
                                echo tpi_new_translation_form(
                                    (int) $ctx['post']->ID,
                                    (string) $item['slug'],
                                    '<span class="pi-lang-flag">' . $item['flag'] . '</span>'
                                    . '<span class="pi-lang-name">' . esc_html($item['name']) . '</span>'
                                    . '<span class="pi-lang-plus" aria-hidden="true">+</span>',
                                    'pi-lang is-empty is-add',
                                    $item['name'] . tpi_admin_ui('common.langbar.add-hint')
                                ); // phpcs:ignore WordPress.Security.EscapeOutput
                                ?>
                            <?php else : ?>
                                <span class="pi-lang is-empty" title="<?php echo esc_attr($item['name'] . tpi_admin_ui('common.langbar.empty-hint')); ?>">
                                    <span class="pi-lang-flag"><?php echo $item['flag']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                    <span class="pi-lang-name"><?php echo esc_html($item['name']); ?></span>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif;
    }
}

if (!function_exists('tpi_info_notices')) {
    /**
     * 输出统一提示条。$notices 为 ['type' => 'ok'|'error', 'text' => '...'] 列表。
     */
    function tpi_info_notices(array $notices): void
    {
        foreach ($notices as $notice) {
            $type = ($notice['type'] ?? 'ok') === 'error' ? ' is-error' : '';
            echo '<div class="pi-notice' . $type . '">' . esc_html($notice['text'] ?? '') . '</div>';
        }
    }
}
