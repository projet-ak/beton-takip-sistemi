<?php
/**
 * demir/index.php — Demir Takip Dashboard (Genel Bakış)
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_firma_teslim.php';

$pageTitle = 'Demir Takip — Genel Bakış';

$kurulu = true;
$q = fn($sql) => $GLOBALS['pdoDemir']->query($sql);
$tryScalar = function($sql, $def=0) use ($pdoDemir) {
    try { return $pdoDemir->query($sql)->fetchColumn(); } catch (Throwable $e) { return $def; }
};

try {
    $capSayi   = (int)$q("SELECT COUNT(*) FROM demir_caplar")->fetchColumn();
    $tedSayi   = (int)$q("SELECT COUNT(*) FROM demir_tedarikciler")->fetchColumn();
    $tasSayi   = (int)$q("SELECT COUNT(*) FROM demir_taseronlar")->fetchColumn();
    $sevkSayi  = (int)$q("SELECT COUNT(*) FROM demir_sevkiyatlar")->fetchColumn();
    $gelenTon  = (float)$q("SELECT COALESCE(SUM(irsaliye_miktar),0) FROM demir_sevkiyat_kalemleri")->fetchColumn();
    $kantarTon = (float)$q("SELECT COALESCE(SUM(kantar_miktar),0) FROM demir_sevkiyat_kalemleri")->fetchColumn();
    $fark      = $kantarTon - $gelenTon;

    // Sipariş bakiye (ifs_siparis_no eşleşmesi)
    $orderTon  = (float)$tryScalar("SELECT COALESCE(SUM(miktar_ton),0) FROM demir_siparis_kalemleri");
    $siparisGelen = (float)$tryScalar("SELECT COALESCE(SUM(sk.irsaliye_miktar),0)
        FROM demir_sevkiyat_kalemleri sk JOIN demir_sevkiyatlar s ON s.id=sk.sevkiyat_id
        WHERE s.ifs_siparis_no IS NOT NULL AND s.ifs_siparis_no<>''
          AND s.ifs_siparis_no IN (SELECT ifs_siparis_no FROM demir_siparisler)");
    $siparisKalan = max(0, $orderTon - $siparisGelen);
    $sipSayi   = (int)$tryScalar("SELECT COUNT(*) FROM demir_siparisler");

    // Tutanak / iade / taşeron net
    $tutSayi   = (int)$tryScalar("SELECT COUNT(*) FROM demir_tutanaklar");
    $teslimTon = (float)$tryScalar("SELECT COALESCE(SUM(miktar_ton),0) FROM demir_tutanak_kalemleri");
    $iadeSayi  = (int)$tryScalar("SELECT COUNT(*) FROM demir_iade_tutanaklar");
    $iadeTon   = (float)$tryScalar("SELECT COALESCE(SUM(miktar_ton),0) FROM demir_iade_kalemleri");
    $hurdaTon  = (float)$tryScalar("SELECT COALESCE(SUM(miktar_ton),0) FROM demir_hurda");
    $taseronNet = $teslimTon - $iadeTon - $hurdaTon;

    // Çap bazında gelen
    $capDagilim = $q("
        SELECT c.ad, COALESCE(SUM(sk.irsaliye_miktar),0) AS ton
        FROM demir_caplar c LEFT JOIN demir_sevkiyat_kalemleri sk ON sk.cap_id = c.id
        GROUP BY c.id ORDER BY c.sira")->fetchAll();

    // Proje bazında gelen
    $projeDagilim = $q("
        SELECT COALESCE(p.kod,'(proje yok)') AS kod, COALESCE(SUM(sk.irsaliye_miktar),0) AS ton
        FROM demir_sevkiyatlar s
        JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
        LEFT JOIN demir_projeler p ON p.id = s.proje_id
        GROUP BY p.id HAVING ton > 0 ORDER BY ton DESC")->fetchAll();

    // Aylık gelen demir
    $aylik = $q("
        SELECT DATE_FORMAT(s.irsaliye_tarih,'%Y-%m') AS ay, COALESCE(SUM(sk.irsaliye_miktar),0) AS ton
        FROM demir_sevkiyatlar s JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
        WHERE s.irsaliye_tarih IS NOT NULL
        GROUP BY ay ORDER BY ay")->fetchAll();

    // Son sevkiyatlar
    $sonSevk = $q("
        SELECT s.id, s.irsaliye_no, s.irsaliye_tarih, t.ad AS tedarikci, p.kod AS proje,
               COALESCE(SUM(sk.irsaliye_miktar),0) AS ton
        FROM demir_sevkiyatlar s
        LEFT JOIN demir_sevkiyat_kalemleri sk ON sk.sevkiyat_id = s.id
        LEFT JOIN demir_tedarikciler t ON t.id = s.tedarikci_id
        LEFT JOIN demir_projeler p ON p.id = s.proje_id
        GROUP BY s.id ORDER BY s.irsaliye_tarih DESC, s.id DESC LIMIT 8")->fetchAll();

    // Sipariş Talepleri (IFS) + mutabakat özeti — tablolar runtime oluşur, yoksa gizle
    $talepOzet = null;
    try {
        $to = $pdoDemir->query("SELECT COUNT(DISTINCT t.id) adet,
                                       COALESCE(SUM(k.siparis_kg),0)/1000 sip,
                                       COALESCE(SUM(k.teslim_kg),0)/1000 tes
                                FROM demir_talepler t
                                LEFT JOIN demir_talep_kalemleri k ON k.talep_id = t.id")->fetch();
        if ($to && (int)$to['adet'] > 0) {
            // Mutabakat: çap bazında talep teslimi ↔ saha irsaliyesi (tolerans 0,5 t veya %1)
            $mtTalep = []; $mtSaha = [];
            foreach ($pdoDemir->query("SELECT COALESCE(c.ad, k.cap_label, '—') cl, SUM(k.teslim_kg)/1000 tes
                                       FROM demir_talep_kalemleri k LEFT JOIN demir_caplar c ON c.id = k.cap_id
                                       GROUP BY cl") as $r) $mtTalep[$r['cl']] = (float)$r['tes'];
            foreach ($pdoDemir->query("SELECT COALESCE(c.ad,'—') cl, SUM(sk.irsaliye_miktar) irs
                                       FROM demir_sevkiyat_kalemleri sk LEFT JOIN demir_caplar c ON c.id = sk.cap_id
                                       GROUP BY cl") as $r) $mtSaha[$r['cl']] = (float)$r['irs'];
            $uyumsuz = 0;
            foreach (array_unique(array_merge(array_keys($mtTalep), array_keys($mtSaha))) as $cl) {
                $t2 = $mtTalep[$cl] ?? 0.0; $s2 = $mtSaha[$cl] ?? 0.0;
                if (abs($t2 - $s2) > max(0.5, 0.01 * max($t2, $s2))) $uyumsuz++;
            }
            $talepOzet = ['adet' => (int)$to['adet'], 'sip' => (float)$to['sip'],
                          'tes' => (float)$to['tes'], 'uyumsuz' => $uyumsuz];
        }
    } catch (Throwable $e) { $talepOzet = null; }

    // Firma bazlı teslim matrisi (proje × çap)
    $ftm = firma_teslim_matrisi($pdoDemir);

    // Sözleşme bazlı çap dağılımı (sipariş + teslim; sozlesme_id bağı üzerinden)
    $szData = []; // no => ['taseron'=>, 'caps'=>[capAd=>['s'=>sipariş,'t'=>teslim]]]
    try {
        foreach ($pdoDemir->query("
            SELECT sz.sozlesme_no, ta.ad taseron, COALESCE(c.ad,'(çap belirsiz)') cap, SUM(tk.miktar_ton) ton
            FROM demir_tutanak_kalemleri tk
            JOIN demir_tutanaklar tu ON tu.id = tk.tutanak_id
            JOIN demir_sozlesmeler sz ON sz.id = tu.sozlesme_id
            LEFT JOIN demir_taseronlar ta ON ta.id = sz.taseron_id
            LEFT JOIN demir_caplar c ON c.id = tk.cap_id
            GROUP BY sz.id, tk.cap_id") as $r) {
            $szData[$r['sozlesme_no']]['taseron'] = $r['taseron'];
            $szData[$r['sozlesme_no']]['caps'][$r['cap']]['t'] = ($szData[$r['sozlesme_no']]['caps'][$r['cap']]['t'] ?? 0) + (float)$r['ton'];
        }
        foreach ($pdoDemir->query("
            SELECT sz.sozlesme_no, ta.ad taseron, COALESCE(c.ad,'(çap belirsiz)') cap, SUM(sk.miktar_ton) ton
            FROM demir_siparis_kalemleri sk
            JOIN demir_siparisler sp ON sp.id = sk.siparis_id
            JOIN demir_sozlesmeler sz ON sz.id = sp.sozlesme_id
            LEFT JOIN demir_taseronlar ta ON ta.id = sz.taseron_id
            LEFT JOIN demir_caplar c ON c.id = sk.cap_id
            GROUP BY sz.id, sk.cap_id") as $r) {
            $szData[$r['sozlesme_no']]['taseron'] = $szData[$r['sozlesme_no']]['taseron'] ?? $r['taseron'];
            $szData[$r['sozlesme_no']]['caps'][$r['cap']]['s'] = ($szData[$r['sozlesme_no']]['caps'][$r['cap']]['s'] ?? 0) + (float)$r['ton'];
        }
        ksort($szData);
    } catch (Throwable $e) { $szData = []; } // sozlesme_id/tablolar henüz yoksa gizle
} catch (PDOException $e) {
    $kurulu = false;
}

require_once __DIR__ . '/../includes/header.php';
$fmt = fn($n,$d=2) => number_format((float)$n, $d, ',', '.');
?>

<?php if (!$kurulu): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
    <div><strong>Demir veritabanı henüz kurulmamış.</strong><br>
        Tabloları oluşturmak için <a href="kurulum_demir.php" class="alert-link">kurulum_demir.php</a>'yi bir kez çalıştırın.</div>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-speedometer2 text-dark me-2"></i>Genel Bakış</h4>
        <small class="text-muted">Demir Takip — sevkiyat, sipariş, tutanak ve bakiye özeti</small>
    </div>
    <div class="d-flex gap-2">
        <a href="sevkiyat_form.php" class="btn btn-dark btn-sm"><i class="bi bi-plus-circle me-1"></i> Yeni Sevkiyat</a>
        <a href="import.php" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i> Excel İçe Aktar</a>
    </div>
</div>

<?php
$kart = function($ikon,$renk,$deger,$etiket,$link=null,$alt=null) {
    $ic = '<div class="card h-100 border-0 shadow-sm"><div class="card-body">'
        . '<div class="d-flex align-items-center gap-3">'
        . '<div style="width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:'.$renk.'1f;color:'.$renk.';font-size:1.4rem;"><i class="bi '.$ikon.'"></i></div>'
        . '<div class="flex-fill"><div class="fs-4 fw-bold lh-1">'.$deger.'</div><div class="text-muted small">'.$etiket.'</div></div>'
        . '</div>'
        . ($alt!==null ? '<div class="small mt-2 pt-2 border-top text-muted">'.$alt.'</div>' : '')
        . '</div></div>';
    return $link ? '<a href="'.$link.'" class="text-reset text-decoration-none d-block h-100">'.$ic.'</a>' : $ic;
};
$farkRenk = abs($fark)<0.0005 ? '#6c757d' : ($fark<0 ? '#dc3545' : '#198754');
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= $kart('bi-box-seam','#198754', $fmt($gelenTon).' t', 'Toplam Gelen Demir', 'icmal.php', 'Kantar: <strong>'.$fmt($kantarTon).' t</strong>') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-sliders','' . ($fark<0?'#dc3545':'#198754'), ($fark>0?'+':'').$fmt($fark,3).' t', 'Kantar Farkı', 'icmal.php', 'İrsaliye − Kantar mutabakatı') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-truck','#0d6efd', $sevkSayi, 'Sevkiyat Kaydı', 'sevkiyatlar.php', 'Tedarikçi: <strong>'.$tedSayi.'</strong>') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-cart-check','#fd7e14', $fmt($siparisKalan).' t', 'Kalan Sipariş', 'siparisler.php', $sipSayi.' sipariş · gelen '.$fmt($siparisGelen).' t') ?></div>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><?= $kart('bi-file-earmark-check','#6610f2', $tutSayi, 'Teslim Tutanağı', 'tutanaklar.php', 'Teslim: <strong>'.$fmt($teslimTon).' t</strong>') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-arrow-return-left','#d63384', $fmt($iadeTon).' t', 'İade Edilen', 'iade_tutanaklar.php', $iadeSayi.' iade tutanağı') ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-wallet2','#0dcaf0', $fmt($taseronNet).' t', 'Taşeronda Net', 'taseron_bakiye.php', 'Teslim − İade − Hurda'.($hurdaTon>0?' ('.$fmt($hurdaTon).' t)':'')) ?></div>
    <div class="col-6 col-lg-3"><?= $kart('bi-rulers','#20c997', $capSayi, 'Çap Tanımı', 'caplar.php', 'Taşeron: <strong>'.$tasSayi.'</strong>') ?></div>
</div>

<?php if (!empty($talepOzet)): $tk = $talepOzet['sip'] - $talepOzet['tes']; ?>
<!-- IFS Sipariş Talepleri + Mutabakat -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <div class="col-6 col-md-2">
                <div class="text-muted small"><i class="bi bi-clipboard-data me-1"></i>IFS Talebi</div>
                <a href="talepler.php" class="fs-5 fw-bold text-decoration-none"><?= (int)$talepOzet['adet'] ?></a>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Talep Sipariş</div>
                <div class="fs-6 fw-bold"><?= $fmt($talepOzet['sip']) ?> t</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Teslim Alınan</div>
                <div class="fs-6 fw-bold text-success"><?= $fmt($talepOzet['tes']) ?> t</div>
            </div>
            <div class="col-6 col-md-2">
                <div class="text-muted small">Kalan</div>
                <div class="fs-6 fw-bold <?= $tk > 0.5 ? 'text-danger' : 'text-muted' ?>"><?= $fmt($tk) ?> t</div>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <?php if ($talepOzet['uyumsuz'] > 0): ?>
                <a href="mutabakat.php" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-exclamation-triangle me-1"></i><?= (int)$talepOzet['uyumsuz'] ?> çapta talep↔saha farkı</a>
                <?php else: ?>
                <a href="mutabakat.php" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle me-1"></i>Talep ↔ Saha uyumlu</a>
                <?php endif; ?>
                <a href="talepler.php" class="btn btn-sm btn-outline-primary ms-1">Talepler <i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart-line text-primary me-1"></i> Çap Bazında Gelen Demir (ton)</div>
            <div class="card-body">
                <?php $veriVar = array_sum(array_column($capDagilim,'ton')) > 0; ?>
                <?php if ($veriVar): ?><canvas id="capChart" height="110"></canvas>
                <?php else: ?><div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Henüz sevkiyat kaydı yok. <a href="sevkiyatlar.php">Sevkiyat girişi</a> yapıldığında dağılım burada görünecek.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-pie-chart text-primary me-1"></i> Proje Bazında</div>
            <div class="card-body">
                <?php if ($projeDagilim): ?><canvas id="projeChart" height="200"></canvas>
                <?php else: ?><div class="text-center text-muted py-5"><i class="bi bi-diagram-3 fs-2 d-block mb-2 opacity-50"></i>Proje bazında veri yok.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up text-primary me-1"></i> Aylık Gelen Demir (ton)</div>
            <div class="card-body">
                <?php if (count($aylik) > 0): ?><canvas id="aylikChart" height="110"></canvas>
                <?php else: ?><div class="text-center text-muted py-5"><i class="bi bi-calendar3 fs-2 d-block mb-2 opacity-50"></i>Tarihli sevkiyat verisi yok.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history text-primary me-1"></i> Son Sevkiyatlar</span>
                <a href="sevkiyatlar.php" class="small text-decoration-none">Tümü <i class="bi bi-chevron-right"></i></a>
            </div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>İrsaliye</th><th>Tarih</th><th>Tedarikçi</th><th class="text-end">Ton</th></tr></thead>
                    <tbody>
                    <?php foreach ($sonSevk as $s): ?>
                        <tr>
                            <td><a href="sevkiyat_form.php?id=<?= (int)$s['id'] ?>" class="text-decoration-none font-monospace small"><?= h($s['irsaliye_no'] ?: ('#'.$s['id'])) ?></a><?= $s['proje']?' <span class="badge bg-secondary">'.h($s['proje']).'</span>':'' ?></td>
                            <td class="text-nowrap small"><?= format_date($s['irsaliye_tarih']) ?></td>
                            <td class="small"><?= h($s['tedarikci'] ?: '—') ?></td>
                            <td class="text-end fw-semibold"><?= $fmt($s['ton'],3) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sonSevk): ?><tr><td colspan="4" class="text-center text-muted py-4">Kayıt yok.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>

<!-- Firma bazlı teslim matrisi (Proje × Çap) -->
<?php if (!empty($ftm['matris'])): $fmtT = fn($n)=>number_format((float)$n,3,',','.'); ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-people text-primary me-1"></i> Firma Bazlı Teslim Edilen Demir <span class="text-muted small fw-normal">(Proje × Çap — net)</span></span>
        <select id="fbFirma" class="form-select form-select-sm" style="max-width:280px">
            <?php $ilk = true; foreach ($ftm['firmaToplam'] as $f=>$t): ?>
            <option value="fb-<?= md5($f) ?>"><?= h($f) ?> — <?= $fmtT($t) ?> t</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="card-body p-0">
        <?php $ilk = true; foreach ($ftm['matris'] as $f=>$prjler): ?>
        <div class="fb-panel <?= $ilk?'':'d-none' ?>" id="fb-<?= md5($f) ?>"><?= ftm_tablo_html($f, $prjler, $fmtT) ?></div>
        <?php $ilk = false; endforeach; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var sel = document.getElementById('fbFirma');
    if (!sel) return;
    sel.addEventListener('change', function(){
        document.querySelectorAll('.fb-panel').forEach(function(p){ p.classList.add('d-none'); });
        var t = document.getElementById(this.value); if (t) t.classList.remove('d-none');
    });
});
</script>
<?php endif; ?>

<!-- Sözleşme bazlı çap dağılımı (sipariş / teslim) -->
<?php if (!empty($szData)): $fmtS = $fmtT ?? fn($n)=>number_format((float)$n,3,',','.'); ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-journal-bookmark text-primary me-1"></i> Sözleşme Bazlı Demir <span class="text-muted small fw-normal">(Çap — Sipariş / Teslim)</span></span>
        <select id="szSel" class="form-select form-select-sm" style="max-width:320px">
            <?php foreach ($szData as $no=>$d):
                $topT = 0; foreach ($d['caps'] as $v) $topT += (float)($v['t'] ?? 0); ?>
            <option value="sz-<?= md5($no) ?>"><?= h($no) ?><?= $d['taseron']?' — '.h($d['taseron']):'' ?> — <?= $fmtS($topT) ?> t</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="card-body p-0">
        <?php $ilkSz = true; foreach ($szData as $no=>$d):
            $caps = ftm_cap_sirala(array_keys($d['caps']));
            $topS = 0; $topT = 0;
            foreach ($d['caps'] as $v){ $topS += (float)($v['s'] ?? 0); $topT += (float)($v['t'] ?? 0); }
        ?>
        <div class="sz-panel <?= $ilkSz?'':'d-none' ?>" id="sz-<?= md5($no) ?>">
            <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Çap</th><th class="text-end">Sipariş (t)</th><th class="text-end">Teslim (t)</th><th class="text-end">Kalan (t)</th></tr></thead>
                <tbody>
                <?php foreach ($caps as $c): $s = (float)($d['caps'][$c]['s'] ?? 0); $t = (float)($d['caps'][$c]['t'] ?? 0); $k = $s - $t; ?>
                    <tr>
                        <td class="fw-semibold"><?= h($c) ?></td>
                        <td class="text-end"><?= $s!=0.0 ? $fmtS($s) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end"><?= $t!=0.0 ? $fmtS($t) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end <?= $s>0 ? ($k<=0.0005?'text-success fw-semibold':'text-danger fw-semibold') : 'text-muted' ?>"><?= $s>0 ? ($k<=0.0005?'✓':$fmtS($k)) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td>TOPLAM</td>
                    <td class="text-end"><?= $fmtS($topS) ?></td>
                    <td class="text-end"><?= $fmtS($topT) ?></td>
                    <td class="text-end"><?= $topS>0 ? $fmtS(max(0,$topS-$topT)) : '—' ?></td>
                </tr></tfoot>
            </table>
            </div>
        </div>
        <?php $ilkSz = false; endforeach; ?>
    </div>
    <div class="card-footer bg-white text-muted small"><i class="bi bi-info-circle me-1"></i>Sözleşmeye bağlı sipariş ve teslim tutanaklarından hesaplanır. <a href="sozlesmeler.php" class="text-decoration-none">Sözleşmeler paneli →</a></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var sel = document.getElementById('szSel');
    if (!sel) return;
    sel.addEventListener('change', function(){
        document.querySelectorAll('.sz-panel').forEach(function(p){ p.classList.add('d-none'); });
        var t = document.getElementById(this.value); if (t) t.classList.remove('d-none');
    });
});
</script>
<?php endif; ?>

<?php if ($veriVar || $projeDagilim || count($aylik)>0): ?>
<script>
const palet = ['#0d6efd','#198754','#fd7e14','#6610f2','#d63384','#0dcaf0','#20c997','#ffc107','#dc3545','#6c757d'];
<?php if ($veriVar): ?>
const capData = <?= json_encode(array_values(array_filter($capDagilim, fn($r)=>$r['ton']>0)), JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('capChart'), {
    type:'bar',
    data:{ labels:capData.map(r=>r.ad), datasets:[{ label:'Ton', data:capData.map(r=>parseFloat(r.ton)), backgroundColor:'#0d6efd', borderRadius:4 }] },
    options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
<?php endif; ?>
<?php if ($projeDagilim): ?>
const projeData = <?= json_encode($projeDagilim, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('projeChart'), {
    type:'doughnut',
    data:{ labels:projeData.map(r=>r.kod), datasets:[{ data:projeData.map(r=>parseFloat(r.ton)), backgroundColor:palet }] },
    options:{ plugins:{legend:{position:'bottom'}} }
});
<?php endif; ?>
<?php if (count($aylik)>0): ?>
const aylikData = <?= json_encode($aylik, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('aylikChart'), {
    type:'line',
    data:{ labels:aylikData.map(r=>r.ay), datasets:[{ label:'Ton', data:aylikData.map(r=>parseFloat(r.ton)), borderColor:'#198754', backgroundColor:'#19875422', fill:true, tension:.3 }] },
    options:{ plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
