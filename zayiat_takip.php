<?php
/**
 * zayiat_takip.php — Beton Zayiat Takibi (taşeron bazlı, anlık)
 * Zayiat = Dökülen (irsaliye m³, iade düşülmüş) − Teorik Metraj (proje m³)
 * Teorik metraj 3 seviyede girilebilir: Kot / Blok / İmalat Kalemi (her satır bağımsız izlenir).
 * Limit: satır bazında % (FORE KAZIK %15, diğerleri varsayılan %5) — aşımda kırmızı.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth();
require_once __DIR__ . '/includes/db.php';
if (!can_view_reports()) { flash('error','Bu sayfa için yetkiniz yok.'); redirect('index.php'); }

$pageTitle = 'Zayiat Takip — Beton Takip Sistemi';
$canEdit = has_role('admin','teknik_ofis_admin','teknik_ofis');

// ── Şema (runtime) ────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS beton_metraj (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seviye ENUM('kot','blok','kalem') NOT NULL,
    firma_id INT NULL,
    imalat_grup_id INT NULL,
    ana_is_kalemi_id INT NULL,
    parsel_id INT NULL,
    blok_id INT NULL,
    kot_id INT NULL,
    teorik_m3 DECIMAL(12,3) NOT NULL DEFAULT 0,
    limit_yuzde DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    aciklama VARCHAR(300) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (seviye), KEY (kot_id), KEY (blok_id), KEY (ana_is_kalemi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function zm_num($v){ $v=str_replace(',','.',trim((string)$v)); return $v!=='' && is_numeric($v)?(float)$v:null; }

// ── Kaydet (ekle/düzenle) ─────────────────────────────────────────────────────
if ($canEdit && ($_POST['action'] ?? '') === 'kaydet') {
    $mid = ctype_digit($_POST['id'] ?? '') ? (int)$_POST['id'] : 0;
    $sev = in_array($_POST['seviye'] ?? '', ['kot','blok','kalem'], true) ? $_POST['seviye'] : '';
    $teorik = zm_num($_POST['teorik'] ?? '');
    $limit  = zm_num($_POST['limit'] ?? '');
    $iid = fn($k) => ctype_digit($_POST[$k] ?? '') ? (int)$_POST[$k] : null;
    $d = ['firma_id'=>$iid('firma_id'), 'imalat_grup_id'=>$iid('imalat_grup_id'), 'ana_is_kalemi_id'=>$iid('ana_is_kalemi_id'),
          'parsel_id'=>$iid('parsel_id'), 'blok_id'=>$iid('blok_id'), 'kot_id'=>$iid('kot_id')];
    $hataMsg = '';
    if ($sev === '')                                   $hataMsg = 'Seviye seçin.';
    elseif ($teorik === null || $teorik <= 0)          $hataMsg = 'Teorik metraj (m³) pozitif olmalıdır.';
    elseif ($sev === 'kot'   && !$d['kot_id'])         $hataMsg = 'Kot seçin.';
    elseif ($sev === 'blok'  && !$d['blok_id'])        $hataMsg = 'Blok seçin.';
    elseif ($sev === 'kalem' && !$d['ana_is_kalemi_id']) $hataMsg = 'İmalat kalemi seçin.';
    if ($hataMsg) { flash('error', $hataMsg); }
    else {
        if ($limit === null || $limit < 0) $limit = 5.0;
        // Seviyeye uymayan boyutları temizle (karışık kayıt olmasın)
        if ($sev === 'kot')   { $d['imalat_grup_id'] = $d['ana_is_kalemi_id'] = null; }
        if ($sev === 'blok')  { $d['imalat_grup_id'] = $d['ana_is_kalemi_id'] = null; $d['kot_id'] = null; }
        if ($sev === 'kalem') { $d['parsel_id'] = $d['blok_id'] = $d['kot_id'] = null; }
        $p = [$sev, $d['firma_id'], $d['imalat_grup_id'], $d['ana_is_kalemi_id'], $d['parsel_id'], $d['blok_id'], $d['kot_id'],
              $teorik, $limit, trim($_POST['aciklama'] ?? '') ?: null];
        if ($mid) {
            $p[] = $mid;
            $pdo->prepare("UPDATE beton_metraj SET seviye=?, firma_id=?, imalat_grup_id=?, ana_is_kalemi_id=?,
                parsel_id=?, blok_id=?, kot_id=?, teorik_m3=?, limit_yuzde=?, aciklama=? WHERE id=?")->execute($p);
            flash('success', 'Metraj güncellendi.');
        } else {
            $p[] = current_user_id();
            $pdo->prepare("INSERT INTO beton_metraj (seviye, firma_id, imalat_grup_id, ana_is_kalemi_id,
                parsel_id, blok_id, kot_id, teorik_m3, limit_yuzde, aciklama, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute($p);
            flash('success', 'Teorik metraj eklendi — zayiat anlık izlenecek.');
        }
    }
    redirect('zayiat_takip.php');
}
// ── Sil ───────────────────────────────────────────────────────────────────────
if (has_role('admin','teknik_ofis_admin') && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdo->prepare("DELETE FROM beton_metraj WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Metraj satırı silindi.');
    redirect('zayiat_takip.php');
}

// ── Dökülen agregasyonları (alış +, iade −; reddedilenler hariç) ──────────────
$agg = []; // [seviye][dim_id][firma_key] = ['m3'=>, 'adet'=>]  (firma_key: id veya '*')
foreach ([['kot','kot_id'], ['blok','blok_id'], ['kalem','ana_is_kalemi_id']] as [$sev,$col]) {
    foreach ($pdo->query("
        SELECT $col dim, firma_id,
               SUM(CASE WHEN tip='alis' THEN miktar ELSE -miktar END) m3, COUNT(*) adet
        FROM irsaliyeler WHERE $col IS NOT NULL AND durum <> 'reddedildi'
        GROUP BY $col, firma_id") as $r) {
        $dim = (int)$r['dim'];
        $agg[$sev][$dim]['*']['m3']   = ($agg[$sev][$dim]['*']['m3']   ?? 0) + (float)$r['m3'];
        $agg[$sev][$dim]['*']['adet'] = ($agg[$sev][$dim]['*']['adet'] ?? 0) + (int)$r['adet'];
        $fk = $r['firma_id'] !== null ? (int)$r['firma_id'] : 0;
        $agg[$sev][$dim][$fk]['m3']   = ($agg[$sev][$dim][$fk]['m3']   ?? 0) + (float)$r['m3'];
        $agg[$sev][$dim][$fk]['adet'] = ($agg[$sev][$dim][$fk]['adet'] ?? 0) + (int)$r['adet'];
    }
}

// ── Metraj satırları + hesap ──────────────────────────────────────────────────
$metrajlar = $pdo->query("
    SELECT m.*, f.ad firma_adi, p.ad parsel_adi, b.ad blok_adi, k.kot_degeri,
           ig.ad grup_adi, aik.ad kalem_adi
    FROM beton_metraj m
    LEFT JOIN firmalar f ON f.id = m.firma_id
    LEFT JOIN parseller p ON p.id = m.parsel_id
    LEFT JOIN bloklar b ON b.id = m.blok_id
    LEFT JOIN kotlar k ON k.id = m.kot_id
    LEFT JOIN imalat_gruplari ig ON ig.id = m.imalat_grup_id
    LEFT JOIN ana_is_kalemleri aik ON aik.id = m.ana_is_kalemi_id
    ORDER BY m.seviye, p.ad, b.ad, k.sira, aik.ad")->fetchAll();

$dimCol = ['kot'=>'kot_id','blok'=>'blok_id','kalem'=>'ana_is_kalemi_id'];
$rowsBySeviye = ['kot'=>[], 'blok'=>[], 'kalem'=>[]];
foreach ($metrajlar as $m) {
    $sev = $m['seviye'];
    $dim = (int)$m[$dimCol[$sev]];
    $fk  = $m['firma_id'] !== null ? (int)$m['firma_id'] : '*';
    $dok = (float)($agg[$sev][$dim][$fk]['m3'] ?? 0);
    $adet= (int)($agg[$sev][$dim][$fk]['adet'] ?? 0);
    $teorik = (float)$m['teorik_m3'];
    $zayiat = $dok - $teorik;
    $oran   = $teorik > 0 ? ($zayiat / $teorik * 100) : 0;
    $limit  = (float)$m['limit_yuzde'];
    if ($sev === 'kot')       $etiket = trim(($m['parsel_adi']?:'').' / '.($m['blok_adi']?:'').' / Kot '.($m['kot_degeri']?:''), ' /');
    elseif ($sev === 'blok')  $etiket = trim(($m['parsel_adi']?:'').' / '.($m['blok_adi']?:''), ' /');
    else                      $etiket = trim(($m['grup_adi']?:'').' → '.($m['kalem_adi']?:''), ' →');
    $rowsBySeviye[$sev][] = array_merge($m, [
        'etiket'=>$etiket ?: '(tanımsız)', 'dokulen'=>$dok, 'zayiat'=>$zayiat, 'oran'=>$oran, 'adet'=>$adet,
        'durumx'=> $zayiat <= 0 ? 'eksik' : ($oran > $limit ? 'asim' : ($oran > $limit*0.8 ? 'yaklasti' : 'normal')),
    ]);
}

// ── Proje bazında zayiat özeti (tanımlı metraj satırlarının roll-up'ı) ────────
// Parsel → proje haritası (kot/blok seviyesi parsel taşır; kalem seviyesi parselsiz → '—').
$parselProje = [];
try {
    foreach ($pdo->query("SELECT p.id, COALESCE(pr.kod,'') kod, COALESCE(pr.aciklama,'') ad
                          FROM parseller p LEFT JOIN projeler pr ON pr.id = p.proje_id") as $r) {
        $parselProje[(int)$r['id']] = ['kod'=>$r['kod'], 'ad'=>$r['ad']];
    }
} catch (Throwable $e) { $parselProje = []; }

$projeZayiat = [];
foreach ($rowsBySeviye as $sev => $rows) {
    foreach ($rows as $r) {
        $pp = ($r['parsel_id'] ?? null) ? ($parselProje[(int)$r['parsel_id']] ?? null) : null;
        $kod = ($pp && $pp['kod'] !== '') ? $pp['kod'] : '—';
        $ad  = $pp['ad'] ?? ($kod === '—' ? 'Proje atanmamış' : '');
        if (!isset($projeZayiat[$kod])) $projeZayiat[$kod] = ['ad'=>$ad,'teorik'=>0,'dokulen'=>0,'zayiat'=>0,'asim'=>0,'satir'=>0];
        $projeZayiat[$kod]['teorik']  += (float)$r['teorik_m3'];
        $projeZayiat[$kod]['dokulen'] += (float)$r['dokulen'];
        $projeZayiat[$kod]['zayiat']  += (float)$r['zayiat'];
        $projeZayiat[$kod]['satir']++;
        if ($r['durumx'] === 'asim') $projeZayiat[$kod]['asim']++;
    }
}
uasort($projeZayiat, fn($a,$b) => $b['dokulen'] <=> $a['dokulen']);

// ── AJAX: metraj satırının irsaliyeleri (popup) ───────────────────────────────
if (isset($_GET['detay']) && ctype_digit($_GET['detay'])) {
    $mid = (int)$_GET['detay'];
    $mrow = null;
    foreach ($metrajlar as $m) if ((int)$m['id'] === $mid) { $mrow = $m; break; }
    header('Content-Type: text/html; charset=utf-8');
    if (!$mrow) { echo '<div class="alert alert-danger mb-0">Kayıt bulunamadı.</div>'; exit; }
    $col = $dimCol[$mrow['seviye']];
    $w = ["i.$col = ?", "i.durum <> 'reddedildi'"]; $pq = [(int)$mrow[$col]];
    if ($mrow['firma_id'] !== null) { $w[] = 'i.firma_id = ?'; $pq[] = (int)$mrow['firma_id']; }
    $q = $pdo->prepare("
        SELECT i.id, i.tarih, i.irsaliye_no, i.tip, i.miktar, i.durum,
               f.ad firma, bs.ad beton_sinifi, t.ad tedarikci
        FROM irsaliyeler i
        LEFT JOIN firmalar f ON f.id = i.firma_id
        LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
        LEFT JOIN tedarikciler t ON t.id = i.tedarikci_id
        WHERE " . implode(' AND ', $w) . " ORDER BY i.tarih DESC, i.id DESC LIMIT 300");
    $q->execute($pq);
    $irs = $q->fetchAll();
    $fmt2 = fn($n) => number_format((float)$n, 2, ',', '.');
    ?>
    <div class="table-responsive" style="max-height:420px">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Tarih</th><th>İrsaliye No</th><th>Tip</th><th>Firma</th><th>Beton</th><th>Tedarikçi</th><th class="text-end">Miktar (m³)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($irs as $r): ?>
            <tr>
                <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                <td class="font-monospace small"><?= h($r['irsaliye_no'] ?: '—') ?></td>
                <td><?= $r['tip']==='iade' ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">İade</span>' : '<span class="badge bg-success-subtle text-success border border-success-subtle">Alış</span>' ?></td>
                <td class="small"><?= h($r['firma'] ?: '—') ?></td>
                <td class="small"><?= h($r['beton_sinifi'] ?: '—') ?></td>
                <td class="small"><?= h($r['tedarikci'] ?: '—') ?></td>
                <td class="text-end fw-semibold <?= $r['tip']==='iade'?'text-danger':'' ?>"><?= ($r['tip']==='iade'?'−':'').$fmt2($r['miktar']) ?></td>
                <td class="text-end"><a href="irsaliye_detay.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-xs btn-outline-primary" title="İrsaliyeye git"><i class="bi bi-box-arrow-up-right"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$irs): ?><tr><td colspan="8" class="text-center text-muted py-4">Bu kapsamda irsaliye yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
    exit;
}

// ── Tanım listeleri (modal cascade) ───────────────────────────────────────────
$firmalar  = $pdo->query("SELECT id, ad FROM firmalar WHERE aktif=1 ORDER BY ad")->fetchAll();
$parseller = $pdo->query("SELECT id, ad FROM parseller WHERE aktif=1 ORDER BY ad")->fetchAll();
$bloklar   = $pdo->query("SELECT id, parsel_id, ad FROM bloklar WHERE aktif=1 ORDER BY ad")->fetchAll();
$kotlar    = $pdo->query("SELECT id, blok_id, kot_degeri FROM kotlar WHERE aktif=1 ORDER BY sira, kot_degeri")->fetchAll();
$gruplar   = $pdo->query("SELECT id, ad FROM imalat_gruplari ORDER BY sira, ad")->fetchAll();
$kalemler  = $pdo->query("SELECT id, imalat_grup_id, ad FROM ana_is_kalemleri WHERE aktif=1 ORDER BY sira, ad")->fetchAll();

require_once __DIR__ . '/includes/header.php';
$fmt = fn($n,$d=2) => number_format((float)$n, $d, ',', '.');
$sevAd = ['kot'=>'Kot Bazında', 'blok'=>'Blok Bazında', 'kalem'=>'İmalat Kalemi Bazında'];
$sevIkon = ['kot'=>'bi-layers', 'blok'=>'bi-building', 'kalem'=>'bi-list-task'];
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-graph-down-arrow text-dark me-2"></i>Beton Zayiat Takibi</h4>
        <small class="text-muted">Zayiat = Dökülen (iade düşülmüş) − Teorik Metraj · limit aşımı kırmızı (varsayılan %5, fore kazık %15)</small>
    </div>
    <?php if ($canEdit): ?><button class="btn btn-dark" id="btnYeni"><i class="bi bi-plus-circle me-1"></i> Teorik Metraj Ekle</button><?php endif; ?>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<!-- Proje Bazında Zayiat Özeti -->
<?php if ($projeZayiat): ?>
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 text-primary me-1"></i>Proje Bazında — Teorik vs Dökülen</div>
            <div class="card-body"><canvas id="chProje" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3 text-primary me-1"></i>Proje Bazında Zayiat</span>
                <span class="small text-muted fw-normal" title="Tanımlı teorik metraj satırlarının parsel→proje bağına göre toplamı">tanımlı metraja göre</span>
            </div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Proje</th><th class="text-end">Teorik m³</th><th class="text-end">Dökülen m³</th><th class="text-end">Zayiat m³</th><th class="text-end">Zayiat %</th><th class="text-center">Limit Aşımı</th></tr></thead>
                    <tbody>
                    <?php foreach ($projeZayiat as $kod=>$pz): $oran = $pz['teorik']>0 ? $pz['zayiat']/$pz['teorik']*100 : 0; ?>
                        <tr>
                            <td><span class="badge bg-dark"><?= h($kod) ?></span> <span class="small text-muted"><?= h($pz['ad']) ?></span></td>
                            <td class="text-end font-monospace"><?= $fmt($pz['teorik']) ?></td>
                            <td class="text-end font-monospace"><?= $fmt($pz['dokulen']) ?></td>
                            <td class="text-end font-monospace fw-bold <?= $pz['zayiat']>0?'text-danger':'text-success' ?>"><?= ($pz['zayiat']>0?'+':'').$fmt($pz['zayiat']) ?></td>
                            <td class="text-end font-monospace <?= $oran>5?'text-danger':'' ?>">%<?= $fmt($oran,1) ?></td>
                            <td class="text-center"><?= $pz['asim']>0 ? '<span class="badge bg-danger">'.(int)$pz['asim'].'</span>' : '<span class="text-muted">0</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <?php $ilk = true; foreach ($sevAd as $sev=>$ad): ?>
    <li class="nav-item"><button class="nav-link <?= $ilk?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $sev ?>" type="button"><i class="bi <?= $sevIkon[$sev] ?> me-1"></i><?= $ad ?> <span class="badge bg-secondary ms-1"><?= count($rowsBySeviye[$sev]) ?></span></button></li>
    <?php $ilk = false; endforeach; ?>
</ul>

<div class="tab-content">
<?php $ilk = true; foreach ($sevAd as $sev=>$ad):
    $rows = $rowsBySeviye[$sev];
    $tT = array_sum(array_column($rows,'teorik_m3'));
    $tD = array_sum(array_column($rows,'dokulen'));
    $tZ = $tD - $tT;
    $tO = $tT>0 ? $tZ/$tT*100 : 0;
?>
<div class="tab-pane fade <?= $ilk?'show active':'' ?>" id="tab-<?= $sev ?>">
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Teorik Metraj</div><div class="fs-5 fw-bold"><?= $fmt($tT) ?> m³</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Dökülen</div><div class="fs-5 fw-bold"><?= $fmt($tD) ?> m³</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Zayiat</div><div class="fs-5 fw-bold <?= $tZ>0?'text-danger':'text-success' ?>"><?= ($tZ>0?'+':'').$fmt($tZ) ?> m³</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Zayiat Oranı</div><div class="fs-5 fw-bold <?= $tO>5?'text-danger':'text-success' ?>">%<?= $fmt($tO,1) ?></div></div></div></div>
    </div>

    <?php if ($rows): ?>
    <div class="card mb-3"><div class="card-body"><canvas id="ch-<?= $sev ?>" height="90"></canvas></div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th><?= $ad ?></th><th>Firma</th><th class="text-end">Teorik (m³)</th><th class="text-end">Dökülen (m³)</th>
                <th class="text-end">Zayiat (m³)</th><th class="text-end">Oran</th><th class="text-center">Limit</th><th class="text-center">Durum</th>
                <th class="text-center">İrsaliye</th><?php if($canEdit): ?><th class="text-end">İşlem</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><a href="#" class="metraj-detay text-decoration-none" data-id="<?= $r['id'] ?>" data-etiket="<?= h($r['etiket']) ?>"><?= h($r['etiket']) ?></a><?= $r['aciklama']?'<div class="small text-muted">'.h($r['aciklama']).'</div>':'' ?></td>
                    <td><?= $r['firma_adi'] ? h($r['firma_adi']) : '<span class="text-muted">Tümü</span>' ?></td>
                    <td class="text-end"><?= $fmt($r['teorik_m3']) ?></td>
                    <td class="text-end"><a href="#" class="metraj-detay text-decoration-none" data-id="<?= $r['id'] ?>" data-etiket="<?= h($r['etiket']) ?>"><?= $fmt($r['dokulen']) ?></a></td>
                    <td class="text-end fw-semibold <?= $r['zayiat']>0?'text-danger':'text-success' ?>"><?= ($r['zayiat']>0?'+':'').$fmt($r['zayiat']) ?></td>
                    <td class="text-end fw-semibold <?= $r['durumx']==='asim'?'text-danger':($r['durumx']==='yaklasti'?'text-warning':'') ?>">%<?= $fmt($r['oran'],1) ?></td>
                    <td class="text-center text-muted small">%<?= $fmt($r['limit_yuzde'],0) ?></td>
                    <td class="text-center">
                        <?php if ($r['durumx']==='asim'): ?><span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>LİMİT AŞIMI</span>
                        <?php elseif ($r['durumx']==='yaklasti'): ?><span class="badge bg-warning text-dark">Yaklaşıyor</span>
                        <?php elseif ($r['durumx']==='eksik'): ?><span class="badge bg-light text-muted border">Devam ediyor</span>
                        <?php else: ?><span class="badge bg-success">Normal</span><?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge bg-light text-dark border"><?= (int)$r['adet'] ?></span></td>
                    <?php if ($canEdit): ?>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-xs btn-outline-primary btn-mduzenle" data-json='<?= h(json_encode($r, JSON_UNESCAPED_UNICODE)) ?>' title="Düzenle"><i class="bi bi-pencil"></i></button>
                        <?php if (has_role('admin','teknik_ofis_admin')): ?>
                        <a href="zayiat_takip.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Bu metraj satırı silinsin mi?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $canEdit?10:9 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-graph-down-arrow fs-1 d-block mb-2 opacity-50"></i>
                    Bu seviyede teorik metraj girilmemiş.<?= $canEdit?' "Teorik Metraj Ekle" ile başlayın.':'' ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
<?php $ilk = false; endforeach; ?>
</div>

<!-- İrsaliye detay popup -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-droplet-half me-1"></i><span id="detayBaslik">İrsaliyeler</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="detayBody"></div>
  </div></div>
</div>

<?php if ($canEdit): ?>
<!-- Metraj ekle/düzenle modal -->
<div class="modal fade" id="metrajModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="action" value="kaydet">
      <input type="hidden" name="id" id="m_id">
      <div class="modal-header"><h5 class="modal-title" id="m_baslik">Teorik Metraj Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small">Seviye <span class="text-danger">*</span></label>
            <select name="seviye" id="m_seviye" class="form-select form-select-sm" required>
              <option value="kot">Kot bazında</option><option value="blok">Blok bazında</option><option value="kalem">İmalat kalemi bazında</option>
            </select></div>
          <div class="col-md-6"><label class="form-label small">Firma (taşeron)</label>
            <select name="firma_id" id="m_firma" class="form-select form-select-sm"><option value="">Tümü</option>
            <?php foreach($firmalar as $f): ?><option value="<?= $f['id'] ?>"><?= h($f['ad']) ?></option><?php endforeach; ?></select></div>

          <div class="col-md-4 grp-kot grp-blok"><label class="form-label small">Parsel</label><select id="m_parsel" name="parsel_id" class="form-select form-select-sm"><option value="">—</option></select></div>
          <div class="col-md-4 grp-kot grp-blok"><label class="form-label small">Blok <span class="text-danger">*</span></label><select id="m_blok" name="blok_id" class="form-select form-select-sm"><option value="">—</option></select></div>
          <div class="col-md-4 grp-kot"><label class="form-label small">Kot <span class="text-danger">*</span></label><select id="m_kot" name="kot_id" class="form-select form-select-sm"><option value="">—</option></select></div>

          <div class="col-md-6 grp-kalem"><label class="form-label small">İmalat Grubu</label><select id="m_grup" name="imalat_grup_id" class="form-select form-select-sm"><option value="">—</option></select></div>
          <div class="col-md-6 grp-kalem"><label class="form-label small">İmalat Kalemi <span class="text-danger">*</span></label><select id="m_kalem" name="ana_is_kalemi_id" class="form-select form-select-sm"><option value="">—</option></select></div>

          <div class="col-md-6"><label class="form-label small">Teorik Metraj (m³) <span class="text-danger">*</span></label><input name="teorik" id="m_teorik" type="text" inputmode="decimal" class="form-control form-control-sm" required placeholder="ör. 1250,5"></div>
          <div class="col-md-6"><label class="form-label small">Zayiat Limiti (%)</label><input name="limit" id="m_limit" type="text" inputmode="decimal" class="form-control form-control-sm" value="5"><div class="form-text">Fore kazık için <strong>15</strong> girin.</div></div>
          <div class="col-12"><label class="form-label small">Açıklama</label><input name="aciklama" id="m_acik" class="form-control form-control-sm" placeholder="ör. D3 Blok temel + perde keşfi"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">İptal</button><button class="btn btn-success"><i class="bi bi-save me-1"></i>Kaydet</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof bootstrap === 'undefined') return;
    var fmtTR = function(n,d){ return Number(n).toLocaleString('tr-TR',{minimumFractionDigits:d===undefined?2:d,maximumFractionDigits:d===undefined?2:d}); };

    // ── Grafikler (teorik vs dökülen; limit aşımı kırmızı çubuk) ──
    var CH = <?= json_encode(array_map(fn($rows)=>array_map(fn($r)=>[
        'l'=>$r['etiket'].($r['firma_adi']?' ('.$r['firma_adi'].')':''),
        't'=>(float)$r['teorik_m3'], 'd'=>(float)$r['dokulen'], 'x'=>$r['durumx']==='asim'
    ], $rows), $rowsBySeviye), JSON_UNESCAPED_UNICODE) ?>;
    if (typeof Chart !== 'undefined') {
        Object.keys(CH).forEach(function(sev){
            var el = document.getElementById('ch-'+sev);
            if (!el || !CH[sev].length) return;
            new Chart(el, { type:'bar',
                data:{ labels: CH[sev].map(r=>r.l), datasets:[
                    { label:'Teorik (m³)', data: CH[sev].map(r=>r.t), backgroundColor:'#00584E', borderRadius:3 },
                    { label:'Dökülen (m³)', data: CH[sev].map(r=>r.d), backgroundColor: CH[sev].map(r=>r.x?'#dc3545':'#00C9B1'), borderRadius:3 }
                ]},
                options:{ plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}} } });
        });

        // Proje bazlı teorik vs dökülen
        var PROJE = <?= json_encode(array_map(fn($kod,$pz)=>['l'=>$kod,'t'=>(float)$pz['teorik'],'d'=>(float)$pz['dokulen'],'x'=>$pz['asim']>0], array_keys($projeZayiat), array_values($projeZayiat)), JSON_UNESCAPED_UNICODE) ?>;
        var elP = document.getElementById('chProje');
        if (elP && PROJE.length) {
            new Chart(elP, { type:'bar',
                data:{ labels: PROJE.map(r=>r.l), datasets:[
                    { label:'Teorik (m³)', data: PROJE.map(r=>r.t), backgroundColor:'#00584E', borderRadius:3 },
                    { label:'Dökülen (m³)', data: PROJE.map(r=>r.d), backgroundColor: PROJE.map(r=>r.x?'#dc3545':'#00C9B1'), borderRadius:3 }
                ]},
                options:{ plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}} } });
        }
    }

    // ── İrsaliye detay popup ──
    var detayModal = new bootstrap.Modal(document.getElementById('detayModal'));
    document.addEventListener('click', function(e){
        var a = e.target.closest('.metraj-detay');
        if (!a) return;
        e.preventDefault();
        document.getElementById('detayBaslik').textContent = a.getAttribute('data-etiket') + ' — İrsaliyeler';
        var body = document.getElementById('detayBody');
        body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…</div>';
        detayModal.show();
        fetch('zayiat_takip.php?detay=' + encodeURIComponent(a.getAttribute('data-id')))
            .then(function(r){ return r.text(); })
            .then(function(html){ body.innerHTML = html; })
            .catch(function(){ body.innerHTML = '<div class="alert alert-danger mb-0">İçerik yüklenemedi.</div>'; });
    });

<?php if ($canEdit): ?>
    // ── Metraj modal + kademeli seçimler ──
    var P = <?= json_encode($parseller, JSON_UNESCAPED_UNICODE) ?>,
        B = <?= json_encode($bloklar, JSON_UNESCAPED_UNICODE) ?>,
        K = <?= json_encode($kotlar, JSON_UNESCAPED_UNICODE) ?>,
        G = <?= json_encode($gruplar, JSON_UNESCAPED_UNICODE) ?>,
        A = <?= json_encode($kalemler, JSON_UNESCAPED_UNICODE) ?>;
    var metrajModal = new bootstrap.Modal(document.getElementById('metrajModal'));
    function el(id){ return document.getElementById(id); }
    function fill(sel, items, valKey, txtKey, keep){
        var cur = keep ? sel.value : '';
        sel.innerHTML = '<option value="">—</option>';
        items.forEach(function(it){ var o=document.createElement('option'); o.value=it[valKey]; o.textContent=it[txtKey]; sel.appendChild(o); });
        if (cur) sel.value = cur;
    }
    function sevGoster(){
        var s = el('m_seviye').value;
        document.querySelectorAll('.grp-kot, .grp-blok, .grp-kalem').forEach(function(d){ d.style.display='none'; });
        if (s==='kot') document.querySelectorAll('.grp-kot').forEach(function(d){ d.style.display=''; });
        if (s==='kot'||s==='blok') document.querySelectorAll('.grp-blok').forEach(function(d){ d.style.display=''; });
        if (s==='kalem') document.querySelectorAll('.grp-kalem').forEach(function(d){ d.style.display=''; });
    }
    function blokDoldur(){ var pid=el('m_parsel').value; fill(el('m_blok'), B.filter(function(b){ return !pid || String(b.parsel_id)===pid; }), 'id','ad'); kotDoldur(); }
    function kotDoldur(){ var bid=el('m_blok').value; fill(el('m_kot'), K.filter(function(k){ return !bid || String(k.blok_id)===bid; }), 'id','kot_degeri'); }
    function kalemDoldur(){ var gid=el('m_grup').value; fill(el('m_kalem'), A.filter(function(a){ return !gid || String(a.imalat_grup_id)===gid; }), 'id','ad'); }
    fill(el('m_parsel'), P, 'id','ad'); fill(el('m_grup'), G, 'id','ad'); blokDoldur(); kalemDoldur(); sevGoster();
    el('m_seviye').addEventListener('change', sevGoster);
    el('m_parsel').addEventListener('change', blokDoldur);
    el('m_blok').addEventListener('change', kotDoldur);
    el('m_grup').addEventListener('change', kalemDoldur);
    // Fore kazık seçilirse limit önerisini 15 yap
    el('m_kalem').addEventListener('change', function(){
        var txt = this.options[this.selectedIndex] ? this.options[this.selectedIndex].textContent : '';
        if (/FORE/i.test(txt) && (el('m_limit').value==='5' || el('m_limit').value==='')) el('m_limit').value = '15';
    });

    var btnYeni = el('btnYeni');
    if (btnYeni) btnYeni.addEventListener('click', function(){
        el('m_baslik').textContent = 'Teorik Metraj Ekle';
        ['m_id','m_acik'].forEach(function(i){ el(i).value=''; });
        el('m_seviye').value='kot'; el('m_firma').value=''; el('m_teorik').value=''; el('m_limit').value='5';
        el('m_parsel').value=''; blokDoldur(); el('m_grup').value=''; kalemDoldur(); sevGoster();
        metrajModal.show();
    });
    document.querySelectorAll('.btn-mduzenle').forEach(function(b){
        b.addEventListener('click', function(){
            var r = JSON.parse(this.getAttribute('data-json'));
            el('m_baslik').textContent = 'Metraj Düzenle';
            el('m_id').value = r.id; el('m_seviye').value = r.seviye; el('m_firma').value = r.firma_id || '';
            el('m_teorik').value = r.teorik_m3; el('m_limit').value = r.limit_yuzde; el('m_acik').value = r.aciklama || '';
            el('m_parsel').value = r.parsel_id || ''; blokDoldur();
            el('m_blok').value = r.blok_id || ''; kotDoldur();
            el('m_kot').value = r.kot_id || '';
            el('m_grup').value = r.imalat_grup_id || ''; kalemDoldur();
            el('m_kalem').value = r.ana_is_kalemi_id || '';
            sevGoster();
            metrajModal.show();
        });
    });
<?php endif; ?>
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
