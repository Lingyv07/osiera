const wrap = document.querySelector(".product-img-visible-wrap");
const zoom_img = document.querySelector(".zoom-img");
const items = wrap.querySelectorAll(".product-img-item");
const thumbs = document.querySelectorAll(".image-item");
const oneItemY = 100 / thumbs.length;
let currentIndex = 0;

// 产品图切换
const productImgSwipe = document.querySelector('.product-img-list-wrap');
const productSwipeSwitch = new SwipeSwitch(productImgSwipe, {
    vertical: false,
    duration: 500,
    dragThreshold: 0.3 // 拖动超过 30% slide 才切换
});
const productSwipeMaxIndex = productSwipeSwitch.slides.length - productSwipeSwitch.visibleCount;

function setImage(arg_index) {
    const thumb = thumbs[arg_index];
    const thumb_index = arg_index;
    thumbs.forEach(item => {
        if (thumb !== item) {
            item.classList.remove("image-item-active");
        } else {
            item.classList.add("image-item-active");
        }
    });

    wrap.style.transform = `translateY(-${thumb_index * oneItemY}%)`;

    items.forEach((item, index)  => {
        if (index !== thumb_index) {
            item.classList.remove("product-img-item-active");
        } else {
            item.classList.add("product-img-item-active");
        }
    });
    currentIndex = thumb_index;
    zoom_img.src = items[thumb_index].dataset.src;

    const item_type = items[currentIndex].dataset.type;
    if (item_type !== "img") {
        mask.classList.add("mask-hidden");
        zoom_view.classList.remove("zoom-view-active");
    } else {
        mask.classList.remove("mask-hidden");
    }

    let reduceIndex = thumb_index - 1;
    let index = reduceIndex >= productSwipeMaxIndex ? productSwipeMaxIndex : reduceIndex;
    productSwipeSwitch.swipeTo(index > 0 ? index : 0);
}

// 缩放图区域展示
const img_wrap = document.querySelector(".product-img-visible");
const iframe_items = img_wrap.querySelectorAll(".iframe-item");
const mask = img_wrap.querySelector(".mask");
const zoom_view = document.querySelector(".zoom-view");
const zoom_view_img = zoom_view.querySelector(".zoom-img");
const zoom_view_transition_time = 300;
let zoom_view_timer = null;

if (items.length) {
    // 点击加载视频
    for (let iframe_item of iframe_items) {
        iframe_item.onclick = (e) => {
            loadIframe(iframe_item);
        }
    }

    function loadIframe(iframe_item) {
        const has_iframe = iframe_item.querySelector('iframe');
        if (!has_iframe) {
            iframe_item.classList.add("v-load");
            iframe_item.innerHTML = `
                    <iframe
                            width="100%"
                            height="100%"
                            src="${iframe_item.dataset.src}"
                            title="Product Introduction Video"
                            loading="lazy"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allowfullscreen
                            >
                    </iframe>
                `;
            // 移除加载
            const iframe = iframe_item.querySelector("iframe");
            iframe.onload = () => {
                iframe.parentElement.classList.remove('v-load');
            }
            iframe.onerror = () => {
                iframe.parentElement.classList.remove('v-load');
            }
        }
    }

    img_wrap.addEventListener("mouseenter", e => {
        const item_type = items[currentIndex].dataset.type;
        if (item_type === "img") {
            clearInterval(zoom_view_timer);
            mask.classList.remove("mask-hidden");
            setTimeout(() => {
                mask.classList.add("mask-visible");
                zoom_view.classList.add("zoom-view-active");
            }, 10);
            setTimeout(() => {
                zoom_view.classList.add("zoom-view-active-css");
            }, 20);
        } else {
            mask.classList.add("mask-hidden");
        }
    });
    img_wrap.addEventListener("mouseleave", e => {
        const item_type = items[currentIndex].dataset.type;
        if (item_type === "img") {
            mask.classList.remove("mask-visible");
            zoom_view.classList.remove("zoom-view-active-css");
            zoom_view_timer = setTimeout(() => {
                zoom_view.classList.remove("zoom-view-active");
            }, zoom_view_transition_time);
        }
    });
    img_wrap.addEventListener("mousemove", e => {
        const item_type = items[currentIndex].dataset.type;
        if (item_type !== "img") return null;
        const rect = img_wrap.getBoundingClientRect(); // wrap 在页面的位置

        // 鼠标相对于 wrap 的位置
        let x = e.clientX - rect.left;
        let y = e.clientY - rect.top;

        const maskWidth = mask.offsetWidth;
        const maskHeight = mask.offsetHeight;

        // 限制 mask 在 wrap 内移动
        const maxX = rect.width - maskWidth;
        const maxY = rect.height - maskHeight;

        let maskX = x - maskWidth / 2;
        let maskY = y - maskHeight / 2;

        if (maskX < 0) maskX = 0;
        if (maskY < 0) maskY = 0;
        if (maskX > maxX) maskX = maxX;
        if (maskY > maxY) maskY = maxY;

        // 设置 mask 位置
        mask.style.left = maskX + "px";
        mask.style.top = maskY + "px";

        const percentX = (maskX / maxX) * 100; // 0 ~ 100%
        const percentY = (maskY / maxY) * 100; // 0 ~ 100%

        zoom_view_img.style.left = `-${percentX}%`;
        zoom_view_img.style.top = `-${percentY}%`;
    });

   // 拖拽切换
    enableDrag(img_wrap, (diff) => {
        const item_type = items[currentIndex].dataset.type;
        if (item_type !== "img") return null;
        if (Math.abs(diff) < 5) {
            return null;
        }
        let set_index;
        if (diff < 0) {
            set_index = currentIndex+1 <= items.length-1 ? currentIndex+1 : 0;
        } else {
            set_index = currentIndex-1 >= 0 ? currentIndex-1 : items.length-1;
        }
        setImage(set_index);
    }, (isScroll) => {
        if (window.innerWidth <= 980) {
            switch (!isScroll && items[currentIndex].dataset.type) {
                case "img":
                    location.href = items[currentIndex].dataset.href;
                    break;
                case "iframe":
                    loadIframe(items[currentIndex]);
                    break;
            }
        }
    });

    function enableDrag(container, callback, clickCallback) {
        let isDown = false;
        let startX = 0;
        let endX = 0;
        let startY = 0;
        let endY = 0;

        // 鼠标按下
        container.addEventListener("mousedown", (e) => {
            e.preventDefault(); // 阻止图片被拖拽
            isDown = true;
            startX = e.clientX;
        });

        // 鼠标抬起
        container.addEventListener("mouseup", (e) => {
            if (!isDown) return;
            isDown = false;
            endX = e.clientX;
            handleSwipe();
        });

        container.addEventListener("touchstart", (e) => {
            isDown = true;
            startX =  e.touches[0].clientX;
            endX = startX;
            startY =  e.touches[0].clientY;
            endY = startY;
        });

        container.addEventListener('touchmove', e => {
            endX = e.touches[0].clientX;
            endY =  e.touches[0].clientY;
        });

        container.addEventListener("touchend", () => {
            if (!isDown) return;
            isDown = false;
            handleSwipe();
        });

        // 处理左右拖拽
        function handleSwipe() {
            const diff = endX - startX;
            if (Math.abs(diff) > 50) { // 拖动超过 50px 才算有效
                callback(diff);
            } else {
                clickCallback(Math.abs(endY - startY) > 20);
            }
        }
    }
}

// 产品锁定
thumbs.forEach((thumb, thumb_index) => {
    thumb.onclick = () => {
        setImage(thumb_index);
    }
});

// 回到底部
const backScrollBottom = document.querySelector(".back-scroll-bottom");
const header_box = document.querySelector('.header');
const footer_box = document.querySelector('.footer');
backScrollBottom.onclick = () => {
    window.scrollTo({ top: document.documentElement.scrollHeight - header_box.offsetHeight - footer_box.offsetHeight, behavior: "smooth" });
}

// related product 切换
const relatedProductSwipe = document.querySelector('.related-product-swipe');
const relatedProductList = relatedProductSwipe.querySelectorAll('.swipe-slide');
if (relatedProductList.length) {
    new SwipeSwitch(relatedProductSwipe, {
        vertical: false,
        duration: 500,
        dragThreshold: 0.3 // 拖动超过 30% slide 才切换
    });
}

function default_show_email_box() {
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
    const scrollPercent = scrollTop / scrollHeight;

    if (scrollPercent >= 0.1 && visible_hidden_inquiry) {
        window.removeEventListener("scroll", default_show_email_box);
        visible_hidden_inquiry(true);
    }
}

window.addEventListener('scroll', default_show_email_box);