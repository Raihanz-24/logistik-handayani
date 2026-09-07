const PWA_NAVIGATION_TIMEOUT = 12000;
const PWA_NAVIGATION_TRANSITION = 160;

if (! window.__handayaniNavigationSkeletonInitialized) {
    window.__handayaniNavigationSkeletonInitialized = true;

    let safetyTimer = null;
    let hideTimer = null;

    const isInstalledPwa = () => (
        window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true
    );

    const skeleton = () => document.querySelector('[data-pwa-navigation-skeleton]');

    const setMainBusyState = (isBusy) => {
        const main = document.querySelector('.fi-main');

        if (! main) {
            return;
        }

        if (isBusy) {
            main.setAttribute('aria-busy', 'true');
        } else {
            main.removeAttribute('aria-busy');
        }
    };

    const hideSkeleton = () => {
        window.clearTimeout(safetyTimer);
        window.clearTimeout(hideTimer);

        const element = skeleton();

        if (! element) {
            setMainBusyState(false);

            return;
        }

        element.classList.remove('is-visible');
        element.setAttribute('aria-hidden', 'true');
        setMainBusyState(false);

        hideTimer = window.setTimeout(() => {
            element.hidden = true;
        }, PWA_NAVIGATION_TRANSITION);
    };

    const showSkeleton = () => {
        if (! isInstalledPwa()) {
            return;
        }

        const element = skeleton();

        if (! element) {
            return;
        }

        window.clearTimeout(hideTimer);
        window.clearTimeout(safetyTimer);
        element.hidden = false;
        element.setAttribute('aria-hidden', 'false');
        setMainBusyState(true);

        window.requestAnimationFrame(() => {
            element.classList.add('is-visible');
        });

        safetyTimer = window.setTimeout(hideSkeleton, PWA_NAVIGATION_TIMEOUT);
    };

    // `livewire:navigate` fires as soon as navigation starts, before the server response arrives.
    document.addEventListener('livewire:navigate', showSkeleton);
    document.addEventListener('livewire:navigated', hideSkeleton);
    window.addEventListener('pageshow', hideSkeleton);
    window.addEventListener('online', hideSkeleton);
}
