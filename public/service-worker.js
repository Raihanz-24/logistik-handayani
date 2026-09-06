const CACHE_VERSION = 'handayani-pwa-v2';
const OFFLINE_URL = '/offline.html';
const PRECACHE_ASSETS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/images/pwa/icon-192.png',
    '/images/pwa/icon-512.png',
    '/images/pwa/icon-maskable-512.png',
    '/images/pwa/apple-touch-icon.png',
];
const CACHEABLE_ASSET_PATHS = ['/build/', '/css/', '/js/', '/images/', '/fonts/'];
const CACHEABLE_EXACT_PATHS = ['/livewire/livewire.js'];

const isCacheableAsset = (url) => (
    url.origin === self.location.origin
    && (
        CACHEABLE_ASSET_PATHS.some((path) => url.pathname.startsWith(path))
        || CACHEABLE_EXACT_PATHS.includes(url.pathname)
    )
);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('handayani-pwa-') && key !== CACHE_VERSION)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type !== 'CACHE_APP_ASSETS' || ! Array.isArray(event.data.urls)) {
        return;
    }

    const urls = [...new Set(event.data.urls)]
        .slice(0, 40)
        .map((value) => {
            try {
                return new URL(value, self.location.origin);
            } catch {
                return null;
            }
        })
        .filter((url) => url && isCacheableAsset(url));

    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => Promise.all(
            urls.map(async (url) => {
                try {
                    const request = new Request(url.href, { credentials: 'same-origin' });
                    const response = await fetch(request);

                    if (response.ok && response.type === 'basic') {
                        await cache.put(request, response);
                    }
                } catch {
                    // One unavailable asset must not cancel the rest of the app-shell download.
                }
            })),
        ),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );

        return;
    }

    const isPublicAsset = isCacheableAsset(url);

    if (! isPublicAsset) {
        return;
    }

    const networkRequest = fetch(request).then((response) => {
        if (response.ok && response.type === 'basic') {
            const responseToCache = response.clone();

            caches.open(CACHE_VERSION)
                .then((cache) => cache.put(request, responseToCache));
        }

        return response;
    });

    event.waitUntil(networkRequest.catch(() => undefined));
    event.respondWith(
        caches.match(request).then((cachedResponse) => cachedResponse || networkRequest),
    );
});
