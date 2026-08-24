<?php
/**
 * demir/zayiat.php — Demir Zayiat Takibi
 *
 * Beton zayiat deseninin demir karşılığı:
 *   Teorik metraj (proje × çap, elle girilir — projenin demir metrajı)
 *   Kullanılan   = taşerona teslim edilen (tutanak kalemleri + Tutanak Takip defteri)
 *   Zayiat       = Kullanılan − Teorik   |   Oran = Zayiat / Teorik
 * Satır bazında limit % (varsayılan 3) — aşımda kırmızı.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';

$pageTitle = 'Zayiat Takibi — Demir';

$pdoDemir->exec("CREATE TABLE IF NOT EXISTS demir_metraj (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proje_id INT NULL,
    cap_id INT NULL COMMENT 'NULL = tüm çaplar toplamı',
    teorik_ton DECIMAL(12,3) NOT NULL DEFAULT 0,
    limit_yuzde DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    aciklama VARCHAR(200) NULL,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (proje_id), KEY (cap_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$duzenleyebilir = has_role('admin', 'teknik_ofis_admin', 'teknik_ofis');

// ── Metraj CRUD ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $duzenleyebilir && ($_POST['action'] ?? '') === 'metraj_kaydet') {
    $id  = (int)($_POST['id'] ?? 0);
    $sayi = function ($v): float {   // "1.234,5" ve "1234.5" ikisi de desteklenir
        $v = trim((string)$v);
        if (strpos($v, ',') !== false) $v = str_replace(',', '.', str_replace('.', '', $v));
        return is_numeric($v) ? (float)$v : 0.0;
    };
    $teorik = $sayi($_POST['teorik'] ?? '0');
    $limit  = max(0, $sayi($_POST['limit'] ?? '3'));
    $projeId = (int)($_POST['proje_id'] ?? 0) ?: null;
    $capId   = (int)($_POST['cap_id'] ?? 0) ?: null;
    if ($teorik <= 0) { flash('error', 'Teorik metraj sıfırdan büyük olmalı.'); }
    else {
        if ($id) {
            $pdoDemir->prepare("UPDATE demir_metraj SET proje_id=?, cap_id=?, teorik_ton=?, limit_yuzde=?, aciklama=? WHERE id=?")
                ->execute([$projeId, $capId, $teorik, $limit, trim((string)($_POST['aciklama'] ?? '')) ?: null, $id]);
        } else {
            $pdoDemir->prepare("INSERT INTO demir_metraj (proje_id, cap_id, teorik_ton, limit_yuzde, aciklama) VALUES (?,?,?,?,?)")
                ->execute([$projeId, $capId, $teorik, $limit, trim((string)($_POST['aciklama'] ?? '')) ?: null]);
        }
        flash('success', 'Metraj kaydedildi.');
    }
    redirect('zayiat.php');
}
if ($duzenleyebilir && isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $pdoDemir->prepare("DELETE FROM demir_metraj WHERE id=?")->execute([(int)$_GET['sil']]);
    flash('success', 'Metraj satırı silindi.');
    redirect('zayiat.php');
}

// ── Kullanılan (teslim edilen) — proje × çap ────────────────────────────────
// Kaynak 1: uygulama tutanakları  ·  Kaynak 2: Tutanak Takip defteri (proje kodu metin)
$kullanilan = [];   // "proje|cap" => ton   (cap 0 = toplam)
try {
    foreach ($pdoDemir->query("
        SELECT COALESCE(tu.proje_id, 0) pid, COALESCE(tk.cap_id, 0) cid, SUM(tk.miktar_ton) ton
        FROM demir_tutanak_kalemleri tk JOIN demir_tutanaklar tu ON tu.id = tk.tutanak_id
        GROUP BY pid, cid") as $r) {
        $kullanilan[$r['pid'].'|'.$r['cid']] = ($kullanilan[$r['pid'].'|'.$r['cid']] ?? 0) + (float)$r['ton'];
        $kullanilan[$r['pid'].'|0']  = ($kullanilan[$r['pid'].'|0'] ?? 0) + ((int)$r['cid'] ? (float)$r['ton'] : 0);
    }
} catch (Throwable $e) {}
// Defter: proje kodu string, çap label — id'lere çevir
$projeler = $pdoDemir->query("SELECT id, kod, aciklama FROM demir_projeler ORDER BY kod")->fetchAll();
$caplar   = $pdoDemir->query("SELECT id, ad FROM demir_caplar ORDER BY sira")->fetchAll();
$projeByKod = []; foreach ($projeler as $p) $projeByKod[mb_strtoupper(trim($p['kod']),'UTF-8')] = (int)$p['id'];
$capBySayi  = []; foreach ($caplar as $c) if (preg_match('/(\d+)/',$c['ad'],$m)) $capBySayi[(int)$m[1]] = (int)$c['id'];
try {
    // Uygulama tutanağında zaten sayılanları çift saymamak için tutanak_no dedup
    $uygNo = [];
    foreach ($pdoDemir->query("SELECT DISTINCT tutanak_no FROM demir_tutanaklar WHERE tutanak_no IS NOT NULL") as $r)
        $uygNo[mb_strtoupper(trim($r['tutanak_no']),'UTF-8')] = true;
    foreach ($pdoDemir->query("SELECT proje, cap_label, tutanak_no, SUM(miktar_ton) ton
                               FROM demir_tutanak_takip WHERE tip='teslim'
                               GROUP BY proje, cap_label, tutanak_no") as $r) {
        if ($r['tutanak_no'] !== null && isset($uygNo[mb_strtoupper(trim($r['tutanak_no']),'UTF-8')])) continue;
        $pid = $projeByKod[mb_strtoupper(trim((string)$r['proje']),'UTF-8')] ?? 0;
        $cid = preg_match('/(\d+)/', (string)$r['cap_label'], $m) ? ($capBySayi[(int)$m[1]] ?? 0) : 0;
        $kullanilan[$pid.'|'.$cid] = ($kullanilan[$pid.'|'.$cid] ?? 0) + (float)$r['ton'];
        if ($cid) $kullanilan[$pid.'|0'] = ($kullanilan[$pid.'|0'] ?? 0) + (float)$r['ton'];
    }
} catch (Throwable $e) {}

// ── Metraj satırları + zayiat hesabı ────────────────────────────────────────
$metraj = $pdoDemir->query("
    SELECT m.*, p.kod proje_kod, c.ad cap_ad
    FROM demir_metraj m
    LEFT JOIN demir_projeler p ON p.id = m.proje_id
    LEFT JOIN demir_caplar c ON c.id = m.cap_id
    ORDER BY p.kod, c.sira")->fetchAll();

$satirlar = []; $asim = 0; $topTeorik = 0.0; $topKull = 0.0;
foreach ($metraj as $m) {
    $k = (int)($m['proje_id'] ?? 0) . '|' . (int)($m['cap_id'] ?? 0);
    $kull = (float)($kullanilan[$k] ?? 0);
    $teorik = (float)$m['teorik_ton'];
    $zayiat = $kull - $teorik;
    $oran = $teorik > 0 ? $zayiat / $teorik * 100 : 0;
    $lim = (float)$m['limit_yuzde'];
    $durum = $kull < $teorik ? 'devam' : ($oran > $lim ? 'asim' : ($oran > $lim * 0.8 ? 'yaklasti' : 'normal'));
    if ($durum === 'asim') $asim++;
    $topTeorik += $teorik; $topKull += $kull;
    $satirlar[] = ['m'=>$m, 'kull'=>$kull, 'zayiat'=>$zayiat, 'oran'=>$oran, 'durum'=>$durum];
}

$fmt = fn($n, $d=2) => number_format((float)$n, $d, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-graph-down-arrow text-primary me-2"></i>Demir Zayiat Takibi</h4>
        <small class="text-muted">Zayiat = Teslim Edilen − Teorik Metraj · kaynak: tutanaklar + Tutanak Takip defteri</small>
    </div>
    <?php if ($duzenleyebilir): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#metrajModal" onclick="metrajAc()">
        <i class="bi bi-plus-circle me-1"></i>Teorik Metraj Ekle</button>
    <?php endif; ?>
</div>

<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>

<?php if (!$metraj): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
    Henüz teorik metraj girilmemiş. "Teorik Metraj Ekle" ile proje (istenirse çap) bazında projenin demir
    metrajını girin — teslim edilen demirle karşılaştırılıp zayiat canlı hesaplanır.
</div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Teorik Metraj</div><div class="fs-5 fw-bold"><?= $fmt($topTeorik) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Teslim Edilen</div><div class="fs-5 fw-bold text-success"><?= $fmt($topKull) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="text-muted small">Zayiat</div>
        <div class="fs-5 fw-bold <?= ($topKull-$topTeorik)>0?'text-danger':'text-muted' ?>"><?= $fmt($topKull-$topTeorik) ?> t</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100 <?= $asim?'border border-danger':'' ?>"><div class="card-body py-2">
        <div class="text-muted small">Limit Aşımı</div><div class="fs-5 fw-bold <?= $asim?'text-danger':'text-success' ?>"><?= $asim ?> satır</div></div></div></div>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Proje</th><th>Çap</th><th>Açıklama</th>
        <th class="text-end">Teorik (t)</th><th class="text-end">Teslim (t)</th>
        <th class="text-end">Zayiat (t)</th><th class="text-end">Oran</th><th class="text-end">Limit</th><th>Durum</th>
        <?php if ($duzenleyebilir): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($satirlar as $sr): $m = $sr['m']; ?>
        <tr class="<?= $sr['durum']==='asim'?'table-danger':($sr['durum']==='yaklasti'?'table-warning':'') ?>">
            <td class="fw-semibold"><?= h($m['proje_kod'] ?: 'Tümü') ?></td>
            <td><?= h($m['cap_ad'] ?: 'Tüm çaplar') ?></td>
            <td class="small text-muted"><?= h((string)$m['aciklama']) ?></td>
            <td class="text-end"><?= $fmt($m['teorik_ton'], 3) ?></td>
            <td class="text-end"><?= $fmt($sr['kull'], 3) ?></td>
            <td class="text-end fw-bold <?= $sr['zayiat']>0?'text-danger':'text-muted' ?>"><?= $fmt($sr['zayiat'], 3) ?></td>
            <td class="text-end fw-bold"><?= $sr['kull']<$m['teorik_ton'] ? '—' : '%'.$fmt($sr['oran'], 1) ?></td>
            <td class="text-end text-muted">%<?= $fmt($m['limit_yuzde'], 1) ?></td>
            <td>
                <?php if ($sr['durum']==='asim'): ?><span class="badge bg-danger">LİMİT AŞIMI</span>
                <?php elseif ($sr['durum']==='yaklasti'): ?><span class="badge bg-warning text-dark">Yaklaşıyor</span>
                <?php elseif ($sr['durum']==='devam'): ?><span class="badge bg-secondary">Devam ediyor</span>
                <?php else: ?><span class="badge bg-success">Normal</span><?php endif; ?>
            </td>
            <?php if ($duzenleyebilir): ?>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="modal" data-bs-target="#metrajModal"
                        onclick='metrajAc(<?= json_encode(['id'=>(int)$m['id'],'proje_id'=>(int)($m['proje_id']??0),'cap_id'=>(int)($m['cap_id']??0),'teorik'=>(float)$m['teorik_ton'],'limit'=>(float)$m['limit_yuzde'],'aciklama'=>(string)$m['aciklama']], JSON_HEX_APOS) ?>)'><i class="bi bi-pencil"></i></button>
                <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$m['id'] ?>" onclick="return confirm('Metraj satırı silinsin mi?')"><i class="bi bi-trash"></i></a>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot class="table-light fw-bold"><tr>
        <td colspan="3" class="text-end">TOPLAM</td>
        <td class="text-end"><?= $fmt($topTeorik, 3) ?></td>
        <td class="text-end"><?= $fmt($topKull, 3) ?></td>
        <td class="text-end <?= ($topKull-$topTeorik)>0?'text-danger':'' ?>"><?= $fmt($topKull-$topTeorik, 3) ?></td>
        <td colspan="<?= $duzenleyebilir ? 4 : 3 ?>"></td>
    </tr></tfoot>
</table>
</div></div></div>
<div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>
    "Devam ediyor" = teslim henüz teorik metrajın altında (zayiat konuşmak için erken).
    Oran, teslim teoriği geçince hesaplanır; limit üstü kırmızıdır.
</div>
<?php endif; ?>

<?php if ($duzenleyebilir): ?>
<div class="modal fade" id="metrajModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post">
        <input type="hidden" name="action" value="metraj_kaydet">
        <input type="hidden" name="id" id="mId" value="0">
        <div class="modal-header"><h6 class="modal-title">Teorik Metraj</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-6">
                <label class="form-label">Proje</label>
                <select name="proje_id" id="mProje" class="form-select">
                    <option value="0">Tümü</option>
                    <?php foreach ($projeler as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['kod']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label">Çap <span class="text-muted small">(boş = tüm çaplar)</span></label>
                <select name="cap_id" id="mCap" class="form-select">
                    <option value="0">Tüm çaplar</option>
                    <?php foreach ($caplar as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['ad']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6"><label class="form-label">Teorik Metraj (ton)</label>
                <input type="text" name="teorik" id="mTeorik" class="form-control" required inputmode="decimal"></div>
            <div class="col-6"><label class="form-label">Zayiat Limiti (%)</label>
                <input type="text" name="limit" id="mLimit" class="form-control" value="3"></div>
            <div class="col-12"><label class="form-label">Açıklama</label>
                <input type="text" name="aciklama" id="mAciklama" class="form-control"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
            <button class="btn btn-primary btn-sm">Kaydet</button>
        </div>
    </form>
</div></div></div>
<script>
function metrajAc(v){
    v = v || {id:0, proje_id:0, cap_id:0, teorik:'', limit:3, aciklama:''};
    document.getElementById('mId').value = v.id;
    document.getElementById('mProje').value = v.proje_id;
    document.getElementById('mCap').value = v.cap_id;
    document.getElementById('mTeorik').value = v.teorik;
    document.getElementById('mLimit').value = v.limit;
    document.getElementById('mAciklama').value = v.aciklama;
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
