<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth();
require_once __DIR__ . '/includes/db.php';
$pageTitle = 'Hızlı Tarama — Beton Takip Sistemi';
require_once __DIR__ . '/includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>

<div class="row g-4">

    <!-- Sol: Kamera -->
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex align-items-center gap-2 fw-semibold">
                <i class="bi bi-camera-video text-primary"></i>
                Kamera Önizleme
            </div>
            <div class="card-body p-3">

                <!-- Kamera butonları -->
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button id="btnKameraAc" class="btn btn-success btn-lg flex-fill"
                            onclick="kameraAc()">
                        <i class="bi bi-camera-video-fill me-1"></i> Kamerayı Aç
                    </button>
                    <button id="btnKameraKapat" class="btn btn-secondary btn-lg flex-fill d-none"
                            onclick="kameraKapat()">
                        <i class="bi bi-camera-video-off-fill me-1"></i> Kapat
                    </button>
                    <button id="btnCevir" class="btn btn-outline-primary btn-lg d-none"
                            onclick="kameraCevir()" title="Ön/Arka kamera">
                        <i class="bi bi-arrow-repeat"></i>
                    </button>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button id="btnTaraToggle" class="btn btn-outline-warning btn-lg flex-fill d-none"
                            onclick="taramaToggle()">
                        <i class="bi bi-qr-code-scan me-1"></i> QR Tara: <span id="taramaLabel">KAPALI</span>
                    </button>
                    <button id="btnFoto" class="btn btn-outline-secondary btn-lg d-none"
                            onclick="fotografCek()">
                        <i class="bi bi-camera me-1"></i> Fotoğraf Çek
                    </button>
                </div>

                <!-- Video + overlay -->
                <div class="position-relative bg-black rounded overflow-hidden"
                     style="min-height:280px;">
                    <video id="videoEl" autoplay playsinline muted
                           class="w-100 d-block rounded"
                           style="max-height:420px;object-fit:cover;"></video>
                    <canvas id="canvasEl" class="d-none"></canvas>

                    <!-- Hedef çerçeve overlay -->
                    <div id="hedefCerceve" class="d-none position-absolute"
                         style="
                            top:50%;left:50%;
                            transform:translate(-50%,-50%);
                            width:220px;height:220px;
                            border:3px solid #28a745;
                            border-radius:12px;
                            box-shadow:0 0 0 4000px rgba(0,0,0,.35);
                            pointer-events:none;">
                        <span class="position-absolute top-0 start-0 border-top border-start border-3 border-success"
                              style="width:28px;height:28px;border-radius:4px 0 0 0;"></span>
                        <span class="position-absolute top-0 end-0 border-top border-end border-3 border-success"
                              style="width:28px;height:28px;border-radius:0 4px 0 0;"></span>
                        <span class="position-absolute bottom-0 start-0 border-bottom border-start border-3 border-success"
                              style="width:28px;height:28px;border-radius:0 0 0 4px;"></span>
                        <span class="position-absolute bottom-0 end-0 border-bottom border-end border-3 border-success"
                              style="width:28px;height:28px;border-radius:0 0 4px 0;"></span>
                    </div>
                </div>

                <!-- Durum mesajı -->
                <div id="durumMesaj" class="mt-2 small text-muted text-center">
                    Kamerayı açmak için butona tıklayın.
                </div>

            </div>
        </div>
    </div>

    <!-- Sağ: Sonuçlar -->
    <div class="col-lg-6">

        <!-- Tarama Sonucu -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center gap-2 fw-semibold">
                <i class="bi bi-clipboard-data text-success"></i>
                Tarama Sonucu
            </div>
            <div class="card-body p-3">
                <div id="sonucBos" class="text-center text-muted py-4">
                    <i class="bi bi-qr-code display-4 opacity-25"></i>
                    <p class="mt-2 mb-0">Henüz QR kodu taranmadı.</p>
                </div>

                <div id="sonucIcerik" class="d-none">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-3">
                            <tbody>
                                <tr>
                                    <th class="table-light" style="width:40%">İrsaliye No</th>
                                    <td id="sonucNo">—</td>
                                </tr>
                                <tr>
                                    <th class="table-light">Tarih</th>
                                    <td id="sonucTarih">—</td>
                                </tr>
                                <tr>
                                    <th class="table-light">Araç Plaka</th>
                                    <td id="sonucPlaka">—</td>
                                </tr>
                                <tr>
                                    <th class="table-light">Sevk Zamanı</th>
                                    <td id="sonucSevkZamani">—</td>
                                </tr>
                                <tr>
                                    <th class="table-light">ETTN</th>
                                    <td id="sonucEttn">—</td>
                                </tr>
                                <tr id="hamVeriSatiri">
                                    <th class="table-light">Ham Veri</th>
                                    <td id="sonucHam" class="text-break small font-monospace">—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-grid">
                        <a id="btnYeniIrsaliye" href="#" class="btn btn-primary btn-lg">
                            <i class="bi bi-plus-circle me-1"></i> Yeni İrsaliye Oluştur
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarihçe -->
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between fw-semibold">
                <span><i class="bi bi-clock-history text-secondary me-1"></i> Son Taramalar</span>
                <button class="btn btn-sm btn-outline-danger" onclick="tarihceSil()">
                    <i class="bi bi-trash"></i> Temizle
                </button>
            </div>
            <div class="card-body p-0">
                <div id="tarihceListesi">
                    <p class="text-muted small text-center py-3 mb-0">Tarihçe yok.</p>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ── Durum değişkenleri ──────────────────────────────────────────────
var stream        = null;
var taramaAktif   = false;
var taramaInterval = null;
var facingMode    = 'environment'; // arka kamera önce

// ── Kamera ─────────────────────────────────────────────────────────
function kameraAc() {
    var constraints = {
        video: { facingMode: { ideal: facingMode }, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false
    };
    navigator.mediaDevices.getUserMedia(constraints)
        .then(function(s) {
            stream = s;
            var video = document.getElementById('videoEl');
            video.srcObject = s;
            video.play();

            document.getElementById('btnKameraAc').classList.add('d-none');
            document.getElementById('btnKameraKapat').classList.remove('d-none');
            document.getElementById('btnCevir').classList.remove('d-none');
            document.getElementById('btnTaraToggle').classList.remove('d-none');
            document.getElementById('btnFoto').classList.remove('d-none');
            document.getElementById('hedefCerceve').classList.remove('d-none');
            setDurum('<i class="bi bi-camera-video-fill text-success me-1"></i> Kamera açık.');
        })
        .catch(function(err) {
            setDurum('<i class="bi bi-exclamation-triangle text-danger me-1"></i> Kamera açılamadı: ' + err.message);
        });
}

function kameraKapat() {
    taramaDurdur();
    if (stream) {
        stream.getTracks().forEach(function(t){ t.stop(); });
        stream = null;
    }
    var video = document.getElementById('videoEl');
    video.srcObject = null;

    document.getElementById('btnKameraAc').classList.remove('d-none');
    document.getElementById('btnKameraKapat').classList.add('d-none');
    document.getElementById('btnCevir').classList.add('d-none');
    document.getElementById('btnTaraToggle').classList.add('d-none');
    document.getElementById('btnFoto').classList.add('d-none');
    document.getElementById('hedefCerceve').classList.add('d-none');
    setDurum('Kamera kapatıldı.');
}

function kameraCevir() {
    facingMode = (facingMode === 'environment') ? 'user' : 'environment';
    kameraKapat();
    setTimeout(kameraAc, 300);
}

// ── QR Tarama ──────────────────────────────────────────────────────
function taramaToggle() {
    if (taramaAktif) {
        taramaDurdur();
    } else {
        taramaBaslat();
    }
}

function taramaBaslat() {
    taramaAktif = true;
    document.getElementById('taramaLabel').textContent = 'AÇIK';
    document.getElementById('btnTaraToggle').classList.remove('btn-outline-warning');
    document.getElementById('btnTaraToggle').classList.add('btn-warning');
    setDurum('<i class="bi bi-qr-code-scan text-warning me-1"></i> QR tarama aktif…');
    taramaInterval = setInterval(qrTara, 250);
}

function taramaDurdur() {
    taramaAktif = false;
    clearInterval(taramaInterval);
    taramaInterval = null;
    document.getElementById('taramaLabel').textContent = 'KAPALI';
    document.getElementById('btnTaraToggle').classList.remove('btn-warning');
    document.getElementById('btnTaraToggle').classList.add('btn-outline-warning');
    if (stream) {
        setDurum('<i class="bi bi-camera-video-fill text-success me-1"></i> Kamera açık, tarama durduruldu.');
    }
}

function qrTara() {
    var video = document.getElementById('videoEl');
    if (!video.videoWidth || !video.videoHeight) return;

    var canvas = document.getElementById('canvasEl');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    var code = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert'
    });

    if (code && code.data) {
        taramaDurdur();
        qrSonucGoster(code.data);
    }
}

// ── Fotoğraf çek (tek kare tarama) ────────────────────────────────
function fotografCek() {
    var video = document.getElementById('videoEl');
    if (!video.videoWidth) {
        setDurum('<i class="bi bi-exclamation-triangle text-warning me-1"></i> Kamera hazır değil.');
        return;
    }
    var canvas = document.getElementById('canvasEl');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    var code = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert'
    });

    if (code && code.data) {
        qrSonucGoster(code.data);
    } else {
        setDurum('<i class="bi bi-exclamation-circle text-warning me-1"></i> QR kodu bulunamadı. Tekrar deneyin.');
    }
}

// ── Sonuç göster ───────────────────────────────────────────────────
function qrSonucGoster(veri) {
    setDurum('<i class="bi bi-check-circle-fill text-success me-1"></i> QR kodu başarıyla okundu!');

    var json = null;
    try { json = JSON.parse(veri); } catch(e) { /* ham veri */ }

    var no          = (json && (json.no || json.irsaliye_no || json.irsaliyeNo)) || '';
    var tarih       = (json && (json.tarih || json.date)) || '';
    var plaka       = (json && (json.plaka || json.arac_plaka || json.aracPlaka)) || '';
    var sevkZamani  = (json && (json.sevkzamani || json.sevk_zamani || json.sevkZamani)) || '';
    var ettn        = (json && (json.ettn || json.ETTN)) || '';

    document.getElementById('sonucNo').textContent        = no        || '—';
    document.getElementById('sonucTarih').textContent     = tarih     || '—';
    document.getElementById('sonucPlaka').textContent     = plaka     || '—';
    document.getElementById('sonucSevkZamani').textContent= sevkZamani|| '—';
    document.getElementById('sonucEttn').textContent      = ettn      || '—';
    document.getElementById('sonucHam').textContent       = veri;

    // Satırı sadece ham metin varsa göster (json değil)
    document.getElementById('hamVeriSatiri').style.display = json ? 'none' : '';

    // İrsaliye form URL'i
    var params = new URLSearchParams();
    if (no)         params.set('irsaliye_no', no);
    if (tarih)      params.set('tarih', tarih);
    if (plaka)      params.set('arac_plaka', plaka);
    if (sevkZamani) params.set('sevk_zamani', sevkZamani);
    if (ettn)       params.set('ettn', ettn);
    document.getElementById('btnYeniIrsaliye').href = 'irsaliye_form.php?' + params.toString();

    document.getElementById('sonucBos').classList.add('d-none');
    document.getElementById('sonucIcerik').classList.remove('d-none');

    // Tarihçeye ekle
    tarihceEkle({
        veri: veri,
        no: no,
        tarih: tarih,
        plaka: plaka,
        zaman: new Date().toLocaleString('tr-TR')
    });
}

// ── Tarihçe (sessionStorage) ────────────────────────────────────────
var TARIHCE_KEY = 'beton_qr_tarihce';

function tarihceYukle() {
    try { return JSON.parse(sessionStorage.getItem(TARIHCE_KEY)) || []; }
    catch(e) { return []; }
}

function tarihceKaydet(list) {
    sessionStorage.setItem(TARIHCE_KEY, JSON.stringify(list));
}

function tarihceEkle(item) {
    var list = tarihceYukle();
    list.unshift(item);
    if (list.length > 5) list = list.slice(0, 5);
    tarihceKaydet(list);
    tarihceGoster();
}

function tarihceSil() {
    sessionStorage.removeItem(TARIHCE_KEY);
    tarihceGoster();
}

function tarihceGoster() {
    var list = tarihceYukle();
    var el   = document.getElementById('tarihceListesi');
    if (!list.length) {
        el.innerHTML = '<p class="text-muted small text-center py-3 mb-0">Tarihçe yok.</p>';
        return;
    }
    var html = '<ul class="list-group list-group-flush">';
    list.forEach(function(item, idx) {
        html += '<li class="list-group-item list-group-item-action py-2 px-3" style="cursor:pointer" onclick="tarihceSec(' + idx + ')">';
        html += '<div class="d-flex justify-content-between align-items-start">';
        html += '<div>';
        if (item.no)    html += '<span class="fw-semibold">' + escHtml(item.no) + '</span><br>';
        if (item.plaka) html += '<small class="text-muted"><i class="bi bi-truck me-1"></i>' + escHtml(item.plaka) + '</small>';
        if (!item.no && !item.plaka) html += '<small class="text-muted font-monospace">' + escHtml(item.veri.substring(0,40)) + (item.veri.length > 40 ? '…' : '') + '</small>';
        html += '</div>';
        html += '<small class="text-muted ms-2 text-nowrap">' + escHtml(item.zaman) + '</small>';
        html += '</div>';
        html += '</li>';
    });
    html += '</ul>';
    el.innerHTML = html;
}

function tarihceSec(idx) {
    var list = tarihceYukle();
    if (list[idx]) qrSonucGoster(list[idx].veri);
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Yardımcı ───────────────────────────────────────────────────────
function setDurum(html) {
    document.getElementById('durumMesaj').innerHTML = html;
}

// Sayfa yüklenince tarihçeyi göster
document.addEventListener('DOMContentLoaded', function() {
    tarihceGoster();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setDurum('<i class="bi bi-exclamation-triangle text-danger me-1"></i> Bu tarayıcı kamera erişimini desteklemiyor.');
        document.getElementById('btnKameraAc').disabled = true;
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
