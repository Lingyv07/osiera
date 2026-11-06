// 使用示例
const swipe = document.querySelector('.workshop-swipe');
const swipe_images = document.querySelectorAll('.workshop-image');
if (swipe_images.length) {
    new Swipe(swipe, {
        loop: true,
        autoplay: 3000,
        vertical: false,
        duration: 500,
        dragThreshold: 0.3 // 拖动超过 30% slide 才切换
    });
}