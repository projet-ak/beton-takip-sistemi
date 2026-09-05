<?php
/**
 * import.php — Prekast günlük "İŞ TAKİP / HAKKEDİŞ ÇİZELGESİ" aktarımı
 *
 * Çizelge sabit iş listesidir; her gün aynı satırlar gelir, durumlar dolar. İçe aktarma
 * BİRLEŞTİRİR (bkz. _import.php): yeni satır → kayıt açılır, mevcut satır → güncellenir,
 * dosyadan düşen satır silinmez "çizelgede yok" işaretlenir. Bir satır ilk kez "Yapıldı"
 * olduğunda o günün tarihi damgalanır — ilerleme takvimi böyle oluşur.
 * Birden çok gün biriktiyse dosyalar tarih sırasıyla tek seferde yüklenebilir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_prekast.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_import.php';
use Shuchkin\SimpleXLSX;

pk_semasi_kur($pdoPrekast);
$pageTitle = 'Prekast Çizelge Aktarımı';

$raporlar = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dosya'])) {
    $tarih = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['rapor_tarihi'] ?? '') ? $_POST['rapor_tarihi'] : date('Y-m-d');
    $kisi  = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;

    $f = $_FILES['dosya'];
    $dosyalar = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $ad) $dosyalar[] = ['name'=>$ad, 'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]];
    } else { $dosyalar[] = $f; }
    $dosyalar = array_values(array_filter($dosyalar, fn($d) => ($d['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    // Birden çok gün birden yüklenirse dosya adına göre sırala — damgalar doğru güne düşsün
    usort($dosyalar, fn($a, $b) => strcmp((string)(pk_dosya_tarihi((string)$a['name']) ?: $a['name']),
                                          (string)(pk_dosya_tarihi((string)$b['name']) ?: $b['name'])));

    foreach ($dosyalar as $d) {
        $ad  = basename((string)$d['name']);
        $rap = ['ad'=>$ad, 'hata'=>null, 'sonuc'=>null];
        if ($d['error'] !== UPLOAD_ERR_OK) {
            $rap['hata'] = match ((int)$d['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu boyut sınırını aşıyor (upload_max_filesize).',
                UPLOAD_ERR_PARTIAL => 'Dosya eksik yüklendi, tekrar deneyin.',
                default => 'Yükleme hatası (kod ' . (int)$d['error'] . ').',
            };
        } elseif (!preg_match('/\.xlsx$/i', $ad)) {
            $rap['hata'] = 'Yalnız .xlsx dosyası kabul edilir.';
        } elseif (!($x = SimpleXLSX::parse($d['tmp_name']))) {
            $rap['hata'] = 'Excel okunamadı: ' . SimpleXLSX::parseError();
        } else {
            $dt = pk_dosya_tarihi($ad) ?: $tarih;
            try {
                $rap['sonuc'] = pk_import($pdoPrekast, $x, ['rapor_tarihi'=>$dt, 'dosya'=>$ad, 'kullanici'=>$kisi]);
            } catch (Throwable $e) {
                $rap['hata'] = $e->getMessage() . ' — bu dosyadan hiçbir değişiklik uygulanmadı.';
            }
        }
        $raporlar[] = $rap;
    }
}

$ozet = pk_ozet($pdoPrekast);
$gecmis = [];
try { $gecmis = $pdoPrekast->query("SELECT * FROM prekast_gunluk ORDER BY rapor_tarihi DESC, id DESC LIMIT 15")->fetchAll(); } catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Prekast Günlük Çizelge Aktarımı</h4>

<?php if ($raporlar): ?>
<?php $basarili = array_filter($raporlar, fn($r) => $r['sonuc'] !== null); ?>
<div class="alert <?= $basarili ? 'alert-success' : 'alert-danger' ?> py-2">
    <i class="bi bi-<?= $basarili ? 'check-circle' : 'x-circle' ?> me-1"></i>
    <strong><?= count($basarili) ?>/<?= count($raporlar) ?> çizelge işlendi.</strong>
    Sistemde <strong><?= $f0($ozet['silikon']) ?></strong> / <?= $f0($ozet['toplam']) ?> iş tamamlandı
    (<?= $f2($ozet['metraj']) ?> m · <?= $f2($ozet['hakkedis']) ?> TL).
    <div class="small mt-1"><a href="index.php" class="alert-link">Dashboard</a> ·
        <a href="isler.php?durum=kesim" class="alert-link">Silikon bekleyenler</a> ·
        <a href="raporlar.php" class="alert-link">Raporlar</a></div>
</div>

<?php foreach ($raporlar as $r): ?>
<div class="card mb-2 <?= $r['hata'] ? 'border-danger' : '' ?>">
    <div class="card-header py-2 small d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-file-earmark-excel <?= $r['hata'] ? 'text-danger' : 'text-success' ?>"></i>
        <strong class="text-truncate"><?= h($r['ad']) ?></strong>
        <?php if ($r['sonuc']): ?>
            <span class="text-muted">— <?= h($r['sonuc']['cizelge']) ?></span>
            <span class="ms-auto text-muted">rapor tarihi: <?= h($r['sonuc']['rapor_tarihi']) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body py-2 small">
        <?php if ($r['hata']): ?>
            <div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= h($r['hata']) ?></div>
        <?php else: $s = $r['sonuc']; ?>
            <div class="d-flex flex-wrap gap-3 mb-2">
                <span><span class="badge bg-secondary"><?= $f0($s['okunan']) ?></span> iş satırı okundu</span>
                <span><span class="badge bg-primary"><?= $f0($s['yeni']) ?></span> yeni iş</span>
                <span><span class="badge bg-info text-dark"><?= $f0($s['guncellenen']) ?></span> güncellenen</span>
                <span><span class="badge bg-warning text-dark"><?= $f0($s['yeniKesim']) ?></span> yeni kesim</span>
                <span><span class="badge bg-success"><?= $f0($s['yeniSilikon']) ?></span> yeni silikon (tamamlanan)</span>
                <?php if ($s['dusen']): ?>
                <span><span class="badge bg-dark"><?= $f0($s['dusen']) ?></span> çizelgede yok</span>
                <?php endif; ?>
            </div>
            <div class="text-muted mb-2">
                Çizelge toplamı: <strong><?= $f2($s['toplamMetraj']) ?></strong> m metraj ·
                <strong><?= $f2($s['toplamHakkedis']) ?> TL</strong> hakkediş ·
                iş tipi: <strong><?= h($s['is_tipi'] ?: '—') ?></strong>.
                Hesap: <?= $f0($s['okunan']) ?> okunan = <?= $f0($s['yeni']) ?> yeni +
                <?= $f0($s['guncellenen']) ?> güncellenen + <?= $f0($s['degismeyen']) ?> değişmeyen +
                <?= $f0(count($s['atlanan'])) ?> atlanan
                <?php if ($s['okunan'] === $s['yeni'] + $s['guncellenen'] + $s['degismeyen'] + count($s['atlanan'])): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Her satırın hesabı verildi"></i>
                <?php endif; ?>
                <?php if ($s['sablon']): ?>
                    · <?= $f0($s['sablon']) ?> boş şablon satırı atlandı
                <?php endif; ?>
            </div>

            <?php foreach ($s['kontrol'] as $k):
                $renk = ['ok'=>'success', 'uyari'=>'warning', 'bilgi'=>'info'][$k['tip']] ?? 'secondary'; ?>
                <div class="mb-1">
                    <span class="badge bg-<?= $renk ?><?= $renk === 'warning' || $renk === 'info' ? ' text-dark' : '' ?>">
                        <?= h($k['baslik']) ?></span>
                    <span class="text-muted"><?= h($k['mesaj']) ?></span>
                    <?php if (!empty($k['satirlar'])): ?>
                        <details class="d-inline"><summary style="cursor:pointer" class="d-inline">detay</summary>
                            <div class="ps-3 pt-1 text-muted" style="font-size:.78rem">
                                <?= h(implode(' · ', array_slice($k['satirlar'], 0, 60))) ?>
                                <?php if (count($k['satirlar']) > 60): ?> … (<?= count($k['satirlar']) - 60 ?> satır daha)<?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($s['degisenler']): ?>
            <details class="mt-2"><summary style="cursor:pointer" class="text-success">
                <i class="bi bi-check2-square me-1"></i><strong><?= count($s['degisenler']) ?> işte durum ilerledi</strong> — listeyi göster</summary>
                <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered mb-0" style="font-size:.8rem">
                    <thead class="table-light"><tr><th>İş</th><th>Blok</th><th>Daire</th><th class="text-end">Metraj</th><th class="text-end">Hakkediş</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($s['degisenler'], 0, 80) as $dg): ?>
                        <tr>
                            <td><span class="badge bg-<?= $dg['tur'] === 'silikon' ? 'success' : 'warning text-dark' ?>"><?= $dg['tur'] === 'silikon' ? 'Silikon' : 'Kesim' ?></span></td>
                            <td><?= h($dg['blok']) ?></td><td><?= h($dg['daire']) ?></td>
                            <td class="text-end"><?= $f2($dg['metraj']) ?></td>
                            <td class="text-end"><?= $f2($dg['hakkedis']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($s['degisenler']) > 80): ?>
                <div class="text-muted mt-1">… ve <?= count($s['degisenler']) - 80 ?> kayıt daha.</div>
                <?php endif; ?>
            </details>
            <?php endif; ?>

            <?php if ($s['atlanan']): ?>
            <details class="mt-2"><summary style="cursor:pointer" class="text-warning-emphasis">
                <i class="bi bi-exclamation-triangle-fill me-1"></i><strong><?= count($s['atlanan']) ?> satır kayıt açılmadan atlandı</strong> — sebebiyle göster</summary>
                <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered mb-0" style="font-size:.8rem">
                    <thead class="table-light"><tr><th style="width:70px">Excel satırı</th><th>Sebep</th><th>İçerik</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['atlanan'] as $at): ?>
                        <tr><td><?= (int)$at['satir'] ?></td><td><?= h($at['sebep']) ?></td><td class="text-muted"><?= h($at['ozet']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </details>
            <?php endif; ?>

            <?php foreach ($s['uyari'] as $u): ?>
                <div class="text-warning-emphasis mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= h($u) ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="pkForm">
        <div class="col-md-6">
            <label class="form-label small">İş takip / hakkediş çizelgesi (.xlsx) — birden çok gün seçilebilir</label>
            <input type="file" name="dosya[]" id="pkDosya" class="form-control form-control-sm" accept=".xlsx" multiple required>
            <div id="pkSecilen" class="form-text"></div>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Rapor tarihi</label>
            <input type="date" name="rapor_tarihi" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            <div class="form-text">Dosya adında tarih varsa o kullanılır.</div>
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary btn-sm w-100" id="pkBtn"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Güncelle</button>
        </div>
    </form>
</div></div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100"><div class="card-body small">
        <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Günlük çizelge nasıl işlenir?</div>
        <table class="table table-sm table-bordered mb-2">
            <thead class="table-light"><tr><th>Durum</th><th>Sistemin yaptığı</th></tr></thead>
            <tbody>
                <tr><td>Çizelgede var, sistemde yok</td><td><span class="badge bg-primary">Yeni iş</span> açılır</td></tr>
                <tr><td>Çizelgede var, sistemde var</td><td>Alanlar güncellenir, "son görülme" yenilenir</td></tr>
                <tr><td>Kesim ilk kez "Yapıldı"</td><td><span class="badge bg-warning text-dark">Kesim tarihi</span> o günün raporuyla damgalanır</td></tr>
                <tr><td>Silikon ilk kez "Yapıldı"</td><td><span class="badge bg-success">Tamamlandı</span> — silikon tarihi damgalanır, hakkedişe girer</td></tr>
                <tr><td>Sistemde var, çizelgede yok</td><td><span class="badge bg-dark">Çizelgede yok</span> işaretlenir — <strong>silinmez</strong></td></tr>
                <tr><td>Blok ve daire boş satır</td><td>Çizelgenin boş şablon satırı sayılır, sessizce atlanır</td></tr>
                <tr><td>Aynı dosya ikinci kez yüklenir</td><td>Hiçbir şey değişmez — kimlik içerikten üretilir, mükerrer oluşmaz</td></tr>
            </tbody>
        </table>
        <div class="text-muted">
            Çizelgede ID kolonu olmadığından kimlik <strong>çizelge + blok + daire + tekrar sırası</strong>ndan üretilir
            (aynı dairede iki ayrı iş olabildiği için tekrar sırası şart). Hakkediş her yüklemede
            <strong>metraj × birim fiyat</strong> ile çapraz kontrol edilir; tutmayan satırlar uyarı olarak listelenir
            ama <strong>Excel esas alınır</strong>. Hata olursa o dosyanın hiçbir satırı yazılmaz (transaction).
        </div>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="fw-semibold mb-2 small"><i class="bi bi-clock-history me-1"></i>Son yüklemeler</div>
        <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.8rem">
            <thead class="table-light"><tr><th>Rapor</th><th>Dosya</th><th class="text-end">Satır</th>
                <th class="text-end">Silikon</th><th class="text-end">Hakkediş</th></tr></thead>
            <tbody>
            <?php foreach ($gecmis as $g): ?>
                <tr>
                    <td><?= h(date('d.m.Y', strtotime($g['rapor_tarihi']))) ?></td>
                    <td class="text-truncate" style="max-width:140px" title="<?= h($g['dosya']) ?>"><?= h($g['dosya'] ?: '—') ?></td>
                    <td class="text-end"><?= $f0($g['satir']) ?></td>
                    <td class="text-end text-success fw-semibold"><?= $f0($g['silikon']) ?></td>
                    <td class="text-end"><?= $f0($g['hakkedis']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$gecmis): ?><tr><td colspan="5" class="text-center text-muted py-3">Henüz çizelge yüklenmedi.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div></div>
  </div>
</div>

<script>
(function(){
    var inp = document.getElementById('pkDosya'), out = document.getElementById('pkSecilen'), btn = document.getElementById('pkBtn');
    inp.addEventListener('change', function(){
        var n = [];
        for (var i = 0; i < inp.files.length; i++) n.push(inp.files[i].name);
        out.textContent = inp.files.length ? inp.files.length + ' dosya: ' + n.join(' · ') : '';
    });
    document.getElementById('pkForm').addEventListener('submit', function(){
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aktarılıyor…';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
