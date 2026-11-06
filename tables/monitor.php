<?php
/**
 * 监控管理（Osiera → 监控管理）
 *
 * 维护前台统计 / 转化账号与事件发送，配置读取在 inc/admin/monitor.php，
 * 前台脚本读 window.tpiMonitor（由 inc/output/push-js.php 注入）。
 *
 * 页面四块：
 *   1. 开关    本站启用 / Cookie 授权 / 本站 SEO（没有总开关，统计脚本由下面两个启用开关决定）
 *   2. Clarity 启用 + 项目 ID（只录屏，不参与 gtag、不发事件）
 *   3. Google  启用 + gtag 配置列表（每行一个 ID，逐个 gtag('config', id)）
 *              —— GA4 记录与 Ads 账号是同一份配置，不分类、不填转化标签
 *   4. 事件发送 触发动作 + 流量来源 + 类型（GA4 记录 / Ads 转化）+ 事件名或发送目标
 *
 * 行内控件带 name="google[gtag][0]" 与 data-name="google[gtag][%d]"，
 * %d 由页面脚本按当前顺序重排，删掉中间一行不会留下空洞，也不会错位。
 */

// 菜单注册在 inc/admin/admin-menu.php（Osiera → 监控管理），这里只管页面

if (!function_exists('tpi_monitor_field')) {
    /**
     * 一行里的一格：小标题 + 控件
     *
     * @param string $cap   小标题
     * @param string $col   宽度用的类名（mt-col-*）
     * @param string $inner 控件 HTML
     * @param bool   $label 用 <label> 包住（点标题也能聚焦控件）
     * @param array  $attrs 额外属性（例如 ['hidden' => true]）
     */
    function tpi_monitor_field(string $cap, string $col, string $inner, bool $label = true, array $attrs = []): void
    {
        $tag   = $label ? 'label' : 'div';
        $extra = '';
        foreach ($attrs as $key => $value) {
            $extra .= $value === true
                ? ' ' . $key
                : ' ' . $key . '="' . esc_attr((string) $value) . '"';
        }
        printf(
            '<%1$s class="mt-field %2$s"%5$s><span class="mt-cap">%3$s</span>%4$s</%1$s>',
            $tag,
            esc_attr($col),
            esc_html($cap),
            $inner,
            $extra
        );
    }
}

if (!function_exists('tpi_monitor_toggle')) {
    /**
     * 一个开关项（开关 + 标题 + 说明）
     *
     * @param string $name  表单字段名
     * @param bool   $on    是否打开
     * @param string $title 标题
     * @param string $desc  说明
     */
    function tpi_monitor_toggle(string $name, bool $on, string $title, string $desc): void
    {
        ?>
        <label class="mt-toggle<?php echo $on ? ' is-on' : ''; ?>">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($on); ?>>
            <span class="mt-tgl-track"><span class="mt-tgl-dot"></span></span>
            <span class="mt-toggle-text">
                <b><?php echo esc_html($title); ?></b>
                <small><?php echo esc_html($desc); ?></small>
            </span>
        </label>
        <?php
    }
}

if (!function_exists('tpi_monitor_gtag_row')) {
    /**
     * 一行 gtag 配置：一个 ID（G-… 记 GA4，AW-… 记 Ads 转化）
     *
     * @param string $id  配置 ID
     * @param string $idx 真实下标；template 里传 %d，交给页面脚本按顺序重排
     */
    function tpi_monitor_gtag_row(string $id, string $idx = '%d'): void
    {
        $input = '<input type="text" name="google[gtag][' . $idx . ']" data-name="google[gtag][%d]"'
            . ' value="' . esc_attr($id) . '"'
            . ' placeholder="G-XXXXXXXXXX / AW-XXXXXXXXX / GT-XXXXXXXX / GTM-XXXXXXX"'
            . ' spellcheck="false" autocomplete="off">';
        ?>
        <div class="mt-row mt-row-gtag" data-mt-row>
            <?php
            tpi_monitor_field(tpi_admin_ui('monitor.field.gtag-id'), 'mt-col-gtag', $input);
            ?>
            <button type="button" class="mt-del" data-mt-del
                    title="<?php echo esc_attr(tpi_admin_ui('monitor.button.delete')); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
        <?php
    }
}

if (!function_exists('tpi_monitor_event_row')) {
    /**
     * 一行事件发送
     *
     * @param array  $row ['t','s','k','v']，k=ads 时 v 是 Google Ads 的 send_to
     * @param string $idx 真实下标；template 里传 %d
     */
    function tpi_monitor_event_row(array $row, string $idx = '%d'): void
    {
        $trigger = (string) ($row['t'] ?? 'inquiry');
        $source  = (string) ($row['s'] ?? '');
        $kind    = (string) ($row['k'] ?? 'ga4');
        $value   = (string) ($row['v'] ?? '');

        $triggers = tpi_monitor_triggers();
        $sources  = tpi_monitor_sources();
        $kinds    = tpi_monitor_kinds();

        if (!isset($triggers[$trigger])) {
            $trigger = 'inquiry';
        }
        if (!array_key_exists($source, $sources)) {
            $source = '';
        }
        if (!isset($kinds[$kind])) {
            $kind = 'ga4';
        }

        // name 里先写 %IDX%（随后替换成真实下标），data-name 保留 %d 交给页面脚本重排
        $select = static function (string $field, string $current, array $options, string $attr = ''): string {
            $html = '<select name="events[%IDX%][' . $field . ']" data-name="events[%d][' . $field . ']"' . $attr . '>';
            foreach ($options as $key => $label) {
                $html .= '<option value="' . esc_attr((string) $key) . '"'
                    . selected($current, (string) $key, false) . '>'
                    . esc_html((string) $label) . '</option>';
            }
            return $html . '</select>';
        };

        // GA4 记录：发事件名
        $event = '<input type="text" name="events[%IDX%][v]" data-name="events[%d][v]"'
            . ' value="' . esc_attr($kind === 'ga4' ? $value : '') . '"'
            . ' placeholder="generate_lead" spellcheck="false" autocomplete="off">';

        // Ads 转化：手填 send_to（Google Ads 的转化 ID/转化标签），前台原样发给 gtag
        $target = '<input type="text" name="events[%IDX%][to]" data-name="events[%d][to]"'
            . ' value="' . esc_attr($kind === 'ads' ? $value : '') . '"'
            . ' placeholder="AW-XXXXXXXXXX/XXXXXXXXXX"'
            . ' spellcheck="false" autocomplete="off">';

        ob_start();
        ?>
        <div class="mt-row mt-row-event" data-mt-row>
            <?php
            tpi_monitor_field(tpi_admin_ui('monitor.field.trigger'), 'mt-col-trigger', $select('t', $trigger, $triggers));
            tpi_monitor_field(tpi_admin_ui('monitor.field.source'), 'mt-col-source', $select('s', $source, $sources));
            tpi_monitor_field(
                tpi_admin_ui('monitor.field.kind'),
                'mt-col-kind',
                $select('k', $kind, $kinds, ' data-mt-kind')
            );
            tpi_monitor_field(
                tpi_admin_ui('monitor.field.event'),
                'mt-col-value',
                $event,
                true,
                $kind === 'ga4' ? [] : ['hidden' => true]
            );
            tpi_monitor_field(
                tpi_admin_ui('monitor.field.target'),
                'mt-col-value',
                $target,
                true,
                $kind === 'ads' ? [] : ['hidden' => true]
            );
            ?>
            <button type="button" class="mt-del" data-mt-del
                    title="<?php echo esc_attr(tpi_admin_ui('monitor.button.delete')); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>
        <?php
        echo str_replace('%IDX%', $idx, (string) ob_get_clean());
    }
}

if (!function_exists('display_monitor_page')) {
    function display_monitor_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 保存
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['monitor_save'])) {
            if (isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'save_monitor')) {
                // 直接传原始数组，清洗交给 tpi_monitor_save()
                tpi_monitor_save([
                    'site'    => $_POST['site'] ?? '',
                    'consent' => $_POST['consent'] ?? '',
                    'seo'     => $_POST['seo'] ?? '',
                    'clarity' => wp_unslash($_POST['clarity'] ?? []),
                    'google'  => wp_unslash($_POST['google'] ?? []),
                    'events'  => wp_unslash($_POST['events'] ?? []),
                ]);
                echo '<div class="notice notice-success"><p>'
                    . esc_html(tpi_admin_ui('monitor.notice.saved'))
                    . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>'
                    . esc_html(tpi_admin_ui('monitor.notice.expired'))
                    . '</p></div>';
            }
        }

        $cfg        = tpi_monitor();
        $gtag       = $cfg['google']['gtag'];
        $events     = $cfg['events'];
        $site_on    = $cfg['site'] === '1';
        $consent_on = $cfg['consent'] === '1';
        $seo_on     = $cfg['seo'] === '1';
        $clarity_on = $cfg['clarity']['on'] === '1';
        $google_on  = $cfg['google']['on'] === '1';

        // 角标里的「账号数」= 配置里实际会加载的脚本数（Clarity + 每条 gtag 配置），
        // 按后台配置算，不受当前请求是否走 CDN 影响。
        $loaded = ($clarity_on && $cfg['clarity']['id'] !== '' ? 1 : 0)
            + ($google_on ? count($gtag) : 0);

        ?>
        <div class="ad-wrap">
            <div class="ad-head">
                <h1><?php echo esc_html(tpi_admin_ui('monitor.page.title')); ?></h1>
                <p><?php echo esc_html(tpi_admin_ui('monitor.page.hint')); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('save_monitor'); ?>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('monitor.card.switch-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('monitor.card.switch-hint')); ?></p>
                        </div>
                        <div class="ad-card-actions">
                            <span class="ad-badge<?php echo $loaded > 0 ? '' : ' is-empty'; ?>">
                                <?php echo esc_html($loaded > 0
                                    ? sprintf(tpi_admin_ui('monitor.badge.on'), $loaded)
                                    : tpi_admin_ui('monitor.badge.off')); ?>
                            </span>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="mt-toggles">
                            <?php
                            tpi_monitor_toggle(
                                'site',
                                $site_on,
                                tpi_admin_ui('monitor.field.site'),
                                tpi_admin_ui('monitor.hint.site')
                            );
                            tpi_monitor_toggle(
                                'consent',
                                $consent_on,
                                tpi_admin_ui('monitor.field.consent'),
                                tpi_admin_ui('monitor.hint.consent')
                            );
                            tpi_monitor_toggle(
                                'seo',
                                $seo_on,
                                tpi_admin_ui('monitor.field.seo'),
                                tpi_admin_ui('monitor.hint.seo')
                            );
                            ?>
                        </div>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('monitor.card.clarity-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('monitor.card.clarity-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="mt-toggles">
                            <?php
                            tpi_monitor_toggle(
                                'clarity[on]',
                                $clarity_on,
                                tpi_admin_ui('monitor.field.clarity-on'),
                                tpi_admin_ui('monitor.hint.clarity-on')
                            );
                            ?>
                        </div>
                        <div class="mt-rows">
                            <div class="mt-row mt-row-single">
                                <?php
                                tpi_monitor_field(
                                    tpi_admin_ui('monitor.field.clarity-id'),
                                    'mt-col-id',
                                    '<input type="text" name="clarity[id]"'
                                    . ' value="' . esc_attr($cfg['clarity']['id']) . '"'
                                    . ' placeholder="abcdefghij" spellcheck="false" autocomplete="off">'
                                );
                                ?>
                            </div>
                        </div>
                        <p class="ad-hint"><?php echo esc_html(tpi_admin_ui('monitor.hint.clarity-id')); ?></p>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('monitor.card.google-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('monitor.card.google-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="mt-toggles">
                            <?php
                            tpi_monitor_toggle(
                                'google[on]',
                                $google_on,
                                tpi_admin_ui('monitor.field.google-on'),
                                tpi_admin_ui('monitor.hint.google-on')
                            );
                            ?>
                        </div>

                        <div class="mt-sub-head">
                            <h3><?php echo esc_html(tpi_admin_ui('monitor.sub.gtag')); ?></h3>
                        </div>

                        <p class="ad-hint"><?php echo esc_html(tpi_admin_ui('monitor.hint.gtag')); ?></p>
                        <code class="mt-code">gtag('config', 'G-XXXXXXXXXX');</code>

                        <div class="mt-rows" data-mt-gtag>
                            <?php
                            if ($gtag) {
                                foreach ($gtag as $i => $id) {
                                    tpi_monitor_gtag_row((string) $id, (string) $i);
                                }
                            }
                            ?>
                        </div>

                        <div class="mt-empty" data-mt-empty<?php echo $gtag ? ' hidden' : ''; ?>>
                            <?php echo esc_html(tpi_admin_ui('monitor.gtag-empty')); ?>
                        </div>

                        <div class="mt-actions">
                            <button type="button" class="button" data-mt-add="gtag">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php echo esc_html(tpi_admin_ui('monitor.button.add-gtag')); ?>
                            </button>
                        </div>
                    </div>
                </section>

                <section class="ad-card">
                    <header class="ad-card-head">
                        <div>
                            <h2><?php echo esc_html(tpi_admin_ui('monitor.card.event-title')); ?></h2>
                            <p><?php echo esc_html(tpi_admin_ui('monitor.card.event-hint')); ?></p>
                        </div>
                    </header>
                    <div class="ad-card-body">
                        <div class="mt-rows" data-mt-events>
                            <?php
                            if ($events) {
                                foreach ($events as $i => $row) {
                                    tpi_monitor_event_row((array) $row, (string) $i);
                                }
                            }
                            ?>
                        </div>

                        <div class="mt-empty" data-mt-empty<?php echo $events ? ' hidden' : ''; ?>>
                            <?php echo esc_html(tpi_admin_ui('monitor.event-empty')); ?>
                        </div>

                        <div class="mt-actions">
                            <button type="button" class="button" data-mt-add="event">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php echo esc_html(tpi_admin_ui('monitor.button.add-event')); ?>
                            </button>
                            <span class="ad-hint"><?php echo esc_html(tpi_admin_ui('monitor.hint.event')); ?></span>
                        </div>
                    </div>
                </section>

                <div class="ad-savebar">
                    <span class="ad-savebar-hint"><?php echo esc_html(tpi_admin_ui('monitor.hint.savebar')); ?></span>
                    <button type="submit" name="monitor_save" class="button button-primary button-large">
                        <?php echo esc_html(tpi_admin_ui('monitor.button.save')); ?>
                    </button>
                </div>
            </form>
        </div>

        <template data-mt-template="gtag">
            <?php tpi_monitor_gtag_row(''); ?>
        </template>
        <template data-mt-template="event">
            <?php tpi_monitor_event_row(['t' => 'inquiry', 's' => '', 'k' => 'ga4', 'v' => '']); ?>
        </template>

        <script>
        (function () {
            const boxes = {
                gtag:  document.querySelector('[data-mt-gtag]'),
                event: document.querySelector('[data-mt-events]'),
            };

            // 按当前顺序重排 name，删掉中间一行也不会错位
            function renumber(kind) {
                const box = boxes[kind];
                if (!box) { return; }
                box.querySelectorAll('[data-mt-row]').forEach(function (row, i) {
                    row.querySelectorAll('[data-name]').forEach(function (el) {
                        el.name = el.getAttribute('data-name').replace('%d', i);
                    });
                });
                const empty = box.parentNode.querySelector('[data-mt-empty]');
                if (empty) { empty.hidden = box.querySelectorAll('[data-mt-row]').length > 0; }
            }

            /* 事件行的「类型」决定后面那一格：GA4 记录发事件名，Ads 转化发 send_to。
               两格都常驻 DOM，只切 hidden —— 切走的那一格值留着，切回来还在。 */
            function applyKind(row) {
                const sel = row.querySelector('[data-mt-kind]');
                if (!sel) { return; }
                const ads = sel.value === 'ads';
                const fields = row.querySelectorAll('.mt-col-value');
                if (fields[0]) { fields[0].hidden = ads; }
                if (fields[1]) { fields[1].hidden = !ads; }
            }

            document.addEventListener('click', function (e) {
                const add = e.target.closest('[data-mt-add]');
                if (add) {
                    const kind = add.getAttribute('data-mt-add');
                    const tpl = document.querySelector('[data-mt-template="' + kind + '"]');
                    if (!tpl || !boxes[kind]) { return; }
                    boxes[kind].appendChild(tpl.content.firstElementChild.cloneNode(true));
                    renumber(kind);
                    const rows = boxes[kind].querySelectorAll('[data-mt-row]');
                    const last = rows[rows.length - 1];
                    if (last) {
                        applyKind(last);
                        const input = last.querySelector('input[type="text"]');
                        if (input) { input.focus(); }
                    }
                    return;
                }

                const del = e.target.closest('[data-mt-del]');
                if (del) {
                    const row = del.closest('[data-mt-row]');
                    const box = row && row.parentNode;
                    if (!row || !box) { return; }
                    row.remove();
                    renumber(box.hasAttribute('data-mt-gtag') ? 'gtag' : 'event');
                }
            });

            document.addEventListener('change', function (e) {
                const kind = e.target.closest('[data-mt-kind]');
                if (kind) { applyKind(kind.closest('[data-mt-row]')); }
            });

            renumber('gtag');
            renumber('event');
            document.querySelectorAll('[data-mt-events] [data-mt-row]').forEach(applyKind);
        })();
        </script>
        <?php
    }
}
