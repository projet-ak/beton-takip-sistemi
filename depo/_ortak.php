<?php
/** _ortak.php — Depo modülü ortak yardımcıları */

function dp_sayi($v): float {
    $v = trim((string)$v);
    if ($v==='' || strncmp($v,'#',1)===0) return 0.0;
    $v = str_replace([' ',"\xc2\xa0"],'',$v);
    if (strpos($v,',')!==false) { $v=str_replace('.','',$v); $v=str_replace(',','.',$v); }
    return is_numeric($v) ? (float)$v : 0.0;
}

$GLOBALS['DP_KATEGORI'] = [
    'demirbas' => ['ad'=>'Demirbaşlar', 'ikon'=>'bi-hdd-stack', 'birim'=>'Ad'],
    'sarf'     => ['ad'=>'Sarf Malzeme', 'ikon'=>'bi-basket', 'birim'=>'Ad'],
    'el_aleti' => ['ad'=>'El Aletleri', 'ikon'=>'bi-tools', 'birim'=>'Adet'],
];
function dp_katAd(string $k): string { return $GLOBALS['DP_KATEGORI'][$k]['ad'] ?? $k; }
function dp_katGecerli(string $k): bool { return isset($GLOBALS['DP_KATEGORI'][$k]); }

/** Kategori özetleri: kalem sayısı, toplam stok, mali değer */
function dp_ozet(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT kategori,
               COUNT(*) adet,
               SUM(sayim+gelen-giden) stok,
               SUM((sayim+gelen-giden) * COALESCE(birim_fiyat,0)) deger,
               SUM(CASE WHEN (sayim+gelen-giden)<=0 THEN 1 ELSE 0 END) tukenen
        FROM depo_kalemler WHERE aktif=1 GROUP BY kategori")->fetchAll();
    $o = [];
    foreach ($rows as $r) $o[$r['kategori']] = $r;
    return $o;
}
