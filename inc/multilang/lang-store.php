<?php
/**
 * 统一存储层：语言表 + 语言配置表
 *
 * 两张表，一套语义，不再把数据散在各个 option 里：
 *
 *   1. {prefix}osiera_languages      一种语言一行
 *        code / name / locale / 国旗 / is_default / status / position
 *
 *   2. {prefix}osiera_language_data  一条配置一行（lang_id = 0 表示全站默认）
 *        scope      string  = 前台语言包（data-init/data/lang-content.json 的结构）
 *                   setting = 站点配置（page-info / smtp / indexnow / assets…）
 *        group_key  scope=string 时是分组名（home / footer / product…）
 *                   scope=setting 时是配置组名（page-info / smtp / indexnow…）
 *        item_key   组内路径（our-products-desc / about-us.summary-info-items）
 *        value      标量原样存；数组（列表）存 JSON
 *
 * 语言包读取顺序：系统模板 → 该语言 → 默认语言兜底，缺字段永远不会报错。
 * 站点配置读取顺序：全站默认(lang_id=0) → 该语言覆盖 → 代码内置默认值。
 *
 * @package Osiera
 */

if (!defined('OSIERA_DB_VERSION')) {
    /**
     * 数据库结构版本号
     *
     *   4 = 语言两表（osiera_languages / osiera_language_data）
     *   5 = 业务两表（product_template / inquiries）改由主题统一建，
     *       老站升级到这里会把缺的表补上
     */
    define('OSIERA_DB_VERSION', '5');
}

/* =========================================================
 * 一、表定义
 * ========================================================= */

if (!function_exists('tpi_languages_table')) {
    function tpi_languages_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'osiera_languages';
    }
}

if (!function_exists('tpi_lang_data_table')) {
    function tpi_lang_data_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'osiera_language_data';
    }
}

if (!function_exists('tpi_table_exists')) {
    function tpi_table_exists(string $table, bool $refresh = false): bool
    {
        global $wpdb;
        static $cache = [];
        if (!$refresh && isset($cache[$table])) {
            return $cache[$table];
        }
        $ok = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        $cache[$table] = $ok;
        return $ok;
    }
}

if (!function_exists('tpi_languages_table_exists')) {
    function tpi_languages_table_exists(bool $refresh = false): bool
    {
        return tpi_table_exists(tpi_languages_table(), $refresh);
    }
}

if (!function_exists('tpi_db_install')) {
    /** 建表 + 迁移（幂等，版本号变了才跑） */
    function tpi_db_install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $languages = tpi_languages_table();
        $data      = tpi_lang_data_table();

        dbDelta("CREATE TABLE $languages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(12) NOT NULL DEFAULT '',
            name varchar(64) NOT NULL DEFAULT '',
            locale varchar(20) NOT NULL DEFAULT '',
            flag_id bigint(20) unsigned NOT NULL DEFAULT 0,
            flag_url varchar(255) NOT NULL DEFAULT '',
            is_default tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            position int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status_position (status,position)
        ) $charset_collate;");

        dbDelta("CREATE TABLE $data (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lang_id bigint(20) unsigned NOT NULL DEFAULT 0,
            scope varchar(10) NOT NULL DEFAULT 'string',
            group_key varchar(48) NOT NULL DEFAULT '',
            item_key varchar(150) NOT NULL DEFAULT '',
            value longtext NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY lang_scope_group_item (lang_id,scope,group_key,item_key),
            KEY group_lookup (scope,group_key)
        ) $charset_collate;");

        // dbDelta 不保证引擎，统一转 InnoDB（事务、崩溃恢复、行锁都需要它）
        foreach ([$languages, $data] as $table) {
            $wpdb->query("ALTER TABLE `$table` ENGINE=InnoDB");
        }

        // 业务表（产品模板 / 询盘）也走这一个入口，启用主题时一起建好
        if (function_exists('tpi_db_extra_install')) {
            tpi_db_extra_install();
        }

        tpi_db_migrate();
        update_option('osiera_db_version', OSIERA_DB_VERSION, false);
        tpi_lang_content_flush();
    }
}

if (!function_exists('tpi_db_migrate')) {
    /**
     * 从旧结构搬数据
     *
     *   旧表 {prefix}tpi_languages（content 一列塞整份 JSON）
     *   option my_page_info_settings[_lang] / my_smtp_settings[_lang] / my_indexnow_settings[_lang]
     *
     * 搬完把旧表删掉，option 一并清掉，避免两套数据源。
     */
    function tpi_db_migrate(): void
    {
        global $wpdb;
        $languages = tpi_languages_table();
        $data      = tpi_lang_data_table();

        // ---- 1. 语言行 ----
        $old_table = $wpdb->prefix . 'tpi_languages';
        $has_old   = tpi_table_exists($old_table, true);
        $has_rows  = ((int) $wpdb->get_var("SELECT COUNT(*) FROM $languages")) > 0;

        $content_map = []; // code => 旧 content JSON 解出来的数组
        $legacy_lang_ids = []; // 旧 id => code

        if ($has_old && !$has_rows) {
            $rows = $wpdb->get_results("SELECT * FROM `$old_table` ORDER BY position ASC, id ASC", ARRAY_A);
            $position = 0;
            foreach ((array) $rows as $row) {
                $code = strtolower(trim((string) ($row['code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                // 老数据里 locale 只写了语言代码（en），补成 en_US 这种完整标记
                $locale = tpi_locale_normalize((string) ($row['locale'] ?? ''));
                if ($locale === '' || $locale === $code) {
                    $locale = tpi_locale_default($code);
                }
                $wpdb->insert($languages, [
                    'code'       => $code,
                    'name'       => (string) ($row['name'] ?? strtoupper($code)),
                    'locale'     => $locale,
                    'flag_id'    => (int) ($row['flag_id'] ?? 0),
                    'flag_url'   => (string) ($row['flag_url'] ?? ''),
                    'is_default' => (int) ($row['is_default'] ?? 0) === 1 ? 1 : 0,
                    'status'     => (string) ($row['status'] ?? 'active') ?: 'active',
                    'position'   => $position++,
                    'created_at' => (string) ($row['updated_at'] ?? current_time('mysql')),
                    'updated_at' => (string) ($row['updated_at'] ?? current_time('mysql')),
                ]);
                $legacy_lang_ids[(int) ($row['id'] ?? 0)] = $code;
                $decoded = json_decode((string) ($row['content'] ?? ''), true);
                if (is_array($decoded) && $decoded) {
                    $content_map[$code] = $decoded;
                }
            }
        } elseif ($has_old && $has_rows) {
            // 语言行已在新表里：只要把 content 列捡过来
            $rows = $wpdb->get_results("SELECT id, code, content FROM `$old_table`", ARRAY_A);
            foreach ((array) $rows as $row) {
                $code = strtolower(trim((string) $row['code']));
                $legacy_lang_ids[(int) $row['id']] = $code;
                $decoded = json_decode((string) $row['content'], true);
                if (is_array($decoded) && $decoded) {
                    $content_map[$code] = $decoded;
                }
            }
        }

        // 没有语言时给一条英语，保证站点可用
        if (((int) $wpdb->get_var("SELECT COUNT(*) FROM $languages")) === 0) {
            $wpdb->insert($languages, [
                'code' => 'en', 'name' => 'English', 'locale' => tpi_locale_default('en'),
                'is_default' => 1, 'status' => 'active', 'position' => 0,
                'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
            ]);
        }

        $id_by_code = [];
        foreach ($wpdb->get_results("SELECT id, code FROM $languages", ARRAY_A) as $row) {
            $id_by_code[strtolower((string) $row['code'])] = (int) $row['id'];
        }

        // ---- 2. 语言包内容 ----
        if ($content_map) {
            foreach ($content_map as $code => $content) {
                $lang_id = $id_by_code[$code] ?? 0;
                if ($lang_id <= 0) {
                    continue;
                }
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $data WHERE lang_id = %d AND scope = 'string'",
                    $lang_id
                ));
                if ($existing > 0) {
                    continue;
                }
                tpi_data_write($lang_id, 'string', $content);
            }
        }

        // ---- 3. 站点配置（option → setting 行） ----
        $groups = ['page-info' => 'my_page_info_settings', 'smtp' => 'my_smtp_settings', 'indexnow' => 'my_indexnow_settings'];
        foreach ($groups as $group => $option_base) {
            $base = get_option($option_base, []);
            if (is_array($base) && $base && !tpi_setting_rows($group, 0)) {
                tpi_setting_write(0, $group, $base);
            }
            foreach ($id_by_code as $code => $lang_id) {
                $own = get_option($option_base . '_' . $code, []);
                if (is_array($own) && $own && !tpi_setting_rows($group, $lang_id)) {
                    tpi_setting_write($lang_id, $group, $own);
                }
            }
        }

        // ---- 4. 清掉旧数据源 ----
        if ($has_old) {
            $wpdb->query("DROP TABLE IF EXISTS `$old_table`");
        }
        foreach (['my_page_info_settings', 'my_smtp_settings', 'my_indexnow_settings'] as $option_base) {
            delete_option($option_base);
            foreach (array_keys($id_by_code) as $code) {
                delete_option($option_base . '_' . $code);
            }
        }
        delete_option('tpi_languages_db_version');
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'my_page_info_settings_%' OR option_name LIKE 'my_smtp_settings_%' OR option_name LIKE 'my_indexnow_settings_%'");
    }
}

/**
 * 建表 / 升级自检
 *
 * 两个条件都会触发：
 *   1. 结构版本号变了（升级主题）；
 *   2. 有表不见了（换库、误删、老站从来没建过）。
 *
 * dbDelta / CREATE TABLE 本身幂等，反复跑不会重复建、也不会清数据；
 * 已存在的业务表更是直接跳过，连 ALTER 都不发。
 */
add_action('init', function () {
    $ready = get_option('osiera_db_version') === OSIERA_DB_VERSION
        && (!function_exists('tpi_db_extra_tables_ready') || tpi_db_extra_tables_ready());
    if ($ready) {
        return;
    }

    // 建表失败时别每个请求都重试（常见原因：数据库账号没有 CREATE 权限）
    if ((int) get_option('osiera_db_install_retry') > time()) {
        return;
    }

    tpi_db_install();

    $ok = get_option('osiera_db_version') === OSIERA_DB_VERSION
        && (!function_exists('tpi_db_extra_tables_ready') || tpi_db_extra_tables_ready());
    if ($ok) {
        delete_option('osiera_db_install_retry');
    } else {
        update_option('osiera_db_install_retry', time() + HOUR_IN_SECONDS, false);
    }
}, 5);

/* =========================================================
 * 二、扁平化 <-> 嵌套
 * ========================================================= */

if (!function_exists('tpi_is_list')) {
    /** PHP 8.0 没有 array_is_list() */
    function tpi_is_list(array $arr): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($arr);
        }
        $i = 0;
        foreach ($arr as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('tpi_data_flatten')) {
    /**
     * 嵌套数组 → [路径 => 值]
     *
     * 标量原样存；列表（数字键数组）整体存 JSON，保证顺序与结构不丢。
     *
     * @param array  $node
     * @param string $prefix
     * @return array<string,string>
     */
    function tpi_data_flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                if (tpi_is_list($value)) {
                    $out[$path] = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $out += tpi_data_flatten($value, $path);
                }
                continue;
            }
            $out[$path] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        return $out;
    }
}

if (!function_exists('tpi_data_unflatten')) {
    /** [路径 => 值] → 嵌套数组 */
    function tpi_data_unflatten(array $rows): array
    {
        $out = [];
        foreach ($rows as $path => $value) {
            $segments = explode('.', (string) $path);
            $node = &$out;
            $last = count($segments) - 1;
            foreach ($segments as $i => $segment) {
                if ($i === $last) {
                    $node[$segment] = tpi_data_decode_value((string) $value);
                    break;
                }
                if (!isset($node[$segment]) || !is_array($node[$segment])) {
                    $node[$segment] = [];
                }
                $node = &$node[$segment];
            }
            unset($node);
        }
        return $out;
    }
}

if (!function_exists('tpi_data_decode_value')) {
    /** 列表值还原成数组，其它原样返回 */
    function tpi_data_decode_value(string $value)
    {
        if ($value !== '' && ($value[0] === '[' || $value[0] === '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }
}

/* =========================================================
 * 三、语言配置行的读写
 * ========================================================= */

if (!function_exists('tpi_data_rows')) {
    /**
     * 读一组配置行
     *
     * @param int    $lang_id 0 = 全站默认
     * @param string $scope   string|setting
     * @param string $group   空表示全部组
     * @return array<string,string> item_key => value
     */
    function tpi_data_rows(int $lang_id, string $scope, string $group = ''): array
    {
        global $wpdb;
        $table = tpi_lang_data_table();
        if (!tpi_table_exists($table, true)) {
            return [];
        }
        if ($group === '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT group_key, item_key, value FROM $table WHERE lang_id = %d AND scope = %s",
                $lang_id,
                $scope
            ), ARRAY_A);
            $out = [];
            foreach ((array) $rows as $row) {
                $key = $row['group_key'] . '.' . $row['item_key'];
                $out[$key] = (string) $row['value'];
            }
            return $out;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT item_key, value FROM $table WHERE lang_id = %d AND scope = %s AND group_key = %s",
            $lang_id,
            $scope,
            $group
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $out[(string) $row['item_key']] = (string) $row['value'];
        }
        return $out;
    }
}

if (!function_exists('tpi_data_write')) {
    /**
     * 覆盖写入一组配置（先清空该语言该组，再逐条写入）
     *
     * @param int    $lang_id
     * @param string $scope
     * @param array  $data    ['home' => [...], 'footer' => [...]] 或 ['host' => '...']
     * @return int 写入行数
     */
    function tpi_data_write(int $lang_id, string $scope, array $data): int
    {
        global $wpdb;
        $table = tpi_lang_data_table();
        if (!tpi_table_exists($table, true)) {
            return 0;
        }

        $flat = [];
        if ($scope === 'setting') {
            // 配置组：一层 key => 值，值可能本身是列表
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $flat[(string) $key] = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $flat[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                }
            }
        } else {
            foreach ($data as $group_key => $node) {
                if (!is_array($node)) {
                    continue;
                }
                foreach (tpi_data_flatten($node) as $path => $value) {
                    $flat[$group_key . "\x1F" . $path] = $value;
                }
            }
        }

        // 入库前去掉域名：图片存根相对路径，换域名不改数据
        foreach ($flat as $key => $value) {
            $flat[$key] = tpi_media_store_deep($value);
        }

        $wpdb->delete($table, ['lang_id' => $lang_id, 'scope' => $scope], ['%d', '%s']);

        $count = 0;
        $now = current_time('mysql');
        foreach ($flat as $key => $value) {
            if ($scope === 'setting') {
                $group = '';
                $item  = $key;
            } else {
                [$group, $item] = array_pad(explode("\x1F", (string) $key, 2), 2, '');
            }
            $ok = $wpdb->insert($table, [
                'lang_id'    => $lang_id,
                'scope'      => $scope,
                'group_key'  => (string) $group,
                'item_key'   => (string) $item,
                'value'      => (string) $value,
                'updated_at' => $now,
            ], ['%d', '%s', '%s', '%s', '%s', '%s']);
            if ($ok !== false) {
                $count++;
            }
        }
        tpi_lang_content_flush();
        return $count;
    }
}

/* =========================================================
 * 四、站点配置（page-info / smtp / indexnow / assets）
 * ========================================================= */

if (!function_exists('tpi_setting_rows')) {
    /** 某语言某配置组的原始行（不合并全站默认） */
    function tpi_setting_rows(string $group, int $lang_id): array
    {
        return tpi_data_rows($lang_id, 'setting', $group);
    }
}

if (!function_exists('tpi_setting_write')) {
    /** 覆盖写入某语言某配置组 */
    function tpi_setting_write(int $lang_id, string $group, array $data): int
    {
        global $wpdb;
        $table = tpi_lang_data_table();
        if (!tpi_table_exists($table, true)) {
            return 0;
        }
        $wpdb->delete($table, ['lang_id' => $lang_id, 'scope' => 'setting', 'group_key' => $group], ['%d', '%s', '%s']);

        $count = 0;
        $now = current_time('mysql');
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = (string) $value;
            // 空值不落库：空 = 继承上一级（全站默认 / 内置默认）
            if ($value === '') {
                continue;
            }
            // 图片地址存根相对路径，换域名不用改数据
            $value = (string) tpi_media_store_deep($value);
            $ok = $wpdb->insert($table, [
                'lang_id'    => $lang_id,
                'scope'      => 'setting',
                'group_key'  => $group,
                'item_key'   => (string) $key,
                'value'      => $value,
                'updated_at' => $now,
            ], ['%d', '%s', '%s', '%s', '%s', '%s']);
            if ($ok !== false) {
                $count++;
            }
        }
        tpi_lang_content_flush();
        return $count;
    }
}

if (!function_exists('tpi_setting_get')) {
    /**
     * 读取某语言某配置组：全站默认 → 该语言覆盖 → 内置默认
     *
     * @param string $group
     * @param string $lang    语言代码，空 = 只读全站默认
     * @param array  $defaults 代码内置默认值
     * @return array
     */
    function tpi_setting_get(string $group, string $lang = '', array $defaults = []): array
    {
        $cache_key = $group . '|' . $lang . '|' . tpi_lang_content_rev();
        $store = $GLOBALS['osiera_setting_cache'] ?? [];
        if (isset($store[$cache_key])) {
            return $store[$cache_key];
        }

        $settings = $defaults;
        // 站点配置一律按字符串读写：需要数组的字段自己 json_decode（图片列表、证书 JSON 等）
        foreach (tpi_setting_rows($group, 0) as $key => $value) {
            $settings[$key] = $value;
        }
        if ($lang !== '') {
            $lang_id = tpi_language_id($lang);
            if ($lang_id > 0) {
                foreach (tpi_setting_rows($group, $lang_id) as $key => $value) {
                    $settings[$key] = $value;
                }
            }
        }

        // 库里存的是站点根相对路径，读出来统一补成绝对地址（模板直接用，不依赖域名）
        $settings = tpi_media_full_deep($settings);

        $store[$cache_key] = $settings;
        $GLOBALS['osiera_setting_cache'] = $store;
        return $settings;
    }
}

if (!function_exists('tpi_setting_save')) {
    /** 保存某语言某配置组（空值 = 删除，回落到全站默认） */
    function tpi_setting_save(string $group, array $data, string $lang = ''): void
    {
        $lang_id = $lang === '' ? 0 : tpi_language_id($lang);
        if ($lang !== '' && $lang_id <= 0) {
            return;
        }
        tpi_setting_write($lang_id, $group, $data);
        $GLOBALS['osiera_setting_cache'] = [];
    }
}

if (!function_exists('tpi_setting_delete')) {
    /** 清空某语言某配置组 */
    function tpi_setting_delete(string $group, string $lang = ''): void
    {
        global $wpdb;
        $lang_id = $lang === '' ? 0 : tpi_language_id($lang);
        $wpdb->delete(tpi_lang_data_table(), [
            'lang_id'   => $lang_id,
            'scope'     => 'setting',
            'group_key' => $group,
        ], ['%d', '%s', '%s']);
        $GLOBALS['osiera_setting_cache'] = [];
        tpi_lang_content_flush();
    }
}

/* =========================================================
 * 五、语言行读取
 * ========================================================= */

if (!function_exists('tpi_lang_content_rev')) {
    /** 数据版本号：任何写入都变一次，用来失效进程内缓存 */
    function tpi_lang_content_rev(): string
    {
        return (string) get_option('osiera_lang_rev', '1');
    }
}

if (!function_exists('tpi_lang_content_flush')) {
    function tpi_lang_content_flush(): void
    {
        update_option('osiera_lang_rev', (string) (time() . mt_rand(100, 999)), false);
        $GLOBALS['tpi_languages_rows_cache'] = null;
        $GLOBALS['tpi_lang_content_raw'] = [];
        $GLOBALS['tpi_lang_content_merged'] = [];
        $GLOBALS['osiera_setting_cache'] = [];
        // 语言列表和默认语言也在这份缓存里：语言表刚写入时，同一次请求里
        // 之前算出来的旧列表（空表时只剩内置的 en）会一直用下去，
        // 表现是「新加的语言 set_post_language 被当成非法代码直接丢掉」
        $GLOBALS['tpi_lang_cache'] = [];
    }
}

if (!function_exists('tpi_languages_rows_all')) {
    /**
     * 语言表全部原始行（含已标记删除的），按 position 排序
     *
     * @param bool $refresh 强制重新读库
     */
    function tpi_languages_rows_all(bool $refresh = false): array
    {
        global $wpdb;
        $rev   = tpi_lang_content_rev();
        $store = $GLOBALS['tpi_languages_rows_cache'] ?? null;
        if (!$refresh && is_array($store) && ($store['rev'] ?? '') === $rev) {
            return $store['rows'];
        }

        $rows = [];
        if (tpi_languages_table_exists(true)) {
            $rows = $wpdb->get_results(
                'SELECT * FROM ' . tpi_languages_table() . ' ORDER BY position ASC, id ASC',
                ARRAY_A
            );
            $rows = is_array($rows) ? $rows : [];
        }

        $GLOBALS['tpi_languages_rows_cache'] = ['rev' => $rev, 'rows' => $rows];
        return $rows;
    }
}

if (!function_exists('tpi_languages_rows')) {
    /**
     * 可用语言行（status = deleted 的行只是被标记删除，不再参与前台/后台展示）
     */
    function tpi_languages_rows(bool $refresh = false): array
    {
        $out = [];
        foreach (tpi_languages_rows_all($refresh) as $row) {
            if (strtolower((string) ($row['status'] ?? 'active')) === 'deleted') {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('tpi_languages_deleted_rows')) {
    /** 已标记删除的语言行（后台列表里给个数量提示） */
    function tpi_languages_deleted_rows(): array
    {
        $out = [];
        foreach (tpi_languages_rows_all(true) as $row) {
            if (strtolower((string) ($row['status'] ?? 'active')) === 'deleted') {
                $out[] = $row;
            }
        }
        return $out;
    }
}

if (!function_exists('tpi_language_row')) {
    /** 一种语言的原始行 */
    function tpi_language_row(string $code): ?array
    {
        $code = strtolower(trim($code));
        foreach (tpi_languages_rows_all() as $row) {
            if (strtolower((string) $row['code']) === $code) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('tpi_language_id')) {
    /** 语言代码 → 主键（不存在返回 0） */
    function tpi_language_id(string $code): int
    {
        $row = tpi_language_row($code);
        return $row ? (int) $row['id'] : 0;
    }
}
