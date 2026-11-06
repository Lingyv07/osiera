<?php
global $custom_page_array, $path;
if (isset($custom_page_array[$path])) {
    require_once THEME_DIR. $custom_page_array[$path];
} else {
    $page_path = routeCallPage();

    if ($page_path) {
        require_once THEME_DIR. $page_path;
    } else {
        get_header();
        the_content();
        get_footer();
    }
}