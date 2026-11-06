<?php
global $paged, $total_num, $pageArray, $current_lang_json;
$omit_diff_num = 2;
$visible_omit_diff = 2;
$omit_num = 14;
$omit_diff_total = $omit_num - (($omit_diff_num+1)*2);
$content_omit = $omit_num - $omit_diff_total;
$content_omit_from = floor($content_omit / 2);
$content_omit_final = ceil($content_omit / 2);
?>
<div class="pagination-wrap">
    <div class="se-pagination">
        <button class="btn-page-num page-prev" data-num="<?php echo $paged > 1 ? $paged-1 : 1; ?>" aria-label="Page Prev"> < </button>
        <?php if ($total_num <= $omit_num) { ?>
            <?php foreach ($pageArray as $item): ?>
                <button class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num' ; ?>"
                        data-num="<?php echo $item ?>">
                    <?php echo $item ?>
                </button>
            <?php endforeach; ?>
        <?php } else { ?>
            <?php foreach (range(1, $omit_diff_num) as $item): ?>
                <button class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num' ; ?>"
                        data-num="<?php echo $item ?>">
                    <?php echo $item ?>
                </button>
            <?php endforeach; ?>
            <?php
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
            <?php if($paged > $omit_diff_num + $visible_omit_diff) { ?>
                <button class="page-num">
                    ···
                </button>
            <?php } ?>
            <?php
            foreach (range($from, $final) as $item):
                ?>
                <button class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num' ; ?>"
                        data-num="<?php echo $item ?>">
                    <?php echo $item ?>
                </button>
            <?php endforeach; ?>
            <?php if ($paged <= $total_num - $omit_diff_num - $visible_omit_diff) { ?>
                <button class="page-num" aria-label="Page <?php echo $item ?>">
                    ···
                </button>
            <?php } ?>
            <?php foreach (range($total_num - $omit_diff_num + 1, $total_num) as $item): ?>
                <button class="btn-page-num page-num <?php if ($paged == $item) echo 'current-page-num' ; ?>"
                        data-num="<?php echo $item ?>">
                    <?php echo $item ?>
                </button>
            <?php endforeach; ?>
        <?php } ?>
        <button class="btn-page-num page-next" data-num="<?php echo $paged >= $total_num ? $paged : $paged+1; ?>" aria-label="Page Next"> > </button>
    </div>
    <div class="total-pages-wrap">
        <?php echo $current_lang_json["product"]["total-pages"]. ": ". $total_num;?>
    </div>
</div>
