<?php
function get_html_top(): void {
    require THEME_DIR. 'template/html-top.php';
}

function get_fixed_functional_box(): void {
    require THEME_DIR. 'template/fixed-functional.php';
}

function get_product_fixed(): void {
    require THEME_DIR. 'template/products-fixed.php';
}

function get_pagination(): void {
    require THEME_DIR. 'template/pagination.php';
}

function get_change_layout(): void {
    require THEME_DIR. 'template/change-layout.php';
}

function get_search_pagination(): void {
    require THEME_DIR. 'template/search-pagination.php';
}

if (!function_exists('tpi_list_head_json')) {
    /**
     * 列表页顶部标题 / 描述 / 面包屑名称的取值
     *
     * 分类描述里存的是 JSON（title / description / breadcrumb_list_name），
     * 分类页以它为准；缺字段、字段为空、或者不是合法 JSON 时，逐项回落到
     * 语言包（languages 里 news / product / applications / faq 三件套）。
     *
     * @param string|null $term_desc 分类描述原文
     * @param array       $fallback  语言包对应分组（键名用连字符写法）
     * @return array{title:string, description:string, breadcrumb_list_name:string}
     */
    function tpi_list_head_json($term_desc, array $fallback): array
    {
        $data = null;
        if (is_string($term_desc)) {
            $text = trim($term_desc);
            if ($text !== '' && $text[0] === '{') {
                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        $pick = static function (string $key, string $lang_key) use ($data, $fallback): string {
            $value = '';
            if (is_array($data) && isset($data[$key]) && is_scalar($data[$key])) {
                $value = (string) $data[$key];
            }
            if (trim($value) === '' && isset($fallback[$lang_key]) && is_scalar($fallback[$lang_key])) {
                $value = (string) $fallback[$lang_key];
            }
            return $value;
        };

        return [
            'title'                => $pick('title', 'title'),
            'description'          => $pick('description', 'description'),
            'breadcrumb_list_name' => $pick('breadcrumb_list_name', 'breadcrumb-list-name'),
        ];
    }
}

if (!function_exists('tpi_term_head')) {
    /**
     * 分类页顶部标题 / 描述：JSON 优先，纯文本分类名次之，最后才是语言包
     *
     * 分类描述可能有两种写法：
     *   1. JSON（title / description / breadcrumb_list_name）—— 老站的做法，整段照读；
     *   2. 普通文字（后台直接写的分类说明）—— 标题用分类名，描述用这段文字。
     * 之前只认第 1 种，纯文字分类会一路回落到语言包，导致点进分类页
     * 标题还是列表页的通用标题。
     *
     * @param WP_Term|null $term     分类对象
     * @param array        $fallback 语言包对应分组
     * @return array{title:string, description:string, breadcrumb_list_name:string}
     */
    function tpi_term_head($term, array $fallback): array
    {
        $desc = ($term instanceof WP_Term) ? html_entity_decode((string) $term->description) : '';
        $head = tpi_list_head_json($desc, $fallback);
        if (!($term instanceof WP_Term)) {
            return $head;
        }

        $text = trim($desc);
        $json = ($text !== '' && str_starts_with($text, '{')) ? json_decode($text, true) : null;
        if (is_array($json)) {
            return $head; // JSON 少字段时 tpi_list_head_json 已逐项回落
        }

        $head['title']                = $term->name;
        $head['breadcrumb_list_name'] = $term->name;
        $plain = trim(wp_strip_all_tags($text));
        if ($plain !== '') {
            $head['description'] = $plain;
        }
        return $head;
    }
}
