<?php
/** import.php — Depo Excel içe aktarma (DEMİRBAŞLAR / SARF MALZEME / EL ALETLERİ) — tam yenileme */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

$pageTitle = 'Depo Excel İçe Aktarma';
$sonuc = null; $hata = null;

function dpSheet(SimpleXLSX $x, array $adlar): ?int {
    foreach ($x->sheetNames() as $i=>$n){ $u=mb_strtoupper(trim($n),'UTF-8'); foreach($adlar as $a) if(mb_strtoupper($a,'UTF-8')===$u) return $i; }
    return null;
}
function dpHeader(array $rows): int { foreach($rows as $ri=>$row){ foreach($row as $c) if(mb_stripos((string)$c,'SAYIM')!==false) return $ri; } return 1; }

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['dosya']['tmp_name'])) {
    if (!($x = SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) { $hata='Excel okunamadı: '.SimpleXLSX::parseError(); }
    else {
        $r = ['demirbas'=>0,'sarf'=>0,'el_aleti'=>0];
        // [kategori, sayfa adları, elAleti mi]
        $harita = [
            'demirbas' => [['DEMİRBAŞLAR','DEMIRBASLAR'], false],
            'sarf'     => [['SARF MALZEME'], false],
            'el_aleti' => [['EL ALETLERİ','EL ALETLERI'], true],
        ];
        try {
            $pdoDepo->beginTransaction();
            $ins = $pdoDepo->prepare("INSERT INTO depo_kalemler
                (kategori,sira,kod,ad,ozellik,birim,sayim,gelen,giden,birim_fiyat,disiplin,alan,alan_kisi)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($harita as $kat => [$adlar,$elAleti]) {
                $si = dpSheet($x, $adlar);
                if ($si === null) continue;
                $pdoDepo->prepare("DELETE FROM depo_kalemler WHERE kategori=?")->execute([$kat]);
                $rows = $x->rows($si, 5000);
                $hr = dpHeader($rows);
                for ($i=$hr+1;$i<count($rows);$i++){
                    $row = $rows[$i];
                    $ad = trim((string)($row[2]??''));
                    if ($ad === '') continue;
                    if ($elAleti) {
                        $ins->execute([$kat, (int)($row[0]??0)?:null, trim((string)($row[1]??''))?:null, $ad, trim((string)($row[3]??''))?:null,
                            trim((string)($row[4]??'Adet'))?:'Adet', dp_sayi($row[5]??''), dp_sayi($row[6]??''), dp_sayi($row[7]??''),
                            null, null, trim((string)($row[10]??''))?:null, trim((string)($row[9]??''))?:null]);
                    } else {
                        $ins->execute([$kat, (int)($row[0]??0)?:null, trim((string)($row[1]??''))?:null, $ad, trim((string)($row[3]??''))?:null,
                            trim((string)($row[4]??'Ad'))?:'Ad', dp_sayi($row[5]??''), dp_sayi($row[6]??''), dp_sayi($row[7]??''),
                            dp_sayi($row[9]??'')?:null, trim((string)($row[11]??''))?:null, trim((string)($row[12]??''))?:null, null]);
                    }
                    $r[$kat]++;
                }
            }
            $pdoDepo->commit();
            $sonuc = $r;
        } catch (Throwable $e) { $pdoDepo->rollBack(); $hata='İçe aktarma hatası: '.$e->getMessage(); }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Depo Excel İçe Aktarma</h4>
<?php if($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if($sonuc): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>İçe aktarma tamam:
    <strong><?= (int)$sonuc['demirbas'] ?></strong> demirbaş, <strong><?= (int)$sonuc['sarf'] ?></strong> sarf malzeme,
    <strong><?= (int)$sonuc['el_aleti'] ?></strong> el aleti.
    <div class="small mt-1"><a href="index.php" class="alert-link">Dashboard</a> · <a href="kalemler.php?k=demirbas" class="alert-link">Demirbaşlar</a></div>
</div>
<?php endif; ?>
<div class="card"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-9"><label class="form-label small">Sarf/Demirbaş Excel (.xlsx) — <strong>DEMİRBAŞLAR</strong>, <strong>SARF MALZEME</strong>, <strong>EL ALETLERİ</strong> sayfaları</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required></div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Eşitle</button></div>
    </form>
    <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i><strong>Tam yenileme:</strong> her kategori için önceki kayıtlar silinip yeniden yazılır. Stok = Sayım + Gelen − Giden.</div>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
