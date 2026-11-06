<?php
add_action('rest_api_init', function () {
    register_rest_route('api', '/get-video', array(
        'methods'  => 'GET',
        'callback' => 'handle_get_video',
        'permission_callback' => '__return_true',
    ));
});

function handle_get_video(WP_REST_Request $request) {
    global $cdn_host, $page_info_settings, $old_host, $new_host, $main_domain, $current_lang_json;

    $id = $request->get_param('id');
    if (!$id) {
        wp_die('Missing ID', '', 400);
    }

    $video_url = wp_get_attachment_url($id);
    if (!$video_url) {
        wp_die('ID Error', '', 400);
    }

    // 视频标题与描述
    $video_name = $current_lang_json['home']["video-title"] ?? '';
    $video_description = $current_lang_json['home']['video-desc'] ?? '';

    $scheme = is_ssl() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $main_domain;
    $request_uri = $_SERVER['REQUEST_URI'] ?? "/videos?id=$id";
    $embed_url = $scheme . '://' . $host . $request_uri;

    header('Content-Type: text/html; charset=UTF-8');
    if ($cdn_host) {
        header('x-robots-tag: index');
    }

    $html = '<script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "VideoObject",
      "name": "' . $video_name . '",
      "description": "' . $video_description . '",
      "thumbnailUrl": "' . ($page_info_settings['company_video_image'] ?? '') . '",
      "uploadDate": "' . get_the_date('c', $id) . '",
      "duration": "PT2M10S",
      "contentUrl": "' . esc_url($video_url) . '",
      "embedUrl": "' . esc_url($embed_url) . '"
    }
    </script>
    <body style="margin: 0;">
        <video controls width="100%"
               title="'.$video_name.'"
               aria-label="'.$video_description.'"
               poster="'.($page_info_settings['company_video_image'] ?? "").'"
               >
            <source src="' . esc_url($video_url) . '" type="video/mp4">
        </video>
    </body>';

    // 1️⃣ 替换上传路径
    $html = str_replace(
        $old_host . "/wp-content/uploads",
        $old_host . "/uploads/assets",
        $html
    );

    // 2️⃣ 替换所有域名
    if ($cdn_host) {
        $html = str_replace($old_host, $new_host, $html);
    }
    // 输出 HTML
    echo $html;
    exit; // 直接结束请求
}