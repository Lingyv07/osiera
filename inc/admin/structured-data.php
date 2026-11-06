<?php
/**
 * 结构化数据（JSON-LD）覆盖 —— 按页面类型填 JSON
 *
 * 这里只做一件事：把后台填的 JSON 深度合并进模板已经生成的 @graph。
 * 品牌名、地址、电话、社媒这些站点信息归「页面信息设置」与「多语言内容」管，
 * 本页不放字段，避免同一份数据在三个地方各填一遍。
 *
 * 覆盖规则（先套「所有页面」，再套本页类型）：
 *   1) 带 @type  → 合并进所有同 @type 的节点（只覆盖写到的字段）；没有该类型就追加
 *   2) 带 @id    → 合并进 @id 相同的节点；没有就追加
 *   3) 都没带    → 按位置对应：数组里的第 1 项改第 1 个节点、第 2 项改第 2 个……
 *                  节点数不够时追加为新节点（只有数组 / 带 @graph 的整段才算按位置）
 *   4) 空对象 {} → 跳过，只占位置
 *   5) 字段值写 null → 删掉该字段
 *   6) 与 @graph 同级的键 → 合并到 JSON-LD 根（如 {"inLanguage": "zh-CN"} 会出现在 @graph 旁边）
 *
 * 存储沿用 SMTP / IndexNow 的一套：
 *   lang_id = 0  → 全部语言默认
 *   lang_id > 0  → 该语言的覆盖
 *   空串         → 不落库 = 继承上一级
 *
 * 前台接入点：push-js.php 的 wp_head，输出前调 tpi_sd_filter_graph()。
 *
 * @package Osiera
 */

/* =========================================================
 * 一、页面类型与默认值
 * ========================================================= */

if (!function_exists('tpi_sd_page_types')) {
    /**
     * 可分别覆盖的页面类型
     *
     * 顺序有意义：common 先应用，页面自己的补丁后应用（同字段后者胜）。
     */
    function tpi_sd_page_types(): array
    {
        return ['common', 'home', 'product', 'post', 'application', 'faq', 'page'];
    }
}

if (!function_exists('tpi_sd_defaults')) {
    /**
     * 设置项默认值：全部空串
     *
     * 空 = 不覆盖，所以「启用主题后什么都不填」与改造前输出完全一致。
     */
    function tpi_sd_defaults(): array
    {
        $defaults = [];
        foreach (tpi_sd_page_types() as $type) {
            $defaults['json_' . $type] = '';
        }

        // 默认全空：结构化数据属于前端信息，只存数据库，不写进后台语言包。
        // 若某个部署在 settings.structured-data 里另写了默认值，按同名键兼容覆盖。
        $template = tpi_lang_template_setting('structured-data', []);
        if (is_array($template) && $template) {
            foreach ($template as $key => $value) {
                if (strpos((string) $key, 'json_') === 0) {
                    $defaults[$key] = (string) $value;
                }
            }
        }
        return $defaults;
    }
}

/* =========================================================
 * 二、读取
 * ========================================================= */

if (!function_exists('tpi_sd_lang')) {
    /** 前台当前语言（拿不到就退回默认语言） */
    function tpi_sd_lang(): string
    {
        $lang = function_exists('tpi_current_language') ? (string) tpi_current_language() : '';
        if ($lang === '' && function_exists('tpi_default_language')) {
            $lang = (string) tpi_default_language();
        }
        return $lang;
    }
}

if (!function_exists('tpi_sd_settings')) {
    /**
     * 某语言下生效的设置（全站默认 → 该语言覆盖 → 内置默认）
     *
     * @param string $lang 空 = 只看全站默认
     */
    function tpi_sd_settings(string $lang = ''): array
    {
        return tpi_setting_get('structured-data', $lang, tpi_sd_defaults());
    }
}

if (!function_exists('tpi_sd_raw')) {
    /**
     * 取某个页面类型的原始 JSON 文本
     *
     * @param string $type common / home / product / post / application / faq / page
     */
    function tpi_sd_raw(string $type, string $lang = ''): string
    {
        if (!in_array($type, tpi_sd_page_types(), true)) {
            return '';
        }
        if ($lang === '') {
            $lang = tpi_sd_lang();
        }
        $settings = tpi_sd_settings($lang);
        $value    = $settings['json_' . $type] ?? '';
        return is_array($value) ? '' : (string) $value;
    }
}

/* =========================================================
 * 三、JSON 解析与合并
 * ========================================================= */

if (!function_exists('tpi_sd_json_locate')) {
    /**
     * 在 json_decode 已经失败的前提下，指出大概出错的行
     *
     * 只做结构扫描（括号、引号、逗号、冒号），不判断取值是否可以解码，
     * 所以只在语法已经报错时调用，不会误伤正常 JSON。
     *
     * @return array{0:int,1:string} [行号, 说明]
     */
    function tpi_sd_json_locate(string $raw): array
    {
        $len         = strlen($raw);
        $i           = 0;
        $line        = 1;
        $types       = [];   // 栈：obj / arr
        $states      = [];   // 栈：value / key / colon / after
        $open_lines  = [];   // 栈：每层开始的行号
        $counts      = [];   // 栈：每层已经收下的成员数（用来识别多余的逗号）
        $root_done   = false;

        while ($i < $len) {
            $ch = $raw[$i];
            if ($ch === "\n") {
                $line++;
                $i++;
                continue;
            }
            if ($ch === ' ' || $ch === "\t" || $ch === "\r") {
                $i++;
                continue;
            }

            $depth = count($states);
            $state = $depth > 0 ? $states[$depth - 1] : ($root_done ? 'done' : 'value');

            // ---- 结束符 ----
            if ($state === 'done') {
                return [$line, tpi_admin_ui('structured.error.end-extra')];
            }

            // ---- 期望键名 ----
            if ($state === 'key') {
                if ($ch === '}') {
                    // 走到这里说明是 {} 或 {"a":1,} —— 后者多了一个逗号
                    if (($counts[$depth - 1] ?? 0) > 0) {
                        return [$line, tpi_admin_ui('structured.error.comma-dup')];
                    }
                    array_pop($states);
                    array_pop($types);
                    array_pop($open_lines);
                    array_pop($counts);
                    if ($states) {
                        $states[count($states) - 1] = 'after';
                    } else {
                        $root_done = true;
                    }
                    $i++;
                    continue;
                }
                if ($ch !== '"') {
                    return [$line, tpi_admin_ui('structured.error.key-name')];
                }
                $end = tpi_sd_json_string_end($raw, $i, $line);
                if ($end[0] < 0) {
                    return [$line, tpi_admin_ui('structured.error.string-open')];
                }
                [$i, $line] = $end;
                $states[$depth - 1] = 'colon';
                continue;
            }

            // ---- 期望冒号 ----
            if ($state === 'colon') {
                if ($ch !== ':') {
                    return [$line, tpi_admin_ui('structured.error.colon')];
                }
                $states[$depth - 1] = 'value';
                $i++;
                continue;
            }

            // ---- 期望逗号或闭合 ----
            if ($state === 'after') {
                $top = $types[$depth - 1] ?? '';
                if ($ch === ',') {
                    $states[$depth - 1] = ($top === 'obj') ? 'key' : 'value';
                    $i++;
                    continue;
                }
                if (($ch === '}' && $top === 'obj') || ($ch === ']' && $top === 'arr')) {
                    array_pop($states);
                    array_pop($types);
                    array_pop($open_lines);
                    array_pop($counts);
                    if ($states) {
                        $states[count($states) - 1] = 'after';
                    } else {
                        $root_done = true;
                    }
                    $i++;
                    continue;
                }
                return [$line, tpi_admin_ui('structured.error.comma-or-close')];
            }

            // ---- 期望一个值（state = value）----
            if ($ch === '{') {
                $types[]      = 'obj';
                $states[]     = 'key';
                $open_lines[] = $line;
                $counts[]     = 0;
                $i++;
                continue;
            }
            if ($ch === '[') {
                $types[]      = 'arr';
                $states[]     = 'value';
                $open_lines[] = $line;
                $counts[]     = 0;
                $i++;
                continue;
            }
            // 空数组收尾；已经有元素还遇到 ] = 多了一个逗号
            if ($ch === ']' && ($types[$depth - 1] ?? '') === 'arr') {
                if (($counts[$depth - 1] ?? 0) > 0) {
                    return [$line, tpi_admin_ui('structured.error.comma-dup')];
                }
                array_pop($states);
                array_pop($types);
                array_pop($open_lines);
                array_pop($counts);
                if ($states) {
                    $states[count($states) - 1] = 'after';
                } else {
                    $root_done = true;
                }
                $i++;
                continue;
            }
            if ($ch === '"') {
                $end = tpi_sd_json_string_end($raw, $i, $line);
                if ($end[0] < 0) {
                    return [$line, tpi_admin_ui('structured.error.string-open')];
                }
                [$i, $line] = $end;
            } elseif (strpos('0123456789-+.eEtruefalsn', $ch) !== false) {
                while ($i < $len && strpos(" \t\r\n,]}", $raw[$i]) === false) {
                    $i++;
                }
            } else {
                return [$line, tpi_admin_ui('structured.error.value')];
            }

            if ($depth > 0) {
                $states[$depth - 1] = 'after';
                $counts[$depth - 1] = ($counts[$depth - 1] ?? 0) + 1;
            } else {
                $root_done = true;
            }
        }

        if ($states) {
            $open = $open_lines ? (int) $open_lines[count($open_lines) - 1] : $line;
            return [$open, tpi_admin_ui('structured.error.bracket')];
        }
        if (!$root_done) {
            return [$line, tpi_admin_ui('structured.error.empty')];
        }
        return [1, tpi_admin_ui('structured.error.unknown')];
    }
}

if (!function_exists('tpi_sd_json_string_end')) {
    /**
     * 跳过一段字符串，返回 [字符串之后的位置, 新的行号]
     *
     * 字符串里出现裸换行即视为未闭合（JSON 不允许），返回 -1。
     */
    function tpi_sd_json_string_end(string $raw, int $start, int $line): array
    {
        $len = strlen($raw);
        $j   = $start + 1;
        while ($j < $len) {
            $c = $raw[$j];
            if ($c === '\\') {
                $j += 2;
                continue;
            }
            if ($c === "\n") {
                return [-1, $line];
            }
            if ($c === '"') {
                return [$j + 1, $line];
            }
            $j++;
        }
        return [-1, $line];
    }
}

if (!function_exists('tpi_sd_json_error')) {
    /**
     * 校验 JSON：返回空串表示没问题，否则是可读的错误（带行号）
     */
    function tpi_sd_json_error(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $found = tpi_sd_json_locate($raw);
            if ($found && (string) ($found[1] ?? '') !== '') {
                return sprintf(tpi_admin_ui('structured.error.located'), (int) $found[0], (string) $found[1]);
            }
            return json_last_error_msg();
        }
        if (!is_array($decoded)) {
            return tpi_admin_ui('structured.error.not-object');
        }
        return '';
    }
}

if (!function_exists('tpi_sd_parse')) {
    /**
     * 一段 JSON → 补丁节点 + @graph 同级键
     *
     * 三种写法都认：
     *   1) 单个对象             {"@type": "Product", ...}
     *   2) 对象数组             [{...}, {}, {...}]
     *   3) 带 @graph 的整段 JSON {"@graph": [{...}, {}, {...}], "inLanguage": "zh-CN"}
     *
     * @return array{sequence:bool,nodes:array[],root:array}
     *   sequence = true 表示「按位置对应」（数组或 @graph 数组）；
     *   nodes 里的空对象会保留，用来占住位置；root 是 @graph 同级的其它键。
     */
    function tpi_sd_parse(string $raw): array
    {
        $result = ['sequence' => false, 'nodes' => [], 'root' => []];

        if (tpi_sd_json_error($raw) !== '') {
            return $result;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return $result;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $result;
        }

        // 顶层就是数组：[{...}, {}, {...}]
        if (tpi_is_list($decoded)) {
            $result['sequence'] = true;
            foreach ($decoded as $item) {
                $result['nodes'][] = is_array($item) ? $item : [];
            }
            return $result;
        }

        // 整段 @graph 写法：数组里逐项对位，@graph 同级其它键归到 root
        if (array_key_exists('@graph', $decoded)) {
            $graph = $decoded['@graph'];
            unset($decoded['@graph']);
            $result['root']     = $decoded;
            $result['sequence'] = true;

            if (is_array($graph) && tpi_is_list($graph)) {
                foreach ($graph as $item) {
                    $result['nodes'][] = is_array($item) ? $item : [];
                }
            } elseif (is_array($graph)) {
                $result['nodes'][] = $graph;   // @graph 写成单个对象
            }
            return $result;
        }

        // 单个对象：有 @type / @id 就按它们匹配，都没有时按老行为追加
        $result['nodes'][] = $decoded;
        return $result;
    }
}

if (!function_exists('tpi_sd_context')) {
    /**
     * 当前请求属于哪种页面类型
     *
     * 判不出来（列表、分类、搜索、404）一律归 page —— 那份是「其它页面」。
     */
    function tpi_sd_context(): string
    {
        if (is_admin()) {
            return 'page';
        }
        if (is_front_page() || is_home()) {
            return 'home';
        }
        // 非默认语言的 /xx/home 不是 front_page，但仍是这个语言的首页
        if (is_page()) {
            $id = (int) get_queried_object_id();
            if ($id > 0 && function_exists('tpi_is_language_home_page') && tpi_is_language_home_page($id)) {
                return 'home';
            }
        }
        if (is_singular()) {
            $map = [
                'product'     => 'product',
                'post'        => 'post',
                'application' => 'application',
                'faq'         => 'faq',
            ];
            $type = (string) get_post_type();
            if (isset($map[$type])) {
                return $map[$type];
            }
        }
        return 'page';
    }
}

if (!function_exists('tpi_sd_sources')) {
    /**
     * 当前页面要套用的来源，按顺序：「所有页面」→ 本页类型
     *
     * @return string[]
     */
    function tpi_sd_sources(): array
    {
        $context = tpi_sd_context();
        $sources = ['common'];
        if ($context !== 'common') {
            $sources[] = $context;
        }
        return $sources;
    }
}

if (!function_exists('tpi_sd_merge')) {
    /**
     * 深度合并：补丁里的值优先
     *
     * 对象递归合并（只覆盖写到的字段），数组与标量整体替换，
     * 值为 null 表示删除该字段。
     */
    function tpi_sd_merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
                continue;
            }
            if (
                is_array($value) && !tpi_is_list($value)
                && isset($base[$key]) && is_array($base[$key]) && !tpi_is_list($base[$key])
            ) {
                $base[$key] = tpi_sd_merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }
}

/* =========================================================
 * 四、输出前套用
 * ========================================================= */

if (!function_exists('tpi_sd_apply_patch')) {
    /**
     * 把一个补丁节点套到 @graph 上
     *
     * 优先级：@type 匹配 → @id 匹配 → 按位置对位（$pos）→ 追加
     *
     * @param array $graph 当前 @graph
     * @param array $patch 补丁节点（不会是空对象）
     * @param int   $pos   在填写序列里的位置；-1 表示这份 JSON 不是数组（不做对位）
     */
    function tpi_sd_apply_patch(array $graph, array $patch, int $pos): array
    {
        $type = isset($patch['@type']) && is_string($patch['@type']) ? trim($patch['@type']) : '';
        if ($type !== '') {
            $hit = false;
            foreach ($graph as $index => $node) {
                if (!is_array($node)) {
                    continue;
                }
                $node_types = $node['@type'] ?? '';
                $node_types = is_array($node_types) ? $node_types : [$node_types];
                if (!in_array($type, array_map('strval', $node_types), true)) {
                    continue;
                }
                $graph[$index] = tpi_sd_merge($node, $patch);
                $hit = true;
            }
            if (!$hit) {
                $graph[] = $patch;
            }
            return $graph;
        }

        $id = isset($patch['@id']) && is_string($patch['@id']) ? trim($patch['@id']) : '';
        if ($id !== '') {
            foreach ($graph as $index => $node) {
                if (is_array($node) && (string) ($node['@id'] ?? '') === $id) {
                    $graph[$index] = tpi_sd_merge($node, $patch);
                    return $graph;
                }
            }
            $graph[] = $patch;
            return $graph;
        }

        // 没有 @type / @id：按位置对应第 N 个节点，节点不够就新增
        if ($pos >= 0 && isset($graph[$pos]) && is_array($graph[$pos])) {
            $graph[$pos] = tpi_sd_merge($graph[$pos], $patch);
            return $graph;
        }

        $graph[] = $patch;
        return $graph;
    }
}

if (!function_exists('tpi_sd_filter_graph')) {
    /**
     * 把补丁套进 @graph
     *
     * @param array  $graph 模板生成的 @graph
     * @param string $lang  空 = 当前语言
     */
    function tpi_sd_filter_graph(array $graph, string $lang = ''): array
    {
        $graph = array_values($graph);
        if ($lang === '') {
            $lang = tpi_sd_lang();
        }

        foreach (tpi_sd_sources() as $source) {
            $parsed = tpi_sd_parse(tpi_sd_raw($source, $lang));
            if (!$parsed['nodes']) {
                continue;
            }
            foreach ($parsed['nodes'] as $pos => $patch) {
                if (!$patch) {
                    continue;   // 空对象 {} 只占位置
                }
                $graph = tpi_sd_apply_patch($graph, $patch, $parsed['sequence'] ? (int) $pos : -1);
            }
        }

        return array_values($graph);
    }
}

if (!function_exists('tpi_sd_root_extra')) {
    /**
     * 与 @graph 同级的键（例如 {"inLanguage": "zh-CN"}）→ 合并到 JSON-LD 根
     *
     * @context 交给模板，不在这里覆盖。
     */
    function tpi_sd_root_extra(string $lang = ''): array
    {
        if ($lang === '') {
            $lang = tpi_sd_lang();
        }
        $root = [];
        foreach (tpi_sd_sources() as $source) {
            $parsed = tpi_sd_parse(tpi_sd_raw($source, $lang));
            if (!$parsed['root']) {
                continue;
            }
            foreach ($parsed['root'] as $key => $value) {
                if ($key === '@graph' || $key === '@context') {
                    continue;
                }
                $root[$key] = $value;
            }
        }
        return $root;
    }
}

if (!function_exists('tpi_sd_apply')) {
    /**
     * 前台总入口：套 @graph，并把 @graph 同级的键并到根
     *
     * @param array $data 模板给的整份 JSON-LD
     */
    function tpi_sd_apply(array $data): array
    {
        $has_graph = isset($data['@graph']) && is_array($data['@graph']);
        $graph     = tpi_sd_filter_graph($has_graph ? $data['@graph'] : [$data]);
        $root      = tpi_sd_root_extra();

        if (!$has_graph) {
            // 模板给的是单个节点：包成标准的 @context + @graph
            $data = [
                '@context' => 'https://schema.org',
                '@graph'   => $graph,
            ];
        } else {
            $data['@graph'] = $graph;
        }

        foreach ($root as $key => $value) {
            $data[$key] = $value;
        }
        return $data;
    }
}

/* =========================================================
 * 五、后台状态提示
 * ========================================================= */

if (!function_exists('tpi_sd_summary')) {
    /**
     * 一段 JSON 在后台该怎么显示状态
     *
     * @return array{state:string,types:string[],error:string,count:int,root:int}
     *   state: empty（未设置）/ merge（按 @type 合并）/ position（按位置对应）/ error（语法有误）
     */
    function tpi_sd_summary(string $raw): array
    {
        if (trim($raw) === '') {
            return ['state' => 'empty', 'types' => [], 'error' => '', 'count' => 0, 'root' => 0];
        }
        $error = tpi_sd_json_error($raw);
        if ($error !== '') {
            return ['state' => 'error', 'types' => [], 'error' => $error, 'count' => 0, 'root' => 0];
        }
        $parsed = tpi_sd_parse($raw);
        $types  = [];
        $count  = 0;
        foreach ($parsed['nodes'] as $node) {
            if (!$node) {
                continue;
            }
            $count++;
            $type = isset($node['@type']) && is_string($node['@type']) ? trim((string) $node['@type']) : '';
            if ($type !== '') {
                $types[] = $type;
            }
        }
        $types = array_values(array_unique($types));

        return [
            'state' => $types ? 'merge' : 'position',
            'types' => $types,
            'error' => '',
            'count' => $count,
            'root'  => count($parsed['root']),
        ];
    }
}
