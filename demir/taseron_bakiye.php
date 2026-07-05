<?php
/**
 * demir/taseron_bakiye.php — Taşeron demir bakiyesi
 * Net Elinde = Teslim Alınan (tutanak) + Devraldı (başka taşeronun iadesi)
 *              − İade Etti − Hurda Satışı (çaptan bağımsız)
 * Hurda: iş sonunda firmaya teslim edilen demirin hurda olarak satılan kısmı;
 * tonaj bazında, çaptan bağımsız düşülür (tablo: demir_hurda).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';
iade_semasi_kur($pdoDemir);

$pageTitle = 'Taşeron Bakiye — Demir Takip';
$canEdit = has_role('admin','teknik_ofis_admin','teknik_ofis','saha_sefi');

// Hurda tablosu (çaptan bağımsız tonaj düşümü)
$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_hurda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hurda_no VARCHAR(50) NULL,
    taseron_id INT NOT NULL,
    tarih DATE NULL,
    miktar_ton DECIMAL(12,3) NOT NULL DEFAULT 0,
    aciklama VARCHAR(300) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (taseron_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Eski tabloya hurda_no / evrak_url ekle
if (!$pdoDemir->query("SHOW COLUMNS FROM demir_hurda LIKE 'hurda_no'")->fetchColumn()) {
    $pdoDemir->exec("ALTER TABLE demir_hurda ADD COLUMN hurda_no VARCHAR(50) NULL AFTER id");
}
if (!$pdoDemir->query("SHOW COLUMNS FROM demir_hurda LIKE 'evrak_url'")->fetchColumn()) {
    $pdoDemir->exec("ALTER TABLE demir_hurda ADD COLUMN evrak_url VARCHAR(500) NULL");
}

// ── Hurda kaydet (ekle/düzenle) ───────────────────────────────────────────────
if ($canEdit && ($_POST['action'] ?? '') === 'hurda_kaydet') {
    $hid = ctype_digit($_POST['id'] ?? '') ? (int)$_POST['id'] : 0;
    $tas = ctype_digit($_POST['taseron_id'] ?? '') ? (int)$_POST['taseron_id'] : 0;
    $mik = iade_num($_POST['miktar'] ?? '');
    if (!$tas || $mik === null || $mik <= 0) {
        flash('error', 'Taşeron ve pozitif miktar zorunludur.');
    } else {
        $d = [$tas, ($_POST['tarih'] ?? '') ?: null, $mik, trim($_POST['aciklama'] ?? '') ?: null];
        if ($hid) {
            $d[] = $hid;
            $pdoDemir->prepare("UPDATE demir_hurda SET taseron_id=?, tarih=?, miktar_ton=?, aciklama=? WHERE id=?")->execute($d);
            flash('success', 'Hurda kaydı güncellendi.');
        } else {
            $tk = $pdoDemir->prepare("SELECT kod FROM demir_taseronlar WHERE id=?"); $tk->execute([$tas]);
            $hurdaNo = hurda_no_uret($pdoDemir, (string)$tk->fetchColumn());
            array_unshift($d, $hurdaNo);
            $d[] = current_user_id();
            $pdoDemir->prepare("INSERT INTO demir_hurda (hurda_no, taseron_id, tarih, miktar_ton, aciklama, created_by) VALUES (?,?,?,?,?,?)")->execute($d);
            flash('success', "Hurda satışı kaydedildi: {$hurdaNo} (bakiyeden düşüldü). Tutanağı yazdırıp imzalatabilirsiniz.");
        }
    }
    redirect('taseron_bakiye.php');
}
// ── Hurda sil (evrak dosyası da temizlenir) ───────────────────────────────────
if (has_role('admin','teknik_ofis_admin') && isset($_GET['hurda_sil']) && ctype_digit($_GET['hurda_sil'])) {
    $hid = (int)$_GET['hurda_sil'];
    $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_hurda WHERE id=?"); $ev->execute([$hid]);
    if ($u = $ev->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
    $pdoDemir->prepare("DELETE FROM demir_hurda WHERE id=?")->execute([$hid]);
    flash('success', 'Hurda kaydı silindi.');
    redirect('taseron_bakiye.php');
}
// ── Hurda evrak sil ───────────────────────────────────────────────────────────
if ($canEdit && isset($_GET['hurda_evrak_sil']) && ctype_digit($_GET['hurda_evrak_sil'])) {
    $hid = (int)$_GET['hurda_evrak_sil'];
    $ev = $pdoDemir->prepare("SELECT evrak_url FROM demir_hurda WHERE id=?"); $ev->execute([$hid]);
    if ($u = $ev->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
    $pdoDemir->prepare("UPDATE demir_hurda SET evrak_url=NULL WHERE id=?")->execute([$hid]);
    flash('success', 'İmzalı evrak kaldırıldı.');
    redirect('taseron_bakiye.php');
}
// ── Hurda evrak yükle (imzalı tutanak) ────────────────────────────────────────
if ($canEdit && ($_POST['action'] ?? '') === 'hurda_evrak' && ctype_digit($_POST['id'] ?? '')
    && isset($_FILES['evrak']) && $_FILES['evrak']['error']===UPLOAD_ERR_OK) {
    $hid = (int)$_POST['id'];
    $rw = $pdoDemir->prepare("SELECT hurda_no, evrak_url FROM demir_hurda WHERE id=?"); $rw->execute([$hid]); $rw = $rw->fetch();
    $mime = mime_content_type($_FILES['evrak']['tmp_name']);
    $izin = ['application/pdf','image/jpeg','image/png','image/webp'];
    if (!$rw) {
        flash('error', 'Hurda kaydı bulunamadı.');
    } elseif (!in_array($mime, $izin, true)) {
        flash('error', 'Sadece PDF, JPG, PNG, WebP yüklenebilir.');
    } elseif ($_FILES['evrak']['size'] > 15*1024*1024) {
        flash('error', 'Dosya çok büyük (maks 15 MB).');
    } else {
        $dir = __DIR__ . '/../uploads/demir_hurda/' . $hid . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = pathinfo($_FILES['evrak']['name'], PATHINFO_EXTENSION) ?: ($mime==='application/pdf'?'pdf':'jpg');
        $ad  = 'imzali_' . date('Ymd_His') . '.' . strtolower($ext);
        if (move_uploaded_file($_FILES['evrak']['tmp_name'], $dir.$ad)) {
            if ($rw['evrak_url']) @unlink(__DIR__ . '/../' . $rw['evrak_url']); // eskiyi temizle
            $url = 'uploads/demir_hurda/' . $hid . '/' . $ad;
            $pdoDemir->prepare("UPDATE demir_hurda SET evrak_url=? WHERE id=?")->execute([$url, $hid]);
            flash('success', 'İmzalı hurda tutanağı yüklendi' . ($rw['hurda_no'] ? ' ('.$rw['hurda_no'].')' : '') . '.');
        } else { flash('error', 'Dosya yüklenemedi.'); }
    }
    redirect('taseron_bakiye.php');
}

$taseronlar = $pdoDemir->query("SELECT id, ad, kod FROM demir_taseronlar ORDER BY ad")->fetchAll();
$caplar     = $pdoDemir->query("SELECT id, ad FROM demir_caplar ORDER BY sira, ad")->fetchAll();
$capAd = []; foreach ($caplar as $c) $capAd[$c['id']] = $c['ad'];
$capAd[-1] = '(çap belirsiz)';

// bak[taseron_id][cap_id] = ['teslim'=>,'iade'=>,'devir'=>]
$bak = [];
$ekle = function($tid,$cid,$alan,$ton) use (&$bak){
    if (!$tid || !$cid) return;
    if (!isset($bak[$tid][$cid])) $bak[$tid][$cid] = ['teslim'=>0.0,'iade'=>0.0,'devir'=>0.0];
    $bak[$tid][$cid][$alan] += (float)$ton;
};

// Teslim alınan (taşerona teslim tutanakları)
foreach ($pdoDemir->query("
    SELECT tu.taseron_id AS tid, tk.cap_id AS cid, SUM(tk.miktar_ton) AS ton
    FROM demir_tutanak_kalemleri tk JOIN demir_tutanaklar tu ON tu.id = tk.tutanak_id
    WHERE tu.taseron_id IS NOT NULL GROUP BY tu.taseron_id, tk.cap_id") as $r) {
    $ekle($r['tid'], $r['cid'], 'teslim', $r['ton']);
}
// İade ettiği (iade eden olarak)
foreach ($pdoDemir->query("
    SELECT iu.iade_eden_id AS tid, ik.cap_id AS cid, SUM(ik.miktar_ton) AS ton
    FROM demir_iade_kalemleri ik JOIN demir_iade_tutanaklar iu ON iu.id = ik.iade_id
    WHERE iu.iade_eden_id IS NOT NULL GROUP BY iu.iade_eden_id, ik.cap_id") as $r) {
    $ekle($r['tid'], $r['cid'], 'iade', $r['ton']);
}
// Devraldığı (başka taşeronun iadesini teslim alan olarak)
foreach ($pdoDemir->query("
    SELECT iu.teslim_alan_id AS tid, ik.cap_id AS cid, SUM(ik.miktar_ton) AS ton
    FROM demir_iade_kalemleri ik JOIN demir_iade_tutanaklar iu ON iu.id = ik.iade_id
    WHERE iu.teslim_alan_id IS NOT NULL GROUP BY iu.teslim_alan_id, ik.cap_id") as $r) {
    $ekle($r['tid'], $r['cid'], 'devir', $r['ton']);
}

// ── Tutanak Takip defteri (Excel) hareketleri — firmaların gerçek teslim/iade toplamı ──
// Uygulamada aynı tutanak_no ile oluşturulmuş tutanak varsa o satır atlanır (çift sayım olmaz).
try {
    $norm = function($s){ $s=mb_strtoupper(trim((string)$s),'UTF-8'); return str_replace(['İ','I','ı','i'],'I',$s); };
    $mevcutTutNo = [];
    foreach ($pdoDemir->query("SELECT DISTINCT tutanak_no FROM demir_tutanaklar WHERE tutanak_no IS NOT NULL") as $r) {
        $mevcutTutNo[$norm($r['tutanak_no'])] = 1;
    }
    $tasByAd = [];
    foreach ($taseronlar as $t) $tasByAd[$norm($t['ad'])] = (int)$t['id'];
    // Çap eşleyici (sayı + tip)
    $capDb2 = $pdoDemir->query("SELECT id, ad, tip FROM demir_caplar")->fetchAll();
    $capBul = function($label) use ($capDb2, $norm) {
        $u = $norm($label);
        if ($u === '') return 0;
        preg_match('/(\d+)/', $u, $m); $num = isset($m[1]) ? (int)$m[1] : 0;
        $tip = mb_stripos($u,'KANGAL')!==false ? 'kangal'
             : (strpos($u,'/')!==false ? 'hasir'
             : (mb_stripos($u,'SPIRAL')!==false || mb_stripos($u,'SPİRAL')!==false ? 'spiral' : 'duz'));
        foreach ($capDb2 as $c){ preg_match('/(\d+)/',$c['ad'],$cm); if ((isset($cm[1])?(int)$cm[1]:0)===$num && $c['tip']===$tip) return (int)$c['id']; }
        foreach ($capDb2 as $c){ preg_match('/(\d+)/',$c['ad'],$cm); if ((isset($cm[1])?(int)$cm[1]:0)===$num) return (int)$c['id']; }
        return 0;
    };
    foreach ($pdoDemir->query("SELECT firma, tip, tutanak_no, irsaliye_no, cap_label, miktar_ton FROM demir_tutanak_takip") as $r) {
        $tid = $tasByAd[$norm($r['firma'])] ?? null;
        if (!$tid) continue;
        $tn = trim((string)$r['tutanak_no']);
        if ($tn !== '' && isset($mevcutTutNo[$norm($tn)])) continue; // uygulama tutanağı var → çift sayma
        $cid = $capBul($r['cap_label'] ?? '');
        $mik = (float)$r['miktar_ton'];
        if ($r['tip'] === 'teslim') {
            // Kaynağı başka bir firma olan teslimler (irsaliye alanı "DENER U030", "PRP U031" gibi) = taşerondan DEVİR
            // (ör. ana firma YILDIZLAR'ın taşeronlardan iade olarak teslim aldığı demir).
            $alan = 'teslim';
            $irsN = $norm($r['irsaliye_no'] ?? '');
            $tok  = strtok($irsN, ' ');
            if ($tok !== false && mb_strlen($tok) >= 3) {
                foreach ($tasByAd as $adN => $idX) {
                    if ($idX !== $tid && $adN !== '' && (strpos($irsN, $adN) === 0 || strpos($adN, $tok) === 0)) { $alan = 'devir'; break; }
                }
            }
            $ekle($tid, $cid === 0 ? -1 : $cid, $alan, $mik);
        } else {
            $ekle($tid, $cid === 0 ? -1 : $cid, 'iade', abs($mik)); // defterde iade negatif tutulur
        }
    }
} catch (Throwable $e) { /* demir_tutanak_takip yoksa sessiz geç */ }
// Hurda satışları (çaptan bağımsız — taşeron bazında toplam)
$hurdaByTas = [];
foreach ($pdoDemir->query("SELECT taseron_id, SUM(miktar_ton) ton FROM demir_hurda GROUP BY taseron_id") as $r) {
    $hurdaByTas[(int)$r['taseron_id']] = (float)$r['ton'];
}
// Hurda kayıt listesi
$hurdaListe = $pdoDemir->query("
    SELECT hd.*, t.ad AS taseron_adi FROM demir_hurda hd
    LEFT JOIN demir_taseronlar t ON t.id = hd.taseron_id
    ORDER BY hd.tarih DESC, hd.id DESC")->fetchAll();

// Taşeron başına toplamlar
$satirlar = [];
foreach ($taseronlar as $t) {
    $caps  = $bak[$t['id']] ?? [];
    $hurda = $hurdaByTas[$t['id']] ?? 0.0;
    if (!$caps && $hurda == 0.0) continue;
    $tT=0;$tI=0;$tD=0;
    foreach ($caps as $v){ $tT+=$v['teslim']; $tI+=$v['iade']; $tD+=$v['devir']; }
    $satirlar[] = ['t'=>$t, 'teslim'=>$tT, 'iade'=>$tI, 'devir'=>$tD, 'hurda'=>$hurda,
                   'net'=>$tT+$tD-$tI-$hurda, 'caps'=>$caps];
}
$gT=array_sum(array_column($satirlar,'teslim'));
$gI=array_sum(array_column($satirlar,'iade'));
$gD=array_sum(array_column($satirlar,'devir'));
$gH=array_sum(array_column($satirlar,'hurda'));
$gN=array_sum(array_column($satirlar,'net'));

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-wallet2 text-dark me-2"></i>Taşeron Bakiye</h4>
        <small class="text-muted">Net Elinde = Teslim Alınan + Devraldığı − İade Ettiği − Hurda Satışı</small>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?><button class="btn btn-dark" id="btnHurda"><i class="bi bi-recycle me-1"></i> Hurda Satışı Ekle</button><?php endif; ?>
        <a href="iade_tutanaklar.php" class="btn btn-outline-dark"><i class="bi bi-arrow-return-left me-1"></i> İade Tutanakları</a>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Toplam Teslim Alınan</div><div class="fs-4 fw-bold"><?= $fmt($gT) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Taşeronlar Arası Devir</div><div class="fs-4 fw-bold"><?= $fmt($gD) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Toplam İade</div><div class="fs-4 fw-bold text-danger"><?= $fmt($gI) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Hurda Satışı</div><div class="fs-4 fw-bold text-warning"><?= $fmt($gH) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
    <div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small">Net Taşeronlarda</div><div class="fs-4 fw-bold text-success"><?= $fmt($gN) ?> <span class="fs-6 text-muted">t</span></div></div></div></div>
</div>

<div class="card mb-3"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th style="width:40px"></th><th>Taşeron</th>
            <th class="text-end">Teslim Alınan (t)</th><th class="text-end">Devraldığı (t)</th>
            <th class="text-end">İade Ettiği (t)</th><th class="text-end">Hurda (t)</th><th class="text-end">Net Elinde (t)</th>
        </tr></thead>
        <tbody>
            <?php foreach ($satirlar as $idx=>$s): ?>
            <tr>
                <td><button class="btn btn-xs btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#d<?= $idx ?>" title="Çap dağılımı"><i class="bi bi-chevron-down"></i></button></td>
                <td class="fw-semibold"><?= h($s['t']['ad']) ?><?= $s['t']['kod']?' <span class="text-muted small">('.h($s['t']['kod']).')</span>':'' ?></td>
                <td class="text-end"><?= $fmt($s['teslim']) ?></td>
                <td class="text-end"><?= $s['devir']>0?'<span class="text-success">+'.$fmt($s['devir']).'</span>':'—' ?></td>
                <td class="text-end"><?= $s['iade']>0?'<span class="text-danger">−'.$fmt($s['iade']).'</span>':'—' ?></td>
                <td class="text-end"><?= $s['hurda']>0?'<span class="text-warning">−'.$fmt($s['hurda']).'</span>':'—' ?></td>
                <td class="text-end fw-bold"><?= $fmt($s['net']) ?></td>
            </tr>
            <tr class="collapse" id="d<?= $idx ?>">
                <td></td>
                <td colspan="6" class="p-0">
                    <table class="table table-sm mb-0 bg-light">
                        <thead><tr class="small text-muted"><th>Çap</th><th class="text-end">Teslim</th><th class="text-end">Devir</th><th class="text-end">İade</th><th class="text-end">Net (çap)</th></tr></thead>
                        <tbody>
                        <?php foreach ($s['caps'] as $cid=>$v): $net=$v['teslim']+$v['devir']-$v['iade']; ?>
                            <tr>
                                <td><?= h($capAd[$cid] ?? ('#'.$cid)) ?></td>
                                <td class="text-end"><?= $fmt($v['teslim']) ?></td>
                                <td class="text-end"><?= $v['devir']>0?$fmt($v['devir']):'—' ?></td>
                                <td class="text-end"><?= $v['iade']>0?$fmt($v['iade']):'—' ?></td>
                                <td class="text-end fw-semibold"><?= $fmt($net) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($s['hurda']>0): ?>
                            <tr class="table-warning"><td colspan="4" class="text-end small">Hurda satışı (çaptan bağımsız düşüm)</td><td class="text-end fw-semibold">−<?= $fmt($s['hurda']) ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$satirlar): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">
                <i class="bi bi-wallet2 fs-1 d-block mb-2 opacity-50"></i>
                Henüz taşerona teslim tutanağı, iade veya hurda kaydı yok.
            </td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($satirlar): ?>
        <tfoot class="table-light fw-bold"><tr>
            <td></td><td class="text-end">TOPLAM</td>
            <td class="text-end"><?= $fmt($gT) ?></td><td class="text-end"><?= $fmt($gD) ?></td>
            <td class="text-end text-danger"><?= $fmt($gI) ?></td><td class="text-end text-warning"><?= $fmt($gH) ?></td><td class="text-end"><?= $fmt($gN) ?></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
</div></div></div>

<!-- Hurda satış kayıtları -->
<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-recycle text-warning me-1"></i> Hurda Satış Kayıtları (<?= count($hurdaListe) ?>)</div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:360px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Tutanak No</th><th>Taşeron</th><th>Tarih</th><th class="text-end">Miktar (t)</th><th>Açıklama</th><th class="text-center">İmzalı Evrak</th><th class="text-end">İşlem</th></tr></thead>
            <tbody>
            <?php foreach ($hurdaListe as $hd): ?>
                <tr>
                    <td class="font-monospace small fw-semibold"><?= h($hd['hurda_no'] ?: '—') ?></td>
                    <td class="fw-semibold"><?= h($hd['taseron_adi'] ?: '—') ?></td>
                    <td class="text-nowrap"><?= format_date($hd['tarih']) ?></td>
                    <td class="text-end fw-semibold text-warning">−<?= $fmt($hd['miktar_ton']) ?></td>
                    <td class="small"><?= h($hd['aciklama'] ?: '—') ?></td>
                    <td class="text-center">
                        <?php if ($hd['evrak_url']): ?>
                            <a href="<?= h($rootPath.$hd['evrak_url']) ?>" target="_blank" class="badge bg-success text-decoration-none" title="İmzalı evrağı aç"><i class="bi bi-paperclip"></i> Var</a>
                        <?php elseif ($canEdit): ?>
                            <button class="btn btn-xs btn-outline-secondary btn-hurda-evrak" data-id="<?= $hd['id'] ?>" data-no="<?= h($hd['hurda_no'] ?: '') ?>" title="İmzalı tutanağı yükle"><i class="bi bi-upload"></i></button>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="hurda_pdf.php?id=<?= $hd['id'] ?>" target="_blank" class="btn btn-xs btn-outline-dark" title="Tutanak Yazdır / PDF (imza için)"><i class="bi bi-printer"></i></a>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-xs btn-outline-primary btn-hurda-duzenle" data-json='<?= h(json_encode($hd, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button>
                        <?php if ($hd['evrak_url']): ?><a href="taseron_bakiye.php?hurda_evrak_sil=<?= $hd['id'] ?>" class="btn btn-xs btn-outline-warning" title="Evrağı kaldır" onclick="return confirm('İmzalı evrak kaldırılsın mı?')"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                        <?php endif; ?>
                        <?php if (has_role('admin','teknik_ofis_admin')): ?>
                        <a href="taseron_bakiye.php?hurda_sil=<?= $hd['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Bu hurda kaydı silinsin mi? (Bakiye geri yükselir)')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$hurdaListe): ?><tr><td colspan="7" class="text-center text-muted py-4">Henüz hurda satışı kaydı yok.<?= $canEdit?' "Hurda Satışı Ekle" ile kaydedin.':'' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<p class="text-muted small mt-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Teslim Alınan</strong>: taşerona kesilen teslim tutanakları + <strong>Tutanak Takip defterindeki</strong>
    (Excel) teslim hareketleri (aynı tutanak no uygulamada da varsa çift sayılmaz).
    <strong>Devraldığı</strong>: başka taşeronun iade edip bu taşerona aktardığı demir.
    <strong>İade Ettiği</strong>: depoya/başka taşerona iade + defterdeki iade hareketleri.
    <strong>Hurda</strong>: iş sonunda hurda olarak satılan demir — tonaj bazında, <strong>çaptan bağımsız</strong> düşülür.
</p>

<?php if ($canEdit): ?>
<!-- Hurda ekle/düzenle modal -->
<div class="modal fade" id="hurdaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="action" value="hurda_kaydet">
      <input type="hidden" name="id" id="h_id">
      <div class="modal-header"><h5 class="modal-title" id="h_baslik"><i class="bi bi-recycle me-1"></i>Hurda Satışı</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-7"><label class="form-label small">Taşeron (firma) <span class="text-danger">*</span></label>
            <select name="taseron_id" id="h_tas" class="form-select form-select-sm" required><option value="">—</option>
            <?php foreach($taseronlar as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['ad']) ?><?= $t['kod']?' ('.h($t['kod']).')':'' ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-5"><label class="form-label small">Tarih</label><input name="tarih" id="h_tarih" type="date" class="form-control form-control-sm"></div>
          <div class="col-md-5"><label class="form-label small">Miktar (t) <span class="text-danger">*</span></label><input name="miktar" id="h_mik" type="text" inputmode="decimal" class="form-control form-control-sm" required placeholder="ör. 5 veya 2,350"></div>
          <div class="col-md-7"><label class="form-label small">Açıklama</label><input name="aciklama" id="h_acik" class="form-control form-control-sm" placeholder="ör. iş sonu hurda satışı — kantar fişi no"></div>
        </div>
        <div class="alert alert-warning small py-2 px-3 mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Bu miktar, seçilen firmanın <strong>Net Elinde</strong> bakiyesinden <strong>çaptan bağımsız</strong> olarak düşülür.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button><button class="btn btn-success"><i class="bi bi-save me-1"></i>Kaydet</button></div>
    </form>
  </div></div>
</div>

<!-- Hurda imzalı evrak yükle modal (sürükle-bırak) -->
<div class="modal fade" id="hurdaEvrakModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" enctype="multipart/form-data" class="dz-form">
      <input type="hidden" name="action" value="hurda_evrak">
      <input type="hidden" name="id" id="he_id">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-paperclip me-1"></i>İmzalı Hurda Tutanağı Yükle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div id="he_scope" class="alert alert-info py-2 px-3 small mb-2 d-none"></div>
        <label class="dz-zone d-flex flex-column align-items-center justify-content-center text-center p-4 border border-2 rounded" style="cursor:pointer;background:#f8f9fa;border-style:dashed!important">
            <i class="bi bi-cloud-arrow-up fs-2 text-secondary"></i>
            <span class="fw-semibold mt-1">Taranmış imzalı tutanağı buraya sürükleyin ya da tıklayın</span>
            <span class="small text-muted dz-name">PDF, JPG, PNG — maks 15 MB</span>
            <input type="file" name="evrak" accept="application/pdf,image/*" class="d-none dz-input" required>
        </label>
      </div>
      <div class="modal-footer"><button class="btn btn-primary w-100 dz-submit" disabled><i class="bi bi-upload me-1"></i>Yükle</button></div>
    </form>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(document.getElementById('hurdaModal'));
    var evrakModal = new bootstrap.Modal(document.getElementById('hurdaEvrakModal'));
    function set(id,v){ document.getElementById(id).value = (v===null||v===undefined)?'':v; }
    var btn = document.getElementById('btnHurda');
    if (btn) btn.addEventListener('click', function(){
        document.getElementById('h_baslik').innerHTML = '<i class="bi bi-recycle me-1"></i>Hurda Satışı Ekle';
        set('h_id',''); set('h_tas',''); set('h_tarih',''); set('h_mik',''); set('h_acik','');
        modal.show();
    });
    document.querySelectorAll('.btn-hurda-duzenle').forEach(function(b){
        b.addEventListener('click', function(){
            var r = JSON.parse(this.getAttribute('data-json'));
            document.getElementById('h_baslik').innerHTML = '<i class="bi bi-recycle me-1"></i>Hurda Kaydı Düzenle';
            set('h_id',r.id); set('h_tas',r.taseron_id); set('h_tarih',r.tarih); set('h_mik',r.miktar_ton); set('h_acik',r.aciklama);
            modal.show();
        });
    });
    // İmzalı evrak yükleme (sürükle-bırak)
    document.querySelectorAll('.btn-hurda-evrak').forEach(function(b){
        b.addEventListener('click', function(){
            set('he_id', this.getAttribute('data-id'));
            var no = this.getAttribute('data-no') || '';
            var scope = document.getElementById('he_scope');
            if (no) { scope.innerHTML = '<i class="bi bi-file-earmark-check me-1"></i><strong>' + no + '</strong> numaralı hurda tutanağının imzalı hali yüklenecek.'; scope.classList.remove('d-none'); }
            else { scope.classList.add('d-none'); }
            evrakModal.show();
        });
    });
    document.querySelectorAll('#hurdaEvrakModal .dz-form').forEach(function(form){
        var zone = form.querySelector('.dz-zone'), input = form.querySelector('.dz-input'),
            name = form.querySelector('.dz-name'), submit = form.querySelector('.dz-submit');
        if (!zone || !input) return;
        function refresh(){
            if (input.files && input.files.length){
                if (name) name.textContent = input.files[0].name;
                zone.style.borderColor = '#198754'; zone.style.background = '#eafaf1';
                if (submit) submit.disabled = false;
            }
        }
        input.addEventListener('change', refresh);
        ['dragenter','dragover'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.borderColor='#0d6efd'; zone.style.background='#e7f1ff'; }); });
        ['dragleave','dragend'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); zone.style.borderColor=''; zone.style.background='#f8f9fa'; }); });
        zone.addEventListener('drop', function(e){
            e.preventDefault(); e.stopPropagation();
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){ input.files = e.dataTransfer.files; refresh(); }
        });
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
