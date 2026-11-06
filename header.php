<?php
global $language_map_list, $media_info_list, $page_info_settings, $current_lang_json, $lang, $current_lang_info, $current_lang;
get_html_top();
?>
<header class="header" role="banner">
    <div class="logo">
        <?php
        if (has_custom_logo()) {
            the_custom_logo(); // 自带 aria-label 和链接
        } else {
            // 新项目还没在「外观 → 自定义 → 站点身份」上传 logo：
            // 用站点名兜底，否则这里会是一片空白（the_custom_logo() 什么都没有输出）
            echo '<a href="' . esc_url(go_url('home')) . '" class="custom-logo-link custom-logo-text"'
                . ' title="' . esc_attr(get_bloginfo('name')) . '" aria-label="Home">'
                . '<span>' . esc_html(get_bloginfo('name')) . '</span></a>';
        }
        ?>
    </div>
    <div class="menu-box-wrap mobile-menu-box">
        <div class="telephone-contact" aria-label="Telephone contact">
            <span class="phone-number"><?php echo $page_info_settings['telephone'] ?? '';?></span>
            <a class="call-now" rel="nofollow" href="tel:<?php echo $page_info_settings['telephone'] ?? '';?>" aria-label="Call now">
                <?php echo $current_lang_json["header"]["call-now"] ?? "";?>
            </a>
        </div>
        <nav class="main-navigation mobile-main-navigation" aria-label="Main site navigation">
            <?php
            // 导航用页面列表（Custom_Walker_Nav_Menu 继承 Walker_Page），
            // 成员来自主题菜单名单 tpi_primary_menu、顺序由 menu_order 决定，
            // 见 inc/content/primary-menu.php 与 inc/multilang/multilang-ui.php
            tpi_render_primary_menu([
                'children_link_after' => '<button class="expand-children-btn" aria-expanded="false" aria-label="expand menu"><span class="expand-children-btn-icon"></span></button>',
            ]);
            ?>
        </nav>
        <form class="header-search" action="<?php echo '/'. ($current_lang ? $current_lang. '/' : '')?>" method="get" role="search" aria-label="Site search">
            <label for="search-query" hidden><?php echo $current_lang_json["header"]["search-label"] ?? '';?></label>
            <input id="search-query" type="text" name="s" placeholder="<?php echo $current_lang_json["header"]["search-placeholder"] ?? "";?>" aria-label="Search articles">
            <button type="submit" aria-label="Submit search form">
                <?php echo tpi_svg_markup('Search', 'ffffff'); ?>
            </button>
        </form>
        <div class="change-language mobile-change-language" aria-label="Language switcher">
            <button class="language-toggle point-to" aria-expanded="false" aria-label="Toggle language selection">
                <img src="<?php echo $current_lang_info["icon_url"] ?>" alt="<?php echo  "Language " . $current_lang_info['text'];?>" aria-hidden="true">
                <span class="current-language" aria-label="Current language: <?php echo $current_lang_info['text']; ?>">
                <?php echo $current_lang_info['text']; ?>
            </span>
            </button>
            <span class="select-language-description">
                <?php echo $current_lang_json["header"]["select-language-description"] ?? "";?> :
            </span>
            <ul class="language-box mobile-language-box" aria-label="Language selection">
                <?php foreach ($language_map_list as $code => $item):?>
                    <li class="language-item <?php  if ($item["lang"] === $lang) echo "language-item-active" ?>">
                        <button type="button" lang="<?php echo $item["lang"];?>" <?php if ($lang === $item["lang"]) echo 'aria-current="page"'; ?>>
                            <img src="<?php echo $item["icon_url"] ?>" alt="<?php echo $item["text"];?>" aria-hidden="true">
                            <span><?php echo $item["text"];?></span>
                            <?php if ($item["lang"] === $lang) : ?>
                                <img src="<?php echo esc_url(tpi_svg_url('Selected', '14547c')); ?>" aria-hidden="true" alt="Current language">
                            <?php endif; ?>
                        </button>
                    </li>
                <?php endforeach;?>
            </ul>
        </div>
        <div class="mobile-social-media">
            <span class="mobile-social-media-description">
                <?php echo $current_lang_json["header"]["social-media-description"] ?? "";?> :
            </span>
            <ul class="social-icons mobile-social-icons" aria-label="Follow us on social media List">
                <?php foreach ($media_info_list as $code => $item):?>
                    <li aria-label="<?php echo $item["name"]; ?>">
                        <a class="point-to" href="<?php echo $item["url"]; ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?php echo $item["name"]; ?>">
                            <img src="<?php echo $item["icon_url"] ?>" alt="<?php echo $item["name"];?>" aria-hidden="true">
                            <span class="mobile-social-icons-title" aria-label="Social media handles"><?php echo $item["name"]; ?></span>
                        </a>
                    </li>
                <?php endforeach;?>
            </ul>
        </div>
    </div>
    <form class="header-search mobile-header-search" action="<?php echo '/'. ($current_lang ? $current_lang. '/' : '')?>" method="get" role="search" aria-label="Mobile Site search">
        <label for="mobile-search-query" hidden><?php echo $current_lang_json["header"]["search-label"] ?? '';?></label>
        <input id="mobile-search-query" type="text" name="s" placeholder="<?php echo $current_lang_json["header"]["search-placeholder"] ?? "";?>" aria-label="Mobile Search articles">
        <button type="submit" aria-label="Mobile Submit search form">
            <?php echo tpi_svg_markup('Search', 'ffffff'); ?>
        </button>
    </form>
    <button class="mobile-menu-toggle" aria-label="Open site mobile menu" aria-controls="main-navigation" aria-expanded="false">
        <span aria-hidden="true"></span>
    </button>
</header>
<main class="content" role="main">
