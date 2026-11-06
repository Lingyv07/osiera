<?php
/**
 * 后台「语言」页：列表式维护语言，国旗从媒体库上传。
 *
 * 数据落在独立表 {prefix}tpi_languages：一种语言一行，
 * 含 国旗 / 语言代码 / 显示名称 / Locale / 默认 / 语言内容(JSON)；
 * 内容归属仍存 taxonomy tpi_lang。
 */

if (!function_exists('tpi_languages_page_url')) {
    function tpi_languages_page_url(): string
    {
        return admin_url('admin.php?page=languages');
    }
}

if (!function_exists('tpi_languages_normalize_row')) {
    /**
     * 清洗一行提交：返回 null 表示这一行无效要丢弃
     */
    function tpi_languages_normalize_row(array $row): ?array
    {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slug === '' || !preg_match('/^[a-z]{2,5}$/', $slug)) {
            return null;
        }
        $name = trim((string) ($row['name'] ?? ''));
        // 语言标记统一成 {语言}_{地区}：en-US → en_US（语言小写、地区大写，别整串转小写）
        $locale = tpi_locale_normalize((string) ($row['locale'] ?? ''));
        $flag_id = (int) ($row['flag_id'] ?? 0);
        $flag_url = trim((string) ($row['flag_url'] ?? ''));

        if ($flag_id > 0) {
            $url = wp_get_attachment_image_url($flag_id, 'full');
            if ($url) {
                $flag_url = $url;
            } else {
                $flag_id = 0;
            }
        }
        if ($flag_url === '') {
            $flag_url = tpi_builtin_flag_url($slug);
        }
        // 国旗同样只存站点根相对路径，换域名不用改数据
        $flag_url = tpi_media_store($flag_url);

        return [
            'slug'     => $slug,
            'name'     => $name !== '' ? $name : strtoupper($slug),
            'locale'   => $locale !== '' ? $locale : tpi_locale_default($slug),
            'flag_id'  => $flag_id,
            'flag_url' => $flag_url,
        ];
    }
}

if (!function_exists('tpi_save_languages')) {
    /**
     * 保存语言列表，返回 ['ok'=>bool,'message'=>string]
     */
    function tpi_save_languages(array $rows, string $default): array
    {
        $clean = [];
        $seen = [];
        $dups = [];
        $invalid = 0;
        foreach ($rows as $row) {
            $item = tpi_languages_normalize_row((array) $row);
            if ($item === null) {
                $invalid++;
                continue;
            }
            if (isset($seen[$item['slug']])) {
                $dups[] = $item['slug'];
                continue;
            }
            $seen[$item['slug']] = true;
            $clean[] = $item;
        }

        // 语言代码重复：不静默丢弃，直接退回让用户改
        if ($dups) {
            $dups = array_values(array_unique($dups));
            return [
                'ok'      => false,
                'message' => sprintf(tpi_admin_ui('languages.notice.dup-code'), implode('、', $dups)),
            ];
        }

        if (!$clean) {
            return ['ok' => false, 'message' => tpi_admin_ui('languages.notice.at-least-one')];
        }

        $result = tpi_languages_sync($clean, $default);

        // 老语言补 term，保证马上能归类内容（新语言在 sync 里已经建好）
        foreach ($clean as $item) {
            if (!term_exists($item['slug'], 'tpi_lang')) {
                wp_insert_term($item['name'], 'tpi_lang', ['slug' => $item['slug']]);
            }
        }

        tpi_flush_languages_cache();

        $message = tpi_admin_ui('languages.notice.saved');
        if (!empty($result['created'])) {
            $message .= sprintf(tpi_admin_ui('languages.notice.created'), count($result['created']));
        }
        if ($invalid > 0) {
            $message .= sprintf(tpi_admin_ui('languages.notice.invalid-rows'), $invalid);
        }
        return ['ok' => true, 'message' => $message];
    }
}

if (!function_exists('tpi_render_languages_page')) {
    function tpi_render_languages_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(tpi_admin_ui('languages.error.permission'));
        }

        $notice = ['type' => '', 'text' => ''];

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['tpi_lang_save'])) {
            check_admin_referer('tpi_languages_save');
            $rows = [];
            $slugs = (array) ($_POST['lang_slug'] ?? []);
            foreach ($slugs as $i => $slug) {
                $rows[] = [
                    'slug'     => $slug,
                    'name'     => $_POST['lang_name'][$i] ?? '',
                    'locale'   => $_POST['lang_locale'][$i] ?? '',
                    'flag_id'  => $_POST['lang_flag_id'][$i] ?? 0,
                    'flag_url' => $_POST['lang_flag_url'][$i] ?? '',
                ];
            }
            $result = tpi_save_languages($rows, (string) ($_POST['tpi_default_language'] ?? ''));
            $notice = [
                'type' => $result['ok'] ? 'success' : 'error',
                'text' => $result['message'],
            ];
        }

        $languages = tpi_languages(true);
        $default = tpi_default_language();
        ?>
        <div class="ad-wrap lg-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('languages.page.title')); ?></h1>
            </div>

            <?php if ($notice['text'] !== ''): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" id="tpi-languages-form">
                <?php wp_nonce_field('tpi_languages_save'); ?>
                <input type="hidden" name="tpi_lang_save" value="1">

                <div class="ad-card">
                    <div class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('languages.card.list-title')); ?></h2>
                            <p><?php echo sprintf(esc_html(tpi_admin_ui('languages.card.count-hint')), count($languages)); ?></p>
                        </div>
                        <div class="ad-card-actions">
                            <button type="button" class="button" id="tpi-lang-add"><?php echo esc_html(tpi_admin_ui('languages.button.add')); ?></button>
                        </div>
                    </div>
                    <div class="ad-card-body">
                        <table class="lg-table">
                            <thead>
                                <tr>
                                    <th class="lg-col-drag"></th>
                                    <th class="lg-col-flag"><?php echo esc_html(tpi_admin_ui('languages.col.flag')); ?></th>
                                    <th class="lg-col-slug"><?php echo esc_html(tpi_admin_ui('languages.col.code')); ?></th>
                                    <th class="lg-col-name"><?php echo esc_html(tpi_admin_ui('languages.col.name')); ?></th>
                                    <th class="lg-col-locale" title="<?php echo esc_attr(tpi_admin_ui('languages.col.locale-tip')); ?>"><?php echo esc_html(tpi_admin_ui('languages.col.locale')); ?></th>
                                    <th class="lg-col-default"><?php echo esc_html(tpi_admin_ui('languages.col.default')); ?></th>
                                    <th class="lg-col-content"><?php echo esc_html(tpi_admin_ui('languages.col.content')); ?></th>
                                    <th class="lg-col-action"></th>
                                </tr>
                            </thead>
                            <tbody id="tpi-lang-rows">
                                <?php foreach ($languages as $item): ?>
                                    <?php $has_content = tpi_lang_content_exists($item['slug']); ?>
                                    <tr class="lg-row" data-code="<?php echo esc_attr($item['slug']); ?>">
                                        <td class="lg-col-drag"><span class="lg-drag" title="<?php echo esc_attr(tpi_admin_ui('languages.row.drag')); ?>"></span></td>
                                        <td class="lg-col-flag">
                                            <button type="button" class="lg-flag-btn" title="<?php echo esc_attr(tpi_admin_ui('languages.row.flag-upload')); ?>">
                                                <img class="lg-flag-preview" src="<?php echo esc_url($item['flag_url']); ?>" alt="">
                                            </button>
                                            <input type="hidden" name="lang_flag_id[]" value="<?php echo (int) $item['flag_id']; ?>">
                                            <input type="hidden" name="lang_flag_url[]" value="<?php echo esc_attr($item['flag_url']); ?>">
                                        </td>
                                        <td class="lg-col-slug">
                                            <input type="text" name="lang_slug[]" value="<?php echo esc_attr($item['slug']); ?>" maxlength="5" class="lg-input lg-input-slug" placeholder="en" data-orig="<?php echo esc_attr($item['slug']); ?>">
                                        </td>
                                        <td class="lg-col-name">
                                            <input type="text" name="lang_name[]" value="<?php echo esc_attr($item['name']); ?>" class="lg-input" placeholder="English">
                                        </td>
                                        <td class="lg-col-locale">
                                            <input type="text" name="lang_locale[]" value="<?php echo esc_attr($item['locale']); ?>" class="lg-input" placeholder="en_us">
                                        </td>
                                        <td class="lg-col-default">
                                            <label class="lg-radio">
                                                <input type="radio" name="tpi_default_language" value="<?php echo esc_attr($item['slug']); ?>" <?php checked($default, $item['slug']); ?>>
                                                <span class="screen-reader-text"><?php echo esc_html(tpi_admin_ui('languages.row.set-default')); ?></span>
                                            </label>
                                        </td>
                                        <td class="lg-col-content">
                                            <a class="button button-small lg-content-btn" href="<?php echo esc_url(add_query_arg(['page' => 'language-content', 'lang' => $item['slug']], admin_url('admin.php'))); ?>">
                                                <span class="lg-content-dot<?php echo $has_content ? ' is-on' : ''; ?>" aria-hidden="true"></span><?php echo esc_html(tpi_admin_ui('languages.button.edit-content')); ?>
                                            </a>
                                        </td>
                                        <td class="lg-col-action">
                                            <button type="button" class="lg-del button-link"><?php echo esc_html(tpi_admin_ui('languages.button.delete')); ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="ad-hint"><?php echo esc_html(tpi_admin_ui('languages.card.delete-hint-before')); ?><?php echo esc_html(tpi_admin_ui('languages.card.delete-hint-after')); ?></p>
                        <?php $deleted_rows = tpi_languages_deleted_rows(); ?>
                        <?php if ($deleted_rows): ?>
                            <p class="lg-deleted-note">
                                <?php
                                $deleted_codes = array_map(static function ($row) {
                                    return (string) $row['code'];
                                }, $deleted_rows);
                                echo esc_html(
                                    sprintf(tpi_admin_ui('languages.card.deleted-note'), count($deleted_rows))
                                    . implode(tpi_admin_ui('languages.card.deleted-sep'), $deleted_codes)
                                    . tpi_admin_ui('languages.card.deleted-restore')
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                        <p class="lg-dup-note" data-lang-dup-note></p>
                    </div>
                </div>

                <div class="pi-savebar">
                    <div class="pi-savebar-main">
                        <span class="pi-savebar-hint"><?php echo esc_html(tpi_admin_ui('languages.savebar.hint')); ?></span>
                    </div>
                    <div class="pi-savebar-actions">
                        <button type="submit" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('languages.button.save')); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <script type="text/html" id="tpi-lang-row-tpl">
            <tr class="lg-row">
                <td class="lg-col-drag"><span class="lg-drag" title="<?php echo esc_attr(tpi_admin_ui('languages.row.drag')); ?>"></span></td>
                <td class="lg-col-flag">
                    <button type="button" class="lg-flag-btn" title="<?php echo esc_attr(tpi_admin_ui('languages.row.flag-upload')); ?>">
                        <img class="lg-flag-preview" src="" alt="">
                    </button>
                    <input type="hidden" name="lang_flag_id[]" value="0">
                    <input type="hidden" name="lang_flag_url[]" value="">
                </td>
                <td class="lg-col-slug"><input type="text" name="lang_slug[]" value="" maxlength="5" class="lg-input lg-input-slug" placeholder="en"></td>
                <td class="lg-col-name"><input type="text" name="lang_name[]" value="" class="lg-input" placeholder="English"></td>
                <td class="lg-col-locale"><input type="text" name="lang_locale[]" value="" class="lg-input" placeholder="en_us"></td>
                <td class="lg-col-default"><label class="lg-radio"><input type="radio" name="tpi_default_language" value=""><span class="screen-reader-text"><?php echo esc_html(tpi_admin_ui('languages.row.set-default')); ?></span></label></td>
                <td class="lg-col-content"><span class="lg-content-tip"><?php echo esc_html(tpi_admin_ui('languages.row.auto-template')); ?></span></td>
                <td class="lg-col-action"><button type="button" class="lg-del button-link"><?php echo esc_html(tpi_admin_ui('languages.button.delete')); ?></button></td>
            </tr>
        </script>
        <?php
    }
}
