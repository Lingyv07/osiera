<?php
add_action('rest_api_init', function () {
    register_rest_route('api', '/inquiry', array(
        'methods'  => 'POST',
        'callback' => 'handle_inquiry_post',
        'permission_callback' => '__return_true', // 允许公开访问，如需权限请改这里
    ));
});

function handle_inquiry_post(WP_REST_Request $request): WP_REST_Response|array {
    // 语言必须先定：收件邮箱、SMTP 账号、邮件文案都按语言取，
    // 以前这里传空串读配置，导致分语言设的收件邮箱永远用不上，全发到「全部语言」那个邮箱
    $pll_language = tpi_inquiry_request_language($request);
    $GLOBALS['tpi_inquiry_mail_lang'] = $pll_language;

    $settings = function_exists('tpi_get_smtp_settings')
        ? tpi_get_smtp_settings($pll_language)
        : get_option('my_smtp_settings', []);
    $ip = get_client_ip();
    $current_lang_json = get_lang_json($pll_language);
    $cookies = parseCookies();
    $u_token = $cookies["u_token"] ?? null;

    // 获取 POST 参数
    $company = sanitize_text_field($request->get_param('company')); // 人机参数认证
    $name = sanitize_text_field($request->get_param('name'));
    $email = sanitize_text_field($request->get_param('email'));
    $telephone = sanitize_text_field($request->get_param('telephone'));
    $message = sanitize_textarea_field($request->get_param('message'));
    $utm_term = sanitize_textarea_field($request->get_param('utm_term'));
    $origin_type = sanitize_textarea_field($request->get_param('origin_type')); // 询盘类型来源
    $referer_domain = sanitize_textarea_field($request->get_param('referer_domain')); // 询盘平台来源

    // 简单验证
    if (empty($u_token) || empty($name) || empty($email) || empty($message) || $company) {
        return new WP_REST_Response([
            'message' => $current_lang_json["message"]["inquiry"]["empty-param"]
        ], 400);
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return new WP_REST_Response([
            'message' => $current_lang_json["message"]["inquiry"]["email-format"]
        ], 400);
    }

    global $wpdb;

    $table_name = tpi_inquiry_table();
    // 获取当前时间的前10秒
    $time_10_sec_ago = date('Y-m-d H:i:s', current_time('timestamp') - 10);

    // 查询最近10秒内是否存在相同 token
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE token = %s AND created_at >= %s",
            $u_token,
            $time_10_sec_ago
        )
    );

    if ($exists) {
        // 最近10秒内已存在相同 token，不插入
        return new WP_REST_Response([
            'message' => $current_lang_json["message"]["inquiry"]["req-frequently"]
        ], 403);
    }

    /**
     * 人机判断，异常内容返回成功信息，保留数据观察，但不执行成功转发询盘程序
     *
     * 是否拦截由后台「邮箱设置 → 询盘拦截」的开关决定（默认开启，可按语言单独设）：
     * 关掉之后该语言的询盘不再做内容判定，全部按正常流程转发。
     */
    $is_abnormal = false;
    // 函数缺失时按「开启」处理，保持原来的拦截行为
    if (!function_exists('tpi_inquiry_abnormal_filter_enabled') || tpi_inquiry_abnormal_filter_enabled($pll_language)) {
        if (isAbnormal($name) || isAbnormal($message)) {
            $is_abnormal = true;
        }
    }

    // 保存到数据库，发邮件等操作
    $insert_status = $wpdb->insert($table_name, [
        'name' => $name,
        'email' => $email,
        'telephone' => $telephone,
        'message' => $message,
        'lang' => $pll_language,
        'ip' => $ip,
        'token' => $u_token,
        'origin_type' => $origin_type,
        'referer_domain' => $referer_domain,
        'utm_term' => $utm_term,
        'remark' => $is_abnormal ? "未转发询盘-内容异常" : "",
        'created_at' => current_time('mysql'),
    ]);

    $success_numbers = [0, 1, 4, 7, 8]; // 成功转发询盘数字
    $failure_numbers = [2, 3, 5, 6, 9]; // 失败转发询盘数字

    if ($is_abnormal) {
        // 判断脚本操作，不转发询盘
        $response = new WP_REST_Response([
            'message' => $current_lang_json["message"]["inquiry"]["success"],
        ], 200);
        $response->header('Lucky-Num', $failure_numbers[mt_rand(0, 4)]);// 烟雾状态码
        return $response;
    }

    if ($insert_status) {
        $to      = $settings["to"];
        $site_name = trim(wp_strip_all_tags((string) get_bloginfo('name')));
        $subject_label = tpi_inquiry_mail_ui('subject', 'Inquiry');
        $subject = ($site_name !== '' ? '[' . $site_name . '] ' : '') . $subject_label;
        $message_content = tpi_inquiry_mail_html([
            'name'      => $name,
            'email'     => $email,
            'telephone' => $telephone,
            'message'   => $message,
            'time'      => current_time('mysql'),
        ]);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        if (wp_mail($to, $subject, $message_content, $headers)) {
            $response = new WP_REST_Response([
                'message' => $current_lang_json["message"]["inquiry"]["success"],
            ], 200);
            $response->header('Lucky-Num', $success_numbers[mt_rand(0, 4)]);// 烟雾状态码
            return $response;
        } else {
            return new WP_REST_Response([
                'message' => $current_lang_json["message"]["inquiry"]["error"],
            ], 400);
        }

    }

    return new WP_REST_Response([
        'message' => $current_lang_json["message"]["inquiry"]["error"],
//        "err" =>  $wpdb->last_error
    ], 400);
}

if (!function_exists('tpi_inquiry_request_language')) {
    /**
     * 这条询盘算哪种语言
     *
     * REST 地址是固定路由（/wp-json/api/inquiry），不带语言前缀，直接调
     * tpi_current_language() 只会拿到默认语言 —— 分语言配置的收件邮箱因此永远走不到。
     * 依次判断：表单带的 lang → 来源页 Referer 的语言前缀 → 当前语言 → 默认语言。
     *
     * @param WP_REST_Request|null $request
     */
    function tpi_inquiry_request_language($request = null): string
    {
        $slugs = function_exists('tpi_language_slugs') ? tpi_language_slugs() : [];
        if (!$slugs) {
            return '';
        }

        $pick = static function ($value) use ($slugs): string {
            $value = strtolower(trim((string) $value));
            return in_array($value, $slugs, true) ? $value : '';
        };

        // 1) 表单提交时带上当前语言，最准
        if ($request instanceof WP_REST_Request) {
            $lang = $pick($request->get_param('lang'));
            if ($lang !== '') {
                return $lang;
            }
        }

        // 2) 来源页首段：/en/contact、/ru/products/xxx
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $path  = (string) parse_url($referer, PHP_URL_PATH);
            $first = explode('/', trim($path, '/'))[0] ?? '';
            $lang  = $pick($first);
            if ($lang !== '') {
                return $lang;
            }
        }

        // 3) 当前上下文（后台、站内调用）
        $lang = $pick(function_exists('tpi_current_language') ? tpi_current_language() : '');
        if ($lang !== '') {
            return $lang;
        }

        return function_exists('tpi_default_language') ? tpi_default_language() : (string) $slugs[0];
    }
}

if (!function_exists('tpi_inquiry_mail_ui')) {
    /**
     * 询盘邮件里的文案
     *
     * 跟「后台语言」走（tpi_admin_config_lang()：有登录用户取用户语言，否则取站点语言），
     * 不跟访客的前台语言走 —— 收信的是站长，他看到的后台是哪套语言，邮件就该是哪套。
     * 文案真源仍是 languages/admin-system-config-{zh|en}.json 的 ui.inquiry.mail。
     */
    function tpi_inquiry_mail_ui(string $key, string $fallback = ''): string
    {
        $text = (string) tpi_admin_ui('inquiry.mail.' . $key);
        return $text !== '' ? $text : $fallback;
    }
}

if (!function_exists('tpi_inquiry_mail_html')) {
    /**
     * 询盘通知邮件正文
     *
     * 只用 table + 行内样式：Outlook、QQ / 网易邮箱都会丢掉 <style> 里的 class，
     * flex / grid 也会被打散，老办法最稳。整封邮件铺满宽度、无外边距，正文以黑色为主，
     * 只有顶部标题条用主题蓝（默认 #2271b1，和后台主色一致），需要换色：
     *   add_filter('tpi_inquiry_mail_accent', fn() => '#135e96');
     *
     * @param array{name?:string,email?:string,telephone?:string,message?:string,time?:string} $data
     */
    function tpi_inquiry_mail_html(array $data): string
    {
        $site   = trim(wp_strip_all_tags((string) get_bloginfo('name')));
        $accent = (string) apply_filters('tpi_inquiry_mail_accent', '#2271b1');
        $time   = (string) ($data['time'] ?? current_time('mysql'));

        $name      = trim((string) ($data['name'] ?? ''));
        $email     = trim((string) ($data['email'] ?? ''));
        $telephone = trim((string) ($data['telephone'] ?? ''));
        $message   = trim((string) ($data['message'] ?? ''));

        $dash = '<span style="color:#8a949e;">—</span>';

        // 邮箱、电话都是纯文本，不做 mailto / tel 链接：
        // 加上链接后部分客户端会强制显示成可点样式，鼠标一拖就触发跳转，反而选不中、复制不了。
        $rows   = [];
        $rows[] = [
            tpi_inquiry_mail_ui('name', 'Name'),
            $name !== '' ? esc_html($name) : $dash,
        ];
        $rows[] = [
            tpi_inquiry_mail_ui('email', 'Email'),
            $email !== '' ? esc_html($email) : $dash,
        ];
        $rows[] = [
            tpi_inquiry_mail_ui('telephone', 'Phone'),
            $telephone !== '' ? esc_html($telephone) : $dash,
        ];

        $rows_html = '';
        $last      = count($rows) - 1;
        foreach ($rows as $i => $row) {
            $line       = $i < $last ? 'border-bottom:1px solid #eceff2;' : '';
            $rows_html .= '<tr>'
                . '<td width="76" style="padding:9px 12px 9px 0;' . $line . 'vertical-align:top;font-size:13px;line-height:22px;color:#4a545e;white-space:nowrap;">' . esc_html((string) $row[0]) . '</td>'
                . '<td style="padding:9px 0;' . $line . 'font-size:15px;line-height:22px;color:#111111;word-break:break-all;">' . $row[1] . '</td>'
                . '</tr>';
        }

        $message_html = $message !== '' ? nl2br(esc_html($message), false) : $dash;
        $site_label   = $site !== ''
            ? esc_html($site)
            : esc_html(tpi_inquiry_mail_ui('subject', 'Inquiry'));

        return strtr(<<<'HTML'
<!DOCTYPE html>
<html lang="{{LANG}}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{{SUBJECT}}</title>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#ffffff;">{{PREVIEW}}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0;background-color:#ffffff;border-collapse:collapse;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',Arial,sans-serif;">
<tr>
<td style="background-color:{{ACCENT}};padding:16px 20px;">
<div style="font-size:17px;font-weight:600;line-height:24px;color:#ffffff;">{{SITE}}</div>
<div style="margin-top:2px;font-size:12px;line-height:18px;color:#dbe9f7;">{{SUBTITLE}}</div>
</td>
</tr>
<tr>
<td style="padding:14px 20px 2px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
{{ROWS}}
</table>
</td>
</tr>
<tr>
<td style="padding:6px 20px 16px;">
<div style="padding-bottom:12px;font-size:13px;line-height:20px;color:#4a545e;">{{MSG_LABEL}}</div>
<div style="background-color:#f6f8fa;border-left:3px solid {{ACCENT}};padding:12px 14px;font-size:15px;line-height:24px;color:#111111;word-break:break-word;">{{MSG}}</div>
</td>
</tr>
<tr>
<td style="padding:10px 20px 14px;border-top:1px solid #eceff2;">
<div style="font-size:12px;line-height:18px;color:#6b7280;">{{FOOTER}}</div>
</td>
</tr>
</table>
</body>
</html>
HTML, [
            '{{LANG}}'      => tpi_admin_config_lang() === 'zh' ? 'zh-CN' : 'en',
            '{{ACCENT}}'    => esc_attr($accent),
            '{{SUBJECT}}'   => esc_html(tpi_inquiry_mail_ui('subject', 'Inquiry')),
            '{{SITE}}'      => $site_label,
            '{{SUBTITLE}}'  => esc_html(tpi_inquiry_mail_ui('subtitle', '')),
            '{{PREVIEW}}'   => esc_html(sprintf(tpi_inquiry_mail_ui('preview', '%s'), $site !== '' ? $site : tpi_inquiry_mail_ui('subject', 'Inquiry'))),
            '{{ROWS}}'      => $rows_html,
            '{{MSG_LABEL}}' => esc_html(tpi_inquiry_mail_ui('message', 'Message')),
            '{{MSG}}'       => $message_html,
            '{{FOOTER}}'    => sprintf(
                esc_html(tpi_inquiry_mail_ui('footer', '%1$s · %2$s')),
                $site_label,
                esc_html($time)
            ),
        ]);
    }
}