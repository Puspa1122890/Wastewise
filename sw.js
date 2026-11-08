/* WasteWise service worker.
 * Only the app shell (styles, scripts, icons, offline page) is cached.
 * Pages with household data are never cached, so nothing private is stored
 * on the device and old data is never shown as if it were current. */
var CACHE = 'wastewise-shell-v2';
var SHELL = ['offline.html', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png'];

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }));
    self.skipWaiting();
});
self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }));
    self.clients.claim();
});
self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') { return; }
    var url = new URL(req.url);
    if (url.origin !== location.origin) { return; }
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(function () { return caches.match('offline.html'); }));
        return;
    }
    if (url.pathname.indexOf('/assets/') !== -1) {
        // Network first, so an updated script or style is never hidden by an old copy.
        e.respondWith(fetch(req).then(function (res) {
            var copy = res.clone();
            caches.open(CACHE).then(function (c) { c.put(req, copy); });
            return res;
        }).catch(function () { return caches.match(req); }));
    }
});
