const mobileSwipeNavigation = (pages = []) => ({
    pages: Array.isArray(pages) ? pages : [],
    currentPath: window.location.pathname,
    cueDirection: null,
    cueProgress: 0,
    gesture: null,
    navigating: false,
    listeners: {},

    init() {
        this.listeners.start = (event) => this.startSwipe(event);
        this.listeners.move = (event) => this.moveSwipe(event);
        this.listeners.end = (event) => this.endSwipe(event);
        this.listeners.cancel = () => this.cancelSwipe();
        this.listeners.navigated = () => {
            this.currentPath = window.location.pathname;
            this.navigating = false;
            this.cancelSwipe();
        };

        document.addEventListener('touchstart', this.listeners.start, { capture: true, passive: true });
        document.addEventListener('touchmove', this.listeners.move, { capture: true, passive: false });
        document.addEventListener('touchend', this.listeners.end, { capture: true, passive: true });
        document.addEventListener('touchcancel', this.listeners.cancel, { capture: true, passive: true });
        document.addEventListener('livewire:navigated', this.listeners.navigated);
    },

    destroy() {
        document.removeEventListener('touchstart', this.listeners.start, true);
        document.removeEventListener('touchmove', this.listeners.move, true);
        document.removeEventListener('touchend', this.listeners.end, true);
        document.removeEventListener('touchcancel', this.listeners.cancel, true);
        document.removeEventListener('livewire:navigated', this.listeners.navigated);
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

        if (targetIndex < 0 || targetIndex >= this.pages.length) {
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

            return;
        }

        this.cueProgress = 1;
        this.navigating = true;
        window.navigator.vibrate?.(8);

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
        }, 90);
    },

    cancelSwipe() {
        this.gesture = null;
        this.resetCue();
    },

    resetCue() {
        this.cueDirection = null;
        this.cueProgress = 0;
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
