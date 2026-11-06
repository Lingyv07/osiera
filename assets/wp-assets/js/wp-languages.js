/**
 * 后台「语言」页脚本：添加行、删除行、上传国旗、拖动排序。
 * 依赖 jQuery + jquery-ui-sortable + wp.media（wp_enqueue_media 已加载）。
 */
(function ($) {
    'use strict';

    /* 界面文案由后台按当前语言下发，这里保留中文兜底 */
    var T = $.extend({
        dup: '语言代码重复：%s。每种语言只能用唯一代码，请改好后再保存。',
        dupAlert: '有重复的语言代码：%s。每种语言只能用唯一代码，改好后再保存。',
        lastOne: '至少要保留一个语言，最后一个不能删除。\n如果确实不需要这个语言，可以先添加另一个语言，再删除它。',
        delTitle: '删除「%s」？',
        delTitlePlain: '删除这个语言？',
        delTail: '保存后这个语言会从列表和前台移除，已填写的内容仍会保留，重新添加同一语言代码即可恢复。',
        mediaFail: '媒体库没能打开，请刷新页面后重试。',
        mediaTitle: '选择国旗图标',
        mediaButton: '使用这张图片',
        rename: '改语言代码等于新增一种语言，原语言下已填写的内容会一起删除：',
        renameConfirm: '确定继续？'
    }, window.tpiLangI18n || {});

    function fill(tpl, value) {
        return String(tpl || '').replace('%s', value);
    }

    var $rows = $('#tpi-lang-rows');
    if (!$rows.length) {
        return;
    }

    /* 拖动排序 */
    if ($.fn.sortable) {
        $rows.sortable({
            handle: '.lg-drag',
            axis: 'y',
            items: '> tr',
            tolerance: 'pointer',
            start: function (event, ui) {
                ui.item.addClass('is-dragging');
            },
            stop: function (event, ui) {
                ui.item.removeClass('is-dragging');
            },
            helper: function (event, item) {
                var $origin = item.children();
                var $helper = item.clone();
                $helper.children().each(function (index) {
                    $(this).css('width', $origin.eq(index).outerWidth());
                });
                return $helper.addClass('lg-row-helper');
            }
        });
    }

    /* 语言代码重复检测：重复的行标红，表格下方给出提示 */
    function checkDup() {
        var seen = {};
        var dups = {};
        $rows.children('tr').each(function () {
            var slug = $.trim($(this).find('.lg-input-slug').val()).toLowerCase();
            if (slug === '') {
                return;
            }
            if (seen[slug]) {
                dups[slug] = true;
            }
            seen[slug] = true;
        });

        $rows.children('tr').each(function () {
            var $input = $(this).find('.lg-input-slug');
            var slug = $.trim($input.val()).toLowerCase();
            $input.toggleClass('is-dup', !!dups[slug]);
        });

        var list = [];
        for (var key in dups) {
            if (Object.prototype.hasOwnProperty.call(dups, key)) {
                list.push(key);
            }
        }

        var $note = $('[data-lang-dup-note]');
        if ($note.length) {
            if (list.length) {
                $note.addClass('is-on').text(fill(T.dup, list.join('、')));
            } else {
                $note.removeClass('is-on').text('');
            }
        }
        return list;
    }

    /* 添加一行 */
    $('#tpi-lang-add').on('click', function (e) {
        e.preventDefault();
        var tpl = $('#tpi-lang-row-tpl').html();
        if (!tpl) {
            return;
        }
        var $row = $(tpl).appendTo($rows);
        $row.find('.lg-input-slug').trigger('focus');
        checkDup();
    });

    /* 删除一行 */
    $rows.on('click', '.lg-del', function (e) {
        e.preventDefault();
        if ($rows.children('tr').length <= 1) {
            window.alert(T.lastOne);
            return;
        }
        var $row = $(this).closest('tr');
        var name = $.trim($row.find('.lg-input-name, .lg-col-name .lg-input').val()) || $.trim($row.find('.lg-input-slug').val());
        var tip = name ? fill(T.delTitle, name) : T.delTitlePlain;
        if (!window.confirm(tip + '\n' + T.delTail)) {
            return;
        }
        $row.remove();
        checkDup();
    });

    /* 语言标记默认值：{语言代码}_{地区}，英语按 en_us */
    function defaultLocale(slug) {
        if (!slug) {
            return '';
        }
        return slug === 'en' ? 'en_us' : slug + '_' + slug;
    }

    /* 语言代码改动时同步默认语言的单选值，并检查重复 */
    $rows.on('input', '.lg-input-slug', function () {
        var slug = $.trim($(this).val()).toLowerCase();
        $(this).val(slug);
        var $radio = $(this).closest('tr').find('input[type="radio"]');
        var checked = $radio.prop('checked');
        $radio.val(slug);
        if (checked) {
            $radio.prop('checked', true);
        }
        /* 语言标记还空着就自动按代码补上，省得每次手填 */
        var $locale = $(this).closest('tr').find('input[name="lang_locale[]"]');
        if ($locale.length && $.trim($locale.val()) === '') {
            $locale.val(defaultLocale(slug));
        }
        checkDup();
    });

    /* 页面打开时先扫一遍（老数据也可能有重复） */
    checkDup();

    /* 上传国旗 */
    var frame = null;
    $rows.on('click', '.lg-flag-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');

        if (!window.wp || !window.wp.media) {
            window.alert(T.mediaFail);
            return;
        }

        frame = window.wp.media({
            title: T.mediaTitle,
            button: { text: T.mediaButton },
            multiple: false,
            library: { type: 'image' }
        });

        frame.off('select').on('select', function () {
            var attachment = frame.state().get('selection').first();
            if (!attachment) {
                return;
            }
            var data = attachment.toJSON();
            var url = (data.sizes && data.sizes.thumbnail) ? data.sizes.thumbnail.url : data.url;
            $row.find('.lg-flag-preview').attr('src', url);
            $row.find('input[name="lang_flag_id[]"]').val(data.id);
            $row.find('input[name="lang_flag_url[]"]').val(data.url);
        });

        frame.open();
        /* 打开时锁定当前国旗：有附件 ID 直接用，老数据只有地址就按地址反查 */
        if (window.tpiMediaPreselect) {
            window.tpiMediaPreselect(
                frame,
                $row.find('input[name="lang_flag_url[]"]').val() || '',
                $row.find('input[name="lang_flag_id[]"]').val() || 0
            );
        }
        return false;
    });

    /* 提交前校验：语言代码不能重复 */
    $('#tpi-languages-form').on('submit', function (e) {
        var dups = checkDup();
        if (dups.length) {
            e.preventDefault();
            window.alert(fill(T.dupAlert, dups.join('、')));
            return false;
        }

        var renamed = [];
        $rows.children('tr').each(function () {
            var $row = $(this);
            var slug = $.trim($row.find('.lg-input-slug').val()).toLowerCase();
            $row.find('input[type="radio"]').val(slug);
            var old = $row.attr('data-code') || '';
            if (old && slug && old !== slug) {
                renamed.push(old + ' → ' + slug);
            }
        });
        /* 改语言代码等于新建一种语言：旧代码的语言内容会被删除 */
        if (renamed.length && !window.confirm(T.rename + '\n' + renamed.join('\n') + '\n\n' + T.renameConfirm)) {
            e.preventDefault();
            return false;
        }
    });
})(jQuery);
