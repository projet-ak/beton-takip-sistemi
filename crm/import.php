<?php
/**
 * import.php — CRM "UretimArizalari" günlük raporunu içe aktarma
 *
 * Günlük rapor = o anda AÇIK olan arızaların anlık görüntüsü. İçe aktarma
 * BİRLEŞTİRİR (bkz. _import.php): yeni satır → kayıt açılır, mevcut satır → güncellenir,
 * dosyada olmayan açık kayıt → arıza kapanmış sayılır (isteğe bağlı).
 * Birden çok gün biriktiyse dosyalar **tarih sırasıyla** tek seferde yüklenebilir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_crm.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_import.php';
use Shuchkin\SimpleXLSX;

crm_semasi_kur($pdoCrm);
$pageTitle = 'CRM Günlük Rapor Aktarımı';

$raporlar = [];
$kapat = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dosya'])) {
    $kapat  = !empty($_POST['kapat']);
    $tarih  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['rapor_tarihi'] ?? '') ? $_POST['rapor_tarihi'] : date('Y-m-d');
    $kisi   = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;

    $f = $_FILES['dosya'];
    $dosyalar = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $ad) $dosyalar[] = ['name'=>$ad, 'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]];
    } else { $dosyalar[] = $f; }
    $dosyalar = array_values(array_filter($dosyalar, fn($d) => ($d['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    // Aynı anda birden çok gün yüklenirse dosya adına göre sırala (…_2026-09-01.xlsx gibi)
    usort($dosyalar, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));

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
            // Dosya adında tarih varsa (…2026-09-01… / …01.09.2026…) rapor tarihi ondan alınır
            $dt = $tarih;
            if (preg_match('/(20\d{2})[-_.](\d{2})[-_.](\d{2})/', $ad, $m))      $dt = "$m[1]-$m[2]-$m[3]";
            elseif (preg_match('/(\d{2})[-_.](\d{2})[-_.](20\d{2})/', $ad, $m))  $dt = "$m[3]-$m[2]-$m[1]";
            try {
                $rap['sonuc'] = crm_import($pdoCrm, $x, ['kapat'=>$kapat, 'rapor_tarihi'=>$dt, 'dosya'=>$ad, 'kullanici'=>$kisi]);
            } catch (Throwable $e) {
                $rap['hata'] = $e->getMessage() . ' — bu dosyadan hiçbir değişiklik uygulanmadı.';
            }
        }
        $raporlar[] = $rap;
    }
}

$ozet = crm_ozet($pdoCrm);
$gecmis = [];
try { $gecmis = $pdoCrm->query("SELECT * FROM crm_import_log ORDER BY id DESC LIMIT 15")->fetchAll(); } catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>CRM Günlük Rapor Aktarımı</h4>

<?php if ($raporlar): ?>
<?php $basarili = array_filter($raporlar, fn($r) => $r['sonuc'] !== null); ?>
<div class="alert <?= $basarili ? 'alert-success' : 'alert-danger' ?> py-2">
    <i class="bi bi-<?= $basarili ? 'check-circle' : 'x-circle' ?> me-1"></i>
    <strong><?= count($basarili) ?>/<?= count($raporlar) ?> rapor işlendi.</strong>
    Sistemde şu an <strong><?= $f0($ozet['acik']) ?></strong> açık, <?= $f0($ozet['cozuldu']) ?> çözülmüş arıza var.
    <div class="small mt-1"><a href="index.php" class="alert-link">Dashboard</a> ·
        <a href="arizalar.php?durum=acik" class="alert-link">Açık arızalar</a> ·
        <a href="raporlar.php" class="alert-link">Raporlar</a></div>
</div>

<?php foreach ($raporlar as $r): ?>
<div class="card mb-2 <?= $r['hata'] ? 'border-danger' : '' ?>">
    <div class="card-header py-2 small d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-excel <?= $r['hata'] ? 'text-danger' : 'text-success' ?>"></i>
        <strong class="text-truncate"><?= h($r['ad']) ?></strong>
        <?php if ($r['sonuc']): ?><span class="ms-auto text-muted">rapor tarihi: <?= h($r['sonuc']['rapor_tarihi']) ?></span><?php endif; ?>
    </div>
    <div class="card-body py-2 small">
        <?php if ($r['hata']): ?>
            <div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= h($r['hata']) ?></div>
        <?php else: $s = $r['sonuc']; ?>
            <div class="d-flex flex-wrap gap-3 mb-2">
                <span><span class="badge bg-secondary"><?= $f0($s['okunan']) ?></span> satır okundu</span>
                <span><span class="badge bg-danger"><?= $f0($s['yeni']) ?></span> yeni arıza</span>
                <span><span class="badge bg-primary"><?= $f0($s['guncellenen']) ?></span> güncellenen</span>
                <span><span class="badge bg-success"><?= $f0($s['kapanan']) ?></span> kapanan (çözüldü)</span>
                <?php if ($s['yenidenAcilan']): ?>
                <span><span class="badge bg-warning text-dark"><?= $f0($s['yenidenAcilan']) ?></span> yeniden açılan</span>
                <?php endif; ?>
                <?php if ($s['atlanan']): ?>
                <span><span class="badge bg-dark"><?= $f0(count($s['atlanan'])) ?></span> atlanan</span>
                <?php endif; ?>
                <?php if ($s['ikinciKayit']): ?>
                <span><span class="badge bg-info text-dark"><?= $f0($s['ikinciKayit']) ?></span> aynı kimlikte ayrı kayıt</span>
                <?php endif; ?>
            </div>
            <div class="text-muted mb-2">
                Hesap: <?= $f0($s['okunan']) ?> okunan = <?= $f0($s['yeni']) ?> yeni +
                <?= $f0($s['guncellenen']) ?> güncellenen + <?= $f0(count($s['atlanan'])) ?> atlanan
                <?php if ($s['okunan'] === $s['yeni'] + $s['guncellenen'] + count($s['atlanan'])): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Her satırın hesabı verildi"></i>
                <?php endif; ?>
            </div>
            <?php if ($s['atlanan']): ?>
            <details class="mb-2"><summary style="cursor:pointer" class="text-warning-emphasis">
                <i class="bi bi-exclamation-triangle-fill me-1"></i><strong><?= count($s['atlanan']) ?> satır kayıt açılmadan atlandı</strong>
                — sebebiyle birlikte göster</summary>
                <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered mb-0" style="font-size:.8rem">
                    <thead class="table-light"><tr><th style="width:70px">Excel satırı</th><th>Sebep</th><th>Satır içeriği</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['atlanan'] as $at): ?>
                        <tr><td><?= (int)$at['satir'] ?></td><td><?= h($at['sebep']) ?></td>
                            <td class="text-muted"><?= h($at['ozet']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($s['kapananlar']): ?>
            <details><summary style="cursor:pointer">Kapanan arızalar (raporda artık yok) — listeyi göster</summary>
                <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered mb-0" style="font-size:.8rem">
                    <thead class="table-light"><tr><th>Konut</th><th>Konu</th><th>Detay</th><th>Arıza Tipi</th><th>Açılış</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($s['kapananlar'], 0, 60) as $k): ?>
                        <tr><td><?= h($k['konut']) ?></td><td><?= h($k['sikayet_konusu']) ?></td>
                            <td><?= h($k['sikayet_aciklamasi']) ?></td><td><?= h($k['ariza_tipi']) ?></td>
                            <td><?= $k['olusturma'] ? format_date(substr($k['olusturma'],0,10)) : '—' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($s['kapananlar']) > 60): ?>
                <div class="text-muted mt-1">… ve <?= count($s['kapananlar']) - 60 ?> kayıt daha.</div>
                <?php endif; ?>
            </details>
            <?php endif; ?>
            <?php foreach ($s['uyari'] as $u): ?><div class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= h($u) ?></div><?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="crmForm">
        <div class="col-md-6">
            <label class="form-label small">CRM "UretimArizalari" raporu (.xlsx) — birden çok gün seçilebilir</label>
            <input type="file" name="dosya[]" id="crmDosya" class="form-control form-control-sm" accept=".xlsx" multiple required>
            <div id="crmSecilen" class="form-text"></div>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Rapor tarihi</label>
            <input type="date" name="rapor_tarihi" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            <div class="form-text">Dosya adında tarih varsa o kullanılır.</div>
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary btn-sm w-100" id="crmBtn"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Güncelle</button>
        </div>
        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="kapat" value="1" id="kapatChk" <?= $kapat ? 'checked' : '' ?>>
                <label class="form-check-label small" for="kapatChk">
                    <strong>Raporda görünmeyen açık arızaları ÇÖZÜLDÜ say</strong>
                    <span class="text-muted">— günlük rapor açık arızaların tam listesi olduğundan, listeden düşen arıza
                    kapanmış demektir. Rapor <em>eksik/kısmi</em> geldiyse bu kutuyu kapatın.</span>
                </label>
            </div>
        </div>
    </form>
</div></div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100"><div class="card-body small">
        <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Günlük rapor nasıl işlenir?</div>
        <table class="table table-sm table-bordered mb-2">
            <thead class="table-light"><tr><th>Durum</th><th>Sistemin yaptığı</th></tr></thead>
            <tbody>
                <tr><td>Raporda var, sistemde yok</td><td><span class="badge bg-danger">Yeni arıza</span> açılır (durum: Açık)</td></tr>
                <tr><td>Raporda var, sistemde var</td><td>Alanlar güncellenir, "son görülme" yenilenir</td></tr>
                <tr><td>Sistemde açık, raporda yok</td><td><span class="badge bg-success">Çözüldü</span> sayılır (kapanış: otomatik)</td></tr>
                <tr><td>Kapatılmıştı, raporda yine var</td><td><span class="badge bg-warning text-dark">Yeniden açılır</span> — hatalı kapanış kendini düzeltir</td></tr>
                <tr><td>Raporda Çözümlenme Tarihi dolu</td><td>Çözüldü sayılır (kapanış: excel), tarih birebir alınır</td></tr>
                <tr><td>Aynı dosyada <strong>birebir aynı</strong> satır</td><td>Tek kayıt açılır, ikincisi <span class="badge bg-dark">atlanan</span> listesinde sebebiyle görünür</td></tr>
                <tr><td>Aynı kimlik, <strong>farklı içerik</strong></td><td>Satır KAYBOLMAZ — ayrı kayıt açılır (<span class="badge bg-info text-dark">aynı kimlikte ayrı kayıt</span>)</td></tr>
                <tr><td>Konut ve şikayet konusu boş satır</td><td>Arıza satırı sayılmaz, <span class="badge bg-dark">atlanan</span> listesinde içeriğiyle gösterilir</td></tr>
            </tbody>
        </table>
        <div class="text-muted">
            Arıza kaydının CRM'de ID'si olmadığından kimlik <strong>konut + açılış anı + şikayet zinciri + açıklama</strong>dan
            üretilir ve benzersizdir; <strong>aynı dosyayı iki kez yüklemek mükerrer kayıt oluşturmaz</strong>.
            "1.01.0001" çözüm tarihi boş kabul edilir. Hata olursa o dosyanın hiçbir satırı yazılmaz (transaction).
        </div>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="fw-semibold mb-2 small"><i class="bi bi-clock-history me-1"></i>Son yüklemeler</div>
        <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.8rem">
            <thead class="table-light"><tr><th>Tarih</th><th>Dosya</th><th class="text-end">Satır</th>
                <th class="text-end">Yeni</th><th class="text-end">Kapanan</th></tr></thead>
            <tbody>
            <?php foreach ($gecmis as $g): ?>
                <tr>
                    <td><?= h(date('d.m.Y H:i', strtotime($g['created']))) ?></td>
                    <td class="text-truncate" style="max-width:150px" title="<?= h($g['dosya']) ?>"><?= h($g['dosya'] ?: '—') ?></td>
                    <td class="text-end"><?= $f0($g['satir']) ?></td>
                    <td class="text-end text-danger fw-semibold"><?= $f0($g['yeni']) ?></td>
                    <td class="text-end text-success fw-semibold"><?= $f0($g['kapanan']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$gecmis): ?><tr><td colspan="5" class="text-center text-muted py-3">Henüz rapor yüklenmedi.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div></div>
  </div>
</div>

<script>
(function(){
    var inp = document.getElementById('crmDosya'), out = document.getElementById('crmSecilen'), btn = document.getElementById('crmBtn');
    inp.addEventListener('change', function(){
        var n = [];
        for (var i = 0; i < inp.files.length; i++) n.push(inp.files[i].name);
        out.textContent = inp.files.length ? inp.files.length + ' dosya: ' + n.join(' · ') : '';
    });
    document.getElementById('crmForm').addEventListener('submit', function(){
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aktarılıyor…';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
