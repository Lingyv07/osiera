<?php
/**
 * 后台菜单：Osiera
 *
 * 把主题自己的设置页收进一个「Osiera」顶级菜单，不再一个个占满左侧：
 *
 *   Osiera
 *     ├ 初始数据
 *     ├ 邮箱设置
 *     ├ IndexNow 设置
 *     ├ 结构化设置
 *     ├ 站点与路径
 *     ├ 资源更新
 *     └ 监控管理
 *
 * 顶级菜单顺序（整站）：
 *   FAQ 管理 → 询盘记录 → 页面信息设置 → 语言 → Osiera
 *
 * 询盘记录是独立的顶级菜单，不塞进 FAQ 的子菜单 —— 折叠状态下子项看不见，
 * 找起来反而麻烦。
 *
 * 各页面的 slug 一律沿用原来的（smtp-settings / index-now-settings /
 * site-paths / assets / inquiries），所以 admin.php?page=xxx 这类
 * 旧地址、页面内的语言标签链接都不用改。
 *
 * 后台标语页已移除：提示条文案本来就在 admin-system-config-{zh|en}.json
 * 的 slogans 里，不需要单独的编辑页。
 */

add_action('admin_menu', 'tpi_register_osiera_menu', 9);

if (!function_exists('tpi_register_osiera_menu')) {
    /**
     * 注册「Osiera」顶级菜单与四个设置页
     *
     * 顶级菜单的回调直接指向邮箱设置，第一个子项与顶级共用同一个 slug ——
     * 于是点「Osiera」落在邮箱设置上，而不是停在空白的顶级页。
     */
    function tpi_register_osiera_menu(): void
    {
        $cap = 'manage_options';

        // 顶级菜单的 slug 直接用第一个子页的 slug（smtp-settings）：
        // 这样点「Osiera」落在邮箱设置上，`admin.php?page=smtp-settings`
        // 这个老地址也照样有效，不会因为换菜单层级而失效。
        add_menu_page(
            'Osiera',
            'Osiera',
            $cap,
            'smtp-settings',
            'display_smtp_page',
            'dashicons-admin-customizer',
            32
        );

        // slug => 菜单标题（顺序即显示顺序）
        // 初始数据排最前：新装站第一件事就是决定要不要导入演示数据
        $pages = [
            'data-init'          => tpi_admin_ui('data_init.menu-title'),
            'smtp-settings'      => tpi_admin_ui('smtp.menu-title'),
            'index-now-settings' => tpi_admin_ui('indexnow.menu-title'),
            'structured-data'    => tpi_admin_ui('structured.menu-title'),
            'site-paths'         => tpi_admin_ui('site_paths.menu-title'),
            'assets'             => tpi_admin_ui('assets.menu-title'),
            'monitor'            => tpi_admin_ui('monitor.menu-title'),
        ];

        $callbacks = [
            'data-init'          => 'display_data_init_page',
            'smtp-settings'      => 'display_smtp_page',
            'index-now-settings' => 'display_index_now_page',
            'structured-data'    => 'display_structured_data_page',
            'site-paths'         => 'display_site_paths_page',
            'assets'             => 'display_assets_page',
            'monitor'            => 'display_monitor_page',
        ];

        foreach ($pages as $slug => $title) {
            add_submenu_page(
                'smtp-settings',
                $title,
                $title,
                $cap,
                $slug,
                $callbacks[$slug] ?? ''
            );
        }
    }
}

add_action('admin_menu', 'tpi_register_inquiry_menu', 20);

if (!function_exists('tpi_register_inquiry_menu')) {
    /**
     * 询盘记录：紧跟「FAQ 管理」的顶级菜单
     *
     * 位置 23.5 —— FAQ 管理是 CPT 自带的 23，页面信息设置被 WP 自动挤到
     * 23.64，23.5 正好落在两者之间，于是菜单顺序是
     * 　FAQ 管理 → 询盘记录 → 页面信息设置 → 语言 → Osiera
     */
    function tpi_register_inquiry_menu(): void
    {
        $title = tpi_admin_ui('inquiry.menu-title');

        add_menu_page(
            $title,
            $title,
            'read', // 所有已登录用户都具备的最低权限
            'inquiries',
            'display_inquiries_page',
            'dashicons-email',
            23.5
        );
    }
}
