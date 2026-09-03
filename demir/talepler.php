<?php
/**
 * demir/talepler.php — IFS Sipariş Talepleri (Demir Siparişleri Takip Tablosu)
 *
 * Kaynak: "U030U031U039 … Demir Siparişleri Takip Tablosu.xlsx" — her sayfa BİR
 * IFS talebi: Talep No, Malzeme No (I2602-…), Malzeme Açıklaması ("İnşaat Demiri
 * Nervürlü 26 Mm" → çap buradan çıkar), Site (proje), Miktar/Teslim Alınan (kg),
 * Firma, Parsel. Sayfa adındaki tarih talep tarihi sayılır.
 *
 * Not: Buradaki "Talep No" (110307…) ile sevkiyat eşleşmesindeki "IFS Sipariş No"
 * (706589…) FARKLI numara uzaylarıdır — bu ekran sipariş bakiyesine KARIŞMAZ,
 * dosyayı birebir yansıtır (Excel tek doğru kaynak). Teslim değeri Excel'dendir.
 *
 * Özel durumlar (gerçek dosyadan): birleşik talep "111779-112123" tek sayfada iki
 * "Miktar <no>" kolonu + "Toplam" kolonu taşır → Toplam varsa o okunur. Bir sayfada
 * satırlar farklı firmalara ait olabilir (PRP + OSMAN CAMCI) → firma kalem düzeyinde.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Sipariş Talepleri — Demir Takip';

// ── Şema (runtime) ───────────────────────────────────────────────────────────
$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_talepler (
    id INT AUTO_INCREMENT PRIMARY KEY,
    talep_no VARCHAR(40) NOT NULL,
    tarih DATE NULL,
    sayfa_adi VARCHAR(150) NULL,
    firma VARCHAR(200) NULL,
    proje VARCHAR(60) NULL,
    parsel VARCHAR(30) NULL,
    statu VARCHAR(40) NULL,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_talep (talep_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_talep_kalemleri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    talep_id INT NOT NULL,
    kod VARCHAR(30) NULL,
    aciklama VARCHAR(200) NULL,
    cap_id INT NULL,
    cap_label VARCHAR(40) NULL,
    firma VARCHAR(120) NULL,
    siparis_kg DECIMAL(14,1) NOT NULL DEFAULT 0,
    teslim_kg  DECIMAL(14,1) NOT NULL DEFAULT 0,
    KEY (talep_id), KEY (cap_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Sonradan eklenen kolonlar (mevcut kurulumlarda runtime ALTER):
//   Talepte Excel'in KENDİ özet satırı (Sipariş/Teslim alınan/Fark) → içe aktarım sağlaması.
//   Kalemde Excel'in Kalan kolonu + Parsel'den sonraki serbest notlar ("25460 OSMAN CAMCI VERİLDİ").
foreach (["ALTER TABLE demir_talepler ADD COLUMN excel_siparis_kg DECIMAL(14,1) NULL,
                                     ADD COLUMN excel_teslim_kg DECIMAL(14,1) NULL,
                                     ADD COLUMN excel_fark_kg DECIMAL(14,1) NULL",
          "ALTER TABLE demir_talep_kalemleri ADD COLUMN kalan_kg DECIMAL(14,1) NULL,
                                             ADD COLUMN notlar VARCHAR(300) NULL"] as $q0) {
    try { $pdoDemir->exec($q0); } catch (Throwable $e0) {}
}

// ── İçe aktarma (tam yenileme — dosya kümülatif, tüm talepler her dosyada) ───
require_once __DIR__ . '/_talep_import.php';
$hata = null; $sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['dosya']['tmp_name'])
    && has_role('admin', 'teknik_ofis_admin')) {
    if (!($x = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        $hata = 'Excel okunamadı: ' . h((string)\Shuchkin\SimpleXLSX::parseError());
    } else {
        $tur = tlp_dosya_turu($x);
        if ($tur !== null && $tur !== 'talep') {
            // Yanlış dosya: kullanıcıyı doğru ekrana yönlendir (sessiz "0 talep" yerine açık mesaj)
            [$hedef, $hedefAd] = tlp_dosya_hedef($tur);
            $hata = 'Bu dosya bir talep dosyası değil — ' . $hedefAd . ' dosyasına benziyor. '
                  . 'Doğru ekran: <a href="' . $hedef . '" class="alert-link">' . $hedefAd . '</a>.';
        } else {
            try { $sonuc = tlp_import($pdoDemir, $x); }
            catch (Throwable $e) { $hata = 'İçe aktarma hatası: ' . h($e->getMessage()); }
        }
    }
}

// ── Liste + filtre ───────────────────────────────────────────────────────────
$fFirma = trim($_GET['firma'] ?? '');
$fProje = trim($_GET['proje'] ?? '');
$where = []; $par = [];
if ($fFirma !== '') { $where[] = "(t.firma LIKE ? OR EXISTS(SELECT 1 FROM demir_talep_kalemleri k2 WHERE k2.talep_id=t.id AND k2.firma LIKE ?))"; $par[] = "%$fFirma%"; $par[] = "%$fFirma%"; }
if ($fProje !== '') { $where[] = "t.proje LIKE ?"; $par[] = "%$fProje%"; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdoDemir->prepare("
    SELECT t.*, SUM(k.siparis_kg) sip, SUM(k.teslim_kg) tes, COUNT(k.id) kalem
    FROM demir_talepler t LEFT JOIN demir_talep_kalemleri k ON k.talep_id = t.id
    $wsql GROUP BY t.id
    ORDER BY t.tarih IS NULL, t.tarih DESC, t.talep_no DESC");
$st->execute($par);
$talepler = $st->fetchAll();

$kalemMap = [];
if ($talepler) {
    $ids = implode(',', array_map(fn($t) => (int)$t['id'], $talepler));
    foreach ($pdoDemir->query("SELECT * FROM demir_talep_kalemleri WHERE talep_id IN ($ids) ORDER BY id") as $k) {
        $kalemMap[(int)$k['talep_id']][] = $k;
    }
}

// Çap bazlı genel özet (filtreye saygılı)
$capOzet = [];
foreach ($talepler as $t) foreach ($kalemMap[(int)$t['id']] ?? [] as $k) {
    $cl = $k['cap_label'] ?: '—';
    $capOzet[$cl]['sip'] = ($capOzet[$cl]['sip'] ?? 0) + (float)$k['siparis_kg'];
    $capOzet[$cl]['tes'] = ($capOzet[$cl]['tes'] ?? 0) + (float)$k['teslim_kg'];
}
uksort($capOzet, function($a, $b) {
    $na = preg_match('/(\d+)/', $a, $m) ? (int)$m[1] : 999;
    $nb = preg_match('/(\d+)/', $b, $m) ? (int)$m[1] : 999;
    return [$a[0] === 'Q', $na] <=> [$b[0] === 'Q', $nb];
});

$topSip = 0.0; $topTes = 0.0;
foreach ($talepler as $t) { $topSip += (float)$t['sip']; $topTes += (float)$t['tes']; }
$firmaListe = $pdoDemir->query("SELECT DISTINCT firma FROM demir_talep_kalemleri WHERE firma IS NOT NULL AND firma<>'' ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN);

$fmt = fn($n, $d = 2) => number_format((float)$n, $d, ',', '.');
$ton = fn($kg) => $fmt($kg / 1000, 2);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-clipboard-data text-primary me-2"></i>Sipariş Talepleri (IFS)</h4>
        <small class="text-muted">Demir Siparişleri Takip Tablosu — talep bazlı sipariş / teslim / kalan (kg)</small>
    </div>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>
<?php if ($hata): ?><div class="alert alert-danger"><?= $hata ?></div><?php endif; ?>
<?php if ($sonuc): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>
    <strong><?= (int)$sonuc['talep'] ?></strong> talep, <strong><?= (int)$sonuc['kalem'] ?></strong> kalem aktarıldı.
    <?php if ($sonuc['atlanan']): ?><span class="small text-muted">Atlanan sayfa: <?= h(implode(' · ', $sonuc['atlanan'])) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<?php if (has_role('admin','teknik_ofis_admin')): ?>
<div class="card mb-3"><div class="card-body py-2">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label small mb-1">Demir Siparişleri Takip Tablosu (.xlsx) — her sayfa bir IFS talebi; <strong>tam yenileme</strong></label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required>
        </div>
        <div class="col-md-4"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Eşitle</button></div>
    </form>
</div></div>
<?php endif; ?>

<!-- KPI -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Talep</div><div class="fs-5 fw-bold"><?= count($talepler) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Toplam Sipariş</div><div class="fs-5 fw-bold"><?= $ton($topSip) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Teslim Alınan</div><div class="fs-5 fw-bold text-success"><?= $ton($topTes) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Kalan</div>
        <div class="fs-5 fw-bold <?= ($topSip-$topTes)>0?'text-danger':'text-success' ?>"><?= $ton($topSip-$topTes) ?> t</div></div></div></div>
</div>

<!-- Filtre -->
<form class="row g-2 mb-3">
    <div class="col-md-4">
        <select name="firma" class="form-select form-select-sm">
            <option value="">Tüm firmalar</option>
            <?php foreach ($firmaListe as $f): ?>
            <option value="<?= h($f) ?>" <?= $fFirma===$f?'selected':'' ?>><?= h($f) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3"><input name="proje" value="<?= h($fProje) ?>" class="form-control form-control-sm" placeholder="Proje (U030 / U031 / U039)"></div>
    <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrele</button></div>
    <div class="col-6 col-md-2"><a href="talepler.php" class="btn btn-outline-secondary btn-sm w-100">Temizle</a></div>
</form>

<?php if (!$talepler): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Kayıt yok — üstteki formdan Demir Siparişleri Takip Tablosu'nu yükleyin.</div>
<?php else: ?>

<!-- Çap bazlı özet -->
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-bordered align-middle mb-0 text-center" style="font-size:.8rem">
    <thead class="table-light">
        <tr><th class="text-start">Çap</th>
        <?php foreach ($capOzet as $cl => $o): ?><th><?= h($cl) ?></th><?php endforeach; ?>
        <th class="table-secondary">TOPLAM</th></tr>
    </thead>
    <tbody>
        <tr><td class="text-start fw-semibold">Sipariş (t)</td>
        <?php foreach ($capOzet as $o): ?><td><?= $ton($o['sip']) ?></td><?php endforeach; ?>
        <td class="fw-bold"><?= $ton($topSip) ?></td></tr>
        <tr><td class="text-start fw-semibold">Teslim (t)</td>
        <?php foreach ($capOzet as $o): ?><td class="text-success"><?= $ton($o['tes']) ?></td><?php endforeach; ?>
        <td class="fw-bold text-success"><?= $ton($topTes) ?></td></tr>
        <tr><td class="text-start fw-semibold">Kalan (t)</td>
        <?php foreach ($capOzet as $o): $k = $o['sip'] - $o['tes']; ?>
            <td class="<?= $k > 0.05 ? 'text-danger' : ($k < -0.05 ? 'text-primary' : 'text-muted') ?>"><?= $ton($k) ?></td>
        <?php endforeach; ?>
        <td class="fw-bold"><?= $ton($topSip - $topTes) ?></td></tr>
    </tbody>
</table>
</div></div></div>

<!-- Talep listesi -->
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Talep No</th><th>Tarih</th><th>Firma</th><th>Proje</th><th>Parsel</th>
        <th class="text-end">Sipariş (t)</th><th class="text-end">Teslim (t)</th><th class="text-end">Kalan (t)</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($talepler as $t): $kal = (float)$t['sip'] - (float)$t['tes']; ?>
        <?php
            // Excel'in kendi özet satırıyla sağlama: kalem toplamı ≠ özet ise Excel'de formül bozukluğu var demektir
            $exS = $t['excel_siparis_kg'] ?? null; $exT = $t['excel_teslim_kg'] ?? null;
            $sagla = ($exS === null && $exT === null) ? null
                   : (abs((float)$t['sip'] - (float)($exS ?? $t['sip'])) <= 1 && abs((float)$t['tes'] - (float)($exT ?? $t['tes'])) <= 1);
            $notVar = false;
            foreach ($kalemMap[(int)$t['id']] ?? [] as $k0) if (!empty($k0['notlar'])) { $notVar = true; break; }
        ?>
        <tr data-bs-toggle="collapse" data-bs-target="#tk<?= (int)$t['id'] ?>" style="cursor:pointer">
            <td class="font-monospace fw-semibold"><?= h($t['talep_no']) ?>
                <?php if ($sagla === true): ?><i class="bi bi-patch-check-fill text-success" title="Excel'in kendi özet satırıyla (Sipariş <?= $ton($exS) ?> t / Teslim <?= $ton($exT) ?> t) birebir doğrulandı"></i>
                <?php elseif ($sagla === false): ?><span class="badge bg-warning text-dark" title="Excel özet satırı (Sipariş <?= $ton($exS) ?> t / Teslim <?= $ton($exT) ?> t) kalem toplamlarıyla uyuşmuyor — Excel'de formül bozuk olabilir">özet farklı</span>
                <?php endif; ?>
                <?php if ($notVar): ?><i class="bi bi-sticky-fill text-warning" title="Bu talepte not var — açınca kalemlerde görünür"></i><?php endif; ?>
            </td>
            <td><?= h(format_date($t['tarih'])) ?></td>
            <td class="small"><?= h((string)$t['firma']) ?></td>
            <td><?= h((string)$t['proje']) ?></td>
            <td><?= h((string)$t['parsel']) ?></td>
            <td class="text-end"><?= $ton($t['sip']) ?></td>
            <td class="text-end text-success"><?= $ton($t['tes']) ?></td>
            <td class="text-end fw-semibold <?= $kal > 50 ? 'text-danger' : ($kal < -50 ? 'text-primary' : 'text-muted') ?>"><?= $ton($kal) ?></td>
            <td class="text-end text-muted small"><?= (int)$t['kalem'] ?> kalem <i class="bi bi-chevron-down"></i></td>
        </tr>
        <tr class="collapse" id="tk<?= (int)$t['id'] ?>">
            <td colspan="9" class="bg-body-tertiary p-2">
                <table class="table table-sm table-bordered bg-body mb-0" style="max-width:760px;font-size:.8rem">
                    <thead class="table-light"><tr>
                        <th>Çap</th><th>Malzeme Kodu</th><th>Firma</th>
                        <th class="text-end">Sipariş (kg)</th><th class="text-end">Teslim (kg)</th><th class="text-end">Kalan (kg)</th><th>Not</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($kalemMap[(int)$t['id']] ?? [] as $k): $kk = (float)$k['siparis_kg'] - (float)$k['teslim_kg']; ?>
                        <tr>
                            <td class="fw-semibold"><?= h($k['cap_label'] ?: '—') ?></td>
                            <td class="font-monospace small"><?= h((string)$k['kod']) ?></td>
                            <td class="small"><?= h((string)$k['firma']) ?></td>
                            <td class="text-end"><?= $fmt($k['siparis_kg'], 0) ?></td>
                            <td class="text-end text-success"><?= $fmt($k['teslim_kg'], 0) ?></td>
                            <td class="text-end <?= $kk > 0 ? 'text-danger' : ($kk < 0 ? 'text-primary' : 'text-muted') ?>"><?= $fmt($kk, 0) ?></td>
                            <td class="small"><?= !empty($k['notlar']) ? '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">' . h($k['notlar']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div></div>
<div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>
    Kalan = Sipariş − Teslim. <span class="text-danger">Kırmızı</span> = eksik teslim,
    <span class="text-primary">mavi</span> = sipariş üstü teslim (fazla gelen). Değerler Excel'deki IFS çıktısından birebirdir.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
