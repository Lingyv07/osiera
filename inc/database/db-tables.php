<?php
/**
 * 业务数据表：产品模板 + 询盘记录
 *
 * 语言相关的两张表在 inc/multilang/lang-store.php，这里补上业务表，
 * 统一由 tpi_db_install() 汇总调用，所以「启用主题」和「每次 init 自检」都会覆盖到。
 *
 * 设计取舍：
 *   - 只建「不存在」的表，已存在的表一律不碰。dbDelta 虽然幂等，但它会对结构差异
 *     发 ALTER，老站（主站 product_template 是 MyISAM、字段顺序也不同）没必要冒这个险。
 *   - 以后真要改字段，用 OSIERA_DB_VERSION 升级 + 显式 ALTER 写迁移，别指望 dbDelta 猜。
 *
 * @package Osiera
 */

if (!function_exists('tpi_product_template_table')) {
    function tpi_product_template_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'product_template';
    }
}

if (!function_exists('tpi_inquiry_table_name')) {
    /** 询盘表名（tpi_inquiry_table() 在 tables/inquiry.php，后台才加载，这里做个兜底） */
    function tpi_inquiry_table_name(): string
    {
        if (function_exists('tpi_inquiry_table')) {
            return tpi_inquiry_table();
        }
        global $wpdb;
        return $wpdb->prefix . 'inquiries';
    }
}

if (!function_exists('tpi_db_extra_tables')) {
    /** 业务表清单（顺序即建表顺序，只放表名） */
    function tpi_db_extra_tables(): array
    {
        return [
            tpi_product_template_table(),
            tpi_inquiry_table_name(),
        ];
    }
}

if (!function_exists('tpi_db_extra_tables_ready')) {
    /**
     * 业务表是否都在（缺一张就返回 false，用于 init 时决定要不要补建）
     *
     * 用一次 SHOW TABLES 拿全量比对，比逐表 LIKE 少查几次——这个函数每个请求都要跑。
     */
    function tpi_db_extra_tables_ready(): bool
    {
        global $wpdb;
        $have = $wpdb->get_col('SHOW TABLES');
        if (!is_array($have)) {
            return false;
        }
        return !array_diff(tpi_db_extra_tables(), $have);
    }
}

if (!function_exists('tpi_db_extra_definitions')) {
    /**
     * 建表语句
     *
     * dbDelta 的硬性要求：字段名小写、类型小写、每行一个字段、PRIMARY KEY 后两个空格。
     * 写错不会报错，只会静默少建一列，所以这里和线上实际结构逐个对齐。
     */
    function tpi_db_extra_definitions(): array
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $product_template = tpi_product_template_table();
        $inquiry          = tpi_inquiry_table_name();

        return [
            $product_template => "CREATE TABLE $product_template (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                template_name varchar(255) NOT NULL,
                images text NULL,
                description text NULL,
                warning text NULL,
                parameters text NULL,
                content longtext NULL,
                main_title text NULL,
                summary text NULL,
                sort int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id)
            ) $charset_collate;",

            $inquiry => "CREATE TABLE $inquiry (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                email varchar(100) NOT NULL,
                telephone varchar(50) DEFAULT '',
                message mediumtext NOT NULL,
                remark mediumtext NOT NULL,
                lang varchar(45) DEFAULT '',
                ip varchar(45) DEFAULT '',
                token varchar(128) DEFAULT '',
                origin_type varchar(45) DEFAULT '',
                utm_term varchar(45) DEFAULT '',
                referer_domain varchar(128) DEFAULT '',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id)
            ) $charset_collate;",
        ];
    }
}

if (!function_exists('tpi_db_extra_install')) {
    /**
     * 补齐缺失的业务表
     *
     * @return string[] 本次真正新建的表名
     */
    function tpi_db_extra_install(): array
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $created = [];
        foreach (tpi_db_extra_definitions() as $table => $sql) {
            // 必须 refresh：同一请求里刚删过表时，static 缓存还留着「存在」的旧结果
            if (tpi_table_exists($table, true)) {
                continue; // 老表不碰
            }
            dbDelta($sql);
            if (tpi_table_exists($table, true)) {
                // dbDelta 不指定引擎，统一 InnoDB（事务、崩溃恢复、行锁）
                $wpdb->query("ALTER TABLE `$table` ENGINE=InnoDB");
                $created[] = $table;
            }
        }
        return $created;
    }
}
