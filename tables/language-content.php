<?php
/**
 * 后台「语言内容」页
 *
 * 一个语言 = 一行记录（{prefix}tpi_languages.content 里的完整 JSON）。
 *   1. 可视化模式：按系统模板归类成卡片，逐字段编辑，列表可增删；
 *   2. JSON 模式：直接改整份 JSON，适合批量替换或从外部粘贴。
 *
 * 缺失的字段不用手动补齐 —— 读取时会按「系统模板 → 该语言 → 默认语言」兜底。
 */

add_action('admin_menu', 'tpi_register_language_content_menu', 12);

/**
 * 语言内容页要拖拽排序和选文件，单独补两个资源
 * （优先级 9 保证先于 wp-assets/js/wp-manage.js 入队，脚本按顺序输出）
 */
add_action('admin_enqueue_scripts', 'tpi_language_content_assets', 9);

if (!function_exists('tpi_language_content_assets')) {
    function tpi_language_content_assets(string $hook = ''): void
    {
        if (strpos($hook, 'language-content') === false) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
    }
}

if (!function_exists('tpi_register_language_content_menu')) {
    function tpi_register_language_content_menu(): void
    {
        add_submenu_page(
            'languages',
            tpi_admin_ui('language-content.menu-title'),
            tpi_admin_ui('language-content.menu-title'),
            'manage_options',
            'language-content',
            'tpi_render_language_content_page'
        );
    }
}

if (!function_exists('tpi_lc_page_url')) {
    function tpi_lc_page_url(string $lang = '', string $mode = ''): string
    {
        $args = ['page' => 'language-content'];
        if ($lang !== '') {
            $args['lang'] = $lang;
        }
        if ($mode !== '') {
            $args['mode'] = $mode;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }
}

/* =========================================================
 * 一、渲染辅助
 * ========================================================= */

if (!function_exists('tpi_lc_value_is_long')) {
    /** 值本身够长或带换行，就该用 textarea */
    function tpi_lc_value_is_long($value): bool
    {
        $value = is_array($value) ? '' : (string) $value;
        return mb_strlen($value) > 60 || strpos($value, "\n") !== false;
    }
}

if (!function_exists('tpi_lc_key_is_desc')) {
    /**
     * 字段名本身就是「说明 / 描述」类的，不管内容长短都用多行文本框：
     * 优势说明、规格项说明、公司简介、各页 SEO 描述等。
     *
     * 注意有例外：header.select-language-description 的标签是「选择语言提示」、
     * header.social-media-description 其实是「社媒区标题」，这类仍用单行输入框，
     * 所以键名像描述时还要看中文标签。
     */
    function tpi_lc_key_is_desc(string $key, string $label = ''): bool
    {
        $plain = strtolower(str_replace(['-', '_'], '', $key));
        if ($plain === 'text' || $plain === 'desc' || substr($plain, -4) === 'desc') {
            return true;
        }
        $tail = substr($plain, -11); // description
        if ($tail === 'description' || substr($plain, -7) === 'content') {
            if ($label === '') {
                return true;
            }
            return (bool) preg_match('/说明|描述|简介|正文|内容|详情/u', $label);
        }
        return false;
    }
}

if (!function_exists('tpi_lc_render_text')) {
    /**
     * 单个文本字段
     *
     * @param bool $multiline 是否允许退化成 textarea
     * @param bool $wide      是否占满整行（描述字段占满时，同前缀的标题也跟着占满）
     * @param bool $force_long 强制 textarea（说明类字段用，内容为空也保持多行）
     */
    function tpi_lc_render_text(string $label, string $name, $value, bool $multiline = true, bool $wide = false, bool $force_long = false): void
    {
        $value = is_array($value) ? '' : (string) $value;
        $long  = $force_long || ($multiline && tpi_lc_value_is_long($value));
        if ($long) {
            $wide = true;
        }
        echo '<label class="lc-field' . ($wide ? ' lc-field-wide' : '') . '">';
        echo '<span class="lc-field-label">' . esc_html($label) . '</span>';
        if ($long) {
            printf(
                '<textarea name="%s" rows="%d" class="lc-input">%s</textarea>',
                esc_attr($name),
                min(10, max(2, (int) ceil(mb_strlen($value) / 70))),
                esc_textarea($value)
            );
        } else {
            printf('<input type="text" name="%s" value="%s" class="lc-input">', esc_attr($name), esc_attr($value));
        }
        echo '</label>';
    }
}

if (!function_exists('tpi_lc_render_file')) {
    /** 文件类字段：缩略图 + 路径 + 媒体库选择 */
    function tpi_lc_render_file(string $label, string $name, $value): void
    {
        $value = (string) $value;
        $url   = $value !== '' && function_exists('tpi_media_url') ? tpi_media_url($value) : $value;

        echo '<div class="lc-field lc-field-wide lc-file" data-lc-file>';
        echo '<span class="lc-field-label">' . esc_html($label) . '<em>'
            . esc_html(tpi_admin_ui('file-hint')) . '</em></span>';
        echo '<div class="lc-file-row">';
        echo '<span class="lc-file-thumb" data-lc-file-thumb>';
        if ($url !== '') {
            echo '<img src="' . esc_url($url) . '" alt="">';
        } else {
            echo '<span class="lc-file-none">' . esc_html(tpi_admin_ui('file-none')) . '</span>';
        }
        echo '</span>';
        printf(
            '<input type="text" name="%s" value="%s" class="lc-input lc-file-path" placeholder="%s">',
            esc_attr($name),
            esc_attr($value),
            esc_attr(tpi_admin_ui('file-placeholder'))
        );
        echo '<button type="button" class="button lc-file-pick">' . esc_html(tpi_admin_ui('file-pick')) . '</button>';
        echo '<button type="button" class="button-link lc-file-clear">' . esc_html(tpi_admin_ui('file-clear')) . '</button>';
        echo '</div></div>';
    }
}

if (!function_exists('tpi_lc_render_tags')) {
    /** 标签类字段：输入框紧贴标题，输入即生成标签块，可删除 */
    function tpi_lc_render_tags(string $label, string $name, array $items): void
    {
        echo '<div class="lc-field lc-field-wide lc-tags" data-lc-tags>';
        echo '<span class="lc-field-label">' . esc_html($label) . '<em>'
            . esc_html(tpi_admin_ui('tag-hint')) . '</em></span>';
        printf(
            '<input type="text" class="lc-input lc-tag-input" placeholder="%s" data-lc-tag-input data-lc-tag-name="%s">',
            esc_attr(tpi_admin_ui('tag-placeholder')),
            esc_attr($name)
        );
        echo '<div class="lc-tag-list" data-lc-tag-list>';
        foreach ($items as $item) {
            if (is_array($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            echo '<span class="lc-tag">';
            printf('<input type="hidden" name="%s[]" value="%s">', esc_attr($name), esc_attr($text));
            echo '<span class="lc-tag-text">' . esc_html($text) . '</span>';
            echo '<button type="button" class="lc-tag-del" aria-label="' . esc_attr(tpi_admin_ui('language-content.tag.delete')) . '">×</button>';
            echo '</span>';
        }
        echo '</div>';
        echo '</div>';
    }
}

if (!function_exists('tpi_lc_template_shape')) {
    /** 从 meta.shapes / 系统模板里取某个路径的样例值，给空列表做「添加一项」的模板 */
    function tpi_lc_template_shape(string $section, string $path)
    {
        // 语言包模板里已经不放业务内容，结构统一由 languages/admin-system-config-{zh|en}.json 的 meta.shapes 给
        $shape = tpi_admin_shape($section . '.' . $path);
        if ($shape) {
            return $shape;
        }
        $node = tpi_lang_template_strings()[$section] ?? [];
        if ($path === '') {
            return $node;
        }
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return [];
            }
            $node = $node[$part];
        }
        return $node;
    }
}

/**
 * 渲染一个对象（分组内容 / 列表项）
 *
 * @param string $section   分组名，如 home
 * @param string $path      字段路径（不含分组），如 summary-info-items.0
 * @param array  $data
 * @param string $name_base 表单名，如 lc[about-us][summary-info-items][0]
 * @param int    $depth
 */
if (!function_exists('tpi_lc_render_object')) {
    function tpi_lc_render_object(string $section, string $path, array $data, string $name_base, int $depth = 0): void
    {
        /**
         * 先算出这一层哪些字段要占满整行：
         *   1. 内容够长的字段（描述、正文）本身占满；
         *   2. 描述占满时，配对的标题也跟着占满 —— 同前缀（xxx-title / xxx-desc），
         *      或紧挨在描述前面的标题（SEO 的 title + description 就是这种写法）。
         */
        $wide_map = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $key = (string) $key;
            if (tpi_lc_value_is_long($value)) {
                $wide_map[$key] = true;
                continue;
            }
            $desc_path = $path === '' ? $key : $path . '.' . $key;
            if (tpi_lc_key_is_desc($key, tpi_lang_field_label($section, $desc_path))) {
                $wide_map[$key] = true;
            }
        }

        $is_title_key = static function (string $key): bool {
            return (bool) preg_match('/(title|heading|name|label)$/', strtolower(str_replace(['-', '_'], '', $key)));
        };

        $keys = array_keys($data);
        foreach ($keys as $index => $key) {
            $key = (string) $key;
            if (is_array($data[$key]) || isset($wide_map[$key]) || !$is_title_key($key)) {
                continue;
            }

            // 同前缀的兄弟是 wide
            $prefix = preg_replace('/[-_][^-_]+$/', '', $key);
            $paired = false;
            if ($prefix !== '' && $prefix !== $key) {
                foreach ($wide_map as $wide_key => $unused) {
                    if ($prefix === preg_replace('/[-_][^-_]+$/', '', (string) $wide_key)) {
                        $paired = true;
                        break;
                    }
                }
            }

            // 紧跟在后面的字段是 wide（title 在 desc 前面）
            if (!$paired && isset($keys[$index + 1])) {
                $next = (string) $keys[$index + 1];
                if (!is_array($data[$next]) && isset($wide_map[$next])) {
                    $paired = true;
                }
            }

            if ($paired) {
                $wide_map[$key] = true;
            }
        }

        /**
         * 连续的非整行字段如果剩单数个，最后那个也占满整行 —— 否则右边会空半格。
         * 列表 / 子对象本身占整行，会把连续段打断。
         */
        $run = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (is_array($data[$key]) || isset($wide_map[$key])) {
                if (count($run) % 2 === 1) {
                    $wide_map[end($run)] = true;
                }
                $run = [];
                continue;
            }
            $run[] = $key;
        }
        if (count($run) % 2 === 1) {
            $wide_map[end($run)] = true;
        }

        foreach ($data as $key => $value) {
            $key   = (string) $key;
            $fpath = $path === '' ? $key : $path . '.' . $key;
            $fname = $name_base . '[' . $key . ']';
            $label = tpi_lang_field_label($section, $fpath);
            $full  = $section . '.' . $fpath;

            if (!is_array($value)) {
                if (tpi_admin_config_has('files', $full)) {
                    tpi_lc_render_file($label, $fname, (string) $value);
                } else {
                    tpi_lc_render_text($label, $fname, $value, true, isset($wide_map[$key]), tpi_lc_key_is_desc($key, $label));
                }
                continue;
            }

            // 标签类列表：输入即生成标签块（空列表也要能加）
            if (tpi_is_list($value) && tpi_admin_config_has('tags', $full)) {
                tpi_lc_render_tags($label, $fname, $value);
                continue;
            }

            if (!$value) {
                // 空列表：有字段结构就照常渲染「添加一项」，没有就提示去 JSON 模式
                $empty_shape = tpi_lc_template_shape($section, $fpath);
                if (tpi_is_list($value) && is_array($empty_shape) && $empty_shape) {
                    tpi_lc_render_object_list($section, $fpath, $label, [], $fname, $depth);
                    continue;
                }
                echo '<div class="lc-field lc-field-wide lc-field-empty">';
                echo '<span class="lc-field-label">' . esc_html($label) . '</span>';
                echo '<p class="lc-empty-tip">' . esc_html(tpi_admin_ui('list-empty')) . '</p>';
                echo '</div>';
                continue;
            }

            if (tpi_is_list($value)) {
                $first = reset($value);
                if (is_array($first)) {
                    tpi_lc_render_object_list($section, $fpath, $label, $value, $fname, $depth);
                } else {
                    // 纯文本列表：一行一条
                    echo '<label class="lc-field lc-field-wide">';
                    echo '<span class="lc-field-label">' . esc_html($label) . '<em>'
                        . esc_html(tpi_admin_ui('list-lines')) . '</em></span>';
                    printf(
                        '<textarea name="%s[__lines]" rows="%d" class="lc-input">%s</textarea>',
                        esc_attr($fname),
                        min(12, max(3, count($value) + 1)),
                        esc_textarea(implode("\n", array_map('strval', $value)))
                    );
                    echo '</label>';
                }
                continue;
            }

            // 子对象
            echo '<fieldset class="lc-subgroup lc-field-wide">';
            echo '<legend>' . esc_html($label) . '</legend>';
            echo '<div class="lc-fields">';
            tpi_lc_render_object($section, $fpath, $value, $fname, $depth + 1);
            echo '</div></fieldset>';
        }
    }
}

if (!function_exists('tpi_lc_render_object_list')) {
    /** 列表（每一项是对象）：可增删 */
    function tpi_lc_render_object_list(string $section, string $path, string $label, array $items, string $name_base, int $depth = 0): void
    {
        $shape = tpi_lc_template_shape($section, $path);
        if (is_array($shape) && isset($shape[0]) && is_array($shape[0])) {
            $shape = $shape[0];
        } elseif (!is_array($shape)) {
            $shape = [];
        }
        if (!$shape) {
            $shape = is_array(reset($items)) ? reset($items) : ['text' => ''];
        }

        echo '<div class="lc-field lc-field-wide lc-list" data-lc-list data-lc-name="' . esc_attr($name_base) . '">';
        echo '<div class="lc-list-head">';
        echo '<span class="lc-field-label">' . esc_html($label) . '<em>'
            . (int) count($items) . ' ' . esc_html(tpi_admin_ui('list-count')) . '</em></span>';
        echo '<button type="button" class="button lc-add">'
            . esc_html(tpi_admin_ui('list-add')) . '</button>';
        echo '</div>';

        // 图标型列表（「我们的优势」这种 icon + 说明）：图标在上、说明在下，两栏并排
        $icon_mode = false;
        foreach (array_keys($shape) as $shape_key) {
            if (is_string($shape_key) && tpi_admin_config_has('files', $section . '.' . ($path !== '' ? $path . '.' : '') . $shape_key)) {
                $icon_mode = true;
                break;
            }
        }
        echo '<div class="lc-items' . ($icon_mode ? ' lc-items-icon' : '') . '">';

        $i = 0;
        foreach ($items as $item) {
            tpi_lc_render_object_item($section, $path, (int) $i, is_array($item) ? $item : [], $name_base);
            $i++;
        }
        echo '</div>';

        // 新增项的模板（JS 复制它）
        echo '<template class="lc-item-tpl">';
        tpi_lc_render_object_item($section, $path, 0, $shape, $name_base, true);
        echo '</template>';
        echo '</div>';
    }
}

if (!function_exists('tpi_lc_render_object_item')) {
    function tpi_lc_render_object_item(string $section, string $path, int $index, array $item, string $name_base, bool $is_template = false): void
    {
        $item_name = $name_base . '[' . $index . ']';
        echo '<div class="lc-item" data-lc-item>';
        echo '<div class="lc-item-head">';
        echo '<span class="lc-item-drag" title="' . esc_attr(tpi_admin_ui('list-drag')) . '" aria-hidden="true"></span>';
        echo '<button type="button" class="button-link lc-del">'
            . esc_html(tpi_admin_ui('list-delete')) . '</button>';
        echo '</div>';
        echo '<div class="lc-item-body lc-fields">';

        // 按模板形状补齐缺的键，避免某项少字段后无法编辑
        $shape = tpi_lc_template_shape($section, $path);
        if (is_array($shape) && isset($shape[0]) && is_array($shape[0])) {
            $shape = $shape[0];
        }
        if (is_array($shape)) {
            foreach ($shape as $key => $default) {
                if (is_string($key) && !is_array($default) && !array_key_exists($key, $item)) {
                    $item[$key] = $is_template ? $default : '';
                }
            }
        }

        /**
         * 这一项里的字段排两栏还是整行：
         *   说明类 / 内容很长的字段整行；文件（图标）字段整行；
         *   连续的单行字段剩单数个时，最后一个也整行，避免一行只有半格。
         */
        $fields = [];
        $wide   = [];
        foreach ($item as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                continue;
            }
            $field_path = $path . '.' . $key;
            $fields[$key] = $value;
            if (tpi_admin_config_has('files', $section . '.' . $field_path)
                || tpi_lc_key_is_desc($key, tpi_lang_field_label($section, $field_path))
                || tpi_lc_value_is_long($value)) {
                $wide[$key] = true;
            }
        }
        $run = [];
        foreach (array_keys($fields) as $key) {
            if (isset($wide[$key])) {
                if (count($run) % 2 === 1) {
                    $wide[end($run)] = true;
                }
                $run = [];
                continue;
            }
            $run[] = $key;
        }
        if (count($run) % 2 === 1) {
            $wide[end($run)] = true;
        }

        foreach ($item as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                continue; // 列表里再套列表：交给 JSON 模式
            }
            $field_path = $path . '.' . $key;
            $item_label = tpi_lang_field_label($section, $field_path);
            if (tpi_admin_config_has('files', $section . '.' . $field_path)) {
                tpi_lc_render_file(
                    $item_label,
                    $item_name . '[' . $key . ']',
                    $is_template ? '' : (string) $value
                );
                continue;
            }
            tpi_lc_render_text(
                $item_label,
                $item_name . '[' . $key . ']',
                $is_template ? '' : $value,
                true,
                isset($wide[$key]),
                tpi_lc_key_is_desc($key, $item_label)
            );
        }
        echo '</div></div>';
    }
}

/* =========================================================
 * 二、页面
 * ========================================================= */

if (!function_exists('tpi_render_language_content_page')) {
    function tpi_render_language_content_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(tpi_admin_ui('language-content.error.permission'));
        }

        $langs = tpi_language_slugs();
        $lang  = isset($_GET['lang']) ? strtolower(sanitize_text_field(wp_unslash($_GET['lang']))) : '';
        if ($lang === '' || !in_array($lang, $langs, true)) {
            /**
             * 进页面时没有 ?lang=，按优先级取：
             *   1. 后台当前语言筛选（顶栏 / 列表页刚选过的语言）；
             *   2. 上一次在这里编辑的语言；
             *   3. 默认语言。
             * 这样才能做到「在哪里选了语言，语言内容就是哪个语言」。
             */
            $current = tpi_admin_language_filter();
            if (in_array($current, $langs, true)) {
                $lang = $current;
            } else {
                $last = (string) get_user_meta(get_current_user_id(), 'tpi_lc_last_lang', true);
                $lang = in_array($last, $langs, true) ? $last : tpi_default_language();
            }
        }
        if (in_array($lang, $langs, true)) {
            // 在这里切换语言，也同步回后台筛选，避免两边说法不一致
            update_user_meta(get_current_user_id(), 'tpi_lc_last_lang', $lang);
            if ((string) get_user_meta(get_current_user_id(), 'tpi_filter_content', true) !== $lang) {
                update_user_meta(get_current_user_id(), 'tpi_filter_content', $lang);
            }
        }
        $mode = (isset($_GET['mode']) && $_GET['mode'] === 'json') ? 'json' : 'visual';
        // 「填入默认文案」只是把该语言的默认值塞进表单，保存后才写库
        $fill_template = isset($_GET['fill']) && $_GET['fill'] === 'template';

        // 分组结构按当前语言的默认值走（各语言结构一致，只是文案不同）
        $template_sections = tpi_lang_template_strings($lang);
        $notice = ['type' => '', 'text' => ''];
        $is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

        if ($is_post && isset($_POST['tpi_lc_action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['tpi_lc_action']));
            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tpi_lc_save');
            if (!$nonce_ok) {
                $notice = ['type' => 'error', 'text' => tpi_admin_ui('language-content.notice.nonce-expired')];
            } elseif ($action === 'save') {
                if ($mode === 'json') {
                    $raw  = (string) wp_unslash($_POST['lc_json'] ?? '');
                    $data = json_decode($raw, true);
                    if (!is_array($data)) {
                        $notice = ['type' => 'error', 'text' => tpi_admin_ui('language-content.error.json') . json_last_error_msg()];
                    } else {
                        $save = [];
                        foreach ($data as $section => $value) {
                            if (strpos((string) $section, '__') === 0) {
                                continue;
                            }
                            if (!array_key_exists($section, $template_sections)) {
                                continue; // 模板里没有的分组不落库，避免脏键
                            }
                            $save[$section] = tpi_lc_normalize_files($section, $value);
                        }
                        $n = tpi_lang_content_save_many($lang, $save);
                        $notice = ['type' => 'success', 'text' => sprintf(tpi_admin_ui('language-content.notice.saved-count'), $n)];
                    }
                } else {
                    $posted = isset($_POST['lc']) && is_array($_POST['lc']) ? wp_unslash($_POST['lc']) : [];
                    $save = [];
                    foreach ($posted as $section => $value) {
                        if (!array_key_exists($section, $template_sections)) {
                            continue;
                        }
                        $save[$section] = tpi_lang_normalize_data(tpi_lc_normalize_files($section, tpi_lc_clean_post($value)));
                    }
                    $n = tpi_lang_content_save_many($lang, $save);
                    $notice = ['type' => 'success', 'text' => sprintf(tpi_admin_ui('language-content.notice.saved-count'), $n)];
                }
            }
        }

        // 「填入默认文案」只补空着的字段，不覆盖已填内容（默认值文件里已经没有站点业务数据），
        // 也不加提示条；页面脚本会把 URL 上的 fill 参数去掉，刷新或切换语言就回到已保存的内容
        $strings = $fill_template
            ? tpi_lang_fill_blank(tpi_lang_content_get($lang), tpi_lang_template_strings($lang))
            : tpi_lang_content_get($lang);
        $groups    = tpi_lang_section_groups();
        $stats     = tpi_lang_content_stats($lang);
        $row       = tpi_language_row($lang);
        $updated   = $row ? (string) $row['updated_at'] : '';
        $json_text = wp_json_encode(
            array_intersect_key($strings, $template_sections),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        ?>
        <div class="ad-wrap lc-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('language-content.page.title')); ?></h1>
            </div>

            <?php tpi_admin_slogan('language-content'); ?>

            <?php tpi_admin_lang_tabs('language-content', $lang, false); ?>

            <?php if ($notice['text'] !== ''): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <div class="lc-toolbar">
                <div class="lc-toolbar-info">
                    <span class="lc-toolbar-desc">
                        <?php echo sprintf(esc_html(tpi_admin_ui('language-content.stats.summary')), (int) $stats['sections'], (int) $stats['filled'], (int) $stats['fields']); ?>
                        <?php if ($updated !== ''): ?><?php echo sprintf(esc_html(tpi_admin_ui('language-content.stats.updated')), esc_html($updated)); ?><?php endif; ?>
                    </span>
                </div>
                <div class="lc-toolbar-actions">
                    <div class="lc-mode" role="group" aria-label="<?php echo esc_attr(tpi_admin_ui('language-content.mode.aria-label')); ?>">
                        <a class="lc-mode-btn<?php echo $mode === 'visual' ? ' is-current' : ''; ?>" href="<?php echo esc_url(tpi_lc_page_url($lang, 'visual')); ?>"><?php echo esc_html(tpi_admin_ui('mode-visual')); ?></a>
                        <a class="lc-mode-btn<?php echo $mode === 'json' ? ' is-current' : ''; ?>" href="<?php echo esc_url(tpi_lc_page_url($lang, 'json')); ?>"><?php echo esc_html(tpi_admin_ui('mode-json')); ?></a>
                    </div>
                    <a class="button" href="<?php echo esc_url(add_query_arg('fill', 'template', tpi_lc_page_url($lang, $mode))); ?>" onclick="return confirm('<?php echo esc_js(tpi_admin_ui('language-content.confirm.fill-template')); ?>');"><?php echo esc_html(tpi_admin_ui('fill-template')); ?></a>
                </div>
            </div>

            <form method="post" id="lc-form" action="<?php echo esc_url(tpi_lc_page_url($lang, $mode)); ?>">
                <?php wp_nonce_field('tpi_lc_save'); ?>
                <input type="hidden" name="tpi_lc_action" value="save">

                <?php if ($mode === 'json'): ?>
                    <?php tpi_admin_slogan('language-content-json'); ?>
                    <section class="ad-card lc-jsoncard">
                        <header class="ad-card-head">
                            <div>
                                <h2><?php echo esc_html(tpi_admin_ui('language-content.json.card-title')); ?></h2>
                                <p><?php echo esc_html(tpi_admin_ui('language-content.json.card-hint-before')); ?><?php echo esc_html(tpi_admin_ui('language-content.json.card-hint-after')); ?></p>
                            </div>
                            <span class="ad-badge"><?php echo sprintf(esc_html(tpi_admin_ui('language-content.card.count')), count($template_sections)); ?></span>
                        </header>
                        <div class="ad-card-body">
                            <textarea name="lc_json" id="lc-json" class="ad-code lc-json" spellcheck="false" rows="30"><?php echo esc_textarea($json_text); ?></textarea>
                            <div class="lc-jsonfoot">
                                <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('language-content.json.foot-hint-before')); ?><code>__</code><?php echo esc_html(tpi_admin_ui('language-content.json.foot-hint-after')); ?></span>
                                <span class="lc-jsonfoot-len" data-lc-json-len></span>
                            </div>
                        </div>
                    </section>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <section class="ad-card lc-group">
                            <header class="ad-card-head">
                                <div>
                                    <h2><?php echo esc_html($group['label']); ?></h2>
                                    <?php if ($group['desc'] !== ''): ?>
                                        <p><?php echo esc_html($group['desc']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <span class="ad-badge"><?php echo sprintf(esc_html(tpi_admin_ui('language-content.card.count')), count($group['sections'])); ?></span>
                            </header>
                            <div class="ad-card-body">
                                <?php foreach ($group['sections'] as $section): ?>
                                    <?php
                                    $labels = tpi_lang_section_labels();
                                    $section_label = $labels[$section] ?? $section;
                                    $section_data = $strings[$section] ?? [];
                                    if (!is_array($section_data)) {
                                        $section_data = [];
                                    }
                                    ?>
                                    <fieldset class="lc-section" id="lc-<?php echo esc_attr($section); ?>">
                                        <legend>
                                            <span class="lc-section-name"><?php echo esc_html($section_label); ?></span>
                                            <code><?php echo esc_html($section); ?></code>
                                        </legend>
                                        <div class="lc-fields">
                                            <?php tpi_lc_render_object($section, '', $section_data, 'lc[' . $section . ']'); ?>
                                        </div>
                                    </fieldset>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="ad-savebar">
                    <span class="ad-savebar-hint"><?php echo esc_html(tpi_admin_ui('language-content.savebar.hint')); ?></span>
                    <button type="submit" class="button button-primary button-large"><?php echo esc_html(tpi_admin_ui('save')); ?></button>
                </div>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('tpi_lc_clean_post')) {
    /** 把提交上来的「一行一条」还原成数组 */
    function tpi_lc_clean_post($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (count($value) === 1 && array_key_exists('__lines', $value)) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $value['__lines']);
            $lines = array_map('trim', $lines ?: []);
            return array_values(array_filter($lines, static function ($line) {
                return $line !== '';
            }));
        }
        foreach ($value as $key => $item) {
            $value[$key] = tpi_lc_clean_post($item);
        }
        return $value;
    }
}

if (!function_exists('tpi_lc_stored_file_value')) {
    /**
     * 文件字段的落库值
     *
     * 媒体库里的文件存「上传目录相对路径」（2026/10/Logistics.png）：
     * 文件名并不唯一——不同年月的目录里可以有同名文件（2025/10/x.jpg 与 2026/01/x.jpg），
     * 只存文件名时 tpi_media_url() 反查只能取最新那份，选中的图会被顶替。
     * 带上目录后既能精确定位，也不影响换域名、换上传目录（生产走 /uploads/assets）。
     * 主题自带的 /assets/... 资源保持路径不变。
     */
    function tpi_lc_stored_file_value(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // 主题自带资源：原样保留
        if (strpos($value, '/assets/') === 0) {
            return $value;
        }
        // 媒体库文件：统一转成上传目录相对路径，带目录才唯一
        $rel = function_exists('tpi_media_rel') ? tpi_media_rel($value) : '';
        if ($rel !== '' && function_exists('tpi_media_is_uploads_rel') && tpi_media_is_uploads_rel($rel)) {
            return $rel;
        }
        // 上传目录以外的站内文件：退回文件名
        $url = function_exists('tpi_media_url') ? tpi_media_url($value) : $value;
        if ($url === '') {
            return $value;
        }
        if (strpos($url, '/assets/') === 0) {
            return $url;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = $path !== '' ? basename($path) : '';
        if ($base !== '' && preg_match('/\.(png|jpe?g|gif|webp|svg|avif|ico|pdf)$/i', $base)) {
            return $base;
        }
        return $value;
    }
}

if (!function_exists('tpi_lc_normalize_files')) {
    /**
     * 文件类字段统一成「文件名 / 主题相对路径」
     *
     * @param string $section 分组名
     * @param mixed  $value
     * @param string $path    当前路径
     * @return mixed
     */
    function tpi_lc_normalize_files(string $section, $value, string $path = '')
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $sub = $path === '' ? (string) $key : $path . '.' . $key;
                // 列表项不参与路径判断（避免 keywords.0 这类键混进来）
                $value[$key] = tpi_lc_normalize_files($section, $item, is_int($key) ? $path : $sub);
            }
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }
        // 列表里的字段路径带下标（our-strengths-items.0.icon），判断时去掉
        $plain = preg_replace('/\.\d+(?=\.|$)/', '', $path);
        if (tpi_admin_config_has('files', $section . '.' . $plain)) {
            return tpi_lc_stored_file_value($value);
        }
        return $value;
    }
}
