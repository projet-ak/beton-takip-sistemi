<?php
/**
 * hareketler.php — Akaryakıt Günlük Hareket Defteri (giriş + çıkış tek listede)
 *
 * "Bugün tanka ne geldi, hangi araca ne verildi" sorusunun tek ekrandaki cevabı.
 * Defter iki kaynaktan BİRLEŞİR (bkz. _ortak.php `ak_defter`):
 *   GİRİŞ → `akaryakit_girisler` (bu ekran yönetir)
 *   ÇIKIŞ → `akaryakit_cikislar` (cikislar.php'nin tablosu; KOPYALANMAZ, oradan okunur)
 *
 * ⚠ Stok zinciri (Devir + Gelen − Kullanılan) EXCEL'den kurulur — Excel tek doğru
 *   kaynaktır. Defter onun yerine geçmez; sayfadaki **mutabakat bandı** seçilen ayın
 *   defter toplamlarını Excel'deki Gelen/Kullanılan ile karşılaştırır ve farkı gösterir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';

ak_giris_semasi_kur($pdoAkaryakit);
$pageTitle = 'Hareketler — Akaryakıt';
$yetkili   = has_role('admin','teknik_ofis_admin');
$qs        = fn(array $ek = []) => 'hareketler.php?' . http_build_query(array_merge(
                array_intersect_key($_GET, array_flip(['ay','bas','bit','tur','arac_id','ara'])), $ek));

// ── Giriş kaydet / güncelle ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'giris_kaydet') {
    $id     = (int)($_POST['id'] ?? 0);
    $miktar = ak_sayi_form($_POST['miktar'] ?? '');
    $tarih  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['tarih'] ?? '') ? $_POST['tarih'] : date('Y-m-d');
    $bfiyat = ak_sayi_form($_POST['birim_fiyat'] ?? '');
    $tutar  = ak_sayi_form($_POST['tutar'] ?? '');
    if ($tutar <= 0 && $bfiyat > 0) $tutar = round($miktar * $bfiyat, 2);   // tutar boşsa hesapla
    if ($miktar <= 0) {
        flash('error', 'Miktar sıfırdan büyük olmalı.');
    } else {
        $alan = [$tarih,
                 trim((string)($_POST['belge_no'] ?? ''))    ?: null,
                 trim((string)($_POST['tedarikci'] ?? ''))   ?: null,
                 trim((string)($_POST['plaka'] ?? ''))       ?: null,
                 $miktar,
                 $bfiyat > 0 ? $bfiyat : null,
                 $tutar  > 0 ? $tutar  : null,
                 trim((string)($_POST['teslim_alan'] ?? '')) ?: null,
                 trim((string)($_POST['aciklama'] ?? ''))    ?: null];
        if ($id) {
            $pdoAkaryakit->prepare("UPDATE akaryakit_girisler SET tarih=?, belge_no=?, tedarikci=?, plaka=?,
                miktar_lt=?, birim_fiyat=?, tutar=?, teslim_alan=?, aciklama=? WHERE id=?")
                ->execute(array_merge($alan, [$id]));
            flash('success', 'Giriş kaydı güncellendi.');
        } else {
            $pdoAkaryakit->prepare("INSERT INTO akaryakit_girisler
                (tarih,belge_no,tedarikci,plaka,miktar_lt,birim_fiyat,tutar,teslim_alan,aciklama,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge($alan, [current_user_id()]));
            flash('success', number_format($miktar, 0, ',', '.') . ' Lt mazot girişi kaydedildi.');
        }
    }
    redirect($qs());
}

// ── Giriş belgesi (irsaliye/fatura taraması) yükle ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'giris_evrak') {
    $id = (int)($_POST['id'] ?? 0);
    $v  = $pdoAkaryakit->prepare("SELECT id, evrak_url FROM akaryakit_girisler WHERE id=?");
    $v->execute([$id]);
    $kayit = $v->fetch();
    if (!$kayit) { flash('error', 'Kayıt bulunamadı.'); redirect($qs()); }
    if (empty($_FILES['evrak']['tmp_name']) || !is_uploaded_file($_FILES['evrak']['tmp_name'])) {
        flash('error', 'Dosya seçilmedi.');
    } else {
        $ad   = (string)$_FILES['evrak']['name'];
        $mime = guess_mime($_FILES['evrak']['tmp_name'], $ad);
        if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) {
            flash('error', 'Desteklenmeyen tür (PDF, JPG, PNG, WEBP): ' . $mime);
        } elseif ((int)$_FILES['evrak']['size'] > 10 * 1024 * 1024) {
            flash('error', 'Dosya 10 MB sınırını aşıyor.');
        } else {
            $dir = __DIR__ . '/../uploads/akaryakit_giris/' . $id;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'pdf';
            $yeni = 'evrak_' . date('Ymd_His') . '.' . $ext;
            if (@move_uploaded_file($_FILES['evrak']['tmp_name'], $dir . '/' . $yeni)) {
                if ($kayit['evrak_url']) @unlink(__DIR__ . '/../' . $kayit['evrak_url']);
                $pdoAkaryakit->prepare("UPDATE akaryakit_girisler SET evrak_url=? WHERE id=?")
                    ->execute(['uploads/akaryakit_giris/' . $id . '/' . $yeni, $id]);
                flash('success', 'Giriş belgesi yüklendi.');
            } else flash('error', 'Dosya diske yazılamadı.');
        }
    }
    redirect($qs());
}

if (isset($_GET['giris_sil']) && ctype_digit($_GET['giris_sil']) && $yetkili) {
    $v = $pdoAkaryakit->prepare("SELECT evrak_url FROM akaryakit_girisler WHERE id=?");
    $v->execute([(int)$_GET['giris_sil']]);
    if ($u = $v->fetchColumn()) @unlink(__DIR__ . '/../' . $u);
    $pdoAkaryakit->prepare("DELETE FROM akaryakit_girisler WHERE id=?")->execute([(int)$_GET['giris_sil']]);
    flash('success', 'Giriş kaydı silindi.');
    redirect($qs());
}

// ── Filtre + defter ──────────────────────────────────────────────────────────
if (!isset($_GET['ay']) && !isset($_GET['bas']) && !isset($_GET['bit'])) $_GET['ay'] = date('Y-m');
$f      = ak_defter_filtre($_GET);
$defter = ak_defter($pdoAkaryakit, $f);

// Açılış: ay + Excel dönemi varsa Excel devri, serbest aralıkta defterin önceki hareketleri
$donem = $f['ay'] !== '' ? ak_donem_ay($pdoAkaryakit, $f['ay']) : null;
[$devir, $devirKaynak] = ak_defter_acilis($pdoAkaryakit, $f, $donem);
// Tür ya da araç süzgeci varsa hareketlerin bir kısmı listede değildir; yürüyen
// bakiye o durumda YANILTIR (yalnız girişleri toplar), bu yüzden sütun gizlenir.
$bakiyeGoster = $f['tur'] === '' && $f['arac_id'] === 0;

// Toplam/bakiye yalnız SAYILAN satırlardan: Excel'e işlenmiş (eşleşen) elle kayıt iki kez sayılmaz
$sayilan  = array_values(array_filter($defter, fn($r) => !empty($r['sayilir'])));
$tGiris   = array_sum(array_column($sayilan, 'giris'));
$tCikis   = array_sum(array_column($sayilan, 'cikis'));
$tTutar   = array_sum(array_map(fn($r) => (float)($r['tutar'] ?? 0), $sayilan));
$aracAdet = count(array_unique(array_filter(array_column($defter, 'arac_id'))));
$excelAdet    = count(array_filter($defter, fn($r) => $r['kaynak'] === 'excel'));
$elleBekleyen = array_values(array_filter($defter, fn($r) => $r['kaynak'] === 'elle' && !empty($r['sayilir'])));
$elleIslenen  = count(array_filter($defter, fn($r) => $r['kaynak'] === 'elle' && empty($r['sayilir'])));
$excelGiris   = array_sum(array_map(fn($r) => $r['kaynak'] === 'excel' && empty($r['sentetik']) ? $r['giris'] : 0, $defter));
$excelCikis   = array_sum(array_map(fn($r) => $r['kaynak'] === 'excel' && empty($r['sentetik']) ? $r['cikis'] : 0, $defter));

// ── Excel dışa aktarma (filtrelere saygılı) ─────────────────────────────────
if (($_GET['disaaktar'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $xl = new \XlsxWriter('Akaryakıt Hareketleri');
    $xl->header(array_merge(['Tarih','Tür','Kaynak','Durum','Belge No','Taraf','Detay','Plaka','Giriş (Lt)','Çıkış (Lt)'],
                 $bakiyeGoster ? ['Bakiye (Lt)'] : [], ['Sayaç','Teslim Alan','Tutar (TL)','Açıklama']));
    $bak = $devir;
    foreach ($defter as $r) {
        if (!empty($r['sayilir'])) $bak += $r['giris'] - $r['cikis'];
        $durum = $r['kaynak'] === 'excel' ? 'Excel' : (!empty($r['sayilir']) ? "Excel'de yok" : "Excel'e işlendi");
        $xl->row(array_merge([
            ['v'=>$r['tarih'],'t'=>'date'], ['v'=>$r['tur'] === 'giris' ? 'Giriş' : 'Çıkış'],
            ['v'=>$r['kaynak'] === 'excel' ? 'Excel' : 'Elle'], ['v'=>$durum],
            ['v'=>$r['belge_no']], ['v'=>$r['taraf']], ['v'=>$r['detay']], ['v'=>$r['plaka']],
            ['v'=>$r['giris'] ?: null,'t'=>'number'], ['v'=>$r['cikis'] ?: null,'t'=>'number'],
        ], $bakiyeGoster ? [['v'=>round($bak, 2),'t'=>'number']] : [], [
            ['v'=>$r['sayac']], ['v'=>$r['teslim_alan']],
            ['v'=>$r['tutar'],'t'=>'number'], ['v'=>$r['aciklama']],
        ]));
    }
    $xl->download('akaryakit_hareketler_' . date('Ymd_Hi') . '.xlsx');
}

$araclar = $pdoAkaryakit->query("SELECT id, sofor, cinsi, firma, plaka FROM akaryakit_araclar WHERE aktif=1 ORDER BY sofor")->fetchAll();
$duzenle = null;
if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $d = $pdoAkaryakit->prepare("SELECT * FROM akaryakit_girisler WHERE id=?");
    $d->execute([(int)$_GET['duzenle']]);
    $duzenle = $d->fetch() ?: null;
}

$f0 = fn($n) => number_format((float)$n, 0, ',', '.');
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-arrow-left-right text-primary me-2"></i>Hareketler</h4>
        <small class="text-muted">Excel'in günlük hücreleri + elle girilen hareketler tek listede · Excel esastır, elle kayıt Excel'e işlenince bir kez sayılır</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= h($qs(['disaaktar'=>'xlsx'])) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel'e Aktar</a>
        <a href="cikislar.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-up me-1"></i>Çıkış Ekranı</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#girisModal" onclick="girisAc()">
            <i class="bi bi-plus-circle me-1"></i>Yeni Giriş</button>
    </div>
</div>

<?php foreach (['success','error','warning'] as $t): if ($m = get_flash($t)): ?>
    <div class="alert alert-<?= $t === 'error' ? 'danger' : $t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<?php if ($donem):
    // Excel'in kendi iç tutarlılığı: günlük hücreler toplamı ↔ aylık özet hücresi
    $gunFarkG = $excelGiris - (float)$donem['gelen'];
    $gunFarkC = $excelCikis - (float)$donem['kullanilan'];
    $bekleyen = count($elleBekleyen);
    $sorun = $bekleyen > 0 || abs($gunFarkC) >= 0.5 || abs($gunFarkG) >= 0.5; ?>
<div class="alert <?= $sorun ? 'alert-warning' : 'alert-success' ?> py-2 small">
    <i class="bi bi-<?= $sorun ? 'exclamation-triangle' : 'check-circle' ?> me-1"></i>
    <strong>Excel mutabakatı — <?= h($donem['donem']) ?>:</strong>
    Excel aylık özet: gelen <strong><?= $f0($donem['gelen']) ?></strong> · kullanılan <strong><?= $f0($donem['kullanilan']) ?></strong> Lt.
    Excel günlük hücreler: gelen <strong><?= $f0($excelGiris) ?></strong>
    · kullanılan <strong><?= $f0($excelCikis) ?></strong>
    <?php if (abs($gunFarkG) >= 0.5 || abs($gunFarkC) >= 0.5): ?>
        <span class="text-danger">— günlük hücreler aylık özetle tutmuyor
        (<?= abs($gunFarkG) >= 0.5 ? 'gelen ' . ($gunFarkG > 0 ? '+' : '') . $f0($gunFarkG) : '' ?><?= abs($gunFarkG) >= 0.5 && abs($gunFarkC) >= 0.5 ? ', ' : '' ?><?= abs($gunFarkC) >= 0.5 ? 'kullanılan ' . ($gunFarkC > 0 ? '+' : '') . $f0($gunFarkC) : '' ?>)</span>
    <?php endif; ?>.
    <?php $sentetik = array_filter($defter, fn($r) => !empty($r['sentetik'])); if ($sentetik): ?>
        <span class="d-block mt-1"><i class="bi bi-info-circle me-1"></i>Fark, <strong>"Excel özet"</strong> rozetli
        <?= count($sentetik) ?> satırla deftere eklendi ki ay sonu bakiyesi Excel'in KALAN'ıyla birebir olsun
        (geliş günü yazılmayan mazot ayın 1'ine, araç detayı girilmeyen tüketim ayın sonuna yazılır).</span>
    <?php endif; ?>
    <?php if ($bekleyen): ?>
        <span class="d-block mt-1"><strong><?= $bekleyen ?> elle kayıt</strong> (<?= $f0(array_sum(array_map(fn($r) => $r['giris'] + $r['cikis'], $elleBekleyen))) ?> Lt)
        henüz Excel'de yok — ay sonunda Excel'e işlenip <a href="import.php" class="alert-link">içe aktarılınca</a>
        "Excel'e işlendi" rozetine döner. <strong>Excel esastır</strong>; işlenene kadar defter bu kayıtları da sayar.</span>
    <?php elseif ($elleIslenen): ?>
        <span class="d-block mt-1"><?= $elleIslenen ?> elle kayıt Excel'e işlenmiş (bir kez sayıldı).</span>
    <?php endif; ?>
</div>
<?php elseif ($f['ay'] !== ''): ?>
<div class="alert alert-secondary py-2 small">
    <i class="bi bi-info-circle me-1"></i>Bu ay için Excel dönem kaydı yok — listede yalnız elle girilen hareketler var,
    açılış devri <strong>0</strong> alındı.
    <a href="import.php" class="alert-link">Aylık Excel'i yükleyin</a>.
</div>
<?php endif; ?>

<div class="row g-2 mb-3">
<?php
$kpi = [
    ['Giriş',   $f0($tGiris) . ' Lt', 'success', 'bi-box-arrow-in-down'],
    ['Çıkış',   $f0($tCikis) . ' Lt', 'danger',  'bi-box-arrow-up'],
    ['Net',     ($tGiris - $tCikis >= 0 ? '+' : '') . $f0($tGiris - $tCikis) . ' Lt', 'primary', 'bi-arrow-left-right'],
    [$bakiyeGoster ? 'Dönem sonu bakiye' : 'Süzgeçli net', $f0($devir + $tGiris - $tCikis) . ' Lt', 'info', 'bi-fuel-pump'],
    ['Hareket', $f0(count($defter)) . ' <span class="small fw-normal text-muted">(' . $f0($excelAdet) . ' Excel)</span>', 'secondary', 'bi-list-ol'],
    [count($elleBekleyen) ? "Excel'de olmayan elle kayıt" : 'Araç',
     count($elleBekleyen) ? $f0(count($elleBekleyen)) : $f0($aracAdet),
     count($elleBekleyen) ? 'warning' : 'dark', count($elleBekleyen) ? 'bi-exclamation-diamond' : 'bi-truck'],
];
foreach ($kpi as [$ad, $deger, $renk, $ikon]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">
            <i class="bi <?= $ikon ?> text-<?= $renk ?> fs-5"></i>
            <div class="fs-6 fw-bold mt-1"><?= $deger ?></div>
            <div class="small text-muted"><?= $ad ?></div>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Ay</label>
            <input type="month" name="ay" class="form-control form-control-sm" value="<?= h($f['ay']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Başlangıç</label>
            <input type="date" name="bas" class="form-control form-control-sm" value="<?= h($_GET['bas'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Bitiş</label>
            <input type="date" name="bit" class="form-control form-control-sm" value="<?= h($_GET['bit'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Tür</label>
            <select name="tur" class="form-select form-select-sm">
                <option value="">Giriş + Çıkış</option>
                <option value="giris" <?= $f['tur'] === 'giris' ? 'selected' : '' ?>>Yalnız giriş</option>
                <option value="cikis" <?= $f['tur'] === 'cikis' ? 'selected' : '' ?>>Yalnız çıkış</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Araç / Şoför</label>
            <select name="arac_id" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($araclar as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $f['arac_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= h(trim($a['sofor'] . ' · ' . $a['cinsi'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Ara</label>
            <input type="text" name="ara" class="form-control form-control-sm" value="<?= h($f['ara']) ?>" placeholder="firma / plaka / belge">
        </div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrele</button>
            <a href="hareketler.php" class="btn btn-outline-secondary btn-sm">Temizle</a>
            <span class="small text-muted align-self-center">
                Ay seçiliyken tarih aralığı boş bırakılırsa ayın tamamı listelenir; tarih verirseniz o öncelikli olur.
                <?php if (!$bakiyeGoster): ?><strong class="text-warning-emphasis">Tür/araç süzgeci açıkken bakiye sütunu
                gizlenir</strong> — hareketlerin bir kısmı listede olmadığından yürüyen bakiye yanıltırdı.<?php endif; ?>
            </span>
        </div>
    </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:.84rem">
    <thead class="table-light"><tr>
        <th>Tarih</th><th>Tür</th><th>Belge No</th><th>Taraf</th><th>Detay</th><th>Plaka</th>
        <th class="text-end">Giriş</th><th class="text-end">Çıkış</th>
        <?php if ($bakiyeGoster): ?><th class="text-end">Bakiye</th><?php endif; ?>
        <th>Sayaç</th><th>Evrak</th><th></th>
    </tr></thead>
    <tbody>
    <?php if ($bakiyeGoster && abs($devir) > 0.001): ?>
        <tr class="table-light">
            <td colspan="8" class="fst-italic text-muted">Devir — <?= h($devirKaynak) ?></td>
            <td class="text-end fw-bold"><?= $f0($devir) ?></td><td colspan="3"></td>
        </tr>
    <?php endif; ?>
    <?php $bak = $devir; foreach ($defter as $r):
        $sayilir = !empty($r['sayilir']);
        if ($sayilir) $bak += $r['giris'] - $r['cikis']; ?>
        <tr class="<?= $sayilir ? '' : 'opacity-50' ?><?= !empty($r['sentetik']) ? ' fst-italic' : '' ?>" <?= $sayilir ? '' : 'title="Bu elle kayıt Excel\'e işlenmiş — Excel satırı sayıldı, bu satır toplama girmez"' ?>>
            <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
            <td class="text-nowrap"><span class="badge bg-<?= $r['tur'] === 'giris' ? 'success' : 'danger' ?>">
                <?= $r['tur'] === 'giris' ? 'Giriş' : 'Çıkış' ?></span>
                <?php if ($r['kaynak'] === 'excel' && !empty($r['sentetik'])): ?>
                    <span class="badge bg-info text-dark" title="Excel'in aylık özet hücresi ile günlük hücreleri arasındaki fark — gün bilgisi Excel'de yok">Excel özet</span>
                <?php elseif ($r['kaynak'] === 'excel'): ?>
                    <span class="badge bg-light text-dark border" title="Excel aylık sayfasındaki günlük hücreden">Excel</span>
                <?php elseif ($sayilir): ?>
                    <span class="badge bg-warning text-dark" title="Elle girildi, Excel'de henüz yok — ay sonunda Excel'e işlenmeli">Excel'de yok</span>
                <?php else: ?>
                    <span class="badge bg-secondary" title="Elle girilmişti, Excel'e işlenmiş — bir kez sayıldı">Excel'e işlendi ✓</span>
                <?php endif; ?></td>
            <td class="small font-monospace"><?= h($r['belge_no'] ?: '—') ?></td>
            <td class="fw-semibold"><?= h($r['taraf'] ?: '—') ?></td>
            <td class="small text-muted"><?= h($r['detay'] ?: '—') ?>
                <?php if ($r['aciklama']): ?><div class="fst-italic"><?= h($r['aciklama']) ?></div><?php endif; ?></td>
            <td class="small font-monospace"><?= h($r['plaka'] ?: '—') ?></td>
            <td class="text-end fw-bold text-success"><?= $r['giris'] > 0 ? $f0($r['giris']) : '' ?></td>
            <td class="text-end fw-bold text-danger"><?= $r['cikis'] > 0 ? $f0($r['cikis']) : '' ?></td>
            <?php if ($bakiyeGoster): ?>
            <td class="text-end fw-semibold <?= $bak < 0 ? 'text-danger' : '' ?>"><?= $f0($bak) ?></td>
            <?php endif; ?>
            <td class="small text-muted"><?php if ($r['sayac'] !== null && $r['sayac'] !== ''): ?><?= h($r['sayac']) ?>
                <?php else: ?><span title="Km / Mak. saati girilmemiş">—</span><?php endif; ?></td>
            <td class="text-nowrap">
                <?php if ($r['kaynak'] === 'excel'): ?><span class="text-muted">—</span>
                <?php elseif (!empty($r['evrak_url'])): ?>
                    <a href="../<?= h($r['evrak_url']) ?>" target="_blank" class="btn btn-sm btn-success py-0" title="Belgeyi aç"><i class="bi bi-file-earmark-check"></i></a>
                <?php elseif ($r['tur'] === 'giris'): ?>
                    <form method="post" enctype="multipart/form-data" class="d-inline-flex gap-1">
                        <input type="hidden" name="action" value="giris_evrak">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="file" name="evrak" class="form-control form-control-sm py-0" style="width:130px" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                        <button class="btn btn-sm btn-outline-secondary py-0" title="İrsaliyeyi yükle"><i class="bi bi-upload"></i></button>
                    </form>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
                <?php if ($r['kaynak'] === 'excel'): ?>
                    <?php if ($r['arac_id']): ?>
                    <a href="araclar.php" class="btn btn-sm btn-outline-secondary py-0" title="Araç yakıt geçmişi (Excel)"><i class="bi bi-clock-history"></i></a>
                    <?php else: ?>
                    <a href="stok.php" class="btn btn-sm btn-outline-secondary py-0" title="Dönem stok zinciri"><i class="bi bi-fuel-pump"></i></a>
                    <?php endif; ?>
                <?php elseif ($r['tur'] === 'giris'): ?>
                    <a href="<?= h($qs(['duzenle'=>$r['id']])) ?>" class="btn btn-sm btn-outline-secondary py-0" title="Düzenle"><i class="bi bi-pencil"></i></a>
                    <?php if ($yetkili): ?>
                    <a href="<?= h($qs(['giris_sil'=>$r['id']])) ?>" class="btn btn-sm btn-outline-danger py-0"
                       onclick="return confirm('Giriş kaydı silinsin mi?')" title="Sil"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="cikis_tutanak.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Çıkış fişi"><i class="bi bi-printer"></i></a>
                    <a href="cikislar.php?ay=<?= h(substr($r['tarih'], 0, 7)) ?>" class="btn btn-sm btn-outline-secondary py-0" title="Çıkış ekranında aç"><i class="bi bi-box-arrow-up-right"></i></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$defter): ?>
        <tr><td colspan="<?= $bakiyeGoster ? 12 : 11 ?>" class="text-center text-muted py-4">
            Bu aralıkta hareket yok. <a href="#" data-bs-toggle="modal" data-bs-target="#girisModal" onclick="girisAc()">Giriş ekleyin</a>
            ya da <a href="cikislar.php">çıkış kaydı</a> girin.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($defter): ?>
    <tfoot class="table-light fw-semibold"><tr>
        <td colspan="6">TOPLAM<?= $tTutar > 0 ? ' — giriş tutarı ' . $f2($tTutar) . ' TL' : '' ?></td>
        <td class="text-end text-success"><?= $f0($tGiris) ?></td>
        <td class="text-end text-danger"><?= $f0($tCikis) ?></td>
        <?php if ($bakiyeGoster): ?><td class="text-end"><?= $f0($devir + $tGiris - $tCikis) ?></td><?php endif; ?>
        <td colspan="3"></td>
    </tr></tfoot>
    <?php endif; ?>
</table>
</div></div></div>

<!-- Yeni / düzenle giriş -->
<div class="modal fade" id="girisModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
      <input type="hidden" name="action" value="giris_kaydet">
      <input type="hidden" name="id" id="gId" value="">
      <div class="modal-header">
        <h6 class="modal-title"><i class="bi bi-box-arrow-in-down text-success me-2"></i><span id="gBaslik">Yeni Mazot Girişi</span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-3"><label class="form-label small mb-1">Tarih *</label>
            <input type="date" name="tarih" id="gTarih" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-3"><label class="form-label small mb-1">İrsaliye / Fiş No</label>
            <input type="text" name="belge_no" id="gBelge" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small mb-1">Tedarikçi (mazotu getiren firma)</label>
            <input type="text" name="tedarikci" id="gTedarikci" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small mb-1">Miktar (Lt) *</label>
            <input type="text" name="miktar" id="gMiktar" class="form-control form-control-sm" inputmode="decimal" required></div>
          <div class="col-md-3"><label class="form-label small mb-1">Birim Fiyat (TL)</label>
            <input type="text" name="birim_fiyat" id="gBfiyat" class="form-control form-control-sm" inputmode="decimal"></div>
          <div class="col-md-3"><label class="form-label small mb-1">Tutar (TL)</label>
            <input type="text" name="tutar" id="gTutar" class="form-control form-control-sm" inputmode="decimal">
            <div class="form-text" style="font-size:.72rem">Boş bırakılırsa miktar × birim fiyat yazılır.</div></div>
          <div class="col-md-3"><label class="form-label small mb-1">Tanker Plakası</label>
            <input type="text" name="plaka" id="gPlaka" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small mb-1">Teslim Alan</label>
            <input type="text" name="teslim_alan" id="gTeslim" class="form-control form-control-sm"></div>
          <div class="col-md-8"><label class="form-label small mb-1">Açıklama</label>
            <input type="text" name="aciklama" id="gAciklama" class="form-control form-control-sm"></div>
        </div>
        <div class="form-text mt-2">
          Kaydettikten sonra satırdaki yükleme kutusundan <strong>irsaliye/fatura taramasını</strong> ekleyebilirsiniz.
          Stok zinciri Excel'den kurulur; bu kayıt ay sonunda Excel'e de işlenmelidir.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Kaydet</button>
      </div>
    </form>
  </div></div>
</div>

<script>
function girisAc(d) {
    document.getElementById('gBaslik').textContent = d ? 'Girişi Düzenle' : 'Yeni Mazot Girişi';
    document.getElementById('gId').value        = d ? d.id : '';
    document.getElementById('gTarih').value     = d ? d.tarih : '<?= date('Y-m-d') ?>';
    document.getElementById('gBelge').value     = d ? (d.belge_no || '') : '';
    document.getElementById('gTedarikci').value = d ? (d.tedarikci || '') : '';
    document.getElementById('gMiktar').value    = d ? d.miktar_lt : '';
    document.getElementById('gBfiyat').value    = d ? (d.birim_fiyat || '') : '';
    document.getElementById('gTutar').value     = d ? (d.tutar || '') : '';
    document.getElementById('gPlaka').value     = d ? (d.plaka || '') : '';
    document.getElementById('gTeslim').value    = d ? (d.teslim_alan || '') : '';
    document.getElementById('gAciklama').value  = d ? (d.aciklama || '') : '';
}
<?php if ($duzenle): ?>
// ?duzenle=ID ile gelindiyse modal dolu açılır
document.addEventListener('DOMContentLoaded', function () {
    girisAc(<?= json_encode($duzenle, JSON_UNESCAPED_UNICODE) ?>);
    new bootstrap.Modal(document.getElementById('girisModal')).show();
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
