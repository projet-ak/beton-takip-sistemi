<?php
/**
 * istinat.php — İstinat Duvarları (ayrı menü)
 *
 * İki İstinat sayfası ayrı sekmelerde:
 *   1) İstinat — Dener İnşaat (İSTİNAT DENER): parsel bazlı bölümlü zayiat tablosu
 *   2) İstinat Duvarı (İSTİNAT DUVAR): duvar bazlı metraj listesi
 * Veriler Dinamik Excel Aktarımı ile metraj_sayfa'ya gelir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'İstinat Duvarları — Beton Takip Sistemi';

function istGrid(PDO $pdo, string $ad): array {
    $st = $pdo->prepare("SELECT veri FROM metraj_sayfa WHERE UPPER(TRIM(ad)) = ? ORDER BY id LIMIT 1");
    $st->execute([mb_strtoupper($ad,'UTF-8')]);
    $v = $st->fetchColumn();
    return $v ? (json_decode($v, true) ?: []) : [];
}
$gDener = istGrid($pdo, 'İSTİNAT DENER');
$gDuvar = istGrid($pdo, 'İSTİNAT DUVAR');
$varMi = ($gDener || $gDuvar);

function ihc($g,$r,$c){ return isset($g[$r][$c]) ? trim((string)$g[$r][$c]) : ''; }
function ibos($v){ $v=trim((string)$v); return ($v===''||strcasecmp($v,'#N/A')===0||strncmp($v,'#',1)===0); }
function iserial($v){
    $v=trim((string)$v);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/',$v)) return null;
    $ts=strtotime($v.' UTC'); if($ts===false) return null;
    return ($ts-strtotime('1899-12-30 00:00:00 UTC'))/86400;
}
function inum($v,$dec=2){ if(ibos($v)) return ''; if(!is_numeric($v)){ $s=iserial($v); if($s===null) return h($v); $v=$s; } return number_format((float)$v,$dec,',','.'); }
function ipct($v){ if(ibos($v)||!is_numeric($v)) return ''; return number_format((float)$v*100,1,',','.').'%'; }

/** Bölümlü zayiat sayfası (İstinat Dener): parsel başlıkları + kolon başlığı + veri */
function renderBolumluSayfa(array $grid): string {
    if (!$grid) return '<div class="text-muted py-3">Veri yok.</div>';
    ob_start();
    $basliklar = [];
    echo '<div class="table-responsive" style="max-height:70vh"><table class="table table-sm table-bordered align-middle mb-0" style="font-size:.8rem"><tbody>';
    foreach ($grid as $row) {
        $doluIdx = []; foreach ($row as $ci=>$c){ if(trim($c)!=='') $doluIdx[]=$ci; }
        if (!$doluIdx) continue;
        $ilk = trim((string)($row[$doluIdx[0]] ?? ''));
        $isHeader = false;
        foreach ($row as $c) { if (mb_stripos((string)$c,'PROJE TOPLAM')!==false || (mb_stripos((string)$c,'İLERLEME')!==false && mb_stripos((string)$c,'PROJE')===false && count($doluIdx)>3)) { $isHeader=true; break; } }
        if ($isHeader) {
            $basliklar = array_map(fn($c)=>trim((string)$c), $row);
            echo '<tr>';
            foreach ($row as $c) {
                $t = str_ireplace(['PROJE TOPLAM MİKTAR','SAHADA DÖKÜLEN BETON MİKTARI','SAHADA DÖKÜLEN BETON','PROJEYE GÖRE DÖKÜLMESİ GEREKEN','SÖZLEŞMEYE GÖRE ZAYİAT','FİİLİ ZAYİAT MİKTARI','ZAİYAT ORANI'],
                                   ['Proje Metrajı','Sahada Dökülen','Sahada Dökülen','Projeye Göre','Sözl. Zayiat','Fiili Zayiat','Zayiat Oranı'], trim((string)$c));
                echo '<td style="background:var(--ern);color:#fff;font-weight:600;text-align:center;font-size:.72rem">'.h($t).'</td>';
            }
            echo '</tr>';
            continue;
        }
        // Bölüm başlığı (tek metin hücresi)
        if (count($doluIdx)===1 && !is_numeric($ilk) && !preg_match('/^\d{4}-\d{2}-\d{2}/',$ilk)) {
            $cols = max(count($basliklar), count($row), 9);
            echo '<tr><td colspan="'.$cols.'" style="background:#eef6f4;color:var(--ern);font-weight:700"><i class="bi bi-geo-alt-fill me-1"></i>'.h($ilk).'</td></tr>';
            continue;
        }
        // "PEYZAJ / DENER / parsel" gibi 2 hücreli üst başlık → bölüm
        if (count($doluIdx)<=3 && !is_numeric($ilk) && mb_stripos($ilk,'GROBETON')===false && mb_stripos($ilk,'ISTINAT')===false && mb_stripos($ilk,'ÇEVRE')===false) {
            $etiket = trim(implode(' — ', array_filter(array_map(fn($i)=>trim((string)($row[$i]??'')), $doluIdx))));
            $cols = max(count($basliklar), count($row), 9);
            echo '<tr><td colspan="'.$cols.'" style="background:#f6eccf;color:#6b5411;font-weight:700"><i class="bi bi-bookmark-fill me-1"></i>'.h($etiket).'</td></tr>';
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
            echo $isPct ? '<td class="text-end">'.ipct($t).'</td>' : '<td class="text-end font-monospace">'.inum($t).'</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-bricks text-primary me-2"></i>İstinat Duvarları</h4>
        <small class="text-muted">İstinat & çevre duvarı imalatları — Dener İnşaat zayiat takibi + duvar metrajları</small>
    </div>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Dinamik Excel Aktarımı</a>
</div>

<?php if (!$varMi): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> İstinat verisi yok.
    Önce <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a> ile Excel'i yükleyin.</div>
<?php else: ?>

<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#i-dener" type="button"><i class="bi bi-graph-down-arrow me-1"></i>İstinat — Dener İnşaat</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#i-duvar" type="button"><i class="bi bi-list-columns-reverse me-1"></i>İstinat Duvarı (Metraj)</button></li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white p-3">
    <!-- 1) İstinat Dener -->
    <div class="tab-pane fade show active" id="i-dener" role="tabpanel">
        <div class="mb-2"><span class="badge bg-primary"><i class="bi bi-building me-1"></i>Taşeron: Dener İnşaat</span></div>
        <?= renderBolumluSayfa($gDener) ?>
        <div class="alert alert-light border small mt-2 mb-0"><i class="bi bi-info-circle me-1 text-success"></i>Parsel bazlı bölümler; sözleşme zayiat limiti %4. Bazı hücreler Excel'de tarih formatlı olduğundan sayıya çevrilerek gösterilir.</div>
    </div>

    <!-- 2) İstinat Duvarı metraj -->
    <div class="tab-pane fade" id="i-duvar" role="tabpanel">
    <?php
    if (!$gDuvar) { echo '<div class="text-muted py-3">İstinat Duvarı verisi yok.</div>'; }
    else {
        // Başlık R0, veri R1+
        $topMetraj=0; $topYap=0;
        for($r=1;$r<count($gDuvar);$r++){ $m=ihc($gDuvar,$r,5); $y=ihc($gDuvar,$r,7); if(is_numeric($m))$topMetraj+=(float)$m; if(is_numeric($y))$topYap+=(float)$y; }
        $kols = [0=>'Parsel',1=>'Duvar No',2=>'Beton',3=>'Alan (m²)',4=>'Yükseklik (m)',5=>'Metraj (m³)',6=>'İlerleme',7=>'Yapılan (m³)'];
    ?>
        <div class="d-flex gap-2 flex-wrap mb-2">
            <span class="badge bg-primary">Toplam Metraj: <?= number_format($topMetraj,2,',','.') ?> m³</span>
            <span class="badge bg-success">Yapılan: <?= number_format($topYap,2,',','.') ?> m³</span>
            <span class="badge bg-secondary">Kalan: <?= number_format($topMetraj-$topYap,2,',','.') ?> m³</span>
        </div>
        <div class="table-responsive" style="max-height:68vh">
            <table class="table table-sm table-hover table-bordered align-middle mb-0" style="font-size:.8rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><?php foreach($kols as $l): ?><th class="text-nowrap"><?= h($l) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php for($r=1;$r<count($gDuvar);$r++):
                    $dolu=false; foreach($gDuvar[$r] as $c){ if(trim($c)!==''){$dolu=true;break;} } if(!$dolu) continue;
                ?>
                    <tr>
                        <?php foreach($kols as $ci=>$l): $v=ihc($gDuvar,$r,$ci);
                            if($ci===6){ echo '<td class="text-end">'.ipct($v).'</td>'; continue; }
                            $say = in_array($ci,[3,4,5,7],true);
                            echo '<td class="'.($say?'text-end font-monospace':'').'">'.($say?inum($v):h($v)).'</td>';
                        ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    </div>
</div>
<?php endif; ?>

<style>.nav-tabs .nav-link{font-weight:600;}</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
