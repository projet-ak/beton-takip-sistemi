<?php
/**
 * temel_kazik.php — Temel & Kazık İmalatları (ayrı, anlaşılır menü)
 *
 * Excel'in temel/kazık sayfalarını (PRP TEMEL = Temel Beton, KAZIK = Kazık Listesi,
 * İKSA KAZIK, TEMEL ALTI KAZIK) metraj_sayfa'daki grid'lerden okuyup anlaşılır,
 * kurumsal renkli sekmelerde gösterir. Veriler Dinamik Excel Aktarımı ile gelir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Temel & Kazık — Beton Takip Sistemi';

/** Adı verilen sayfanın grid'ini getir */
function gridGetir(PDO $pdo, array $adlar): array {
    $ph = implode(',', array_fill(0, count($adlar), '?'));
    $st = $pdo->prepare("SELECT veri FROM metraj_sayfa WHERE UPPER(TRIM(ad)) IN ($ph) ORDER BY id LIMIT 1");
    $st->execute(array_map(fn($a)=>mb_strtoupper($a,'UTF-8'), $adlar));
    $v = $st->fetchColumn();
    return $v ? (json_decode($v, true) ?: []) : [];
}

$gTemel   = gridGetir($pdo, ['PRP TEMEL']);
$gKazik   = gridGetir($pdo, ['KAZIK']);
$gIksa    = gridGetir($pdo, ['İKSA KAZIK']);
$gTemelAlt= gridGetir($pdo, ['TEMEL ALTI KAZIK']);

$varMi = ($gTemel || $gKazik || $gIksa || $gTemelAlt);

require_once __DIR__ . '/includes/zayiat_helper.php';
$dokTB = zy_dokum($pdo, 'blok_imalat');    // Temel Beton: blok + imalat (TEMEL/GROBETON)
$TB_LIMIT = 0.05;                          // Temel beton sözleşme zayiat limiti %5
$dokForeP = zy_dokum($pdo, 'parsel_imalat'); // İksa Kazık: parsel + FORE KAZIK
$dokForeB = zy_dokum($pdo, 'blok_imalat');    // Temel Altı Kazık: blok + FORE KAZIK
$KAZIK_LIMIT = 0.15;                        // Kazık (fore) sözleşme zayiat limiti %15

/** Kazık sayfası parsel/blok özeti: her bölüm için FORE KAZIK canlı toplam + zayiat.
 *  $metrajCol null ise yalnız canlı sahada gösterilir (zayiat hesaplanmaz). */
function kazikOzet(array $grid, array $dokFore, string $seviye, ?int $metrajCol, float $limit): string {
    if (!$grid) return '';
    // Bölümleri (parsel/blok) ve metraj toplamlarını çıkar
    $sec = []; $cur = null;
    foreach ($grid as $row) {
        $c0 = trim((string)($row[0] ?? ''));
        $isSec = ($c0!=='' && (mb_stripos($c0,'PARSEL')!==false || mb_stripos($c0,'BLOK')!==false || mb_stripos($c0,'BAHCESI')!==false || mb_stripos($c0,'BAHÇESİ')!==false));
        if ($isSec) { $cur = $c0; if(!isset($sec[$cur])) $sec[$cur]=0.0; continue; }
        if ($cur !== null && $metrajCol !== null) {
            $m = trim((string)($row[$metrajCol] ?? ''));
            if (is_numeric($m)) $sec[$cur] += (float)$m;
        }
    }
    if (!$sec) return '';
    ob_start();
    echo '<div class="row g-2 mb-3">';
    foreach ($sec as $name => $metrajTop) {
        $key = ($seviye==='parsel') ? mb_strtoupper(trim($name),'UTF-8') : zy_normBlok($name);
        $sahada = (float)($dokFore[$key]['FORE KAZIK'] ?? 0);
        echo '<div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">';
        echo '<div class="fw-semibold text-truncate"><i class="bi bi-geo-alt-fill text-primary me-1"></i>'.h($name).'</div>';
        echo '<div class="d-flex justify-content-between small mt-1"><span class="text-muted">Sahada Dökülen (canlı)</span><span class="fw-bold font-monospace">'.number_format($sahada,2,',','.').' m³</span></div>';
        if ($metrajCol !== null && $metrajTop > 0) {
            $z = zy_hesap($sahada, $metrajTop, $limit);
            echo '<div class="d-flex justify-content-between small"><span class="text-muted">Proje Metrajı</span><span class="font-monospace">'.number_format($metrajTop,2,',','.').' m³</span></div>';
            echo '<div class="d-flex justify-content-between small align-items-center"><span class="text-muted">Zayiat (limit %'.rtrim(rtrim(number_format($limit*100,1,',','.'),'0'),',').')</span>'.zy_durumRozet($z).'</div>';
            if ($z['fiili']>0) echo '<div class="d-flex justify-content-between small"><span class="text-muted">Fiili Zayiat</span><span class="text-danger fw-bold">'.number_format($z['fiili'],2,',','.').' m³</span></div>';
        }
        echo '</div></div></div>';
    }
    echo '</div>';
    return ob_get_clean();
}

// ── Yardımcılar ───────────────────────────────────────────────────────────────
function hc($g,$r,$c){ return isset($g[$r][$c]) ? trim((string)$g[$r][$c]) : ''; }
function bosMu($v){ $v=trim((string)$v); return ($v===''||strcasecmp($v,'#N/A')===0||strncmp($v,'#',1)===0); }
/** Excel tarih-serial → sayı (bozuk formatlı hücreler için) */
function exSerial($v){
    $v=trim((string)$v);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/',$v)) return null;
    $ts=strtotime($v.' UTC'); if($ts===false) return null;
    $base=strtotime('1899-12-30 00:00:00 UTC');
    return ($ts-$base)/86400;
}
/** Sayı biçimle (tarih-serial ise çevir); $pct ise yüzde */
function num($v,$dec=2){ if(bosMu($v)) return ''; if(!is_numeric($v)){ $s=exSerial($v); if($s===null) return h($v); $v=$s; } return number_format((float)$v,$dec,',','.'); }
function pct($v){ if(bosMu($v)||!is_numeric($v)) return ''; return number_format((float)$v*100,1,',','.').'%'; }

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-cone-striped text-primary me-2"></i>Temel &amp; Kazık İmalatları</h4>
        <small class="text-muted">Temel betonu (PRP İnşaat), kazık listesi, iksa & temel altı kazık — zayiat takibi</small>
    </div>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Dinamik Excel Aktarımı</a>
</div>

<?php if (!$varMi): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Temel/Kazık verisi yok.
    Önce <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a> ile Excel'i yükleyin.</div>
<?php else: ?>

<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-temel" type="button"><i class="bi bi-bricks me-1"></i>Temel Beton <span class="text-muted small">(PRP İnşaat)</span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-kazik" type="button"><i class="bi bi-list-columns me-1"></i>Kazık Listesi</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-iksa" type="button"><i class="bi bi-cone me-1"></i>İksa Kazık</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-taban" type="button"><i class="bi bi-arrow-down-square me-1"></i>Temel Altı Kazık</button></li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white p-3">

    <!-- ===== 1) TEMEL BETON (PRP) — blok bazlı ===== -->
    <div class="tab-pane fade show active" id="t-temel" role="tabpanel">
    <?php
    if (!$gTemel) { echo '<div class="text-muted py-3">Temel Beton verisi yok.</div>'; }
    else {
        // Blokları çıkar: col0'da *_BLOK; altında TEMEL/GROBETON satırları
        // (GENEL TOPLAM gibi satırlar hariç: her blokta yalnız ilk TEMEL/GROBETON alınır)
        $bloklar = [];
        $cur = null; $gorulen = [];
        for ($r = 0; $r < count($gTemel); $r++) {
            $a = hc($gTemel,$r,0);
            if (preg_match('/_BLOK$/u', $a)) { $cur = $a; $bloklar[$cur] = []; $gorulen[$cur] = []; continue; }
            if (mb_stripos($a,'GENEL TOPLAM') !== false) { $cur = null; continue; }
            $kalemU = mb_strtoupper($a,'UTF-8');
            if ($cur && in_array($kalemU, ['TEMEL','GROBETON'], true) && empty($gorulen[$cur][$kalemU])) {
                $gorulen[$cur][$kalemU] = true;
                $bloklar[$cur][] = [
                    'kalem'  => $a,
                    'metraj' => hc($gTemel,$r,1),
                    'iler'   => hc($gTemel,$r,2),
                    'sahada' => hc($gTemel,$r,3),
                    'zoran'  => hc($gTemel,$r,5),
                    'sozB'   => hc($gTemel,$r,6),
                    'fiili'  => hc($gTemel,$r,8),
                ];
            }
        }
    ?>
        <div class="row g-3">
        <?php foreach ($bloklar as $blok => $satirlar): if(!$satirlar) continue; ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header text-white fw-semibold" style="background:linear-gradient(90deg,var(--ern),var(--ern-light))">
                        <i class="bi bi-building me-1"></i><?= h($blok) ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light"><tr>
                                <th style="width:16%">İmalat</th>
                                <th class="text-end" style="width:16%">Proje Metrajı</th>
                                <th class="text-end" style="width:16%">Sahada Dökülen</th>
                                <th class="text-end" style="width:14%">İlerleme</th>
                                <th class="text-center" style="width:20%">Zayiat (limit %5)</th>
                                <th class="text-end" style="width:18%">Fiili Zayiat</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($satirlar as $s):
                                $metraj = is_numeric($s['metraj']) ? (float)$s['metraj'] : 0;
                                $sahada = $dokTB[zy_normBlok($blok)][mb_strtoupper($s['kalem'],'UTF-8')] ?? 0;
                                $z = zy_hesap($sahada, $metraj, $TB_LIMIT);
                            ?>
                                <tr class="<?= $z['asim']?'table-danger':'' ?>">
                                    <td class="fw-semibold"><?= h($s['kalem']) ?></td>
                                    <td class="text-end font-monospace"><?= num($s['metraj']) ?></td>
                                    <td class="text-end font-monospace <?= $sahada>0?'fw-semibold':'text-muted' ?>"><?= $sahada>0?number_format($sahada,2,',','.'):'0' ?></td>
                                    <td class="text-end"><?= number_format($z['iler']*100,1,',','.') ?>%</td>
                                    <td class="text-center"><?= zy_durumRozet($z) ?></td>
                                    <td class="text-end <?= $z['fiili']>0?'text-danger fw-bold':'' ?>"><?= number_format($z['fiili'],2,',','.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <div class="alert alert-light border small mt-3 mb-0"><i class="bi bi-broadcast me-1 text-success"></i><strong>CANLI:</strong> Sahada dökülen, gerçek irsaliyelerden (blok + TEMEL/GROBETON imalatı) toplanır; ilerleme = sahada ÷ metraj. Teorik metrajı <strong>%5</strong>'ten fazla aşan blok kırmızı işaretlenir (Fiili Zayiat = kesilecek). Taşeron: <strong>PRP İnşaat</strong>.</div>
    <?php } ?>
    </div>

    <!-- ===== 2) KAZIK LİSTESİ ===== -->
    <div class="tab-pane fade" id="t-kazik" role="tabpanel">
    <?php
    if (!$gKazik) { echo '<div class="text-muted py-3">Kazık listesi yok.</div>'; }
    else {
        // Başlık R2, veri R3+
        $bas = 2;
        $kols = [0=>'Pafta',1=>'Parsel',2=>'Duvar No',3=>'Açıklama',4=>'Detay',5=>'Kazık Boy',6=>'Adet',8=>'Çap',10=>'Toplam Beton',11=>'Yapılan Adet',12=>'Kalan Adet',13=>'Yapılan Beton',14=>'Kalan Beton'];
        $topBeton=0; $yapBeton=0;
        for($r=$bas+1;$r<count($gKazik);$r++){ $tb=hc($gKazik,$r,10); $yb=hc($gKazik,$r,13); if(is_numeric($tb))$topBeton+=(float)$tb; if(is_numeric($yb))$yapBeton+=(float)$yb; }
    ?>
        <div class="d-flex gap-2 flex-wrap mb-2">
            <span class="badge bg-primary">Toplam Kazık Betonu: <?= number_format($topBeton,2,',','.') ?> m³</span>
            <span class="badge bg-success">Yapılan: <?= number_format($yapBeton,2,',','.') ?> m³</span>
            <span class="badge bg-secondary">Kalan: <?= number_format($topBeton-$yapBeton,2,',','.') ?> m³</span>
        </div>
        <div class="table-responsive" style="max-height:68vh">
            <table class="table table-sm table-hover table-bordered align-middle mb-0" style="font-size:.8rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><?php foreach($kols as $lbl): ?><th class="text-nowrap"><?= h($lbl) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php for($r=$bas+1;$r<count($gKazik);$r++):
                    $row=$gKazik[$r]; $dolu=false; foreach($row as $c){ if(trim($c)!==''){$dolu=true;break;} } if(!$dolu) continue;
                ?>
                    <tr>
                        <?php foreach($kols as $ci=>$lbl): $v=hc($gKazik,$r,$ci);
                            $sayisal = in_array($ci,[5,6,10,11,12,13,14],true); ?>
                            <td class="<?= $sayisal?'text-end font-monospace':'' ?>"><?= $sayisal ? num($v) : h($v) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    </div>

    <!-- ===== 3) İKSA KAZIK ===== -->
    <div class="tab-pane fade" id="t-iksa" role="tabpanel">
        <div class="d-flex gap-2 flex-wrap mb-2 align-items-center">
            <span class="badge bg-primary"><i class="bi bi-building me-1"></i>Taşeron: Osman Camcı (Fore Kazık)</span>
            <span class="badge bg-info-subtle text-info-emphasis border"><i class="bi bi-broadcast me-1"></i>Parsel bazlı canlı Fore Kazık dökümü</span>
        </div>
        <?php echo kazikOzet($gIksa, $dokForeP, 'parsel', null, $KAZIK_LIMIT); ?>
        <div class="alert alert-light border small mb-3"><i class="bi bi-info-circle me-1 text-warning"></i>Üstteki kartlar her parsele dökülen <strong>toplam Fore Kazık betonunu canlı</strong> gösterir (irsaliyelerde Ø80/Ø100 ayrımı yok). Sözleşme limiti <strong>%15</strong>. Satır bazında zayiat oranı için aşağıdaki tabloda Excel'in kendi değerleri kullanılır.</div>
        <?php echo renderKazikSheet($gIksa, 'İksa Kazık'); ?>
    </div>

    <!-- ===== 4) TEMEL ALTI KAZIK ===== -->
    <div class="tab-pane fade" id="t-taban" role="tabpanel">
        <div class="d-flex gap-2 flex-wrap mb-2 align-items-center">
            <span class="badge bg-primary"><i class="bi bi-building me-1"></i>Taşeron: Osman Camcı (Fore Kazık)</span>
            <span class="badge bg-info-subtle text-info-emphasis border"><i class="bi bi-broadcast me-1"></i>Blok bazlı canlı Fore Kazık toplamı</span>
        </div>
        <?php echo kazikOzet($gTemelAlt, $dokForeB, 'blok', null, $KAZIK_LIMIT); ?>
        <?php echo renderKazikSheet($gTemelAlt, 'Temel Altı Kazık'); ?>
    </div>

</div>
<?php endif; ?>

<?php
/** İksa/Temel Altı Kazık: bölüm başlıklı + zayiat sütunlu temiz tablo */
function renderKazikSheet(array $grid, string $ad): string {
    if (!$grid) return '<div class="text-muted py-3">'.h($ad).' verisi yok.</div>';
    ob_start();
    $bolum = ''; $basliklar = [];
    echo '<div class="table-responsive" style="max-height:68vh"><table class="table table-sm table-bordered align-middle mb-0" style="font-size:.8rem"><tbody>';
    for ($r = 0; $r < count($grid); $r++) {
        $row = $grid[$r];
        // dolu hücreler
        $doluIdx = []; foreach ($row as $ci=>$c){ if(trim($c)!=='') $doluIdx[]=$ci; }
        if (!$doluIdx) continue;
        $ilkMetin = trim((string)($row[$doluIdx[0]] ?? ''));
        // Sütun başlığı satırı mı?
        $isHeader = false;
        foreach ($row as $c) { if (mb_stripos((string)$c,'PROJE BOYU')!==false || mb_stripos((string)$c,'İLERLEME')!==false) { $isHeader=true; break; } }
        if ($isHeader) {
            $basliklar = array_map(fn($c)=>trim((string)$c), $row);
            echo '<tr class="mg-head">';
            foreach ($row as $c) {
                $t = trim((string)$c);
                // uzun başlıkları kısalt
                $t = str_ireplace(['SAHADA DÖKÜLEN BETON MİKTARI','PROJEYE GÖRE DÖKÜLMESİ GEREKEN','SÖZLEŞMEYE GÖRE ZAYİAT','FİİLİ ZAYİAT MİKTARI','PROJE TOPLAM MİKTAR','ZAİYAT ORANI','TOPLAM MİKTAR','BİRİM MİKTAR','PROJE BOYU'],
                                   ['Sahada Dökülen','Projeye Göre','Sözl. Zayiat','Fiili Zayiat','Proje Toplam','Zayiat Oranı','Toplam Miktar','Birim Miktar','Proje Boyu'], $t);
                echo '<td style="background:var(--ern);color:#fff;font-weight:600;text-align:center;font-size:.72rem">'.h($t).'</td>';
            }
            echo '</tr>';
            continue;
        }
        // Bölüm başlığı: sadece 1 dolu hücre + metin
        if (count($doluIdx)===1 && !is_numeric($ilkMetin) && !preg_match('/^\d{4}-\d{2}-\d{2}/',$ilkMetin)) {
            $cols = max(count($basliklar), count($row), 11);
            echo '<tr><td colspan="'.$cols.'" class="satir-bolum"><i class="bi bi-geo-alt-fill me-1"></i>'.h($ilkMetin).'</td></tr>';
            continue;
        }
        // Veri satırı
        echo '<tr>';
        foreach ($row as $ci=>$c) {
            $t = trim((string)$c);
            $bas = mb_strtoupper($basliklar[$ci] ?? '', 'UTF-8');
            $isPct = (mb_strpos($bas,'İLERLEME')!==false || mb_strpos($bas,'ORANI')!==false || (mb_strpos($bas,'SÖZLEŞME')!==false && is_numeric($t) && (float)$t<=1));
            if ($ci===0) { echo '<td class="fw-semibold">'.h($t).'</td>'; continue; }
            if ($t==='') { echo '<td></td>'; continue; }
            if ($isPct) echo '<td class="text-end">'.pct($t).'</td>';
            else echo '<td class="text-end font-monospace">'.num($t).'</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="alert alert-light border small mt-2 mb-0"><i class="bi bi-info-circle me-1 text-success"></i>Bölümler parsel/blok bazındadır. Bazı hücreler Excel\'de tarih formatlı olduğundan sayıya çevrilerek gösterilir.</div>';
    return ob_get_clean();
}
?>

<style>
#t-iksa .mg-head td, #t-taban .mg-head td { position:sticky; top:0; z-index:2; }
.nav-tabs .nav-link { font-weight:600; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
