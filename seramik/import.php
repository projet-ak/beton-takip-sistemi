<?php
/**
 * import.php — Seramik Excel içe aktarma (AMBAR GİRİŞ / AMBAR ÇIKIŞ / PALET DURUMU)
 * Tam yenileme: bulunan her sayfa için o tip kayıtlar silinip yeniden yazılır (Excel = doğru kaynak).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_seramik.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

$pageTitle = 'Seramik Excel İçe Aktarma';
$sonuc = null; $hata = null;

function sheetIndex(SimpleXLSX $x, array $adlar): ?int {
    foreach ($x->sheetNames() as $i => $n) {
        $u = mb_strtoupper(trim($n), 'UTF-8');
        foreach ($adlar as $a) if (mb_strtoupper($a,'UTF-8') === $u) return $i;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['dosya']['tmp_name'])) {
    if (!($x = SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        $hata = 'Excel okunamadı: ' . SimpleXLSX::parseError();
    } else {
        $r = ['giris'=>0,'cikis'=>0,'palet'=>0,'malzeme'=>0];
        try {
            $pdoSeramik->beginTransaction();

            // ── AMBAR GİRİŞ ──────────────────────────────────────────────
            $gi = sheetIndex($x, ['AMBAR GİRİŞ','AMBAR GIRIS']);
            if ($gi !== null) {
                $pdoSeramik->exec("DELETE FROM seramik_giris WHERE kaynak='excel'");
                $rows = $x->rows($gi, 5000);
                // başlık satırı: SIRA içeren satır
                $hr = 0; foreach ($rows as $ri=>$row){ foreach($row as $c){ if(mb_stripos((string)$c,'SIRA')!==false){ $hr=$ri; break 2; } } }
                $ins = $pdoSeramik->prepare("INSERT INTO seramik_giris
                    (sira,gelis_tarihi,belge_tarihi,belge_no,malzeme_id,miktar,birim,birim_fiyat,toplam,firma_id,arac_plaka,geldigi_birim,teslim_alan,palet_adet,kdv_oran,aciklama,kaynak)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'excel')");
                for ($i=$hr+1;$i<count($rows);$i++){
                    $row=$rows[$i];
                    $mal = trim((string)($row[4]??'')); $mik = sr_sayi($row[5]??'');
                    if ($mal==='' && $mik==0) continue;
                    $mid = sr_malzemeId($pdoSeramik, $mal, 'SERAMİK', trim((string)($row[6]??'M2')) ?: 'M2');
                    $ins->execute([
                        (int)($row[0]??0) ?: null, sr_tarih($row[1]??''), sr_tarih($row[2]??''), trim((string)($row[3]??'')) ?: null,
                        $mid, $mik, trim((string)($row[6]??'M2')) ?: 'M2', sr_sayi($row[7]??'') ?: null, sr_sayi($row[8]??'') ?: null,
                        sr_adId($pdoSeramik,'seramik_firmalar',$row[9]??''), trim((string)($row[10]??'')) ?: null,
                        trim((string)($row[11]??'')) ?: null, trim((string)($row[13]??'')) ?: null,
                        trim((string)($row[14]??'')) ?: null, trim((string)($row[15]??'')) ?: null, trim((string)($row[12]??'')) ?: null,
                    ]);
                    $r['giris']++;
                }
            }

            // ── AMBAR ÇIKIŞ ──────────────────────────────────────────────
            $ci = sheetIndex($x, ['AMBAR ÇIKIŞ','AMBAR CIKIS']);
            if ($ci !== null) {
                $pdoSeramik->exec("DELETE FROM seramik_cikis WHERE kaynak='excel'");
                $rows = $x->rows($ci, 5000);
                $hr = 0; foreach ($rows as $ri=>$row){ foreach($row as $c){ if(mb_stripos((string)$c,'SIRA')!==false){ $hr=$ri; break 2; } } }
                $ins = $pdoSeramik->prepare("INSERT INTO seramik_cikis
                    (sira,cikis_tarihi,fis_no,taseron_id,cikis_yeri,cikis_kodu,malzeme_id,miktar,birim,birim_fiyat,toplam,onay,palet_adet,teslim_alan_firma,aciklama,kaynak)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'excel')");
                for ($i=$hr+1;$i<count($rows);$i++){
                    $row=$rows[$i];
                    $mal = trim((string)($row[6]??'')); $mik = sr_sayi($row[7]??'');
                    if ($mal==='' && $mik==0) continue;
                    $mid = sr_malzemeId($pdoSeramik, $mal, 'SERAMİK', trim((string)($row[8]??'M2')) ?: 'M2');
                    $palet = trim(trim((string)($row[13]??'')).' '.trim((string)($row[14]??'')));
                    $ins->execute([
                        (int)($row[0]??0) ?: null, sr_tarih($row[1]??''), trim((string)($row[2]??'')) ?: null,
                        sr_adId($pdoSeramik,'seramik_taseronlar',$row[3]??''), trim((string)($row[4]??'')) ?: (trim((string)($row[11]??'')) ?: null),
                        trim((string)($row[5]??'')) ?: null, $mid, $mik, trim((string)($row[8]??'M2')) ?: 'M2',
                        sr_sayi($row[9]??'') ?: null, sr_sayi($row[10]??'') ?: null, trim((string)($row[12]??'')) ?: null,
                        $palet ?: null, trim((string)($row[15]??'')) ?: null, trim((string)($row[11]??'')) ?: null,
                    ]);
                    $r['cikis']++;
                }
            }

            // ── AMBAR MEVCUT (Sayım = inbound baz) ───────────────────────
            $mi = sheetIndex($x, ['AMBAR MEVCUT']);
            if ($mi !== null) {
                $pdoSeramik->exec("DELETE FROM seramik_sayim");
                $rows = $x->rows($mi, 500);
                $hr = 0; foreach ($rows as $ri=>$row){ foreach($row as $c){ if(mb_stripos((string)$c,'SAYIM')!==false){ $hr=$ri; break 2; } } }
                $ins = $pdoSeramik->prepare("INSERT INTO seramik_sayim (malzeme_id,sayim,giden_excel,stok_excel)
                    VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE sayim=VALUES(sayim),giden_excel=VALUES(giden_excel),stok_excel=VALUES(stok_excel)");
                for ($i=$hr+1;$i<count($rows);$i++){
                    $row=$rows[$i];
                    $ozellik = trim((string)($row[1]??''));   // MALZEME ÖZELLİKLERİ
                    if ($ozellik==='') continue;
                    $mid = sr_malzemeId($pdoSeramik, $ozellik, trim((string)($row[0]??'SERAMİK')) ?: 'SERAMİK', trim((string)($row[2]??'M2')) ?: 'M2');
                    $ins->execute([$mid, sr_sayi($row[3]??''), sr_sayi($row[4]??'') ?: null, sr_sayi($row[5]??'') ?: null]);
                    $r['mevcut'] = ($r['mevcut'] ?? 0) + 1;
                }
            }

            // ── PALET DURUMU ─────────────────────────────────────────────
            $pi = sheetIndex($x, ['PALET DURUMU']);
            if ($pi !== null) {
                $pdoSeramik->exec("DELETE FROM seramik_palet");
                $rows = $x->rows($pi, 500);
                $ins = $pdoSeramik->prepare("INSERT INTO seramik_palet (sira,tarih,aciklama,palet_adet,durum) VALUES (?,?,?,?,?)");
                foreach ($rows as $row) {
                    $s0 = trim((string)($row[0]??''));
                    if ($s0==='' || !is_numeric($s0)) continue;
                    $ins->execute([(int)$s0, sr_tarih($row[1]??''), trim((string)($row[2]??'')) ?: null, trim((string)($row[3]??'')) ?: null, trim((string)($row[4]??'')) ?: null]);
                    $r['palet']++;
                }
            }

            $r['malzeme'] = (int)$pdoSeramik->query("SELECT COUNT(*) FROM seramik_malzemeler")->fetchColumn();
            $pdoSeramik->commit();
            $sonuc = $r;
        } catch (Throwable $e) {
            $pdoSeramik->rollBack();
            $hata = 'İçe aktarma hatası: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Seramik Excel İçe Aktarma</h4>

<?php if ($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if ($sonuc): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-1"></i>İçe aktarma tamam:
    <strong><?= (int)$sonuc['giris'] ?></strong> giriş, <strong><?= (int)$sonuc['cikis'] ?></strong> çıkış,
    <strong><?= (int)($sonuc['mevcut']??0) ?></strong> sayım (mevcut), <strong><?= (int)$sonuc['palet'] ?></strong> palet ·
    <strong><?= (int)$sonuc['malzeme'] ?></strong> malzeme çeşidi.
    <div class="small mt-1 text-muted">Stok = Sayım (Mevcut) + elle giriş − çıkışlar olarak canlı hesaplanır.</div>
    <div class="small mt-1"><a href="stok.php" class="alert-link">Stok</a> · <a href="index.php" class="alert-link">Dashboard</a></div>
</div>
<?php endif; ?>

<div class="card"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-9">
            <label class="form-label small">Seramik Takip Excel (.xlsx) — <strong>AMBAR GİRİŞ</strong>, <strong>AMBAR ÇIKIŞ</strong>, <strong>PALET DURUMU</strong> sayfaları okunur</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required>
        </div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i> Aktar / Eşitle</button></div>
    </form>
    <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i><strong>Tam yenileme:</strong> her aktarımda önceki Excel kayıtları silinip yeniden yazılır (Excel ile birebir eşitlenir). Elle girdiğiniz kayıtlar (kaynak=manuel) korunur. Malzeme çeşitleri ad benzerliğine göre otomatik birleştirilir.</div>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
