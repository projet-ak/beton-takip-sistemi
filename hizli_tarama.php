<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth();
require_once __DIR__ . '/includes/db.php';
$pageTitle = 'Hızlı Tarama — Beton Takip Sistemi';

// Referans veriler (dropdownlar için)
// VKN sütunu varsa al, yoksa boş
try {
    $tedarikciler = $pdo->query("SELECT id, ad, COALESCE(vkn,'') AS vkn FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
} catch (Exception $e) {
    $tedarikciler = $pdo->query("SELECT id, ad, '' AS vkn FROM tedarikciler WHERE aktif=1 ORDER BY ad")->fetchAll();
}
$betonSiniflari = $pdo->query("SELECT id,ad FROM beton_siniflari ORDER BY ad")->fetchAll();
$projeler       = $pdo->query("SELECT id,kod,aciklama FROM projeler WHERE aktif=1 ORDER BY kod")->fetchAll();
$kivamSiniflari = $pdo->query("SELECT id,ad FROM kivam_siniflari ORDER BY ad")->fetchAll();
require_once __DIR__ . '/includes/header.php';
?>

<style>
.btn-xs { padding:.2rem .4rem; font-size:.75rem; }

/* ── Kamera Paneli Stilleri ─────────────────────────────── */
.cam-viewport {
    position: relative;
    background: #000;
    border-radius: 12px;
    overflow: hidden;
    max-height: 420px;
    min-height: 220px;
}
.cam-viewport video {
    width: 100%;
    max-height: 420px;
    object-fit: cover;
    display: block;
}

/* Belge kılavuz çerçevesi */
.doc-guide {
    position: absolute; inset: 0;
    pointer-events: none;
    display: flex; align-items: center; justify-content: center;
}
.doc-guide-box {
    position: relative;
    width: 85%; max-width: 480px;
    aspect-ratio: 1.4 / 1;
    border: 2px solid rgba(0,200,180,.5);
    border-radius: 8px;
}
/* Köşe L şekilleri */
.doc-guide-box::before,
.doc-guide-box::after,
.doc-corner-br, .doc-corner-bl {
    content: '';
    position: absolute;
    width: 22px; height: 22px;
    border-color: var(--ern-teal, #00C9B1);
    border-style: solid;
}
.doc-guide-box::before { top:-2px; left:-2px; border-width:3px 0 0 3px; border-radius:6px 0 0 0; }
.doc-guide-box::after  { top:-2px; right:-2px; border-width:3px 3px 0 0; border-radius:0 6px 0 0; }
.doc-corner-br { bottom:-2px; right:-2px; border-width:0 3px 3px 0; border-radius:0 0 6px 0; }
.doc-corner-bl { bottom:-2px; left:-2px;  border-width:0 0 3px 3px; border-radius:0 0 0 6px; }

/* KGS ve QR kod pozisyon ipuçları */
.code-hint {
    position: absolute;
    top: 8%; right: 6%;
    display: flex; gap: 6px;
}
.code-hint-box {
    border: 1.5px dashed rgba(0,200,180,.55);
    border-radius: 5px;
    padding: 3px 6px;
    font-size: .62rem;
    font-weight: 700;
    color: rgba(0,200,180,.8);
    letter-spacing: .3px;
    backdrop-filter: blur(2px);
    background: rgba(0,0,0,.3);
}

/* Kılavuz metni */
.doc-hint-text {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    padding: 14px 12px 10px;
    background: linear-gradient(transparent, rgba(0,0,0,.65));
    text-align: center;
    font-size: .75rem;
    color: rgba(255,255,255,.75);
    font-weight: 500;
}

/* Fotoğraf çek ana buton */
.btn-capture {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: #fff;
    border: 4px solid var(--ern-teal, #00C9B1);
    box-shadow: 0 4px 20px rgba(0,200,180,.4);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: .2s;
    flex-shrink: 0;
}
.btn-capture:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(0,200,180,.6); }
.btn-capture:active { transform: scale(.95); }
.btn-capture i { font-size: 1.6rem; color: var(--ern, #00584E); }
.btn-capture.scanning {
    animation: captureAnim .4s ease;
    background: var(--ern-teal, #00C9B1);
}
.btn-capture.scanning i { color: #fff; }
@keyframes captureAnim {
    0%   { transform: scale(1); }
    40%  { transform: scale(.88); }
    100% { transform: scale(1); }
}

/* Alt kontrol çubuğu */
.cam-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .75rem 1rem;
    background: rgba(0,0,0,.85);
    border-radius: 0 0 12px 12px;
    gap: .75rem;
}
.cam-side-btns { display: flex; gap: .5rem; }
.btn-cam-side {
    width: 42px; height: 42px; border-radius: 50%;
    background: rgba(255,255,255,.12); border: none;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.7); font-size: 1rem; cursor: pointer; transition: .2s;
}
.btn-cam-side:hover { background: rgba(255,255,255,.22); color: #fff; }
.btn-cam-side.active { background: var(--ern-teal,#00C9B1); color: #fff; }

/* Tarama sonuç kartı */
.scan-result-card {
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--bt-border, #dce8e6);
}
.scan-result-header {
    padding: .6rem 1rem;
    font-size: .78rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.scan-result-header.ok  { background: rgba(0,200,180,.12); color: #00796b; }
.scan-result-header.err { background: rgba(224,84,84,.1); color: #c23b3b; }
html[data-dark="1"] .scan-result-header.ok  { background: rgba(0,200,180,.15); color: var(--ern-teal); }
html[data-dark="1"] .scan-result-header.err { background: rgba(224,84,84,.12); color: #ff8080; }
.scan-result-body { padding: .7rem 1rem; font-size: .82rem; }
.scan-field { display: flex; gap: .5rem; margin-bottom: .25rem; }
.scan-field-label { color: var(--bt-text-muted,#4E7068); font-weight: 600; min-width: 100px; flex-shrink: 0; }
.scan-field-val  { font-weight: 500; color: var(--bt-text,#0D2E28); font-family: monospace; word-break: break-all; }

/* Fotoğraf önizleme */
.photo-preview {
    border-radius: 10px;
    overflow: hidden;
    position: relative;
    background: #000;
    max-height: 200px;
}
.photo-preview img {
    width: 100%;
    max-height: 200px;
    object-fit: contain;
    display: block;
}

/* ── Drop Zone ─────────────────────────────────────────────────── */
.drop-zone {
    border: 2px dashed var(--bs-border-color, #dee2e6);
    border-radius: 12px;
    padding: 28px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    user-select: none;
}
.drop-zone:hover, .drop-zone.dragging {
    border-color: var(--ern, #00584E);
    background: rgba(0,88,78,.04);
}
.drop-zone-icon { font-size: 2.4rem; color: var(--bt-text-muted,#4E7068); display: block; margin-bottom: .5rem; opacity: .6; }
.drop-zone-text { font-weight: 600; color: var(--bs-body-color); margin-bottom: .2rem; font-size: .9rem; }
.drop-zone-hint { font-size: .78rem; color: var(--bt-text-muted,#4E7068); }
@keyframes spin { to { transform: rotate(360deg); } }
.spin { display: inline-block; animation: spin 1s linear infinite; }
.yontem-badge {
    font-size: .74rem;
    padding: .25em .7em;
    border-radius: 20px;
    display: inline-flex;
    align-items: center;
    gap: .3rem;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';</script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

<!-- Belge Okuma Paneli -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header p-0">
        <div class="d-flex align-items-center justify-content-between px-3 py-2">
          <span class="fw-semibold">
            <i class="bi bi-file-earmark-search text-primary me-2"></i>Belge Oku
          </span>
          <div class="d-flex align-items-center gap-2">
            <span id="sayacBadge" class="badge" style="background:var(--ern);font-size:.8rem;padding:.4em .8em;">0 kayit</span>
            <span id="scannerBadge" class="badge bg-secondary d-none" style="font-size:.72rem;"></span>
          </div>
        </div>
      </div>
      <div class="card-body">

        <!-- Drop Zone -->
        <div id="dropZone" class="drop-zone"
             onclick="document.getElementById('belgeDosya').click()"
             ondragover="dropZoneOver(event)"
             ondragleave="dropZoneLeave(event)"
             ondrop="dropZoneDrop(event)">
          <input type="file" id="belgeDosya" hidden
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                 onchange="dosyaSecildi(this)">
          <i class="bi bi-cloud-upload drop-zone-icon"></i>
          <div class="drop-zone-text">Dosya secin veya surukleyip birakin</div>
          <div class="drop-zone-hint">PDF, JPG, PNG, WEBP &middot; Maks 20 MB</div>
        </div>

        <!-- Secilen Dosya Bilgisi -->
        <div id="dosyaBilgi" class="d-none mt-3">
          <div class="d-flex align-items-center gap-2 py-2 px-3 rounded"
               style="background:var(--bs-success-bg-subtle,#d1e7dd);border:1px solid var(--bs-success-border-subtle,#a3cfbb);">
            <i id="dosyaIcon" class="bi bi-file-earmark-pdf text-danger fs-5"></i>
            <div class="flex-grow-1 overflow-hidden">
              <div id="dosyaAdi" class="fw-semibold small text-truncate"></div>
              <div id="dosyaBoyut" class="text-muted" style="font-size:.72rem;"></div>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="dosyaTemizle()">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </div>

        <!-- Kamera Toggle -->
        <div class="mt-3">
          <button id="btnKameraToggle" class="btn btn-outline-secondary w-100" onclick="kameraToggle()">
            <i class="bi bi-camera me-1"></i> Kamera ile Cek
          </button>
        </div>

        <!-- Kamera Alani (gizli baslar) -->
        <div id="kameraAlani" class="d-none mt-3">
          <div class="cam-viewport" id="camViewport">
            <video id="videoEl" autoplay playsinline muted></video>
            <div class="doc-guide" id="docGuide" style="display:none">
              <div class="doc-guide-box">
                <div class="doc-corner-br"></div>
                <div class="doc-corner-bl"></div>
                <div class="code-hint">
                  <div class="code-hint-box">E1<br>KGS</div>
                  <div class="code-hint-box" style="padding:3px 8px;">QR<br>GIB</div>
                </div>
              </div>
            </div>
            <div class="doc-hint-text" id="hintText" style="display:none">
              Irsaliyeyi cercevelere hizalayin &bull; Sag ustteki iki kare kodu gordugunuzden emin olun
            </div>
            <div id="camPlaceholder" style="min-height:240px;display:flex;align-items:center;justify-content:center;background:var(--bt-bg,#EEF3F2);">
              <div class="text-center" style="color:var(--bt-text-muted)">
                <i class="bi bi-camera" style="font-size:3rem;opacity:.3;display:block;margin-bottom:.75rem"></i>
                <div style="font-size:.85rem;font-weight:500">Kamerayi acmak icin asagidaki butona basin</div>
              </div>
            </div>
            <div id="flashEl" style="position:absolute;inset:0;background:rgba(255,255,255,.8);pointer-events:none;display:none;"></div>
          </div>

          <div class="cam-controls" id="camControls" style="display:none;">
            <div class="cam-side-btns">
              <button class="btn-cam-side" id="btnCevir" title="Kamerayi Cevir" onclick="kameraCevir()">
                <i class="bi bi-arrow-repeat"></i>
              </button>
              <button class="btn-cam-side d-none" id="btnTorch" onclick="torchToggle()" title="Flas">
                <i class="bi bi-lightning-charge"></i>
              </button>
            </div>
            <button class="btn-capture" id="btnCapture" onclick="fotoCekVeTara()" title="Fotograf Cek ve Tara">
              <i class="bi bi-camera-fill"></i>
            </button>
            <div class="cam-side-btns">
              <select id="kameraSec" class="form-select form-select-sm"
                      style="width:auto;max-width:120px;background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff;font-size:.72rem;"
                      onchange="kameraAc()">
              </select>
            </div>
          </div>

          <div class="p-2 d-flex gap-2" id="camOpenArea">
            <button id="btnAc" class="btn btn-primary flex-fill" onclick="kameraAc()">
              <i class="bi bi-camera-fill me-1"></i> Kamerayi Ac
            </button>
            <button id="btnKapat" class="btn btn-outline-secondary d-none" onclick="kameraKapat()">
              <i class="bi bi-x-circle me-1"></i> Kapat
            </button>
          </div>

          <!-- Tarama Sonuclari (fotograf cekince gorunur) -->
          <div id="taramaSonuc" class="p-3 border-top d-none">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="fw-semibold small"><i class="bi bi-search me-1"></i>Tarama Sonuclari</span>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" onclick="tekrarCek()">
                  <i class="bi bi-arrow-counterclockwise me-1"></i>Tekrar Cek
                </button>
                <button class="btn btn-sm btn-primary d-none" id="btnSonucEkle" onclick="sonucuEkle()">
                  <i class="bi bi-plus-circle me-1"></i>Listeye Ekle
                </button>
              </div>
            </div>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="photo-preview">
                  <img id="photoPreviewImg" src="" alt="Cekilen fotograf">
                </div>
              </div>
              <div class="col-md-8">
                <div id="kodSonuclar" class="d-flex flex-column gap-2"></div>
              </div>
            </div>
          </div>

          <div id="durumEl" class="px-3 pb-2 text-center small"
               style="color:var(--bt-text-muted);min-height:24px;"></div>
        </div>

        <!-- Belge Oku Ana Buton -->
        <div id="belgeOkuArea" class="mt-3" style="display:none;">
          <button id="btnBelgeOku" class="btn btn-primary btn-lg w-100" onclick="belgeyiOku()">
            <i class="bi bi-search me-2"></i> Belge Oku
          </button>
        </div>

        <!-- Ilerleme -->
        <div id="belgeProgress" class="d-none mt-3">
          <div class="d-flex justify-content-between small mb-1">
            <span id="belgeProgressText">Isleniyor...</span>
            <span id="belgeProgressPct"></span>
          </div>
          <div class="progress mb-2" style="height:7px;">
            <div id="belgeProgressBar"
                 class="progress-bar bg-primary progress-bar-striped progress-bar-animated"
                 style="width:0%"></div>
          </div>
          <div class="d-flex gap-2 flex-wrap small justify-content-center">
            <span id="durQr"  class="yontem-badge bg-light text-muted border"><i class="bi bi-circle"></i> QR Kodu</span>
            <span id="durOcr" class="yontem-badge bg-light text-muted border"><i class="bi bi-circle"></i> OCR</span>
            <span id="durAi"  class="yontem-badge bg-light text-muted border"><i class="bi bi-circle"></i> AI Okuma</span>
          </div>
        </div>

        <!-- Sonuc -->
        <div id="okuSonucEl" class="d-none mt-3"></div>

        <!-- PDF isleme icin gizli canvas -->
        <canvas id="pdfCanvas" class="d-none"></canvas>

      </div>
    </div>
  </div>
</div>

<!-- Taranan Kayitlar Tablosu -->
<div class="card shadow-sm" id="kayitlarCard">
  <div class="card-header fw-semibold d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span><i class="bi bi-table text-success me-1"></i> Taranan Kayıtlar</span>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-danger" onclick="listeTemizle()">
        <i class="bi bi-trash me-1"></i> Listeyi Temizle
      </button>
      <button id="btnTopluKaydet" class="btn btn-sm btn-primary d-none" onclick="topluKaydet()">
        <i class="bi bi-cloud-upload me-1"></i> Toplu Kaydet (<span id="kaydetSayac">0</span>)
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <!-- Sonuç alert (toplu kayıt sonrası gösterilir) -->
    <div id="kaydetSonuc" class="d-none px-3 pt-3"></div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" id="kayitTablo">
        <thead class="table-light sticky-top">
          <tr>
            <th style="width:30px">#</th>
            <th style="width:56px" title="Sayfa Görseli">📄</th>
            <th style="min-width:125px">İrsaliye No</th>
            <th style="min-width:120px">Tarih</th>
            <th style="min-width:100px">Plaka</th>
            <th style="min-width:85px">Saat</th>
            <th style="min-width:150px">Fatura No (ETTN)</th>
            <th style="min-width:160px">Tedarikçi <span class="text-danger">*</span></th>
            <th style="min-width:120px">Beton Sınıfı</th>
            <th style="min-width:90px">Kıvam</th>
            <th style="width:90px">Miktar m³</th>
            <th style="width:105px">Kantar (Yıldız)</th>
            <th style="width:105px">Kantar (Tedarikçi)</th>
            <th style="min-width:140px">Proje</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody id="kayitGovde">
          <tr id="bosRow">
            <td colspan="15" class="text-center text-muted py-5">
              <i class="bi bi-qr-code display-4 opacity-25 d-block mb-2"></i>
              QR kodu veya PDF yükleyin — kayıtlar buraya eklenecek
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// PHP'den gelen referans veriler
var TEDARIKCILER    = <?= json_encode($tedarikciler, JSON_UNESCAPED_UNICODE) ?>;
var BETON_SINIFLARI = <?= json_encode($betonSiniflari, JSON_UNESCAPED_UNICODE) ?>;
var PROJELER        = <?= json_encode($projeler, JSON_UNESCAPED_UNICODE) ?>;
var KIVAM_SINIFLARI = <?= json_encode($kivamSiniflari, JSON_UNESCAPED_UNICODE) ?>;

// ── Tarayıcı seçimi: Native BarcodeDetector > jsQR fallback ────────
// QR Code (GIB e-İrsaliye JSON) + Data Matrix (KGS/THBB E1) her ikisini de okur
var nativeDetector = null;
(async function() {
    if (!('BarcodeDetector' in window)) return;
    try {
        var formats = ['qr_code', 'data_matrix'];
        if (typeof BarcodeDetector.getSupportedFormats === 'function') {
            var sup = await BarcodeDetector.getSupportedFormats();
            formats = formats.filter(function(f){ return sup.includes(f); });
        }
        nativeDetector = new BarcodeDetector({ formats: formats.length ? formats : ['qr_code'] });
    } catch(e) {}
})();

// ── Durum değişkenleri ──────────────────────────────────────────────
var stream        = null;
var taramaActive  = false;  // loop flag (BarcodeDetector için)
var taramaTimer   = null;   // setInterval handle (jsQR fallback)
var torchAktif    = false;
var cooldownAktif = false;
var scanCanvas    = document.createElement('canvas');
var SCAN_W = 320, SCAN_H = 240; // küçük canvas → jsQR ~4x hızlı
var scanIndex     = 0;

var taranmisList = [];
var rowSayac     = 0;

// ── Ses (beep) ──────────────────────────────────────────────────────
var globalAudioCtx = null;
function beepSes(isWarning) {
    try {
        if (!globalAudioCtx) {
            globalAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        var ctx = globalAudioCtx;
        if (ctx.state === 'suspended') {
            ctx.resume();
        }
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        
        if (isWarning) {
            osc.type = 'square';
            osc.frequency.value = 400;
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.2);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.2);
        } else {
            osc.type = 'square';
            osc.frequency.value = 1400;
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.12);
        }
    } catch(e) {
        console.warn('AudioContext error:', e);
    }
}


// ── Kamera Durum Değişkenleri ───────────────────────────────────────
var stream = null, torchAktif = false, facingUser = false;
var pendingScanData = null; // fotoğraf tarama sonucu bekliyor
var pendingUploadPromise = null; // kamera fotoğrafı yükleme promise
var ocrPdfUrl  = ''; // OCR için yüklenen PDF URL
var aiDosyaUrl = ''; // AI için yüklenen dosya URL

// ── Kamera cihazı listeleme ─────────────────────────────────────────
async function kameralariListele() {
    if (!navigator.mediaDevices) return;
    try { var tmp = await navigator.mediaDevices.getUserMedia({video:true}); tmp.getTracks().forEach(function(t){ t.stop(); }); } catch(e) {}
    var devices = (await navigator.mediaDevices.enumerateDevices()).filter(function(d){ return d.kind==='videoinput'; });
    var sel = document.getElementById('kameraSec');
    sel.innerHTML = '';
    devices.forEach(function(d, i) {
        var opt = document.createElement('option');
        opt.value = d.deviceId;
        opt.textContent = d.label || ('Kamera ' + (i+1));
        if (/back|arka|environment/i.test(d.label)) opt.selected = true;
        sel.appendChild(opt);
    });
    if (!devices.length) sel.innerHTML = '<option value="">Kamera bulunamadı</option>';
}

// ── Kamera Aç ──────────────────────────────────────────────────────
async function kameraAc() {
    if (stream) kameraKapat();
    var deviceId = document.getElementById('kameraSec').value;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: deviceId
                ? { deviceId: { exact: deviceId }, width:{ideal:2560}, height:{ideal:1920} }
                : { facingMode: facingUser ? 'user' : {ideal:'environment'}, width:{ideal:2560}, height:{ideal:1920} },
            audio: false
        });
        var video = document.getElementById('videoEl');
        video.srcObject = stream;
        await video.play();

        // Sürekli odak
        var track = stream.getVideoTracks()[0];
        try {
            var caps = track.getCapabilities ? track.getCapabilities() : {};
            var adv = {};
            if (caps.focusMode && caps.focusMode.includes('continuous')) adv.focusMode = 'continuous';
            if (caps.torch) document.getElementById('btnTorch').classList.remove('d-none');
            if (Object.keys(adv).length) await track.applyConstraints({ advanced: [adv] });
        } catch(e) {}

        // UI güncelle
        document.getElementById('camPlaceholder').style.display = 'none';
        document.getElementById('docGuide').style.display = '';
        document.getElementById('hintText').style.display = '';
        document.getElementById('camControls').style.display = '';
        document.getElementById('btnAc').classList.add('d-none');
        document.getElementById('btnKapat').classList.remove('d-none');
        document.getElementById('taramaSonuc').classList.add('d-none');
        pendingScanData = null;

        var badge = document.getElementById('scannerBadge');
        badge.textContent = nativeDetector ? '⚡ BarcodeDetector' : 'jsQR (QR only)';
        badge.className = 'badge ' + (nativeDetector ? 'bg-success' : 'bg-warning text-dark') + ' ms-1';
        badge.classList.remove('d-none');
        setDurum('Hazır — belgeyi çerçeveye hizalayın, fotoğraf çek butonuna basın');
    } catch(e) {
        setDurum('<i class="bi bi-exclamation-triangle text-danger me-1"></i> Kamera açılamadı: ' + e.message);
    }
}

// ── Kamera Kapat ────────────────────────────────────────────────────
function kameraKapat() {
    if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
    document.getElementById('videoEl').srcObject = null;
    document.getElementById('camPlaceholder').style.display = '';
    document.getElementById('docGuide').style.display = 'none';
    document.getElementById('hintText').style.display = 'none';
    document.getElementById('camControls').style.display = 'none';
    document.getElementById('btnAc').classList.remove('d-none');
    document.getElementById('btnKapat').classList.add('d-none');
    document.getElementById('btnTorch').classList.add('d-none');
    document.getElementById('scannerBadge').classList.add('d-none');
    setDurum('');
}

// ── Kamera Çevir ────────────────────────────────────────────────────
function kameraCevir() {
    facingUser = !facingUser;
    kameraAc();
}

// ── Torch ───────────────────────────────────────────────────────────
async function torchToggle() {
    if (!stream) return;
    torchAktif = !torchAktif;
    try {
        await stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch: torchAktif }] });
        var btn = document.getElementById('btnTorch');
        btn.classList.toggle('active', torchAktif);
    } catch(e) {}
}

// ── FOTOĞRAF ÇEK VE TARA (Ana Fonksiyon) ────────────────────────────
async function fotoCekVeTara() {
    var video = document.getElementById('videoEl');
    if (!stream || !video.videoWidth) {
        setDurum('Önce kamerayı açın.'); return;
    }

    // Fotoğraf çek → canvas
    var capCanvas = document.createElement('canvas');
    capCanvas.width  = video.videoWidth;
    capCanvas.height = video.videoHeight;
    var ctx = capCanvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    // Flash efekti
    var flashEl = document.getElementById('flashEl');
    flashEl.style.display = '';
    setTimeout(function(){ flashEl.style.display = 'none'; }, 180);
    beepSes(false);

    // Buton animasyonu
    var btn = document.getElementById('btnCapture');
    btn.classList.add('scanning');
    setTimeout(function(){ btn.classList.remove('scanning'); }, 400);

    // Önizleme göster
    var previewImg = document.getElementById('photoPreviewImg');
    previewImg.src = capCanvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('taramaSonuc').classList.remove('d-none');
    document.getElementById('kodSonuclar').innerHTML =
        '<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Kodlar taranıyor...</div>';
    document.getElementById('btnSonucEkle').classList.add('d-none');

    setDurum('Taranıyor...');

    // Tüm kodları tara
    var bulunanKodlar = await tumKodlariBul(capCanvas, ctx);

    // Sonuçları parse et
    pendingScanData = kodlariIsle(bulunanKodlar);
    pendingUploadPromise = sayfaGorselKaydet(capCanvas);
    sonuclariGoster(pendingScanData);
}

// Bir canvas'tan QR + DataMatrix kodlarını toplar
async function tumKodlariBul(canvas, ctx) {
    var bulunan = new Set();
    function ekle(val) { if (val && val.trim()) bulunan.add(val.trim()); }

    // 1) BarcodeDetector (QR + DataMatrix)
    if (nativeDetector) {
        try {
            var codes = await nativeDetector.detect(canvas);
            codes.forEach(function(c){ if(c.rawValue) ekle(c.rawValue); });
            if (bulunan.size >= 2) return Array.from(bulunan);
        } catch(e) {}
    }

    // 2) jsQR fallback — tam + 4 bölge + kontrast artırma
    function deneJsqr(imgData) {
        if (typeof jsQR === 'undefined') return;
        var r1 = jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'attemptBoth' });
        if (r1) ekle(r1.data);
        var enhanced = goruntIsle(imgData);
        var r2 = jsQR(enhanced.data, enhanced.width, enhanced.height, { inversionAttempts: 'attemptBoth' });
        if (r2) ekle(r2.data);
    }

    // Farklı scale'lerde dene (büyük ölçek daha iyi okur)
    var scales = [1, 2];
    for (var si = 0; si < scales.length; si++) {
        var s = scales[si];
        var tmpC = document.createElement('canvas');
        tmpC.width  = canvas.width * s;
        tmpC.height = canvas.height * s;
        var tmpCtx = tmpC.getContext('2d');
        tmpCtx.drawImage(canvas, 0, 0, tmpC.width, tmpC.height);

        deneJsqr(tmpCtx.getImageData(0, 0, tmpC.width, tmpC.height));

        // Sağ üst köşe (irsaliyelerde kodlar genelde oraya)
        var qw = Math.floor(tmpC.width * 0.4), qh = Math.floor(tmpC.height * 0.4);
        deneJsqr(tmpCtx.getImageData(tmpC.width - qw, 0, qw, qh));
        if (bulunan.size >= 2) break;
    }

    return Array.from(bulunan);
}

// Ham kod listesini JSON + E1 olarak ayır, birleştir
function kodlariIsle(liste) {
    var jsonVeri = null, e1Veri = null;
    liste.forEach(function(raw) {
        if (raw.charAt(0) === '{') {
            try {
                var parsed = JSON.parse(raw);
                jsonVeri = {};
                for (var k in parsed) jsonVeri[k.toLowerCase()] = parsed[k];
            } catch(e) {}
        } else if (raw.substring(0,2) === 'E1') {
            var p = parseE1Qr(raw);
            if (p) e1Veri = p;
        }
    });
    return { json: jsonVeri, e1: e1Veri, raw: liste };
}

// Sonuçları HTML olarak göster
function sonuclariGoster(data) {
    var html = '';
    var toplamBulunan = 0;

    if (data.json) {
        toplamBulunan++;
        html += '<div class="scan-result-card mb-2">';
        html += '<div class="scan-result-header ok"><i class="bi bi-check-circle-fill"></i> GİB e-İrsaliye QR (sağ büyük kod)</div>';
        html += '<div class="scan-result-body">';
        if (data.json.no)         html += scanField('İrsaliye No', data.json.no);
        if (data.json.tarih)      html += scanField('Tarih', data.json.tarih);
        if (data.json.plaka)      html += scanField('Araç Plaka', data.json.plaka);
        if (data.json.sevkzamani) html += scanField('Sevk Saati', data.json.sevkzamani ? data.json.sevkzamani.substring(0,5) : '');
        if (data.json.ettn)       html += scanField('ETTN', data.json.ettn.substring(0,18) + '…');
        if (data.json.vkntckn)    html += scanField('VKN', data.json.vkntckn);
        html += '</div></div>';
    } else {
        html += '<div class="scan-result-card mb-2">';
        html += '<div class="scan-result-header err"><i class="bi bi-x-circle-fill"></i> GİB e-İrsaliye QR okunamadı</div>';
        html += '<div class="scan-result-body" style="font-size:.78rem;color:var(--bt-text-muted)">Belgeyi daha iyi aydınlatın veya yakından çekin.</div>';
        html += '</div>';
    }

    if (data.e1) {
        toplamBulunan++;
        html += '<div class="scan-result-card">';
        html += '<div class="scan-result-header ok"><i class="bi bi-check-circle-fill"></i> KGS / THBB DataMatrix (sol küçük kod)</div>';
        html += '<div class="scan-result-body">';
        if (data.e1.beton_sinifi) html += scanField('Beton Sınıfı', data.e1.beton_sinifi);
        if (data.e1.miktar)       html += scanField('Miktar', data.e1.miktar + ' m³');
        if (data.e1.kivam)        html += scanField('Kıvam', data.e1.kivam);
        if (data.e1.plaka)        html += scanField('Araç Plaka', data.e1.plaka);
        if (data.e1.dayanim)      html += scanField('Dayanım', data.e1.dayanim);
        if (data.e1.suCimento)    html += scanField('Su/Çimento', data.e1.suCimento);
        html += '</div></div>';
    } else {
        html += '<div class="scan-result-card">';
        html += '<div class="scan-result-header err"><i class="bi bi-x-circle-fill"></i> KGS DataMatrix okunamadı</div>';
        html += '<div class="scan-result-body" style="font-size:.78rem;color:var(--bt-text-muted)">'
              + (nativeDetector ? 'Belgeyi biraz yaklaştırın.' : 'DataMatrix için Chrome/Edge gereklidir.')
              + '</div></div>';
    }

    document.getElementById('kodSonuclar').innerHTML = html;

    if (toplamBulunan > 0) {
        document.getElementById('btnSonucEkle').classList.remove('d-none');
        setDurum('<i class="bi bi-check-circle-fill text-success me-1"></i> ' + toplamBulunan + '/2 kod okundu — listeye ekleyin');
        beepSes(false);
    } else {
        setDurum('<i class="bi bi-exclamation-triangle text-warning me-1"></i> Kod okunamadı — tekrar çekin');
        beepSes(true);
    }
}

function scanField(label, val) {
    return '<div class="scan-field"><span class="scan-field-label">' + label + '</span>'
         + '<span class="scan-field-val">' + escHtml(String(val || '')) + '</span></div>';
}

// Taranan fotoğraf sonucunu kayıt listesine ekle
async function sonucuEkle() {
    if (!pendingScanData) return;
    var data = pendingScanData;
    var json = data.json, e1 = data.e1;

    var irsaliyeNo = (json && json.no) || (e1 && e1.irsaliye_no) || '';
    var tarih = (json && json.tarih) || (e1 && e1.tarih) || '';
    var plaka = (json && json.plaka) ? json.plaka.replace(/\s+/g,'').toUpperCase() : (e1 && e1.plaka) || '';
    var saat  = (json && json.sevkzamani) ? json.sevkzamani.substring(0,5) : (e1 && e1.sevkZamani) || '';
    var ettn  = (json && json.ettn) || '';
    var miktar = (e1 && e1.miktar) || '';

    // Tedarikçi VKN eşleştir
    var vkn = (json && json.vkntckn) || (e1 && e1.vkn) || '';
    var tedarikciId = '';
    if (vkn) {
        for (var i = 0; i < TEDARIKCILER.length; i++) {
            if (String(TEDARIKCILER[i].vkn).trim() === String(vkn).trim()) {
                tedarikciId = String(TEDARIKCILER[i].id); break;
            }
        }
    }

    // Beton sınıfı eşleştir
    var betonSinifiId = '';
    if (e1 && e1.beton_sinifi) {
        var bn = e1.beton_sinifi.toUpperCase();
        for (var b = 0; b < BETON_SINIFLARI.length; b++) {
            var bc = BETON_SINIFLARI[b].ad.replace(/\s+/g,'').toUpperCase();
            if (bc === bn || bc.startsWith(bn+'/') || bc.startsWith(bn+'-')) {
                betonSinifiId = String(BETON_SINIFLARI[b].id); break;
            }
        }
    }

    // Kıvam sınıfı eşleştir
    var kivamSinifiId = '';
    if (e1 && e1.kivam) {
        var kn = e1.kivam.toUpperCase();
        for (var k = 0; k < KIVAM_SINIFLARI.length; k++) {
            if (KIVAM_SINIFLARI[k].ad.toUpperCase() === kn) {
                kivamSinifiId = String(KIVAM_SINIFLARI[k].id); break;
            }
        }
    }

    // Duplicate kontrol
    if (irsaliyeNo && taranmisList.some(function(r){ return r.irsaliye_no === irsaliyeNo; })) {
        setDurum('<i class="bi bi-exclamation-circle text-warning me-1"></i> Bu irsaliye zaten listede: ' + irsaliyeNo);
        beepSes(true); return;
    }

    var scanUrl = '';
    try { if (pendingUploadPromise) scanUrl = (await pendingUploadPromise) || ''; } catch(e) {}
    pendingUploadPromise = null;
    rowSayac++;
    var item = {
        rowId: rowSayac, irsaliye_no: irsaliyeNo, tarih: tarih,
        arac_plaka: plaka, mikser_cikis_saati: saat, fatura_no: ettn,
        miktar: miktar, tedarikci_id: tedarikciId, beton_sinifi_id: betonSinifiId,
        kivam_sinifi_id: kivamSinifiId, kantar_net_yildizlar: '', kantar_net_tedarikci: '',
        proje_id: '', qrKullanildi: true, scanImageUrl: scanUrl, docUrl: scanUrl
    };
    taranmisList.push(item);
    tabloSatirEkle(item);
    sayacGuncelle();
    beepSes(false);

    document.getElementById('taramaSonuc').classList.add('d-none');
    pendingScanData = null;
    setDurum('<i class="bi bi-check-circle-fill text-success me-1"></i> Listeye eklendi: ' + (irsaliyeNo || 'yeni kayıt'));
}

// Sonuçları temizle, tekrar çekmeye hazırla
function tekrarCek() {
    document.getElementById('taramaSonuc').classList.add('d-none');
    pendingScanData = null;
    setDurum('Hazır — tekrar fotoğraf çekebilirsiniz');
}

// ── QR bulununca işlem ──────────────────────────────────────────────
function qrBulundu(rawData) {
    // Cooldown başlat (1.5sn — aynı kodu tekrar okuma önlemi)
    cooldownAktif = true;
    var cooldownBar  = document.getElementById('cooldownBar');
    var cooldownFill = document.getElementById('cooldownFill');
    cooldownBar.classList.remove('d-none');
    cooldownFill.style.width = '100%';
    var elapsed = 0, total = 1500;
    var tick = setInterval(function() {
        elapsed += 50;
        cooldownFill.style.width = Math.max(0, 100 - (elapsed / total * 100)) + '%';
        if (elapsed >= total) {
            clearInterval(tick);
            cooldownAktif = false;
            cooldownBar.classList.add('d-none');
        }
    }, 50);

    // JSON parse veya E1 parse
    var json = null;
    var e1 = null;
    if (rawData.charAt(0) === '{') {
        try {
            var rawJson = JSON.parse(rawData);
            json = {};
            for (var key in rawJson) {
                if (rawJson.hasOwnProperty(key)) {
                    json[key.toLowerCase()] = rawJson[key];
                }
            }
        } catch(e) {}
    } else if (rawData.substring(0, 2) === 'E1') {
        e1 = parseE1Qr(rawData);
    }

    var irsaliyeNo    = '';
    var tarih         = '';
    var aracPlaka     = '';
    var sevkZamani    = '';
    var ettn          = '';
    var miktar        = '';
    var tedarikciId   = '';
    var betonSinifiId = '';
    var kivamSinifiId = '';

    if (json) {
        irsaliyeNo  = json.no || '';
        tarih       = json.tarih || '';
        aracPlaka   = json.plaka || '';
        sevkZamani  = (json.sevkzamani || '').substring(0, 5);
        ettn        = json.ettn || '';
        if (json.vkntckn) {
            for (var ti = 0; ti < TEDARIKCILER.length; ti++) {
                if (TEDARIKCILER[ti].vkn && String(TEDARIKCILER[ti].vkn).trim() === String(json.vkntckn).trim()) {
                    tedarikciId = String(TEDARIKCILER[ti].id); break;
                }
            }
        }
    } else if (e1) {
        irsaliyeNo  = e1.irsaliye_no || '';
        tarih       = e1.tarih || '';
        aracPlaka   = e1.plaka || '';
        sevkZamani  = e1.sevkZamani || '';
        miktar      = e1.miktar || '';
        if (e1.vkn) {
            for (var te = 0; te < TEDARIKCILER.length; te++) {
                if (TEDARIKCILER[te].vkn && String(TEDARIKCILER[te].vkn).trim() === String(e1.vkn).trim()) {
                    tedarikciId = String(TEDARIKCILER[te].id); break;
                }
            }
        }
        if (e1.beton_sinifi) {
            var bn = e1.beton_sinifi.toUpperCase();
            for (var be = 0; be < BETON_SINIFLARI.length; be++) {
                var bc = BETON_SINIFLARI[be].ad.replace(/\s+/g,'').toUpperCase();
                if (bc === bn || bc.startsWith(bn+'/') || bc.startsWith(bn+'-')) {
                    betonSinifiId = String(BETON_SINIFLARI[be].id); break;
                }
            }
        }
        if (e1.kivam) {
            var kn = e1.kivam.toUpperCase();
            for (var k = 0; k < KIVAM_SINIFLARI.length; k++) {
                if (KIVAM_SINIFLARI[k].ad.toUpperCase() === kn) {
                    kivamSinifiId = String(KIVAM_SINIFLARI[k].id); break;
                }
            }
        }
    }

    if (!irsaliyeNo && !e1) {
        setDurum('<i class="bi bi-exclamation-triangle text-warning me-1"></i> QR içeriği tanımlanamadı.');
        return;
    }

    // Duplicate/Akıllı Birleştirme kontrolü (aynı irsaliye_no)
    if (irsaliyeNo) {
        var varolanIndex = taranmisList.findIndex(function(r){ return r.irsaliye_no === irsaliyeNo; });
        if (varolanIndex !== -1) {
            var existingItem = taranmisList[varolanIndex];
            var guncellendi = false;

            if (json) {
                if (!existingItem.fatura_no && ettn) { existingItem.fatura_no = ettn; guncellendi = true; }
                if (!existingItem.tarih && tarih) { existingItem.tarih = tarih; guncellendi = true; }
                if (!existingItem.arac_plaka && aracPlaka) { existingItem.arac_plaka = aracPlaka; guncellendi = true; }
                if (!existingItem.mikser_cikis_saati && sevkZamani) { existingItem.mikser_cikis_saati = sevkZamani; guncellendi = true; }
                if (!existingItem.tedarikci_id && tedarikciId) { existingItem.tedarikci_id = tedarikciId; guncellendi = true; }
            } else if (e1) {
                if (!existingItem.miktar && miktar) { existingItem.miktar = miktar; guncellendi = true; }
                if (!existingItem.beton_sinifi_id && betonSinifiId) { existingItem.beton_sinifi_id = betonSinifiId; guncellendi = true; }
                if (!existingItem.kivam_sinifi_id && kivamSinifiId) { existingItem.kivam_sinifi_id = kivamSinifiId; guncellendi = true; }
                if (!existingItem.tarih && tarih) { existingItem.tarih = tarih; guncellendi = true; }
                if (!existingItem.arac_plaka && aracPlaka) { existingItem.arac_plaka = aracPlaka; guncellendi = true; }
                if (!existingItem.mikser_cikis_saati && sevkZamani) { existingItem.mikser_cikis_saati = sevkZamani; guncellendi = true; }
                if (!existingItem.tedarikci_id && tedarikciId) { existingItem.tedarikci_id = tedarikciId; guncellendi = true; }
            }

            if (guncellendi) {
                tabloSatiriGuncelle(existingItem);
                beepSes(false);
                var flash = document.getElementById('flashEl');
                if (flash) {
                    flash.classList.remove('d-none');
                    setTimeout(function(){ flash.classList.add('d-none'); }, 200);
                }
                setDurum('<i class="bi bi-intersect text-success me-1"></i> İrsaliye bilgileri birleştirildi: ' + irsaliyeNo);
            } else {
                setDurum('<i class="bi bi-exclamation-circle text-warning me-1"></i> Bu irsaliye zaten listede ve güncel: ' + irsaliyeNo);
                // Farklı frekanslı uyarı sesi
                beepSes(true);
            }
            return;
        }
    }

    // Ses çal
    beepSes(false);

    // Flash animasyonu
    var flash = document.getElementById('flashEl');
    flash.classList.remove('d-none');
    setTimeout(function(){ flash.classList.add('d-none'); }, 200);

    // Listeye ekle
    rowSayac++;
    var item = {
        rowId: rowSayac,
        irsaliye_no: irsaliyeNo,
        tarih: tarih,
        arac_plaka: aracPlaka,
        mikser_cikis_saati: sevkZamani,
        fatura_no: ettn,
        miktar: miktar,
        tedarikci_id: tedarikciId,
        beton_sinifi_id: betonSinifiId,
        kivam_sinifi_id: kivamSinifiId,
        kantar_net_yildizlar: '',
        kantar_net_tedarikci: '',
        proje_id: '',
        qrKullanildi: true
    };
    taranmisList.push(item);

    tabloSatirEkle(item);
    sayacGuncelle();
    setDurum('<i class="bi bi-check-circle-fill text-success me-1"></i> Okundu: ' + (irsaliyeNo || rawData.substring(0, 30)));

    // 10'un katı ise sayfayı aşağı kaydır
    if (taranmisList.length % 10 === 0) {
        document.getElementById('kayitlarCard').scrollIntoView({behavior:'smooth'});
    }
}

// ── Tablo satırı ekleme ─────────────────────────────────────────────
function tabloSatirEkle(item) {
    // Boş satırı kaldır
    var bosRow = document.getElementById('bosRow');
    if (bosRow) bosRow.remove();

    var tbody = document.getElementById('kayitGovde');
    var tr = document.createElement('tr');
    tr.id = 'row-' + item.rowId;
    tr.className = item.qrKullanildi ? 'table-success' : 'table-info'; // highlight
    setTimeout(function(){ tr.className = ''; }, 2000);

    // Tedarikçi options
    var tedOpts = '<option value="">— Seçin —</option>';
    TEDARIKCILER.forEach(function(t){
        tedOpts += '<option value="' + t.id + '"' + (String(t.id) === String(item.tedarikci_id || '') ? ' selected' : '') + '>' + escHtml(t.ad) + '</option>';
    });

    // Beton options
    var betOpts = '<option value="">—</option>';
    BETON_SINIFLARI.forEach(function(b){
        betOpts += '<option value="' + b.id + '"' + (String(b.id) === String(item.beton_sinifi_id || '') ? ' selected' : '') + '>' + escHtml(b.ad) + '</option>';
    });

    // Kıvam options
    var kivOpts = '<option value="">—</option>';
    KIVAM_SINIFLARI.forEach(function(k){
        kivOpts += '<option value="' + k.id + '"' + (String(k.id) === String(item.kivam_sinifi_id || '') ? ' selected' : '') + '>' + escHtml(k.ad) + '</option>';
    });

    // Proje options
    var prjOpts = '<option value="">—</option>';
    PROJELER.forEach(function(p){
        prjOpts += '<option value="' + p.id + '"' + (String(p.id) === String(item.proje_id || '') ? ' selected' : '') + '>' + escHtml(p.kod) + '</option>';
    });

    // Sayfa görseli hücresi
    var isPdfUrl = item.scanImageUrl && item.scanImageUrl.toLowerCase().endsWith('.pdf');
    var gorselTd = item.scanImageUrl
        ? (isPdfUrl
            ? '<td><a href="' + escHtml(item.scanImageUrl) + '" target="_blank" title="PDF Görüntüle" style="width:46px;height:34px;display:inline-flex;align-items:center;justify-content:center;background:var(--bt-bg-soft);border-radius:4px;border:1px solid var(--bt-border);text-decoration:none"><i class="bi bi-file-pdf text-danger"></i></a></td>'
            : '<td><a href="' + escHtml(item.scanImageUrl) + '" target="_blank" title="Görüntüle"><img src="' + escHtml(item.scanImageUrl) + '" style="width:46px;height:34px;object-fit:cover;border-radius:4px;border:1px solid var(--bt-border)"></a></td>')
        : '<td><span style="width:46px;height:34px;display:inline-block;background:var(--bt-bg-soft);border-radius:4px;border:1px solid var(--bt-border);"></span></td>';

    tr.innerHTML =
        '<td class="text-muted small">' + item.rowId + '</td>' +
        gorselTd +
        '<td><input type="text" class="form-control form-control-sm font-monospace" style="min-width:125px" value="' + escHtml(item.irsaliye_no || '') + '" oninput="satirGuncelle(' + item.rowId + ',\'irsaliye_no\',this.value)"></td>' +
        '<td><input type="date" class="form-control form-control-sm" style="min-width:120px" value="' + escHtml(item.tarih || '') + '" onchange="satirGuncelle(' + item.rowId + ',\'tarih\',this.value)"></td>' +
        '<td><input type="text" class="form-control form-control-sm text-uppercase" style="min-width:100px" value="' + escHtml(item.arac_plaka || '') + '" oninput="satirGuncelle(' + item.rowId + ',\'arac_plaka\',this.value)"></td>' +
        '<td><input type="time" class="form-control form-control-sm" style="min-width:85px" value="' + escHtml(item.mikser_cikis_saati || '') + '" onchange="satirGuncelle(' + item.rowId + ',\'mikser_cikis_saati\',this.value)"></td>' +
        '<td><input type="text" class="form-control form-control-sm" style="min-width:150px" value="' + escHtml(item.fatura_no || '') + '" oninput="satirGuncelle(' + item.rowId + ',\'fatura_no\',this.value)"></td>' +
        '<td><select class="form-select form-select-sm' + (!item.tedarikci_id && item.qrVkn ? ' border-warning' : '') + '" style="min-width:160px" onchange="satirGuncelle(' + item.rowId + ',\'tedarikci_id\',this.value)" title="' + (item.qrVkn && !item.tedarikci_id ? 'QR\'dan VKN okundu: ' + escHtml(item.qrVkn) + ' — Tedarikçiler sayfasında bu VKN\'i ilgili tedarikçiye ekleyin' : '') + '">' + tedOpts + '</select>' + (!item.tedarikci_id && item.qrVkn ? '<div class="text-warning small mt-1" style="font-size:0.7rem"><i class="bi bi-exclamation-circle me-1"></i>VKN: ' + escHtml(item.qrVkn) + '</div>' : '') + '</td>' +
        '<td><select class="form-select form-select-sm" style="min-width:120px" onchange="satirGuncelle(' + item.rowId + ',\'beton_sinifi_id\',this.value)">' + betOpts + '</select></td>' +
        '<td><select class="form-select form-select-sm" style="min-width:90px" onchange="satirGuncelle(' + item.rowId + ',\'kivam_sinifi_id\',this.value)">' + kivOpts + '</select></td>' +
        '<td><input type="number" class="form-control form-control-sm" style="min-width:85px" step="0.01" min="0" placeholder="0.0" value="' + escHtml(item.miktar || '') + '" oninput="satirGuncelle(' + item.rowId + ',\'miktar\',this.value)"></td>' +
        '<td><input type="number" class="form-control form-control-sm" style="min-width:95px" step="10" placeholder="Yıldızlar" value="' + escHtml(item.kantar_net_yildizlar || '') + '" oninput="satirGuncelle(' + item.rowId + ',\'kantar_net_yildizlar\',this.value)"></td>' +
        '<td><input type="number" class="form-control form-control-sm" style="min-width:95px" step="10" placeholder="Tedarikçi" value="' + escHtml(item.kantar_net_tedarikci || '') + '" oninput="satirGuncelle(' + item.rowId + ',\'kantar_net_tedarikci\',this.value)"></td>' +
        '<td><select class="form-select form-select-sm" style="min-width:130px" onchange="satirGuncelle(' + item.rowId + ',\'proje_id\',this.value)">' + prjOpts + '</select></td>' +
        '<td><button class="btn btn-xs btn-outline-danger" onclick="satirSil(' + item.rowId + ')"><i class="bi bi-trash"></i></button></td>';

    tbody.insertBefore(tr, tbody.firstChild); // En üste ekle

    // Tabloya scroll
    tr.scrollIntoView({behavior:'smooth', block:'center'});
}

// Akıllı Birleştirme sonrası arayüzde satırı güncelleme fonksiyonu
function tabloSatiriGuncelle(item) {
    var tr = document.getElementById('row-' + item.rowId);
    if (!tr) return;

    tr.className = 'table-warning';
    setTimeout(function(){ tr.className = ''; }, 2000);

    var inputs = tr.querySelectorAll('input, select');
    if (inputs.length >= 12) {
        inputs[0].value = item.irsaliye_no || '';
        inputs[1].value = item.tarih || '';
        inputs[2].value = item.arac_plaka || '';
        inputs[3].value = item.mikser_cikis_saati || '';
        inputs[4].value = item.fatura_no || '';
        inputs[5].value = item.tedarikci_id || '';
        inputs[6].value = item.beton_sinifi_id || '';
        inputs[7].value = item.kivam_sinifi_id || '';
        inputs[8].value = item.miktar || '';
        inputs[9].value = item.kantar_net_yildizlar || '';
        inputs[10].value = item.kantar_net_tedarikci || '';
        inputs[11].value = item.proje_id || '';
    }
}

function satirGuncelle(rowId, alan, deger) {
    var item = taranmisList.find(function(r){ return r.rowId === rowId; });
    if (item) item[alan] = deger;
}

function satirSil(rowId) {
    taranmisList = taranmisList.filter(function(r){ return r.rowId !== rowId; });
    var tr = document.getElementById('row-' + rowId);
    if (tr) tr.remove();
    if (!taranmisList.length) {
        document.getElementById('kayitGovde').innerHTML = '<tr id="bosRow"><td colspan="15" class="text-center text-muted py-5"><i class="bi bi-qr-code display-4 opacity-25 d-block mb-2"></i>QR kodu veya PDF yükleyin — kayıtlar buraya eklenecek</td></tr>';
    }
    sayacGuncelle();
}

function sayacGuncelle() {
    var n = taranmisList.length;
    document.getElementById('sayacBadge').textContent = n + ' kayıt tarandı';
    var sc = document.getElementById('kaydetSayac');
    if (sc) sc.textContent = n;
    document.getElementById('btnTopluKaydet').classList.toggle('d-none', n === 0);
}

function listeTemizle() {
    if (!taranmisList.length || confirm('Taranan tüm kayıtlar silinecek. Emin misiniz?')) {
        taranmisList = [];
        rowSayac = 0;
        document.getElementById('kayitGovde').innerHTML = '<tr id="bosRow"><td colspan="15" class="text-center text-muted py-5"><i class="bi bi-qr-code display-4 opacity-25 d-block mb-2"></i>QR kodu okutun — kayıtlar buraya eklenecek</td></tr>';
        sayacGuncelle();
    }
}

// ── Toplu Kaydet ────────────────────────────────────────────────────
async function topluKaydet() {
    if (!taranmisList.length) return;
    var eksik = taranmisList.filter(function(r){ return !r.tedarikci_id; });
    if (eksik.length) {
        alert('Tedarikçi seçilmemiş ' + eksik.length + ' kayıt var. Lütfen tedarikçi seçin.');
        return;
    }
    var btn = document.getElementById('btnTopluKaydet');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Kaydediliyor...';

    try {
        var resp = await fetch('api/hizli_kaydet.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ kayitlar: taranmisList })
        });
        var data = await resp.json();
        var sonucEl = document.getElementById('kaydetSonuc');
        if (data.ok) {
            var html = '<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>';
            html += '<strong>' + data.eklenen + ' kayıt eklendi</strong>';
            if (data.atlanan) html += ', ' + data.atlanan + ' atlandı';
            if (data.cakismalar && data.cakismalar.length) html += ', <strong>' + data.cakismalar.length + ' çakışan</strong>';
            if (data.hatalar && data.hatalar.length) {
                html += '<ul class="mb-0 mt-1 small">' + data.hatalar.map(function(e){ return '<li>' + escHtml(e) + '</li>'; }).join('') + '</ul>';
            }
            html += '</div>';
            sonucEl.innerHTML = html;
            sonucEl.classList.remove('d-none');
            listeTemizle();
            // Çakışanları modal ile göster
            if (data.cakismalar && data.cakismalar.length) {
                cakismalariGoster(data.cakismalar);
            }
        } else {
            sonucEl.innerHTML = '<div class="alert alert-danger">Hata: ' + escHtml(data.msg) + '</div>';
            sonucEl.classList.remove('d-none');
        }
    } catch(e) {
        alert('Kayıt hatası: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i> Toplu Kaydet (<span id="kaydetSayac">' + taranmisList.length + '</span>)';
    }
}

// ── Yardımcılar ─────────────────────────────────────────────────────
function setDurum(html) {
    document.getElementById('durumEl').innerHTML = html;
}

function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', function() {
    kameralariListele();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setDurum('<i class="bi bi-exclamation-triangle text-danger me-1"></i> Bu tarayıcı kamera erişimini desteklemiyor. HTTPS bağlantısı gereklidir.');
        document.getElementById('btnAc').disabled = true;
    }
});

// ── PDF'den QR Tarama ───────────────────────────────────────────────
var pdfDosyaObj = null;

function pdfSecildi(input) {
    pdfDosyaObj = input.files[0] || null;
    document.getElementById('btnPdfTara').classList.toggle('d-none', !pdfDosyaObj);
    document.getElementById('pdfSonuc').classList.add('d-none');
    document.getElementById('pdfProgress').classList.add('d-none');
    if (pdfDosyaObj) {
        document.getElementById('pdfDurumBadge').textContent = pdfDosyaObj.name;
        document.getElementById('pdfDurumBadge').classList.remove('d-none');
    } else {
        document.getElementById('pdfDurumBadge').classList.add('d-none');
    }
}

// QR verilerini parsed nesnesine yazar (E1 → JSON öncelik sırası)
function qrVerileriniUygula(parsed, qrJson, qrE1) {
    // E1 QR: miktar, beton sınıfı, plaka, tedarikçi (OCR'ın zayıf olduğu alanlar)
    if (qrE1) {
        if (qrE1.irsaliye_no) parsed.irsaliye_no = qrE1.irsaliye_no;
        if (qrE1.tarih)       parsed.tarih        = qrE1.tarih;
        if (qrE1.sevkZamani)  parsed.sevkZamani   = qrE1.sevkZamani;
        if (qrE1.plaka)       parsed.plaka         = qrE1.plaka;
        if (qrE1.miktar)      parsed.miktar        = qrE1.miktar;
        if (qrE1.beton_sinifi) {
            var bn = qrE1.beton_sinifi.toUpperCase();
            for (var be = 0; be < BETON_SINIFLARI.length; be++) {
                var bc = BETON_SINIFLARI[be].ad.replace(/\s+/g,'').toUpperCase();
                if (bc === bn || bc.startsWith(bn+'/') || bc.startsWith(bn+'-')) {
                    parsed.beton_sinifi_id = String(BETON_SINIFLARI[be].id); break;
                }
            }
        }
        if (qrE1.vkn) {
            parsed.qrVkn = String(qrE1.vkn).trim();
            for (var te = 0; te < TEDARIKCILER.length; te++) {
                if (TEDARIKCILER[te].vkn && String(TEDARIKCILER[te].vkn).trim() === parsed.qrVkn) {
                    parsed.tedarikci_id = String(TEDARIKCILER[te].id); break;
                }
            }
        }
        if (qrE1.kivam) {
            var kn = qrE1.kivam.replace(/\s+/g,'').toUpperCase();
            for (var k = 0; k < KIVAM_SINIFLARI.length; k++) {
                if (KIVAM_SINIFLARI[k].ad.toUpperCase() === kn) {
                    parsed.kivam_sinifi_id = String(KIVAM_SINIFLARI[k].id); break;
                }
            }
        }
    }
    // JSON QR: irsaliye_no, tarih, plaka, saat, ETTN, tedarikçi (öncelikli — üstüne yazar)
    if (qrJson) {
        if (qrJson.no)         parsed.irsaliye_no = String(qrJson.no).trim();
        if (qrJson.tarih)      parsed.tarih        = String(qrJson.tarih).trim();
        if (qrJson.plaka)      parsed.plaka         = String(qrJson.plaka).replace(/\s+/g,'').toUpperCase();
        if (qrJson.sevkzamani) parsed.sevkZamani   = String(qrJson.sevkzamani).substring(0, 5);
        if (qrJson.ettn)       parsed.ettn          = String(qrJson.ettn).trim();
        if (qrJson.vkntckn) {
            if (!parsed.qrVkn) parsed.qrVkn = String(qrJson.vkntckn).trim();
            if (!parsed.tedarikci_id) {
                for (var ti = 0; ti < TEDARIKCILER.length; ti++) {
                    if (TEDARIKCILER[ti].vkn && String(TEDARIKCILER[ti].vkn).trim() === parsed.qrVkn) {
                        parsed.tedarikci_id = String(TEDARIKCILER[ti].id); break;
                    }
                }
            }
        }
    }
}

async function pdfTara() {
    if (!pdfDosyaObj) return;
    if (typeof pdfjsLib === 'undefined') { alert('PDF.js yüklenemedi. Sayfayı yenileyin.'); return; }

    var btn = document.getElementById('btnPdfTara');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Taranıyor...';
    document.getElementById('pdfSonuc').classList.add('d-none');

    var progressEl   = document.getElementById('pdfProgress');
    var progressBar  = document.getElementById('pdfProgressBar');
    var progressText = document.getElementById('pdfProgressText');
    var progressPct  = document.getElementById('pdfProgressPct');
    progressEl.classList.remove('d-none');
    progressBar.style.width = '2%';
    progressText.textContent = 'PDF açılıyor...';
    progressPct.textContent  = '';

    var canvas = document.getElementById('pdfCanvas');
    var ctx    = canvas.getContext('2d');
    var bulunan = 0, atlanan = 0, errors = [];

    try {
        var arrayBuf    = await pdfDosyaObj.arrayBuffer();
        var pdf         = await pdfjsLib.getDocument({ data: arrayBuf }).promise;
        var toplamSayfa = pdf.numPages;

        for (var i = 1; i <= toplamSayfa; i++) {
            var pct = Math.round(((i - 1) / toplamSayfa) * 95);
            progressBar.style.width = Math.max(pct, 4) + '%';
            progressPct.textContent = pct + '%';
            progressText.textContent = toplamSayfa + ' sayfadan ' + i + '. sayfa taranıyor...';

            try {
                var page     = await pdf.getPage(i);
                var qrListesi = await sayfadakiTumQrlar(page, canvas, ctx);

                if (qrListesi.length === 0) {
                    errors.push('Sayfa ' + i + ': QR kodu okunamadı');
                    continue;
                }

                var qrSonucListesi = qrListesindenVeriAl(qrListesi);
                if (qrSonucListesi.length === 0) {
                    errors.push('Sayfa ' + i + ': Tanımlanabilir QR kodu bulunamadı');
                    continue;
                }

                for (var g = 0; g < qrSonucListesi.length; g++) {
                    var qrSonuc = qrSonucListesi[g];
                    var parsed  = { irsaliye_no:'', tarih:'', plaka:'', miktar:'',
                                    sevkZamani:'', ettn:'', beton_sinifi_id:'', tedarikci_id:'',
                                    kivam_sinifi_id:'', kantar_net_yildizlar:'', kantar_net_tedarikci:'',
                                    qrVkn:'' };
                    qrVerileriniUygula(parsed, qrSonuc.json, qrSonuc.e1);

                    if (!parsed.irsaliye_no && !qrSonuc.e1) {
                        errors.push('Sayfa ' + i + ': QR içeriği tanımlanamadı');
                        continue;
                    }

                    if (parsed.irsaliye_no && taranmisList.some(function(r){ return r.irsaliye_no === parsed.irsaliye_no; })) {
                        atlanan++;
                        errors.push('Sayfa ' + i + ': ' + parsed.irsaliye_no + ' zaten listede (atlandı)');
                        continue;
                    }

                    beepSes();
                    rowSayac++;
                    var item = {
                        rowId: rowSayac,
                        irsaliye_no: parsed.irsaliye_no,
                        tarih: parsed.tarih,
                        arac_plaka: parsed.plaka,
                        mikser_cikis_saati: parsed.sevkZamani,
                        fatura_no: parsed.ettn,
                        miktar: parsed.miktar,
                        tedarikci_id: parsed.tedarikci_id,
                        beton_sinifi_id: parsed.beton_sinifi_id,
                        kivam_sinifi_id: parsed.kivam_sinifi_id || '',
                        kantar_net_yildizlar: parsed.kantar_net_yildizlar || '',
                        kantar_net_tedarikci: parsed.kantar_net_tedarikci || '',
                        proje_id: '',
                        qrKullanildi: true,
                        qrVkn: parsed.qrVkn || ''
                    };
                    taranmisList.push(item);
                    tabloSatirEkle(item);
                    sayacGuncelle();
                    bulunan++;
                }

            } catch(pageErr) {
                errors.push('Sayfa ' + i + ' işlenemedi: ' + pageErr.message);
            }
        }

        progressBar.style.width = '100%';
        progressPct.textContent = '100%';

        var sonucEl   = document.getElementById('pdfSonuc');
        var alertType = bulunan > 0 ? 'success' : 'warning';
        var html = '<div class="alert alert-' + alertType + ' mb-0">';
        html += '<i class="bi bi-' + (bulunan > 0 ? 'check-circle' : 'exclamation-circle') + ' me-1"></i>';
        html += '<strong>' + toplamSayfa + ' sayfa tarandı.</strong> ' + bulunan + ' irsaliye eklendi';
        if (atlanan) html += ', ' + atlanan + ' zaten listede atlandı';
        html += '.';
        if (errors.length) html += '<ul class="mb-0 mt-2 small">' + errors.map(function(e){ return '<li>' + escHtml(e) + '</li>'; }).join('') + '</ul>';
        html += '</div>';
        sonucEl.innerHTML = html;
        sonucEl.classList.remove('d-none');
        if (bulunan > 0) document.getElementById('kayitlarCard').scrollIntoView({ behavior: 'smooth' });

    } catch(err) {
        document.getElementById('pdfSonuc').innerHTML =
            '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Hata: ' + escHtml(err.message) + '</div>';
        document.getElementById('pdfSonuc').classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-qr-code-scan me-1"></i> QR Kodları Tara';
        progressEl.classList.add('d-none');
        document.getElementById('pdfDosya').value = '';
        pdfDosyaObj = null;
        document.getElementById('btnPdfTara').classList.add('d-none');
    }
}

// jsQR için gri-tonlama + kontrast artırma (taramalı PDF QR'ları için)
function goruntIsle(imgData) {
    var src = imgData.data, dst = new Uint8ClampedArray(src.length);
    for (var i = 0; i < src.length; i += 4) {
        var gray = 0.299 * src[i] + 0.587 * src[i+1] + 0.114 * src[i+2];
        var v = Math.max(0, Math.min(255, 2.2 * (gray - 128) + 128));
        dst[i] = dst[i+1] = dst[i+2] = v; dst[i+3] = 255;
    }
    return new ImageData(dst, imgData.width, imgData.height);
}

// Bir PDF sayfasında TÜM QR kodlarını bulur (JSON + E1)
// Önce native BarcodeDetector (Chrome/Edge — çok güçlü), yoksa jsQR + kontrast
async function sayfadakiTumQrlar(page, canvas, ctx) {
    var bulunanlar = new Set();

    function tryAdd(val) {
        if (val && val.trim()) bulunanlar.add(val.trim());
    }
    function deneBolgeJsqr(imgData) {
        var r1 = jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'attemptBoth' });
        if (r1) tryAdd(r1.data);
        var kg = goruntIsle(imgData);
        var r2 = jsQR(kg.data, kg.width, kg.height, { inversionAttempts: 'attemptBoth' });
        if (r2) tryAdd(r2.data);
    }

    // Native BarcodeDetector — QR Code (GIB) + Data Matrix (KGS/THBB E1) ikisini de tarar
    var useBarcodeDetector = ('BarcodeDetector' in window);
    if (useBarcodeDetector && !window._bdQr) {
        try {
            var fmts = ['qr_code', 'data_matrix'];
            if (typeof BarcodeDetector.getSupportedFormats === 'function') {
                var sup = await BarcodeDetector.getSupportedFormats();
                fmts = fmts.filter(function(f){ return sup.includes(f); });
            }
            window._bdQr = new BarcodeDetector({ formats: fmts.length ? fmts : ['qr_code'] });
        } catch(e) { useBarcodeDetector = false; }
    }

    var scales = [2.5, 4.0, 5.5];
    for (var si = 0; si < scales.length; si++) {
        var viewport = page.getViewport({ scale: scales[si] });
        canvas.width  = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: ctx, viewport: viewport }).promise;

        if (useBarcodeDetector) {
            try {
                var barcodes = await window._bdQr.detect(canvas);
                for (var bc of barcodes) { if (bc.rawValue) tryAdd(bc.rawValue); }
                if (bulunanlar.size >= 6) break;     // Birden fazla çift QR bulunabilir
                // Bulduysa jsQR'a gerek yok, bulamadıysa jsQR denesin
                if (bulunanlar.size > 0) continue;   // kısmen buldu, büyük ölçekle dene
            } catch(e) { useBarcodeDetector = false; }
        }

        // jsQR fallback: tam sayfa + 4 çeyrek + 4 köşe
        var tamSayfa = ctx.getImageData(0, 0, canvas.width, canvas.height);
        deneBolgeJsqr(tamSayfa);
        if (bulunanlar.size >= 6) break;

        var hw = Math.floor(canvas.width / 2), hh = Math.floor(canvas.height / 2);
        [[0,0,hw,hh],[hw,0,hw,hh],[0,hh,hw,hh],[hw,hh,hw,hh]].forEach(function(b){
            deneBolgeJsqr(ctx.getImageData(b[0],b[1],b[2],b[3]));
        });
        if (bulunanlar.size >= 6) break;

        var cw = Math.floor(canvas.width / 3), ch = Math.floor(canvas.height / 3);
        [[canvas.width-cw,0,cw,ch],[canvas.width-cw,canvas.height-ch,cw,ch],
         [0,canvas.height-ch,cw,ch],[0,0,cw,ch]].forEach(function(k){
            deneBolgeJsqr(ctx.getImageData(k[0],k[1],k[2],k[3]));
        });
        if (bulunanlar.size >= 6) break;
    }
    return Array.from(bulunanlar);
}

// Geriye dönük uyumluluk — ilk bulduğu QR'ı döndürür (eski API)
async function sayfadaQrBul(page, canvas, ctx) {
    var hepsi = await sayfadakiTumQrlar(page, canvas, ctx);
    return hepsi.length > 0 ? hepsi[0] : null;
}

// E1 formatlı QR'ı parse eder
// Örnek: E1SAM202400002490562104209552024101518119/9C250,60S4N0,20220,5606CTY232CEMII 42,5...
function parseE1Qr(s) {
    if (!s) return null;
    s = s.replace(/\s+/g, '');
    if (s.substring(0,2) !== 'E1') return null;
    var d = { irsaliye_no:'', vkn:'', tarih:'', sevkZamani:'', miktar:'',
              beton_sinifi:'', dayanim:'', kivam:'', yogunluk:'',
              klorur:'', dmax:'', suCimento:'', plaka:'' };

    // E1 + harf prefix (2-5) + 13 digit (sabit, TR e-irsaliye standardı) + 10 digit VKN
    //    + 8 digit tarih (YYYYMMDD) + 4 digit saat (HHMM) + X/Y miktar + Cxx beton sınıfı
    //    + dayanım/kıvam/yoğunluk/klorür/Dmax/su-çimento — sonra plaka
    var m = s.match(/^E1([A-ZÇĞİÖŞÜ]{2,5}\d{13})(\d{10})(\d{8})(\d{4})(\d{1,3})\/\d{1,3}(C\d{2,3})(?:\s*[\/\-]\s*\d{2,3})?(\d{1,2},\d{1,2})(S\d{1,2})([A-Z]{1,2})(\d,\d{1,2})(\d{2})(\d,\d{1,2})(\d{2}[A-ZÇĞİÖŞÜ]{2,3}\d{2,4})/);
    if (!m) {
        // Gevşek fallback: en az irsaliye_no, VKN, tarih, saat, miktar, beton sınıfı
        var m2 = s.match(/^E1([A-ZÇĞİÖŞÜ]{2,5}\d{13})(\d{10})(\d{8})(\d{4})(\d{1,3})\/\d{1,3}(C\d{2,3})/);
        if (!m2) return null;
        d.irsaliye_no = m2[1];
        d.vkn         = m2[2];
        d.tarih       = m2[3].substring(0,4) + '-' + m2[3].substring(4,6) + '-' + m2[3].substring(6,8);
        d.sevkZamani  = m2[4].substring(0,2) + ':' + m2[4].substring(2,4);
        d.miktar      = m2[5];
        d.beton_sinifi= m2[6];
        // Plaka aramayı eşleşen kısımdan SONRAKİ metinde yap
        var restFb = s.substring(m2[0].length);
        var pmFb = restFb.match(/(\d{2}[A-ZÇĞİÖŞÜ]{2,3}\d{2,4})/);
        if (pmFb) d.plaka = pmFb[1];
        return d;
    }
    d.irsaliye_no = m[1];
    d.vkn         = m[2];
    d.tarih       = m[3].substring(0,4) + '-' + m[3].substring(4,6) + '-' + m[3].substring(6,8);
    d.sevkZamani  = m[4].substring(0,2) + ':' + m[4].substring(2,4);
    d.miktar      = m[5];
    d.beton_sinifi= m[6];
    d.dayanim     = m[7];
    d.kivam       = m[8];
    d.yogunluk    = m[9];
    d.klorur      = m[10];
    d.dmax        = m[11];
    d.suCimento   = m[12];
    d.plaka       = m[13];
    return d;
}

// QR listesinden JSON ve E1 verilerini irsaliye numarasına göre gruplayıp birleştirir
function qrListesindenVeriAl(qrListesi) {
    var jsonListesi = [];
    var e1Listesi = [];

    for (var i = 0; i < qrListesi.length; i++) {
        var s = qrListesi[i];
        if (!s) continue;
        if (s.charAt(0) === '{') {
            try {
                var rawJson = JSON.parse(s);
                var normalizedJson = {};
                for (var key in rawJson) {
                    if (rawJson.hasOwnProperty(key)) {
                        normalizedJson[key.toLowerCase()] = rawJson[key];
                    }
                }
                jsonListesi.push(normalizedJson);
            } catch(e) {}
        } else if (s.substring(0,2) === 'E1') {
            var e1 = parseE1Qr(s);
            if (e1) {
                e1Listesi.push(e1);
            }
        }
    }

    var sonucListe = [];
    var eslesenE1Indexes = new Set();

    // JSON'ları irsaliye numaralarına göre gruplayıp uygun E1'lerle eşleştirelim
    for (var j = 0; j < jsonListesi.length; j++) {
        var jsonItem = jsonListesi[j];
        var jsonNo = jsonItem.no ? String(jsonItem.no).trim() : '';
        
        var matchedE1 = null;
        for (var k = 0; k < e1Listesi.length; k++) {
            var e1Item = e1Listesi[k];
            var e1No = e1Item.irsaliye_no ? String(e1Item.irsaliye_no).trim() : '';
            if (jsonNo && e1No && jsonNo === e1No) {
                matchedE1 = e1Item;
                eslesenE1Indexes.add(k);
                break;
            }
        }
        sonucListe.push({ json: jsonItem, e1: matchedE1 });
    }

    // Eşleşmeyen E1 kodlarını da tekil irsaliye olarak ekleyelim
    for (var k = 0; k < e1Listesi.length; k++) {
        if (!eslesenE1Indexes.has(k)) {
            sonucListe.push({ json: null, e1: e1Listesi[k] });
        }
    }

    return sonucListe;
}

// ── PDF OCR (Metin Çıkartma) ────────────────────────────────────────
var ocrDosyaObj = null;

function ocrSecildi(input) {
    ocrDosyaObj = input.files[0] || null;
    ocrPdfUrl   = '';
    document.getElementById('btnOcrTara').classList.toggle('d-none', !ocrDosyaObj);
    document.getElementById('ocrSonuc').classList.add('d-none');
    document.getElementById('ocrProgress').classList.add('d-none');
    if (ocrDosyaObj) {
        var fd = new FormData(); fd.append('dosya', ocrDosyaObj);
        fetch('api/pdf_kaydet.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.ok) ocrPdfUrl = d.url; })
            .catch(function(){});
    }
}

// Sayfadan PDF metin katmanını çıkar (dijital PDF için anında — OCR gerektirmez)
async function sayfadanTextAl(page) {
    try {
        var tc = await page.getTextContent();
        var text = tc.items.map(function(it){ return it.str; }).join(' ').trim();
        return (text.replace(/\s/g,'').length > 40) ? text : null;
    } catch(e) { return null; }
}

// Sayfa görselini sunucuya kaydet, URL döndür
async function sayfaGorselKaydet(canvas) {
    try {
        // Thumbnail boyutuna küçült (performans + boyut)
        var thumb = document.createElement('canvas');
        var maxW = 480;
        var ratio = Math.min(maxW / canvas.width, 1);
        thumb.width  = Math.round(canvas.width  * ratio);
        thumb.height = Math.round(canvas.height * ratio);
        thumb.getContext('2d').drawImage(canvas, 0, 0, thumb.width, thumb.height);

        var base64 = thumb.toDataURL('image/jpeg', 0.75);
        var fd = new FormData();
        fd.append('image', base64);

        var resp = await fetch('api/scan_kaydet.php', { method: 'POST', body: fd });
        var data = await resp.json();
        return data.ok ? data.url : null;
    } catch(e) { return null; }
}

async function ocrTara() {
    if (!ocrDosyaObj) return;
    var btn = document.getElementById('btnOcrTara');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Okunuyor...';
    document.getElementById('ocrSonuc').classList.add('d-none');

    var progressEl  = document.getElementById('ocrProgress');
    var progressBar = document.getElementById('ocrProgressBar');
    var progressText= document.getElementById('ocrProgressText');
    var progressPct = document.getElementById('ocrProgressPct');
    progressEl.classList.remove('d-none');
    progressBar.style.width = '0%';

    var bulunan = 0, atlanan = 0, hatalar = [];
    var worker = null;
    var ocrGerekli = false; // gerçekten OCR lazım mı diye ilk geçişte kontrol

    try {
        var arrayBuf = await ocrDosyaObj.arrayBuffer();
        var pdf = await pdfjsLib.getDocument({ data: arrayBuf }).promise;
        var toplamSayfa = pdf.numPages;
        var ocrCanvas = document.createElement('canvas');
        var ocrCtx = ocrCanvas.getContext('2d');

        // ── Ön kontrol: PDF text layer var mı?
        progressText.textContent = 'PDF analiz ediliyor (' + toplamSayfa + ' sayfa)...';
        var ilkSayfa = await pdf.getPage(1);
        var textKatmani = await sayfadanTextAl(ilkSayfa);
        ocrGerekli = !textKatmani; // metin yoksa OCR şart

        // ── OCR worker'ı sadece gerekirse başlat
        if (ocrGerekli) {
            progressText.textContent = 'Tesseract OCR motoru başlatılıyor... (taranmış PDF)';
            progressBar.style.width = '3%';
            worker = await Tesseract.createWorker('tur', 1, {
                workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
                langPath:   'https://tessdata.projectnaptha.com/4.0.0',
                logger: function(m) {
                    if (m.status === 'loading tesseract core' || m.status === 'loading language traineddata') {
                        progressText.textContent = 'Dil paketi indiriliyor... (ilk seferde uzun sürebilir)';
                    }
                }
            });
        } else {
            progressText.textContent = 'Dijital PDF — metin katmanı kullanılacak (hızlı mod) ✓';
        }

        for (var i = 1; i <= toplamSayfa; i++) {
            var pct = Math.round(((i - 1) / toplamSayfa) * 100);
            progressBar.style.width = Math.max(pct, 5) + '%';
            progressPct.textContent = pct + '%';
            progressText.textContent = toplamSayfa + ' sayfadan ' + i + '. sayfa işleniyor...';

            try {
                var page = i === 1 ? ilkSayfa : await pdf.getPage(i);

                // ── 1) Sayfa görselini render et (QR tarama + OCR + thumbnail için ortak)
                var viewport = page.getViewport({ scale: ocrGerekli ? 2.0 : 3.0 });
                ocrCanvas.width  = viewport.width;
                ocrCanvas.height = viewport.height;
                await page.render({ canvasContext: ocrCtx, viewport: viewport }).promise;

                // ── 2) Görsel sunucuya kaydet (arka planda — beklemiyoruz)
                var scanUrlPromise = sayfaGorselKaydet(ocrCanvas);

                // ── 3) QR + DataMatrix tara (BarcodeDetector ile paralel)
                var qrJson = null, qrE1 = null;
                try {
                    var qrListesi = await sayfadakiTumQrlar(page, ocrCanvas, ocrCtx);
                    var qrSonucListesi = qrListesindenVeriAl(qrListesi);
                    if (qrSonucListesi.length > 0) {
                        qrJson = qrSonucListesi[0].json;
                        qrE1   = qrSonucListesi[0].e1;
                    }
                } catch(qrErr) {}

                // ── 4) Metin al: text layer VEYA OCR
                var text = '';
                if (!ocrGerekli) {
                    text = (await sayfadanTextAl(page)) || '';
                } else {
                    progressText.textContent = toplamSayfa + ' sayfadan ' + i + '. sayfa OCR yapılıyor...';
                    // Taranmış PDF için iki geçiş: normal + kontrast
                    // Geçiş 1: Gri+yüksek kontrast (metin/arka plan ayrımını artırır)
                    var kontrCanvas = document.createElement('canvas');
                    kontrCanvas.width  = ocrCanvas.width;
                    kontrCanvas.height = ocrCanvas.height;
                    var kontrCtx = kontrCanvas.getContext('2d');
                    kontrCtx.filter = 'grayscale(100%) contrast(200%) brightness(1.05)';
                    kontrCtx.drawImage(ocrCanvas, 0, 0);
                    var result1 = await worker.recognize(kontrCanvas);
                    text = result1.data.text || '';

                    // Geçiş 2: Eğer metin çok azsa sharpen ile tekrar dene
                    if (text.replace(/\s/g,'').length < 30) {
                        kontrCtx.filter = 'grayscale(100%) contrast(250%) brightness(0.95)';
                        kontrCtx.drawImage(ocrCanvas, 0, 0);
                        var result2 = await worker.recognize(kontrCanvas);
                        // Uzun olanı al
                        if ((result2.data.text||'').length > text.length) text = result2.data.text;
                    }
                }

                // Görsel URL'sini bekle
                var scanImageUrl = await scanUrlPromise;

                var parsed = parseIrsaliyeMetin(text);

                // ── 3) E1 QR'dan veriler (miktar, beton sınıfı, plaka — OCR'dan daha güvenilir)
                if (qrE1) {
                    if (qrE1.irsaliye_no) parsed.irsaliye_no = qrE1.irsaliye_no;
                    if (qrE1.tarih)       parsed.tarih       = qrE1.tarih;
                    if (qrE1.sevkZamani)  parsed.sevkZamani  = qrE1.sevkZamani;
                    if (qrE1.plaka)       parsed.plaka       = qrE1.plaka;
                    if (qrE1.miktar)      parsed.miktar      = qrE1.miktar;
                    // Beton sınıfı E1'den (örn. C30) — DB'de "C30" veya "C30/37" formatıyla eşle
                    if (qrE1.beton_sinifi) {
                        var bnE1 = qrE1.beton_sinifi.toUpperCase();
                        for (var be = 0; be < BETON_SINIFLARI.length; be++) {
                            var bcE1 = BETON_SINIFLARI[be].ad.replace(/\s+/g,'').toUpperCase();
                            if (bcE1 === bnE1 || bcE1.startsWith(bnE1 + '/') || bcE1.startsWith(bnE1 + '-')) {
                                parsed.beton_sinifi_id = String(BETON_SINIFLARI[be].id); break;
                            }
                        }
                    }
                    // Tedarikçi VKN eşleştirmesi (E1'de de var)
                    if (qrE1.vkn && !parsed.tedarikci_id) {
                        for (var te = 0; te < TEDARIKCILER.length; te++) {
                            if (TEDARIKCILER[te].vkn && String(TEDARIKCILER[te].vkn) === qrE1.vkn) {
                                parsed.tedarikci_id = String(TEDARIKCILER[te].id); break;
                            }
                        }
                    }
                    // Kıvam sınıfı eşleştirmesi
                    if (qrE1.kivam) {
                        var knE1 = qrE1.kivam.toUpperCase();
                        for (var k = 0; k < KIVAM_SINIFLARI.length; k++) {
                            if (KIVAM_SINIFLARI[k].ad.toUpperCase() === knE1) {
                                parsed.kivam_sinifi_id = String(KIVAM_SINIFLARI[k].id); break;
                            }
                        }
                    }
                }

                // ── 4) JSON QR (öncelikli — en güvenilir, modern e-irsaliye standardı)
                if (qrJson) {
                    if (qrJson.no)         parsed.irsaliye_no = String(qrJson.no).trim();
                    if (qrJson.tarih)      parsed.tarih       = String(qrJson.tarih).trim();
                    if (qrJson.plaka)      parsed.plaka       = String(qrJson.plaka).replace(/\s+/g,'').toUpperCase();
                    if (qrJson.sevkzamani) parsed.sevkZamani  = String(qrJson.sevkzamani).substring(0,5);
                    if (qrJson.ettn)       parsed.ettn        = String(qrJson.ettn).trim();
                    if (qrJson.vkntckn && !parsed.tedarikci_id) {
                        for (var ti = 0; ti < TEDARIKCILER.length; ti++) {
                            if (TEDARIKCILER[ti].vkn && String(TEDARIKCILER[ti].vkn) === String(qrJson.vkntckn)) {
                                parsed.tedarikci_id = String(TEDARIKCILER[ti].id); break;
                            }
                        }
                    }
                }

                var qrVarMi = !!(qrJson || qrE1);
                if (!qrVarMi && (!text || text.replace(/\s/g, '').length < 10)) {
                    hatalar.push('Sayfa ' + i + ': QR ve OCR metni okunamadı');
                    continue;
                }

                if (parsed.irsaliye_no && taranmisList.some(function(r){ return r.irsaliye_no === parsed.irsaliye_no; })) {
                    atlanan++;
                    hatalar.push('Sayfa ' + i + ': ' + parsed.irsaliye_no + ' zaten listede (atlandı)');
                    continue;
                }

                beepSes();
                rowSayac++;
                var item = {
                    rowId: rowSayac,
                    irsaliye_no: parsed.irsaliye_no,
                    tarih:        parsed.tarih,
                    arac_plaka:   parsed.plaka,
                    mikser_cikis_saati: parsed.sevkZamani,
                    fatura_no:    parsed.ettn,
                    miktar:       parsed.miktar,
                    tedarikci_id: parsed.tedarikci_id,
                    beton_sinifi_id: parsed.beton_sinifi_id,
                    kivam_sinifi_id: parsed.kivam_sinifi_id || '',
                    kantar_net_yildizlar: parsed.kantar_net_yildizlar || '',
                    kantar_net_tedarikci: parsed.kantar_net_tedarikci || '',
                    proje_id: '',
                    qrKullanildi: qrVarMi,
                    scanImageUrl: scanImageUrl || '',
                    docUrl: ocrPdfUrl || ''
                };
                taranmisList.push(item);
                tabloSatirEkle(item);
                sayacGuncelle();
                bulunan++;
            } catch(pageErr) {
                hatalar.push('Sayfa ' + i + ' okunamadı: ' + pageErr.message);
            }
        }

        await worker.terminate();
        worker = null;

        progressBar.style.width = '100%';
        progressPct.textContent = '100%';

        var sonucEl = document.getElementById('ocrSonuc');
        var at = bulunan > 0 ? 'success' : 'warning';
        var html = '<div class="alert alert-' + at + ' mb-0">';
        html += '<i class="bi bi-' + (bulunan > 0 ? 'check-circle' : 'exclamation-circle') + ' me-1"></i>';
        html += '<strong>' + toplamSayfa + ' sayfa OCR ile okundu.</strong> ' + bulunan + ' irsaliye eklendi';
        if (atlanan) html += ', ' + atlanan + ' atlandı';
        html += '.';
        if (hatalar.length) html += '<ul class="mb-0 mt-2 small">' + hatalar.map(function(e){ return '<li>' + escHtml(e) + '</li>'; }).join('') + '</ul>';
        html += '</div>';
        sonucEl.innerHTML = html;
        sonucEl.classList.remove('d-none');
        if (bulunan > 0) document.getElementById('kayitlarCard').scrollIntoView({ behavior: 'smooth' });
    } catch(err) {
        if (worker) try { await worker.terminate(); } catch(e) {}
        document.getElementById('ocrSonuc').innerHTML = '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Hata: ' + escHtml(err.message) + '</div>';
        document.getElementById('ocrSonuc').classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-file-text me-1"></i> OCR ile Oku &amp; Ekle';
        progressEl.classList.add('d-none');
        document.getElementById('ocrDosya').value = '';
        ocrDosyaObj = null;
        document.getElementById('btnOcrTara').classList.add('d-none');
    }
}

function ocrNormalize(text) {
    // Taranmış PDF OCR hatalarını düzelt
    // Tüm metin üzerinde genel karakter normalizasyonu (Türkçe beton irsaliyesi için)
    return text
        // Yaygın OCR hata düzeltmeleri
        .replace(/\bVKl\b/g, 'VKN')
        .replace(/\bl([0-9])/g, '1$1')   // l1→11, l5→15 (küçük L → rakam 1)
        .replace(/\bO([0-9])/g, '0$1')   // O1→01 (büyük O → sıfır)
        // Beton sınıfı OCR hataları: C3O → C30, C25/3O → C25/30
        .replace(/\bC(\d+)\/(\d*)O\b/g, function(_, a, b) { return 'C'+a+'/'+(b||'')+'0'; })
        // Kıvam: 5x → Sx (OCR S'yi 5 olarak okuyor) sadece tek basamaklı sayı önünde
        .replace(/\b5([1-5])\b/g, 'S$1');
}

function parseIrsaliyeMetin(text) {
    // OCR hatalarını normalize et
    text = ocrNormalize(text);

    var lines = text.split(/\r?\n/).map(function(s){ return s.trim(); }).filter(function(s){ return s.length > 0; });
    var flat  = text.replace(/\s+/g, ' ').trim();
    var r = { irsaliye_no:'', tarih:'', plaka:'', miktar:'', sevkZamani:'', ettn:'', beton_sinifi_id:'', tedarikci_id:'',
              kivam_sinifi_id:'', kantar_net_yildizlar:'', kantar_net_tedarikci:'' };

    // ── İrsaliye No ──────────────────────────────────────────────────────────
    var noM =
        flat.match(/[İIil1]rsaliye\s*[Nn]o[\s:.]*([A-ZÇĞİÖŞÜa-zçğiöşü0-9]{6,25})/) ||
        flat.match(/[Bb]elge\s*[Nn]o[\s:.]*([A-ZÇĞİÖŞÜ0-9]{6,25})/) ||
        flat.match(/[Ss]eri\s*[Nn]o[\s:.]*([A-ZÇĞİÖŞÜ0-9]{6,25})/) ||
        flat.match(/\b([A-ZÇĞİÖŞÜ]{2,5}\d{10,20})\b/);
    if (noM) {
        var raw = noM[1].trim();
        r.irsaliye_no = raw.replace(/^([A-ZÇĞİÖŞÜ]{2,3})(.*)$/, function(_, pfx, rest) {
            return pfx + rest.replace(/Z/g,'2').replace(/O/g,'0').replace(/I/g,'1').replace(/B/g,'8');
        });
    }

    // ── Tarih DD.MM.YYYY → YYYY-MM-DD ────────────────────────────────────────
    var tM = flat.match(/\b(\d{2})[.\/\-](\d{2})[.\/\-](\d{4})\b/);
    if (tM) r.tarih = tM[3] + '-' + tM[2] + '-' + tM[1];

    // ── Plaka ────────────────────────────────────────────────────────────────
    function bulPlaka(s) {
        // OCR normalizasyonu: O→0, I/l→1, Z→2 (sayı kısmında), S→5 geri al (rakam takip ediyorsa)
        var ns = s.replace(/\bO(\d)/g,'0$1').replace(/(\d)O\b/g,'$10')
                  .replace(/\bI(\d)/g,'1$1').replace(/\bl(\d)/g,'1$1')
                  .replace(/(\d)Z\b/g,'$12')   // 23Z → 232
                  .replace(/\bZ(\d)/g,'2$1');   // Z32 → 232
        // Format: 2 rakam + 2-3 harf + 2-4 rakam (boşluklu da olabilir)
        var pats = [
            /\b(\d{2})\s*([A-ZÇĞİÖŞÜ]{2,3})\s*(\d{2,4})\b/,
            /\b(\d{2})\s*([A-ZÇĞİÖŞÜ]{2,3})\s*([0-9OI]{2,4})\b/,
            // OCR: rakam yerine harf olan kısımlar
            /\b([0-9OIl]{2})\s*([A-ZÇĞİÖŞÜa-z]{2,3})\s*([0-9OI]{2,4})\b/
        ];
        for (var pi = 0; pi < pats.length; pi++) {
            var m = ns.match(pats[pi]);
            if (m) {
                return (m[1].replace(/O/g,'0').replace(/[Ii]/g,'1') +
                        m[2].toUpperCase() +
                        m[3].replace(/O/g,'0').replace(/[Ii]/g,'1')).toUpperCase();
            }
        }
        return '';
    }

    // Mikser Kodu - Plaka satırında ara (öncelikli)
    for (var li = 0; li < lines.length; li++) {
        if (/[Mm]ikser[\s\S]{0,30}?[Pp]laka/i.test(lines[li])) {
            var blob = [lines[li], lines[li+1]||'', lines[li+2]||'', lines[li+3]||''].join(' ');
            var p = bulPlaka(blob);
            if (p) { r.plaka = p; break; }
        }
    }
    // Tüm metinde fallback (Pompa Kodu satırını atla)
    if (!r.plaka) {
        var flatPlakasiz = flat.replace(/[Pp]ompa\s+[Kk]odu[\s\S]{0,60}/g, ' ');
        r.plaka = bulPlaka(flatPlakasiz);
    }

    // ── Miktar m³ ───────────────────────────────────────────────────────────
    // False positive kaynakları: "Dayanım Gelişimi 0,60", "Su/Çimento 0,45" → sadece o satırları sil
    var temizLines = text.split(/\r?\n/).map(function(s){ return s.trim(); }).filter(function(s){
        return s.length > 0
            && !/[Dd]ayan[ıi]m\s*[Gg]eli[şs]imi/i.test(s)
            && !/[Ss]u\s*[\/\-]\s*[Çc][iİ]mento/i.test(s)
            && !/[Kk]lor[üu]r/i.test(s)
            && !/[Yy]o[ğg]unluk/i.test(s);
    });
    // temizFlat: sadece sorunlu satırları çıkar, sayıları dokunma
    var temizFlat = temizLines.join(' ');

    function miktarBul(s) {
        // 1) Ondalıklı + m birimi (m³, m3, m2, m?)
        var m = s.match(/\b(\d{1,3}[.,]\d{1,2})\s*m[³3²2\?]?\b/i);
        if (m) { var v = parseFloat(m[1].replace(',','.')); if (v >= 0.5 && v < 200) return m[1]; }
        // 2) Tam sayı + m birimi
        var m2 = s.match(/\b(\d{1,3})\s*m[³3²2]\b/i);
        if (m2) { var v2 = parseInt(m2[1]); if (v2 >= 1 && v2 < 200) return m2[1] + ',00'; }
        // 3) Ondalıklı + "m" (büyük harf takip etmeden): "10,00 m" (³ OCR'da kayboldu)
        var m3 = s.match(/\b(\d{1,3}[.,]\d{2})\s+m\b(?!\s*[A-ZÇĞİÖŞÜa-zçğıöşü])/);
        if (m3) { var v3 = parseFloat(m3[1].replace(',','.')); if (v3 >= 1 && v3 < 200) return m3[1]; }
        // 4) "T TESLİMİ" / "P TESLİMİ" satırında: "C30 T TESLİMİ 10,00"
        var m4 = s.match(/[TPtp]\s*[Tt]eslim[İi]\s+(\d{1,3}[.,]\d{1,2})/);
        if (m4) { var v4 = parseFloat(m4[1].replace(',','.')); if (v4 >= 0.5 && v4 < 200) return m4[1]; }
        // 5) "Miktar" kelimesinin hemen yanında birim olmadan (son çare)
        var m5 = s.match(/[Mm]iktar[\s:]+(\d{1,3}[.,]\d{2})/);
        if (m5) { var v5 = parseFloat(m5[1].replace(',','.')); if (v5 >= 0.5 && v5 < 200) return m5[1]; }
        return null;
    }

    var miktarHam = null;
    // 1) "Miktar" başlığı yakınında ara (en güvenilir)
    for (var mi = 0; mi < temizLines.length; mi++) {
        if (/[Mm]iktar/.test(temizLines[mi])) {
            var area = temizLines.slice(mi, mi + 6).join(' ');
            miktarHam = miktarBul(area);
            if (miktarHam) break;
        }
    }
    // 2) Beton sınıfı + Teslim şekli satırı (tablo satırı tam: "C30 T TESLİMİ 10,00")
    if (!miktarHam) {
        for (var bi2 = 0; bi2 < temizLines.length; bi2++) {
            if (/\bC\s?\d{2}/i.test(temizLines[bi2])) {
                var area3 = temizLines.slice(bi2, bi2 + 5).join(' ');
                miktarHam = miktarBul(area3);
                if (miktarHam) break;
            }
        }
    }
    // 3) Temizlenmiş metinde genel fallback
    if (!miktarHam) miktarHam = miktarBul(temizFlat);
    // 4) Son çare: ham metinde hiçbir şey bulunamazsa daha geniş ara
    if (!miktarHam) {
        var rawLines = text.split(/\r?\n/).map(function(s){ return s.trim(); })
            .filter(function(s){ return s.length > 0 && !/[Dd]ayan[ıi]m\s*[Gg]eli[şs]/i.test(s); });
        for (var ri = 0; ri < rawLines.length; ri++) {
            miktarHam = miktarBul(rawLines[ri]);
            if (miktarHam) break;
        }
    }

    if (miktarHam) {
        var ms = miktarHam.replace(',', '.');
        var mn = parseFloat(ms);
        if (!isNaN(mn) && mn >= 0.5 && mn < 200) r.miktar = ms; // 0.5-200 arası
    }

    // ── Sevk saati ──────────────────────────────────────────────────────────
    var sM = flat.match(/[Ss]evk[\s\S]{0,60}?(\d{2}:\d{2})/) ||
             flat.match(/[Üü]retim\s+[Bb]a[şs]lang[ıi][çc][\s\S]{0,30}?(\d{2}:\d{2})/) ||
             flat.match(/[Çç][ıi]k[ıi][şs][\s\S]{0,30}?(\d{2}:\d{2})/) ||
             flat.match(/(\d{2}:\d{2}:\d{2})/);
    if (sM) r.sevkZamani = sM[1].substring(0, 5);

    // ── ETTN (UUID formatı, büyük harf) ─────────────────────────────────────
    function temizleEttn(s) {
        return s.toUpperCase()
            .replace(/[—–]/g, '-')   // uzun tire → normal tire
            .replace(/O/g, '0')       // harf O → rakam 0 (UUID'de O geçersiz)
            .replace(/\bI\b/g, '1');  // harf I → rakam 1
    }
    // UUID regex: O ve l de olabilir (OCR hatası)
    var uuidPat = /\b([0-9A-Fa-fOIl]{8}[-—–][0-9A-Fa-fOIl]{4}[-—–][0-9A-Fa-fOIl]{4}[-—–][0-9A-Fa-fOIl]{4}[-—–][0-9A-Fa-fOIl]{12})\b/;
    var uuidM = flat.match(uuidPat);
    if (uuidM) {
        r.ettn = temizleEttn(uuidM[1]);
    } else {
        var eM = flat.match(/[Ee][Tt][Tt][Nn][\s:]+([A-Za-z0-9OIl\-]{30,50})/);
        if (eM) r.ettn = temizleEttn(eM[1].trim());
    }

    // ── Beton sınıfı ────────────────────────────────────────────────────────
    // p1: "C35/45" → grup1=35, grup2=45
    // p2: "C35 BRÜT" → grup1=35
    // p3: "Basınç Dayanım Sınıfı C35" → grup1="C35"
    // p4: "Beton Sınıfı C35" fallback → grup1="C35"
    var bM = flat.match(/\bC\s*(\d{2,3})\s*[\/\-]\s*(\d{2,3})\b/i) ||
             flat.match(/\bC\s*(\d{2,3})\s+BRÜT\b/i) ||
             flat.match(/[Bb]as[ıi]n[çc]\s*[Dd]ayan[ıi]m\s*[Ss][ıi]n[ıi]f[ıi][\s:]*([Cc]\s*\d{2,3})/i) ||
             flat.match(/[Bb]eton\s*[Ss][ıi]n[ıi]f[ıi][\s:]+([Cc]\s*\d{2,3})/i);
    if (bM) {
        var rawBeton;
        if (bM[2]) {
            rawBeton = 'C' + bM[1].replace(/\s/g,'') + '/' + bM[2];
        } else {
            var v = (bM[1] || '').replace(/\s/g,'').toUpperCase();
            rawBeton = v.charAt(0) === 'C' ? v : ('C' + v);
        }
        var bn = rawBeton.toUpperCase();
        for (var b = 0; b < BETON_SINIFLARI.length; b++) {
            var bc = BETON_SINIFLARI[b].ad.replace(/\s+/g,'').toUpperCase();
            if (bc === bn || bc.startsWith(bn) || bn.startsWith(bc)) {
                r.beton_sinifi_id = String(BETON_SINIFLARI[b].id); break;
            }
        }
    }

    // ── Tedarikçi ───────────────────────────────────────────────────────────
    var vknM = flat.match(/\bVKN\s*[:\s]\s*(\d{10,11})\b/) ||
               flat.match(/\b(\d{10})\b.*?[Vv]ergi/);
    var ocrVkn = vknM ? vknM[1] : '';

    var musteriIdx = flat.toUpperCase().indexOf('MÜŞTERİ ADI');
    var aramaMetni = musteriIdx > 0 ? flat.substring(0, musteriIdx) : flat;
    var fu = aramaMetni.toUpperCase();

    for (var t = 0; t < TEDARIKCILER.length; t++) {
        if (ocrVkn && TEDARIKCILER[t].vkn && String(TEDARIKCILER[t].vkn).trim() === ocrVkn.trim()) {
            r.tedarikci_id = String(TEDARIKCILER[t].id); break;
        }
    }
    if (!r.tedarikci_id) {
        for (var t2 = 0; t2 < TEDARIKCILER.length; t2++) {
            var ta = TEDARIKCILER[t2].ad.toUpperCase();
            var words = ta.split(' ').filter(function(w){ return w.length >= 5; });
            var hit = words.length > 0 && words.every(function(w){ return fu.includes(w); });
            if (!hit && ta.length >= 5) hit = fu.includes(ta);
            if (hit) { r.tedarikci_id = String(TEDARIKCILER[t2].id); break; }
        }
    }

    // ── Kıvam Sınıfı ─────────────────────────────────────────────────────────
    // OCR S→5 dönüşümü ocrNormalize'da düzeltildi (5x→Sx)
    // Ek kontrol: "Kıvam Sınıfı" başlığı yakınında ara (öncelikli)
    var kivamM =
        flat.match(/[Kk][ıi]vam\s*[Ss][ıi]n[ıi]f[ıi][\s:.]*([Ss][1-5])\b/) ||
        flat.match(/[Kk][ıi]vam[\s:.]+([Ss][1-5])\b/) ||
        flat.match(/\b([Ss][1-5])\b/);
    if (kivamM) {
        var kn = kivamM[1].replace(/\s+/g,'').toUpperCase();
        for (var k = 0; k < KIVAM_SINIFLARI.length; k++) {
            if (KIVAM_SINIFLARI[k].ad.toUpperCase() === kn) {
                r.kivam_sinifi_id = String(KIVAM_SINIFLARI[k].id); break;
            }
        }
    }

    // ── Kantar Net ───────────────────────────────────────────────────────────
    var kantarM = flat.match(/(?:[Nn]et|[Nn]et\s*[Aa]ğ[ıi]rl[ıi]k|[Kk]antar\s*[Nn]et)[\s:.]*([1-9]\d{1,2}[.,\s]?\d{3})\b/i);
    if (kantarM) {
        var rawKantar = kantarM[1].replace(/[.,\s]/g, '');
        var valKantar = parseFloat(rawKantar);
        if (!isNaN(valKantar) && valKantar >= 1000 && valKantar <= 100000) {
            r.kantar_net_yildizlar = String(valKantar);
            r.kantar_net_tedarikci = String(valKantar);
        }
    }

    return r;
}

// tabloSatirEkleOcr kaldırıldı, tek tabloSatirEkle kullanılıyor.

// ── AI ile Oku (Hızlı Tarama) ────────────────────────────────────────
var aiDosyaObj = null;
var aiSonucAlanlar = {};

var AI_ETIKETLER = {
    irsaliye_no:        'İrsaliye No',
    tarih:              'Tarih',
    arac_plaka:         'Araç Plaka',
    miktar:             'Miktar (m³)',
    tedarikci_vkn:      'Tedarikçi VKN',
    beton_sinifi:       'Beton Sınıfı',
    kivam:              'Kıvam',
    ettn:               'ETTN',
    fatura_no:          'Fatura No',
    mikser_cikis_saati: 'Mikser Çıkış Saati',
    katki1:             'Katkı 1',
    katki2:             'Katkı 2',
};

function aiDosyaSecildi(input) {
    aiDosyaObj  = input.files[0] || null;
    aiDosyaUrl  = '';
    document.getElementById('btnAiOku').classList.toggle('d-none', !aiDosyaObj);
    document.getElementById('aiSonucHT').classList.add('d-none');
    document.getElementById('aiHataHT').classList.add('d-none');
    if (!aiDosyaObj) return;
    var isPdf = aiDosyaObj.type === 'application/pdf' || aiDosyaObj.name.toLowerCase().endsWith('.pdf');
    if (isPdf) {
        var fd = new FormData(); fd.append('dosya', aiDosyaObj);
        fetch('api/pdf_kaydet.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.ok) aiDosyaUrl = d.url; })
            .catch(function(){});
    } else {
        var reader = new FileReader();
        reader.onload = function(e) {
            var fd2 = new FormData(); fd2.append('image', e.target.result);
            fetch('api/scan_kaydet.php', { method: 'POST', body: fd2 })
                .then(function(r){ return r.json(); })
                .then(function(d){ if (d.ok) aiDosyaUrl = d.url; })
                .catch(function(){});
        };
        reader.readAsDataURL(aiDosyaObj);
    }
}

async function aiOku() {
    if (!aiDosyaObj) return;
    var btn = document.getElementById('btnAiOku');
    btn.disabled = true;
    document.getElementById('aiProgress').classList.remove('d-none');
    document.getElementById('aiSonucHT').classList.add('d-none');
    document.getElementById('aiHataHT').classList.add('d-none');

    try {
        var fd = new FormData();
        fd.append('dosya', aiDosyaObj);
        var res  = await fetch('api/ai_okut.php', { method: 'POST', body: fd });
        var json = await res.json();

        if (!json.ok) {
            document.getElementById('aiHataHT').textContent = json.msg || 'AI hatası';
            document.getElementById('aiHataHT').classList.remove('d-none');
            return;
        }

        aiSonucAlanlar = json.alanlar || {};

        var rows = '';
        for (var key in AI_ETIKETLER) {
            var val = aiSonucAlanlar[key];
            var goster = (val !== null && val !== undefined && val !== '') ? escHtml(String(val)) : '<span class="text-muted">—</span>';
            rows += '<tr><td class="text-muted" style="width:45%">' + AI_ETIKETLER[key] + '</td><td class="fw-semibold">' + goster + '</td></tr>';
        }
        document.getElementById('aiSonucTbodyHT').innerHTML = rows;
        document.getElementById('aiSonucHT').classList.remove('d-none');

    } catch(e) {
        document.getElementById('aiHataHT').textContent = 'Bağlantı hatası: ' + e.message;
        document.getElementById('aiHataHT').classList.remove('d-none');
    } finally {
        document.getElementById('aiProgress').classList.add('d-none');
        btn.disabled = false;
    }
}

function aiListeyeEkle() {
    var a = aiSonucAlanlar;

    var irsaliyeNo = a.irsaliye_no || '';
    var tarih      = a.tarih       || '';
    var plaka      = a.arac_plaka  ? String(a.arac_plaka).toUpperCase().replace(/\s+/g,'') : '';
    var saat       = a.mikser_cikis_saati || '';
    var faturaNo   = a.ettn || a.fatura_no || '';
    var miktar     = a.miktar != null ? String(a.miktar) : '';

    // Duplicate kontrol
    if (irsaliyeNo && taranmisList.some(function(r){ return r.irsaliye_no === irsaliyeNo; })) {
        setDurum('<i class="bi bi-exclamation-circle text-warning me-1"></i> Bu irsaliye zaten listede: ' + irsaliyeNo);
        beepSes(true); return;
    }

    // Tedarikçi VKN eşleştir
    var tedarikciId = '';
    if (a.tedarikci_vkn) {
        var vkn = String(a.tedarikci_vkn).trim();
        for (var ti = 0; ti < TEDARIKCILER.length; ti++) {
            if (String(TEDARIKCILER[ti].vkn || '').trim() === vkn) {
                tedarikciId = String(TEDARIKCILER[ti].id); break;
            }
        }
    }

    // Beton sınıfı eşleştir
    var betonSinifiId = '';
    if (a.beton_sinifi) {
        var bn = String(a.beton_sinifi).toUpperCase().replace(/\s+/g,'');
        for (var bi = 0; bi < BETON_SINIFLARI.length; bi++) {
            var bc = BETON_SINIFLARI[bi].ad.replace(/\s+/g,'').toUpperCase();
            if (bc === bn || bc.startsWith(bn+'/') || bc.startsWith(bn+'-')) {
                betonSinifiId = String(BETON_SINIFLARI[bi].id); break;
            }
        }
    }

    // Kıvam sınıfı eşleştir
    var kivamSinifiId = '';
    if (a.kivam) {
        var kn = String(a.kivam).toUpperCase().replace(/\s+/g,'');
        for (var ki = 0; ki < KIVAM_SINIFLARI.length; ki++) {
            if (KIVAM_SINIFLARI[ki].ad.toUpperCase() === kn) {
                kivamSinifiId = String(KIVAM_SINIFLARI[ki].id); break;
            }
        }
    }

    rowSayac++;
    var item = {
        rowId: rowSayac, irsaliye_no: irsaliyeNo, tarih: tarih,
        arac_plaka: plaka, mikser_cikis_saati: saat, fatura_no: faturaNo,
        miktar: miktar, tedarikci_id: tedarikciId, beton_sinifi_id: betonSinifiId,
        kivam_sinifi_id: kivamSinifiId, kantar_net_yildizlar: '',
        kantar_net_tedarikci: '', proje_id: '', qrKullanildi: false,
        scanImageUrl: aiDosyaUrl || '', docUrl: aiDosyaUrl || ''
    };
    taranmisList.push(item);
    tabloSatirEkle(item);
    sayacGuncelle();
    beepSes(false);

    document.getElementById('aiSonucHT').classList.add('d-none');
    document.getElementById('aiDosya').value = '';
    aiDosyaObj = null;
    aiSonucAlanlar = {};
    document.getElementById('btnAiOku').classList.add('d-none');
    setDurum('<i class="bi bi-check-circle-fill text-success me-1"></i> AI ile okunan irsaliye eklendi: ' + (irsaliyeNo || 'yeni kayıt'));
}


// ── Birleşik Dosya/Belge Okuma ──────────────────────────────────
var belgeDosyaObj = null;

function dosyaSecildi(input) {
    belgeDosyaObj = input.files[0] || null;
    dosyaBilgiGuncelle();
}

function dosyaBilgiGuncelle() {
    var bilgi   = document.getElementById('dosyaBilgi');
    var okuArea = document.getElementById('belgeOkuArea');
    document.getElementById('okuSonucEl').classList.add('d-none');
    if (belgeDosyaObj) {
        var isPdf = /\.pdf$/i.test(belgeDosyaObj.name) || belgeDosyaObj.type === 'application/pdf';
        document.getElementById('dosyaIcon').className =
            'bi bi-file-earmark-' + (isPdf ? 'pdf text-danger' : 'image text-primary') + ' fs-5';
        document.getElementById('dosyaAdi').textContent  = belgeDosyaObj.name;
        document.getElementById('dosyaBoyut').textContent =
            (belgeDosyaObj.size / 1024 / 1024).toFixed(1) + ' MB';
        bilgi.classList.remove('d-none');
        okuArea.style.display = '';
    } else {
        bilgi.classList.add('d-none');
        okuArea.style.display = 'none';
    }
}

function dosyaTemizle() {
    belgeDosyaObj = null;
    document.getElementById('belgeDosya').value = '';
    dosyaBilgiGuncelle();
}

function kameraToggle() {
    var alan = document.getElementById('kameraAlani');
    var btn  = document.getElementById('btnKameraToggle');
    if (alan.classList.contains('d-none')) {
        alan.classList.remove('d-none');
        btn.innerHTML = '<i class="bi bi-camera-fill me-1"></i> Kamerayi Kapat';
        btn.classList.replace('btn-outline-secondary','btn-secondary');
        kameralariListele();
    } else {
        alan.classList.add('d-none');
        kameraKapat();
        btn.innerHTML = '<i class="bi bi-camera me-1"></i> Kamera ile Cek';
        btn.classList.replace('btn-secondary','btn-outline-secondary');
    }
}

function dropZoneOver(e)  { e.preventDefault(); document.getElementById('dropZone').classList.add('dragging'); }
function dropZoneLeave(e) { document.getElementById('dropZone').classList.remove('dragging'); }
function dropZoneDrop(e)  {
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('dragging');
    var f = e.dataTransfer.files[0];
    if (f) { belgeDosyaObj = f; dosyaBilgiGuncelle(); }
}

// ── Yontem durum badge'leri ───────────────────────────────────────
function _durSet(id, icon, cls, text) {
    var el = document.getElementById(id);
    if (!el) return;
    el.className = 'yontem-badge ' + cls;
    el.innerHTML = '<i class="bi bi-' + icon + '"></i> ' + text;
}
function _durBekle(id, t) { _durSet(id,'hourglass-split','bg-primary-subtle text-primary border border-primary-subtle',t); }
function _durOk(id, t)    { _durSet(id,'check-circle-fill','bg-success-subtle text-success border border-success-subtle',t); }
function _durAtla(id, t)  { _durSet(id,'dash','bg-light text-muted border',t); }
function _durHata(id, t)  { _durSet(id,'x-circle','bg-danger-subtle text-danger border border-danger-subtle',t); }

// ── Ana Dispatcher ───────────────────────────────────────────────────────────
async function belgeyiOku() {
    if (!belgeDosyaObj) return;
    var isPdf = /\.pdf$/i.test(belgeDosyaObj.name) || belgeDosyaObj.type === 'application/pdf';
    if (isPdf) { await belgeyiOkuPdf(); } else { await belgeyiOkuGorsel(); }
}

// ── PDF: QR + OCR sayfa sayfa ─────────────────────────────────────────
async function belgeyiOkuPdf() {
    var btn  = document.getElementById('btnBelgeOku');
    var pEl  = document.getElementById('belgeProgress');
    var pBar = document.getElementById('belgeProgressBar');
    var pTxt = document.getElementById('belgeProgressText');
    var pPct = document.getElementById('belgeProgressPct');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Okunuyor...';
    pEl.classList.remove('d-none');
    document.getElementById('okuSonucEl').classList.add('d-none');
    pBar.style.width = '2%'; pPct.textContent = '';

    _durBekle('durQr','QR Kodu'); _durBekle('durOcr','OCR'); _durBekle('durAi','AI Okuma');

    // PDF'i sunucuya paralel yukle
    var pdfUploadP = (function(){
        var fd = new FormData(); fd.append('dosya', belgeDosyaObj);
        return fetch('api/pdf_kaydet.php',{method:'POST',body:fd})
            .then(function(r){return r.json();}).then(function(d){return d.ok?d.url:'';}).catch(function(){return '';});
    })();

    // AI'yi paralel basalt
    var aiP = (function(){
        var fd = new FormData(); fd.append('dosya', belgeDosyaObj);
        return fetch('api/ai_okut.php',{method:'POST',body:fd})
            .then(function(r){return r.json();}).catch(function(){return{ok:false};});
    })();

    var bulunan = 0, atlanan = 0, hatalar = [], sayfaItemler = [], worker = null;

    try {
        if (typeof pdfjsLib === 'undefined') throw new Error('PDF.js yuklenemedi, sayfayi yenileyin.');
        var buf  = await belgeDosyaObj.arrayBuffer();
        var pdf  = await pdfjsLib.getDocument({data:buf}).promise;
        var topS = pdf.numPages;

        pTxt.textContent = 'PDF analiz ediliyor (' + topS + ' sayfa)...';
        var ilkS    = await pdf.getPage(1);
        var textKat = await sayfadanTextAl(ilkS);
        var ocrGerekli = !textKat;

        if (ocrGerekli) {
            pTxt.textContent = 'OCR motoru baslatiliyor (taranmis PDF)...';
            pBar.style.width = '3%';
            worker = await Tesseract.createWorker('tur', 1, {
                workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
                langPath:   'https://tessdata.projectnaptha.com/4.0.0',
                logger: function(m){
                    if(m.status==='loading tesseract core'||m.status==='loading language traineddata')
                        pTxt.textContent='Dil paketi indiriliyor...';
                }
            });
        } else {
            pTxt.textContent = 'Dijital PDF — metin katmani kullaniliyor';
        }

        var ocrC = document.createElement('canvas');
        var ocrCtx = ocrC.getContext('2d');

        for (var i = 1; i <= topS; i++) {
            var pct = Math.round(((i-1)/topS)*90);
            pBar.style.width = Math.max(pct,4)+'%'; pPct.textContent = pct+'%';
            pTxt.textContent = topS+' sayfadan '+i+'. sayfa isleniyor...';

            try {
                var page = (i===1) ? ilkS : await pdf.getPage(i);
                var vp   = page.getViewport({scale: ocrGerekli?2.0:3.0});
                ocrC.width=vp.width; ocrC.height=vp.height;
                await page.render({canvasContext:ocrCtx,viewport:vp}).promise;

                var scanP = sayfaGorselKaydet(ocrC);

                var qrJson=null, qrE1=null;
                try {
                    var ql = await sayfadakiTumQrlar(page, ocrC, ocrCtx);
                    var qr = qrListesindenVeriAl(ql);
                    if (qr.length>0){qrJson=qr[0].json; qrE1=qr[0].e1;}
                } catch(e){}

                var text='';
                if (!ocrGerekli) {
                    text = (await sayfadanTextAl(page)) || '';
                } else {
                    pTxt.textContent = topS+' sayfadan '+i+'. OCR yapiliyor...';
                    var kc=document.createElement('canvas');
                    kc.width=ocrC.width; kc.height=ocrC.height;
                    var kctx=kc.getContext('2d');
                    kctx.filter='grayscale(100%) contrast(200%) brightness(1.05)';
                    kctx.drawImage(ocrC,0,0);
                    var r1=await worker.recognize(kc); text=r1.data.text||'';
                    if(text.replace(/\s/g,'').length<30){
                        kctx.filter='grayscale(100%) contrast(250%) brightness(0.95)';
                        kctx.drawImage(ocrC,0,0);
                        var r2=await worker.recognize(kc);
                        if((r2.data.text||'').length>text.length) text=r2.data.text;
                    }
                }

                var scanUrl = await scanP;
                var parsed  = parseIrsaliyeMetin(text);
                qrVerileriniUygula(parsed, qrJson, qrE1);

                var qrVarMi = !!(qrJson||qrE1);
                if (!qrVarMi && (!text||text.replace(/\s/g,'').length<10)){
                    hatalar.push('Sayfa '+i+': QR ve OCR metni okunamadi'); continue;
                }
                if (parsed.irsaliye_no && taranmisList.some(function(r){return r.irsaliye_no===parsed.irsaliye_no;})){
                    atlanan++; hatalar.push('Sayfa '+i+': '+parsed.irsaliye_no+' zaten listede (atlandi)'); continue;
                }

                beepSes(); rowSayac++;
                var item = {
                    rowId:rowSayac, irsaliye_no:parsed.irsaliye_no, tarih:parsed.tarih,
                    arac_plaka:parsed.plaka, mikser_cikis_saati:parsed.sevkZamani,
                    fatura_no:parsed.ettn, miktar:parsed.miktar,
                    tedarikci_id:parsed.tedarikci_id, beton_sinifi_id:parsed.beton_sinifi_id,
                    kivam_sinifi_id:parsed.kivam_sinifi_id||'',
                    kantar_net_yildizlar:parsed.kantar_net_yildizlar||'',
                    kantar_net_tedarikci:parsed.kantar_net_tedarikci||'',
                    proje_id:'', qrKullanildi:qrVarMi, qrVkn:parsed.qrVkn||'',
                    scanImageUrl:scanUrl||'', docUrl:''
                };
                taranmisList.push(item); sayfaItemler.push(item);
                tabloSatirEkle(item); sayacGuncelle(); bulunan++;

            } catch(pe){ hatalar.push('Sayfa '+i+' okunamadi: '+pe.message); }
        }

        if (worker){ try{await worker.terminate();}catch(e){} worker=null; }
        _durOk('durQr','QR Kodu'); _durOk('durOcr','OCR');

        var pdfUrl = await pdfUploadP;
        if (pdfUrl) sayfaItemler.forEach(function(it){it.docUrl=pdfUrl;});

        try {
            var aiData = await aiP;
            if (aiData && aiData.ok){ _durOk('durAi','AI Okuma'); }
            else { _durAtla('durAi','AI Okuma'); }
        } catch(e){ _durAtla('durAi','AI Okuma'); }

        pBar.style.width='100%'; pPct.textContent='100%';

        var at  = bulunan>0 ? 'success' : 'warning';
        var html= '<div class="alert alert-'+at+' mb-0">';
        html   += '<i class="bi bi-'+(bulunan>0?'check-circle':'exclamation-circle')+' me-1"></i>';
        html   += '<strong>'+topS+' sayfa islendi.</strong> '+bulunan+' irsaliye eklendi';
        if (atlanan) html += ', '+atlanan+' atlandi';
        html += '.';
        if (hatalar.length) html += '<ul class="mb-0 mt-2 small">'+hatalar.map(function(e){return'<li>'+escHtml(e)+'</li>';}).join('')+'</ul>';
        html += '</div>';
        document.getElementById('okuSonucEl').innerHTML = html;
        document.getElementById('okuSonucEl').classList.remove('d-none');
        if (bulunan>0) document.getElementById('kayitlarCard').scrollIntoView({behavior:'smooth'});

    } catch(err) {
        if (worker) try{await worker.terminate();}catch(e){}
        document.getElementById('okuSonucEl').innerHTML =
            '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Hata: '+escHtml(err.message)+'</div>';
        document.getElementById('okuSonucEl').classList.remove('d-none');
        _durHata('durQr','QR Kodu'); _durHata('durOcr','OCR');
    } finally {
        btn.disabled=false; btn.innerHTML='<i class="bi bi-search me-2"></i> Belge Oku';
        pEl.classList.add('d-none'); dosyaTemizle();
    }
}

// ── Gorsel: QR + AI paralel ────────────────────────────────────────────
async function belgeyiOkuGorsel() {
    var btn  = document.getElementById('btnBelgeOku');
    var pEl  = document.getElementById('belgeProgress');
    var pBar = document.getElementById('belgeProgressBar');
    var pTxt = document.getElementById('belgeProgressText');

    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Okunuyor...';
    pEl.classList.remove('d-none');
    document.getElementById('okuSonucEl').classList.add('d-none');
    pBar.style.width='5%';

    _durBekle('durQr','QR Kodu'); _durAtla('durOcr','OCR'); _durBekle('durAi','AI Okuma');

    try {
        var dataUrl = await new Promise(function(resolve,reject){
            var rd=new FileReader(); rd.onload=function(e){resolve(e.target.result);}; rd.onerror=reject;
            rd.readAsDataURL(belgeDosyaObj);
        });

        var canvas=document.createElement('canvas'), ctx=canvas.getContext('2d');
        var img = await new Promise(function(resolve,reject){
            var el=new Image(); el.onload=function(){resolve(el);}; el.onerror=reject; el.src=dataUrl;
        });
        canvas.width=img.width; canvas.height=img.height; ctx.drawImage(img,0,0);

        pBar.style.width='25%'; pTxt.textContent='QR kodu araniyor...';

        var qrList  = await tumKodlariBul(canvas, ctx);
        var sonuclar = kodlariIsle(qrList);
        _durOk('durQr','QR Kodu');

        pBar.style.width='45%'; pTxt.textContent='AI ile analiz ediliyor...';

        var aiAlanlar = {};
        try {
            var fdAi = new FormData(); fdAi.append('dosya', belgeDosyaObj);
            var aiResp = await fetch('api/ai_okut.php',{method:'POST',body:fdAi});
            var aiData = await aiResp.json();
            if (aiData.ok){ aiAlanlar=aiData.alanlar||{}; _durOk('durAi','AI Okuma'); }
            else { _durHata('durAi','AI Okuma'); }
        } catch(e){ _durHata('durAi','AI Okuma'); }

        pBar.style.width='75%'; pTxt.textContent='Gorsel yukleniyor...';

        var gorselUrl='';
        try {
            var fdUp=new FormData(); fdUp.append('image',dataUrl);
            var upR=await fetch('api/scan_kaydet.php',{method:'POST',body:fdUp});
            var upD=await upR.json(); if(upD.ok) gorselUrl=upD.url;
        } catch(e){}

        var qrJson=sonuclar.json, qrE1=sonuclar.e1;
        var vkn=(qrJson&&qrJson.vkntckn)||(qrE1&&qrE1.vkn)
                ||(aiAlanlar.tedarikci_vkn?String(aiAlanlar.tedarikci_vkn):'')||'';

        var irsaliyeNo = (qrJson&&qrJson.no)||aiAlanlar.irsaliye_no||(qrE1&&qrE1.irsaliye_no)||'';
        var tarih      = (qrJson&&qrJson.tarih)||aiAlanlar.tarih||(qrE1&&qrE1.tarih)||'';
        var plaka      = (qrJson&&qrJson.plaka) ? String(qrJson.plaka).replace(/\s+/g,'').toUpperCase()
                         : (aiAlanlar.arac_plaka ? String(aiAlanlar.arac_plaka).toUpperCase().replace(/\s+/g,'')
                            : ((qrE1&&qrE1.plaka)||''));
        var saat       = (qrJson&&qrJson.sevkzamani) ? String(qrJson.sevkzamani).substring(0,5)
                         : (aiAlanlar.mikser_cikis_saati||(qrE1&&qrE1.sevkZamani)||'');
        var ettn       = (qrJson&&qrJson.ettn)||aiAlanlar.ettn||aiAlanlar.fatura_no||'';
        var miktar     = (qrE1&&qrE1.miktar)||(aiAlanlar.miktar!=null?String(aiAlanlar.miktar):'')||'';

        var tedarikciId='';
        if (vkn) {
            for(var ti=0;ti<TEDARIKCILER.length;ti++){
                if(String(TEDARIKCILER[ti].vkn||'').trim()===String(vkn).trim()){
                    tedarikciId=String(TEDARIKCILER[ti].id); break;
                }
            }
        }
        var betonSinifiId='', betonAdi=(qrE1&&qrE1.beton_sinifi)||aiAlanlar.beton_sinifi||'';
        if (betonAdi){
            var bn=String(betonAdi).toUpperCase().replace(/\s+/g,'');
            for(var bi=0;bi<BETON_SINIFLARI.length;bi++){
                var bc=BETON_SINIFLARI[bi].ad.replace(/\s+/g,'').toUpperCase();
                if(bc===bn||bc.startsWith(bn+'/')||bc.startsWith(bn+'-')){betonSinifiId=String(BETON_SINIFLARI[bi].id);break;}
            }
        }
        var kivamSinifiId='', kivamAdi=(qrE1&&qrE1.kivam)||aiAlanlar.kivam||'';
        if (kivamAdi){
            var kn=String(kivamAdi).toUpperCase().replace(/\s+/g,'');
            for(var ki=0;ki<KIVAM_SINIFLARI.length;ki++){
                if(KIVAM_SINIFLARI[ki].ad.toUpperCase()===kn){kivamSinifiId=String(KIVAM_SINIFLARI[ki].id);break;}
            }
        }

        if (!irsaliyeNo && !qrE1 && Object.keys(aiAlanlar).length===0){
            document.getElementById('okuSonucEl').innerHTML=
                '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-circle me-1"></i>'+
                'QR kodu veya AI ile veri okunamadi. Goruntu kalitesini kontrol edin.</div>';
            document.getElementById('okuSonucEl').classList.remove('d-none');
            return;
        }
        if (irsaliyeNo && taranmisList.some(function(r){return r.irsaliye_no===irsaliyeNo;})){
            document.getElementById('okuSonucEl').innerHTML=
                '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-circle me-1"></i>'+
                'Bu irsaliye zaten listede: '+escHtml(irsaliyeNo)+'</div>';
            document.getElementById('okuSonucEl').classList.remove('d-none');
            return;
        }

        pBar.style.width='100%';
        rowSayac++;
        var item={
            rowId:rowSayac, irsaliye_no:irsaliyeNo, tarih:tarih,
            arac_plaka:plaka, mikser_cikis_saati:saat, fatura_no:ettn,
            miktar:miktar, tedarikci_id:tedarikciId, beton_sinifi_id:betonSinifiId,
            kivam_sinifi_id:kivamSinifiId, kantar_net_yildizlar:'',
            kantar_net_tedarikci:'', proje_id:'',
            qrKullanildi:!!(qrJson||qrE1), qrVkn:vkn||'',
            scanImageUrl:gorselUrl, docUrl:gorselUrl
        };
        taranmisList.push(item); tabloSatirEkle(item); sayacGuncelle(); beepSes(false);

        document.getElementById('okuSonucEl').innerHTML=
            '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i>'+
            'Irsaliye okundu: <strong>'+escHtml(irsaliyeNo||'Yeni kayit')+'</strong></div>';
        document.getElementById('okuSonucEl').classList.remove('d-none');
        document.getElementById('kayitlarCard').scrollIntoView({behavior:'smooth'});

    } catch(err){
        document.getElementById('okuSonucEl').innerHTML=
            '<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Hata: '+escHtml(err.message)+'</div>';
        document.getElementById('okuSonucEl').classList.remove('d-none');
        _durHata('durQr','QR Kodu'); _durHata('durAi','AI Okuma');
    } finally {
        btn.disabled=false; btn.innerHTML='<i class="bi bi-search me-2"></i> Belge Oku';
        pEl.classList.add('d-none'); dosyaTemizle();
    }
}

// ── Çakışma Yönetimi ─────────────────────────────────────────────────
var cakismaKuyruk  = [];
var cakismaIdx     = 0;
var cakismaModalObj = null;

function cakismalariGoster(cakismalar) {
    cakismaKuyruk = cakismalar;
    cakismaIdx    = 0;
    if (!cakismaModalObj) cakismaModalObj = new bootstrap.Modal(document.getElementById('cakismaModal'));
    cakismaModalDoldur();
    cakismaModalObj.show();
}

function cakismaModalDoldur() {
    if (cakismaIdx >= cakismaKuyruk.length) { cakismaModalObj.hide(); return; }

    var c      = cakismaKuyruk[cakismaIdx];
    var mv     = c.mevcut;  // DB kaydı (tedarikci_ad, beton_sinifi_ad vb. dahil)
    var yn     = c.yeni;    // taranan veri (ID'ler)

    document.getElementById('cakismaSayac').textContent = (cakismaIdx + 1) + ' / ' + cakismaKuyruk.length;

    function adBul(arr, id) {
        var x = arr.find(function(a){ return String(a.id) === String(id); });
        return x ? (x.ad || x.kod || String(id)) : (id ? String(id) : '—');
    }
    function prjAdBul(id) {
        var x = PROJELER.find(function(a){ return String(a.id) === String(id); });
        return x ? (x.kod + (x.aciklama ? ' — ' + x.aciklama : '')) : (id ? String(id) : '—');
    }
    function selOpts(arr, selId, emptyLabel) {
        var html = '<option value="">' + escHtml(emptyLabel || '— Seçin —') + '</option>';
        arr.forEach(function(a) {
            var lbl = a.ad || (a.kod + (a.aciklama ? ' — ' + a.aciklama : ''));
            html += '<option value="' + a.id + '"' + (String(a.id) === String(selId) ? ' selected' : '') + '>' + escHtml(lbl) + '</option>';
        });
        return html;
    }

    // yeni değer yoksa mevcut değeri varsayılan al
    function yeniOrMevcut(yeniVal, mevcutVal) { return (yeniVal !== undefined && yeniVal !== null && yeniVal !== '') ? yeniVal : mevcutVal; }

    var fields = [
        { label:'Tarih',              id:'cak_tarih',              type:'date',   mevcutDisp: mv.tarih||'—',             yeniDisp: yn.tarih||'—',                       defaultVal: yeniOrMevcut(yn.tarih, mv.tarih||''),               input: function(v){ return '<input type="date" class="form-control form-control-sm" id="cak_tarih" value="'+escHtml(v)+'">'; }, mevcutCmp: mv.tarih||'', yeniCmp: yn.tarih||'' },
        { label:'Araç Plaka',         id:'cak_arac_plaka',         type:'text',   mevcutDisp: mv.arac_plaka||'—',        yeniDisp: (yn.arac_plaka||'—').toUpperCase(),   defaultVal: yeniOrMevcut(yn.arac_plaka, mv.arac_plaka||'').toUpperCase(), input: function(v){ return '<input type="text" class="form-control form-control-sm text-uppercase" id="cak_arac_plaka" value="'+escHtml(v)+'">'; }, mevcutCmp: (mv.arac_plaka||'').toUpperCase(), yeniCmp: (yn.arac_plaka||'').toUpperCase() },
        { label:'Çıkış Saati',        id:'cak_mikser_cikis_saati', type:'time',   mevcutDisp: mv.mikser_cikis_saati||'—',yeniDisp: yn.mikser_cikis_saati||'—',           defaultVal: yeniOrMevcut(yn.mikser_cikis_saati, mv.mikser_cikis_saati||''), input: function(v){ return '<input type="time" class="form-control form-control-sm" id="cak_mikser_cikis_saati" value="'+escHtml(v)+'">'; }, mevcutCmp: mv.mikser_cikis_saati||'', yeniCmp: yn.mikser_cikis_saati||'' },
        { label:'Fatura No / ETTN',   id:'cak_fatura_no',          type:'text',   mevcutDisp: mv.fatura_no||'—',         yeniDisp: yn.fatura_no||'—',                    defaultVal: yeniOrMevcut(yn.fatura_no, mv.fatura_no||''),               input: function(v){ return '<input type="text" class="form-control form-control-sm" id="cak_fatura_no" value="'+escHtml(v)+'">'; }, mevcutCmp: mv.fatura_no||'', yeniCmp: yn.fatura_no||'' },
        { label:'Miktar (m³)',        id:'cak_miktar',             type:'number', mevcutDisp: mv.miktar||'—',            yeniDisp: yn.miktar||'—',                       defaultVal: yeniOrMevcut(yn.miktar, mv.miktar||''),                    input: function(v){ return '<input type="number" step="0.01" min="0" class="form-control form-control-sm" id="cak_miktar" value="'+escHtml(v)+'">'; }, mevcutCmp: String(mv.miktar||''), yeniCmp: String(yn.miktar||'') },
        { label:'Tedarikçi',          id:'cak_tedarikci_id',       type:'select', mevcutDisp: mv.tedarikci_ad||'—',      yeniDisp: adBul(TEDARIKCILER, yn.tedarikci_id), defaultVal: yeniOrMevcut(yn.tedarikci_id, mv.tedarikci_id||''),        input: function(v){ return '<select class="form-select form-select-sm" id="cak_tedarikci_id">'+selOpts(TEDARIKCILER, v)+'</select>'; }, mevcutCmp: String(mv.tedarikci_id||''), yeniCmp: String(yn.tedarikci_id||'') },
        { label:'Beton Sınıfı',       id:'cak_beton_sinifi_id',    type:'select', mevcutDisp: mv.beton_sinifi_ad||'—',   yeniDisp: adBul(BETON_SINIFLARI, yn.beton_sinifi_id), defaultVal: yeniOrMevcut(yn.beton_sinifi_id, mv.beton_sinifi_id||''), input: function(v){ return '<select class="form-select form-select-sm" id="cak_beton_sinifi_id">'+selOpts(BETON_SINIFLARI, v)+'</select>'; }, mevcutCmp: String(mv.beton_sinifi_id||''), yeniCmp: String(yn.beton_sinifi_id||'') },
        { label:'Kıvam',              id:'cak_kivam_sinifi_id',    type:'select', mevcutDisp: mv.kivam_ad||'—',          yeniDisp: adBul(KIVAM_SINIFLARI, yn.kivam_sinifi_id), defaultVal: yeniOrMevcut(yn.kivam_sinifi_id, mv.kivam_sinifi_id||''), input: function(v){ return '<select class="form-select form-select-sm" id="cak_kivam_sinifi_id">'+selOpts(KIVAM_SINIFLARI, v)+'</select>'; }, mevcutCmp: String(mv.kivam_sinifi_id||''), yeniCmp: String(yn.kivam_sinifi_id||'') },
        { label:'Proje',              id:'cak_proje_id',           type:'select', mevcutDisp: mv.proje_kod||'—',         yeniDisp: prjAdBul(yn.proje_id),                defaultVal: yeniOrMevcut(yn.proje_id, mv.proje_id||''),                input: function(v){ return '<select class="form-select form-select-sm" id="cak_proje_id">'+selOpts(PROJELER, v, '— Seçin —')+'</select>'; }, mevcutCmp: String(mv.proje_id||''), yeniCmp: String(yn.proje_id||'') },
    ];

    var html = '<div class="d-flex align-items-center gap-2 mb-3">';
    html += '<i class="bi bi-file-earmark-text text-warning fs-5"></i>';
    html += '<span class="fw-semibold">İrsaliye No: <code>' + escHtml(mv.irsaliye_no||'?') + '</code></span>';
    html += '<span class="badge bg-warning text-dark">Veritabanında Kayıtlı</span>';
    html += '</div>';
    html += '<div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-1">';
    html += '<thead class="table-light small"><tr><th>Alan</th><th class="text-muted">Kayıtlı (DB)</th><th>Yeni Taranan</th><th>Kullanılacak Değer</th></tr></thead><tbody>';

    fields.forEach(function(f) {
        var differ = f.mevcutCmp !== f.yeniCmp && f.yeniCmp !== '';
        html += '<tr' + (differ ? ' class="table-warning"' : '') + '>';
        html += '<td class="fw-semibold small text-nowrap">' + escHtml(f.label);
        if (differ) html += ' <i class="bi bi-arrow-left-right text-warning small"></i>';
        html += '</td>';
        html += '<td class="small text-muted">' + escHtml(String(f.mevcutDisp)) + '</td>';
        html += '<td class="small">' + escHtml(String(f.yeniDisp)) + '</td>';
        html += '<td>' + f.input(f.defaultVal) + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    html += '<input type="hidden" id="cak_id" value="' + escHtml(String(mv.id||'')) + '">';

    document.getElementById('cakismaBody').innerHTML = html;
    var btn = document.getElementById('btnCakismaGuncelle');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-pencil-square me-1"></i> Güncelle &amp; Devam';
}

function cakismaAtla() {
    cakismaIdx++;
    cakismaModalDoldur();
}

async function cakismaGuncelle() {
    var id  = document.getElementById('cak_id').value;
    var btn = document.getElementById('btnCakismaGuncelle');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Kaydediliyor...';

    var cakYeni  = (cakismaKuyruk[cakismaIdx] || {}).yeni || {};
    var alanlar = {
        tarih:               document.getElementById('cak_tarih').value,
        arac_plaka:          document.getElementById('cak_arac_plaka').value,
        mikser_cikis_saati:  document.getElementById('cak_mikser_cikis_saati').value,
        fatura_no:           document.getElementById('cak_fatura_no').value,
        miktar:              document.getElementById('cak_miktar').value,
        tedarikci_id:        document.getElementById('cak_tedarikci_id').value,
        beton_sinifi_id:     document.getElementById('cak_beton_sinifi_id').value,
        kivam_sinifi_id:     document.getElementById('cak_kivam_sinifi_id').value,
        proje_id:            document.getElementById('cak_proje_id').value,
        docUrl:              cakYeni.docUrl        || '',
        scanImageUrl:        cakYeni.scanImageUrl  || '',
    };

    try {
        var resp = await fetch('api/hizli_guncelle.php', {
            method:  'POST',
            headers: {'Content-Type':'application/json'},
            body:    JSON.stringify({id: id, alanlar: alanlar})
        });
        var data = await resp.json();
        if (data.ok) {
            cakismaIdx++;
            cakismaModalDoldur();
        } else {
            alert('Güncelleme hatası: ' + (data.msg || 'Bilinmeyen hata'));
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-pencil-square me-1"></i> Güncelle &amp; Devam';
        }
    } catch(e) {
        alert('Bağlantı hatası: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-pencil-square me-1"></i> Güncelle &amp; Devam';
    }
}
</script>

<!-- Çakışma Çözümleme Modalı -->
<div class="modal fade" id="cakismaModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark py-2">
        <h5 class="modal-title fw-semibold">
          <i class="bi bi-exclamation-triangle me-2"></i> Çakışan İrsaliye
          <span id="cakismaSayac" class="badge bg-dark ms-2"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="cakismaBody"></div>
      <div class="modal-footer">
        <small class="text-muted me-auto">Sarı satırlar mevcut kayıtla farklı alanları gösterir.</small>
        <button class="btn btn-outline-secondary" onclick="cakismaAtla()">
          <i class="bi bi-skip-forward me-1"></i> Atla
        </button>
        <button class="btn btn-warning" id="btnCakismaGuncelle" onclick="cakismaGuncelle()">
          <i class="bi bi-pencil-square me-1"></i> Güncelle &amp; Devam
        </button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
