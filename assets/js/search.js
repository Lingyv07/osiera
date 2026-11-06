/**
 * 产品分页
 */
const product_pagination = document.querySelector(".product-list-wrap-box");
const product_num_page_list = product_pagination.querySelectorAll(".btn-page-num");
for (let num_page of product_num_page_list) {
    num_page.onclick = () => {
        let params = new URLSearchParams(window.location.search);
        params.set("product-page", num_page.dataset.num);
        window.location.search = params.toString();
    }
}

/**
 * 文章分页
 */
const new_pagination = document.querySelector(".news-list-wrap-box");
const new_num_page_list = new_pagination.querySelectorAll(".btn-page-num");
for (let num_page of new_num_page_list) {
    num_page.onclick = () => {
        let params = new URLSearchParams(window.location.search);
        params.set("new-page", num_page.dataset.num);
        window.location.search = params.toString();
    }
}

/**
 * 产品应用分页
 */
const application_pagination = document.querySelector(".application-list-wrap-box");
const application_num_page_list = application_pagination.querySelectorAll(".btn-page-num");
for (let num_page of application_num_page_list) {
    num_page.onclick = () => {
        let params = new URLSearchParams(window.location.search);
        params.set("application-page", num_page.dataset.num);
        window.location.search = params.toString();
    }
}

/**
 * 常见问题分页
 */
const faq_pagination = document.querySelector(".faq-list-wrap-box");
const faq_num_page_list = faq_pagination.querySelectorAll(".btn-page-num");
for (let num_page of faq_num_page_list) {
    num_page.onclick = () => {
        let params = new URLSearchParams(window.location.search);
        params.set("faq-page", num_page.dataset.num);
        window.location.search = params.toString();
    }
}