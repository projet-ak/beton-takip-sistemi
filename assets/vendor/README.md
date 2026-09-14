# assets/vendor

Dışarıdan alınan, **değiştirilmeyen** kütüphaneler. Buradaki dosyalara elle
dokunma; sürüm yükseltmek gerekirse kaynağından yeniden kopyala.

## js-aruco2 v2.0.0 — `cv.js` + `aruco.js`

ArUco işaretçi okuma ve kart üretimi (PTS modülü: `pts/kiosk.php`, `pts/kartlar.php`).

- Kaynak: https://github.com/damianofalcioni/js-aruco2 (`src/cv.js`, `src/aruco.js`)
- Lisans: MIT — © 2020 Damiano Falcioni, © 2011 Juan Mellado
- ⚠ **Düz `<script>`** olarak yüklenir ve global `CV` / `AR` tanımlar; ES modülü
  DEĞİLDİR, build adımı gerektirmez. Bu yüzden React/Vite'a ihtiyaç duymadan
  PHP sayfalarında doğrudan kullanılır.
- `ARUCO_MIP_36h12` sözlüğü (250 kod → geçerli ID aralığı **0–249**) `aruco.js`
  içinde gömülüdür; ayrı sözlük dosyası kopyalamaya gerek yoktur.
- Kart çizimi `AR.Dictionary(...).generateSVG(id)` ile üretilir — okuma ile
  birebir aynı kütüphaneden geldiği için kiosk kesin okur.
