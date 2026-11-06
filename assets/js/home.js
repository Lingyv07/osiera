// 使用示例
const swipe = document.querySelector('#swipe');
const swipe_images = document.querySelectorAll('.home-image');
if (swipe_images.length) {
    new Swipe(swipe, {
        loop: true,
        autoplay: 3000,
        vertical: false,
        duration: 500,
        dragThreshold: 0.3 // 拖动超过 30% slide 才切换
    });
}

// 产品列表（移动端轮播）：桌面那份是网格，不初始化
const home_product_swipe = document.querySelector('.mobile-products-wrap');
const product_list = home_product_swipe ? home_product_swipe.querySelectorAll('.swipe-slide') : [];
if (product_list.length) {
    new Swipe(home_product_swipe, {
        vertical: false,
        duration: 500,
        dragThreshold: 0.3
    });
}

// 应用领域列表（移动端轮播）
const home_application_swipe = document.querySelector('.mobile-applications-wrap');
const application_list = home_application_swipe ? home_application_swipe.querySelectorAll('.swipe-slide') : [];
if (application_list.length) {
    new Swipe(home_application_swipe, {
        vertical: false,
        duration: 500,
        dragThreshold: 0.3
    });
}
