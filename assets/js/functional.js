// 图片懒加载
function lazy_image() {
    const image_list = document.querySelectorAll("img");
    const lazy_images = [];
    for (let image of image_list) {
        if (image.dataset.lazy) {
            image.classList.add("lazy-class");
            lazy_images.push(image);
        }
    }
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.lazy;       // 把真实地址赋值给src
                    img.classList.add("lazy-transition");
                    delete img.dataset.lazy;    // 移除懒加载类（可选）
                    observer.unobserve(img);         // 取消观察，节省性能
                }
            });
        });

        lazy_images.forEach(img => {
            observer.observe(img);
        });
    } else {
        // 不支持IntersectionObserver，退回普通加载
        lazy_images .forEach(img => {
            img.src = img.dataset.lazy;
        });
    }
}
lazy_image();

/**
 * 发送消息
 * @param text 消息内容
 * @param status 状态码
 * @param time 动画时间
 * @param awaitTime 等待时间
 */
let message_await_timer = null;
let message_timer = null;
function message_push(text, status = true, time = 500, awaitTime = 2000) {
    clearInterval(message_timer);
    clearInterval(message_await_timer);
    const toast_message = document.querySelector("#toast-message");
    const toast_text = toast_message.querySelector(".toast-text");
    toast_message.style.transition = `${time}ms`;
    toast_text.innerText = text;

    if (status) {
        toast_message.classList.remove("toast-message-error");
        toast_message.classList.add("toast-message-success");
    } else {
        toast_message.classList.remove("toast-message-success");
        toast_message.classList.add("toast-message-error");
    }

    toast_message.classList.add("toast-message-active");
    setTimeout(() => {
        toast_message.classList.add("toast-message-active-css");
    }, 50);
    message_await_timer = setTimeout(() => {
        toast_message.classList.remove("toast-message-active-css");
    }, awaitTime);
    message_timer = setTimeout(() => {
        toast_message.classList.remove("toast-message-active");
    }, time+awaitTime);
}

/**
 * *** 复制信息 ***
 */
const info_box_list = document.querySelectorAll(".copy-info-box");
for (let info_box of info_box_list) {
    const info_text = info_box.querySelector(".copy-info-desc");
    const text_content = info_text?.innerText;
    if (text_content) {
        info_box.addEventListener('mousedown', () => {
            navigator.clipboard.writeText(text_content).then(() => {
                message_push(info_box.dataset.message);
            });
        });
    }
}

/**
 *  http请求
 * @type {{post: (function(*, {}=, {}=): Promise<unknown>), get: (function(*, {}=, {}=): Promise<unknown>)}}
 */
/**
 * 简单的 HTTP 请求封装，支持 GET 和 POST
 */
const http = {
    get: (url, params = {}, headers = {}, options = {}) => {
        return new Promise((resolve, reject) => {
            // 拼接查询参数
            const queryString = Object.keys(params)
                .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
                .join('&');
            const fullUrl = queryString ? `${url}?${queryString}` : url;

            const xhr = new XMLHttpRequest();
            if (options.withCredentials) {
                xhr.withCredentials = true;
            }
            xhr.open('GET', fullUrl, true);

            // 设置请求头
            for (const key in headers) {
                xhr.setRequestHeader(key, headers[key]);
            }

            xhr.onreadystatechange = () => {
                let data = {};
                // 1. 获取所有响应头字符串
                const headerString = xhr.getAllResponseHeaders();

                // 2. 将字符串解析为对象
                const responseHeaders = {};
                if (headerString) {
                    headerString.trim().split(/[\r\n]+/).forEach(line => {
                        const parts = line.split(': ');
                        const key = parts.shift().toLowerCase(); // 转为小写方便读取
                        responseHeaders[key] = parts.join(': ');
                    });
                }
                if (xhr.readyState === 4) {
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch {
                        data = xhr.responseText
                    }
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve({ data, status: xhr.status, headers: responseHeaders });
                    } else {
                        reject({ data, status: xhr.status, headers: responseHeaders  });
                    }
                }
            };

            xhr.onerror = () => reject(new Error('网络错误'));
            xhr.send();
        });
    },

    post: (url, data = {}, headers = {}, options = {}) => {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            if (options.withCredentials) {
                xhr.withCredentials = true;
            }
            xhr.open('POST', url, true);

            // 默认Content-Type为 application/json
            const contentTypeKey = Object.keys(headers).find(k => k.toLowerCase() === 'content-type');
            if (!contentTypeKey) {
                xhr.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');
            }

            // 设置其它请求头
            for (const key in headers) {
                xhr.setRequestHeader(key, headers[key]);
            }

            xhr.onreadystatechange = () => {
                let data = {};
                // 1. 获取所有响应头字符串
                const headerString = xhr.getAllResponseHeaders();

                // 2. 将字符串解析为对象
                const responseHeaders = {};
                if (headerString) {
                    headerString.trim().split(/[\r\n]+/).forEach(line => {
                        const parts = line.split(': ');
                        const key = parts.shift().toLowerCase(); // 转为小写方便读取
                        responseHeaders[key] = parts.join(': ');
                    });
                }
                if (xhr.readyState === 4) {
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch {
                        data = xhr.responseText
                    }
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve({ data, status: xhr.status, headers: responseHeaders });
                    } else {
                        reject({ data, status: xhr.status, headers: responseHeaders  });
                    }
                }
            };

            xhr.onerror = () => reject(new Error('网络错误'));

            // 根据Content-Type处理发送数据
            let sendData = data;
            const contentType = headers[contentTypeKey] || headers[contentTypeKey?.toLowerCase()];
            if (contentType) {
                if (contentType.includes('application/json')) {
                    sendData = JSON.stringify(data);
                } else if (contentType.includes('application/x-www-form-urlencoded')) {
                    sendData = Object.keys(data)
                        .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(data[k]))
                        .join('&');
                }
                // 其他类型如 multipart/form-data 等需调用者自行传入 FormData 类型 data
            } else {
                // 默认 JSON 发送
                sendData = JSON.stringify(data);
            }

            xhr.send(sendData);
        });
    },
};


/**
 * 浮动询盘
 * @type {boolean}
 */
// 显示询盘
let visible_inquiry = false;
const visible_inquiry_btn = document.querySelector(".visible-inquiry");
const visible_inquiry_box = document.querySelector(".fixed-mail-box");
const close_inquiry_btn = visible_inquiry_box.querySelector(".close-inquiry");
const visible_inquiry_transition_time = 300;
let visible_inquiry_timer = null;

// 展示询盘
visible_inquiry_btn.onclick = () => {
    visible_hidden_inquiry();
}

// 关闭询盘
close_inquiry_btn.onclick = () => {
    visible_hidden_inquiry();
}

// 移动端显示浮动询盘
const mobile_visible_inquiry_btn = document.querySelector(".visible-inquiry-btn");
mobile_visible_inquiry_btn.onclick = () => {
    visible_hidden_inquiry(!visible_inquiry);
}

/**
 * 展示隐藏询盘
 * @param visible 填写为指定隐藏或显现，不填写反方向!visible
 */
function visible_hidden_inquiry(visible) {
    if (visible !== undefined) {
        visible_inquiry = !visible;
    }

    if (!visible_inquiry) {
        clearInterval(visible_inquiry_timer);
        visible_inquiry = true;
        visible_inquiry_btn.classList.add("visible-inquiry-hidden");
        visible_inquiry_box.classList.add("fixed-mail-box-active");
        setTimeout(() => {
            visible_inquiry_box.classList.add("fixed-mail-box-active-css");
        }, 10);
    } else {
        visible_inquiry = false;
        visible_inquiry_box.classList.remove("fixed-mail-box-active-css");
        visible_inquiry_timer = setTimeout(() => {
            visible_inquiry_box.classList.remove("fixed-mail-box-active");
            visible_inquiry_btn.classList.remove("visible-inquiry-hidden");
        }, visible_inquiry_transition_time)
    }
}

/**
 * 页脚自带询盘表单，表单露出来时右下角气泡会压在输入框和 Send 按钮上，
 * 这里按可见性临时收起（独立 class，不干扰上面的开关状态）
 */
const footerMailBox = document.querySelector(".footer .mail-box");
if (footerMailBox && visible_inquiry_btn && "IntersectionObserver" in window) {
    const inquiryObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            visible_inquiry_btn.classList.toggle("inquiry-covered", entry.isIntersecting);
        });
    }, { threshold: 0.18 });
    inquiryObserver.observe(footerMailBox);
}

/**
 * 获取cookies
 */
const cookieNames = ['u_token'];
const hasCookieNames = document.cookie.split(';').map(c => c.trim().split('=')[0]);
// 接口地址由 PHP 按「站点与路径」输出（window.tpiApi），默认 /wp-json/api/set-cookies
const set_cookies_api = (window.tpiApi && window.tpiApi.setCookies) || '/wp-json/api/set-cookies';
function getCookies() {
    for (let name of cookieNames) {
        if (!hasCookieNames.includes(name)) {
            // 失败不能抛到控制台（接口不存在/被拦截都可能），静默跳过
            http.post(set_cookies_api, {}, {}, { withCredentials: true }).then(() => {}).catch(() => {});
            break;
        }
    }
}
getCookies();

const cookie_policy_box = document.getElementById('cookie-policy');
/**
 * *** 邮箱询盘 ***
 */
const inquiry_list = document.querySelectorAll(".inquiry");

for (let inquiry of inquiry_list) {
    const input_list = inquiry.querySelectorAll("input, textarea");
    const inquiry_submit = inquiry.querySelector("button[type=submit]");
    // 接口地址由 PHP 按「站点与路径」配置输出，默认 /wp-json/api/inquiry
    const inquiry_api = inquiry.dataset.api || "/wp-json/api/inquiry";

// 清楚前置空格
    for (let input of input_list) {
        input.addEventListener("input", () => {
            input.value = input.value.trimStart();
        });
        input.addEventListener("focus", () => {
            if (!input.value && input.classList.contains("error-message")) {
                input.classList.remove("error-message");
            }
        });
    }

// 提交询盘表单
    inquiry_submit.onclick = (event) => {
        event.preventDefault();
        const name = inquiry.querySelector("input[name=name]");
        const email = inquiry.querySelector("input[name=email]");
        const telephone = inquiry.querySelector("input[name=telephone]");
        const message = inquiry.querySelector("textarea");
        let verify = true;

        // 校验
        if (!name.value) {
            verify = false;
            name.classList.add("error-message");
        }
        if (!email.value) {
            verify = false;
            email.classList.add("error-message");
        }
        if (!message.value) {
            verify = false;
            message.classList.add("error-message");
        }

        if (!verify) {
            return null;
        }

        inquiry_submit.disabled = true;
        inquiry_submit.classList.add("v-load");

        const success_numbers = [0, 1, 4, 7, 8]; // 成功转发询盘数字

        // 校验成功
        http.post(inquiry_api, {
            // 当前页面语言：接口地址没有语言前缀，后端靠它决定收件邮箱和邮件文案
            lang: inquiry.dataset.lang || "",
            name: name.value,
            email: email.value,
            telephone: telephone.value,
            message: message.value,
            utm_term: sessionStorage.getItem('utm_term'),
            origin_type: sessionStorage.getItem('traffic_type') || 'SEO',
            referer_domain: sessionStorage.getItem('referer_domain') || location.origin
        }).then(res => {
            message_push(res.data.message);
            visible_hidden_inquiry(false);

            if (success_numbers.includes(Number(res.headers['lucky-num']))) {
                // 询盘转化：发哪个事件、发给哪个平台，由后台「监控管理」的事件表决定
                tpiMonitorTrack('inquiry', {
                    'event_category': 'Lead Generation',
                    'event_label': 'Inquiry',
                    'form_location': window.location.href,
                    'user_name': name.value
                });
            }

            // 清空数据
            for (let input of input_list) {
                input.value = "";
            }
        }).catch((res) => {
            message_push(res.data?.message || '', false, 500, 3000);
        }).finally(() => {
            inquiry_submit.disabled = false;
            inquiry_submit.classList.remove("v-load");
        });
    }
}

/**
 * *** 复制邮箱记录 ***
 */
const copy_email_list = document.querySelectorAll('.copy-email-element');
let send_click_email_status = false;
for (let copy_item of copy_email_list) {
    copy_item.addEventListener("click", () => {
        if (!send_click_email_status) {
            // 邮箱点击：广告 / 自然搜索的区分同样交给后台事件表
            tpiMonitorTrack('email_click', {
                'event_category': 'Lead Generation',
                'event_label': 'Email Click',
                'form_location': window.location.href,
                'user_name': getCookie("u_token")
            });
            send_click_email_status = true;
        }
    });
}

/**
 * .product-cat-box .new-product-box 定位
 */
let productMoreBox = document.querySelector(".product-more-box");
if (productMoreBox) {
    const productMreAllWrap = document.querySelector(".product-more-all-wrap");
    const productCatBox = productMoreBox.querySelector(".product-cat-box");
    const productCategories = productCatBox.querySelector(" .product-categories");
    const productCatList = productCatBox.querySelectorAll("li > a");
    const newProductBox = productMoreBox.querySelector(".new-product-box");
    let lastScrollTop = 0; // 记录上一次的 scrollTop
    let initialTop = null; // 初始top
    let topMin = -(productCatBox.offsetHeight - 64); // 最小top
    const topMax = 84; // 最大top

    productCatBox.onclick = () => {
        if (window.innerWidth <= 980) {
            productCatBox.classList.toggle("product-cat-box-active");

            if (productCatBox.classList.contains("product-cat-box-active")) {
                productCategories.style.height = `${productCatList.length * 50}px`;
            } else {
                productCategories.style.height = "0";
            }
        }
    }

    fixedProductBox();
    function fixedProductBox() {
        function setInitialAttr() {
            topMin = -(productCatBox.offsetHeight - 64); // 最小top
            productMreAllWrap.style.width = `${productMoreBox.offsetWidth}px`;
            productMreAllWrap.classList.remove("fixed-wrap");
            productMreAllWrap.classList.remove("absolute-wrap");
            productMreAllWrap.style.top = "";
            productMreAllWrap.style.bottom = "";
            initialTop = topMax;
            if (window.innerWidth > 980) {
                productCategories.style.height = "";
            }

            productMreAllWrap.style.height = `${productCatBox.offsetHeight + newProductBox.offsetHeight + 20}px`;
        }

        const resizeObserver = new ResizeObserver(entries => {
            for (let entry of entries) {
                const { width, height } = entry.contentRect;
                setInitialAttr();
                lastScrollTop = 0;
                initialTop = null;
                setCatAndNewProductPosition();
            }
        });

        resizeObserver.observe(productMoreBox);

        // 页脚露出来时浮动侧栏必须收在上面，否则列表会被页脚分割线切断
        const footerElement = document.querySelector(".footer");
        function getFloatTopLimit() {
            if (!footerElement) {
                return topMax;
            }
            const footerTop = footerElement.getBoundingClientRect().top;
            return Math.min(topMax, footerTop - productMreAllWrap.offsetHeight - 24);
        }

        function setCatAndNewProductPosition() {
            if (productMoreBox.offsetHeight <= (productMreAllWrap.offsetHeight + productCatBox.offsetHeight + 20) || window.innerWidth <= 980) {
                return null;
            }

            const scrollTop = document.scrollingElement.scrollTop;
            const rect = productMoreBox.getBoundingClientRect();
            const top = rect.y;
            // 页脚贴近时 maxTop 会被顶成负数，下限跟着放宽，避免又被弹回 topMin 压到页脚
            const maxTop = getFloatTopLimit();
            const minTop = Math.min(topMin, maxTop);
            if (top >= topMax) {
                productMreAllWrap.classList.remove("fixed-wrap");
                productMreAllWrap.classList.remove("absolute-wrap");
                productMreAllWrap.style.top = "";
                productMreAllWrap.style.bottom = "";
                initialTop = topMax;
            } else if (top <= -(productMoreBox.offsetHeight - (newProductBox.offsetHeight + 84))) {
                productMreAllWrap.classList.add("absolute-wrap");
                productMreAllWrap.classList.remove("fixed-wrap");
                productMreAllWrap.style.top = "";
                productMreAllWrap.style.bottom = "0";
                initialTop = topMin;
            } else {
                productMreAllWrap.classList.add("fixed-wrap");
                productMreAllWrap.classList.remove("absolute-wrap");
                if (scrollTop > lastScrollTop) {  // 下滚动
                    const distance = scrollTop - lastScrollTop;
                    let resTop = !initialTop ? productMreAllWrap.offsetTop : initialTop;
                    resTop -= distance;
                    productMreAllWrap.style.top = `${Math.max(minTop, Math.min(resTop, maxTop))}px`;
                    productMreAllWrap.style.bottom = "";
                } else { // 上滚动
                    const distance = lastScrollTop - scrollTop;
                    let resTop = !initialTop ? productMreAllWrap.offsetTop : initialTop;
                    resTop += distance;
                    productMreAllWrap.style.top = `${Math.max(minTop, Math.min(resTop, maxTop))}px`;
                    productMreAllWrap.style.bottom = "";
                }
                initialTop = null;
            }
            lastScrollTop = scrollTop;
        }

        window.addEventListener("scroll", setCatAndNewProductPosition);
    }
}

/**
 * 分页
 */
const pagination_list = document.querySelectorAll(".pagination");
if (pagination_list.length) {
    for (let pagination of pagination_list) {
        const num_page_list = pagination.querySelectorAll(".btn-page-num");
        const baseUrl = window.location.pathname.split("/page/")[0]; // 去掉旧的分页部分
        for (let num_page of num_page_list) {
            num_page.onclick = () => {
                window.location.href = baseUrl+(num_page.dataset.num > 1 ? `/page/${Number(num_page.dataset.num)}` : ''); // 跳转
            }
        }
    }
}

/**
 * 布局切换
 */
const layout_wrap_list = document.querySelectorAll("#layout-wrap");
if (layout_wrap_list.length) {
    for (let layout_wrap of layout_wrap_list) {
        const layout_item_list = layout_wrap.querySelectorAll(".layout-item");
        for (let layout_item of layout_item_list) {
            layout_item.onclick = () => {
                layout_wrap.className = layout_item.dataset.layout;
            }
        }
    }
}

// 地图加载
const iframe_list = document.querySelectorAll("iframe");
for (let iframe of iframe_list) {
    iframe.parentElement.classList.add('v-load');
    iframe.onload = () => {
        iframe.parentElement.classList.remove('v-load');
    }
    iframe.onerror = () => {
        iframe.parentElement.classList.remove('v-load');
    }
}

// 移动端图片点击跳转
const editor_content = document.querySelectorAll('.editor-content');
for (let ele_content of editor_content) {
    const images = ele_content.querySelectorAll('img');
    for (let img of images) {
        img.onclick = function () {
            if (window.innerWidth <= 980) {
                location.href = img.src;
            }
        }
    }
}

// 移动端默认分页布局设置
function setLayoutWrap() {
    if (layout_wrap_list.length) {
        for (let layout_wrap of layout_wrap_list) {
            const layout_item_last = layout_wrap.lastElementChild;
            if (window.innerWidth <= 980) {
                layout_wrap.className = layout_item_last.dataset.layout;
            }
        }
    }
}
setLayoutWrap();

/**
 * cookies 授权
 */
// 设置 cookie 函数，过期时间设置为非常远
function setCookie(name, value) {
    const expires = new Date();
    expires.setFullYear(expires.getFullYear() + 10); // 设置过期时间为10年后，即长期有效
    document.cookie = name + "=" + value + ";expires=" + expires.toUTCString() + ";path=/";
}

// 获取 cookie 函数
function getCookie(name) {
    const nameEQ = name + "=";
    const ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i].trim();
        if (c.indexOf(nameEQ) === 0) {
            return c.substring(nameEQ.length, c.length);
        }
    }
    return null;
}

// 删除 cookie 函数
function eraseCookie(name) {
    document.cookie = name + '=; Max-Age=-99999999;';
}
/**
 * *** 监控与转化（Clarity / gtag）***
 *
 * 账号与事件全部来自后台「Osiera → 监控管理」，由 PHP 注入到 window.tpiMonitor：
 *   { clarity: 'abcdefghij',                       // 空 = 不加载 Clarity
 *     gtag:    ['G-…', 'AW-…', 'GT-…', 'GTM-…'],    // GTM- 走 gtm.js，其余逐个 gtag('config', id)
 *     events:  [{ t: 'inquiry', s: '', k: 'ga4', v: 'generate_lead' },
 *               { t: 'inquiry', s: 'google_ads', k: 'ads', v: 'AW-…/…' }],
 *     consent: '0' }
 *
 * Google 只有一份 gtag 配置列表：页面只请求一次 gtag.js（入口用第一个 G- 开头的 ID），
 * 之后每个 ID 各补一条 config —— GA4 记录和 Ads 账号在这里没有区别。
 * 事件按 k 分派：k=ga4 发事件名，k=ads 发 conversion + send_to（后台手填的「转化 ID/标签」）。
 * Clarity 单独加载，不参与 gtag，也没有事件。
 */
const tpiMonitor = window.tpiMonitor || null;

// 后台「需要访客授权 Cookie」打开时，必须等访客点过同意（cookieConsent=true）才加载
const tpiMonitorNeedsConsent = !!(tpiMonitor && tpiMonitor.consent === '1');

function tpiMonitorConsented() {
    return !tpiMonitorNeedsConsent || getCookie('cookieConsent') === 'true';
}

// 微软 Clarity（只录屏与热图）
function tpiMonitorLoadClarity(id) {
    (function (c, l, a, r, i, t, y) {
        c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments) };
        t = l.createElement(r); t.async = 1; t.src = "https://www.clarity.ms/tag/" + i;
        y = l.getElementsByTagName(r)[0]; y.parentNode.insertBefore(t, y);
    })(window, document, "clarity", "script", id);
}

// Google：所有 gtag 配置共用一份 gtag.js，只请求一次
function tpiMonitorLoadGtag(ids) {
    if (!ids || !ids.length) { return; }
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    ids.forEach(function (id) { window.gtag('config', id); });

    // 入口优先用 GA4 的记录 ID（G- 开头）；只有 Ads 账号时用列表里第一个
    let entry = ids[0];
    for (let i = 0; i < ids.length; i++) {
        if (ids[i].toUpperCase().indexOf('G-') === 0) { entry = ids[i]; break; }
    }

    const script = document.createElement('script');
    script.async = true;
    script.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(entry);
    document.head.appendChild(script);
}

// Google Tag Manager 容器：独立脚本（gtm.js），容器里的事件在 Tag Manager 后台配
function tpiMonitorLoadGtm(id) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
    const script = document.createElement('script');
    script.async = true;
    script.src = "https://www.googletagmanager.com/gtm.js?id=" + encodeURIComponent(id);
    document.head.appendChild(script);
}

// 按配置加载脚本（同一页面只加载一次；需要授权且还没同意时先不加载）
let tpiMonitorStarted = false;
function tpiMonitorStart() {
    if (tpiMonitorStarted || !tpiMonitor || !tpiMonitorConsented()) { return; }
    tpiMonitorStarted = true;

    if (tpiMonitor.clarity) {
        tpiMonitorLoadClarity(tpiMonitor.clarity);
    }

    const gtag_ids = [];
    (tpiMonitor.gtag || []).forEach(function (id) {
        if (!id) { return; }
        // GTM- 是容器，单独请求 gtm.js；其余（G- / AW- / GT-）都是一条 gtag('config')
        if (id.toUpperCase().indexOf('GTM-') === 0) {
            tpiMonitorLoadGtm(id);
        } else {
            gtag_ids.push(id);
        }
    });
    tpiMonitorLoadGtag(gtag_ids);
}

/**
 * 这一个触发动作要发哪些行
 * 同一目标（同一个事件名 / 同一个 send_to）只发一次：
 * 先找来源精确匹配的那行，没有再用「默认」那行。
 */
function tpiMonitorRows(trigger) {
    const source = (sessionStorage.getItem('traffic_type') || '').toLowerCase();
    const picked = new Map();

    (tpiMonitor.events || []).forEach(function (row) {
        if (!row || row.t !== trigger || !row.v) { return; }
        const key   = (row.k === 'ads' ? 'ads' : 'ga4') + '|' + row.v;
        const score = row.s === source ? 2 : (row.s === '' ? 1 : 0);
        const prev  = picked.get(key);
        if (!prev || score > prev.score) {
            picked.set(key, { row: row, score: score });
        }
    });

    return Array.from(picked.values()).map(function (item) { return item.row; });
}

/**
 * 触发一个转化动作
 * @param {string} trigger 触发动作（inquiry / email_click）
 * @param {object} params  事件参数（event_category / event_label 等）
 */
function tpiMonitorTrack(trigger, params) {
    // 没加载脚本（总开关关闭 / 本站未启用 / 还没拿到授权）就不上报
    if (!tpiMonitor || !tpiMonitorStarted) { return; }

    tpiMonitorRows(trigger).forEach(function (row) {
        if (row.k === 'ads') {
            // 谷歌广告转化：send_to = 转化 ID / 转化标签
            if (!window.gtag) { return; }
            window.gtag('event', 'conversion', Object.assign({ send_to: row.v }, params || {}));
            return;
        }
        if (window.gtag) {
            window.gtag('event', row.v, params || {});
            return;
        }
        // 只配了 GTM 容器的站点没有 gtag：事件推进 dataLayer，由容器里的触发器接
        if (window.dataLayer) {
            window.dataLayer.push(Object.assign({ event: row.v }, params || {}));
        }
    });
}

// 进页面按配置加载：总开关 / 本站启用由后台决定（没有可加载的平台时配置是空的），
// 需要访客授权时这里先跳过，等点了「同意」再加载。
tpiMonitorStart();

/**
 * Cookie 提示条
 * 只有后台打开「需要访客授权 Cookie」时 PHP 才会渲染这段结构，
 * 所以这里找不到元素就说明是「默认授权」，不需要打扰访客。
 */
function tpiMonitorShowBanner(show) {
    if (!cookie_policy_box) { return; }
    cookie_policy_box.style.display = show ? 'block' : 'none';
}

// 用户同意 Cookies 时（提示条按钮 onclick 直接调用）
function acceptCookies() {
    // 设置 cookie，保存用户选择
    setCookie("cookieConsent", "true"); // 存储用户的同意，长期有效
    tpiMonitorShowBanner(false);
    tpiMonitorStart();
}

// 用户拒绝 Cookies 时
function rejectCookies() {
    // 删除 cookie，如果用户不希望保存
    eraseCookie("cookieConsent");
    tpiMonitorShowBanner(false);
}

if (cookie_policy_box) {
    tpiMonitorShowBanner(tpiMonitorNeedsConsent && !tpiMonitorConsented());

    // 关闭按钮功能
    const cookie_policy_close = cookie_policy_box.querySelector('.cookie-policy-close');
    if (cookie_policy_close) {
        cookie_policy_close.addEventListener('click', function () {
            tpiMonitorShowBanner(false);
        });
    }
}
