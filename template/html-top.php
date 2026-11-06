<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<?php
global $description_content, $current_lang_json;
$paged = get_query_var('paged');
;?>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <title><?php echo wp_title_rss() || ''; ?></title>
    <?php
    // 描述统一压成一行并截到搜索引擎摘要长度，含引号也要能安全输出
    $tpi_desc = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $description_content)));
    if (mb_strlen($tpi_desc) > 165) {
        $tpi_desc = rtrim(mb_substr($tpi_desc, 0, 160)) . '…';
    }
    if ($tpi_desc !== '') {
        echo '<meta name="description" content="' . esc_attr($tpi_desc) . '">' . "\n";
    }
    ;?>
    <?php wp_head(); ?>
</head>
<body>
<?php //wp_body_open(); ?>