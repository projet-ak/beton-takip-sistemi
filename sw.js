/* ERN Beton Takip — Service Worker: KENDİNİ İMHA (v3)
 *
 * Önceki service worker, dashboard gibi sayfaların ESKİ HTML kopyasını dondurup
 * gösteriyordu (veri güncellense bile ekran değişmiyordu). Bu sürüm hiçbir şeyi
 * önbelleğe almaz; devreye girer girmez TÜM önbelleği siler, kendini kaydını
 * kaldırır ve açık sekmeleri sunucudan yeniden yükler. Böylece donma kalıcı biter.
 *
 * Tarayıcı, gezinme sırasında sw.js'i her zaman ağdan kontrol ettiği için bu
 * dosya kullanıcı hiçbir şey yapmadan devreye girer ve eski service worker'ı temizler.
 */
self.addEventListener('install', function() {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil((async function() {
    try {
      // 1) Tüm önbellekleri sil (donmuş dashboard kopyası dahil)
      var keys = await caches.keys();
      await Promise.all(keys.map(function(k){ return caches.delete(k); }));
      // 2) Bu service worker'ın kaydını kaldır
      await self.registration.unregister();
      // 3) Kontrol edilen açık sekmeleri sunucudan taze yükle
      var clients = await self.clients.matchAll({ type: 'window' });
      clients.forEach(function(c){ try { c.navigate(c.url); } catch (_) {} });
    } catch (_) {}
  })());
});

// Hiçbir isteği önbellekten sunma — her zaman doğrudan ağ (varsayılan davranış).
self.addEventListener('fetch', function() { /* respondWith yok = tarayıcı ağdan alır */ });
