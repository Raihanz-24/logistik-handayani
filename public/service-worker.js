const CACHE_VERSION = 'handayani-pwa-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE_ASSETS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/images/pwa/icon-192.png',
    '/images/pwa/icon-512.png',
    '/images/pwa/icon-maskable-512.png',
    '/images/pwa/apple-touch-icon.png',
];

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

    const isPublicAsset = [
        '/build/',
        '/css/',
        '/js/',
        '/images/',
    ].some((path) => url.pathname.startsWith(path));

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
