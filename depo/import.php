<?php
/**
 * import.php — Depo Excel içe aktarma (ÇOKLU DOSYA)
 *
 * Takip 4 ayrı Excel dosyasında gelir; hepsi tek seferde seçilip yüklenebilir
 * (dosya[] multiple). Her dosya kendi başına işlenir, kendi transaction'ında.
 *
 * İki tür sayfa okunur:
 *   STOK   → DEMİRBAŞLAR · SARF MALZEME · EL ALETLERİ            → depo_kalemler
 *   HAREKET→ MALZEME GİRİŞ/ÇIKIŞ · TAŞERON MALZEME GİRİŞ/TESLİMAT → depo_hareketler
 *
 * Her ikisi de **tam yenileme**: ilgili kategori/tür önce silinir, sonra Excel'den
 * yeniden yazılır (Excel tek doğru kaynak ilkesi). Dosyada olmayan bölüme dokunulmaz.
 *
 * Her yüklemede **veri doğrulama raporu** üretilir: Excel'in kendi özet hücreleri
 * (KALEM SAYISI / STOK MALİ DEĞERİ), STOK sütunu = SAYIM+GELEN−GİDEN sağlaması,
 * negatif stok, mükerrer kalem, okunamayan miktar/tarih, boş firma/fiş no/onay,
 * çift girilmiş hareket satırları. Bölüm bazında son yükleme `depo_import_log`'a yazılır.
 *
 * ⚠ Sütunlar SABİT DEĞİL — başlık satırındaki metinden bulunur. Sayfalar arasında
 *   sütun düzeni farklı (ör. SARF'ta MALZEME KODU yok, hepsi bir kayıyor); sabit
 *   indeks kullanmak yanlış sütunu okumaya yol açıyordu.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_import.php';   // çekirdek: toplu_yukleme.php ile ORTAK
use Shuchkin\SimpleXLSX;

$pageTitle = 'Depo Excel İçe Aktarma';

// ── POST: bir veya birden çok dosya ──────────────────────────────────────────
$raporlar = [];          // dosya başına sonuç
$genelUyari = [];        // dosyalar arası (aynı bölüm iki dosyada vb.)
$buYukleme = [];         // bölüm → dosya adı (bu yüklemede yenilenenler)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dosya'])) {
    dp_hareket_semasi_kur($pdoDepo);
    dp_import_log_kur($pdoDepo);
    $f = $_FILES['dosya'];
    $dosyalar = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $ad) $dosyalar[] = ['name'=>$ad, 'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]];
    } else {
        $dosyalar[] = $f;
    }
    $dosyalar = array_values(array_filter($dosyalar, fn($d) => ($d['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$dosyalar) $genelUyari[] = 'Dosya seçilmedi.';

    foreach ($dosyalar as $d) {
        $ad = basename((string)$d['name']);
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
            try {
                $s = dp_import_dosya($pdoDepo, $x, $ad);
                if (!$s['bolumler']) {
                    $rap['hata'] = 'Bu dosyada tanınan sayfa yok (sayfalar: ' . implode(', ', $x->sheetNames()) . '). Hiçbir şey değiştirilmedi.';
                } else {
                    $rap['sonuc'] = $s;
                    foreach ($s['bolumler'] as $b => $n) {
                        if (isset($buYukleme[$b]) && $buYukleme[$b] !== $ad)
                            $genelUyari[] = (DP_BOLUM[$b][0] ?? $b) . " bölümü hem \"{$buYukleme[$b]}\" hem \"$ad\" dosyasında var — son işlenen ($ad) esas alındı.";
                        $buYukleme[$b] = $ad;
                    }
                }
            } catch (Throwable $e) {
                $rap['hata'] = 'İçe aktarma hatası: ' . $e->getMessage() . ' (bu dosyanın hiçbir bölümü değiştirilmedi)';
            }
        }
        $raporlar[] = $rap;
    }
}

$sonYukleme = dp_import_son($pdoDepo);
$korunan = 0;
try { $korunan = (int)$pdoDepo->query("SELECT COUNT(*) FROM depo_hareketler WHERE elle=1")->fetchColumn(); } catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
$ikon = ['ok'=>'<i class="bi bi-patch-check-fill text-success"></i>', 'uyari'=>'<i class="bi bi-exclamation-triangle-fill text-warning"></i>', 'bilgi'=>'<i class="bi bi-info-circle text-secondary"></i>', 'fark'=>'<i class="bi bi-exclamation-triangle-fill text-warning"></i>'];
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Depo Excel İçe Aktarma</h4>
  <a href="../toplu_yukleme.php" class="btn btn-outline-primary btn-sm" title="Beton Takip dahil günlük dosyaların hepsini tek seferde yükle"><i class="bi bi-cloud-arrow-up-fill me-1"></i>Günlük Toplu Yükleme</a>
</div>

<?php foreach ($genelUyari as $g): ?><div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= h($g) ?></div><?php endforeach; ?>

<?php if ($raporlar): ?>
<?php
    $basarili = array_filter($raporlar, fn($r) => $r['sonuc'] !== null);
    $toplamUyari = 0;
    foreach ($basarili as $r) { foreach ($r['sonuc']['kontrol'] as $k) if ($k[0] === 'uyari') $toplamUyari++; foreach ($r['sonuc']['saglamalar'] as $s) if ($s[0] === 'fark') $toplamUyari++; }
?>
<div class="alert <?= $basarili ? 'alert-success' : 'alert-danger' ?> py-2">
    <i class="bi bi-<?= $basarili ? 'check-circle' : 'x-circle' ?> me-1"></i>
    <strong><?= count($basarili) ?>/<?= count($raporlar) ?> dosya aktarıldı.</strong>
    <?php if ($buYukleme): ?>Yenilenen bölümler: <?= h(implode(' · ', array_map(fn($b) => DP_BOLUM[$b][0] ?? $b, array_keys($buYukleme)))) ?>.<?php endif; ?>
    <?php if ($toplamUyari): ?><span class="badge bg-warning text-dark ms-1"><?= $toplamUyari ?> veri uyarısı</span><?php endif; ?>
    <?php if ($korunan): ?><span class="text-muted small ms-1">— elle girilen <?= $fmt0($korunan) ?> günlük kayıt korundu</span><?php endif; ?>
    <div class="small mt-1">
        <a href="index.php" class="alert-link">Dashboard</a> ·
        <a href="kalemler.php?k=demirbas" class="alert-link">Demirbaşlar</a> ·
        <a href="kalemler.php?k=sarf" class="alert-link">Sarf</a> ·
        <a href="hareketler.php" class="alert-link">Hareketler</a>
    </div>
</div>

<?php foreach ($raporlar as $r): ?>
<div class="card mb-2 <?= $r['hata'] ? 'border-danger' : '' ?>">
    <div class="card-header py-2 small d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-excel <?= $r['hata'] ? 'text-danger' : 'text-success' ?>"></i>
        <strong class="text-truncate"><?= h($r['ad']) ?></strong>
        <?php if ($r['sonuc']): ?><span class="text-muted ms-auto text-nowrap"><?= h(implode(' · ', $r['sonuc']['bulunan'])) ?></span><?php endif; ?>
    </div>
    <div class="card-body py-2 small">
        <?php if ($r['hata']): ?>
            <div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= h($r['hata']) ?></div>
        <?php else: ?>
            <?php foreach ($r['sonuc']['saglamalar'] as [$sd, $sm]): ?>
                <div><?= $ikon[$sd] ?> <?= h($sm) ?></div>
            <?php endforeach; ?>
            <?php foreach ($r['sonuc']['kontrol'] as [$sv, $sm, $detay]): ?>
                <?php if ($detay): ?>
                    <details class="mb-1"><summary style="cursor:pointer"><?= $ikon[$sv] ?> <?= h($sm) ?> <span class="text-muted">(satırları göster)</span></summary>
                        <ul class="mb-1 mt-1 text-muted" style="font-size:.8rem"><?php foreach ($detay as $dd): ?><li><?= h($dd) ?></li><?php endforeach; ?></ul>
                    </details>
                <?php else: ?>
                    <div><?= $ikon[$sv] ?> <?= h($sm) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="dpImportForm">
        <div class="col-md-9">
            <label class="form-label small">Depo Excel dosyaları (.xlsx) — <strong>4 dosyayı birden seçebilirsiniz</strong></label>
            <input type="file" name="dosya[]" id="dpDosya" class="form-control form-control-sm" accept=".xlsx" multiple required>
            <div id="dpSecilen" class="form-text"></div>
        </div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100" id="dpBtn"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Eşitle</button></div>
    </form>
</div></div>

<div class="card"><div class="card-body small">
    <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Bölümler ve son yükleme</div>
    <div class="table-responsive">
    <table class="table table-sm table-bordered mb-2" style="max-width:980px">
        <thead class="table-light"><tr><th>Excel sayfası</th><th>Dosya</th><th>Nereye yazılır</th><th>Son yükleme</th></tr></thead>
        <tbody>
        <?php foreach (DP_BOLUM as $b => [$sayfa, $hedef, $dosyaTip]): $s = $sonYukleme[$b] ?? null; ?>
            <tr class="<?= isset($buYukleme[$b]) ? 'table-success' : '' ?>">
                <td><?= h($sayfa) ?></td>
                <td class="text-muted"><?= h($dosyaTip) ?></td>
                <td><?= h($hedef) ?></td>
                <td>
                    <?php if ($s): ?>
                        <?= isset($buYukleme[$b]) ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '' ?>
                        <?= h(date('d.m.Y H:i', strtotime($s['created']))) ?> · <?= $fmt0($s['satir']) ?> satır
                        <span class="text-muted">· <?= h($s['dosya'] ?? '') ?><?= $s['kullanici'] ? ' · ' . h($s['kullanici']) : '' ?></span>
                    <?php else: ?><span class="text-muted">— henüz yüklenmedi / kayıt yok</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="text-muted">
        Sayfa adı <strong>içerir</strong> mantığıyla eşleşir — "KARTAL-BATIYAKASI SARF MALZEME" de bulunur.
        Sütunlar başlık metninden okunur, sabit sıra beklenmez. Her dosya <strong>yalnız kendi bölümlerini</strong> tam yeniler;
        dosyada bulunmayan bölümlere dokunulmaz. Elle girilen günlük hareketler korunur.
    </div>
    <div class="alert alert-info small mt-2 mb-0">
        <i class="bi bi-collection me-1"></i><strong>4 dosyayı tek seferde yükleyin:</strong>
        <em>Demirbaş Takip</em> · <em>Sarf Malzeme Stok</em> (sarf + el aletleri) · <em>Malzeme Takip</em> (depo giriş/çıkış) ·
        <em>Sarf Taşeron Malzeme Teslimat</em> (taşeron giriş/teslimat). Dosya seçme penceresinde Ctrl/⌘ ile hepsini işaretleyin.
        Sıra fark etmez; her dosya kendi başına işlenir, biri hatalıysa diğerleri yine aktarılır.
        Aktarım sonunda her dosya için <strong>veri doğrulama raporu</strong> çıkar: Excel özet hücreleri (kalem sayısı / mali değer),
        STOK = SAYIM+GELEN−GİDEN sağlaması, eksi stok, mükerrer kart, okunamayan miktar/tarih, çift girilmiş hareket satırları.
    </div>
</div></div>

<script>
(function(){
    var inp = document.getElementById('dpDosya'), out = document.getElementById('dpSecilen'), btn = document.getElementById('dpBtn');
    if (!inp) return;
    inp.addEventListener('change', function(){
        var f = inp.files, n = [];
        for (var i = 0; i < f.length; i++) n.push(f[i].name + ' (' + Math.round(f[i].size / 1024) + ' KB)');
        out.textContent = f.length ? f.length + ' dosya seçildi: ' + n.join(' · ') : '';
    });
    document.getElementById('dpImportForm').addEventListener('submit', function(){
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aktarılıyor… (' + inp.files.length + ' dosya)';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
