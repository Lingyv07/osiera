/**
 * *** 当前路由展示样式 ***
 */
// const menu_box_wrap = document.querySelector(".main-menu");
// const menu_shallow_items = menu_box_wrap.querySelectorAll(":scope .page_item");
// for (let menu_item of menu_shallow_items) {
//     const menu_a = menu_item.querySelector(":scope > a");
//     if (menu_a.href === location.href) {
//         if (menu_item.parentElement.classList.contains("children")) {
//             menu_item.parentElement.parentElement.classList.add("page_item_active_route");
//         } else {
//             menu_item.classList.add("page_item_active_route");
//         }
//     }
// }
//
// const mobile_menu_footer = document.querySelector(".menu-footer");
// const mobile_menu_shallow_items = mobile_menu_footer.querySelectorAll(":scope li");
// for (let menu_item of mobile_menu_shallow_items) {
//     const menu_a = menu_item.querySelector(":scope > a");
//     if (menu_a && menu_a.href === location.href) {
//         if (menu_item.parentElement.classList.contains("children")) {
//             menu_item.parentElement.parentElement.classList.add("mobile-page_item_active_route");
//         } else {
//             menu_item.classList.add("mobile-page_item_active_route");
//         }
//     }
// }

/**
 * *** 语言切换 ***
 *
 * 语言不能写死：当前页在每个语言下的地址由 PHP 注入（window.tpiI18n.urls，
 * 键是语言代码），后台加减语言前端自动跟着变。
 * <head> 里的 <link rel="alternate" hreflang="xx"> 只作兜底 ——
 * 它的值是 ja-JP / en-US 这种 BCP 47 标记，不能直接当语言代码用。
 */
const language_dom_box = document.querySelector(".language-box");
const tpi_i18n = window.tpiI18n || {};
const default_language = tpi_i18n.default || "en"; // 默认语言

// 语言列表动态取：后台「语言」页维护的全部语言（含默认语言 en），
// 注入缺失时退回 hreflang 里出现过的语言
const language_list = (tpi_i18n.all && tpi_i18n.all.length)
    ? tpi_i18n.all
    : Object.keys(tpi_i18n.urls || {});

// 语言代码 => 当前页面在该语言下的地址
const language_urls = {};

// 1) PHP 按语言代码直接给的地址，最可靠，优先用
const language_url_map = (tpi_i18n.urls && typeof tpi_i18n.urls === "object") ? tpi_i18n.urls : {};
Object.keys(language_url_map).forEach(function (slug) {
    if (language_url_map[slug]) {
        language_urls[slug] = language_url_map[slug];
    }
});

// 2) 兜底读 <link rel="alternate" hreflang="xx">。
// 注意 hreflang 是 BCP 47（ja-JP、en-US），语言代码是 ja、en，
// 直接用 hreflang 当键会取不到值，切换时就会掉回首页。
document.querySelectorAll('link[rel="alternate"][hreflang]').forEach(function (link) {
    const code = (link.getAttribute("hreflang") || "").toLowerCase();
    const href = link.getAttribute("href") || "";
    if (!code || code === "x-default" || !href) {
        return;
    }
    language_list.forEach(function (slug) {
        const key = String(slug).toLowerCase();
        if (!language_urls[key] && (code === key || code.indexOf(key + "-") === 0)) {
            language_urls[key] = href;
        }
    });
    if (!language_urls[code]) {
        language_urls[code] = href;
    }
});

/**
 * 某语言首页地址
 * @param {string} lang
 * @returns {string}
 */
function language_home_url(lang) {
    return location.origin + (lang === default_language ? "/" : "/" + lang + "/");
}

/**
 * 去掉路径里的语言前缀
 * @returns {string} 例：/ja/products → /products
 */
function path_without_language() {
    const path = location.pathname.replace(/\/+$/, "");
    const first = path.split("/")[1] || "";
    if (first && first !== default_language && language_list.indexOf(first) !== -1) {
        return path.slice(first.length + 1) || "/";
    }
    return path || "/";
}

/**
 * 切换语言
 * @param {string} lang 需要切换的语言
 * @returns {string} 结果链接
 */
function language_link(lang) {
    const hash = location.hash || "";
    const mapped = language_urls[lang] || "";

    // 详情页：有该语言的对应地址就过去（译文 → 同 slug → 等价路径，
    // 顺序由 PHP 算好），一条都没有才回该语言首页，避免落到 404
    if (tpi_i18n.isSingular) {
        return (mapped || language_home_url(lang)) + hash;
    }

    // 列表 / 分类 / 搜索页：PHP 给的地址不是该语言首页时直接用，
    // 否则按「路径换语言前缀」拼，并保留查询串
    if (mapped && mapped.replace(/\/+$/, "") !== language_home_url(lang).replace(/\/+$/, "")) {
        return mapped + hash;
    }

    const rest = path_without_language();
    const base = lang === default_language ? "" : "/" + lang;
    const url = base + (rest === "/" ? "/" : rest);
    return location.origin + url + (location.search || "") + hash;
}

// 加入切换语言事件
if (language_dom_box) {
    const language_dom_lists = language_dom_box.querySelectorAll("button");
    for (const item of language_dom_lists) {
        item.onclick = function () {
            location.href = language_link(item.lang);
        };
    }
}

const change_language = document.querySelector('.change-language');
const language_toggle = document.querySelector('.language-toggle');
const expand_lang_transition_time = 300;// 语言盒子动画时常
let language_timer = null;

// 点击展开语言列表
change_language.onclick = () => {
    if (window.window.innerWidth > 980) {
        const hidden = !change_language.classList.contains('change-language-active');
        language_toggle.setAttribute('aria-expanded', String(!hidden));
        if (!hidden) {
            change_language.classList.remove('change-language-active-css');
            language_timer = setTimeout(() => {
                change_language.classList.remove('change-language-active');
            }, expand_lang_transition_time);
        } else {
            clearInterval(language_timer);
            change_language.classList.add('change-language-active');
            setTimeout(() => {
                change_language.classList.add('change-language-active-css');
            });
        }
    }
};

// 隐藏语言列表
function hidden_language_box(event) {
    if (!change_language.contains(event.target)) {
        language_toggle.setAttribute('aria-expanded', "false");
        change_language.classList.remove('change-language-active-css');
        setTimeout(() => {
            change_language.classList.remove('change-language-active');
        }, expand_lang_transition_time);
    }
}
document.addEventListener("click", hidden_language_box);

/**
 * *** pc端菜单盒子展开收起 ***
 */
const menu_box = document.querySelector(".main-navigation");
const menu_children_list = menu_box.querySelectorAll(".page_item_has_children");
const visible_menu_transition_time = 300;// 菜单显示动画时常

// 移入a展示子菜单
for (let menu_children of menu_children_list) {
    let timer = null;
    menu_children.ariaLabel = "Expand submenu";
    menu_children.onmouseenter = (event) => {
        clearInterval(timer);
        menu_children.classList.add("menu-item-active");
        menu_children.ariaExpanded = "true";
        setTimeout(() => {
            menu_children.classList.add("menu-item-active-css");
        });
    }
    menu_children.onmouseleave = (event) => {
        menu_children.classList.remove("menu-item-active-css");
        timer = setTimeout(() => {
            menu_children.classList.remove("menu-item-active");
            menu_children.ariaExpanded = "false";
        }, visible_menu_transition_time);
    }
}

/**
 * *** 移动端菜单盒子展开收起 ***
 */
const mobile_menu_toggle = document.querySelector(".mobile-menu-toggle");
const mobile_menu_box = document.querySelector(".mobile-menu-box");
const mobile_visible_menu_transition_time = 300;// 菜单显示动画时常
let mobile_menu_timer = null;

// 点击展开盒子
mobile_menu_toggle.onclick = (event) => {
    event.preventDefault();
    if (mobile_menu_toggle.classList.contains('mobile-menu-toggle-expand')) {
        mobile_menu_toggle.classList.remove('mobile-menu-toggle-expand');
        document.documentElement.classList.remove('html-overflow-hidden');
        mobile_menu_box.classList.remove('mobile-menu-box-active-css');
        mobile_menu_timer = setTimeout(() => {
            mobile_menu_box.classList.remove('mobile-menu-box-active');
        }, mobile_visible_menu_transition_time);
    } else {
        clearInterval(mobile_menu_timer);
        mobile_menu_toggle.classList.add('mobile-menu-toggle-expand');
        document.documentElement.classList.add('html-overflow-hidden');
        mobile_menu_box.classList.add('mobile-menu-box-active');
        setTimeout(() => {
            mobile_menu_box.classList.add('mobile-menu-box-active-css');
        });
    }
}

const expand_menu_children_list = mobile_menu_box.querySelectorAll(".page_item_has_children");
const li_item_height = 55;// 一个li高度
const expand_menu_transition_time = 300;// 菜单动画时常

// 点击展示子菜单 expand-children-btn
for (let expand_menu_children of expand_menu_children_list) {
    let timer = null;
    const menu_expand_children_bth = expand_menu_children.querySelector(":scope > .expand-children-btn");
    const menu_expand_children_bth_icon = menu_expand_children_bth.querySelector(":scope > .expand-children-btn-icon");
    const menu_children_box = expand_menu_children.querySelector(":scope > .children");
    const menu_children_list = menu_children_box.querySelectorAll(":scope > li");

    if (menu_expand_children_bth) {
        menu_expand_children_bth.onclick = () => {
            if (menu_expand_children_bth_icon.classList.contains('expand-children-btn-expand')) {
                menu_expand_children_bth_icon.classList.remove('expand-children-btn-expand');
                menu_children_box.style.height = "0";
                timer = setTimeout(() => {
                    menu_children_box.classList.remove('menu-children-box-visible');
                }, expand_menu_transition_time);
            } else {
                clearInterval(timer);
                menu_children_box.classList.add('menu-children-box-visible');
                menu_expand_children_bth_icon.classList.add('expand-children-btn-expand');
                setTimeout(() => {
                    menu_children_box.style.height = `${menu_children_list.length * li_item_height}px`;
                });
            }
        }
    }
}

/**
 * *** 回到顶部, 进度条展示 ***
 */
const back_top_dom = document.querySelector(".progress-wrap");
const back_top_svg_path = back_top_dom.querySelector("path");
const progress_wrap_num = 307.919;
// 回到顶部
back_top_dom.onclick = () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
}
// 进度条变化
function visible_progress_wrap(event) {
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
    const scrollPercent = scrollTop / scrollHeight;
    const new_progress_wrap_num = progress_wrap_num - (progress_wrap_num * scrollPercent);
    back_top_svg_path.style.strokeDashoffset = new_progress_wrap_num+"";

    if (scrollTop > 20) {
        back_top_dom.classList.add("progress-wrap-active");
    } else {
        back_top_dom.classList.remove("progress-wrap-active");
    }
}
window.addEventListener("scroll", visible_progress_wrap);

/**
 * *** 回到顶部, 进度条展示 ***
 */
const address_tel_email = document.querySelector(".address-tel-email");
// 回到顶部
back_top_dom.onclick = () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
}
// 进度条变化
function visible_tel_email() {
    const scrollTop = window.scrollY || document.documentElement.scrollTop;

    if (scrollTop > 100) {
        address_tel_email.classList.add("address-tel-email-visible");
    } else {
        address_tel_email.classList.remove("address-tel-email-visible");
    }
}
window.addEventListener("scroll", visible_tel_email);

/**
 * 平台广告和自然搜索区分
 */
const urlParams = new URLSearchParams(window.location.search);
const referrer = document.referrer.toLowerCase();
// 1. 定义本次访问检测到的“强信号” (广告 ID)
let currentStrongSource = '';
if (urlParams.has('gclid')) {
    currentStrongSource = 'google_ads';
} else if (urlParams.has('bd_vid') || urlParams.has('bdclkid')) {
    currentStrongSource = 'baidu_ads';
} else if (urlParams.has('msclkid')) {
    currentStrongSource = 'bing_ads';
} else if (urlParams.has('fbclid')) {
    currentStrongSource = 'facebook_ads';
} else if (urlParams.has('ttclid')) {
    currentStrongSource = 'tiktok_ads';
}

// 2. 逻辑判断
if (currentStrongSource) {
    // 只要检测到广告 ID，就说明用户是从新广告点进来的
    // 无论之前 session 里存的是什么，直接覆盖！
    sessionStorage.setItem('traffic_type', currentStrongSource);
} else {
    // 如果当前 URL 里没有广告 ID，再看 session 里是不是已经有值了
    if (!sessionStorage.getItem('traffic_type')) {
        // 只有在 session 为空的情况下，才去判断 Referrer (SEO 流量)
        if (referrer.includes('google.')) {
            sessionStorage.setItem('traffic_type', 'google_seo');
        } else if (referrer.includes('bing.com')) {
            sessionStorage.setItem('traffic_type', 'bing_seo');
        } else if (referrer.includes('baidu.com')) {
            sessionStorage.setItem('traffic_type', 'baidu_seo');
        }
    }
    // 如果 session 里已经有值（比如是之前存的 google_ads），
    // 且当前 URL 没带新 ID，说明用户可能是在刷站内页面，我们保持原状，不进行任何操作。
}

// 记录平台来源
if (!sessionStorage.getItem('referer_domain')) {
    sessionStorage.setItem('referer_domain', document.referrer);
}
// 记录关键词来源
if (!sessionStorage.getItem('utm_term')) {
    sessionStorage.setItem('utm_term', urlParams.get('utm_term') || '');
}