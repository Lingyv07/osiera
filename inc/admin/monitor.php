<?php
/**
 * 监控管理：统计与转化配置
 *
 * 前台埋点（Clarity / GA4 / Google Ads）原本写死在 assets/js/functional.js 里，
 * 账号一换就得改代码。这里把账号和「什么动作发什么」都做成配置，
 * 后台「Osiera → 监控管理」维护，前台只读配置，脚本里不再出现任何账号。
 *
 * option tpi_monitor 的结构（ver 5）：
 *   site     本站启用（关掉后只有 CDN 回源的请求才统计）
 *   consent  需要访客授权 Cookie（默认开启；关 = 默认授权）
 *   seo      本站 SEO（默认开启；关 = 源站 noindex + robots.txt 禁抓，CDN 照常）
 *   clarity  ['on' => '1', 'id' => 'abcdefghij']       —— 只录屏，不发事件
 *   google   ['on' => '1', 'gtag' => ['G-XXXXXXXXXX', 'AW-000000000', …]]
 *   events   [['t' => 'inquiry', 's' => '', 'k' => 'ga4', 'v' => 'generate_lead'],
 *             ['t' => 'inquiry', 's' => 'google_ads', 'k' => 'ads',
 *              'v' => 'AW-18204783228/VKFRCJDU27ocEPzk2-h']]
 *      t 触发动作（询盘提交 / 邮箱点击）
 *      s 流量来源（'' = 默认，任何来源都发；同一目标精确来源优先）
 *      k 类型（ga4 = GA4 记录，ads = Ads 转化）
 *      v ga4 存事件名；ads 存 Google Ads 的 send_to（转化 ID / 转化标签）
 *
 * Google 的配置只有一份列表：G-（GA4）/ AW-（Ads）/ GT-（Google Tag）一个个
 * 依次 gtag('config', id)，GTM- 开头的容器由前台单独加载 gtm.js。
 *   页面只请求一次 https://www.googletagmanager.com/gtag/js?id=<第一个 G- 开头的 ID>，
 *   之后 GA4 记录与每个 Ads 账号都只是补一条 gtag('config', '…')，
 *   所以这里没有「GA4 记录 ID + Ads 转化账号」之分，也不需要在配置里填转化标签 ——
 *   转化标签只在「事件发送」里以 send_to 的形式直接填。
 *
 * Clarity 的逻辑和 Google 完全不同（不参与 gtag、没有转化、不发事件），
 * 所以单独一块配置：是否开启 + 项目 ID。
 *
 * 没有「总开关」：Clarity 与 Google 各自一个启用开关，两个都关掉就等于全站
 * 不加载任何统计脚本，再放一个总开关只是多一层与下面两个开关打架的层级。
 */

if (!function_exists('tpi_monitor_triggers')) {
    /** 触发动作（事件发送的「什么时候发」） */
    function tpi_monitor_triggers(): array
    {
        return [
            'inquiry'     => tpi_admin_ui('monitor.trigger.inquiry'),
            'email_click' => tpi_admin_ui('monitor.trigger.email-click'),
        ];
    }
}

if (!function_exists('tpi_monitor_sources')) {
    /**
     * 流量来源，对应 sessionStorage.traffic_type（见 assets/js/initial.js）
     * 空串 = 默认（任何来源都发，精确来源优先）
     */
    function tpi_monitor_sources(): array
    {
        return [
            ''              => tpi_admin_ui('monitor.source.default'),
            'google_ads'    => tpi_admin_ui('monitor.source.google-ads'),
            'google_seo'    => tpi_admin_ui('monitor.source.google-seo'),
            'bing_ads'      => tpi_admin_ui('monitor.source.bing-ads'),
            'bing_seo'      => tpi_admin_ui('monitor.source.bing-seo'),
            'baidu_ads'     => tpi_admin_ui('monitor.source.baidu-ads'),
            'baidu_seo'     => tpi_admin_ui('monitor.source.baidu-seo'),
            'facebook_ads'  => tpi_admin_ui('monitor.source.facebook-ads'),
            'tiktok_ads'    => tpi_admin_ui('monitor.source.tiktok-ads'),
        ];
    }
}

if (!function_exists('tpi_monitor_kinds')) {
    /**
     * 事件发送的类型
     *   ga4  记录一次事件（发事件名）
     *   ads  上报一次广告转化（发 send_to，即「转化 ID/转化标签」）
     */
    function tpi_monitor_kinds(): array
    {
        return [
            'ga4' => tpi_admin_ui('monitor.kind.ga4'),
            'ads' => tpi_admin_ui('monitor.kind.ads'),
        ];
    }
}

if (!function_exists('tpi_monitor_defaults')) {
    /**
     * 默认值：账号一律留空（新站点不往别人的统计账号里打数据），
     * 事件也留空 —— 没有账号就没有接收方，预置几条等于埋一堆不会生效的配置。
     * 三个开关（本站统计 / Cookie 授权 / 本站 SEO）默认都开，只差填账号。
     */
    function tpi_monitor_defaults(): array
    {
        return [
            // 本站启用：关掉后只有 CDN 回源的请求才统计
            'site'    => '1',
            // Cookie 授权：'1' = 必须访客手动同意（默认开启），'0' = 默认授权（不弹提示条）
            'consent' => '1',
            // 本站 SEO：'1' = 源站正常收录；'0' = 源站 noindex + robots 禁抓，CDN 仍允许抓取
            'seo'     => '1',
            'clarity' => ['on' => '1', 'id' => ''],
            'google'  => ['on' => '1', 'gtag' => []],
            'events'  => [],
        ];
    }
}

if (!function_exists('tpi_monitor_id_clean')) {
    /**
     * 账号 ID 清洗：只留字母数字、下划线和连字符。
     * 不做格式强校验（各平台规则会变），填错在平台侧能看出来，这里只保证不输出脏字符。
     */
    function tpi_monitor_id_clean(string $raw): string
    {
        return (string) preg_replace('~[^A-Za-z0-9_\-]~', '', trim($raw));
    }
}

if (!function_exists('tpi_monitor_event_clean')) {
    /** 事件名只留字母数字下划线（GA4 事件名规则） */
    function tpi_monitor_event_clean(string $raw): string
    {
        return (string) preg_replace('~[^A-Za-z0-9_]~', '', trim($raw));
    }
}

if (!function_exists('tpi_monitor_sendto_clean')) {
    /** 发送目标形如「AW-18204783228/VKFRCJDU27ocEPzk2-h」，保留中间那个斜杠 */
    function tpi_monitor_sendto_clean(string $raw): string
    {
        return (string) preg_replace('~[^A-Za-z0-9_\-/]~', '', trim($raw));
    }
}

if (!function_exists('tpi_monitor_gtag_clean')) {
    /**
     * gtag 配置列表：清洗、丢空行、按出现顺序去重
     *
     * @param array $rows 形如 ['G-…', 'AW-…'] 或 [['id' => 'AW-…'], …]
     * @return string[]
     */
    function tpi_monitor_gtag_clean(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $raw = is_array($row) ? (string) ($row['id'] ?? '') : (string) $row;
            $id  = tpi_monitor_id_clean($raw);
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $out[$id] = true;
        }

        return array_keys($out);
    }
}

if (!function_exists('tpi_monitor_gtag_entry')) {
    /**
     * gtag.js 的加载入口：优先第一个 G- 开头的 ID（GA4 记录），
     * 没有 GA4 才用列表里的第一个。
     *
     * @param string[] $ids
     */
    function tpi_monitor_gtag_entry(array $ids): string
    {
        foreach ($ids as $id) {
            if (stripos($id, 'G-') === 0) {
                return $id;
            }
        }
        return (string) ($ids[0] ?? '');
    }
}

if (!function_exists('tpi_monitor_migrate')) {
    /**
     * 老配置升级到 ver 5（去掉总开关、新增本站 SEO）。
     *
     * ver 4 只是缺 seo、多一个 enabled：enabled 丢掉（改由 Clarity / Google
     * 两个启用开关控制），seo 补默认值（关），其余原样保留。
     *
     * ver 3 的结构是 google{on,ga4,ads[]}：GA4 记录 + 每个 Ads 账号的 ID
     * 本来就是同一份 gtag 配置，这里合并成 google.gtag[]，转化标签不再需要
     * （事件里的 send_to 已经存好，改由「事件发送」直接填）。
     *
     * ver 1/2 是「一个平台一行」的旧结构：type + id + label + on，
     *   clarity → clarity{on,id}
     *   ga4/ads → google.gtag[]（全部 ID；GTM 没有 gtag 配置，丢弃）
     *   事件    → ads + conversion 且填了标签 → k=ads、v=ID/标签；其余归 k=ga4、v=事件名
     *
     * @param array $saved 已存的 option
     * @return array
     */
    function tpi_monitor_migrate(array $saved): array
    {
        $ver = (int) ($saved['ver'] ?? 1);
        if ($ver >= 5) {
            return $saved;
        }

        $defaults = tpi_monitor_defaults();
        $flag     = static function (string $key) use ($saved, $defaults): string {
            return (string) ($saved[$key] ?? $defaults[$key]) === '1' ? '1' : '0';
        };

        if ($ver === 4) {
            $migrated = [
                'site'    => $flag('site'),
                'consent' => $flag('consent'),
                'seo'     => $flag('seo'),
                'clarity' => is_array($saved['clarity'] ?? null) ? $saved['clarity'] : $defaults['clarity'],
                'google'  => is_array($saved['google'] ?? null) ? $saved['google'] : $defaults['google'],
                'events'  => is_array($saved['events'] ?? null) ? $saved['events'] : [],
                'ver'     => 5,
            ];

            update_option('tpi_monitor', $migrated);

            return $migrated;
        }

        $clarity = ['on' => '1', 'id' => ''];
        $gtag    = [];
        $events  = [];

        if ($ver === 3) {
            $google  = is_array($saved['google'] ?? null) ? $saved['google'] : [];
            $clarity = [
                'on' => (string) ($saved['clarity']['on'] ?? '1') === '1' ? '1' : '0',
                'id' => tpi_monitor_id_clean((string) ($saved['clarity']['id'] ?? '')),
            ];

            $rows = [(string) ($google['ga4'] ?? '')];
            foreach ((array) ($google['ads'] ?? []) as $row) {
                $rows[] = is_array($row) ? (string) ($row['id'] ?? '') : (string) $row;
            }
            $gtag = tpi_monitor_gtag_clean($rows);

            foreach ((array) ($saved['events'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $kind  = (string) ($row['k'] ?? 'ga4') === 'ads' ? 'ads' : 'ga4';
                $value = $kind === 'ads'
                    ? tpi_monitor_sendto_clean((string) ($row['v'] ?? ''))
                    : tpi_monitor_event_clean((string) ($row['v'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $events[] = [
                    't' => (string) ($row['t'] ?? ''),
                    's' => (string) ($row['s'] ?? ''),
                    'k' => $kind,
                    'v' => $value,
                ];
            }

            $migrated = [
                'site'    => $flag('site'),
                'consent' => $flag('consent'),
                'seo'     => $defaults['seo'],
                'clarity' => $clarity,
                'google'  => ['on' => (string) ($google['on'] ?? '1') === '1' ? '1' : '0', 'gtag' => $gtag],
                'events'  => $events,
                'ver'     => 5,
            ];

            update_option('tpi_monitor', $migrated);

            return $migrated;
        }

        // ---- ver 1/2：平台行模型 ----
        $rows   = is_array($saved['platforms'] ?? null) ? $saved['platforms'] : [];
        $ids    = [];   // 账号 ID => 类型
        $labels = [];   // 账号 ID => 转化标签
        $google_on = '1';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $id   = tpi_monitor_id_clean((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $on = (string) ($row['on'] ?? '1') === '1' ? '1' : '0';

            if ($type === 'clarity') {
                $clarity = ['on' => $on, 'id' => $id];
                continue;
            }
            if ($type === 'ga4' || $type === 'ads') {
                if (!isset($ids[$id])) {
                    $gtag[] = $id;
                }
                $ids[$id]    = $type;
                $labels[$id] = (string) ($row['label'] ?? '');
                if ($on === '0') {
                    // 有停用的 Google 账号时，整体跟着关着，由后台自己再打开
                    $google_on = '0';
                }
            }
        }

        $gtag = tpi_monitor_gtag_clean($gtag);

        foreach ((array) ($saved['events'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $trigger = (string) ($row['t'] ?? '');
            $source  = (string) ($row['s'] ?? '');
            $target  = (string) ($row['p'] ?? '');
            $name    = (string) ($row['e'] ?? '');
            $mode    = (string) ($row['m'] ?? 'event');

            // ver 1 的写法「类型#ID」只取 ID
            $hash = strpos($target, '#');
            if ($hash !== false) {
                $target = substr($target, $hash + 1);
            }

            // ver 1 停在类型级别：该类型下每个账号各复制一行
            $hits = isset($ids[$target]) ? [$target] : [];
            if (!$hits) {
                foreach ($ids as $id => $type) {
                    if ($type === $target) {
                        $hits[] = $id;
                    }
                }
            }

            foreach ($hits as $id) {
                $type = $ids[$id] ?? '';

                if ($type === 'ads' && $mode === 'conversion') {
                    $label = $labels[$id] ?? '';
                    if ($label !== '') {
                        $events[] = ['t' => $trigger, 's' => $source, 'k' => 'ads', 'v' => $id . '/' . $label];
                    }
                    continue;
                }
                // Ads 上的普通事件与 GA4 是同一份 gtag，归到 GA4 记录
                if (($type === 'ads' || $type === 'ga4') && $name !== '') {
                    $events[] = ['t' => $trigger, 's' => $source, 'k' => 'ga4', 'v' => $name];
                }
            }
        }

        // 同一份事件可能从多个 Ads 账号迁出同一条，去重
        $unique = [];
        foreach ($events as $row) {
            $unique[$row['t'] . '|' . $row['s'] . '|' . $row['k'] . '|' . $row['v']] = $row;
        }

        $migrated = [
            'site'    => $flag('site'),
            'consent' => $flag('consent'),
            'seo'     => $defaults['seo'],
            'clarity' => $clarity,
            'google'  => ['on' => $google_on, 'gtag' => $gtag],
            'events'  => array_values($unique),
            'ver'     => 5,
        ];

        update_option('tpi_monitor', $migrated);

        return $migrated;
    }
}

if (!function_exists('tpi_monitor')) {
    /**
     * 当前配置（读一次缓存一次）
     *
     * @return array{site:string,consent:string,seo:string,clarity:array,google:array,events:array}
     */
    function tpi_monitor(): array
    {
        if (isset($GLOBALS['tpi_monitor_cache'])) {
            return $GLOBALS['tpi_monitor_cache'];
        }

        $defaults = tpi_monitor_defaults();
        $saved    = get_option('tpi_monitor', []);
        $saved    = is_array($saved) ? tpi_monitor_migrate($saved) : [];

        $flag = static function (string $key) use ($saved, $defaults): string {
            $value = $saved[$key] ?? $defaults[$key];
            return (string) $value === '1' ? '1' : '0';
        };

        $clarity = is_array($saved['clarity'] ?? null) ? $saved['clarity'] : $defaults['clarity'];
        $google  = is_array($saved['google'] ?? null) ? $saved['google'] : $defaults['google'];

        $cfg = [
            'site'    => $flag('site'),
            'consent' => $flag('consent'),
            'seo'     => $flag('seo'),
            'clarity' => [
                'on' => (string) ($clarity['on'] ?? '1') === '1' ? '1' : '0',
                'id' => tpi_monitor_id_clean((string) ($clarity['id'] ?? '')),
            ],
            'google'  => [
                'on'   => (string) ($google['on'] ?? '1') === '1' ? '1' : '0',
                'gtag' => tpi_monitor_gtag_clean((array) ($google['gtag'] ?? [])),
            ],
            'events'  => [],
        ];

        foreach ((array) ($saved['events'] ?? $defaults['events']) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind  = (string) ($row['k'] ?? 'ga4') === 'ads' ? 'ads' : 'ga4';
            $value = (string) ($row['v'] ?? '');
            if ($value === '') {
                continue;
            }
            $cfg['events'][] = [
                't' => (string) ($row['t'] ?? ''),
                's' => (string) ($row['s'] ?? ''),
                'k' => $kind,
                'v' => $value,
            ];
        }

        $GLOBALS['tpi_monitor_cache'] = $cfg;
        return $cfg;
    }
}

if (!function_exists('tpi_monitor_cdn_traffic')) {
    /**
     * 当前请求是不是经 CDN 回源进来的（有 X-Forwarded-Host 就是）
     * 判定统一走 tpi_cdn_is_cdn()，不在这里重复解析请求头。
     * 注意不能用 tpi_cdn_request_host()：那个没有 CDN 头时会回落到 HTTP_HOST，永远非空。
     */
    function tpi_monitor_cdn_traffic(): bool
    {
        if (function_exists('tpi_cdn_is_cdn')) {
            return tpi_cdn_is_cdn();
        }
        return !empty($_SERVER['HTTP_X_FORWARDED_HOST']);
    }
}

if (!function_exists('tpi_monitor_seo_allowed')) {
    /**
     * 本站是否允许搜索引擎收录
     *
     * 后台「本站 SEO」打开时始终允许；关闭时只有经 CDN 回源的请求允许抓取 ——
     * 源站（含本地调试、临时预览域名）全站 noindex + robots.txt 禁抓，
     * 线上走 CDN 的域名照常收录，不会因为源站设置把正式站也屏蔽掉。
     */
    function tpi_monitor_seo_allowed(): bool
    {
        $cfg = tpi_monitor();
        return $cfg['seo'] === '1' || tpi_monitor_cdn_traffic();
    }
}

if (!function_exists('tpi_monitor_consent_required')) {
    /**
     * 是否需要访客手动授权 Cookie（前台据此决定是否弹提示条、是否延迟加载）
     *
     * 没有任何脚本会加载时不弹提示条 —— 否则访客同意了一个空配置。
     */
    function tpi_monitor_consent_required(): bool
    {
        $cfg = tpi_monitor();
        if ($cfg['consent'] !== '1') {
            return false;
        }

        $front = tpi_monitor_front_config();
        return $front['clarity'] !== '' || (bool) $front['gtag'];
    }
}

if (!function_exists('tpi_monitor_save')) {
    /**
     * 保存：数组按顺序重新索引，空行丢掉（表单用 name="google[gtag][]"、
     * 不靠下标，所以这里直接顺序重建）。
     *
     * @param array<string,mixed> $input
     * @return array
     */
    function tpi_monitor_save(array $input): array
    {
        $triggers = array_keys(tpi_monitor_triggers());
        $sources  = array_keys(tpi_monitor_sources());
        $kinds    = array_keys(tpi_monitor_kinds());

        $clarity = [
            'on' => !empty($input['clarity']['on']) ? '1' : '0',
            'id' => tpi_monitor_id_clean((string) ($input['clarity']['id'] ?? '')),
        ];

        $google = [
            'on'   => !empty($input['google']['on']) ? '1' : '0',
            'gtag' => tpi_monitor_gtag_clean((array) ($input['google']['gtag'] ?? [])),
        ];

        $events = [];
        foreach ((array) ($input['events'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $trigger = (string) ($row['t'] ?? '');
            $source  = (string) ($row['s'] ?? '');
            $kind    = (string) ($row['k'] ?? 'ga4');

            if (!in_array($trigger, $triggers, true) || !in_array($kind, $kinds, true)) {
                continue;
            }
            if (!in_array($source, $sources, true)) {
                $source = '';
            }

            // 发送目标是手填的 send_to，不做「账号必须存在」的校验：
            // 转化 ID/标签在 Google Ads 后台，跟这里的 gtag 配置不一定同名同序。
            if ($kind === 'ads') {
                $value = tpi_monitor_sendto_clean((string) ($row['to'] ?? ''));
            } else {
                $value = tpi_monitor_event_clean((string) ($row['v'] ?? ''));
            }
            if ($value === '') {
                continue;
            }

            $events[] = ['t' => $trigger, 's' => $source, 'k' => $kind, 'v' => $value];
        }

        $clean = [
            'site'    => !empty($input['site']) ? '1' : '0',
            'consent' => !empty($input['consent']) ? '1' : '0',
            'seo'     => !empty($input['seo']) ? '1' : '0',
            'clarity' => $clarity,
            'google'  => $google,
            'events'  => $events,
            'ver'     => 5,
        ];

        update_option('tpi_monitor', $clean);
        unset($GLOBALS['tpi_monitor_cache'], $GLOBALS['tpi_monitor_front_cache']);

        return $clean;
    }
}

if (!function_exists('tpi_monitor_front_config')) {
    /**
     * 交给前台脚本的配置（只带启用的账号；没有任何可加载的账号就是空）
     *
     *   clarity  项目 ID（空 = 不加载）
     *   gtag     gtag('config', id) 的 ID 列表，第一个 G- 开头的是 gtag.js 入口
     *   events   事件发送行
     *   consent  '1' 表示必须访客同意后才加载
     *
     * 不输出配置的两种情况：Clarity 与 Google 都没启用（或没填账号）、
     * 本站没启用且请求不是 CDN 回源。
     *
     * @return array{clarity:string,gtag:array,events:array,consent:string}
     */
    function tpi_monitor_front_config(): array
    {
        if (isset($GLOBALS['tpi_monitor_front_cache'])) {
            return $GLOBALS['tpi_monitor_front_cache'];
        }

        $cfg = tpi_monitor();
        $out = ['clarity' => '', 'gtag' => [], 'events' => [], 'consent' => $cfg['consent']];

        // 「本站启用」关掉时，只有经 CDN 回源的访客才加载脚本
        $allowed = $cfg['site'] === '1' || tpi_monitor_cdn_traffic();

        if ($allowed) {
            if ($cfg['clarity']['on'] === '1' && $cfg['clarity']['id'] !== '') {
                $out['clarity'] = $cfg['clarity']['id'];
            }

            if ($cfg['google']['on'] === '1') {
                $out['gtag'] = $cfg['google']['gtag'];

                // 没有任何 gtag 配置就没有接收方，事件发了也是白条
                if ($out['gtag']) {
                    foreach ($cfg['events'] as $row) {
                        $out['events'][] = $row;
                    }
                }
            }
        }

        $GLOBALS['tpi_monitor_front_cache'] = $out;
        return $out;
    }
}
