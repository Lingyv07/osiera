<?php
// 菜单注册在 inc/admin/admin-menu.php（FAQ 管理 → 询盘记录），这里只管页面

/**
 * 询盘表名：始终跟随 wp-config.php 里的 $table_prefix，不再写死 wp_ / 无前缀
 */
function tpi_inquiry_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'inquiries';
}

/**
 * 内容异常拦截转发开关（后台：邮箱设置 → 询盘拦截）
 *
 * 默认开启：开启时 isAbnormal() 判为异常的内容只入库、不转发邮件，
 * 记录的 remark 标记为「未转发询盘-内容异常」；关闭后该语言的询盘全部正常转发。
 *
 * 跟 SMTP 配置存在同一组（scope=setting、group_key=smtp）：
 *   lang_id = 0  全站默认（「全部语言」tab）
 *   lang_id > 0  单语言覆盖，没记这一项的语言自动继承全站默认
 * 所以传哪条询盘的语言，就按哪个语言的开关算。
 *
 * @param string $lang 语言代码，空 = 只读全站默认
 * @return bool
 */
function tpi_inquiry_abnormal_filter_enabled(string $lang = ''): bool
{
    // SMTP 那套没加载时按「开启」处理，保持原来的拦截行为
    if (!function_exists('tpi_get_smtp_settings')) {
        return true;
    }
    $settings = tpi_get_smtp_settings($lang);
    return (string) ($settings['abnormal_block'] ?? '1') !== '0';
}

/**
 * 历史版本把表建成了不带前缀的 inquiries，这里一次性改名到 {prefix}inquiries
 * （目标表已存在时不动，避免覆盖现有数据）
 */
function tpi_inquiry_migrate_table(): void {
    global $wpdb;
    $target = tpi_inquiry_table();
    if (get_option('tpi_inquiry_table_migrated') === $target) {
        return;
    }
    $legacy = 'inquiries';
    if ($target === $legacy) {
        update_option('tpi_inquiry_table_migrated', $target, false);
        return;
    }
    $has_target = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target));
    $has_legacy = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy));
    if (!$has_target && $has_legacy) {
        $wpdb->query("RENAME TABLE `{$legacy}` TO `{$target}`");
        $has_target = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target));
    }
    if ($has_target) {
        update_option('tpi_inquiry_table_migrated', $target, false);
    }
}
add_action('admin_init', 'tpi_inquiry_migrate_table', 5);

/**
 * 创建数据inquiries库表
 *
 * 建表已统一到 inc/database/db-tables.php（tpi_db_install 里会调），
 * 这里保留函数名做兼容：老代码或手工调用都能正常建表，且重复执行不会出错。
 * @return void
 */
function create_inquiries_table(): void {
    if (function_exists('tpi_db_extra_install')) {
        tpi_db_extra_install();
        return;
    }

    global $wpdb;
    $table_name = tpi_inquiry_table();

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        telephone VARCHAR(50) DEFAULT '',
        message MEDIUMTEXT NOT NULL,
        remark MEDIUMTEXT NOT NULL,
        lang VARCHAR(45) DEFAULT '',
        ip VARCHAR(45) DEFAULT '',
        token VARCHAR(128) DEFAULT '',
        origin_type VARCHAR(45) DEFAULT '',
        utm_term VARCHAR(45) DEFAULT '',
        referer_domain VARCHAR(128) DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
//create_inquiries_table();
// 建表钩子已合并到 inc/setup/theme-setup.php 的 after_switch_theme → tpi_db_install()，
// 这里不再单独注册，避免同一件事有两个入口。create_inquiries_table() 仍可直接调用。

/**
 * 站点正式域名（设置 → 站点路径 → 目标域名）
 *
 * referer 落成本地地址时用它还原，避免后台显示一串本地地址。
 * 没配目标域名就退回当前站点自己的域名，至少不是空的。
 *
 * @return array{url:string,host:string}
 */
function tpi_inquiry_site_origin(): array {
    $target = function_exists('tpi_target_host') ? trim((string) tpi_target_host()) : '';
    if ($target === '') {
        $target = home_url('/');
    }
    if (!preg_match('#^https?://#i', $target)) {
        $target = 'https://' . ltrim($target, '/');
    }
    $host = (string) parse_url($target, PHP_URL_HOST);
    if ($host === '') {
        $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
    }
    return ['url' => rtrim($target, '/'), 'host' => strtolower($host)];
}

/**
 * 主机名是否属于「本站」：本地回环、内网、当前站点域名都算
 */
function tpi_inquiry_host_is_local(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === '') {
        return true;
    }
    // parse_url 不返回端口，这里再兜一层 host:port 的写法
    $host = (string) preg_replace('#:\d+$#', '', $host);
    if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'], true)) {
        return true;
    }
    if (preg_match('#^(127\.|10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)#', $host)) {
        return true;
    }
    $site = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
    if ($site !== '' && $host === $site) {
        return true;
    }
    return $host !== '' && strpos($host, '.') === false;   // 单段主机名（如 intranet）也算本站
}

/**
 * 从一段文本里抽出域名（兼容带协议、带路径、带 www 的写法）
 */
function tpi_inquiry_domain_of(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }
    return strtolower((string) parse_url($value, PHP_URL_HOST));
}

/**
 * 询盘来源：把 referer_domain 解析成「能看懂的来源域名」
 *
 * 库里可能存三种形态，统一在这里收口：
 *
 *   1. 外部来源 https://www.google.com/          → 直接用它自己的域名
 *   2. 本站地址（本地预览域名也算）              → 换成站点正式域名 + 原路径
 *   3. 空（老版本主题没记录）                     → 按「直接访问」算，用站点正式域名
 *
 * 链接自带 utm_source / ref 之类参数时以它为准（?utm_source=chatgpt.com → chatgpt.com），
 * 那是真正的渠道，比 referer 更准。
 *
 * @param string|null $referer referer_domain 原值
 * @return array{host:string,url:string,local:bool}
 */
function tpi_inquiry_source_info(?string $referer): array {
    $referer = trim((string) $referer);
    $site    = tpi_inquiry_site_origin();

    $probe = $referer;
    if ($probe !== '' && !preg_match('#^https?://#i', $probe)) {
        $probe = 'https://' . ltrim($probe, '/');
    }

    /* 1. 链接参数里的渠道域名优先 */
    if ($probe !== '') {
        $query = (string) parse_url($probe, PHP_URL_QUERY);
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['utm_source', 'utm_medium', 'ref', 'source'] as $key) {
                if (empty($params[$key]) || !is_string($params[$key])) {
                    continue;
                }
                $chan = tpi_inquiry_domain_of($params[$key]);
                if ($chan !== '' && strpos($chan, '.') !== false && !tpi_inquiry_host_is_local($chan)) {
                    return ['host' => $chan, 'url' => $probe, 'local' => false];
                }
            }
        }
    }

    /* 2. 没记录 / 记的是本站：按直接访问处理，用站点正式域名 */
    $host = $probe !== '' ? (string) parse_url($probe, PHP_URL_HOST) : '';
    if ($host === '' || tpi_inquiry_host_is_local($host)) {
        $tail = '';
        if ($probe !== '') {
            $tail = (string) parse_url($probe, PHP_URL_PATH);
            $q    = (string) parse_url($probe, PHP_URL_QUERY);
            if ($q !== '') {
                $tail .= '?' . $q;
            }
            if ($tail === '/') {
                $tail = '';
            }
        }
        return ['host' => $site['host'], 'url' => $site['url'] . $tail, 'local' => true];
    }

    /* 3. 真正的外部来源 */
    return ['host' => $host, 'url' => $probe, 'local' => false];
}

/**
 * 询盘列表页：一条询盘一个卡片行
 * @return void
 */
function display_inquiries_page(): void {
    global $wpdb;
    $table_name = tpi_inquiry_table();
    $per_page   = 10;

    /* ---------- 语言筛选 ---------- */
    $lang_filter = isset($_GET['lang']) ? strtolower(sanitize_text_field(wp_unslash($_GET['lang']))) : '';
    $lang_slugs  = function_exists('tpi_language_slugs') ? tpi_language_slugs() : [];
    if ($lang_filter !== '' && !in_array($lang_filter, $lang_slugs, true)) {
        $lang_filter = '';
    }
    // 语言代码存在 lang 列：空值的老数据归到「未记录」，用 all 才看得到
    $where = '';
    if ($lang_filter !== '') {
        $where = $wpdb->prepare(' WHERE lang = %s', $lang_filter);
    }

    $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name" . $where);
    $total_pages = max(1, (int) ceil($total_items / $per_page));

    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    if ($paged > $total_pages) {
        $paged = $total_pages;
    }
    $offset = ($paged - 1) * $per_page;

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name" . $where . " ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    /**
     * 生成分页地址（保留语言筛选）
     */
    $page_link = static function (int $page) use ($lang_filter): string {
        $args = array('page' => 'inquiries', 'paged' => $page);
        if ($lang_filter !== '') {
            $args['lang'] = $lang_filter;
        }
        return add_query_arg($args, admin_url('admin.php'));
    };

    /**
     * 去掉首尾空白（含不间断空格、零宽字符）
     */
    $clean = static function ($value): string {
        $value = (string) $value;
        $trimmed = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $value);
        if ($trimmed === null) {
            $trimmed = trim($value);
        }
        return $trimmed;
    };

    /**
     * 空值占位
     */
    $val = static function ($value) use ($clean): string {
        $value = $clean($value);
        return $value === '' ? '<span class="inquiry-empty">—</span>' : esc_html($value);
    };

    echo '<div class="inquiry-wrap manage-wrap-box">';

    /* ---------- 标题栏 ---------- */
    echo '<div class="inquiry-head">';
    echo '<div class="inquiry-head-main">';
    echo '<h1>' . esc_html(tpi_admin_ui('inquiry.page.title')) . '</h1>';
    echo '<span class="inquiry-count">' . sprintf(tpi_admin_ui('inquiry.page.total'), esc_html(number_format_i18n($total_items))) . '</span>';
    echo '</div>';
    echo '<span class="inquiry-page-ind">' . sprintf(tpi_admin_ui('inquiry.page.position'), $paged, $total_pages) . '</span>';
    echo '</div>';

    /* ---------- 语言筛选 ---------- */
    if (function_exists('tpi_admin_lang_tabs') && count($lang_slugs) > 1) {
        echo '<div class="inquiry-langfilter">';
        echo '<span class="inquiry-langfilter-label">' . esc_html(tpi_admin_ui('inquiry.filter.label')) . '</span>';
        tpi_admin_lang_tabs('inquiries', $lang_filter, true);
        echo '</div>';
    }

    /* ---------- 列表 ---------- */
    echo '<div class="inquiry-table-shell">';
    echo '<table class="inquiry-wrap-table">';
    echo '<colgroup>
            <col class="col-id">
            <col class="col-customer">
            <col class="col-contact">
            <col class="col-message">
            <col class="col-source">
            <col class="col-time">
          </colgroup>';
    echo '<thead><tr>
            <th class="col-id">ID</th>
            <th class="col-customer">' . esc_html(tpi_admin_ui('inquiry.col.customer')) . '</th>
            <th class="col-contact">' . esc_html(tpi_admin_ui('inquiry.col.contact')) . '</th>
            <th class="col-message">' . esc_html(tpi_admin_ui('inquiry.col.message')) . '</th>
            <th class="col-source">' . esc_html(tpi_admin_ui('inquiry.col.source')) . '</th>
            <th class="col-time">' . esc_html(tpi_admin_ui('inquiry.col.time')) . '</th>
          </tr></thead><tbody>';

    if ($results) {
        foreach ($results as $row) {
            $message = $clean($row->message);
            $remark  = $clean($row->remark);
            $email   = $clean($row->email);
            $phone   = $clean($row->telephone);
            $lang    = $clean($row->lang);
            $type    = $clean($row->origin_type);
            $utm     = $clean($row->utm_term);

            /* 来源域名：外部来源用它自己的域名；本站地址 / 空记录按「直接访问」，用站点正式域名 */
            $source       = tpi_inquiry_source_info($row->referer_domain);
            $referer_host = $source['host'];
            $referer_full = $source['url'];

            $message_len = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
            $is_long     = $message_len > 90 || substr_count($message, "\n") > 1;

            echo '<tr class="inquiry-row">';

            /* ID */
            echo '<td class="col-id"><span class="inquiry-id">' . esc_html($row->id) . '</span></td>';

            /* 客户 */
            echo '<td class="col-customer">';
            echo '<div class="inquiry-name-row">';
            echo '<span class="inquiry-name">' . $val($row->name) . '</span>';
            if ($lang !== '') {
                echo '<span class="inquiry-lang" title="' . esc_attr(tpi_admin_ui('inquiry.row.lang-title')) . '">' . esc_html($lang) . '</span>';
            }
            echo '</div>';
            echo '</td>';

            /* 联系方式：纯文本可拖选，悬停出复制按钮 */
            echo '<td class="col-contact"><div class="inquiry-contact">';
            if ($email !== '') {
                echo '<div class="inquiry-field">';
                echo '<span class="inquiry-value inquiry-email">' . esc_html($email) . '</span>';
                echo '<button type="button" class="inquiry-copy" data-copy="' . esc_attr($email) . '" title="' . esc_attr(tpi_admin_ui('inquiry.row.copy-email')) . '" aria-label="' . esc_attr(tpi_admin_ui('inquiry.row.copy-email')) . '"><span class="dashicons dashicons-admin-page"></span></button>';
                echo '</div>';
            } else {
                echo '<span class="inquiry-empty">—</span>';
            }
            if ($phone !== '') {
                echo '<div class="inquiry-field">';
                echo '<span class="inquiry-value inquiry-telephone">' . esc_html($phone) . '</span>';
                echo '<button type="button" class="inquiry-copy" data-copy="' . esc_attr($phone) . '" title="' . esc_attr(tpi_admin_ui('inquiry.row.copy-phone')) . '" aria-label="' . esc_attr(tpi_admin_ui('inquiry.row.copy-phone')) . '"><span class="dashicons dashicons-admin-page"></span></button>';
                echo '</div>';
            }
            echo '</div></td>';

            /* 询盘内容 */
            echo '<td class="col-message">';
            echo '<p class="inquiry-message-text">' . ($message === '' ? '<span class="inquiry-empty">—</span>' : nl2br(esc_html($message))) . '</p>';
            if ($is_long) {
                // 文案放 data 里：展开后要换成「收起」，脚本不能写死中文
                echo '<button type="button" class="inquiry-toggle"'
                    . ' data-label-open="' . esc_attr(tpi_admin_ui('inquiry.row.show-full')) . '"'
                    . ' data-label-close="' . esc_attr(tpi_admin_ui('inquiry.row.show-less')) . '">'
                    . esc_html(tpi_admin_ui('inquiry.row.show-full')) . '</button>';
            }
            echo '</td>';

            /* 来源信息 */
            echo '<td class="col-source"><div class="inquiry-source">';
            if ($referer_host !== '') {
                echo '<a class="inquiry-chip inquiry-referer-domain" href="' . esc_url($referer_full) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($referer_full) . '">' . esc_html($referer_host) . '</a>';
            }
            if ($type !== '') {
                echo '<span class="inquiry-chip inquiry-origin-type" title="' . esc_attr(tpi_admin_ui('inquiry.row.type-title')) . '">' . esc_html($type) . '</span>';
            }
            if ($utm !== '') {
                echo '<span class="inquiry-chip inquiry-utm_term" title="' . esc_attr(tpi_admin_ui('inquiry.row.keyword-title')) . '">' . esc_html($utm) . '</span>';
            }
            if ($remark !== '') {
                echo '<span class="inquiry-chip inquiry-remark" title="' . esc_attr($remark) . '">' . esc_html($remark) . '</span>';
            }
            if ($referer_host === '' && $type === '' && $utm === '' && $remark === '') {
                echo '<span class="inquiry-empty">—</span>';
            }
            echo '</div></td>';

            /* 时间 */
            $created = (string) $row->created_at;
            $date    = $created !== '' ? substr($created, 0, 10) : '';
            $clock   = $created !== '' ? substr($created, 11, 8) : '';
            echo '<td class="col-time"><time class="inquiry-created-at" datetime="' . esc_attr($created) . '">';
            echo '<span class="d">' . esc_html($date) . '</span>';
            echo '<span class="t">' . esc_html($clock) . '</span>';
            echo '</time></td>';

            echo '</tr>';
        }
    } else {
        echo '<tr class="inquiry-empty-row"><td colspan="6">' . esc_html(tpi_admin_ui('inquiry.row.empty')) . '</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    /* ---------- 分页 ---------- */
    $window = 2;
    $start  = max(1, $paged - $window);
    $end    = min($total_pages, $paged + $window);

    echo '<nav class="inquiry-pagination">';
    echo '<span class="inquiry-page-sum">' . sprintf(tpi_admin_ui('inquiry.page.summary'), esc_html(number_format_i18n($total_items)), $per_page) . '</span>';
    echo '<div class="inquiry-page-ctrl">';

    echo '<a class="inquiry-page-btn' . ($paged <= 1 ? ' is-disabled' : '') . '" href="' . esc_url($page_link(max(1, $paged - 1))) . '">' . esc_html(tpi_admin_ui('inquiry.pager.prev')) . '</a>';

    if ($start > 1) {
        echo '<a class="inquiry-page-btn" href="' . esc_url($page_link(1)) . '">1</a>';
        if ($start > 2) {
            echo '<span class="inquiry-page-gap">…</span>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i === $paged) {
            echo '<span class="inquiry-page-btn is-current">' . $i . '</span>';
        } else {
            echo '<a class="inquiry-page-btn" href="' . esc_url($page_link($i)) . '">' . $i . '</a>';
        }
    }

    if ($end < $total_pages) {
        if ($end < $total_pages - 1) {
            echo '<span class="inquiry-page-gap">…</span>';
        }
        echo '<a class="inquiry-page-btn" href="' . esc_url($page_link($total_pages)) . '">' . $total_pages . '</a>';
    }

    echo '<a class="inquiry-page-btn' . ($paged >= $total_pages ? ' is-disabled' : '') . '" href="' . esc_url($page_link(min($total_pages, $paged + 1))) . '">' . esc_html(tpi_admin_ui('inquiry.pager.next')) . '</a>';

    echo '</div></nav>';

    echo '</div>';
}
