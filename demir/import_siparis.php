<?php
/**
 * demir/import_siparis.php — "Sipariş Takip" sayfasından demir siparişlerini içe aktarır.
 * Sipariş tanım satırlarını (firma + çap miktarı) sipariş olarak ekler; teslimat satırlarındaki
 * irsaliye no'larıyla mevcut sevkiyatları siparişe (IFS Sipariş No) bağlar → bakiye çalışır.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

$pageTitle = 'Sipariş İçe Aktar — Demir Takip';
$rapor = null; $hata = '';

function s_cell($r,$i){ return (!is_array($r) || $i>=count($r) || $r[$i]===null) ? '' : trim((string)$r[$i]); }
function s_num($v){ $v=str_replace(',','.',trim((string)$v)); return is_numeric($v)?(float)$v:0.0; }

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['dosya']['tmp_name'])) {
    if ($_FILES['dosya']['error']!==UPLOAD_ERR_OK) { $hata='Yükleme hatası.'; }
    elseif (!($xlsx=SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) { $hata='Excel okunamadı: '.SimpleXLSX::parseError(); }
    else {
        $si=null;
        foreach ($xlsx->sheetNames() as $i=>$n) { if (mb_stripos($n,'SİPARİŞ')!==false && mb_stripos($n,'TAKİP')!==false) { $si=$i; break; } }
        if ($si===null) foreach ($xlsx->sheetNames() as $i=>$n) { if (mb_stripos($n,'SIPARIŞ')!==false || mb_stripos($n,'SİPARİŞ')!==false) { $si=$i; break; } }
        if ($si===null) { $hata='"Sipariş Takip" sayfası bulunamadı.'; }
        else {
            $rows=$xlsx->rows($si, 3000);
            // çap başlık satırı: "Ø8" veya "KANGAL" içeren
            $capRow=-1;
            foreach ($rows as $ri=>$r) { foreach ($r as $v){ if($v!==null && (mb_stripos((string)$v,'KANGAL')!==false)){$capRow=$ri;break;} } if($capRow>=0)break; if($ri>15)break; }
            if ($capRow<0) { $hata='Çap başlık satırı bulunamadı.'; }
            else {
                // çap kolonları: capRow'da dolu hücreler (index>=14 civarı)
                $capCols=[]; // col => label
                foreach ($rows[$capRow] as $c=>$v){ $v=trim((string)$v); if($v!=='' && $c>=13){ $capCols[$c]=$v; } }

                // DB çaplar
                $capDb=$pdoDemir->query("SELECT id,ad,tip FROM demir_caplar")->fetchAll();
                $capMatch=function($label) use ($capDb){
                    preg_match('/(\d+)/', $label, $m); $num=isset($m[1])?(int)$m[1]:0;
                    $u=mb_strtoupper($label,'UTF-8');
                    $tip = mb_stripos($u,'KANGAL')!==false ? 'kangal' : (strpos($label,'/')!==false ? 'hasir' : (mb_stripos($u,'SPIRAL')!==false ? 'spiral' : 'duz'));
                    foreach ($capDb as $c){ preg_match('/(\d+)/',$c['ad'],$cm); if((isset($cm[1])?(int)$cm[1]:0)===$num && $c['tip']===$tip) return (int)$c['id']; }
                    foreach ($capDb as $c){ preg_match('/(\d+)/',$c['ad'],$cm); if((isset($cm[1])?(int)$cm[1]:0)===$num) return (int)$c['id']; }
                    return null;
                };
                $getTas=function($ad) use ($pdoDemir){ $ad=trim($ad); if($ad===''||$ad==='0')return null;
                    $s=$pdoDemir->prepare("SELECT id FROM demir_taseronlar WHERE UPPER(ad)=UPPER(?)"); $s->execute([$ad]);
                    if($id=$s->fetchColumn())return (int)$id;
                    $pdoDemir->prepare("INSERT INTO demir_taseronlar (ad) VALUES (?)")->execute([$ad]); return (int)$pdoDemir->lastInsertId(); };

                $eklenen=0; $atlanan=0; $baglanan=0; $olusturulanTas=[];
                $pdoDemir->beginTransaction();
                try {
                    $sipVar=$pdoDemir->prepare("SELECT id FROM demir_siparisler WHERE ifs_siparis_no=? LIMIT 1");
                    $insSip=$pdoDemir->prepare("INSERT INTO demir_siparisler (ifs_siparis_no, siparisi_olusturan, imalat, taseron_id, durum, created_by) VALUES (?,?,?,?, 'acik', ?)");
                    $insKlm=$pdoDemir->prepare("INSERT INTO demir_siparis_kalemleri (siparis_id, cap_id, miktar_ton) VALUES (?,?,?)");
                    $bagla=$pdoDemir->prepare("UPDATE demir_sevkiyatlar SET ifs_siparis_no=? WHERE irsaliye_no=? AND (ifs_siparis_no IS NULL OR ifs_siparis_no='')");
                    $uid=current_user_id();

                    for ($ri=$capRow+1; $ri<count($rows); $ri++) {
                        $r=$rows[$ri];
                        $grpNo=s_cell($r,2); if($grpNo==='' || $grpNo==='0') continue;
                        $firma=s_cell($r,9);
                        $capsum=0; foreach ($capCols as $c=>$lbl){ $capsum+=s_num(s_cell($r,$c)); }
                        $gelenIrs=s_cell($r,12);

                        // Sipariş tanım satırı
                        if ($firma!=='' && $firma!=='0' && $capsum>0) {
                            $sipVar->execute([$grpNo]);
                            if ($sipVar->fetchColumn()) { $atlanan++; }
                            else {
                                $tasId=$getTas($firma);
                                if ($tasId && !in_array($firma,$olusturulanTas)) { /* takip */ }
                                $insSip->execute([$grpNo, s_cell($r,7) ?: null, s_cell($r,8) ?: null, $tasId, $uid]);
                                $sipId=(int)$pdoDemir->lastInsertId();
                                foreach ($capCols as $c=>$lbl){ $m=s_num(s_cell($r,$c)); if($m>0){ $cid=$capMatch($lbl); if($cid) $insKlm->execute([$sipId,$cid,$m]); } }
                                $eklenen++;
                            }
                        }
                        // Teslimat satırı → sevkiyatı siparişe bağla
                        if ($gelenIrs!=='' && $gelenIrs!=='0') {
                            $bagla->execute([$grpNo, $gelenIrs]);
                            $baglanan += $bagla->rowCount();
                        }
                    }
                    $pdoDemir->commit();
                    $rapor=['eklenen'=>$eklenen,'atlanan'=>$atlanan,'baglanan'=>$baglanan];
                } catch (Throwable $e) { $pdoDemir->rollBack(); $hata='İçe aktarma hatası: '.$e->getMessage(); }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="siparisler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-file-earmark-excel text-success me-2"></i>Sipariş Takip İçe Aktar</h4>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($hata) ?></div><?php endif; ?>
<?php if ($rapor): ?>
<div class="alert alert-success">
    <h5 class="mb-2"><i class="bi bi-check-circle-fill me-1"></i>İçe aktarma tamamlandı</h5>
    <ul class="mb-0">
        <li><strong><?= $rapor['eklenen'] ?></strong> sipariş eklendi</li>
        <?php if($rapor['atlanan']): ?><li><strong><?= $rapor['atlanan'] ?></strong> sipariş zaten vardı (atlandı)</li><?php endif; ?>
        <li><strong><?= $rapor['baglanan'] ?></strong> sevkiyat siparişe bağlandı (IFS Sipariş No eşleştirildi → bakiye çalışır)</li>
    </ul>
    <hr><a href="siparisler.php" class="btn btn-dark btn-sm"><i class="bi bi-cart-check me-1"></i> Siparişlere Git</a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Excel Dosyası Yükle</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Demir Takip Excel dosyası (.xlsx)</label>
                <input type="file" name="dosya" class="form-control" accept=".xlsx" required>
                <div class="form-text">
                    <strong>Sipariş Takip</strong> sayfası okunur. Sipariş tanımları (firma + çap miktarı) sipariş olarak eklenir;
                    teslimat irsaliye no'larıyla mevcut sevkiyatlar siparişe bağlanır (bakiye hesabı için). Aynı IFS Sipariş No varsa atlanır.
                </div>
            </div>
            <button class="btn btn-success"><i class="bi bi-cloud-arrow-up me-1"></i> İçe Aktar</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
