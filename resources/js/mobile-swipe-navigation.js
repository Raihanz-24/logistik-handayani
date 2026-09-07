const mobileSwipeNavigation = (pages = []) => ({
    pages: Array.isArray(pages) ? pages : [],
    currentPath: window.location.pathname,
    cueDirection: null,
    cueProgress: 0,
    gesture: null,
    navigating: false,
    listeners: {},
    visualTimer: null,
    navigationTimer: null,

    init() {
        this.listeners.start = (event) => this.startSwipe(event);
        this.listeners.move = (event) => this.moveSwipe(event);
        this.listeners.end = (event) => this.endSwipe(event);
        this.listeners.cancel = () => this.cancelSwipe();
        this.listeners.navigated = () => {
            const direction = document.documentElement.dataset.swipeNavigationDirection || null;
            window.clearTimeout(this.navigationTimer);
            this.currentPath = window.location.pathname;
            this.navigating = false;
            this.gesture = null;
            this.resetCue();
            this.animateIncomingPage(direction);
        };

        document.addEventListener('touchstart', this.listeners.start, { capture: true, passive: true });
        document.addEventListener('touchmove', this.listeners.move, { capture: true, passive: false });
        document.addEventListener('touchend', this.listeners.end, { capture: true, passive: true });
        document.addEventListener('touchcancel', this.listeners.cancel, { capture: true, passive: true });
        document.addEventListener('livewire:navigated', this.listeners.navigated);

        const pendingDirection = document.documentElement.dataset.swipeNavigationDirection;
        if (pendingDirection) {
            window.requestAnimationFrame(() => this.animateIncomingPage(pendingDirection));
        }
    },

    destroy() {
        document.removeEventListener('touchstart', this.listeners.start, true);
        document.removeEventListener('touchmove', this.listeners.move, true);
        document.removeEventListener('touchend', this.listeners.end, true);
        document.removeEventListener('touchcancel', this.listeners.cancel, true);
        document.removeEventListener('livewire:navigated', this.listeners.navigated);
        window.clearTimeout(this.visualTimer);
        window.clearTimeout(this.navigationTimer);
        if (! this.navigating) this.clearPageVisual();
    },

    normalizePath(path) {
        return String(path || '/').replace(/\/+$/, '') || '/';
    },

    pagePath(page) {
        return this.normalizePath(new URL(page.url, window.location.origin).pathname);
    },

    isActive(url, exact = false) {
        const current = this.normalizePath(this.currentPath);
        const target = this.normalizePath(new URL(url, window.location.origin).pathname);

        return exact ? current === target : current === target || current.startsWith(`${target}/`);
    },

    activePageIndex() {
        const current = this.normalizePath(this.currentPath);

        // Keep create/edit/detail screens out of swipe navigation so unfinished work is never lost.
        return this.pages.findIndex((page) => this.pagePath(page) === current);
    },

    startSwipe(event) {
        if (
            this.navigating
            || this.pages.length < 2
            || event.touches.length !== 1
            || ! window.matchMedia('(max-width: 1023px)').matches
            || this.$store.sidebar.isOpen
            || this.shouldIgnoreTarget(event.target)
        ) {
            this.gesture = null;

            return;
        }

        const activeIndex = this.activePageIndex();

        if (activeIndex < 0) {
            this.gesture = null;

            return;
        }

        const touch = event.touches[0];
        this.gesture = {
            startX: touch.clientX,
            startY: touch.clientY,
            lastX: touch.clientX,
            lastY: touch.clientY,
            startedAt: performance.now(),
            axis: null,
            activeIndex,
            targetIndex: null,
            prefetchedIndex: null,
        };
    },

    moveSwipe(event) {
        if (! this.gesture || event.touches.length !== 1) {
            return;
        }

        const touch = event.touches[0];
        const deltaX = touch.clientX - this.gesture.startX;
        const deltaY = touch.clientY - this.gesture.startY;
        const horizontalDistance = Math.abs(deltaX);
        const verticalDistance = Math.abs(deltaY);

        this.gesture.lastX = touch.clientX;
        this.gesture.lastY = touch.clientY;

        if (this.gesture.axis === null) {
            if (Math.max(horizontalDistance, verticalDistance) < 12) {
                return;
            }

            if (verticalDistance >= horizontalDistance * 0.82) {
                this.cancelSwipe();

                return;
            }

            this.gesture.axis = 'horizontal';
        }

        if (this.gesture.axis !== 'horizontal') {
            return;
        }

        const step = deltaX < 0 ? 1 : -1;
        const targetIndex = this.gesture.activeIndex + step;

        event.preventDefault();
        this.updatePageDrag(deltaX, targetIndex < 0 || targetIndex >= this.pages.length);

        if (targetIndex < 0 || targetIndex >= this.pages.length) {
            this.gesture.targetIndex = null;
            this.cueDirection = null;
            this.cueProgress = 0;

            return;
        }

        this.gesture.targetIndex = targetIndex;
        this.cueDirection = step > 0 ? 'next' : 'previous';
        this.cueProgress = Math.min(1, horizontalDistance / 92);

        if (horizontalDistance >= 24) {
            this.prefetchPage(targetIndex);
        }
    },

    endSwipe(event) {
        if (! this.gesture) {
            return;
        }

        const touch = event.changedTouches?.[0];
        const deltaX = (touch?.clientX ?? this.gesture.lastX) - this.gesture.startX;
        const deltaY = (touch?.clientY ?? this.gesture.lastY) - this.gesture.startY;
        const elapsed = performance.now() - this.gesture.startedAt;
        const targetIndex = this.gesture.targetIndex;
        const shouldNavigate = this.gesture.axis === 'horizontal'
            && targetIndex !== null
            && Math.abs(deltaX) >= 72
            && Math.abs(deltaX) > Math.abs(deltaY) * 1.25
            && elapsed <= 1400;

        this.gesture = null;

        if (! shouldNavigate) {
            this.resetCue();
            this.returnPageVisual();

            return;
        }

        const direction = deltaX < 0 ? 'next' : 'previous';
        this.cueProgress = 1;
        this.navigating = true;
        this.commitPageVisual(direction);
        window.navigator.vibrate?.(8);

        window.clearTimeout(this.navigationTimer);
        this.navigationTimer = window.setTimeout(() => {
            this.navigating = false;
            this.returnPageVisual();
        }, 12000);

        window.setTimeout(() => {
            const page = this.pages[targetIndex];
            const targetPath = this.pagePath(page);
            const link = Array.from(this.$el.querySelectorAll('a[href]'))
                .find((item) => this.normalizePath(new URL(item.href).pathname) === targetPath);

            if (link) {
                link.click();
            } else {
                window.location.assign(page.url);
            }

            this.resetCue();
        }, 16);
    },

    cancelSwipe() {
        const hadHorizontalGesture = this.gesture?.axis === 'horizontal';
        this.gesture = null;
        this.resetCue();
        if (hadHorizontalGesture) this.returnPageVisual();
    },

    resetCue() {
        this.cueDirection = null;
        this.cueProgress = 0;
    },

    updatePageDrag(deltaX, isBoundary = false) {
        const root = document.documentElement;
        const distance = Math.abs(deltaX);
        const resistance = isBoundary ? 0.18 : 0.72;
        const maximumOffset = window.innerWidth * (isBoundary ? 0.07 : 0.28);
        const offset = Math.sign(deltaX) * Math.min(distance * resistance, maximumOffset);
        const progress = Math.min(1, distance / 120);

        window.clearTimeout(this.visualTimer);
        root.classList.remove(
            'wm-page-swipe-returning',
            'wm-page-swipe-entering-next',
            'wm-page-swipe-entering-previous',
        );
        root.style.setProperty('--wm-page-swipe-offset', `${offset}px`);
        root.style.setProperty('--wm-page-swipe-opacity', String(1 - (progress * 0.12)));
        root.classList.add('wm-page-swipe-dragging');
    },

    returnPageVisual() {
        const root = document.documentElement;

        window.clearTimeout(this.visualTimer);
        root.classList.remove(
            'wm-page-swipe-dragging',
            'wm-page-swipe-leaving-next',
            'wm-page-swipe-leaving-previous',
        );
        delete root.dataset.swipeNavigationDirection;
        root.classList.add('wm-page-swipe-returning');
        this.visualTimer = window.setTimeout(() => this.clearPageVisual(), 260);
    },

    commitPageVisual(direction) {
        const root = document.documentElement;
        const exitDistance = Math.min(220, Math.max(96, window.innerWidth * 0.34));

        window.clearTimeout(this.visualTimer);
        root.classList.remove(
            'wm-page-swipe-dragging',
            'wm-page-swipe-returning',
            'wm-page-swipe-leaving-next',
            'wm-page-swipe-leaving-previous',
        );
        root.dataset.swipeNavigationDirection = direction;
        root.style.setProperty(
            '--wm-page-swipe-exit',
            `${direction === 'next' ? -exitDistance : exitDistance}px`,
        );
        root.classList.add(`wm-page-swipe-leaving-${direction}`);
    },

    animateIncomingPage(direction) {
        const root = document.documentElement;

        this.clearPageVisual();
        if (! ['next', 'previous'].includes(direction)) return;

        root.classList.add(`wm-page-swipe-entering-${direction}`);
        this.visualTimer = window.setTimeout(() => this.clearPageVisual(), 300);
    },

    clearPageVisual() {
        const root = document.documentElement;

        window.clearTimeout(this.visualTimer);
        root.classList.remove(
            'wm-page-swipe-dragging',
            'wm-page-swipe-returning',
            'wm-page-swipe-leaving-next',
            'wm-page-swipe-leaving-previous',
            'wm-page-swipe-entering-next',
            'wm-page-swipe-entering-previous',
        );
        root.style.removeProperty('--wm-page-swipe-offset');
        root.style.removeProperty('--wm-page-swipe-opacity');
        root.style.removeProperty('--wm-page-swipe-exit');
        delete root.dataset.swipeNavigationDirection;
    },

    prefetchPage(targetIndex) {
        if (this.gesture?.prefetchedIndex === targetIndex) {
            return;
        }

        const page = this.pages[targetIndex];
        const targetPath = this.pagePath(page);
        const link = Array.from(this.$el.querySelectorAll('a[href]'))
            .find((item) => this.normalizePath(new URL(item.href).pathname) === targetPath);

        if (! link) {
            return;
        }

        this.gesture.prefetchedIndex = targetIndex;
        link.dispatchEvent(new MouseEvent('mouseenter', { bubbles: false }));
    },

    shouldIgnoreTarget(target) {
        if (! (target instanceof Element)) {
            return true;
        }

        if (target.closest([
            'input',
            'textarea',
            'select',
            'option',
            'button',
            'a',
            'label',
            'video',
            'canvas',
            'dialog',
            '[contenteditable="true"]',
            '[role="button"]',
            '[role="dialog"]',
            '[role="slider"]',
            '[data-swipe-navigation-ignore]',
            '.fi-modal',
            '.fi-dropdown-panel',
            '.fm-live-camera',
            '.fm-server-viewer',
            '.ff-viewer',
            '.fme-result-dialog',
        ].join(','))) {
            return true;
        }

        for (let element = target; element && element !== document.body; element = element.parentElement) {
            const style = window.getComputedStyle(element);
            const scrollsHorizontally = ['auto', 'scroll'].includes(style.overflowX)
                && element.scrollWidth > element.clientWidth + 4;

            if (scrollsHorizontally) {
                return true;
            }
        }

        return false;
    },
});

window.mobileSwipeNavigation = mobileSwipeNavigation;
