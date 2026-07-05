<?php
/**
 * veri_kontrol.php — Beton Veri Kontrol & Mükerrer Temizleme
 * Veritabanı ↔ Excel mutabakatı: DB toplamları, mükerrer irsaliye gruplarını bulma/temizleme,
 * Excel dosyasıyla satır-satır karşılaştırma (DB'de fazla / Excel'de eksik).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Veri Kontrol — Beton Takip Sistemi';
$isAdmin = is_admin();

// ── Mükerrer temizleme: her grupta EN ESKİ kayıt kalır, diğerleri silinir ─────
if ($isAdmin && ($_POST['action'] ?? '') === 'mukerrer_temizle') {
    $silinen = 0; $silinenM3 = 0.0;
    $pdo->beginTransaction();
    try {
        $gruplar = $pdo->query("
            SELECT UPPER(TRIM(irsaliye_no)) no_norm, MIN(id) keep_id
            FROM irsaliyeler
            WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''
            GROUP BY UPPER(TRIM(irsaliye_no)) HAVING COUNT(*) > 1")->fetchAll();
        $selDup = $pdo->prepare("SELECT id, miktar FROM irsaliyeler WHERE UPPER(TRIM(irsaliye_no)) = ? AND id <> ?");
        $del    = $pdo->prepare("DELETE FROM irsaliyeler WHERE id = ?");
        foreach ($gruplar as $g) {
            $selDup->execute([$g['no_norm'], (int)$g['keep_id']]);
            foreach ($selDup->fetchAll() as $d) {
                $del->execute([(int)$d['id']]);
                $silinen++; $silinenM3 += (float)$d['miktar'];
            }
        }
        $pdo->commit();
        audit_log($pdo, 'irsaliyeler', 0, 'DELETE', null, ['mukerrer_temizlik'=>true, 'silinen'=>$silinen, 'm3'=>round($silinenM3,2)]);
        flash('success', "Mükerrer temizlik tamamlandı: {$silinen} kayıt silindi (".number_format($silinenM3,2,',','.')." m³). Her grupta ilk kayıt korundu.");
    } catch (Throwable $e) { $pdo->rollBack(); flash('error', 'Temizlik hatası: '.$e->getMessage()); }
    redirect('veri_kontrol.php');
}
// ── Tek grup temizleme ────────────────────────────────────────────────────────
if ($isAdmin && isset($_GET['grup_temizle']) && $_GET['grup_temizle'] !== '') {
    $no = mb_strtoupper(trim((string)$_GET['grup_temizle']), 'UTF-8');
    $pdo->beginTransaction();
    try {
        $keep = $pdo->prepare("SELECT MIN(id) FROM irsaliyeler WHERE UPPER(TRIM(irsaliye_no)) = ?");
        $keep->execute([$no]); $keepId = (int)$keep->fetchColumn();
        if ($keepId) {
            $del = $pdo->prepare("DELETE FROM irsaliyeler WHERE UPPER(TRIM(irsaliye_no)) = ? AND id <> ?");
            $del->execute([$no, $keepId]);
            flash('success', "\"{$no}\" grubunda ".$del->rowCount()." mükerrer kayıt silindi (ilk kayıt korundu).");
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); flash('error', 'Hata: '.$e->getMessage()); }
    redirect('veri_kontrol.php');
}

// ── DB özet ───────────────────────────────────────────────────────────────────
$oz = $pdo->query("
    SELECT COUNT(*) kayit,
           COALESCE(SUM(CASE WHEN tip='alis' THEN miktar END),0) alis_tum,
           COALESCE(SUM(CASE WHEN tip='alis' AND durum<>'reddedildi' THEN miktar END),0) alis_gecerli,
           COALESCE(SUM(CASE WHEN tip='alis' AND durum='reddedildi' THEN miktar END),0) alis_red,
           COALESCE(SUM(CASE WHEN tip='iade' THEN miktar END),0) iade
    FROM irsaliyeler")->fetch();

// ── Mükerrer irsaliye_no grupları ─────────────────────────────────────────────
$mukerrer = $pdo->query("
    SELECT UPPER(TRIM(irsaliye_no)) no_norm, COUNT(*) adet, SUM(miktar) toplam_m3,
           MIN(id) keep_id, MIN(tarih) ilk_tarih, MAX(tarih) son_tarih
    FROM irsaliyeler
    WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''
    GROUP BY UPPER(TRIM(irsaliye_no)) HAVING COUNT(*) > 1
    ORDER BY adet DESC, toplam_m3 DESC")->fetchAll();
$mukSayi = 0; $mukFazlaM3 = 0.0;
if ($mukerrer) {
    // fazla m³ = grup toplamı − korunacak ilk kaydın miktarı
    $ilkMik = $pdo->prepare("SELECT miktar FROM irsaliyeler WHERE id = ?");
    foreach ($mukerrer as &$g) {
        $ilkMik->execute([(int)$g['keep_id']]);
        $g['fazla_m3'] = (float)$g['toplam_m3'] - (float)$ilkMik->fetchColumn();
        $g['fazla_kayit'] = (int)$g['adet'] - 1;
        $mukSayi += $g['fazla_kayit']; $mukFazlaM3 += $g['fazla_m3'];
    }
    unset($g);
}

// ── Boş irsaliye no'lu şüpheli gruplar (tarih+plaka+miktar aynı) ──────────────
$bosSupheli = $pdo->query("
    SELECT tarih, arac_plaka, miktar, COUNT(*) adet
    FROM irsaliyeler
    WHERE (irsaliye_no IS NULL OR TRIM(irsaliye_no) = '')
    GROUP BY tarih, arac_plaka, miktar HAVING COUNT(*) > 1
    ORDER BY adet DESC LIMIT 100")->fetchAll();

// ── Excel karşılaştırma ───────────────────────────────────────────────────────
$exc = null; $excHata = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='excel_kiyas' && !empty($_FILES['dosya']['tmp_name'])) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (!($x = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        $excHata = 'Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError();
    } else {
        // MİKTAR + İRSALİYE kolonlu ilk sayfayı bul (ALIŞLAR / Sayfa1)
        $bulundu = false;
        foreach ($x->sheetNames() as $si=>$sn) {
            $rows = @$x->rows($si, 8000); if (!$rows) continue;
            $hdr=-1; $mcol=-1; $icol=-1;
            foreach ($rows as $ri=>$r) {
                foreach ($r as $ci=>$v) {
                    $s = mb_strtoupper(trim((string)$v),'UTF-8'); $s = str_replace(['İ','ı'],['I','I'],$s);
                    if ($mcol<0 && strpos($s,'MIKTAR')===0) $mcol=$ci;
                    if ($icol<0 && strpos($s,'IRSALIYE')===0 && strpos($s,'NO')!==false) $icol=$ci;
                }
                if ($mcol>=0) { $hdr=$ri; break; }
                if ($ri>8) break;
            }
            if ($mcol<0) continue;
            $excelNolar = []; $excelToplam=0.0; $excelSatir=0; $noSuz=0;
            for ($ri=$hdr+1; $ri<count($rows); $ri++) {
                $mv = trim((string)($rows[$ri][$mcol]??'')); if ($mv==='') continue;
                $mv = (float)str_replace(',','.',$mv); if ($mv==0.0) continue;
                $excelToplam += $mv; $excelSatir++;
                $no = $icol>=0 ? mb_strtoupper(trim((string)($rows[$ri][$icol]??'')),'UTF-8') : '';
                if ($no!=='') $excelNolar[$no] = ($excelNolar[$no]??0)+$mv; else $noSuz++;
            }
            // DB tarafı (alış, reddedilen hariç)
            $dbNolar = [];
            foreach ($pdo->query("SELECT UPPER(TRIM(irsaliye_no)) no_n, SUM(miktar) m3, COUNT(*) c
                FROM irsaliyeler WHERE tip='alis' AND durum<>'reddedildi'
                  AND irsaliye_no IS NOT NULL AND TRIM(irsaliye_no)<>'' GROUP BY UPPER(TRIM(irsaliye_no))") as $r) {
                $dbNolar[$r['no_n']] = ['m3'=>(float)$r['m3'], 'c'=>(int)$r['c']];
            }
            $dbFazla = array_diff_key($dbNolar, $excelNolar);   // DB'de var, Excel'de yok
            $excelEksik = array_diff_key($excelNolar, $dbNolar); // Excel'de var, DB'de yok
            $dbFazlaM3 = 0; foreach ($dbFazla as $v) $dbFazlaM3 += $v['m3'];
            $exc = [
                'sayfa'=>$sn, 'satir'=>$excelSatir, 'toplam'=>$excelToplam, 'nosuz'=>$noSuz,
                'db_fazla'=>$dbFazla, 'db_fazla_m3'=>$dbFazlaM3,
                'excel_eksik'=>$excelEksik, 'excel_eksik_m3'=>array_sum($excelEksik),
            ];
            $bulundu = true; break;
        }
        if (!$bulundu) $excHata = 'MİKTAR kolonu olan sayfa bulunamadı (ALIŞLAR / Sayfa1 bekleniyor).';
    }
}

require_once __DIR__ . '/includes/header.php';
$fmt = fn($n,$d=2) => number_format((float)$n, $d, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-shield-check text-dark me-2"></i>Veri Kontrol & Mutabakat</h4>
        <small class="text-muted">Veritabanı ↔ Excel eşitliği: mükerrer bulma/temizleme, toplam karşılaştırma</small>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>
<?php if ($excHata): ?><div class="alert alert-danger"><?= h($excHata) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Toplam Kayıt</div><div class="fs-5 fw-bold"><?= (int)$oz['kayit'] ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Alış (geçerli)</div><div class="fs-5 fw-bold"><?= $fmt($oz['alis_gecerli']) ?> m³</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Reddedilen</div><div class="fs-5 fw-bold text-muted"><?= $fmt($oz['alis_red']) ?> m³</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">İade</div><div class="fs-5 fw-bold text-danger"><?= $fmt($oz['iade']) ?> m³</div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100 <?= $mukSayi?'border border-danger':'' ?>"><div class="card-body py-2"><div class="text-muted small">Mükerrer Fazlalık</div><div class="fs-5 fw-bold <?= $mukSayi?'text-danger':'text-success' ?>"><?= $mukSayi ?> kayıt / <?= $fmt($mukFazlaM3) ?> m³</div></div></div></div>
</div>

<!-- Mükerrer gruplar -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-exclamation-octagon text-danger me-1"></i> Mükerrer İrsaliye Grupları (<?= count($mukerrer) ?>)</span>
        <?php if ($isAdmin && $mukerrer): ?>
        <form method="post" onsubmit="return confirm('TÜM mükerrer gruplar temizlenecek: her grupta EN ESKİ kayıt korunur, <?= $mukSayi ?> kayıt (<?= $fmt($mukFazlaM3) ?> m³) silinir. Devam edilsin mi?')">
            <input type="hidden" name="action" value="mukerrer_temizle">
            <button class="btn btn-danger btn-sm"><i class="bi bi-trash3 me-1"></i> Tümünü Temizle (ilk kayıtlar korunur)</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:420px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light" style="position:sticky;top:0"><tr><th>İrsaliye No</th><th class="text-center">Kayıt</th><th class="text-center">Fazla</th><th class="text-end">Grup m³</th><th class="text-end">Fazla m³</th><th>Tarih Aralığı</th><th class="text-end">İşlem</th></tr></thead>
            <tbody>
            <?php foreach ($mukerrer as $g): ?>
                <tr>
                    <td class="font-monospace small fw-semibold"><?= h($g['no_norm']) ?></td>
                    <td class="text-center"><span class="badge bg-danger"><?= (int)$g['adet'] ?>×</span></td>
                    <td class="text-center"><?= (int)$g['fazla_kayit'] ?></td>
                    <td class="text-end"><?= $fmt($g['toplam_m3']) ?></td>
                    <td class="text-end fw-semibold text-danger"><?= $fmt($g['fazla_m3']) ?></td>
                    <td class="small text-muted"><?= format_date($g['ilk_tarih']) ?> — <?= format_date($g['son_tarih']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="irsaliyeler.php?ara=<?= urlencode($g['no_norm']) ?>" class="btn btn-xs btn-outline-secondary" title="İrsaliyelerde gör"><i class="bi bi-eye"></i></a>
                        <?php if ($isAdmin): ?>
                        <a href="veri_kontrol.php?grup_temizle=<?= urlencode($g['no_norm']) ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('<?= h($g['no_norm']) ?>: <?= (int)$g['fazla_kayit'] ?> mükerrer silinecek, ilk kayıt korunacak. Onaylıyor musunuz?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$mukerrer): ?><tr><td colspan="7" class="text-center text-success py-4"><i class="bi bi-check-circle me-1"></i>Mükerrer irsaliye yok — veri temiz.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>

<!-- Boş no'lu şüpheliler -->
<?php if ($bosSupheli): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-question-octagon text-warning me-1"></i> İrsaliye No'suz Şüpheli Tekrarlar (<?= count($bosSupheli) ?>) <span class="text-muted small fw-normal">— aynı tarih + plaka + m³; otomatik silinmez, elle kontrol edin</span></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:300px">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Tarih</th><th>Plaka</th><th class="text-end">Miktar (m³)</th><th class="text-center">Kayıt Sayısı</th></tr></thead>
            <tbody>
            <?php foreach ($bosSupheli as $b): ?>
                <tr><td><?= format_date($b['tarih']) ?></td><td class="font-monospace"><?= h($b['arac_plaka'] ?: '—') ?></td><td class="text-end"><?= $fmt($b['miktar']) ?></td><td class="text-center"><span class="badge bg-warning text-dark"><?= (int)$b['adet'] ?>×</span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
<?php endif; ?>

<!-- Excel karşılaştırma -->
<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-excel text-success me-1"></i> Excel ile Mutabakat</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="action" value="excel_kiyas">
            <div class="col-md-8"><label class="form-label small">Güncel Beton Takip Excel'i (.xlsx) — MİKTAR kolonlu sayfa (ALIŞLAR/Sayfa1) okunur</label><input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required></div>
            <div class="col-md-4"><button class="btn btn-success btn-sm"><i class="bi bi-arrow-left-right me-1"></i> Karşılaştır</button></div>
        </form>

        <?php if ($exc): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="card bg-light"><div class="card-body py-2"><div class="text-muted small">Excel (<?= h($exc['sayfa']) ?>)</div><div class="fw-bold"><?= $fmt($exc['toplam']) ?> m³ · <?= $exc['satir'] ?> satır</div></div></div></div>
            <div class="col-md-3"><div class="card bg-light"><div class="card-body py-2"><div class="text-muted small">Veritabanı (geçerli alış)</div><div class="fw-bold"><?= $fmt($oz['alis_gecerli']) ?> m³</div></div></div></div>
            <div class="col-md-3"><div class="card <?= abs($oz['alis_gecerli']-$exc['toplam'])<0.005?'bg-success-subtle':'bg-danger-subtle' ?>"><div class="card-body py-2"><div class="text-muted small">Fark</div><div class="fw-bold"><?= $fmt($oz['alis_gecerli']-$exc['toplam']) ?> m³</div></div></div></div>
            <div class="col-md-3"><div class="card bg-light"><div class="card-body py-2"><div class="text-muted small">Excel'de no'suz satır</div><div class="fw-bold"><?= (int)$exc['nosuz'] ?></div></div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="small fw-semibold text-danger mb-1"><i class="bi bi-database-exclamation me-1"></i>DB'de var, Excel'de YOK (<?= count($exc['db_fazla']) ?> no · <?= $fmt($exc['db_fazla_m3']) ?> m³) — fazlalık adayları</div>
                <div class="table-responsive border rounded" style="max-height:300px">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>İrsaliye No</th><th class="text-center">DB kayıt</th><th class="text-end">m³</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($exc['db_fazla'] as $no=>$v): ?>
                        <tr><td class="font-monospace small"><?= h($no) ?></td><td class="text-center"><?= (int)$v['c'] ?></td><td class="text-end"><?= $fmt($v['m3']) ?></td>
                            <td class="text-end"><a href="irsaliyeler.php?ara=<?= urlencode($no) ?>" class="btn btn-xs btn-outline-secondary"><i class="bi bi-eye"></i></a></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$exc['db_fazla']): ?><tr><td colspan="4" class="text-center text-success py-3">Fazlalık yok ✓</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="small fw-semibold text-warning mb-1"><i class="bi bi-file-earmark-minus me-1"></i>Excel'de var, DB'de YOK (<?= count($exc['excel_eksik']) ?> no · <?= $fmt($exc['excel_eksik_m3']) ?> m³) — eksik kayıtlar</div>
                <div class="table-responsive border rounded" style="max-height:300px">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>İrsaliye No</th><th class="text-end">m³</th></tr></thead>
                    <tbody>
                    <?php foreach ($exc['excel_eksik'] as $no=>$m3): ?>
                        <tr><td class="font-monospace small"><?= h($no) ?></td><td class="text-end"><?= $fmt($m3) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$exc['excel_eksik']): ?><tr><td colspan="2" class="text-center text-success py-3">Eksik yok ✓</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($exc['excel_eksik']): ?><div class="form-text mt-1">Eksikleri <a href="import.php">Excel Aktarımı</a> ile içe aktarabilirsiniz (mükerrer koruması var).</div><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Neden fark oluşur?</strong> Aynı Excel'in tekrar içe aktarılması (irsaliye no'suz satırlar mükerrer korumasını atlar),
    hızlı tarama + içe aktarma çakışması, veya reddedilen kayıtların toplama dahil edilmesi.
    Dashboard artık reddedilenleri saymaz; içe aktarma mükerrer kontrolü büyük/küçük harf ve boşluk farklarına dayanıklı hale getirildi.
</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
