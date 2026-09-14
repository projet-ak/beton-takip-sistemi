<?php
/**
 * pts/kiosk.php — Kart okutma ekranı (tablet / kiosk cihazı)
 *
 * Tam ekran, sade, dokunmatik için büyük yazı. Sistemin normal kabuğunu (sidebar,
 * topbar) KULLANMAZ: cihaz duvara asılı durur, gezinmesi olmamalı.
 *
 * ⚠ ArUco tespiti **TARAYICIDA** yapılır (assets/vendor/aruco.js — js-aruco2);
 * sunucuya yalnız marker ID gider. Bu yüzden kiosk cihazına ya da sunucuya OpenCV
 * kurmak gerekmez.
 *
 * ⚠ Cihaz kimliği: `api/pts_scan.php` her istekte `X-Checkpoint-Key` bekler.
 * Anahtar bu ekranda BİR KEZ girilir, `localStorage`'da kalır ve kaydedilmeden
 * önce sunucuya doğrulatılır (yanlış yapıştırılan anahtarın ilk kart okutulana
 * kadar fark edilmemesi kötü olurdu).
 *
 * ⚠ Kamera izni yalnız GÜVENLİ BAĞLAMDA verilir: HTTPS ya da localhost. Cihaz ağdan
 * açılacaksa sertifika şart — ekranda bunu açıkça söyleriz.
 *
 * Sayfa oturum ister (yetkisiz kişi kurulum ekranını görmesin); tablet bir kez
 * giriş yapıp açık kalır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_pts.php';
require_once __DIR__ . '/_ortak.php';
pts_semasi_kur($pdoPts);
?><!doctype html>
<html lang="tr" data-dark="0">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Kart Okut — Personel Takip</title>
<link rel="icon" href="<?= $rootPath ?>assets/icons/favicon-32.png" sizes="32x32">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{--ern:#00584E;--ern-dark:#013F38;--ern-light:#0B7A6C}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;font-family:Outfit,system-ui,sans-serif;color:#fff;
       background:linear-gradient(160deg,var(--ern-dark),var(--ern) 55%,var(--ern-light));
       display:flex;flex-direction:column}
  .kiosk-ust{display:flex;align-items:center;gap:.75rem;padding:.75rem 1.25rem;
             background:rgba(0,0,0,.18);font-size:.9rem}
  .kiosk-ust .nokta{font-weight:700}
  .kiosk-govde{flex:1;display:flex;gap:1.25rem;padding:1.25rem;align-items:stretch;flex-wrap:wrap}
  .kiosk-kamera{flex:1 1 420px;min-width:320px;position:relative;
                background:#000;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.35)}
  .kiosk-kamera video{width:100%;height:100%;object-fit:cover;display:block}
  .kiosk-kamera canvas{position:absolute;inset:0;width:100%;height:100%;opacity:.85;pointer-events:none}
  .kiosk-sonuc{flex:1 1 380px;min-width:300px;display:flex;flex-direction:column;justify-content:center;
               background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);
               border-radius:16px;padding:1.5rem;text-align:center;backdrop-filter:blur(6px)}
  .kiosk-ad{font-size:2.1rem;font-weight:700;line-height:1.15;margin:.35rem 0}
  .kiosk-yon{display:inline-flex;align-items:center;gap:.5rem;font-size:1.6rem;font-weight:700;
             padding:.4rem 1.1rem;border-radius:999px;margin:.5rem 0}
  .yon-giris{background:#198754;color:#fff}
  .yon-cikis{background:#495057;color:#fff}
  .kiosk-saat{font-size:1.1rem;opacity:.85}
  .kiosk-bos{opacity:.7;font-size:1.15rem}
  .kiosk-hata{background:#dc3545;border-radius:12px;padding:1rem;font-size:1.1rem;font-weight:600}
  .kiosk-tekrar{background:rgba(255,193,7,.22);border:1px solid rgba(255,193,7,.5);
                border-radius:10px;padding:.5rem;font-size:.95rem;margin-top:.5rem}
  .kiosk-alt{padding:.6rem 1.25rem;background:rgba(0,0,0,.2);font-size:.82rem;opacity:.85;
             display:flex;gap:1rem;flex-wrap:wrap;align-items:center}
  .kurulum{max-width:560px;margin:auto;padding:2rem;background:rgba(255,255,255,.1);
           border:1px solid rgba(255,255,255,.2);border-radius:16px}
  .form-control,.form-select{background:rgba(255,255,255,.92)}
  a{color:#9ff0e3}
</style>
</head>
<body>

<!-- ── 1) CİHAZ KURULUMU: anahtar girilmemişse yalnız bu görünür ───────────── -->
<div id="kurulumEkran" class="kiosk-govde" style="display:none">
  <div class="kurulum">
    <h4 class="mb-1"><i class="bi bi-door-open me-2"></i>Kiosk Kurulumu</h4>
    <p class="small opacity-75">Bu cihazın hangi geçiş noktası olduğunu bir kez tanımlayın.
       Anahtarı <strong>Geçiş Noktaları</strong> ekranından kopyalayın.</p>
    <label class="form-label small">Cihaz anahtarı</label>
    <input id="anahtarGiris" class="form-control font-monospace mb-2" autocomplete="off" spellcheck="false">
    <div id="kurulumMesaj" class="small mb-2"></div>
    <button id="anahtarKaydet" class="btn btn-light w-100 fw-semibold">
      <i class="bi bi-check-lg me-1"></i>Doğrula ve Kaydet</button>
    <div class="mt-3 small"><a href="noktalar.php">← Geçiş Noktaları</a></div>
  </div>
</div>

<!-- ── 2) OKUMA EKRANI ─────────────────────────────────────────────────────── -->
<div id="okumaEkran" style="display:none;flex:1;flex-direction:column">
  <div class="kiosk-ust">
    <i class="bi bi-camera-video"></i>
    <span class="nokta" id="noktaAd">—</span>
    <span class="badge bg-light text-dark" id="noktaYon"></span>
    <div class="ms-auto d-flex gap-2 align-items-center">
      <select id="kameraSec" class="form-select form-select-sm" style="max-width:220px"></select>
      <select id="yonSec" class="form-select form-select-sm" style="max-width:190px">
        <option value="">Nokta ayarı</option>
        <option value="giris">Yalnız GİRİŞ</option>
        <option value="cikis">Yalnız ÇIKIŞ</option>
      </select>
      <button id="sifirla" class="btn btn-sm btn-outline-light" title="Cihaz anahtarını sil"><i class="bi bi-gear"></i></button>
    </div>
  </div>

  <div class="kiosk-govde">
    <div class="kiosk-kamera">
      <video id="video" playsinline muted></video>
      <canvas id="izCanvas"></canvas>
    </div>
    <div class="kiosk-sonuc" id="sonucKutu">
      <div class="kiosk-bos" id="bosMesaj">
        <i class="bi bi-person-vcard" style="font-size:3rem;opacity:.5"></i>
        <div class="mt-2">Kartınızı kameraya gösterin</div>
      </div>
      <div id="sonucIcerik" style="display:none"></div>
    </div>
  </div>

  <div class="kiosk-alt">
    <span id="durumMetni">Kamera hazırlanıyor…</span>
    <span class="ms-auto" id="saatMetni"></span>
  </div>
</div>

<canvas id="isCanvas" style="display:none"></canvas>

<script src="<?= $rootPath ?>assets/vendor/cv.js"></script>
<script src="<?= $rootPath ?>assets/vendor/aruco.js"></script>
<script>
(function(){
  'use strict';
  var SOZLUK   = <?= json_encode(PTS_SOZLUK) ?>;
  var SCAN_URL = <?= json_encode($rootPath . 'api/pts_scan.php') ?>;
  var ANAHTAR_KEY = 'pts_cihaz_anahtari', KAMERA_KEY = 'pts_kamera', YON_KEY = 'pts_yon';
  // Aynı marker bu süre boyunca sunucuya TEKRAR gönderilmez. Sunucu tarafında da
  // debounce var; bu istemci kilidi gereksiz ağ trafiğini keser.
  var ISTEMCI_KILIT_MS = 3000;
  var FOTO_KALITE = 0.72;

  // localStorage gizli sekmede / depolama kapalıyken patlayabilir — her erişim korumalı.
  function oku(k){ try { return localStorage.getItem(k); } catch(e){ return null; } }
  function yaz(k,v){ try { localStorage.setItem(k,v); } catch(e){} }
  function sil(k){ try { localStorage.removeItem(k); } catch(e){} }

  var $ = function(id){ return document.getElementById(id); };
  var anahtar = oku(ANAHTAR_KEY) || '';
  var nokta = null, detector = null, akim = null, kareId = 0;
  var sonGonderim = { marker: null, at: 0 };

  try { detector = new AR.Detector({ dictionaryName: SOZLUK }); }
  catch(e){ console.error('ArUco sözlüğü yüklenemedi', e); }

  // ── saat ──
  setInterval(function(){
    var d = new Date();
    $('saatMetni').textContent = d.toLocaleDateString('tr-TR') + ' ' +
      d.toLocaleTimeString('tr-TR', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
  }, 1000);

  // ── 1) kurulum ──
  function kurulumGoster(mesaj){
    $('kurulumEkran').style.display = 'flex';
    $('okumaEkran').style.display   = 'none';
    if (mesaj) { $('kurulumMesaj').textContent = mesaj; $('kurulumMesaj').className = 'small mb-2 text-warning'; }
    $('anahtarGiris').value = anahtar;
  }
  $('anahtarKaydet').addEventListener('click', function(){
    var deger = $('anahtarGiris').value.trim();
    if (!deger) { $('kurulumMesaj').textContent = 'Anahtar boş olamaz.'; return; }
    $('kurulumMesaj').textContent = 'Doğrulanıyor…';
    $('kurulumMesaj').className = 'small mb-2';
    fetch(SCAN_URL + '?whoami=1', { headers: { 'X-Checkpoint-Key': deger } })
      .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
      .then(function(x){
        if (!x.ok || !x.j.ok) throw new Error(x.j.hata || 'Anahtar doğrulanamadı.');
        anahtar = deger; yaz(ANAHTAR_KEY, deger); nokta = x.j.nokta;
        basla();
      })
      .catch(function(e){
        $('kurulumMesaj').textContent = e.message;
        $('kurulumMesaj').className = 'small mb-2 text-warning';
      });
  });
  $('sifirla').addEventListener('click', function(){
    if (!confirm('Cihaz anahtarı silinecek ve kurulum ekranı açılacak. Devam?')) return;
    sil(ANAHTAR_KEY); anahtar = '';
    if (akim) { akim.getTracks().forEach(function(t){ t.stop(); }); akim = null; }
    cancelAnimationFrame(kareId);
    kurulumGoster('');
  });

  // ── 2) okuma ──
  function basla(){
    $('kurulumEkran').style.display = 'none';
    $('okumaEkran').style.display   = 'flex';
    $('noktaAd').textContent  = nokta ? (nokta.ad + ' (' + nokta.kod + ')') : '—';
    $('noktaYon').textContent = nokta ? ({otomatik:'Otomatik', giris:'Yalnız GİRİŞ', cikis:'Yalnız ÇIKIŞ'}[nokta.yon] || nokta.yon) : '';
    $('yonSec').value = oku(YON_KEY) || '';
    kameraBaslat();
  }
  $('yonSec').addEventListener('change', function(){ yaz(YON_KEY, this.value); });
  $('kameraSec').addEventListener('change', function(){ yaz(KAMERA_KEY, this.value); kameraBaslat(); });

  function durum(metin, hata){
    $('durumMetni').textContent = metin;
    $('durumMetni').style.color = hata ? '#ffd0d0' : '';
  }

  function kameraBaslat(){
    if (akim) { akim.getTracks().forEach(function(t){ t.stop(); }); akim = null; }
    cancelAnimationFrame(kareId);

    // Tarayıcı kameraya yalnız güvenli bağlamda izin verir; aksi halde
    // navigator.mediaDevices HİÇ tanımlanmaz ve sebebi anlaşılmaz görünür.
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      durum('Kamera açılamıyor: bu sayfa HTTPS ya da localhost üzerinden açılmalı. Şu an: ' + location.origin, true);
      return;
    }
    var secili = oku(KAMERA_KEY);
    var kosul = secili ? { deviceId: { exact: secili }, width: 640, height: 480 } : { width: 640, height: 480 };
    navigator.mediaDevices.getUserMedia({ video: kosul })
      .then(function(s){
        akim = s;
        var v = $('video'); v.srcObject = s;
        return v.play().then(function(){
          // Cihaz etiketleri ancak izin verildikten SONRA dolu gelir.
          return navigator.mediaDevices.enumerateDevices();
        });
      })
      .then(function(cihazlar){
        var sel = $('kameraSec'); sel.innerHTML = '';
        (cihazlar || []).filter(function(d){ return d.kind === 'videoinput'; })
          .forEach(function(d, i){
            var o = document.createElement('option');
            o.value = d.deviceId; o.textContent = d.label || ('Kamera ' + (i + 1));
            sel.appendChild(o);
          });
        var s2 = oku(KAMERA_KEY); if (s2) sel.value = s2;
        durum('Kart bekleniyor…');
        kareId = requestAnimationFrame(dongu);
      })
      .catch(function(e){ durum('Kamera açılamadı: ' + e.message, true); });
  }

  function cerceve(ctx, kose){
    ctx.strokeStyle = '#00e0c6'; ctx.lineWidth = 4; ctx.beginPath();
    ctx.moveTo(kose[0].x, kose[0].y);
    for (var i = 1; i < kose.length; i++) ctx.lineTo(kose[i].x, kose[i].y);
    ctx.closePath(); ctx.stroke();
  }

  function dongu(){
    var v = $('video'), c = $('isCanvas'), iz = $('izCanvas');
    if (detector && v && v.readyState === v.HAVE_ENOUGH_DATA) {
      var ctx = c.getContext('2d', { willReadFrequently: true });
      c.width = v.videoWidth; c.height = v.videoHeight;
      ctx.drawImage(v, 0, 0, c.width, c.height);
      var markerlar = [];
      try { markerlar = detector.detect(ctx.getImageData(0, 0, c.width, c.height)) || []; }
      catch(e){ /* kare bozuksa sessiz geç */ }

      iz.width = c.width; iz.height = c.height;
      var ictx = iz.getContext('2d');
      ictx.clearRect(0, 0, iz.width, iz.height);
      markerlar.forEach(function(m){ cerceve(ictx, m.corners); });

      if (markerlar.length > 0) gonder(markerlar[0].id, c);
    }
    kareId = requestAnimationFrame(dongu);
  }

  function gonder(markerId, canvas){
    var simdi = Date.now();
    if (sonGonderim.marker === markerId && simdi - sonGonderim.at < ISTEMCI_KILIT_MS) return;
    sonGonderim = { marker: markerId, at: simdi };

    var foto = null;
    // Kanıt fotoğrafı kaydın tamamlayıcısı; üretilemezse geçiş yine kaydedilir.
    try { foto = canvas.toDataURL('image/jpeg', FOTO_KALITE); } catch(e){}

    var govde = { marker_id: markerId, sozluk: SOZLUK };
    var y = $('yonSec').value; if (y) govde.yon = y;
    if (foto) govde.foto = foto;

    fetch(SCAN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Checkpoint-Key': anahtar },
      body: JSON.stringify(govde)
    })
      .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
      .then(function(x){
        if (!x.ok || !x.j.ok) throw new Error(x.j.hata || 'Okuma reddedildi.');
        sonucGoster(x.j);
      })
      .catch(function(e){ hataGoster('ArUco ' + markerId + ': ' + e.message); });
  }

  var temizleZaman = 0;
  function kutu(html){
    $('bosMesaj').style.display = 'none';
    $('sonucIcerik').style.display = 'block';
    $('sonucIcerik').innerHTML = html;
    clearTimeout(temizleZaman);
    temizleZaman = setTimeout(function(){
      $('sonucIcerik').style.display = 'none';
      $('bosMesaj').style.display = 'block';
    }, 6000);
  }
  function kacis(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
    });
  }
  function sonucGoster(r){
    var giris = r.yon === 'giris';
    var saat = r.zaman ? r.zaman.substring(11, 16) : '';
    kutu(
      '<div class="kiosk-yon ' + (giris ? 'yon-giris' : 'yon-cikis') + '">' +
        '<i class="bi bi-box-arrow-' + (giris ? 'in-right' : 'right') + '"></i>' +
        (giris ? 'GİRİŞ' : 'ÇIKIŞ') + '</div>' +
      '<div class="kiosk-ad">' + kacis(r.ad_soyad) + '</div>' +
      (r.unvan ? '<div class="opacity-75">' + kacis(r.unvan) + '</div>' : '') +
      (r.birim ? '<div class="opacity-75 small">' + kacis(r.birim) + '</div>' : '') +
      '<div class="kiosk-saat mt-2">' + kacis(saat) + (r.sicil_no ? ' · sicil ' + kacis(r.sicil_no) : '') + '</div>' +
      (r.tekrar ? '<div class="kiosk-tekrar"><i class="bi bi-info-circle me-1"></i>' +
                  'Az önce okundu — yeni kayıt açılmadı.</div>' : '')
    );
    durum('Son okuma: ' + r.ad_soyad + ' · ' + (giris ? 'giriş' : 'çıkış'));
  }
  function hataGoster(mesaj){
    kutu('<div class="kiosk-hata"><i class="bi bi-exclamation-triangle me-2"></i>' + kacis(mesaj) + '</div>');
    durum(mesaj, true);
  }

  // ── açılış: anahtar varsa doğrula, yoksa kurulum ──
  if (!anahtar) { kurulumGoster(''); }
  else {
    fetch(SCAN_URL + '?whoami=1', { headers: { 'X-Checkpoint-Key': anahtar } })
      .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, j: j }; }); })
      .then(function(x){
        if (!x.ok || !x.j.ok) throw new Error(x.j.hata || 'Anahtar geçersiz.');
        nokta = x.j.nokta; basla();
      })
      .catch(function(e){ kurulumGoster('Kayıtlı anahtar kabul edilmedi: ' + e.message); });
  }
})();
</script>
</body>
</html>
