<?php
global $paged, $total_num, $pageArray, $current_lang_json, $host_url;

$omit_diff_num = 2;
$visible_omit_diff = 2;
$omit_num = 14;
$omit_diff_total = $omit_num - (($omit_diff_num + 1) * 2);
// 基础 URL，去掉旧的 /page/x/
$baseUrl = $host_url. $_SERVER['REQUEST_URI'];
if (str_contains($baseUrl, '/page/')) {
    $baseUrl = preg_replace('#/page/\d+/?#', '', $baseUrl);
}
?>

<div class="pagination-wrap">
    <div class="pagination">

        <!-- 上一页 -->
        <a class="btn-page-num page-num page-prev <?php echo $paged <= 1 ? 'disabled' : ''; ?>"
           href="<?php echo $paged > 1 ? ($paged - 1 > 1 ? $baseUrl . '/page/' . ($paged - 1) : $baseUrl) : $baseUrl; ?>">
            &lt;
        </a>

        <?php if ($total_num <= $omit_num) { ?>
            <?php foreach ($pageArray as $item): ?>
                <a class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num'; ?>"
                   href="<?php echo $item > 1 ? $baseUrl . '/page/' . $item : $baseUrl; ?>">
                    <?php echo $item ?>
                </a>
            <?php endforeach; ?>
        <?php } else { ?>

            <!-- 左侧固定页 -->
            <?php foreach (range(1, $omit_diff_num) as $item): ?>
                <a class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num'; ?>"
                   href="<?php echo $item > 1 ? $baseUrl . '/page/' . $item : $baseUrl; ?>">
                    <?php echo $item ?>
                </a>
            <?php endforeach; ?>

            <?php
            // 中间可变页逻辑（保持原循环逻辑）
            $from = $omit_diff_num + 1;
            $final = $omit_diff_num + 1;

            if ($paged <= $omit_diff_num + $visible_omit_diff) {
                $final = $omit_diff_total + $omit_diff_num + 1;
            } else if ($paged <= $omit_diff_total) {
                $from = $paged;
                $final = $paged + $omit_diff_total - 1;
            } else if ($paged > $total_num - $omit_diff_num - $visible_omit_diff) {
                $from = $total_num - $omit_diff_num - $omit_diff_total;
                $final = $total_num - $omit_diff_num;
            } else if ($paged > $total_num - $omit_diff_total) {
                $final = $total_num - $omit_diff_num - $visible_omit_diff;
                $from = $final - $omit_diff_total + 1;
            } else {
                $from = $paged - $visible_omit_diff;
                $final = $from + $omit_diff_total - 1;
            }
            ?>

            <!-- 左省略号 -->
            <?php if ($paged > $omit_diff_num + $visible_omit_diff) { ?>
                <div class="page-num dots">···</div>
            <?php } ?>

            <!-- 中间页 -->
            <?php foreach (range($from, $final) as $item): ?>
                <a class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num'; ?>"
                   href="<?php echo $item > 1 ? $baseUrl . '/page/' . $item : $baseUrl; ?>">
                    <?php echo $item ?>
                </a>
            <?php endforeach; ?>

            <!-- 右省略号 -->
            <?php if ($paged <= $total_num - $omit_diff_num - $visible_omit_diff) { ?>
                <div class="page-num dots">···</div>
            <?php } ?>

            <!-- 右侧固定页 -->
            <?php foreach (range($total_num - $omit_diff_num + 1, $total_num) as $item): ?>
                <a class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num'; ?>"
                   href="<?php echo $item > 1 ? $baseUrl . '/page/' . $item : $baseUrl; ?>">
                    <?php echo $item ?>
                </a>
            <?php endforeach; ?>

        <?php } ?>

        <!-- 下一页 -->
        <a class="btn-page-num page-num page-next <?php echo $paged >= $total_num ? 'disabled' : ''; ?>"
           href="<?php echo $paged < $total_num ? ($paged + 1 > 1 ? $baseUrl . '/page/' . ($paged + 1) : $baseUrl) : $baseUrl; ?>">
            &gt;
        </a>

    </div>

    <div class="total-pages-wrap">
        <?php echo $current_lang_json["product"]["total-pages"] . ": " . $total_num; ?>
    </div>
</div>
