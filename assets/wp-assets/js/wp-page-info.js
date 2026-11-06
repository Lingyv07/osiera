/**
 * 后台「页面信息设置」交互
 * - 媒体库选图 / 选视频
 * - 首页轮播增删
 * - 外链认可：可视化 / 代码 双模式
 * - 证书 JSON：可视化 / 代码 双模式
 */
(function ($) {
    'use strict';

    /* 后台界面文案：全部来自 languages/admin-system-config-{zh|en}.json
       的 meta.ui["page-info"].js，由 tables/page-info.php 用 wp_localize_script 注入。
       这里不放中文字面量，切后台语言时跟着 json 走。 */
    var LANG = window.tpiPI || {};

    function T(key) {
        return Object.prototype.hasOwnProperty.call(LANG, key) ? LANG[key] : key;
    }

    /* ================= 通用工具 ================= */
    var uid = 0;

    function nextId(prefix) {
        uid += 1;
        return prefix + '_' + uid;
    }

    function escAttr(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function val($el) {
        return $.trim($el.val() || '');
    }

    function openMedia(kind, currentUrl, onSelect) {
        if (!window.wp || !wp.media) {
            window.alert('' + T('media.not-loaded') + '');
            return;
        }
        var titles = {
            video: '' + T('media.pick-video') + '',
            file: '' + T('media.pick-file') + '',
            image: '' + T('media.pick-image') + ''
        };
        var libraries = {
            video: { type: 'video' },
            file: {
                type: [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            },
            image: { type: 'image' }
        };
        var frame = wp.media({
            title: titles[kind] || titles.image,
            button: { text: '' + T('media.use') + '' },
            multiple: false,
            library: libraries[kind] || libraries.image
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            onSelect(attachment.url || '', attachment);
        });
        frame.open();
        if (window.tpiMediaPreselect) {
            window.tpiMediaPreselect(frame, currentUrl || '');
        }
    }

    function fileName(url) {
        if (!url) return '';
        var clean = String(url).split('?')[0].split('#')[0];
        var parts = clean.split('/');
        return parts[parts.length - 1] || '';
    }

    /**
     * 把某个媒体字段的 URL 写回隐藏域 + 预览图 / 视频
     */
    function setMedia(key, url) {
        $('#' + key).val(url || '');
        var $preview = $('[data-preview="' + key + '"]');
        if (!$preview.length) return;

        var $img = $preview.find('img');
        if ($img.length) {
            $img.attr('src', url || '');
        }
        var $video = $preview.find('video');
        if ($video.length) {
            if (url) {
                $video.attr('src', url);
            } else {
                $video.removeAttr('src');
            }
            try { $video[0].load(); } catch (e) { /* ignore */ }
        }
        $preview.toggleClass('is-empty', !url);
    }

    /**
     * 初始化所有预览区的空状态
     */
    function initThumbs() {
        $('[data-preview]').each(function () {
            var key = $(this).attr('data-preview');
            var current;
            if (key === 'slide') {
                current = $(this).find('img').attr('src');
            } else {
                var $input = $('#' + key);
                current = $input.length ? $input.val() : $(this).find('img').attr('src');
            }
            $(this).toggleClass('is-empty', !current);
        });
    }

    /* ================= 媒体库按钮 ================= */
    $(document).on('click', '.pi-upload', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var key = $btn.attr('data-target');
        var kind = $btn.attr('data-library') || 'image';

        var $input = $('#' + key);

        openMedia(kind, $input.length ? $input.val() : '', function (url) {
            setMedia(key, url);
            if (kind === 'video') {
                $('#' + key).closest('.pi-media-item').find('.pi-media-desc').text(fileName(url) || '' + T('media.no-video') + '');
            }
        });
    });

    $(document).on('click', '.pi-remove', function (e) {
        e.preventDefault();
        var key = $(this).attr('data-target');
        setMedia(key, '');
        var $item = $('#' + key).closest('.pi-media-item');
        if ($item.find('video').length) {
            $item.find('.pi-media-desc').text('' + T('media.no-video') + '');
        }
    });

    /* ================= 首页轮播 ================= */
    function slideTpl(url) {
        return '' +
            '<li class="pi-slide">' +
            '  <div class="pi-slide-thumb' + (url ? '' : ' is-empty') + '" data-preview="slide">' +
            '    <img src="' + escAttr(url) + '" alt="">' +
            '    <span class="pi-thumb-hint">' + T('slide.change') + '</span>' +
            '    <button type="button" class="pi-slide-del" title="' + T('slide.remove') + '">×</button>' +
            '  </div>' +
            '  <input type="hidden" name="images_src[]" value="' + escAttr(url) + '">' +
            '  <label class="pi-slide-route">' +
            '    <span>' + T('slide.route') + '</span>' +
            '    <input type="text" name="images_route[]" value="" placeholder="/products">' +
            '  </label>' +
            '</li>';
    }

    function refreshSlideEmpty() {
        var has = $('#pi-slides .pi-slide').length > 0;
        $('#pi-slides-empty').prop('hidden', has);
    }

    $('#pi-add-slide').on('click', function (e) {
        e.preventDefault();
        openMedia('image', '', function (url) {
            $('#pi-slides').append(slideTpl(url));
            refreshSlideEmpty();
        });
    });

    $(document).on('click', '.pi-slide-thumb', function (e) {
        if ($(e.target).closest('.pi-slide-del').length) return;
        var $thumb = $(this);
        var $li = $thumb.closest('.pi-slide');
        openMedia('image', $li.find('input[name="images_src[]"]').val(), function (url) {
            $thumb.find('img').attr('src', url);
            $thumb.removeClass('is-empty');
            $li.find('input[name="images_src[]"]').val(url);
        });
    });

    $(document).on('click', '.pi-slide-del', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.confirm('' + T('slide.confirm') + '')) return;
        $(this).closest('.pi-slide').remove();
        refreshSlideEmpty();
    });

    /* ================= 外链认可 ================= */
    var $sameAsHidden = $('#pi-same-as');
    var $sameAsCode = $('#pi-same-as-code');
    var $linkList = $('#pi-link-list');

    function parseLinks(str) {
        return String(str || '')
            .split(/[;\n\r]+/)
            .map(function (s) { return $.trim(s); })
            .filter(function (s) { return s.length > 0; });
    }

    function linkRowTpl(url) {
        return '' +
            '<li class="pi-link-row">' +
            '  <span class="pi-link-idx"></span>' +
            '  <input type="url" class="pi-link-input" value="' + escAttr(url) + '" placeholder="https://example.com/company">' +
            '  <button type="button" class="button-link pi-link-del" title="' + T('link.del') + '">' + T('common.delete') + '</button>' +
            '</li>';
    }

    function linksFromDom() {
        return $linkList.find('.pi-link-input').map(function () {
            return $.trim($(this).val());
        }).get().filter(function (s) { return s.length > 0; });
    }

    function syncLinks() {
        var list = linksFromDom();
        $sameAsHidden.val(list.join('; '));
        $sameAsCode.val(list.join('; '));
        $linkList.find('.pi-link-row').each(function (i) {
            $(this).find('.pi-link-idx').text(i + 1);
        });
        $('#pi-links-empty').prop('hidden', list.length > 0);
    }

    function renderLinks(list) {
        $linkList.html((list || []).map(linkRowTpl).join(''));
        syncLinks();
    }

    $(document).on('click', '#pi-add-link', function (e) {
        e.preventDefault();
        $linkList.append(linkRowTpl(''));
        syncLinks();
        $linkList.find('.pi-link-input').last().trigger('focus');
    });

    $(document).on('input', '.pi-link-input', syncLinks);

    $(document).on('click', '.pi-link-del', function (e) {
        e.preventDefault();
        $(this).closest('.pi-link-row').remove();
        syncLinks();
    });

    $sameAsCode.on('input', function () {
        $sameAsHidden.val(parseLinks(this.value).join('; '));
    });

    /* ================= 社媒列表 ================= */
    var $socialList = $('#pi-social-list');

    function socialRowTpl(name, url, icon) {
        return '' +
            '<li class="pi-social-row">' +
            '  <span class="pi-social-drag" title="' + T('common.drag') + '" aria-hidden="true"></span>' +
            '  <span class="pi-social-icon" title="' + T('social.change-icon') + '">' +
            '    <img src="' + escAttr(icon) + '" alt="">' +
            '  </span>' +
            '  <input type="hidden" name="media_icon_url[]" value="' + escAttr(icon) + '">' +
            '  <label class="pi-social-field">' +
            '    <span>' + T('common.name') + '</span>' +
            '    <input type="text" name="media_name[]" value="' + escAttr(name) + '" placeholder="YouTube">' +
            '  </label>' +
            '  <label class="pi-social-field pi-social-field-wide">' +
            '    <span>' + T('common.url') + '</span>' +
            '    <input type="url" name="media_url[]" value="' + escAttr(url) + '" placeholder="https://www.youtube.com/@example">' +
            '  </label>' +
            '  <div class="pi-social-actions">' +
            '    <button type="button" class="button pi-social-pick">' + T('social.pick-icon') + '</button>' +
            '    <button type="button" class="button-link pi-social-del">' + T('common.delete') + '</button>' +
            '  </div>' +
            '</li>';
    }

    function refreshSocialEmpty() {
        var has = $socialList.find('.pi-social-row').length > 0;
        $('#pi-social-empty').prop('hidden', has);
    }

    function pickSocialIcon($row) {
        openMedia('image', $row.find('input[name="media_icon_url[]"]').val(), function (url) {
            $row.find('.pi-social-icon img').attr('src', url);
            $row.find('input[name="media_icon_url[]"]').val(url);
        });
    }

    $(document).on('click', '#pi-add-social', function (e) {
        e.preventDefault();
        var $row = $(socialRowTpl('', '', ''));
        $socialList.append($row);
        refreshSocialEmpty();
        $row.find('input[name="media_name[]"]').trigger('focus');
    });

    $(document).on('click', '.pi-social-pick, .pi-social-icon', function (e) {
        e.preventDefault();
        pickSocialIcon($(this).closest('.pi-social-row'));
    });

    $(document).on('click', '.pi-social-del', function (e) {
        e.preventDefault();
        if (!window.confirm('' + T('social.confirm') + '')) return;
        $(this).closest('.pi-social-row').remove();
        refreshSocialEmpty();
    });

    /**
     * 社媒拖拽排序：按住左侧手柄上下拖动，表单按 DOM 顺序提交
     */
    function initSocialSortable() {
        if (!$.fn.sortable || !$socialList.length) return;
        $socialList.sortable({
            items: '> .pi-social-row',
            handle: '.pi-social-drag',
            axis: 'y',
            cursor: 'move',
            placeholder: 'pi-social-placeholder',
            forcePlaceholderSize: true,
            tolerance: 'pointer',
            start: function (e, ui) {
                ui.item.addClass('is-dragging');
            },
            stop: function (e, ui) {
                ui.item.removeClass('is-dragging');
            }
        });
    }

    /* ================= 证书 JSON ================= */
    var HERO_KEYS = ['type', 'credential_category', 'badge_text', 'title', 'name', 'id',
        'target_href', 'image_url', 'image_alt', 'file_url', 'button_text'];
    var ITEM_KEYS = ['type', 'credential_category', 'report_no', 'title', 'name', 'id',
        'image_url', 'image_alt', 'file_url', 'standard_text', 'description_text', 'link_text'];

    var $certEditor = $('#pi-cert-editor');
    var $certCode = $('#pi-cert-code');
    var $certHidden = $('#pi-cert-json');

    function parseInitialCert() {
        try {
            var raw = document.getElementById('pi-cert-source');
            var obj = JSON.parse(raw ? raw.textContent : '{}');
            return (obj && typeof obj === 'object') ? obj : {};
        } catch (e) {
            return {};
        }
    }

    var CERT = parseInitialCert();

    function tryParseJson(str) {
        try {
            var node = JSON.parse(str);
            return (node && typeof node === 'object') ? node : null;
        } catch (e) {
            return null;
        }
    }

    function fieldTpl(label, name, value, opts) {
        opts = opts || {};
        var cls = 'pi-field' + (opts.full ? ' pi-field-full' : '');
        var ph = escAttr(opts.placeholder || '');
        var v = value === null || value === undefined ? '' : value;

        if (opts.textarea) {
            return '<label class="' + cls + '"><span>' + escAttr(label) + '</span>' +
                '<textarea data-f="' + name + '" rows="' + (opts.rows || 2) + '" placeholder="' + ph + '">' +
                escAttr(v) + '</textarea></label>';
        }
        return '<label class="' + cls + '"><span>' + escAttr(label) + '</span>' +
            '<input type="text" data-f="' + name + '" value="' + escAttr(v) + '" placeholder="' + ph + '"></label>';
    }

    function mediaFieldTpl(label, name, value, altName, altValue) {
        var id = nextId('certmedia');
        var v = value === null || value === undefined ? '' : value;
        var a = altValue === null || altValue === undefined ? '' : altValue;
        return '' +
            '<div class="pi-field pi-field-full">' +
            '  <span>' + escAttr(label) + '</span>' +
            '  <div class="pi-media-block">' +
            '    <div class="pi-media-row">' +
            '      <div class="pi-thumb pi-thumb-sm' + (v ? '' : ' is-empty') + '" data-preview="' + id + '">' +
            '        <img src="' + escAttr(v) + '" alt="">' +
            '      </div>' +
            '      <div class="pi-media-row-main">' +
            '        <input type="text" id="' + id + '" data-f="' + name + '" value="' + escAttr(v) + '" placeholder="https://...">' +
            '        <button type="button" class="button pi-upload" data-target="' + id + '" data-library="image">' + T('cert.pick-image') + '</button>' +
            '      </div>' +
            '    </div>' +
            '    <input type="text" data-f="' + altName + '" value="' + escAttr(a) + '" placeholder="' + T('cert.alt-placeholder') + '">' +
            '  </div>' +
            '</div>';
    }

    /**
     * 文件字段：URL 输入 + 媒体库选文件（PDF / Word / Excel）
     */
    function fileFieldTpl(label, name, value, opts) {
        opts = opts || {};
        var id = nextId('certfile');
        var v = value === null || value === undefined ? '' : value;
        return '' +
            '<div class="pi-field pi-field-full">' +
            '  <span>' + escAttr(label) + '</span>' +
            '  <div class="pi-media-row">' +
            '    <div class="pi-media-row-main">' +
            '      <input type="text" id="' + id + '" data-f="' + name + '" value="' + escAttr(v) + '" placeholder="' + escAttr(opts.placeholder || '' + T('cert.file-addr') + '') + '">' +
            '      <button type="button" class="button pi-upload" data-target="' + id + '" data-library="file">' + T('cert.pick-file') + '</button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
    }

    function specRowTpl(label, value) {
        return '' +
            '<div class="pi-spec-row">' +
            '  <input type="text" data-f="label" value="' + escAttr(label) + '" placeholder="' + T('cert.spec-name-placeholder') + '">' +
            '  <input type="text" data-f="value" value="' + escAttr(value) + '" placeholder="' + T('cert.spec-value-placeholder') + '">' +
            '  <button type="button" class="button-link pi-spec-del" title="' + T('cert.spec-del') + '">' + T('common.delete') + '</button>' +
            '</div>';
    }

    function renderHero(hero) {
        hero = hero || {};
        var specs = (hero.specs || []).map(function (s) {
            return specRowTpl(s && s.label, s && s.value);
        }).join('');

        return '' +
            '<div class="pi-subsection" data-scope="hero">' +
            '  <div class="pi-subhead-slim"><h4>' + T('cert.hero') + '</h4></div>' +
            '  <div class="pi-fields">' +
            fieldTpl('' + T('cert.card-title') + '', 'title', hero.title) +
            fieldTpl('' + T('cert.badge') + '', 'badge_text', hero.badge_text) +
            fieldTpl('' + T('cert.name') + '', 'name', hero.name) +
            fieldTpl('' + T('cert.anchor') + '', 'id', hero.id, { placeholder: 'iso-9001-2015' }) +
            fieldTpl('' + T('cert.category') + '', 'credential_category', hero.credential_category) +
            fieldTpl('' + T('cert.schema') + '', 'type', hero.type, { placeholder: 'EducationalOccupationalCredential' }) +
            fieldTpl('' + T('cert.verify') + '', 'target_href', hero.target_href, { placeholder: 'https://' }) +
            fieldTpl('' + T('cert.button') + '', 'button_text', hero.button_text) +
            fileFieldTpl('' + T('cert.file') + '', 'file_url', hero.file_url, { placeholder: '' + T('cert.file-placeholder') + '' }) +
            mediaFieldTpl('' + T('cert.image') + '', 'image_url', hero.image_url, 'image_alt', hero.image_alt) +
            '  </div>' +
            '  <div class="pi-subhead-slim" style="margin-top:16px;">' +
            '    <h4>' + T('cert.specs') + '</h4>' +
            '    <button type="button" class="button pi-spec-add">' + T('cert.spec-add') + '</button>' +
            '  </div>' +
            '  <div class="pi-spec-list">' + specs + '</div>' +
            '</div>';
    }

    function renderItem(item) {
        item = item || {};
        // 已有内容默认收起，便于总览；新添加的保持展开
        var collapsed = !!$.trim(item.title || '');
        return '' +
            '<div class="pi-cert-item' + (collapsed ? ' is-collapsed' : '') + '" data-scope="item">' +
            '  <div class="pi-cert-item-head" data-toggle="item">' +
            '    <span class="pi-cert-item-caret"></span>' +
            '    <strong class="pi-cert-item-name">' + escAttr(item.title || '' + T('cert.new') + '') + '</strong>' +
            '    <span class="pi-cert-item-id">' + escAttr(item.id || '') + '</span>' +
            '    <button type="button" class="pi-icon-btn pi-item-del" title="' + T('cert.item-del') + '">' + T('common.delete') + '</button>' +
            '  </div>' +
            '  <div class="pi-cert-item-body">' +
            '    <div class="pi-fields">' +
            fieldTpl('' + T('cert.card-title') + '', 'title', item.title) +
            fieldTpl('' + T('cert.name') + '', 'name', item.name) +
            fieldTpl('' + T('cert.anchor') + '', 'id', item.id, { placeholder: '' + T('cert.relate-hint') + '' }) +
            fieldTpl('' + T('cert.category') + '', 'credential_category', item.credential_category) +
            fieldTpl('' + T('cert.schema') + '', 'type', item.type, { placeholder: 'EducationalOccupationalCredential' }) +
            fieldTpl('' + T('cert.standard') + '', 'standard_text', item.standard_text, { placeholder: 'Standard: ...' }) +
            fieldTpl('' + T('cert.link-text') + '', 'link_text', item.link_text, { placeholder: 'View Report →' }) +
            fileFieldTpl('' + T('cert.file') + '', 'file_url', item.file_url, { placeholder: '' + T('cert.pdf') + '' }) +
            fieldTpl('' + T('cert.report-no') + '', 'report_no', item.report_no, { textarea: true, full: true, rows: 2, placeholder: '' + T('cert.report-no-hint') + '' }) +
            fieldTpl('' + T('cert.desc') + '', 'description_text', item.description_text, { textarea: true, full: true, rows: 2, placeholder: '' + T('cert.html-hint') + '' }) +
            mediaFieldTpl('' + T('cert.image') + '', 'image_url', item.image_url, 'image_alt', item.image_alt) +
            '    </div>' +
            '  </div>' +
            '</div>';
    }

    function renderGroup(group) {
        group = group || {};
        var items = (group.items || []).map(renderItem).join('');
        return '' +
            '<div class="pi-group" data-scope="group">' +
            '  <div class="pi-group-head">' +
            '    <label class="pi-field pi-group-title"><span>' + T('cert.group-title') + '</span>' +
            '      <input type="text" data-f="group_title" value="' + escAttr(group.group_title) + '" placeholder="' + T('cert.group-placeholder') + '">' +
            '    </label>' +
            '    <button type="button" class="pi-icon-btn pi-group-del" title="' + T('cert.group-del') + '">' + T('cert.group-del') + '</button>' +
            '  </div>' +
            '  <div class="pi-group-body">' +
            '    <div class="pi-items">' + items + '</div>' +
            '    <button type="button" class="button pi-item-add">' + T('cert.item-add') + '</button>' +
            '  </div>' +
            '</div>';
    }

    function renderIcon(icon) {
        icon = icon || {};
        var id = nextId('certicon');
        var v = icon.image_url || '';
        return '' +
            '<div class="pi-icon-row" data-scope="icon">' +
            '  <span class="pi-icon-drag" title="' + T('common.drag') + '" aria-hidden="true"></span>' +
            '  <label class="pi-field"><span>' + T('cert.icon-relate') + '</span>' +
            '    <div class="pi-combo">' +
            '      <input type="text" data-f="id" autocomplete="off" spellcheck="false" value="' + escAttr(icon.id) + '" placeholder="' + T('cert.icon-relate-hint') + '">' +
            '      <div class="pi-combo-list"></div>' +
            '    </div>' +
            '  </label>' +
            '  <div class="pi-field"><span>' + T('cert.icon') + '</span>' +
            '    <div class="pi-media-row">' +
            '      <div class="pi-thumb pi-thumb-xs' + (v ? '' : ' is-empty') + '" data-preview="' + id + '">' +
            '        <img src="' + escAttr(v) + '" alt="">' +
            '      </div>' +
            '      <div class="pi-media-row-main">' +
            '        <input type="text" id="' + id + '" data-f="image_url" value="' + escAttr(v) + '" placeholder="https://...">' +
            '        <button type="button" class="button pi-upload" data-target="' + id + '" data-library="image">' + T('cert.icon-pick') + '</button>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '  <label class="pi-field"><span>alt</span>' +
            '    <input type="text" data-f="alt" value="' + escAttr(icon.alt) + '" placeholder="ISO 9001 Logo">' +
            '  </label>' +
            '  <button type="button" class="pi-icon-btn pi-icon-del" title="' + T('cert.icon-del') + '">' + T('common.delete') + '</button>' +
            '</div>';
    }

    /**
     * 关联证书 ID 的可选值：证书区里的条目 id + hero 证书 id（hero 优先）
     */
    function certIdOptions() {
        var ids = [];
        var heroId = $.trim($('#pi-cert-editor [data-scope="hero"] [data-f="id"]').val());
        if (heroId) ids.push(heroId);
        $('#pi-cert-editor .pi-cert-item [data-f="id"]').each(function () {
            var id = $.trim($(this).val());
            if (id && ids.indexOf(id) === -1) ids.push(id);
        });
        return ids;
    }

    /* ---- 关联证书 ID 建议下拉 ----
       原生 datalist 的浮层由浏览器绘制，宽度按最长选项撑开、不受 z-index 约束，
       会越到图标列并盖住下面几行；这里改成跟随列宽的自绘下拉。
       候选一次性写进 DOM，显隐交给 CSS 的 :focus-within，
       JS 只负责过滤和取值，不依赖 focus 事件是否被别的脚本吃掉。 */
    function comboListHtml(current) {
        return certIdOptions().filter(function (id) {
            return id !== current;
        }).map(function (id) {
            return '<button type="button" tabindex="-1" data-value="' + escAttr(id) + '">' + escAttr(id) + '</button>';
        }).join('');
    }

    /** 重建所有图标行的候选（证书条目增删、改 id 后调用） */
    function refreshCombos() {
        $('#pi-cert-icons .pi-combo').each(function () {
            var $combo = $(this);
            var current = $.trim($combo.children('input').val());
            $combo.children('.pi-combo-list').html(comboListHtml(current));
        });
    }

    function expandCombo($input) {
        $input.closest('.pi-combo').removeClass('is-collapsed');
    }

    function renderCertEditor() {
        var header = CERT.page_header || {};
        var hero = CERT.hero_certificate || {};
        var groups = CERT.certificate_groups || [];
        var icons = CERT.icon_list || [];

        $certEditor.html(
            '<div class="pi-subsection" data-scope="header">' +
            '  <div class="pi-subhead-slim"><h4>' + T('cert.header') + '</h4></div>' +
            '  <div class="pi-fields">' +
            fieldTpl('' + T('cert.title') + '', 'title', header.title, { full: true }) +
            fieldTpl('' + T('cert.desc') + '', 'description', header.description, { textarea: true, full: true, rows: 3, placeholder: '' + T('cert.html-hint') + '' }) +
            '  </div>' +
            '</div>' +
            renderHero(hero) +
            '<div class="pi-subsection" data-scope="groups">' +
            '  <div class="pi-subhead-slim">' +
            '    <h4>' + T('cert.groups') + '</h4>' +
            '    <div class="pi-subhead-actions">' +
            '      <button type="button" class="button" id="pi-cert-toggle-all">' + T('cert.expand-all') + '</button>' +
            '      <button type="button" class="button" id="pi-group-add">' + T('cert.group-add') + '</button>' +
            '    </div>' +
            '  </div>' +
            '  <div id="pi-cert-groups">' + groups.map(renderGroup).join('') + '</div>' +
            '</div>' +
            '<div class="pi-subsection" data-scope="icons">' +
            '  <div class="pi-subhead-slim">' +
            '    <h4>' + T('cert.icons') + '</h4>' +
            '    <button type="button" class="button" id="pi-icon-add">' + T('cert.icon-add') + '</button>' +
            '  </div>' +
            '  <div id="pi-cert-icons">' + icons.map(renderIcon).join('') + '</div>' +
            '  <p class="pi-hint">' + T('cert.sort-hint') + '</p>' +
            '</div>'
        );

        initThumbs();
        refreshCombos();
        initIconSortable();
    }

    /**
     * 首页 / 关于我们 图标：按住左侧手柄拖动排序，保存时按 DOM 顺序写入 icon_list
     */
    function initIconSortable() {
        var $icons = $('#pi-cert-icons');
        if (!$.fn.sortable || !$icons.length) return;
        $icons.sortable({
            items: '> .pi-icon-row',
            handle: '.pi-icon-drag',
            axis: 'y',
            cursor: 'move',
            placeholder: 'pi-icon-placeholder',
            forcePlaceholderSize: true,
            tolerance: 'pointer',
            start: function (e, ui) {
                ui.item.addClass('is-dragging');
            },
            stop: function (e, ui) {
                ui.item.removeClass('is-dragging');
            }
        });
    }

    /* ---- 收集数据 ---- */
    function collectScopeFields($scope) {
        var out = {};
        $scope.find('[data-f]').each(function () {
            if ($(this).closest('.pi-spec-row').length) return;
            var closestScope = $(this).closest('[data-scope]');
            if (closestScope[0] !== $scope[0]) return;
            out[this.getAttribute('data-f')] = val($(this));
        });
        return out;
    }

    function collectSpecs($scope) {
        return $scope.find('.pi-spec-row').map(function () {
            return {
                label: val($(this).find('[data-f="label"]')),
                value: val($(this).find('[data-f="value"]'))
            };
        }).get();
    }

    function orderObj(src, keys) {
        var out = {};
        keys.forEach(function (k) {
            out[k] = src[k] === undefined ? '' : src[k];
        });
        return out;
    }

    function collectCert() {
        var $header = $certEditor.find('[data-scope="header"]');
        var $hero = $certEditor.find('[data-scope="hero"]');

        var heroFields = orderObj(collectScopeFields($hero), HERO_KEYS);
        heroFields.specs = collectSpecs($hero);

        var groups = $('#pi-cert-groups .pi-group').map(function () {
            var $group = $(this);
            return {
                group_title: val($group.find('> .pi-group-head [data-f="group_title"]')),
                items: $group.find('.pi-cert-item').map(function () {
                    var $item = $(this);
                    return orderObj(collectScopeFields($item), ITEM_KEYS);
                }).get()
            };
        }).get();

        var icons = $('#pi-cert-icons .pi-icon-row').map(function () {
            var fields = collectScopeFields($(this));
            return {
                id: fields.id || '',
                image_url: fields.image_url || '',
                alt: fields.alt || ''
            };
        }).get();

        return {
            page_header: {
                title: val($header.find('[data-f="title"]')),
                description: val($header.find('[data-f="description"]'))
            },
            hero_certificate: heroFields,
            certificate_groups: groups,
            icon_list: icons
        };
    }

    /**
     * 判断编辑器是否整体为空，避免把空结构写进数据库（会让前台渲染出空证书页）
     */
    function certIsEmpty(obj) {
        var header = obj.page_header || {};
        if ($.trim(header.title || '') || $.trim(header.description || '')) return false;

        var hero = obj.hero_certificate || {};
        var heroHasValue = false;
        Object.keys(hero).forEach(function (k) {
            if (k === 'specs') {
                if ((hero.specs || []).length) heroHasValue = true;
            } else if ($.trim(hero[k] || '')) {
                heroHasValue = true;
            }
        });
        if (heroHasValue) return false;

        if ((obj.certificate_groups || []).length) return false;
        if ((obj.icon_list || []).length) return false;
        return true;
    }

    /* ---- 编辑器内的增删 ---- */
    $(document).on('click', '#pi-group-add', function (e) {
        e.preventDefault();
        $('#pi-cert-groups').append(renderGroup({ group_title: '', items: [] }));
        initThumbs();
    });

    $(document).on('click', '.pi-group-del', function (e) {
        e.preventDefault();
        if (!window.confirm('' + T('cert.group-del-confirm') + '')) return;
        $(this).closest('.pi-group').remove();
        refreshCombos();
    });

    $(document).on('click', '.pi-item-add', function (e) {
        e.preventDefault();
        $(this).closest('.pi-group').find('.pi-items').append(renderItem({}));
        initThumbs();
    });

    $(document).on('click', '.pi-item-del', function (e) {
        e.preventDefault();
        if (!window.confirm('' + T('cert.del-confirm') + '')) return;
        $(this).closest('.pi-cert-item').remove();
        refreshCombos();
    });

    /* ---- 折叠 / 展开 ---- */
    $(document).on('click', '.pi-cert-item-head', function (e) {
        if ($(e.target).closest('.pi-item-del').length) return;
        $(this).closest('.pi-cert-item').toggleClass('is-collapsed');
    });

    $(document).on('click', '#pi-cert-toggle-all', function (e) {
        e.preventDefault();
        var $items = $('#pi-cert-groups .pi-cert-item');
        var shouldCollapse = $items.not('.is-collapsed').length > 0;
        $items.toggleClass('is-collapsed', shouldCollapse);
        $(this).text(shouldCollapse ? '' + T('cert.expand-all') + '' : '' + T('cert.collapse-all') + '');
    });

    $(document).on('click', '.pi-spec-add', function (e) {
        e.preventDefault();
        $(this).closest('.pi-subsection').find('.pi-spec-list').append(specRowTpl('', ''));
    });

    $(document).on('click', '.pi-spec-del', function (e) {
        e.preventDefault();
        $(this).closest('.pi-spec-row').remove();
    });

    $(document).on('click', '#pi-icon-add', function (e) {
        e.preventDefault();
        $('#pi-cert-icons').append(renderIcon({}));
        initThumbs();
        refreshCombos();
    });

    $(document).on('click', '.pi-icon-del', function (e) {
        e.preventDefault();
        $(this).closest('.pi-icon-row').remove();
    });

    /* ---- 关联证书 ID 建议下拉的交互 ----
       下拉的显示/隐藏由 CSS :focus-within 决定（见 wp-manage.css），
       这里只维护 is-collapsed（选完收起）与关键字过滤。 */
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (el && el.matches && el.matches('.pi-combo > input[data-f="id"]')) {
            expandCombo($(el));
        }
    }, true);

    $(document).on('click', '.pi-combo > input[data-f="id"]', function () {
        expandCombo($(this));
    });

    $(document).on('input', '.pi-combo > input[data-f="id"]', function () {
        var q = $.trim($(this).val()).toLowerCase();
        var $list = $(this).closest('.pi-combo').removeClass('is-collapsed')
            .children('.pi-combo-list');

        $list.children('button').each(function () {
            var hit = !q || $(this).attr('data-value').toLowerCase().indexOf(q) !== -1;
            $(this).toggleClass('is-hidden', !hit);
        });
        // 关键字没有命中任何证书时不留下一个空白浮层
        $list.toggleClass('is-empty', $list.children('button:not(.is-hidden)').length === 0);
    });

    // 用 mousedown 抢在输入框失焦之前赋值，避免点击瞬间下拉先收起来
    $(document).on('mousedown', '.pi-combo-list > button', function (e) {
        e.preventDefault();
        var $combo = $(this).closest('.pi-combo');
        $combo.children('input').val($(this).attr('data-value'));
        $combo.addClass('is-collapsed');
    });

    $(document).on('keydown', '.pi-combo > input[data-f="id"]', function (e) {
        if (e.key !== 'Escape') return;
        $(this).closest('.pi-combo').addClass('is-collapsed').children('input').trigger('blur');
    });

    /* ---- 标题 / 锚点实时回显 ---- */
    $(document).on('input', '.pi-cert-item [data-f="title"]', function () {
        $(this).closest('.pi-cert-item').find('.pi-cert-item-name').text($(this).val() || '' + T('cert.new') + '');
    });

    $(document).on('input', '.pi-cert-item [data-f="id"]', function () {
        $(this).closest('.pi-cert-item').find('.pi-cert-item-id').text($(this).val());
        refreshCombos();
    });

    /* ---- 代码校验 ---- */
    function validateCertCode() {
        var $status = $('#pi-cert-status');
        var raw = $certCode.val();
        if (!$.trim(raw)) {
            $status.attr('class', 'pi-code-status').text('');
            return;
        }
        try {
            JSON.parse(raw);
            $status.attr('class', 'pi-code-status is-ok').text('' + T('cert.json-ok') + '');
        } catch (err) {
            $status.attr('class', 'pi-code-status is-err').text('✗ ' + err.message);
        }
    }

    $certCode.on('input', validateCertCode);

    $('#pi-cert-format').on('click', function (e) {
        e.preventDefault();
        var parsed = tryParseJson($certCode.val());
        if (!parsed) {
            window.alert('' + T('cert.json-format-err') + '');
            return;
        }
        $certCode.val(JSON.stringify(parsed, null, 2));
        validateCertCode();
    });

    $('#pi-cert-reload').on('click', function (e) {
        e.preventDefault();
        if (!window.confirm('' + T('cert.reset-confirm') + '')) return;
        CERT = parseInitialCert();
        $certCode.val(JSON.stringify(CERT, null, 2));
        renderCertEditor();
        validateCertCode();
    });

    /* ================= Tab 切换 ================= */
    $(document).on('click', '.pi-tabs .pi-tab', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $tabs = $btn.closest('.pi-tabs');
        var key = $tabs.attr('data-tabs');
        var mode = $btn.attr('data-mode');
        var current = $tabs.find('.pi-tab.is-active').attr('data-mode');
        if (current === mode) return;

        if (key === 'same_as') {
            if (mode === 'code') {
                $sameAsCode.val(linksFromDom().join('; '));
            } else {
                renderLinks(parseLinks($sameAsCode.val()));
            }
        }

        if (key === 'cert') {
            if (mode === 'code') {
                $certCode.val(JSON.stringify(collectCert(), null, 2));
                validateCertCode();
            } else {
                var parsed = tryParseJson($certCode.val());
                if (!parsed) {
                    window.alert('' + T('cert.code-err') + '');
                    return;
                }
                CERT = parsed;
                renderCertEditor();
            }
        }

        $tabs.find('.pi-tab').removeClass('is-active');
        $btn.addClass('is-active');

        $tabs.closest('.pi-subcard').find('[data-pane="' + key + '"]').each(function () {
            $(this).toggleClass('is-active', $(this).attr('data-mode') === mode);
        });
    });

    /* ================= 提交前同步 ================= */
    $('#pi-form').on('submit', function (e) {
        // 外链认可
        var linkMode = $('.pi-tabs[data-tabs="same_as"] .pi-tab.is-active').attr('data-mode');
        var links = linkMode === 'code' ? parseLinks($sameAsCode.val()) : linksFromDom();
        $sameAsHidden.val(links.join('; '));

        // 证书 JSON
        var certMode = $('.pi-tabs[data-tabs="cert"] .pi-tab.is-active').attr('data-mode');

        if (certMode === 'code') {
            var parsed = tryParseJson($certCode.val());
            if (!parsed) {
                e.preventDefault();
                window.alert('' + T('cert.save-err') + '');
                $certCode.trigger('focus');
                return;
            }
            $certHidden.val(JSON.stringify(parsed, null, 2));
            return;
        }

        // 可视化模式：编辑器必须已渲染，否则保留原有隐藏值，避免误清空
        if (!$certEditor.children().length) return;
        var data = collectCert();
        $certHidden.val(certIsEmpty(data) ? '' : JSON.stringify(data, null, 2));
    });

    /* ================= 初始化 ================= */
    $(function () {
        initThumbs();
        refreshSlideEmpty();
        renderLinks(parseLinks($sameAsHidden.val()));
        renderCertEditor();
        initSocialSortable();
    });

})(jQuery);
