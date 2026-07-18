<?php
/** aktivite.php — Kullanıcı Aktivite Raporu (Araçlar; admin). İki sekme: Özet + Detay */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin']);
require_once __DIR__ . '/includes/db.php';

// Şema garanti (ilk açılışta oluşturur)
try { aktivite_semasi_kur($pdo); } catch (Throwable $e) {}

// Retention temizliği
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['islem']??'')==='temizle') {
    $gun = max(30, (int)($_POST['gun'] ?? 90));
    $pdo->prepare("DELETE FROM kullanici_aktivite WHERE created_at < (NOW() - INTERVAL ? DAY)")->execute([$gun]);
    $pdo->prepare("DELETE FROM kullanici_oturum   WHERE son_aktivite < (NOW() - INTERVAL ? DAY)")->execute([$gun]);
    flash('success', $gun.' günden eski aktivite kayıtları temizlendi.');
    redirect('aktivite.php');
}

$sekme = ($_GET['sekme'] ?? 'ozet') === 'detay' ? 'detay' : 'ozet';

$MODUL_AD = ['beton'=>'Beton','demir'=>'Demir','seramik'=>'Seramik','depo'=>'Depo','akaryakit'=>'Akaryakıt'];
function ak_sure($sn): string {
    $sn=(int)$sn; if($sn<=0) return '—';
    $s=$sn%60; $d=intdiv($sn,60)%60; $sa=intdiv($sn,3600);
    if($sa>0) return $sa.' sa '.($d>0?$d.' dk':'');
    if($d>0)  return $d.' dk '.($s>0?$s.' sn':'');
    return $s.' sn';
}
$fmtDt = fn($s)=>$s ? date('d.m.Y H:i', strtotime($s)) : '—';

$pageTitle = 'Aktivite Raporu';

// ── ÖZET verileri ──
$ozet = []; $kpi = ['aktif_bugun'=>0,'oturum'=>0,'sure'=>0,'sayfa'=>0];
if ($sekme==='ozet') {
    $ozet = $pdo->query("
        SELECT o.kullanici_id, u.username, u.full_name, u.role,
               COUNT(*) oturum,
               SUM(TIMESTAMPDIFF(SECOND, o.giris, o.son_aktivite)) sure,
               SUM(o.sayfa_sayisi) sayfa,
               MAX(o.son_aktivite) son
        FROM kullanici_oturum o
        LEFT JOIN users u ON u.id = o.kullanici_id
        GROUP BY o.kullanici_id
        ORDER BY son DESC")->fetchAll();
    // En çok kullanılan modül (kullanıcı başına)
    $modRows = $pdo->query("
        SELECT kullanici_id, modul, COUNT(*) n FROM kullanici_aktivite
        WHERE modul IS NOT NULL AND modul<>'' GROUP BY kullanici_id, modul")->fetchAll();
    $enModul = [];
    foreach ($modRows as $r) {
        $k=$r['kullanici_id'];
        if (!isset($enModul[$k]) || $r['n'] > $enModul[$k]['n']) $enModul[$k] = ['modul'=>$r['modul'],'n'=>$r['n']];
    }
    $kpi['aktif_bugun'] = (int)$pdo->query("SELECT COUNT(DISTINCT kullanici_id) FROM kullanici_oturum WHERE DATE(son_aktivite)=CURDATE()")->fetchColumn();
    foreach ($ozet as $o) { $kpi['oturum']+=(int)$o['oturum']; $kpi['sure']+=(int)$o['sure']; $kpi['sayfa']+=(int)$o['sayfa']; }
}

// ── DETAY verileri ──
$kullanicilar = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name, username")->fetchAll();
$fKul = ctype_digit((string)($_GET['k'] ?? '')) ? (int)$_GET['k'] : 0;
$fBas = $_GET['bas'] ?? date('Y-m-d', strtotime('-6 days'));
$fBit = $_GET['bit'] ?? date('Y-m-d');
$fTur = in_array($_GET['tur'] ?? '', ['goruntuleme','degisiklik'], true) ? $_GET['tur'] : '';
$detay = [];
if ($sekme==='detay') {
    $basDt = $fBas.' 00:00:00'; $bitDt = $fBit.' 23:59:59';
    $parts = []; $par = [];
    if ($fTur !== 'degisiklik') {
        $w = "created_at BETWEEN ? AND ?"; $p = [$basDt,$bitDt];
        if ($fKul) { $w.=" AND kullanici_id=?"; $p[]=$fKul; }
        $parts[] = "SELECT created_at zaman, kullanici_id, 'goruntuleme' tur, sayfa ayrinti, modul, ip FROM kullanici_aktivite WHERE $w";
        $par = array_merge($par, $p);
    }
    if ($fTur !== 'goruntuleme') {
        $w = "created_at BETWEEN ? AND ?"; $p = [$basDt,$bitDt];
        if ($fKul) { $w.=" AND kullanici_id=?"; $p[]=$fKul; }
        $parts[] = "SELECT created_at zaman, kullanici_id, CONCAT('kayit_',islem) tur, CONCAT(tablo,' #',COALESCE(kayit_id,0)) ayrinti, NULL modul, NULL ip FROM audit_log WHERE $w";
        $par = array_merge($par, $p);
    }
    if ($parts) {
        $sql = implode(" UNION ALL ", $parts)." ORDER BY zaman DESC LIMIT 800";
        $st = $pdo->prepare($sql); $st->execute($par); $detay = $st->fetchAll();
        // kullanıcı adları
        $uMap = [];
        foreach ($kullanicilar as $u) $uMap[$u['id']] = $u['full_name'] ?: $u['username'];
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-activity text-primary me-2"></i>Kullanıcı Aktivite Raporu</h4>
</div>
<?php foreach(['success','error','warning'] as $t): if($m=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div><?php endif; endforeach; ?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $sekme==='ozet'?'active':'' ?>" href="aktivite.php?sekme=ozet"><i class="bi bi-bar-chart me-1"></i>Özet</a></li>
    <li class="nav-item"><a class="nav-link <?= $sekme==='detay'?'active':'' ?>" href="aktivite.php?sekme=detay"><i class="bi bi-list-ul me-1"></i>Detaylar</a></li>
</ul>

<?php if($sekme==='ozet'): ?>
<!-- ── ÖZET ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small mb-1"><i class="bi bi-person-check me-1"></i>Bugün Aktif Kullanıcı</div>
        <div class="h3 mb-0 fw-bold text-primary"><?= (int)$kpi['aktif_bugun'] ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small mb-1"><i class="bi bi-box-arrow-in-right me-1"></i>Toplam Oturum</div>
        <div class="h3 mb-0 fw-bold"><?= number_format((int)$kpi['oturum'],0,',','.') ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small mb-1"><i class="bi bi-clock-history me-1"></i>Toplam Süre</div>
        <div class="h4 mb-0 fw-bold text-success"><?= ak_sure($kpi['sure']) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="text-muted small mb-1"><i class="bi bi-window-stack me-1"></i>Toplam Sayfa Görüntüleme</div>
        <div class="h3 mb-0 fw-bold"><?= number_format((int)$kpi['sayfa'],0,',','.') ?></div></div></div></div>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
    <thead class="table-light"><tr>
        <th>Kullanıcı</th><th>Rol</th><th class="text-end">Oturum</th><th class="text-end">Toplam Süre</th>
        <th class="text-end">Ort. Oturum</th><th class="text-end">Sayfa</th><th>En Çok Modül</th><th>Son Görülme</th>
    </tr></thead>
    <tbody>
    <?php foreach($ozet as $o): $ort = $o['oturum']>0 ? (int)$o['sure']/(int)$o['oturum'] : 0; $em=$enModul[$o['kullanici_id']]??null; ?>
        <tr>
            <td class="fw-semibold"><?= h($o['full_name'] ?: $o['username'] ?: ('#'.$o['kullanici_id'])) ?>
                <?php if($o['full_name']): ?><div class="small text-muted"><?= h($o['username']) ?></div><?php endif; ?></td>
            <td><span class="badge bg-light text-dark"><?= h($o['role']?role_label($o['role']):'—') ?></span></td>
            <td class="text-end font-monospace"><?= (int)$o['oturum'] ?></td>
            <td class="text-end font-monospace fw-bold"><?= ak_sure($o['sure']) ?></td>
            <td class="text-end font-monospace small text-muted"><?= ak_sure($ort) ?></td>
            <td class="text-end font-monospace"><?= number_format((int)$o['sayfa'],0,',','.') ?></td>
            <td><?= $em ? '<span class="badge bg-primary-subtle text-primary">'.h($MODUL_AD[$em['modul']]??$em['modul']).'</span> <span class="small text-muted">'.(int)$em['n'].'</span>' : '—' ?></td>
            <td class="small text-muted"><?= $fmtDt($o['son']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if(!$ozet): ?><tr><td colspan="8" class="text-center text-muted py-4">Henüz aktivite kaydı yok. Kullanıcılar sisteme girip gezindikçe burada görünür.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>

<form method="post" class="mt-3 d-flex align-items-center gap-2" onsubmit="return confirm('Eski kayıtlar silinsin mi?')">
    <input type="hidden" name="islem" value="temizle">
    <span class="small text-muted">Bakım:</span>
    <select name="gun" class="form-select form-select-sm" style="width:auto">
        <option value="90">90 günden eski</option>
        <option value="180">180 günden eski</option>
        <option value="365">1 yıldan eski</option>
    </select>
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Kayıtları Temizle</button>
</form>

<?php else: ?>
<!-- ── DETAY ── -->
<form class="card border-0 shadow-sm mb-3"><div class="card-body">
    <input type="hidden" name="sekme" value="detay">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small">Kullanıcı</label>
            <select name="k" class="form-select form-select-sm">
                <option value="0">Tümü</option>
                <?php foreach($kullanicilar as $u): ?><option value="<?= $u['id'] ?>" <?= $fKul===(int)$u['id']?'selected':'' ?>><?= h($u['full_name']?:$u['username']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label small">Başlangıç</label><input type="date" name="bas" value="<?= h($fBas) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small">Bitiş</label><input type="date" name="bit" value="<?= h($fBit) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-3"><label class="form-label small">Tür</label>
            <select name="tur" class="form-select form-select-sm">
                <option value="">Hepsi (gezinme + değişiklik)</option>
                <option value="goruntuleme" <?= $fTur==='goruntuleme'?'selected':'' ?>>Sayfa Görüntüleme</option>
                <option value="degisiklik" <?= $fTur==='degisiklik'?'selected':'' ?>>Kayıt Değişikliği</option>
            </select></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrele</button></div>
    </div>
</div></form>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:68vh">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
    <thead class="table-light" style="position:sticky;top:0"><tr>
        <th>Zaman</th><th>Kullanıcı</th><th>Tür</th><th>Ayrıntı</th><th>Modül</th><th>IP</th>
    </tr></thead>
    <tbody>
    <?php foreach($detay as $d):
        $isView = $d['tur']==='goruntuleme';
        $turRozet = $isView
            ? '<span class="badge bg-info-subtle text-info"><i class="bi bi-eye me-1"></i>Görüntüleme</span>'
            : ('<span class="badge bg-'.($d['tur']==='kayit_DELETE'?'danger':($d['tur']==='kayit_INSERT'?'success':'warning')).'-subtle text-'.($d['tur']==='kayit_DELETE'?'danger':($d['tur']==='kayit_INSERT'?'success':'warning')).'"><i class="bi bi-pencil-square me-1"></i>'.h(str_replace('kayit_','',$d['tur'])).'</span>');
    ?>
        <tr>
            <td class="small text-nowrap font-monospace"><?= $fmtDt($d['zaman']) ?></td>
            <td class="small fw-semibold"><?= h($uMap[$d['kullanici_id']] ?? ('#'.$d['kullanici_id'])) ?></td>
            <td><?= $turRozet ?></td>
            <td class="small"><?= h($d['ayrinti']?:'—') ?></td>
            <td class="small"><?= $d['modul'] ? '<span class="text-muted">'.h($MODUL_AD[$d['modul']]??$d['modul']).'</span>' : '—' ?></td>
            <td class="small text-muted font-monospace"><?= h($d['ip']?:'—') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if(!$detay): ?><tr><td colspan="6" class="text-center text-muted py-4">Seçilen filtrede kayıt yok.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div></div>
<?php if(count($detay)>=800): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>İlk 800 kayıt gösteriliyor; daralt için tarih/kullanıcı filtresi kullanın.</div><?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
