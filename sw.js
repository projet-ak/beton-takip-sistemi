/* ERN Beton Takip — Service Worker v2
 * ÖNEMLİ: HTML sayfaları (dashboard dahil) ASLA cache-first sunulmaz.
 * Önceki sürüm klasör adreslerini (ör. /beton/) statik varlık sanıp cache-first
 * sunuyordu; bu, veri güncellendikten sonra bile dashboard'un ESKİ HTML kopyasını
 * dondurup gösteriyordu. Artık tüm navigasyon/HTML/.php istekleri NETWORK-ONLY
 * (yalnız çevrimdışıyken cache'e düşer). Statik varlıklar cache-first kalır.
 */
const CACHE = 'beton-v2';
const OFFLINE_ASSETS = [
  'login.php',
  'assets/css/style.css',
  'assets/js/app.js'
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
  // Eski tüm önbellekleri (beton-v1 dahil) sil — donmuş dashboard kopyası da gider
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.filter(function(k){ return k !== CACHE; }).map(function(k){ return caches.delete(k); }));
    }).then(function(){ return self.clients.claim(); })
  );
});

// Uygulamadan gelen "hemen sıfırla" mesajı: tüm önbelleği temizle
self.addEventListener('message', function(e) {
  if (e.data === 'sifirla') {
    caches.keys().then(function(keys){ keys.forEach(function(k){ caches.delete(k); }); });
  }
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  var req = e.request;
  var url = new URL(req.url);
  var accept = req.headers.get('accept') || '';

  // HTML / navigasyon / .php / klasör adresleri → NETWORK-ONLY (asla eski kopya)
  var isDocument =
        req.mode === 'navigate' ||
        accept.indexOf('text/html') !== -1 ||
        url.pathname.endsWith('.php') ||
        url.pathname.endsWith('/');

  if (isDocument) {
    e.respondWith(
      fetch(req).catch(function() {
        // Yalnız gerçekten çevrimdışıysa cache/login'e düş
        return caches.match(req).then(function(c){ return c || caches.match('login.php'); });
      })
    );
    return;
  }

  // Statik varlıklar (css/js/font/görsel): cache-first
  e.respondWith(
    caches.match(req).then(function(cached) {
      return cached || fetch(req).then(function(res) {
        var clone = res.clone();
        caches.open(CACHE).then(function(c){ c.put(req, clone); });
        return res;
      });
    }).catch(function() { return caches.match(req); })
  );
});
