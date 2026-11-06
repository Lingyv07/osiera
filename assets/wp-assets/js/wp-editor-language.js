/**
 * 区块编辑器侧栏「语言」面板
 *
 * 结构对齐多语言插件的做法（样式不照搬）：
 *   语言   [ English ▾ ]      ← 改的是「这条内容自己的语言」
 *   翻译   🇷🇺 Русский  标题…  [编辑]
 *          🇯🇵 日本語   —      [+ 添加]
 *
 * 数据流：
 *   改语言 → editPost({ meta: { _tpi_post_lang } }) → REST 存 meta
 *          → PHP 的 added/updated_post_meta 同步到 tpi_lang
 *   已有版本 → 直接跳那个版本的编辑地址（URL 变化）
 *   没有版本 → admin-post.php 端点创建草稿副本，再跳它的编辑页
 *
 * 语言与版本数据由 PHP 注入到 window.tpiEditorLang。
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.plugins || !wp.data || !wp.element) {
        return;
    }

    var el = wp.element.createElement;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;

    var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel)
        || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);

    // 面板 API 不可用时直接退出，页面上仍有 meta box 兜底
    if (!Panel || !wp.plugins.registerPlugin) {
        return;
    }

    var DATA = window.tpiEditorLang || {};
    var LANGS = DATA.langs || [];

    if (!LANGS.length) {
        return;
    }

    var PANEL_NAME = 'tpi-language/tpi-language';

    function nameOf(slug) {
        for (var i = 0; i < LANGS.length; i++) {
            if (LANGS[i].slug === slug) {
                return LANGS[i].name;
            }
        }
        return slug;
    }

    // 面板开合状态在 core/editor（6.6 之前叫 core/edit-post）
    function editorStoreName() {
        var names = ['core/editor', 'core/edit-post'];
        for (var i = 0; i < names.length; i++) {
            var store = wp.data.select(names[i]);
            if (store && typeof store.isEditorPanelOpened === 'function') {
                return names[i];
            }
        }
        return 'core/editor';
    }

    var STATUS_TEXT = {
        draft: '草稿',
        pending: '待审',
        private: '私密',
        future: '定时',
        publish: '已发布'
    };

    function TranslationRow(props) {
        var lang = props.lang;
        var isCurrent = lang.isCurrent;

        var action = null;
        if (lang.href && !isCurrent) {
            action = el(
                'a',
                { className: 'tpi-tr-act', href: lang.href, title: '切换到' + lang.name + '版本' },
                el('span', { className: 'dashicons dashicons-edit', 'aria-hidden': 'true' }),
                '编辑'
            );
        } else if (lang.addForm) {
            // 表单由 PHP 生成（带 nonce），这里只负责插进去
            action = el('div', {
                className: 'tpi-tr-form-wrap',
                dangerouslySetInnerHTML: { __html: lang.addForm }
            });
        }

        var meta = '';
        if (isCurrent) {
            meta = '当前';
        } else if (lang.status) {
            meta = STATUS_TEXT[lang.status] || lang.status;
        }

        return el(
            'div',
            { className: 'tpi-tr-item' + (isCurrent ? ' is-current' : '') + (lang.id ? ' has-item' : ' is-missing') },
            lang.flag ? el('img', { className: 'tpi-tr-flag', src: lang.flag, alt: '' }) : null,
            el(
                'div',
                { className: 'tpi-tr-main' },
                el(
                    'div',
                    { className: 'tpi-tr-line' },
                    el('span', { className: 'tpi-tr-name' }, lang.name),
                    el('code', { className: 'tpi-tr-code' }, lang.slug),
                    meta ? el('span', { className: 'tpi-tr-meta' }, meta) : null
                ),
                lang.title ? el('div', { className: 'tpi-tr-title' }, lang.title) : null
            ),
            action
        );
    }

    function LanguagePanel() {
        var storeName = editorStoreName();

        var meta = wp.data.useSelect(function (select) {
            var editor = select('core/editor');
            return (editor && editor.getEditedPostAttribute('meta')) || {};
        }, []);

        var opened = wp.data.useSelect(function (select) {
            var store = select(storeName);
            return store && store.isEditorPanelOpened
                ? store.isEditorPanelOpened(PANEL_NAME)
                : true;
        }, [storeName]);

        var dispatch = wp.data.useDispatch('core/editor');
        var dispatchPanel = wp.data.useDispatch(storeName);
        var tried = useRef(false);

        // 默认展开一次，让语言选择一进编辑页就能看到
        useEffect(function () {
            if (tried.current) {
                return;
            }
            tried.current = true;
            if (!opened && dispatchPanel && dispatchPanel.toggleEditorPanelOpened) {
                dispatchPanel.toggleEditorPanelOpened(PANEL_NAME);
            }
        }, [opened]);

        var current = meta._tpi_post_lang || DATA.current || '';

        function pick(event) {
            var slug = event.target.value;
            var next = {};
            Object.keys(meta).forEach(function (key) {
                next[key] = meta[key];
            });
            next._tpi_post_lang = slug;
            dispatch.editPost({ meta: next });
        }

        var children = [];

        // 1) 本条内容自己的语言
        children.push(el(
            'div',
            { className: 'tpi-tr-current', key: 'current' },
            el('label', { className: 'tpi-tr-label', htmlFor: 'tpi-tr-select' }, '语言'),
            el(
                'div',
                { className: 'tpi-tr-select-wrap' },
                current && (function () {
                    for (var i = 0; i < LANGS.length; i++) {
                        if (LANGS[i].slug === current) {
                            return el('img', { className: 'tpi-tr-flag', src: LANGS[i].flag, alt: '' });
                        }
                    }
                    return null;
                })(),
                el(
                    'select',
                    { id: 'tpi-tr-select', className: 'tpi-tr-select', value: current, onChange: pick },
                    LANGS.map(function (lang) {
                        return el('option', { key: lang.slug, value: lang.slug }, lang.name + '（' + lang.slug + '）');
                    })
                )
            )
        ));

        // 2) 其它语言版本
        children.push(el(
            'div',
            { className: 'tpi-tr-head', key: 'head' },
            el('span', { className: 'tpi-tr-label' }, '翻译')
        ));

        var others = LANGS.filter(function (lang) {
            return lang.slug !== current;
        });

        if (!DATA.hasId) {
            children.push(el(
                'p',
                { className: 'tpi-tr-hint', key: 'hint' },
                '这条内容还没保存，保存后就可以添加其它语言版本。'
            ));
        }

        others.forEach(function (lang) {
            children.push(el(TranslationRow, { key: lang.slug, lang: lang }));
        });

        return el(
            Panel,
            {
                name: 'tpi-language',
                title: current ? '语言 · ' + nameOf(current) : '语言',
                className: 'tpi-lang-panel-gb'
            },
            el('div', { className: 'tpi-tr-list' }, children)
        );
    }

    wp.plugins.registerPlugin('tpi-language', { render: LanguagePanel });

    // 原生面板出来了，隐藏底部重复的 meta box
    if (document.body) {
        document.body.classList.add('tpi-lang-panel-active');
    }
})(window.wp);
