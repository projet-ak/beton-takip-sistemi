<?php
/**
 * zayiat_helper.php — Taşeron canlı zayiat ortak yardımcıları
 *
 * Gerçek irsaliyelerden (blok / kot / imalat_gruplari.ad) sahada dökülen betonu
 * toplar ve sözleşme limitine göre zayiat durumunu hesaplar. Bina Üstyapı, Temel
 * Beton, Kazık, İstinat gibi zayiat ekranları buradan beslenir.
 *
 * imalat_gruplari.ad = Excel "İmalat Ana Grup" (KOLON-PERDE/TEMEL/GROBETON/FORE KAZIK…).
 */

if (!function_exists('zy_normBlok')) {

function zy_normBlok($s){ return preg_replace('/[\s_\-]/u','', mb_strtoupper(trim((string)$s),'UTF-8')); }
function zy_normKot($s){ $s=str_replace(['+',' '],'',trim((string)$s)); $s=str_replace(',','.',$s); return is_numeric($s)?round((float)$s,2):null; }

/**
 * Canlı sahada dökülen haritası.
 * $seviye: 'blok_imalat' → [blokNorm][imalatUpper]=m³
 *          'blok_kot_imalat' → [blokNorm][kotFloat][imalatUpper]=m³
 *          'parsel_imalat' → [parselUpper][imalatUpper]=m³
 */
function zy_dokum(PDO $pdo, string $seviye = 'blok_imalat'): array {
    $map = [];
    try {
        if ($seviye === 'blok_kot_imalat') {
            $sql = "SELECT b.ad a1, k.kot_degeri a2, ig.ad im,
                           SUM(CASE WHEN i.tip='alis' THEN i.miktar ELSE -i.miktar END) m3
                    FROM irsaliyeler i
                    JOIN bloklar b ON b.id=i.blok_id
                    JOIN kotlar k ON k.id=i.kot_id
                    JOIN imalat_gruplari ig ON ig.id=i.imalat_grup_id
                    WHERE i.durum<>'reddedildi' GROUP BY b.ad, k.kot_degeri, ig.ad";
        } elseif ($seviye === 'parsel_imalat') {
            $sql = "SELECT p.ad a1, ig.ad im,
                           SUM(CASE WHEN i.tip='alis' THEN i.miktar ELSE -i.miktar END) m3
                    FROM irsaliyeler i
                    JOIN parseller p ON p.id=i.parsel_id
                    JOIN imalat_gruplari ig ON ig.id=i.imalat_grup_id
                    WHERE i.durum<>'reddedildi' GROUP BY p.ad, ig.ad";
        } else {
            $sql = "SELECT b.ad a1, ig.ad im,
                           SUM(CASE WHEN i.tip='alis' THEN i.miktar ELSE -i.miktar END) m3
                    FROM irsaliyeler i
                    JOIN bloklar b ON b.id=i.blok_id
                    JOIN imalat_gruplari ig ON ig.id=i.imalat_grup_id
                    WHERE i.durum<>'reddedildi' GROUP BY b.ad, ig.ad";
        }
        foreach ($pdo->query($sql) as $r) {
            $im = mb_strtoupper(trim($r['im']),'UTF-8');
            if ($seviye === 'blok_kot_imalat') {
                $kf = zy_normKot($r['a2']); if ($kf === null) continue;
                $map[zy_normBlok($r['a1'])][(string)$kf][$im] = ($map[zy_normBlok($r['a1'])][(string)$kf][$im] ?? 0) + (float)$r['m3'];
            } elseif ($seviye === 'parsel_imalat') {
                $map[mb_strtoupper(trim($r['a1']),'UTF-8')][$im] = ($map[mb_strtoupper(trim($r['a1']),'UTF-8')][$im] ?? 0) + (float)$r['m3'];
            } else {
                $map[zy_normBlok($r['a1'])][$im] = ($map[zy_normBlok($r['a1'])][$im] ?? 0) + (float)$r['m3'];
            }
        }
    } catch (Throwable $e) { return []; }
    return $map;
}

/**
 * Zayiat hesabı: sahada + teorik metraj + limit → durum/oran/fiili.
 * İlerleme sahadan canlı = min(1, sahada/metraj). A = min(sahada, metraj).
 * Aşım: sahada > metraj×(1+limit).
 */
function zy_hesap(float $sahada, float $metraj, float $limit): array {
    $iler = ($metraj > 0) ? min(1, $sahada / $metraj) : 0;
    $A    = $metraj * $iler;
    $asim = ($metraj > 0 && $sahada > $metraj * (1 + $limit));
    if ($sahada <= 0)                              $durum = 'bos';
    elseif ($metraj > 0 && $sahada < $metraj*0.999) $durum = 'devam';
    elseif ($asim)                                 $durum = 'asim';
    elseif ($metraj > 0 && $sahada > $metraj*(1+0.8*$limit)) $durum = 'yaklasiyor';
    else                                           $durum = 'normal';
    $oran = $asim ? ($sahada - $metraj)/$metraj : (($A>0)?($sahada-$A)/$A:null);
    return [
        'sahada'=>$sahada, 'metraj'=>$metraj, 'iler'=>$iler, 'A'=>$A,
        'oran'=>$oran, 'asim'=>$asim, 'durum'=>$durum, 'limit'=>$limit,
        'fiili'=> $asim ? max(0, $sahada - $metraj*(1+$limit)) : 0.0,
    ];
}

/** Durum rozeti (HTML) */
function zy_durumRozet(array $z): string {
    switch ($z['durum']) {
        case 'devam':     return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Devam ediyor</span>';
        case 'normal':    return '<span class="text-success">'.number_format($z['oran']*100,1,',','.').'% <i class="bi bi-check-circle"></i></span>';
        case 'yaklasiyor':return '<span class="text-warning-emphasis fw-semibold">'.number_format($z['oran']*100,1,',','.').'% ⚠</span>';
        case 'asim':      return '<span class="text-danger fw-bold">'.number_format($z['oran']*100,1,',','.').'% <i class="bi bi-exclamation-triangle-fill"></i></span>';
        default:          return '<span class="text-muted">—</span>';
    }
}

} // function_exists guard
