<?php
/**
* 主题静态资源地址
 *
 * 走 tpi_asset_base()，不写死 /assets 前缀，换主题目录名或站点都不用改配置。
 */

/**
 * 主题内静态资源地址（css / js / svg …）
 *
 * 以前模板里写的是根相对路径 `/assets/...`，依赖 Nginx 里一条
 * `location /assets { rewrite ^/assets/(.*)$ /wp-content/themes/<主题目录>/assets/$1 last; }`
 * 才能访问；
 * 主题目录一改名（或换到没有这条配置的站点）整站样式就会 404。
 * 统一走这个函数后，资源地址直接取主题目录，换目录名、换站点都不用改配置。
 *
 * @param string $path 相对 assets 目录的路径，例如 '/css/home.css'
 * @return string
 */
function tpi_asset(string $path): string {
    return tpi_asset_base() . '/' . ltrim($path, '/');
}
