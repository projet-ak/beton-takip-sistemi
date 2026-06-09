/* ERN Beton Takip — Service Worker v1 */
const CACHE = 'beton-v1';
const OFFLINE_ASSETS = [
  '/',
  '/login.php',
  '/assets/css/style.css',
  '/assets/js/app.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
  'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap'
];

self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE).then(function(c) {
      return c.addAll(OFFLINE_ASSETS);
    }).catch(function() {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k){ return k !== CACHE; }).map(function(k){ return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  // Sadece GET, PHP sayfaları hariç (dinamik içerik)
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);

  // PHP endpoint'leri ağdan al, hata varsa cache
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    e.respondWith(
      fetch(e.request).catch(function() {
        return caches.match(e.request) || caches.match('/login.php');
      })
    );
    return;
  }

  // Statik varlıklar: cache-first
  e.respondWith(
    caches.match(e.request).then(function(cached) {
      return cached || fetch(e.request).then(function(res) {
        var clone = res.clone();
        caches.open(CACHE).then(function(c){ c.put(e.request, clone); });
        return res;
      });
    }).catch(function() { return caches.match(e.request); })
  );
});
