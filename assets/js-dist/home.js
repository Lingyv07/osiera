const _0xbfefb7 = _0xd54146;
function _0x822064() {
    const _0xT = ['#swipe','.home-image','querySelectorAll','.mobile-applications-wrap','.swipe-slide','querySelector','.mobile-products-wrap','length'];
    _0x822064 = function () { return _0xT; };
    return _0x822064();
}
function _0xd54146(_0xI, _0xK) {
    _0xI = _0xI - 0x100;
    const _0xT = _0x822064();
    let _0xV = _0xT[_0xI];
    return _0xV;
}
const swipe=document[_0xbfefb7(0x105)](_0xbfefb7(0x100));
const swipe_images=document[_0xbfefb7(0x102)](_0xbfefb7(0x101));
if(swipe_images[_0xbfefb7(0x107)]){
    new Swipe(swipe,{
        loop:!![],
        autoplay:0xbb8,
        vertical:![],
        duration:0x1f4,
        dragThreshold:0.3
    });
}
const home_product_swipe=document[_0xbfefb7(0x105)](_0xbfefb7(0x106));
const product_list=home_product_swipe?home_product_swipe[_0xbfefb7(0x102)](_0xbfefb7(0x104)):[];
if(product_list[_0xbfefb7(0x107)]){
    new Swipe(home_product_swipe,{
        vertical:![],
        duration:0x1f4,
        dragThreshold:0.3
    });
}
const home_application_swipe=document[_0xbfefb7(0x105)](_0xbfefb7(0x103));
const application_list=home_application_swipe?home_application_swipe[_0xbfefb7(0x102)](_0xbfefb7(0x104)):[];
if(application_list[_0xbfefb7(0x107)]){
    new Swipe(home_application_swipe,{
        vertical:![],
        duration:0x1f4,
        dragThreshold:0.3
    });
}