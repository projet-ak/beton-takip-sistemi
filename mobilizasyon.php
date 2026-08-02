<?php
/**
 * mobilizasyon.php — Mobilizasyon imalatları (firma bölümlü)
 * Excel "MOBİLİZASYON" sayfasını firma bölümleriyle anlaşılır tablo olarak gösterir.
 * Veri Dinamik Excel Aktarımı ile gelir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Mobilizasyon — Beton Takip Sistemi';

$st = $pdo->prepare("SELECT veri FROM metraj_sayfa WHERE UPPER(TRIM(ad)) IN ('MOBİLİZASYON','MOBILIZASYON') ORDER BY id LIMIT 1");
$st->execute();
$grid = ($v = $st->fetchColumn()) ? (json_decode($v, true) ?: []) : [];

function obos($v){ $v=trim((string)$v); return ($v===''||strcasecmp($v,'#N/A')===0||strncmp($v,'#',1)===0); }
function oserial($v){ $v=trim((string)$v); if(!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/',$v))return null; $ts=strtotime($v.' UTC'); if($ts===false)return null; return ($ts-strtotime('1899-12-30 00:00:00 UTC'))/86400; }
function onum($v,$d=2){ if(obos($v))return ''; if(!is_numeric($v)){$s=oserial($v);if($s===null)return h($v);$v=$s;} return number_format((float)$v,$d,',','.'); }
function opct($v){ if(obos($v)||!is_numeric($v))return ''; return number_format((float)$v*100,1,',','.').'%'; }

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-truck text-primary me-2"></i>Mobilizasyon</h4>
        <small class="text-muted">Firma bölümlü mobilizasyon beton imalatları — sahada dökülen / zayiat</small>
    </div>
    <a href="import.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Dinamik Excel Aktarımı</a>
</div>

<?php if (!$grid): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Mobilizasyon verisi yok. Önce <a href="import.php" class="alert-link">Dinamik Excel Aktarımı</a> ile Excel'i yükleyin.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
<div class="table-responsive" style="max-height:74vh">
    <table class="table table-sm table-bordered align-middle mb-0" style="font-size:.82rem"><tbody>
    <?php
    $basliklar = [];
    foreach ($grid as $row) {
        $doluIdx = []; foreach ($row as $ci=>$c){ if(trim($c)!=='') $doluIdx[]=$ci; }
        if (!$doluIdx) continue;
        $ilk = trim((string)($row[$doluIdx[0]] ?? ''));
        // Kolon başlığı satırı mı?
        $isHeader = false;
        foreach ($row as $c) { if (mb_stripos((string)$c,'PROJE BOYU')!==false || mb_stripos((string)$c,'PROJE TOPLAM')!==false) { $isHeader=true; break; } }
        if ($isHeader) {
            $basliklar = array_map(fn($c)=>trim((string)$c), $row);
            echo '<tr>';
            foreach ($row as $c) {
                $t = str_ireplace(['SAHADA DÖKÜLEN BETON MİKTARI','SAHADA DÖKÜLEN BETON','PROJE TOPLAM MİKTARI','FİİLİ MİKTAR','ZAİYAT ORANI','BİRİM MİKTAR','PROJE BOYU'],
                                  ['Sahada Dökülen','Sahada Dökülen','Proje Toplam','Fiili Miktar','Zayiat Oranı','Birim Miktar','Proje Boyu'], trim((string)$c));
                echo '<td style="background:var(--ern);color:#fff;font-weight:600;text-align:center;font-size:.72rem">'.h($t).'</td>';
            }
            echo '</tr>';
            continue;
        }
        // Bölüm başlığı (tek metin hücresi) → firma/iş adı
        if (count($doluIdx)===1 && !is_numeric($ilk)) {
            $cols = max(count($basliklar), count($row), 8);
            echo '<tr><td colspan="'.$cols.'" class="satir-bolum"><i class="bi bi-tag-fill me-1"></i>'.h($ilk).'</td></tr>';
            continue;
        }
        // Veri satırı
        echo '<tr>';
        foreach ($row as $ci=>$c) {
            $t = trim((string)$c);
            $bas = mb_strtoupper($basliklar[$ci] ?? '', 'UTF-8');
            $isPct = (mb_strpos($bas,'İLERLEME')!==false || mb_strpos($bas,'ORANI')!==false);
            if ($ci===0) { echo '<td class="fw-semibold">'.h($t).'</td>'; continue; }
            if ($t==='') { echo '<td></td>'; continue; }
            echo $isPct ? '<td class="text-end">'.opct($t).'</td>' : '<td class="text-end font-monospace">'.onum($t).'</td>';
        }
        echo '</tr>';
    }
    ?>
    </tbody></table>
</div>
</div>
<div class="alert alert-light border small mt-2 mb-0"><i class="bi bi-info-circle me-1 text-success"></i>Bölümler firma/iş bazındadır (Osman Camcı, Yıldızlar, PRP İnşaat…). Sahada dökülen beton ve zayiat oranı gösterilir.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
