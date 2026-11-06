class Swipe {
    constructor(root, options = {}) {
        this.root = root;
        this.track = root.querySelector('.swipe-track');
        this.slides = Array.from(this.track.querySelectorAll(".swipe-slide"));
        this.indicatorContainer = root.querySelector('.swipe-indicators');
        this.prevBtn = root.querySelector('.carousel-btn.prev');
        this.nextBtn = root.querySelector('.carousel-btn.next');

        this.options = Object.assign({
            loop: true,
            autoplay: 3000,
            vertical: false,
            duration: 500,
            initial: 0,
            dragThreshold: 0.3,
            clickThreshold: 5
        }, options);

        this.activeIndex = this.options.loop ? this.options.initial + 1 : this.options.initial;
        this.delta = 0;
        this.dragging = false;
        this.locked = false;
        this.startPos = 0;
        this.timer = null;
        this.touchDistance = 0;
        this.isMouseDown = false;

        this._eventHandlers = {}; // 保存事件引用
        this.initAll();
        this.addResizeListener();
    }

    initAll() {
        this.clearTrack();

        // ✅ 新增判断：如果实际 slides 数量 ≤ 可见数量，直接退出，不做轮播
        const containerSize = this.options.vertical ? this.root.offsetHeight : this.root.offsetWidth;
        const slideSize = this.getSlideSize() || 1;
        const visibleCount = Math.round(containerSize / slideSize);

        if (this.slides.length <= visibleCount) {
            // 清理可能的定时器和事件
            clearInterval(this.timer);
            this.removeEvents();

            // 重置状态
            this.track.style.transform = "translate(0, 0)";
            this.track.style.transition = "none";

            // 删除指示器、按钮事件
            if (this.indicatorContainer) this.indicatorContainer.innerHTML = '';
            if (this.prevBtn) this.prevBtn.style.display = 'none';
            if (this.nextBtn) this.nextBtn.style.display = 'none';

            return; // 🚫 直接结束，不再执行后面的轮播逻辑
        }

        // ✅ 原本的初始化逻辑继续执行
        this.initSlides();
        this.createIndicators();
        this.setTranslate(0, 0);
        this.addEvents();
        this.startAutoplay();
        this.addTransitionEndFix();
        this.prevNext();
    }


    // 清理克隆节点
    clearTrack() {
        if (this.options.loop) {
            const originalSlides = Array.from(this.track.querySelectorAll(".swipe-slide:not(.clone)"));
            this.track.innerHTML = '';
            originalSlides.forEach(slide => this.track.appendChild(slide));
            this.slides = Array.from(this.track.children);
        }
    }

    // 初始化 slides，包括克隆
    initSlides() {
        if (!this.options.loop) return;
        const containerSize = this.options.vertical ? this.root.offsetHeight : this.root.offsetWidth;
        const slideSize = this.getSlideSize() || 1;
        this.visibleCount = Math.round(containerSize / slideSize);

        // 前 clone
        for (let i = this.slides.length - this.visibleCount; i < this.slides.length; i++) {
            if (this.slides[i]) {
                const clone = this.slides[i].cloneNode(true);
                clone.classList.add('clone');
                this.track.insertBefore(clone, this.slides[0]);
            }
        }

        // 后 clone
        for (let i = 0; i < this.visibleCount; i++) {
            if (this.slides[i]) {
                const clone = this.slides[i].cloneNode(true);
                clone.classList.add('clone');
                this.track.appendChild(clone);
            }
        }

        this.slides = Array.from(this.track.children);
        this.activeIndex = this.visibleCount;
    }

    getSlideSize() {
        if (this.slides.length === 0) return 0;
        return this.options.vertical ? this.slides[0].offsetHeight : this.slides[0].offsetWidth;
    }

    getRealCount() {
        if (!this.options.loop) return this.slides.length;
        return this.slides.length - 2 * this.visibleCount;
    }

    getRealIndex() {
        if (!this.options.loop) return this.activeIndex;
        const total = this.slides.length;
        const vc = this.visibleCount;
        if (this.activeIndex < vc) return total - 2 * vc + this.activeIndex - vc;
        if (this.activeIndex >= total - vc) return this.activeIndex - (total - 2 * vc) - vc;
        return this.activeIndex - vc;
    }

    setTranslate(duration = this.options.duration, delta = this.delta) {
        const size = this.getSlideSize();
        const translate = this.options.vertical
            ? `translateY(${-this.activeIndex * size + delta}px)`
            : `translateX(${-this.activeIndex * size + delta}px)`;
        this.track.style.transition = duration + 'ms';
        this.track.style.transform = translate;
    }

    addTransitionEndFix() {
        this.track.addEventListener("transitionend", () => {
            if (!this.options.loop) return;
            const total = this.slides.length;
            const vc = this.visibleCount;
            if (this.activeIndex < vc) this.activeIndex = total - 2 * vc + this.activeIndex;
            else if (this.activeIndex >= total - vc) this.activeIndex -= (total - 2 * vc);
            this.setTranslate(0, 0);
            this.lockDrag(false);
        });
    }

    lockDrag(flag) {
        this.locked = flag;
    }

    correctPosition() {
        if (!this.options.loop) return;
        const total = this.slides.length;
        if (this.activeIndex < this.visibleCount) this.activeIndex = total - 2 * this.visibleCount + this.activeIndex;
        if (this.activeIndex >= total - this.visibleCount) this.activeIndex -= (total - 2 * this.visibleCount);
    }

    createIndicators() {
        if (!this.indicatorContainer) return;
        this.indicatorContainer.innerHTML = '';
        const realCount = this.getRealCount();
        for (let i = 0; i < realCount; i++) {
            const dot = document.createElement('span');
            if (i === 0) dot.classList.add("active");
            dot.addEventListener('click', () => this.swipeTo(i));
            this.indicatorContainer.appendChild(dot);
        }
        this.indicators = Array.from(this.indicatorContainer.children);
    }

    updateIndicators() {
        if (!this.indicators) return;
        const realIndex = this.getRealIndex();
        this.indicators.forEach((dot, i) => dot.classList.toggle('active', i === realIndex));
    }

    prevNext() {
        if (!this.prevBtn || !this.nextBtn) return;
        this.prevBtn.onclick = () => {
            const prev = (this.getRealIndex() - 1);
            this.swipeTo(prev > -1 ? prev : -1);
        };
        this.nextBtn.onclick = () => {
            const realCount = this.getRealCount() + 1;
            const next = (this.getRealIndex() + 1) % realCount;
            this.swipeTo(next);
        };
    }

    next() {
        if (this.locked) return;
        this.activeIndex++;
        this.lockDrag(true);
        this.setTranslate();
        this.updateIndicators();
    }

    prev() {
        if (this.locked) return;
        this.activeIndex--;
        this.lockDrag(true);
        this.setTranslate();
        this.updateIndicators();
    }

    swipeTo(index) {
        if (this.locked) return;
        clearInterval(this.timer);
        this.activeIndex = this.options.loop ? index + this.visibleCount : index;
        this.setTranslate();
        this.lockDrag(true);
        this.updateIndicators();
        setTimeout(() => {
            this.lockDrag(false);
            this.startAutoplay();
        }, this.options.duration);
    }

    startAutoplay() {
        if (!this.options.autoplay) return;
        clearInterval(this.timer);
        this.timer = setInterval(() => this.next(), this.options.autoplay);
    }

    addEvents() {
        this.removeEvents(); // 先清理之前的事件

        const vertical = this.options.vertical;
        const track = this.track;
        let startX = 0, startY = 0;
        let isScrolling = null;

        const startDrag = pos => {
            this.touchDistance = 0;
            this.dragging = true;
            this.startPos = pos;
            this.delta = 0;
            clearInterval(this.timer);
            track.style.cursor = 'grabbing';
        };
        const moveDrag = (pos, e) => {
            this.touchDistance = pos - this.startPos;
            if (!this.dragging) return;
            this.delta = pos - this.startPos;
            this.setTranslate(0, this.delta);
            if (e) e.preventDefault();
        };
        const endDrag = () => {
            if (!this.dragging) return;
            const slideSize = this.getSlideSize();
            const threshold = slideSize * this.options.dragThreshold;
            let pace = Math.abs(this.delta) > threshold ? (this.delta > 0 ? -1 : 1) : 0;
            this.activeIndex += pace;
            this.delta = 0;
            this.setTranslate();
            this.correctPosition();
            this.updateIndicators();
            this.dragging = false;
            track.style.cursor = '';
            this.startAutoplay();
        };

        this._eventHandlers.touchStart = e => {
            if (e.touches.length > 1) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isScrolling = null;
            startDrag(vertical ? startY : startX);
        };
        this._eventHandlers.touchMove = e => {
            const curX = e.touches[0].clientX;
            const curY = e.touches[0].clientY;
            if (isScrolling === null) isScrolling = Math.abs(curY - startY) > Math.abs(curX - startX);
            if (!isScrolling) {
                e.preventDefault();
                moveDrag(vertical ? curY : curX, e);
            }
        };
        this._eventHandlers.touchEnd = endDrag;
        this._eventHandlers.mouseDown = e => {
            e.preventDefault();
            e.stopPropagation();
            if (e.button !== 0) return;
            this.isMouseDown = true;
            startDrag(vertical ? e.clientY : e.clientX);
        };
        this._eventHandlers.mouseMove = e => {
            if (!this.isMouseDown) return;
            moveDrag(vertical ? e.clientY : e.clientX);
        };
        this._eventHandlers.mouseUp = () => {
            if (!this.isMouseDown) return;
            this.isMouseDown = false;
            endDrag();
        };
        this._eventHandlers.click = e => {
            if (Math.abs(this.touchDistance) > this.options.clickThreshold) {
                e.stopImmediatePropagation();
                e.preventDefault();
            }
        };

        track.addEventListener('touchstart', this._eventHandlers.touchStart, { passive: true });
        track.addEventListener('touchmove', this._eventHandlers.touchMove, { passive: false, capture: false });
        track.addEventListener('touchend', this._eventHandlers.touchEnd);
        track.addEventListener('mousedown', this._eventHandlers.mouseDown, true);
        document.addEventListener('mousemove', this._eventHandlers.mouseMove, true);
        document.addEventListener('mouseup', this._eventHandlers.mouseUp, true);
        track.addEventListener('click', this._eventHandlers.click, true);
    }

    removeEvents() {
        const track = this.track;
        const h = this._eventHandlers;
        if (!h) return;
        track.removeEventListener('touchstart', h.touchStart);
        track.removeEventListener('touchmove', h.touchMove);
        track.removeEventListener('touchend', h.touchEnd);
        track.removeEventListener('mousedown', h.mouseDown);
        document.removeEventListener('mousemove', h.mouseMove);
        document.removeEventListener('mouseup', h.mouseUp);
        track.removeEventListener('click', h.click, true);
    }

    addResizeListener() {
        let timer = null;
        window.addEventListener('resize', () => {
            clearTimeout(timer);
            timer = setTimeout(() => this.reInit(), 200);
        });
    }

    reInit() {
        this.removeEvents();
        clearInterval(this.timer);
        this.initAll();
    }
}

class SwipeSwitch {
    constructor(root, options = {}) {
        this.root = root;
        this.track = root.querySelector('.swipe-track');
        this.slides = Array.from(this.track.querySelectorAll(".swipe-slide"));
        this.prevBth = root.querySelector('.carousel-btn.prev');
        this.nextBtn = root.querySelector('.carousel-btn.next');

        this.options = Object.assign({
            vertical: false,
            duration: 500,
            initial: 0,
            dragThreshold: 0.3,
            clickThreshold: 5,
            edgeDamping: 0.4
        }, options);

        this.activeIndex = this.options.initial;
        this.delta = 0;
        this.dragging = false;
        this.locked = false;
        this.startPos = 0;
        this.touchDistance = 0;
        this.isMouseDown = false;
        this.trackPoints = [];

        const containerSize = this.options.vertical ? this.root.offsetHeight : this.root.offsetWidth;
        const slideSize = this.getSlideSize() || 1;
        this.visibleCount = Math.round(containerSize / slideSize);

        this.setTranslate(0, 0);
        this.addEvents();

        if (this.prevBth && this.nextBtn) {
            this.prevNext();
            this.updateButtons();
        }
    }

    /* --------- 关键计算 --------- */
    getItemSize() {
        const el = this.slides[0];
        if (!el) return 0;
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        if (this.options.vertical) {
            const margin = parseFloat(style.marginTop) + parseFloat(style.marginBottom);
            return rect.height + margin;
        } else {
            const margin = parseFloat(style.marginLeft) + parseFloat(style.marginRight);
            return rect.width + margin;
        }
    }

    getViewportSize() {
        const rect = this.root.getBoundingClientRect();
        return this.options.vertical ? rect.height : rect.width;
    }

    getItemsPerView() {
        const item = this.getItemSize();
        const vp = this.getViewportSize();
        if (!item || !vp) return 1;
        return Math.max(1, Math.floor(vp / item));
    }

    getMaxIndex() {
        return Math.max(0, this.slides.length - this.getItemsPerView());
    }

    prevNext() {
        this.prevBth.onclick = () => this.prev();
        this.nextBtn.onclick = () => this.next();
    }

    getSlideSize() {
        return this.getItemSize();
    }

    setTranslate(duration = this.options.duration, delta = this.delta) {
        const size = this.getSlideSize();
        const shift = -this.activeIndex * size + delta;
        const translate = this.options.vertical ? `translateY(${shift}px)` : `translateX(${shift}px)`;
        this.track.style.transition = duration + 'ms';
        this.track.style.transform = translate;
        this.updateButtons();
    }

    updateButtons() {
        if (!this.prevBth || !this.nextBtn) return;

        const maxIndex = this.getMaxIndex();
        if (this.activeIndex <= 0) {
            this.prevBth.classList.add("hidden-btn");
        } else {
            this.prevBth.classList.remove("hidden-btn");
        }

        if (this.activeIndex >= maxIndex) {
            this.nextBtn.classList.add("hidden-btn");
        } else {
            this.nextBtn.classList.remove("hidden-btn");
        }
    }

    next() {
        if (this.locked) return;
        const maxIndex = this.getMaxIndex();
        if (this.activeIndex < maxIndex) {
            this.activeIndex++;
        }
        this.setTranslate();
    }

    prev() {
        if (this.locked) return;
        if (this.activeIndex > 0) {
            this.activeIndex--;
        }
        this.setTranslate();
    }

    swipeTo(index) {
        if (this.locked) return;
        this.activeIndex = index;
        this.setTranslate();
        this.locked = true;
        setTimeout(() => {
            this.locked = false;
        }, this.options.duration);
    }


    addEvents() {
        const vertical = this.options.vertical;
        const track = this.track;

        let startX = 0, startY = 0;
        let isScrolling = null;

        const startDrag = (pos) => {
            this.touchDistance = 0;
            if (this.locked) return;
            this.dragging = true;
            this.startPos = pos;
            this.delta = 0;
            this.trackPoints = [];
            track.style.cursor = 'grabbing';
            this.track.style.transition = '0ms';
        };

        const moveDrag = (pos, e) => {
            this.touchDistance = pos - this.startPos;
            if (!this.dragging) return;

            let delta = this.touchDistance;
            const maxIndex = this.getMaxIndex();

            if ((this.activeIndex === 0 && delta > 0) ||
                (this.activeIndex === maxIndex && delta < 0)) {
                delta = delta * (this.options.edgeDamping ?? 0.4);
            }

            this.delta = delta;
            this.setTranslate(0, this.delta);

            const now = Date.now();
            this.trackPoints.push({time: now, pos});
            this.trackPoints = this.trackPoints.filter(p => now - p.time <= 100);

            if (e) e.preventDefault();
        };

        const endDrag = () => {
            if (!this.dragging) return;
            const size = this.getSlideSize();
            const threshold = size * this.options.dragThreshold;

            const last = this.trackPoints[this.trackPoints.length - 1];
            const first = this.trackPoints[0];
            let velocity = 0;
            if (last && first && last !== first) {
                const deltaPos = last.pos - first.pos;
                const deltaTime = (last.time - first.time) / 1000;
                velocity = deltaPos / deltaTime;
            }

            let pace = 0;
            if (Math.abs(this.delta) > threshold) {
                pace = Math.round(this.delta / size);
            }

            const velocityThreshold = 600;
            if (Math.abs(velocity) > velocityThreshold) {
                const extra = Math.round(velocity / velocityThreshold);
                pace += extra;
            }

            pace = -pace;
            this.activeIndex += pace;

            const maxIndex = this.getMaxIndex();
            if (this.activeIndex < 0) this.activeIndex = 0;
            if (this.activeIndex > maxIndex) this.activeIndex = maxIndex;

            this.delta = 0;
            this.setTranslate(this.options.duration, 0);

            this.dragging = false;
            track.style.cursor = '';
            this.trackPoints = [];
        };

        // --- 移动端 ---
        track.addEventListener('touchstart', e => {
            if (e.touches.length > 1) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            isScrolling = null;
            startDrag(vertical ? startY : startX);
        });

        track.addEventListener('touchmove', e => {
            const curX = e.touches[0].clientX;
            const curY = e.touches[0].clientY;

            if (isScrolling === null) {
                const deltaX = Math.abs(curX - startX);
                const deltaY = Math.abs(curY - startY);
                isScrolling = deltaY > deltaX; // 判断方向
            }

            if (isScrolling) {
                return null // 纵向滚动 → 不拦截
            } else {
                e.preventDefault(); // 横向拖动 → 阻止页面滚动
                moveDrag(vertical ? curY : curX, e);
            }
        }, {passive: false});

        track.addEventListener('touchend', endDrag);

        // --- PC端 ---
        track.addEventListener('mousedown', e => {
            if (e.button !== 0) return;
            e.preventDefault();
            e.stopPropagation();
            this.isMouseDown = true;
            startDrag(vertical ? e.clientY : e.clientX);
        }, true);

        document.addEventListener('mousemove', e => {
            if (!this.isMouseDown) return;
            moveDrag(vertical ? e.clientY : e.clientX, e);
        }, true);

        document.addEventListener('mouseup', () => {
            if (!this.isMouseDown) return;
            this.isMouseDown = false;
            endDrag();
        }, true);

        track.style.userSelect = 'none';

        track.addEventListener('click', e => {
            if (Math.abs(this.touchDistance) > this.options.clickThreshold) {
                e.stopImmediatePropagation();
                e.preventDefault();
            }
        }, true);

        window.addEventListener('resize', () => {
            const maxIndex = this.getMaxIndex();
            if (this.activeIndex > maxIndex) this.activeIndex = maxIndex;
            this.setTranslate(0, 0);
        });
    }
}