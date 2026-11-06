/**
 * 后台媒体选择一致性补丁
 *
 * 1. 经典编辑器「Image details」弹窗：
 *    - 预览图下面补一个「选择图片」按钮（核心只在图片带附件 ID 时才给 Replace）
 *    - Link To 切到 Custom URL 时解除只读并清掉「图片自己的地址」，避免拼出一个打不开的链接
 * 2. 内容页的媒体选择器：打开时预选当前图片，和核心「替换图片」一样锁定当前项
 *
 * 文案全部来自 languages/admin-system-config-{zh|en}.json 的 meta.ui.manage.media，
 * 由 inc/output/push-js.php 注入到 window.tpiMediaParity.i18n，这里不写任何中文字面量。
 */
(function ($) {
    'use strict';

    var CFG = window.tpiMediaParity || {};
    var I18N = CFG.i18n || {};

    function T(key) {
        return Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : '';
    }

    function mediaReady() {
        return !!(window.wp && wp.media && wp.media.model);
    }

    /**
     * 当前的 Image details 弹窗
     *
     * 核心有时不给 .media-modal 加 image-details 类，这里按「有 Link To 下拉」认，
     * 两种 DOM 都能命中。
     */
    function modalDetails() {
        return $('.media-modal').filter(function () {
            return $(this).find('#image-details-link-to').length > 0;
        });
    }

    /* =========================================================
     * 一、媒体库预选（各内容页选择器用）
     * ========================================================= */

    function resetSelection(frame, models) {
        var state = frame && frame.state ? frame.state() : null;
        var selection = state ? state.get('selection') : null;
        if (selection && typeof selection.reset === 'function') {
            selection.reset(models || []);
        }
    }

    function preselectByUrl(frame, url) {
        url = $.trim(url || '');
        if (url === '') {
            resetSelection(frame, []);
            return;
        }
        $.post(CFG.ajaxUrl || window.ajaxurl, {
            action: 'tpi_media_lookup',
            nonce: CFG.nonce || '',
            url: url
        }).done(function (res) {
            if (!res || !res.success || !res.data || !res.data.id) {
                return;
            }
            selectAttachment(frame, res.data.id, '');
        });
    }

    /**
     * 选中附件，并在媒体库首屏加载完成后补选
     *
     * 媒体库打开瞬间 library 还在拉取，选择会被重建的状态覆盖，
     * 表现为「明明选上了又变回未选中」。这里延迟补两次，且只在
     * 选择仍为空时补（用户自己点了别的图就不动）。
     */
    function selectAttachment(frame, id, fallbackUrl) {
        var att = wp.media.attachment(id);
        att.fetch().done(function () {
            injectIntoLibrary(frame, att);
            resetSelection(frame, [att]);
            [350, 800, 1500].forEach(function (ms) {
                window.setTimeout(function () {
                    var state = frame.state ? frame.state() : null;
                    var selection = state ? state.get('selection') : null;
                    if (selection && selection.length === 0 && typeof selection.reset === 'function') {
                        injectIntoLibrary(frame, att);
                        selection.reset([att]);
                    }
                }, ms);
            });
        }).fail(function () {
            // 附件已删或 ID 对不上：退回到按地址反查
            if (fallbackUrl) {
                preselectByUrl(frame, fallbackUrl);
            }
        });
    }

    /**
     * 老附件不在媒体库第一页时，网格里没有它的卡片，选中了也看不见。
     * 把它插到当前列表最前面，相当于原生的「当前图片」。
     *
     * 必须插入同一个模型实例：视图按 collection 里的实例渲染，
     * 另建副本会出现「selection 有值、网格里却没有选中样式」。
     */
    function injectIntoLibrary(frame, att) {
        var state = frame && frame.state ? frame.state() : null;
        var lib = state ? state.get('library') : null;
        if (!lib || typeof lib.add !== 'function' || lib.get(att.id)) {
            return;
        }
        lib.add(att, { at: 0 });
    }

    /**
     * 预选当前媒体（媒体库打开时锁定/高亮当前项）
     *
     * @param {Object} frame wp.media frame
     * @param {string} url   当前地址（空 = 清空选择）
     * @param {number} id    已知附件 ID（国旗这类字段直接存了 ID，省一次反查）
     */
    function preselect(frame, url, id) {
        if (!frame || !mediaReady()) {
            return;
        }
        id = parseInt(id || 0, 10) || 0;

        // 缓存复用的 frame：每次重新打开都按最新值选一次
        frame.__tpiPick = { url: url, id: id };
        if (!frame.__tpiPickBound) {
            frame.__tpiPickBound = true;
            frame.on('open', function () {
                var pick = frame.__tpiPick;
                if (!pick) {
                    return;
                }
                if (pick.id > 0) {
                    selectAttachment(frame, pick.id, pick.url);
                } else {
                    preselectByUrl(frame, pick.url);
                }
            });
        }

        if (id > 0) {
            selectAttachment(frame, id, url);
            return;
        }
        preselectByUrl(frame, url);
    }

    window.tpiMediaPreselect = preselect;

    /* =========================================================
     * 二、编辑器 Image details 弹窗
     * ========================================================= */

    var imageFrame = null;

    function imageModel(frame) {
        if (!frame || !frame.state) {
            return null;
        }
        var state = frame.state('image-details');
        return state ? state.get('image') : null;
    }

    function injectChooseButton() {
        var $modal = modalDetails();
        if (!$modal.length) {
            return;
        }
        var $image = $modal.find('.column-image .image');
        if (!$image.length || $image.find('.tpi-choose-image').length) {
            return;
        }
        var $actions = $image.find('.actions');
        if (!$actions.length) {
            $actions = $('<div class="actions"></div>').appendTo($image);
        }
        $('<button type="button" class="button tpi-choose-image"></button>')
            .text(T('choose-image'))
            .appendTo($actions);
    }

    function injectCustomUrlHint() {
        var $modal = modalDetails();
        var $input = $modal.find('#image-details-link-to-custom');
        if (!$input.length) {
            return;
        }
        var $setting = $input.closest('.setting');
        if (!$setting.length || $setting.next('.tpi-custom-url-hint').length) {
            return;
        }
        $('<p class="description tpi-custom-url-hint" style="display:none"></p>')
            .text(T('custom-url-hint'))
            .insertAfter($setting);
    }

    /** 只在 Link To = Custom URL 时显示输入提示 */
    function toggleHint(isCustom) {
        modalDetails().find('.tpi-custom-url-hint').toggle(!!isCustom);
    }

    /** 两个地址是不是同一个文件（忽略域名、目录前缀、尺寸后缀） */
    function sameFile(a, b) {
        a = String(a || '').split('?')[0].split('#')[0];
        b = String(b || '').split('?')[0].split('#')[0];
        if (!a || !b) {
            return false;
        }
        if (a === b) {
            return true;
        }
        var na = a.split('/').pop();
        var nb = b.split('/').pop();
        if (!na || na !== nb) {
            return false;
        }
        // 同名时再确认尺寸后缀一致（a-1024x683.jpg 与 a.jpg 算同一张）
        var strip = function (n) {
            return n.replace(/-(?:scaled|\d+x\d+)(\.[a-z0-9]+)$/i, '$1');
        };
        return strip(na) === strip(nb);
    }

    /** 图片自己的地址集合（素材库地址 / 原图地址） */
    function ownUrls(model) {
        var own = [model.get('url'), model.get('originalUrl')];
        if (model.attachment && model.attachment.get) {
            own.push(model.attachment.get('url'));
        }
        return own;
    }

    /**
     * Custom URL 下把「图片自己的地址」清掉
     *
     * 核心切到 Custom URL 后会把图片地址原样留在框里（只读），
     * 用户接着输入就拼成「图片地址 + 新地址」，链接打不开。
     * 只在框里的值确实是这张图自己的地址时才清，用户自己填的链接不动。
     */
    function clearOwnUrl(model) {
        var $input = modalDetails().find('#image-details-link-to-custom');
        if (!$input.length || model.get('link') !== 'custom') {
            return;
        }
        $input.prop('readonly', false);
        var current = $.trim($input.val() || '');
        if (current === '') {
            return;
        }
        var own = ownUrls(model);
        for (var i = 0; i < own.length; i += 1) {
            if (sameFile(current, own[i])) {
                $input.val('');
                return;
            }
        }
    }

    /**
     * Link To = Custom URL 的把关
     *
     * 核心的行为：图片链到「媒体文件」时 URL 框里填的是图片自己的地址，而且是只读的；
     * 切到 Custom URL 后框里的值仍留着这个地址，用户直接输入就成了「图片地址 + 新地址」，
     * 链接自然打不开。这里解锁、清掉自家地址，并在 Update 前把输入框的值写回模型。
     */
    function hookCustomLink(frame) {
        var model = imageModel(frame);
        if (!model || model.__tpiLinkPatched) {
            return;
        }
        model.__tpiLinkPatched = true;

        var origToJSON = model.toJSON;
        model.toJSON = function () {
            var $input = modalDetails().find('#image-details-link-to-custom');
            if (model.get('link') === 'custom' && $input.length) {
                var typed = $.trim($input.val() || '');
                if (model.get('linkUrl') !== typed) {
                    model.set('linkUrl', typed);
                }
            }
            return origToJSON.apply(this, arguments);
        };

        model.on('change:link', function () {
            var $input = modalDetails().find('#image-details-link-to-custom');
            if (!$input.length) {
                return;
            }
            injectCustomUrlHint();
            toggleHint(model.get('link') === 'custom');
            if (model.get('link') !== 'custom') {
                return;
            }
            $input.prop('readonly', false);
            clearOwnUrl(model);
            $input.trigger('focus');
        });
    }

    function onFrameOpen() {
        syncModal();
    }

    // 弹窗内容渲染时机不固定（open 时还没渲染，切换 Replace 后又会重建），统一补齐
    function syncModal() {
        injectChooseButton();
        injectCustomUrlHint();
        var model = imageFrame ? imageModel(imageFrame) : null;
        if (model) {
            toggleHint(model.get('link') === 'custom');
            clearOwnUrl(model);
        }
    }

    // media-views 可能在别的脚本里后加载，这里等到位再挂（最多 4 秒）
    var editorHooked = false;

    function hookEditor() {
        if (editorHooked || !mediaReady() || !wp.media.events) {
            return false;
        }
        editorHooked = true;
        wp.media.events.on('editor:frame-create', function (args) {
            var frame = args && args.frame;
            // 核心不给 options 留 frame 键，按 state 认这个弹窗
            if (!frame || !frame.options || frame.options.state !== 'image-details') {
                return;
            }
            imageFrame = frame;
            frame.on('open', onFrameOpen);
            hookCustomLink(frame);
        });
        return true;
    }

    $(function () {
        var tries = 0;
        (function waitMedia() {
            if (hookEditor()) {
                return;
            }
            tries += 1;
            if (tries > 40) {
                return;
            }
            window.setTimeout(waitMedia, 100);
        })();
    });

    // Image details 视图会在切换 Replace / 重新渲染后重建，用 DOM 观察兜底补齐按钮
    if (window.MutationObserver) {
        var pending = false;
        var observer = new MutationObserver(function () {
            if (pending || !document.querySelector('.media-modal')) {
                return;
            }
            pending = true;
            window.requestAnimationFrame(function () {
                pending = false;
                syncModal();
            });
        });
        $(function () {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }

    $(document).on('click', '.tpi-choose-image', function (e) {
        e.preventDefault();
        if (!mediaReady() || !imageFrame) {
            return;
        }
        var model = imageModel(imageFrame);
        if (!model) {
            return;
        }

        var picker = wp.media({
            title: T('pick-title'),
            button: { text: T('pick-button') },
            multiple: false,
            library: { type: 'image' }
        });

        picker.on('select', function () {
            var att = picker.state().get('selection').first().toJSON();
            var $modal = modalDetails();

            // 先落模型（Update 时由核心写回编辑器，带 wp-image-<id>）
            model.set({
                url: att.url,
                attachment_id: att.id,
                originalUrl: att.url,
                alt: att.alt || '',
                caption: att.caption || ''
            });
            model.attachment = wp.media.attachment(att.id);

            // 再同步弹窗里的可见字段，避免和模型对不上
            $modal.find('#image-details-alt-text').val(att.alt || '');
            $modal.find('#image-details-caption').val(att.caption || '');
            picker.close();
        });

        picker.open();

        // 选择器打开时同样锁定弹窗里当前这张图
        var attachModel = model.attachment && model.attachment.id ? model.attachment.id : model.get('attachment_id');
        preselect(picker, model.get('url') || model.get('originalUrl') || '', attachModel || 0);
    });
})(jQuery);
