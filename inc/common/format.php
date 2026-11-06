<?php
/**
* 文本与时间格式化
 *
 * 卡片截断、UTC 时间，以及英文乱码 / 机器串的异常评分。
 */

/**
 * 转换 UTC 格式时间
 * @param $price_valid_until
 * @return string
 */
function utc_time_format($price_valid_until): string {
    $utc_timestamp = strtotime($price_valid_until); // 转换为时间戳
    return gmdate('Y-m-d\TH:i:s\Z', $utc_timestamp);
}

/**
 *  获得异常分数
 * @param $text
 * @return float|int
 */
function getAbnormalScore($text): float|int
{
    $text = trim($text);
    if (empty($text)) return 0;

    // --- 0. 语言过滤：包含中文或其他非拉丁字符，直接返回 0 (判定为正常) ---
    // 使用 Unicode 属性匹配：\p{Han} 匹配汉字
    // 如果你希望放行所有非英文字符，可以只针对纯英文环境运行逻辑
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $text)) {
        return 0;
    }

    // 1. 如果包含空格，判定为句子
    if (strpos($text, ' ') !== false) {
        $words = preg_split('/[\s\-\.\/0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $maxScore = 0;
        foreach ($words as $w) {
            // 只对长度大于 3 的【纯英文单词】片段进行异常分析
            if (mb_strlen($w) > 3 && preg_match('/^[A-Za-z]+$/', $w)) {
                $maxScore = max($maxScore, getAbnormalScore($w));
            }
        }
        return $maxScore * 0.7;
    }

    // --- 以下逻辑仅对纯英文字符串生效 ---
    $len = mb_strlen($text); // 使用多字节长度
    if ($len === 0) return 0;

    $score = 0;

    // --- 策略 A: 大小写乱序 ---
    $caseSwitches = 0;
    for ($i = 1; $i < $len; $i++) {
        $char1 = $text[$i];
        $char2 = $text[$i-1];
        if (ctype_alpha($char1) && ctype_alpha($char2)) {
            if (ctype_upper($char1) !== ctype_upper($char2)) {
                $caseSwitches++;
            }
        }
    }
    $switchRate = $caseSwitches / $len;
    if ($switchRate > 0.35) {
        $score += 50;
    }

    // --- 策略 B: 辅音堆积 (只匹配英文辅音) ---
    // 明确指定辅音字母范围，避免匹配到其他语言字符
    if (preg_match('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]{4,}/u', $text)) {
        $score += 40;
    }

    // --- 策略 C: 字符熵 ---
    // UTF-8 兼容的唯一字符计数
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $uniqueChars = count(array_unique($chars));
    $uniqueRate = $uniqueChars / $len;
    if ($uniqueRate > 0.8 && $len > 10) {
        $score += 30;
    }

    // --- 策略 D: 局部结构异常 (aBa 模式) ---
    preg_match_all('/[a-z][A-Z][a-z]/u', $text, $m);
    if (count($m[0]) > 2) {
        $score += 30;
    }

    return $score;
}

function isAbnormal($text): bool
{
    // 如果包含中文，直接判定为不异常
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $text)) {
        return false;
    }

    $score = getAbnormalScore($text);
    return $score > 60;
}

/**
 * 列表卡片上的文字截断
 *
 * 卡片描述在 CSS 里已经用 -webkit-line-clamp 截到 2~3 行，
 * 但 HTML 里原来输出的是整段摘要；首页 12 个产品铺两遍就是十几 KB 的隐形体积。
 * 这里按「看得见的长度」先截一次，视觉不变，页面明显变小。
 *
 * @param string $text  原文（可含 HTML，会被剥掉）
 * @param int    $limit 保留字符数
 * @return string
 */
function tpi_trim_text(string $text, int $limit = 160): string {
    $text = trim(wp_strip_all_tags($text));
    if ($text === '' || $limit <= 0 || mb_strlen($text) <= $limit) {
        return $text;
    }
    $cut = mb_substr($text, 0, $limit);
    // 英文尽量断在空格上，别把单词切成两半；中文没有空格，直接截
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $limit * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " \t\n\r\0\x0B,.;:，。；：、") . '…';
}
