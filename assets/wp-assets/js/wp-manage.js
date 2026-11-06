/**
 * inquiry 部分
 * 1. 询盘内容「显示全文 / 收起」
 * 2. 邮箱 / 电话一键复制（文本本身保持可拖选，不再是链接）
 */
(function () {
    const syncs = [];

    document.querySelectorAll(".inquiry-toggle").forEach((btn) => {
        const row = btn.closest(".inquiry-row");
        const text = row ? row.querySelector(".inquiry-message-text") : null;
        if (!row || !text) {
            return;
        }

        const labelOpen = btn.getAttribute("data-label-open") || "显示全文";
        const labelClose = btn.getAttribute("data-label-close") || "收起";

        btn.addEventListener("click", () => {
            const opened = row.classList.toggle("is-open");
            btn.textContent = opened ? labelClose : labelOpen;
        });

        /**
         * 只有真的被截断（收起状态两行放不下）才留按钮。
         * 光看字符数会误判：同样 90 个字，窄屏三行、宽屏正好两行，
         * 后者点开和收起长得一模一样。
         */
        const sync = () => {
            if (row.classList.contains("is-open")) {
                btn.hidden = false;
                return;
            }
            btn.hidden = text.scrollHeight - text.clientHeight <= 1;
        };

        syncs.push(sync);
        sync();

        // 字体加载完宽度会变，再测一次
        if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === "function") {
            document.fonts.ready.then(sync);
        }
        window.setTimeout(sync, 150);
    });

    if (syncs.length) {
        let timer = 0;
        window.addEventListener("resize", () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => syncs.forEach((fn) => fn()), 120);
        });
    }
})();

document.querySelectorAll(".inquiry-copy").forEach((btn) => {
    btn.addEventListener("click", () => {
        const text = btn.getAttribute("data-copy") || "";
        const icon = btn.querySelector(".dashicons");

        const feedback = () => {
            btn.classList.add("is-copied");
            btn.setAttribute("title", "已复制");
            if (icon) {
                icon.classList.remove("dashicons-admin-page");
                icon.classList.add("dashicons-yes");
            }
            window.setTimeout(() => {
                btn.classList.remove("is-copied");
                btn.setAttribute("title", "复制");
                if (icon) {
                    icon.classList.remove("dashicons-yes");
                    icon.classList.add("dashicons-admin-page");
                }
            }, 1200);
        };

        const fallback = () => {
            const ta = document.createElement("textarea");
            ta.value = text;
            ta.setAttribute("readonly", "");
            ta.style.position = "fixed";
            ta.style.top = "-1000px";
            ta.style.opacity = "0";
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand("copy");
                feedback();
            } catch (e) {
                // 复制失败时静默，保留原文可手动选取
            }
            document.body.removeChild(ta);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(feedback).catch(fallback);
        } else {
            fallback();
        }
    });
});

/**
 * IndexNow 部分
 * 1. 「获取全部 URL」把已发布链接填进清单
 * 2. 实时统计清单里的 URL 条数
 */
(function () {
    const allUrl = document.querySelector("#all_url");
    const urls = document.querySelector("#urls");
    const getBtn = document.querySelector("#get_urls_btn");
    const counter = document.querySelector("#ix-url-count");

    const parseUrls = (value) =>
        String(value || "")
            .split(/[;\n]/)
            .map((item) => item.trim())
            .filter(Boolean);

    const refreshCount = () => {
        if (!urls || !counter) {
            return;
        }
        const total = parseUrls(urls.value).length;
        counter.textContent = total + " " + (((window.tpiManage || {})["item-unit"]) || "");
        counter.classList.toggle("is-empty", total === 0);
    };

    if (getBtn && allUrl && urls) {
        getBtn.addEventListener("click", () => {
            urls.value = parseUrls(allUrl.value).join(";\n");
            refreshCount();
        });
    }

    if (urls) {
        urls.addEventListener("input", refreshCount);
        refreshCount();
    }
})();

/**
 * SMTP 密码显示 / 隐藏
 */
document.querySelectorAll("[data-toggle-password]").forEach((btn) => {
    btn.addEventListener("click", () => {
        const input = document.getElementById(btn.getAttribute("data-toggle-password"));
        if (!input) {
            return;
        }
        const toText = input.type === "password";
        input.type = toText ? "text" : "password";
        btn.textContent = toText ? "隐藏" : "显示";
    });
});

/**
 * 分类目录：把「描述」列里的 SEO JSON 渲染成可读结构
 * 原始 JSON 仍完整保留，点「查看原文 JSON」可展开，不影响编辑与保存
 */
(function () {
    const cells = document.querySelectorAll("td.column-description");
    if (!cells.length) {
        return;
    }

    const LABELS = {
        title: "SEO 标题",
        description: "SEO 描述",
        breadcrumb_list_name: "面包屑名称",
    };

    // 描述经过 wptexturize，直引号会变成弯引号，解析前先还原
    const normalize = (value) =>
        String(value || "")
            .replace(/[\u201c\u201d]/g, '"')
            .replace(/[\u2018\u2019]/g, "'")
            .replace(/[\u00a0\u200b\ufeff]/g, " ");

    const decode = (value) => {
        const ta = document.createElement("textarea");
        ta.innerHTML = String(value == null ? "" : value);
        return ta.value;
    };

    cells.forEach((cell) => {
        const raw = (cell.textContent || "").trim();
        if (!raw || raw.charAt(0) !== "{") {
            return;
        }

        let data;
        try {
            data = JSON.parse(normalize(raw));
        } catch (e) {
            return;
        }
        if (!data || typeof data !== "object" || Array.isArray(data)) {
            return;
        }

        const box = document.createElement("div");
        box.className = "pit-seo";

        Object.keys(data).forEach((key) => {
            const row = document.createElement("p");
            row.className = "pit-seo-row";

            const keyEl = document.createElement("span");
            keyEl.className = "pit-seo-k";
            keyEl.textContent = LABELS[key] || key;

            const valEl = document.createElement("span");
            valEl.className = "pit-seo-v";
            valEl.textContent = decode(data[key]);

            row.appendChild(keyEl);
            row.appendChild(valEl);
            box.appendChild(row);
        });

        const rawBox = document.createElement("pre");
        rawBox.className = "pit-seo-raw";
        rawBox.hidden = true;
        rawBox.textContent = raw;

        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "pit-seo-toggle";
        toggle.textContent = "查看原文 JSON";
        toggle.addEventListener("click", () => {
            rawBox.hidden = !rawBox.hidden;
            toggle.textContent = rawBox.hidden ? "查看原文 JSON" : "收起原文";
        });

        box.appendChild(toggle);
        box.appendChild(rawBox);

        cell.textContent = "";
        cell.appendChild(box);
    });
})();

/**
 * 分类法（分类 / 标签 / 产品分类 / 应用分类 / FAQ 分类）：描述字段的 JSON 可视化编辑
 * 描述在数据库里存的是 SEO 用的 JSON（title / description / breadcrumb_list_name），
 * 这里拆成结构化表单，提交时再序列化回 textarea，保证前端读取逻辑不变。
 * 已有描述但不是 JSON 的（旧数据、普通文案）保持原样，不接管编辑器。
 */
(function () {
    const body = document.body;
    if (!body || !(body.classList.contains("edit-tags-php") || body.classList.contains("term-php"))) {
        return;
    }

    // 描述存 SEO JSON 的分类法；标签等自由文本分类法保持原生文本框
    const JSON_TAXONOMIES = ["category", "product_cat", "application_cat", "faq_cat"];
    const taxonomy = new URLSearchParams(window.location.search).get("taxonomy") || "";
    if (!JSON_TAXONOMIES.includes(taxonomy)) {
        return;
    }

    const FIELDS = [
        { key: "title", label: "SEO 标题", type: "input", max: 60, placeholder: "搜索结果里显示的标题" },
        { key: "description", label: "SEO 描述", type: "textarea", max: 160, placeholder: "搜索结果里显示的摘要" },
        { key: "breadcrumb_list_name", label: "面包屑名称", type: "input", max: 0, placeholder: "面包屑导航里显示的分类名" },
    ];

    const normalize = (value) => String(value == null ? "" : value).replace(/[\u201c\u201d]/g, '"');

    const parseJson = (raw) => {
        const text = String(raw == null ? "" : raw).trim();
        if (!text || text.charAt(0) !== "{") {
            return null;
        }
        try {
            const data = JSON.parse(normalize(text));
            return data && typeof data === "object" && !Array.isArray(data) ? data : null;
        } catch (e) {
            return null;
        }
    };

    const textarea = document.querySelector('textarea[name="description"]');
    if (!textarea) {
        return;
    }

    // 存量内容是普通文案（非 JSON）时不动它，避免原文被结构化表单遮住
    const origin = String(textarea.value || "").trim();
    if (origin && origin.charAt(0) !== "{") {
        return;
    }

    const form = textarea.closest("form");
    const base = parseJson(textarea.value) || {};
    const inputs = {};
    let touched = false;
    let rawTouched = false;

    const wrap = document.createElement("div");
    wrap.className = "pit-json";

    const grid = document.createElement("div");
    grid.className = "pit-json-grid";

    FIELDS.forEach((def) => {
        const label = document.createElement("label");
        label.className = "pit-json-field";

        const head = document.createElement("span");
        const name = document.createElement("span");
        name.textContent = def.label;
        head.appendChild(name);

        let counter = null;
        if (def.max) {
            counter = document.createElement("span");
            counter.className = "pit-json-count";
            head.appendChild(counter);
        }

        const input = document.createElement(def.type === "textarea" ? "textarea" : "input");
        if (def.type === "textarea") {
            input.rows = 3;
        } else {
            input.type = "text";
        }
        input.placeholder = def.placeholder;
        input.value = typeof base[def.key] === "string" ? base[def.key] : "";
        input.name = "pit_" + def.key;

        const refresh = () => {
            if (!counter) {
                return;
            }
            const len = input.value.trim().length;
            counter.textContent = len + " / " + def.max;
            counter.classList.toggle("is-warn", len > def.max);
        };

        input.addEventListener("input", () => {
            touched = true;
            refresh();
        });

        label.appendChild(head);
        label.appendChild(input);
        grid.appendChild(label);
        inputs[def.key] = input;
        refresh();
    });

    const raw = document.createElement("textarea");
    raw.className = "pit-json-raw";
    raw.hidden = true;
    raw.spellcheck = false;

    const foot = document.createElement("div");
    foot.className = "pit-json-foot";

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "pit-json-toggle";

    const note = document.createElement("span");
    note.className = "pit-json-note";

    foot.appendChild(toggle);
    foot.appendChild(note);

    const collect = () => {
        const data = Object.assign({}, base);
        FIELDS.forEach((def) => {
            const value = inputs[def.key].value.trim();
            if (value) {
                data[def.key] = value;
            } else {
                delete data[def.key];
            }
        });
        return data;
    };

    const syncToField = () => {
        const data = collect();
        textarea.value = Object.keys(data).length ? JSON.stringify(data, null, 2) : "";
    };

    const setRawVisible = (visible) => {
        raw.hidden = !visible;
        toggle.textContent = visible ? "收起原始 JSON" : "查看 / 编辑原始 JSON";
        note.textContent = visible
            ? "直接改 JSON 后点「收起」会回写到上面的字段"
            : "保存时按结构化内容写回 JSON";
        if (visible) {
            raw.value = textarea.value;
        }
    };

    toggle.addEventListener("click", () => {
        const willShow = raw.hidden;
        if (!willShow) {
            const parsed = parseJson(raw.value);
            if (parsed) {
                Object.keys(base).forEach((key) => {
                    delete base[key];
                });
                Object.assign(base, parsed);
                FIELDS.forEach((def) => {
                    inputs[def.key].value = typeof base[def.key] === "string" ? base[def.key] : "";
                });
                touched = false;
                rawTouched = false;
                syncToField();
            } else if (raw.value.trim()) {
                note.textContent = "JSON 格式有误，已保留原文，请检查后再收起";
                return;
            } else {
                rawTouched = false;
                syncToField();
            }
        }
        setRawVisible(willShow);
    });

    raw.addEventListener("input", () => {
        rawTouched = true;
        textarea.value = raw.value;
    });

    if (form) {
        form.addEventListener("submit", () => {
            if (rawTouched) {
                textarea.value = raw.value;
            } else if (touched) {
                syncToField();
            }
        });
    }

    wrap.appendChild(grid);
    wrap.appendChild(foot);
    wrap.appendChild(raw);
    textarea.parentNode.insertBefore(wrap, textarea.nextSibling);
    textarea.style.display = "none";

    setRawVisible(false);

    // 加分类成功后 WP 会重置表单，这里同步清空结构化字段
    if (form) {
        form.addEventListener("reset", () => {
            FIELDS.forEach((def) => {
                inputs[def.key].value = "";
            });
            Object.keys(base).forEach((key) => {
                delete base[key];
            });
            raw.value = "";
            touched = false;
            rawTouched = false;
            setRawVisible(false);
        });
    }
})();

/**
 * 列表页「快速编辑」回填语言
 * 快速编辑表单是同一条 <tr> 里复制出来的，语言下拉在打开时从语言列取值
 */
(function () {
    if (!window.jQuery) return;
    var $ = window.jQuery;

    $(document).on("click", "#the-list a.editinline, #the-list button.editinline", function () {
        var $row = $(this).closest("tr");
        var postId = ($row.attr("id") || "").replace("post-", "");
        var lang = $row.find(".column-tpi_lang .tpi-lang-cell").attr("data-lang") || "";

        // 等核心把快速编辑表单填好再覆盖，否则会被它清空
        window.setTimeout(function () {
            var $form = $("#edit-" + postId);
            var $pick = $form.find('input[name="tpi_quick_lang"]').closest(".tpi-langpick");
            if ($pick.length && window.tpiSetLangPick) {
                window.tpiSetLangPick($pick, lang);
            }
        }, 60);
    });
})();

/**
 * 带国旗的语言选择器（分类新增 / 编辑）
 *
 * 原生 <select> 的 <option> 里放不了图片，所以这里自己画一个：
 * 点击展开列表，选项前带国旗，选完写回隐藏 input（表单照常提交）。
 * 事件都挂 document 上，动态插入的节点也能用。
 */
(function () {
    if (!window.jQuery) return;
    var $ = window.jQuery;

    function closeAll(keep) {
        $(".tpi-langpick.is-open").each(function () {
            if (keep && this === keep[0]) return;
            $(this).removeClass("is-open")
                .find(".tpi-langpick-toggle")
                .attr("aria-expanded", "false");
        });
    }

    /**
     * 把某个 .tpi-langpick 设置成指定语言（按钮上的旗、名称、代码、选中态一起同步）
     * @param {jQuery} $pick
     * @param {string} value 语言代码，空串表示「— 不修改 —」
     */
    function applyPickValue($pick, value) {
        var $item = $pick.find('.tpi-langpick-item[data-value="' + value + '"]');
        if (!$item.length) {
            $item = $pick.find(".tpi-langpick-item").first();
        }
        var real = $item.attr("data-value") || "";
        var name = $item.attr("data-name") || real;
        var flag = $item.attr("data-flag") || "";
        var $toggle = $pick.find(".tpi-langpick-toggle");

        $pick.find('input[type="hidden"]').val(real);
        if (real) {
            $toggle.find(".tpi-langpick-flag").attr("src", flag);
            $toggle.find(".tpi-langpick-name").removeClass("is-empty").text(name);
            if (!$toggle.find(".tpi-langpick-code").length) {
                $toggle.find(".tpi-langpick-name").after('<code class="tpi-langpick-code"></code>');
            }
            $toggle.find(".tpi-langpick-code").text(real);
        } else {
            $toggle.find(".tpi-langpick-flag").remove();
            $toggle.find(".tpi-langpick-code").remove();
            $toggle.find(".tpi-langpick-name").addClass("is-empty").text(name);
        }
        $pick.find(".tpi-langpick-item").removeClass("is-selected").attr("aria-selected", "false");
        $item.addClass("is-selected").attr("aria-selected", "true");
    }

    // 供快速编辑回填使用
    window.tpiSetLangPick = function ($pick, value) {
        if (!$pick || !$pick.length) return;
        applyPickValue($pick, value || "");
    };

    $(document).on("click", ".tpi-langpick-toggle", function (event) {
        event.preventDefault();
        event.stopPropagation();
        var $pick = $(this).closest(".tpi-langpick");
        var open = !$pick.hasClass("is-open");
        closeAll($pick);
        $pick.toggleClass("is-open", open);
        $(this).attr("aria-expanded", open ? "true" : "false");
    });

    $(document).on("click", ".tpi-langpick-item", function (event) {
        event.preventDefault();
        var $item = $(this);
        var $pick = $item.closest(".tpi-langpick");

        applyPickValue($pick, $item.attr("data-value") || "");
        $pick.find('input[type="hidden"]').trigger("change");

        closeAll(null);
    });

    $(document).on("click", function (event) {
        if (!$(event.target).closest(".tpi-langpick").length) {
            closeAll(null);
        }
    });

    $(document).on("keydown", function (event) {
        if (event.key === "Escape") {
            closeAll(null);
        }
    });
})();

/**
 * 「显示选项 / 帮助」按钮
 *
 * 保持核心的 DOM 顺序（#screen-meta 面板在前、#screen-meta-links 按钮在后），
 * 面板展开时按钮自然贴在面板底部右侧，不做任何节点搬移。
 * 偏移只由 CSS 控制（.wp-manage.css 里 #screen-meta-links 的 margin-right）。
 */


/**
 * 「语言内容」页的列表编辑
 *
 * 每个列表项渲染时已经带好正确的 name（lc[分组][列表][序号][字段]），
 * 这里只负责增删 / 拖动 / 重新编号，保证提交时下标连续、顺序不乱。
 */
(function () {
    /* 「填入系统模板」只是这一次的临时视图：把 fill 参数从地址栏抹掉，
       刷新页面或切换语言后就回到数据库里已保存的内容 */
    if (/[?&]fill=template(&|$)/.test(window.location.search)) {
        try {
            var clean = new URL(window.location.href);
            clean.searchParams.delete("fill");
            window.history.replaceState(null, "", clean.toString());
        } catch (e) {
            /* 老浏览器忽略即可 */
        }
    }

    function reindex(list) {
        var nameBase = list.getAttribute("data-lc-name") || "";
        var items = list.querySelectorAll(".lc-items > .lc-item");
        Array.prototype.forEach.call(items, function (item, index) {
            Array.prototype.forEach.call(item.querySelectorAll("[name]"), function (field) {
                field.name = field.name.replace(/^.*?\[(\d+)\]/, nameBase + "[" + index + "]");
            });
        });
    }

    /* 拖动排序：jquery-ui-sortable 只在语言内容页加载 */
    function initSortable() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.sortable) {
            return;
        }
        var $ = window.jQuery;
        $(".lc-list .lc-items").each(function () {
            var $box = $(this);
            if ($box.data("lc-sortable")) {
                return;
            }
            $box.data("lc-sortable", true);
            /* 两栏网格（图标型列表）不能锁 Y 轴，否则左右换位拖不动 */
            var isGrid = $box.hasClass("lc-items-icon");
            $box.sortable({
                handle: ".lc-item-drag",
                items: "> .lc-item",
                axis: isGrid ? false : "y",
                tolerance: "pointer",
                placeholder: "lc-item-placeholder",
                forcePlaceholderSize: true,
                start: function (event, ui) {
                    ui.item.addClass("is-dragging");
                    if (ui.placeholder) {
                        ui.placeholder.height(ui.item.outerHeight(true));
                    }
                },
                change: function (event, ui) {
                    /* 每项高度不一定相同，换位后重新对齐占位高度 */
                    if (ui.placeholder && ui.item) {
                        ui.placeholder.height(ui.item.outerHeight(true));
                    }
                },
                stop: function (event, ui) {
                    ui.item.removeClass("is-dragging");
                    if (ui.placeholder) {
                        ui.placeholder.css("height", "");
                    }
                    var list = ui.item.closest(".lc-list")[0];
                    if (list) {
                        reindex(list);
                    }
                }
            });
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initSortable);
    } else {
        initSortable();
    }

    document.addEventListener("click", function (event) {
        var addBtn = event.target.closest(".lc-add");
        if (addBtn) {
            var list = addBtn.closest(".lc-list");
            var tpl = list ? list.querySelector(".lc-item-tpl") : null;
            if (!list || !tpl || !tpl.content) {
                return;
            }
            var node = tpl.content.firstElementChild.cloneNode(true);
            Array.prototype.forEach.call(node.querySelectorAll("input, textarea"), function (field) {
                field.value = "";
            });
            list.querySelector(".lc-items").appendChild(node);
            reindex(list);
            initSortable();
            return;
        }

        var delBtn = event.target.closest(".lc-del");
        if (delBtn) {
            var item = delBtn.closest(".lc-item");
            var owner = delBtn.closest(".lc-list");
            if (item && owner) {
                event.preventDefault();
                item.parentNode.removeChild(item);
                reindex(owner);
            }
        }
    });
})();

/**
 * 标签类字段（关键词 / 擅长领域）
 *
 * 输入框里回车或逗号即生成一个标签块，标签块带隐藏 input 直接参与表单提交；
 * 标签上点 × 删除。表单提交时把输入框里没来得及回车的内容也补成标签。
 */
(function () {
    function addTag(box, text) {
        text = (text || "").replace(/\s+/g, " ").trim().replace(/[,，]+$/, "").trim();
        if (text === "") {
            return;
        }
        var listEl = box.querySelector("[data-lc-tag-list]");
        var input = box.querySelector("[data-lc-tag-input]");
        if (!listEl || !input) {
            return;
        }
        var name = input.getAttribute("data-lc-tag-name") || "";
        Array.prototype.forEach.call(listEl.querySelectorAll(".lc-tag-text"), function (node) {
            if (node.textContent === text) {
                text = "";
            }
        });
        if (text === "") {
            return;
        }
        var tag = document.createElement("span");
        tag.className = "lc-tag";

        var hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = name + "[]";
        hidden.value = text;

        var label = document.createElement("span");
        label.className = "lc-tag-text";
        label.textContent = text;

        var del = document.createElement("button");
        del.type = "button";
        del.className = "lc-tag-del";
        del.setAttribute("aria-label", "删除");
        del.textContent = "×";

        tag.appendChild(hidden);
        tag.appendChild(label);
        tag.appendChild(del);
        listEl.appendChild(tag);
    }

    document.addEventListener("keydown", function (event) {
        var input = event.target.closest("[data-lc-tag-input]");
        if (!input) {
            return;
        }
        if (event.key === "Enter" || event.key === "," || event.key === "，") {
            event.preventDefault();
            addTag(input.closest("[data-lc-tags]"), input.value);
            input.value = "";
        } else if (event.key === "Backspace" && input.value === "") {
            var box = input.closest("[data-lc-tags]");
            var tags = box ? box.querySelectorAll(".lc-tag") : [];
            if (tags.length) {
                tags[tags.length - 1].parentNode.removeChild(tags[tags.length - 1]);
            }
        }
    });

    document.addEventListener("blur", function (event) {
        var input = event.target.closest ? event.target.closest("[data-lc-tag-input]") : null;
        if (!input) {
            return;
        }
        addTag(input.closest("[data-lc-tags]"), input.value);
        input.value = "";
    }, true);

    document.addEventListener("click", function (event) {
        var del = event.target.closest(".lc-tag-del");
        if (del) {
            var tag = del.closest(".lc-tag");
            if (tag) {
                event.preventDefault();
                tag.parentNode.removeChild(tag);
            }
        }
    });

    document.addEventListener("submit", function () {
        Array.prototype.forEach.call(document.querySelectorAll("[data-lc-tags]"), function (box) {
            var input = box.querySelector("[data-lc-tag-input]");
            if (input) {
                addTag(box, input.value);
                input.value = "";
            }
        });
    });
})();

/**
 * 文件类字段：点「选择文件」打开媒体库
 */
(function () {
    document.addEventListener("click", function (event) {
        var pick = event.target.closest(".lc-file-pick");
        if (pick) {
            event.preventDefault();
            var box = pick.closest("[data-lc-file]");
            if (!box || !window.wp || !wp.media) {
                return;
            }
            var frame = wp.media({
                title: "选择文件",
                button: { text: "使用这个文件" },
                multiple: false
            });
            frame.on("select", function () {
                var file = frame.state().get("selection").first().toJSON();
                var url = file.url || "";
                var pathInput = box.querySelector(".lc-file-path");
                if (pathInput) {
                    pathInput.value = url;
                }
                var thumb = box.querySelector("[data-lc-file-thumb]");
                if (thumb) {
                    var isImage = /\.(png|jpe?g|gif|webp|svg|avif)$/i.test(url) || (file.type || "").indexOf("image") === 0;
                    thumb.innerHTML = "";
                    if (isImage) {
                        var img = document.createElement("img");
                        img.src = file.sizes && file.sizes.thumbnail ? file.sizes.thumbnail.url : url;
                        img.alt = "";
                        thumb.appendChild(img);
                    } else {
                        var span = document.createElement("span");
                        span.className = "lc-file-none";
                        span.textContent = file.filename || "已选择";
                        thumb.appendChild(span);
                    }
                }
            });
            frame.open();
            /* 打开时锁定当前文件，避免主按钮一直是灰的 */
            if (window.tpiMediaPreselect) {
                var currentPath = box.querySelector(".lc-file-path");
                window.tpiMediaPreselect(frame, currentPath ? currentPath.value : "");
            }
            return;
        }

        var clear = event.target.closest(".lc-file-clear");
        if (clear) {
            event.preventDefault();
            var wrap = clear.closest("[data-lc-file]");
            if (!wrap) {
                return;
            }
            var input = wrap.querySelector(".lc-file-path");
            if (input) {
                input.value = "";
            }
            var thumb = wrap.querySelector("[data-lc-file-thumb]");
            if (thumb) {
                thumb.innerHTML = '<span class="lc-file-none">未选择</span>';
            }
        }
    });

    /* 手填路径时同步缩略图 */
    document.addEventListener("change", function (event) {
        var input = event.target.closest ? event.target.closest(".lc-file-path") : null;
        if (!input) {
            return;
        }
        var thumb = input.closest("[data-lc-file]").querySelector("[data-lc-file-thumb]");
        if (!thumb) {
            return;
        }
        var value = input.value.trim();
        thumb.innerHTML = "";
        if (value === "") {
            thumb.innerHTML = '<span class="lc-file-none">未选择</span>';
            return;
        }
        var img = document.createElement("img");
        img.src = value;
        img.alt = "";
        thumb.appendChild(img);
    });
})();

/**
 * JSON 编辑器的细节：字数统计、Tab 缩进、提交前语法校验
 *
 * 「语言内容」和「后台标语」两个页面的 JSON 模式共用；
 * 校验只在 JSON 模式（页面上存在对应 textarea）时生效，可视化模式不受影响。
 */
(function () {
    var areas = [
        { id: 'lc-json', name: 'lc_json', form: 'lc-form' },
        { id: 'slogan-json', name: 'slogan_json', form: 'slogan-form' }
    ];

    areas.forEach(function (conf) {
        var box = document.getElementById(conf.id);
        if (!box) {
            return;
        }

        var counter = document.querySelector('[data-' + conf.id + '-len]') ||
            box.closest('.ad-card-body').querySelector('[data-lc-json-len], [data-slogan-json-len]');

        function refresh() {
            if (!counter) {
                return;
            }
            var lines = box.value === '' ? 0 : box.value.split('\n').length;
            counter.textContent = box.value.length.toLocaleString('en-US') + ' 字符 · ' + lines + ' 行';
        }

        box.addEventListener('input', refresh);
        refresh();

        box.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab') {
                return;
            }
            event.preventDefault();
            var start = box.selectionStart;
            var end = box.selectionEnd;
            box.value = box.value.slice(0, start) + '  ' + box.value.slice(end);
            box.selectionStart = box.selectionEnd = start + 2;
            refresh();
        });

        var form = document.getElementById(conf.form);
        if (!form) {
            return;
        }
        form.addEventListener('submit', function (event) {
            try {
                JSON.parse(box.value);
            } catch (err) {
                event.preventDefault();
                window.alert((((window.tpiManage || {})["json-error"]) || "") + "\n" + err.message);
                box.focus();
            }
        });
    });
})();
