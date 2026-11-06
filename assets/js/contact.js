const header_box = document.querySelector('.header');
const footer_box = document.querySelector('.footer');
const params = new URLSearchParams(window.location.search);
const action = params.get('action');
if (action === 'inquiry') {
    window.scrollTo({ top: document.documentElement.scrollHeight - header_box.offsetHeight - footer_box.offsetHeight, behavior: "smooth" });
}