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
require_once __DIR__ . '/includes/header.php';
?>

<style>
.btn-xs { padding:.2rem .4rem; font-size:.75rem; }
#videoEl { background:#000; }
</style>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';</script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

<!-- Kamera Paneli -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-camera-video text-primary me-1"></i> Kamera &amp; Hızlı Tarama</span>
        <span id="sayacBadge" class="badge bg-success fs-6">0 kayıt tarandı</span>
        <span id="scannerBadge" class="badge bg-secondary d-none ms-auto"></span>
      </div>
      <div class="card-body p-3">

        <!-- Kamera cihazı seçici -->
        <div class="row g-2 mb-3">
          <div class="col-sm-6">
            <select id="kameraSec" class="form-select form-select-sm">
              <option value="">Kamera yükleniyor...</option>
            </select>
          </div>
          <div class="col-sm-6 d-flex gap-2 flex-wrap">
            <button id="btnAc" class="btn btn-success btn-sm flex-fill" onclick="kameraAc()">
              <i class="bi bi-camera-video-fill me-1"></i> Aç &amp; Tara
            </button>
            <button id="btnKapat" class="btn btn-secondary btn-sm d-none" onclick="kameraKapat()">
              <i class="bi bi-stop-circle me-1"></i> Kapat
            </button>
            <button id="btnTorch" class="btn btn-outline-warning btn-sm d-none" onclick="torchToggle()" title="Flaş/Işık">
              <i class="bi bi-lightning-charge"></i>
            </button>
          </div>
        </div>

        <!-- Video -->
        <div class="position-relative bg-black rounded overflow-hidden" style="max-height:350px;">
          <video id="videoEl" autoplay playsinline muted class="w-100 d-block" style="max-height:350px;object-fit:cover;"></video>
          <canvas id="canvasEl" class="d-none"></canvas>
          <!-- Hedef çerçeve -->
          <div id="hedefBox" class="d-none position-absolute" style="top:50%;left:50%;transform:translate(-50%,-50%);width:200px;height:200px;border:2px solid #28a745;border-radius:8px;box-shadow:0 0 0 9999px rgba(0,0,0,.4);pointer-events:none;">
            <span style="position:absolute;top:-2px;left:-2px;width:24px;height:24px;border-top:4px solid #28a745;border-left:4px solid #28a745;border-radius:4px 0 0 0;"></span>
            <span style="position:absolute;top:-2px;right:-2px;width:24px;height:24px;border-top:4px solid #28a745;border-right:4px solid #28a745;border-radius:0 4px 0 0;"></span>
            <span style="position:absolute;bottom:-2px;left:-2px;width:24px;height:24px;border-bottom:4px solid #28a745;border-left:4px solid #28a745;border-radius:0 0 0 4px;"></span>
            <span style="position:absolute;bottom:-2px;right:-2px;width:24px;height:24px;border-bottom:4px solid #28a745;border-right:4px solid #28a745;border-radius:0 0 4px 0;"></span>
          </div>
          <!-- Flash animasyon overlay (yeşil flash on scan) -->
          <div id="flashEl" class="position-absolute top-0 start-0 w-100 h-100 d-none" style="background:rgba(40,167,69,.35);pointer-events:none;"></div>
        </div>

        <!-- Durum -->
        <div id="durumEl" class="mt-2 text-center small text-muted">Kamerayı açmak için butona tıklayın.</div>

        <!-- Bekleme göstergesi (cooldown) -->
        <div id="cooldownBar" class="progress mt-2 d-none" style="height:4px;">
          <div id="cooldownFill" class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width:100%"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PDF İşleme Paneli -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header p-0">
        <ul class="nav nav-tabs border-bottom-0 px-3 pt-2">
          <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPdfQr">
              <i class="bi bi-qr-code text-danger me-1"></i> QR Kodu Tara
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPdfOcr">
              <i class="bi bi-file-text text-primary me-1"></i> OCR ile Oku
            </button>
          </li>
        </ul>
      </div>
      <div class="card-body">
        <div class="tab-content">

          <!-- QR Tab -->
          <div class="tab-pane fade show active" id="tabPdfQr">
            <div class="row g-3 align-items-end">
              <div class="col-sm-8">
                <label class="form-label fw-semibold small">PDF Dosyası Seç</label>
                <input type="file" id="pdfDosya" class="form-control" accept=".pdf" onchange="pdfSecildi(this)">
                <div class="form-text">Her sayfadaki QR kodlar otomatik taranır ve tabloya eklenir.</div>
              </div>
              <div class="col-sm-4">
                <button id="btnPdfTara" class="btn btn-danger w-100 d-none" onclick="pdfTara()">
                  <i class="bi bi-qr-code-scan me-1"></i> QR Kodları Tara
                </button>
              </div>
            </div>
            <div id="pdfProgress" class="d-none mt-3">
              <div class="d-flex justify-content-between small mb-1">
                <span id="pdfProgressText">Sayfa taranıyor...</span>
                <span id="pdfProgressPct">0%</span>
              </div>
              <div class="progress" style="height:6px;">
                <div id="pdfProgressBar" class="progress-bar bg-danger progress-bar-striped progress-bar-animated" style="width:0%"></div>
              </div>
            </div>
            <div id="pdfSonuc" class="d-none mt-3"></div>
            <canvas id="pdfCanvas" class="d-none"></canvas>
          </div>

          <!-- OCR Tab -->
          <div class="tab-pane fade" id="tabPdfOcr">
            <div class="alert alert-info py-2 small mb-3">
              <i class="bi bi-info-circle me-1"></i>
              PDF içindeki metin okunur; <strong>İrsaliye No, Tarih, Plaka, Miktar, Beton Sınıfı ve Tedarikçi</strong> otomatik çıkartılır. Her sayfa = 1 irsaliye kaydı.
              Dijital (taranmış değil) PDF dosyalarında çalışır.
            </div>
            <div class="row g-3 align-items-end">
              <div class="col-sm-8">
                <label class="form-label fw-semibold small">PDF Dosyası Seç</label>
                <input type="file" id="ocrDosya" class="form-control" accept=".pdf" onchange="ocrSecildi(this)">
                <div class="form-text">Çok sayfalı PDF desteklenir — her sayfa ayrı irsaliye satırı olarak eklenir.</div>
              </div>
              <div class="col-sm-4">
                <button id="btnOcrTara" class="btn btn-primary w-100 d-none" onclick="ocrTara()">
                  <i class="bi bi-file-text me-1"></i> Metni Oku &amp; Ekle
                </button>
              </div>
            </div>
            <div id="ocrProgress" class="d-none mt-3">
              <div class="d-flex justify-content-between small mb-1">
                <span id="ocrProgressText">Sayfa okunuyor...</span>
                <span id="ocrProgressPct">0%</span>
              </div>
              <div class="progress" style="height:6px;">
                <div id="ocrProgressBar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width:0%"></div>
              </div>
            </div>
            <div id="ocrSonuc" class="d-none mt-3"></div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<!-- Taranan Kayıtlar Tablosu -->
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
            <th>İrsaliye No</th>
            <th>Tarih</th>
            <th>Plaka</th>
            <th>Saat</th>
            <th style="min-width:130px">Tedarikçi <span class="text-danger">*</span></th>
            <th style="min-width:100px">Beton</th>
            <th style="width:90px">Miktar m³</th>
            <th style="min-width:120px">Proje</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody id="kayitGovde">
          <tr id="bosRow">
            <td colspan="10" class="text-center text-muted py-5">
              <i class="bi bi-qr-code display-4 opacity-25 d-block mb-2"></i>
              QR kodu okutun — kayıtlar buraya eklenecek
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

// ── Tarayıcı seçimi: Native BarcodeDetector > jsQR fallback ────────
var nativeDetector = null;
(function() {
    if ('BarcodeDetector' in window) {
        try { nativeDetector = new BarcodeDetector({ formats: ['qr_code'] }); } catch(e) {}
    }
})();

// ── Durum değişkenleri ──────────────────────────────────────────────
var stream        = null;
var taramaActive  = false;  // loop flag (BarcodeDetector için)
var taramaTimer   = null;   // setInterval handle (jsQR fallback)
var torchAktif    = false;
var cooldownAktif = false;
var scanCanvas    = document.createElement('canvas');
var SCAN_W = 320, SCAN_H = 240; // küçük canvas → jsQR ~4x hızlı

var taranmisList = [];
var rowSayac     = 0;

// ── Ses (beep) ──────────────────────────────────────────────────────
function beepSes() {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.type = 'square';
        osc.frequency.value = 1400;
        gain.gain.setValueAtTime(0.25, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.12);
    } catch(e) {}
}

// ── Kamera cihazı listeleme ─────────────────────────────────────────
async function kameralariListele() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    // Önce izin iste (enumerateDevices boş label döner izinsiz)
    try { var tmp = await navigator.mediaDevices.getUserMedia({video:true}); tmp.getTracks().forEach(function(t){ t.stop(); }); } catch(e) {}
    var devices = await navigator.mediaDevices.enumerateDevices();
    var videoDevices = devices.filter(function(d){ return d.kind === 'videoinput'; });
    var sel = document.getElementById('kameraSec');
    sel.innerHTML = '';
    videoDevices.forEach(function(d, i) {
        var opt = document.createElement('option');
        opt.value = d.deviceId;
        opt.textContent = d.label || ('Kamera ' + (i + 1));
        // Arka kamerayı varsayılan seç
        if (d.label.toLowerCase().includes('back') || d.label.toLowerCase().includes('arka') || d.label.toLowerCase().includes('environment')) {
            opt.selected = true;
        }
        sel.appendChild(opt);
    });
    if (!videoDevices.length) sel.innerHTML = '<option value="">Kamera bulunamadı</option>';
}

// ── Kamera açma ─────────────────────────────────────────────────────
async function kameraAc() {
    var deviceId = document.getElementById('kameraSec').value;
    var constraints = {
        video: deviceId
            ? { deviceId: { exact: deviceId }, width:{ideal:1280}, height:{ideal:720} }
            : { facingMode:{ideal:'environment'}, width:{ideal:1280}, height:{ideal:720} },
        audio: false
    };
    try {
        if (stream) kameraKapat();
        stream = await navigator.mediaDevices.getUserMedia(constraints);
        var video = document.getElementById('videoEl');
        video.srcObject = stream;
        await video.play();
        document.getElementById('btnAc').classList.add('d-none');
        document.getElementById('btnKapat').classList.remove('d-none');
        document.getElementById('hedefBox').classList.remove('d-none');
        // Torch desteği kontrolü
        var track = stream.getVideoTracks()[0];
        var caps = track.getCapabilities ? track.getCapabilities() : {};
        if (caps.torch) document.getElementById('btnTorch').classList.remove('d-none');
        var scannerAd = nativeDetector ? '⚡ Native Scanner' : 'jsQR';
        var scannerCls = nativeDetector ? 'bg-success' : 'bg-secondary';
        var badge = document.getElementById('scannerBadge');
        badge.textContent = scannerAd;
        badge.className = 'badge ' + scannerCls + ' ms-auto';
        badge.classList.remove('d-none');
        setDurum('<i class="bi bi-qr-code-scan text-warning me-1"></i> Kamera açık — otomatik tarama başladı');
        taramaBaslat();
    } catch(e) {
        setDurum('<i class="bi bi-exclamation-triangle text-danger me-1"></i> Kamera açılamadı: ' + e.message);
    }
}

function kameraKapat() {
    taramaDurdur();
    if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
    document.getElementById('videoEl').srcObject = null;
    document.getElementById('btnAc').classList.remove('d-none');
    document.getElementById('btnKapat').classList.add('d-none');
    document.getElementById('btnTorch').classList.add('d-none');
    document.getElementById('hedefBox').classList.add('d-none');
    document.getElementById('scannerBadge').classList.add('d-none');
    setDurum('Kamera kapatıldı.');
}

async function torchToggle() {
    if (!stream) return;
    torchAktif = !torchAktif;
    try {
        await stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch: torchAktif }] });
        document.getElementById('btnTorch').classList.toggle('btn-warning', torchAktif);
        document.getElementById('btnTorch').classList.toggle('btn-outline-warning', !torchAktif);
    } catch(e) {}
}

// ── QR Tarama ──────────────────────────────────────────────────────
function taramaBaslat() {
    taramaActive = true;
    if (nativeDetector) {
        nativeLoop(); // native: kendi döngüsünü kurar
    } else {
        if (taramaTimer) clearInterval(taramaTimer);
        taramaTimer = setInterval(qrTaraJsqr, 80); // jsQR: 80ms (~12 fps)
    }
}

function taramaDurdur() {
    taramaActive = false;
    if (taramaTimer) { clearInterval(taramaTimer); taramaTimer = null; }
}

// Native BarcodeDetector — video frame'i doğrudan okur (canvas gereksiz)
async function nativeLoop() {
    if (!taramaActive) return;
    if (!cooldownAktif) {
        var video = document.getElementById('videoEl');
        if (video.videoWidth && video.videoHeight && !video.paused) {
            try {
                var codes = await nativeDetector.detect(video);
                if (codes.length && codes[0].rawValue) {
                    qrBulundu(codes[0].rawValue.trim());
                }
            } catch(e) {}
        }
    }
    if (taramaActive) requestAnimationFrame(nativeLoop);
}

// jsQR fallback — küçük canvas + dontInvert ile hızlandırılmış
function qrTaraJsqr() {
    if (cooldownAktif) return;
    var video = document.getElementById('videoEl');
    if (!video.videoWidth || !video.videoHeight || video.paused) return;
    if (typeof jsQR === 'undefined') return;

    scanCanvas.width  = SCAN_W;
    scanCanvas.height = SCAN_H;
    var ctx = scanCanvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, SCAN_W, SCAN_H);
    var imgData = ctx.getImageData(0, 0, SCAN_W, SCAN_H);
    // dontInvert: koyu-üzerine-açık QR (standart) için ~2x hızlı
    var code = jsQR(imgData.data, SCAN_W, SCAN_H, { inversionAttempts: 'dontInvert' });
    if (code && code.data && code.data.trim()) {
        qrBulundu(code.data.trim());
    }
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

    // JSON parse
    var json = null;
    try { json = JSON.parse(rawData); } catch(e) {}

    var irsaliyeNo  = (json && json.no)          || '';
    var tarih       = (json && json.tarih)        || '';
    var aracPlaka   = (json && json.plaka)        || '';
    var sevkZamani  = (json && json.sevkzamani)   || '';
    var ettn        = (json && json.ettn)         || '';

    // Duplicate kontrolü (aynı irsaliye_no)
    if (irsaliyeNo && taranmisList.some(function(r){ return r.irsaliye_no === irsaliyeNo; })) {
        setDurum('<i class="bi bi-exclamation-circle text-warning me-1"></i> Bu irsaliye zaten listede: ' + irsaliyeNo);
        // Farklı frekanslı uyarı sesi
        try {
            var ctx2 = new (window.AudioContext || window.webkitAudioContext)();
            var osc2 = ctx2.createOscillator();
            var g2   = ctx2.createGain();
            osc2.connect(g2); g2.connect(ctx2.destination);
            osc2.frequency.value = 400; osc2.type = 'square';
            g2.gain.setValueAtTime(0.15, ctx2.currentTime);
            g2.gain.exponentialRampToValueAtTime(0.001, ctx2.currentTime + 0.2);
            osc2.start(); osc2.stop(ctx2.currentTime + 0.2);
        } catch(e) {}
        return;
    }

    // Ses çal
    beepSes();

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
        miktar: '',
        tedarikci_id: '',
        beton_sinifi_id: '',
        proje_id: ''
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
    tr.className = 'table-success'; // yeşil highlight
    setTimeout(function(){ tr.className = ''; }, 1500); // 1.5sn sonra normal

    // Tedarikçi options
    var tedOpts = '<option value="">— Seçin —</option>';
    TEDARIKCILER.forEach(function(t){ tedOpts += '<option value="' + t.id + '">' + escHtml(t.ad) + '</option>'; });

    // Beton options
    var betOpts = '<option value="">—</option>';
    BETON_SINIFLARI.forEach(function(b){ betOpts += '<option value="' + b.id + '">' + escHtml(b.ad) + '</option>'; });

    // Proje options
    var prjOpts = '<option value="">—</option>';
    PROJELER.forEach(function(p){ prjOpts += '<option value="' + p.id + '">' + escHtml(p.kod) + '</option>'; });

    tr.innerHTML =
        '<td class="text-muted small">' + item.rowId + '</td>' +
        '<td class="font-monospace small text-nowrap">' + escHtml(item.irsaliye_no || '—') + '</td>' +
        '<td class="text-nowrap small">' + escHtml(item.tarih || '—') + '</td>' +
        '<td class="text-nowrap small">' + escHtml(item.arac_plaka || '—') + '</td>' +
        '<td class="text-nowrap small">' + escHtml(item.mikser_cikis_saati || '—') + '</td>' +
        '<td><select class="form-select form-select-sm" onchange="satirGuncelle(' + item.rowId + ',\'tedarikci_id\',this.value)">' + tedOpts + '</select></td>' +
        '<td><select class="form-select form-select-sm" onchange="satirGuncelle(' + item.rowId + ',\'beton_sinifi_id\',this.value)">' + betOpts + '</select></td>' +
        '<td><input type="number" class="form-control form-control-sm" step="0.5" min="0" placeholder="0.0" onchange="satirGuncelle(' + item.rowId + ',\'miktar\',this.value)" oninput="satirGuncelle(' + item.rowId + ',\'miktar\',this.value)"></td>' +
        '<td><select class="form-select form-select-sm" onchange="satirGuncelle(' + item.rowId + ',\'proje_id\',this.value)">' + prjOpts + '</select></td>' +
        '<td><button class="btn btn-xs btn-outline-danger" onclick="satirSil(' + item.rowId + ')"><i class="bi bi-trash"></i></button></td>';

    tbody.insertBefore(tr, tbody.firstChild); // En üste ekle

    // Tabloya scroll
    tr.scrollIntoView({behavior:'smooth', block:'center'});
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
        document.getElementById('kayitGovde').innerHTML = '<tr id="bosRow"><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-qr-code display-4 opacity-25 d-block mb-2"></i>QR kodu okutun — kayıtlar buraya eklenecek</td></tr>';
    }
    sayacGuncelle();
}

function sayacGuncelle() {
    var n = taranmisList.length;
    document.getElementById('sayacBadge').textContent = n + ' kayıt tarandı';
    document.getElementById('kaydetSayac').textContent = n;
    document.getElementById('btnTopluKaydet').classList.toggle('d-none', n === 0);
}

function listeTemizle() {
    if (!taranmisList.length || confirm('Taranan tüm kayıtlar silinecek. Emin misiniz?')) {
        taranmisList = [];
        rowSayac = 0;
        document.getElementById('kayitGovde').innerHTML = '<tr id="bosRow"><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-qr-code display-4 opacity-25 d-block mb-2"></i>QR kodu okutun — kayıtlar buraya eklenecek</td></tr>';
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
            if (data.hatalar && data.hatalar.length) {
                html += '<ul class="mb-0 mt-1 small">' + data.hatalar.map(function(e){ return '<li>' + escHtml(e) + '</li>'; }).join('') + '</ul>';
            }
            html += '</div>';
            sonucEl.innerHTML = html;
            sonucEl.classList.remove('d-none');
            // Kaydedilenleri listeden çıkar
            listeTemizle();
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
            for (var te = 0; te < TEDARIKCILER.length; te++) {
                if (TEDARIKCILER[te].vkn && String(TEDARIKCILER[te].vkn) === qrE1.vkn) {
                    parsed.tedarikci_id = String(TEDARIKCILER[te].id); break;
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
        if (qrJson.vkntckn && !parsed.tedarikci_id) {
            for (var ti = 0; ti < TEDARIKCILER.length; ti++) {
                if (TEDARIKCILER[ti].vkn && String(TEDARIKCILER[ti].vkn) === String(qrJson.vkntckn)) {
                    parsed.tedarikci_id = String(TEDARIKCILER[ti].id); break;
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

                var qrSonuc = qrListesindenVeriAl(qrListesi);
                var parsed  = { irsaliye_no:'', tarih:'', plaka:'', miktar:'',
                                sevkZamani:'', ettn:'', beton_sinifi_id:'', tedarikci_id:'' };
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
                    rowId: rowSayac, irsaliye_no: parsed.irsaliye_no,
                    tarih: parsed.tarih, arac_plaka: parsed.plaka,
                    mikser_cikis_saati: parsed.sevkZamani, fatura_no: parsed.ettn,
                    miktar: parsed.miktar, tedarikci_id: parsed.tedarikci_id,
                    beton_sinifi_id: parsed.beton_sinifi_id, proje_id: ''
                };
                taranmisList.push(item);
                tabloSatirEkleOcr(item);
                sayacGuncelle();
                bulunan++;

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

    // Native BarcodeDetector (Chrome 83+, Edge 83+) — tüm QR'ları tek seferde bulur
    var useBarcodeDetector = ('BarcodeDetector' in window);
    if (useBarcodeDetector && !window._bdQr) {
        try { window._bdQr = new BarcodeDetector({ formats: ['qr_code'] }); }
        catch(e) { useBarcodeDetector = false; }
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
                if (bulunanlar.size >= 2) break;     // JSON + E1 ikisi de bulundu
                // Bulduysa jsQR'a gerek yok, bulamadıysa jsQR denesin
                if (bulunanlar.size > 0) continue;   // kısmen buldu, büyük ölçekle dene
            } catch(e) { useBarcodeDetector = false; }
        }

        // jsQR fallback: tam sayfa + 4 çeyrek + 4 köşe
        var tamSayfa = ctx.getImageData(0, 0, canvas.width, canvas.height);
        deneBolgeJsqr(tamSayfa);
        if (bulunanlar.size >= 2) break;

        var hw = Math.floor(canvas.width / 2), hh = Math.floor(canvas.height / 2);
        [[0,0,hw,hh],[hw,0,hw,hh],[0,hh,hw,hh],[hw,hh,hw,hh]].forEach(function(b){
            deneBolgeJsqr(ctx.getImageData(b[0],b[1],b[2],b[3]));
        });
        if (bulunanlar.size >= 2) break;

        var cw = Math.floor(canvas.width / 3), ch = Math.floor(canvas.height / 3);
        [[canvas.width-cw,0,cw,ch],[canvas.width-cw,canvas.height-ch,cw,ch],
         [0,canvas.height-ch,cw,ch],[0,0,cw,ch]].forEach(function(k){
            deneBolgeJsqr(ctx.getImageData(k[0],k[1],k[2],k[3]));
        });
        if (bulunanlar.size >= 2) break;
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
    if (!s || s.substring(0,2) !== 'E1') return null;
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

// QR listesinden JSON ve E1 verilerini birleştirir
function qrListesindenVeriAl(qrListesi) {
    var sonuc = { json: null, e1: null };
    for (var i = 0; i < qrListesi.length; i++) {
        var s = qrListesi[i];
        if (!s) continue;
        if (s.charAt(0) === '{') {
            try { sonuc.json = JSON.parse(s); } catch(e) {}
        } else if (s.substring(0,2) === 'E1') {
            sonuc.e1 = parseE1Qr(s);
        }
    }
    return sonuc;
}

// ── PDF OCR (Metin Çıkartma) ────────────────────────────────────────
var ocrDosyaObj = null;

function ocrSecildi(input) {
    ocrDosyaObj = input.files[0] || null;
    document.getElementById('btnOcrTara').classList.toggle('d-none', !ocrDosyaObj);
    document.getElementById('ocrSonuc').classList.add('d-none');
    document.getElementById('ocrProgress').classList.add('d-none');
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

    try {
        progressText.textContent = 'Tesseract OCR motoru başlatılıyor...';
        progressBar.style.width = '2%';

        worker = await Tesseract.createWorker('tur', 1, {
            workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/worker.min.js',
            langPath:   'https://tessdata.projectnaptha.com/4.0.0',
            logger: function(m) {
                if (m.status === 'loading tesseract core' || m.status === 'initializing tesseract' || m.status === 'loading language traineddata') {
                    progressText.textContent = 'Dil paketi yükleniyor... (ilk seferde biraz sürer)';
                }
            }
        });

        var arrayBuf = await ocrDosyaObj.arrayBuffer();
        var pdf = await pdfjsLib.getDocument({ data: arrayBuf }).promise;
        var toplamSayfa = pdf.numPages;
        var ocrCanvas = document.createElement('canvas');
        var ocrCtx = ocrCanvas.getContext('2d');

        for (var i = 1; i <= toplamSayfa; i++) {
            var pct = Math.round(((i - 1) / toplamSayfa) * 100);
            progressBar.style.width = Math.max(pct, 5) + '%';
            progressPct.textContent = pct + '%';
            progressText.textContent = toplamSayfa + ' sayfadan ' + i + '. sayfa işleniyor...';

            try {
                var page = await pdf.getPage(i);

                // ── 1) Sayfadaki TÜM QR'ları topla (JSON + E1 formatlı)
                var qrJson = null, qrE1 = null;
                try {
                    var qrListesi = await sayfadakiTumQrlar(page, ocrCanvas, ocrCtx);
                    var qrSonuc = qrListesindenVeriAl(qrListesi);
                    qrJson = qrSonuc.json;
                    qrE1   = qrSonuc.e1;
                } catch(qrErr) { /* QR yoksa devam et */ }

                // ── 2) OCR ile metin çıkar (her durumda — yedek/güvence için)
                var viewport = page.getViewport({ scale: 2.5 });
                ocrCanvas.width  = viewport.width;
                ocrCanvas.height = viewport.height;
                await page.render({ canvasContext: ocrCtx, viewport: viewport }).promise;

                progressText.textContent = toplamSayfa + ' sayfadan ' + i + '. sayfa OCR yapılıyor...';
                var result = await worker.recognize(ocrCanvas);
                var text   = result.data.text || '';

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
                    proje_id: '',
                    qrKullanildi: qrVarMi
                };
                taranmisList.push(item);
                tabloSatirEkleOcr(item);
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

function parseIrsaliyeMetin(text) {
    var lines = text.split(/\r?\n/).map(function(s){ return s.trim(); }).filter(function(s){ return s.length > 0; });
    var flat  = text.replace(/\s+/g, ' ').trim();
    var r = { irsaliye_no:'', tarih:'', plaka:'', miktar:'', sevkZamani:'', ettn:'', beton_sinifi_id:'', tedarikci_id:'' };

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
    var tM = flat.match(/\b(\d{2})[.\/](\d{2})[.\/](\d{4})\b/);
    if (tM) r.tarih = tM[3] + '-' + tM[2] + '-' + tM[1];

    // ── Plaka ────────────────────────────────────────────────────────────────
    // Türk plakası: 2 rakam + 2-3 harf + 2-4 rakam.
    // Bazı sayfalarda "Mikser Kodu - Plaka" başlığı ve plaka AYRI satırlarda.
    function bulPlaka(s) {
        var m = s.match(/\b(\d{2})\s*([A-ZÇĞİÖŞÜ]{2,3})\s*(\d{2,4})\b/);
        if (m) return (m[1] + m[2] + m[3]).toUpperCase();
        var m2 = s.match(/\b(\d{2})\s*([A-ZÇĞİÖŞÜ]{2,3})\s*([O0I1\d]{2,4})\b/);
        if (m2) return (m2[1] + m2[2] + m2[3].replace(/O/g,'0').replace(/I/g,'1')).toUpperCase();
        return '';
    }
    // "Mikser Kodu - Plaka" satırı + sonraki 3 satıra bak (başlık ayrı satırda olabilir)
    for (var li = 0; li < lines.length; li++) {
        if (/[Mm]ikser[\s\S]{0,20}?[Pp]laka/i.test(lines[li])) {
            var blob = [lines[li], lines[li+1]||'', lines[li+2]||'', lines[li+3]||''].join(' ');
            var p = bulPlaka(blob);
            if (p) { r.plaka = p; break; }
        }
    }
    // Pompa plakası satırını atla (genellikle "N" veya boş), tüm metinde ara
    if (!r.plaka) r.plaka = bulPlaka(flat);

    // ── Miktar m³ ───────────────────────────────────────────────────────────
    // KRİTİK SORUN: "Dayanım Gelişimi 0,60 MİLTAŞ BETON" → "0,60 M" false positive.
    // Önce "Dayanım Gelişimi" satırlarını metinden çıkar, sonra ara.
    var temizFlat = flat.replace(/[Dd]ayan[ıi]m\s*[Gg]eli[şs]imi\s*[\d,.\s]+/g, ' ');
    var temizLines = text.split(/\r?\n/).map(function(s){ return s.trim(); })
        .filter(function(s){ return s.length > 0 && !/[Dd]ayan[ıi]m\s*[Gg]eli[şs]imi/i.test(s); });

    // "m" birim eşleştirme — OCR gürültüsüne karşı esnek
    function miktarBul(s) {
        // Açık birim: m³, m3, m2 (OCR ³→2), veya m sonrası büyük harf yok
        var m = s.match(/(\d+[,.]\d{1,2})\s*(?:m[³3²2\?]|m\s*$|m\s+(?:\d|[a-z]))/i);
        if (m && !m[0].match(/m[A-ZÇĞİÖŞÜ]/)) return m[1];
        var m2 = s.match(/(\d+[,.]\d{1,2})\s*m(?![A-ZÇĞİÖŞÜ])/);
        if (m2) return m2[1];
        // Tam sayı m³ (örn. "9 m3" — virgülsüz)
        var m3 = s.match(/\b(\d{1,3})\s*(?:m[³3²2]|m\s*$)/i);
        if (m3 && !m3[0].match(/m[A-ZÇĞİÖŞÜ]/)) return m3[1] + ',00';
        return null;
    }

    var miktarHam = null;
    // 1) "Miktar" başlığı yakınında ara (en güvenilir)
    for (var mi = 0; mi < temizLines.length; mi++) {
        if (/[Mm]iktar/.test(temizLines[mi])) {
            var area = temizLines.slice(mi, mi + 4).join(' ');
            miktarHam = miktarBul(area);
            if (miktarHam) break;
        }
    }
    // 2) Beton sınıfı (C25/30 vb.) satırı yanında
    if (!miktarHam) {
        for (var bi2 = 0; bi2 < temizLines.length; bi2++) {
            if (/\bC\s?\d{2}\s?[\/\-]\s?\d{2}/i.test(temizLines[bi2])) {
                var area3 = temizLines.slice(bi2, bi2 + 3).join(' ');
                miktarHam = miktarBul(area3);
                if (miktarHam) break;
            }
        }
    }
    // 3) Temizlenmiş metin üzerinde genel fallback
    if (!miktarHam) miktarHam = miktarBul(temizFlat);

    if (miktarHam) {
        var ms = miktarHam.replace(',', '.');
        var mn = parseFloat(ms);
        if (!isNaN(mn) && mn >= 1 && mn < 100) r.miktar = ms;
    }

    // ── Sevk saati ──────────────────────────────────────────────────────────
    var sM = flat.match(/[Ss]evk[\s\S]{0,50}?(\d{2}:\d{2})/) ||
             flat.match(/[Çç][ıi]k[ıi][şs][\s\S]{0,30}?(\d{2}:\d{2})/) ||
             flat.match(/(\d{2}:\d{2}:\d{2})/);
    if (sM) r.sevkZamani = sM[1].substring(0, 5);

    // ── ETTN ────────────────────────────────────────────────────────────────
    var eM = flat.match(/[Ee][Tt][Tt][Nn][\s:]+([A-Za-z0-9\-]{30,50})/);
    if (eM) r.ettn = eM[1].trim();

    // ── Beton sınıfı ────────────────────────────────────────────────────────
    // Önce "C30/37" formatı (iki sayılı), yoksa "Basınç Dayanım Sınıfı C30" formatı (tek sayı)
    var bM = flat.match(/\bC\s*(\d{2})\s*[\/\-]\s*(\d{2,3})\b/i) ||
             flat.match(/[Bb]as[ıi]n[çc]\s*[Dd]ayan[ıi]m\s*[Ss][ıi]n[ıi]f[ıi]\s*([Cc]\d{2,3})/);
    if (bM) {
        // bM[0] tam eşleşme, bM[1]+bM[2] = parçalar veya bM[1] = "C30"
        var rawBeton = bM[2] ? ('C' + bM[1] + '/' + bM[2]) : bM[1];
        var bn = rawBeton.replace(/\s+/g,'').toUpperCase();
        for (var b = 0; b < BETON_SINIFLARI.length; b++) {
            var bc = BETON_SINIFLARI[b].ad.replace(/\s+/g,'').toUpperCase();
            if (bc === bn || bc.includes(bn) || bn.includes(bc)) {
                r.beton_sinifi_id = String(BETON_SINIFLARI[b].id); break;
            }
        }
    }

    // ── Tedarikçi ───────────────────────────────────────────────────────────
    // SORUN: "Müşteri Adı: ANADOLU BİRLEŞİM BETON" → "ANADOLU BETON" tedarikçisiyle yanlış eşleşiyor.
    // ÇÖZÜM 1: OCR metnindeki ilk "VKN XXXXXXXXXX" satıcı VKN'sidir → DB'deki vkn ile eşleştir.
    // ÇÖZÜM 2: Müşteri adı satırını dışarıda bırakarak isim eşleştir.
    var vknM = flat.match(/\bVKN\s+(\d{10,11})\b/);
    var ocrVkn = vknM ? vknM[1] : '';

    // "Müşteri Adı" satırından sonrasını tedarikçi aramasına dahil etme
    var musteriIdx = flat.toUpperCase().indexOf('MÜŞTERİ ADI');
    var aramaMetni = musteriIdx > 0 ? flat.substring(0, musteriIdx) : flat;
    var fu = aramaMetni.toUpperCase();

    for (var t = 0; t < TEDARIKCILER.length; t++) {
        // Önce VKN ile eşleştir (en güvenilir)
        if (ocrVkn && TEDARIKCILER[t].vkn && String(TEDARIKCILER[t].vkn) === ocrVkn) {
            r.tedarikci_id = String(TEDARIKCILER[t].id); break;
        }
    }
    if (!r.tedarikci_id) {
        // VKN eşleşmesi yoksa, müşteri adı hariç metinde isim ara
        for (var t2 = 0; t2 < TEDARIKCILER.length; t2++) {
            var ta = TEDARIKCILER[t2].ad.toUpperCase();
            var words = ta.split(' ').filter(function(w){ return w.length >= 5; });
            var hit = words.length > 0 && words.every(function(w){ return fu.includes(w); });
            if (!hit && ta.length >= 5) hit = fu.includes(ta);
            if (hit) { r.tedarikci_id = String(TEDARIKCILER[t2].id); break; }
        }
    }

    return r;
}

// tabloSatirEkle'nin OCR versiyonu — dropdown ve miktar önceden seçili
function tabloSatirEkleOcr(item) {
    var bosRow = document.getElementById('bosRow');
    if (bosRow) bosRow.remove();

    var tbody = document.getElementById('kayitGovde');
    var tr = document.createElement('tr');
    tr.id = 'row-' + item.rowId;
    tr.className = 'table-info';
    setTimeout(function(){ tr.className = ''; }, 2000);

    var tedOpts = '<option value="">— Seçin —</option>';
    TEDARIKCILER.forEach(function(t){
        tedOpts += '<option value="' + t.id + '"' + (String(t.id) === item.tedarikci_id ? ' selected' : '') + '>' + escHtml(t.ad) + '</option>';
    });
    var betOpts = '<option value="">—</option>';
    BETON_SINIFLARI.forEach(function(b){
        betOpts += '<option value="' + b.id + '"' + (String(b.id) === item.beton_sinifi_id ? ' selected' : '') + '>' + escHtml(b.ad) + '</option>';
    });
    var prjOpts = '<option value="">—</option>';
    PROJELER.forEach(function(p){
        prjOpts += '<option value="' + p.id + '">' + escHtml(p.kod) + '</option>';
    });

    tr.innerHTML =
        '<td class="text-muted small">' + item.rowId + '</td>' +
        '<td class="font-monospace small text-nowrap">' + escHtml(item.irsaliye_no || '—') + '</td>' +
        '<td class="text-nowrap small">' + escHtml(item.tarih || '—') + '</td>' +
        '<td class="text-nowrap small">' + escHtml(item.arac_plaka || '—') + '</td>' +
        '<td class="text-nowrap small">' + escHtml(item.mikser_cikis_saati || '—') + '</td>' +
        '<td><select class="form-select form-select-sm" onchange="satirGuncelle(' + item.rowId + ',\'tedarikci_id\',this.value)">' + tedOpts + '</select></td>' +
        '<td><select class="form-select form-select-sm" onchange="satirGuncelle(' + item.rowId + ',\'beton_sinifi_id\',this.value)">' + betOpts + '</select></td>' +
        '<td><input type="number" class="form-control form-control-sm" step="0.5" min="0" placeholder="0.0" value="' + escHtml(item.miktar) + '" onchange="satirGuncelle(' + item.rowId + ',\'miktar\',this.value)" oninput="satirGuncelle(' + item.rowId + ',\'miktar\',this.value)"></td>' +
        '<td><select class="form-select form-select-sm" onchange="satirGuncelle(' + item.rowId + ',\'proje_id\',this.value)">' + prjOpts + '</select></td>' +
        '<td><button class="btn btn-xs btn-outline-danger" onclick="satirSil(' + item.rowId + ')"><i class="bi bi-trash"></i></button></td>';

    tbody.insertBefore(tr, tbody.firstChild);
    tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
