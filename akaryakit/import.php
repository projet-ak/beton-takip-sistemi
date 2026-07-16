<?php
/** import.php — Akaryakıt Excel içe aktarma (aylık tüketim + stok + tutanak) — dönem bazlı tam yenileme */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_akaryakit.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

$pageTitle = 'Akaryakıt Excel İçe Aktarma';
$sonuc = null; $hata = null;

/** Satırda hedef metni ara → satır index; yoksa -1 */
function ak_baslikSatiri(array $rows, array $hedefler): int {
    foreach ($rows as $ri=>$row) {
        foreach ($row as $c) {
            $u = mb_strtoupper(trim((string)$c),'UTF-8');
            if (in_array($u, $hedefler, true)) return $ri;
        }
    }
    return -1;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['dosya']['tmp_name'])) {
    if (!($x = SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) { $hata='Excel okunamadı: '.SimpleXLSX::parseError(); }
    else {
        $st = ['ay'=>0,'arac'=>0,'tuketim'=>0,'tutanak'=>0,'donem'=>[]];
        try {
            $pdoAkaryakit->beginTransaction();

            foreach ($x->sheetNames() as $si => $sheetName) {
                $rows = $x->rows($si, 2000);
                if (!$rows) continue;
                $isTutanak = mb_stripos($sheetName,'TUTANAK')!==false;

                if ($isTutanak) {
                    // ── TUTANAK sayfası ──
                    $hr = ak_baslikSatiri($rows, ['SIRA NO','S.NO']);
                    if ($hr < 0) continue;
                    // Dönem = ad içindeki "TUTANAK" kelimesini at
                    $donem = ak_donemAd(preg_replace('/TUTANAK/iu','',$sheetName));
                    if ($donem==='') $donem = ak_donemAd($sheetName);
                    $dsira = ak_donemSira($donem);
                    $pdoAkaryakit->prepare("DELETE FROM akaryakit_tutanak WHERE donem=?")->execute([$donem]);
                    $ins = $pdoAkaryakit->prepare("INSERT INTO akaryakit_tutanak
                        (donem,donem_sira,sira,sofor,arac_detay,firma_detay,miktar) VALUES (?,?,?,?,?,?,?)");
                    for ($i=$hr+1; $i<count($rows); $i++) {
                        $row = $rows[$i];
                        $sofor = trim((string)($row[1]??''));
                        $sira  = trim((string)($row[0]??''));
                        // Şoför boş veya ONAY/PROJE gibi satırları atla; toplam satırı (yalnız miktar) atla
                        if ($sofor==='' || !ctype_digit($sira)) continue;
                        $ins->execute([$donem,$dsira, (int)$sira, $sofor,
                            trim((string)($row[2]??''))?:null, trim((string)($row[3]??''))?:null, ak_sayi($row[4]??'')]);
                        $st['tutanak']++;
                    }
                    if (!in_array($donem,$st['donem'],true)) $st['donem'][]=$donem;
                    continue;
                }

                // ── AYLIK TÜKETİM sayfası ──
                $hr = ak_baslikSatiri($rows, ['S.NO']);
                if ($hr < 0) continue;
                $donem = ak_donemAd($sheetName);
                $dsira = ak_donemSira($donem);

                // Stok özeti: col7 etiketleri
                $devir=0; $gelen=0; $kullanilan=0;
                foreach ($rows as $row) {
                    $lbl = mb_strtoupper(trim((string)($row[7]??'')),'UTF-8');
                    $val = ak_sayi($row[8]??'');
                    if ($lbl==='DEVİR' || $lbl==='DEVIR') $devir=$val;
                    elseif ($lbl==='YENİ GELEN' || $lbl==='YENI GELEN') $gelen=$val;
                    elseif ($lbl==='KULLANILAN') $kullanilan=$val;
                }
                $toplam = $devir + $gelen;
                $kalan  = $toplam - $kullanilan;

                // Dönem stok kaydı (upsert)
                $pdoAkaryakit->prepare("INSERT INTO akaryakit_donemler
                    (donem,donem_sira,devir,gelen,toplam,kullanilan,kalan) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE donem_sira=VALUES(donem_sira),devir=VALUES(devir),gelen=VALUES(gelen),
                    toplam=VALUES(toplam),kullanilan=VALUES(kullanilan),kalan=VALUES(kalan)")
                    ->execute([$donem,$dsira,$devir,$gelen,$toplam,$kullanilan,$kalan]);
                $st['ay']++;

                // Araç tüketim satırları — tam yenileme (bu dönem)
                $pdoAkaryakit->prepare("DELETE FROM akaryakit_tuketim WHERE donem=?")->execute([$donem]);
                $insT = $pdoAkaryakit->prepare("INSERT INTO akaryakit_tuketim
                    (donem,donem_sira,arac_id,aylik_tuketim,aylik_calisma,ortalama,onceki_okuma,ilk_okuma,son_okuma,not1,not2,gunluk)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

                for ($i=$hr+2; $i<count($rows); $i++) { // hr+1 = Mazot/Km alt başlığı
                    $row = $rows[$i];
                    $sofor = trim((string)($row[6]??''));
                    $cinsi = trim((string)($row[7]??''));
                    if ($sofor==='' && $cinsi==='') continue;
                    if ($sofor==='') $sofor = $cinsi; // güvence
                    $aracId = ak_aracId($pdoAkaryakit, [
                        'sinif'=>trim((string)($row[1]??'')), 'mak_no'=>trim((string)($row[2]??'')),
                        'lokasyon'=>trim((string)($row[3]??'')), 'firma'=>trim((string)($row[4]??'')),
                        'plaka'=>trim((string)($row[5]??'')), 'sofor'=>$sofor, 'cinsi'=>$cinsi,
                    ]);
                    // Günlük: gün d → mazot col 7+2d, km col 8+2d
                    $gunluk=[]; $toplamMazot=0;
                    for ($d=1; $d<=31; $d++) {
                        $mz = ak_sayi($row[7+2*$d] ?? '');
                        $km = ak_sayi($row[8+2*$d] ?? '');
                        if ($mz!=0.0 || $km!=0.0) { $gunluk[]=['g'=>$d,'mz'=>$mz,'km'=>$km]; $toplamMazot+=$mz; }
                    }
                    $aylik = ak_sayi($row[71]??''); if ($aylik==0.0) $aylik=$toplamMazot;
                    $insT->execute([$donem,$dsira,$aracId,$aylik,
                        ak_sayi($row[72]??'')?:null, ak_sayi($row[73]??'')?:null,
                        ak_sayi($row[74]??'')?:null, ak_sayi($row[75]??'')?:null, ak_sayi($row[76]??'')?:null,
                        trim((string)($row[77]??''))?:null, trim((string)($row[78]??''))?:null,
                        $gunluk?json_encode($gunluk,JSON_UNESCAPED_UNICODE):null]);
                    $st['tuketim']++;
                }
                if (!in_array($donem,$st['donem'],true)) $st['donem'][]=$donem;
            }

            $st['arac'] = (int)$pdoAkaryakit->query("SELECT COUNT(*) FROM akaryakit_araclar")->fetchColumn();
            $pdoAkaryakit->commit();
            $sonuc = $st;
        } catch (Throwable $e) { $pdoAkaryakit->rollBack(); $hata='İçe aktarma hatası: '.$e->getMessage(); }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Akaryakıt Excel İçe Aktarma</h4>
<?php if($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if($sonuc): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>İçe aktarma tamam:
    <strong><?= (int)$sonuc['ay'] ?></strong> ay (stok), <strong><?= (int)$sonuc['tuketim'] ?></strong> araç-tüketim satırı,
    <strong><?= (int)$sonuc['tutanak'] ?></strong> tutanak satırı · toplam <strong><?= (int)$sonuc['arac'] ?></strong> araç/makine tanımlı.
    <?php if($sonuc['donem']): ?><div class="small mt-1">Dönemler: <?= h(implode(', ',$sonuc['donem'])) ?></div><?php endif; ?>
    <div class="small mt-1"><a href="index.php" class="alert-link">Dashboard</a> · <a href="aylik.php" class="alert-link">Aylık Takip</a></div>
</div>
<?php endif; ?>
<div class="card"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-9"><label class="form-label small">Akaryakıt Takip Excel (.xlsx) — aylık sayfalar (<strong>OCAK 2026</strong>…) + <strong>TUTANAK</strong> sayfaları</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required></div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Eşitle</button></div>
    </form>
    <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i><strong>Tam yenileme (dönem bazlı):</strong> her ay için stok + araç tüketimi silinip yeniden yazılır.
        Araçlar <strong>Şoför + Cinsi</strong> ile eşleştirilir. Stok = Devir + Gelen − Kullanılan (Excel esas). Günlük 31 günün detayı saklanır.</div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
