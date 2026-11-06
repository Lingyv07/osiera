const _0x9a0f8c = _0x0e02c9;
function _0xdf16d7() {
    const _0xT = ['length','querySelector','.workshop-swipe','.workshop-image','querySelectorAll'];
    _0xdf16d7 = function () { return _0xT; };
    return _0xdf16d7();
}
function _0x0e02c9(_0xI, _0xK) {
    _0xI = _0xI - 0x100;
    const _0xT = _0xdf16d7();
    let _0xV = _0xT[_0xI];
    return _0xV;
}
const swipe=document[_0x9a0f8c(0x101)](_0x9a0f8c(0x102));
const swipe_images=document[_0x9a0f8c(0x104)](_0x9a0f8c(0x103));
if(swipe_images[_0x9a0f8c(0x100)]){
    new Swipe(swipe,{
        loop:!![],
        autoplay:0xbb8,
        vertical:![],
        duration:0x1f4,
        dragThreshold:0.3
    });
}