<?php
/**
 * demir/_firma_teslim.php — Firma (taşeron) bazlı teslim matrisi yardımcıları.
 * Kaynaklar: uygulama teslim tutanakları + Tutanak Takip defteri (Excel).
 * Aynı tutanak_no iki kaynakta da varsa defter satırı atlanır (çift sayım yok).
 * İade hareketleri (defterde negatif) netten düşer.
 */

/** "28" → "Ø28"; diğer etiketler olduğu gibi (SPİRAL - Ø10, Q188/188...). */
function ftm_cap_gosterim(string $cap): string {
    $cap = trim($cap);
    if ($cap === '') return '(çap belirsiz)';
    return preg_match('/^\d+$/', $cap) ? ('Ø' . $cap) : $cap;
}

/**
 * Dönüş:
 *  'matris'      => [firma => [proje => [capLabel => netTon]]]
 *  'firmaToplam' => [firma => netTon] (çoktan aza)
 *  'projeler'    => tüm projelerin sıralı listesi
 */
function firma_teslim_matrisi(PDO $pdo): array {
    $norm = function($s){ $s = mb_strtoupper(trim((string)$s),'UTF-8'); return str_replace(['İ','I','ı','i'],'I',$s); };
    $matris = [];
    $ekle = function($firma,$proje,$cap,$ton) use (&$matris){
        $firma = trim((string)$firma) ?: '(firma yok)';
        $proje = trim((string)$proje) ?: '(proje yok)';
        $cap   = ftm_cap_gosterim((string)$cap);
        $matris[$firma][$proje][$cap] = ($matris[$firma][$proje][$cap] ?? 0) + (float)$ton;
    };

    // 1) Uygulama teslim tutanakları
    $appTutNo = [];
    try {
        foreach ($pdo->query("
            SELECT tu.tutanak_no, ta.ad firma, pr.kod proje, c.ad cap, tk.miktar_ton ton
            FROM demir_tutanak_kalemleri tk
            JOIN demir_tutanaklar tu ON tu.id = tk.tutanak_id
            LEFT JOIN demir_taseronlar ta ON ta.id = tu.taseron_id
            LEFT JOIN demir_projeler pr ON pr.id = tu.proje_id
            LEFT JOIN demir_caplar c ON c.id = tk.cap_id") as $r) {
            $ekle($r['firma'], $r['proje'], $r['cap'], $r['ton']);
            if ($r['tutanak_no']) $appTutNo[$norm($r['tutanak_no'])] = 1;
        }
    } catch (Throwable $e) { /* tablo yoksa geç */ }

    // 2) Tutanak Takip defteri (teslim + iade[negatif] → net)
    try {
        foreach ($pdo->query("SELECT firma, proje, tutanak_no, cap_label, miktar_ton FROM demir_tutanak_takip") as $r) {
            $tn = trim((string)$r['tutanak_no']);
            if ($tn !== '' && isset($appTutNo[$norm($tn)])) continue; // uygulamada da var → çift sayma
            $ekle($r['firma'], $r['proje'], $r['cap_label'], $r['miktar_ton']);
        }
    } catch (Throwable $e) { /* tablo yoksa geç */ }

    // 3) Uygulama iade tutanakları: iade eden firmadan düş, teslim alan firmaya ekle
    try {
        foreach ($pdo->query("
            SELECT ie.ad eden, ta.ad alan, pr.kod proje, c.ad cap, ik.miktar_ton ton
            FROM demir_iade_kalemleri ik
            JOIN demir_iade_tutanaklar iu ON iu.id = ik.iade_id
            LEFT JOIN demir_taseronlar ie ON ie.id = iu.iade_eden_id
            LEFT JOIN demir_taseronlar ta ON ta.id = iu.teslim_alan_id
            LEFT JOIN demir_projeler pr ON pr.id = iu.proje_id
            LEFT JOIN demir_caplar c ON c.id = ik.cap_id") as $r) {
            if ($r['eden']) $ekle($r['eden'], $r['proje'], $r['cap'], -(float)$r['ton']);
            if ($r['alan']) $ekle($r['alan'], $r['proje'], $r['cap'],  (float)$r['ton']);
        }
    } catch (Throwable $e) { /* tablolar yoksa geç */ }

    // Not: A.3 transferlerinin alıcı tarafı Tutanak Takip defterinde zaten "TESLİM ALINAN"
    // satırı olarak kayıtlıdır (ör. YILDIZLAR/ZEKERİYAKÖY ← "DENER U030") — ayrıca eklenmez (çift sayım olur).

    $firmaToplam = []; $projeSet = [];
    foreach ($matris as $f => $prjler) {
        $t = 0;
        foreach ($prjler as $p => $caps) { $projeSet[$p] = 1; $t += array_sum($caps); }
        $firmaToplam[$f] = $t;
    }
    arsort($firmaToplam);
    ksort($projeSet);
    return ['matris'=>$matris, 'firmaToplam'=>$firmaToplam, 'projeler'=>array_keys($projeSet)];
}

/** Çap etiketlerini doğal sırada dizer (sayı + tip). */
function ftm_cap_sirala(array $caps): array {
    usort($caps, function($a,$b){
        preg_match('/(\d+)/',$a,$ma); preg_match('/(\d+)/',$b,$mb);
        $na = isset($ma[1])?(int)$ma[1]:999; $nb = isset($mb[1])?(int)$mb[1]:999;
        $ga = preg_match('/^Ø\d+$/u',$a)?0:1; $gb = preg_match('/^Ø\d+$/u',$b)?0:1; // düz çaplar önce
        return [$ga,$na,$a] <=> [$gb,$nb,$b];
    });
    return $caps;
}

/** Bir firmanın Proje × Çap matris tablosunu (HTML) üretir. */
function ftm_tablo_html(string $firma, array $prjler, callable $fmt): string {
    $projeler = array_keys($prjler); sort($projeler);
    $caps = [];
    foreach ($prjler as $capsArr) foreach ($capsArr as $c=>$t) $caps[$c]=1;
    $caps = ftm_cap_sirala(array_keys($caps));
    $colTop = array_fill_keys($projeler, 0.0); $genel = 0.0;

    $h = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">';
    $h .= '<thead class="table-light"><tr><th>Çap</th>';
    foreach ($projeler as $p) $h .= '<th class="text-end">'.h($p).'</th>';
    $h .= '<th class="text-end table-secondary">Toplam</th></tr></thead><tbody>';
    foreach ($caps as $c) {
        $rowT = 0.0; $cells = '';
        foreach ($projeler as $p) {
            $v = (float)($prjler[$p][$c] ?? 0);
            $rowT += $v; $colTop[$p] += $v;
            $cells .= '<td class="text-end">'.($v!=0.0 ? $fmt($v) : '<span class="text-muted">—</span>').'</td>';
        }
        $genel += $rowT;
        $h .= '<tr><td class="fw-semibold">'.h($c).'</td>'.$cells.'<td class="text-end fw-semibold table-secondary">'.$fmt($rowT).'</td></tr>';
    }
    $h .= '</tbody><tfoot class="table-light fw-bold"><tr><td>TOPLAM</td>';
    foreach ($projeler as $p) $h .= '<td class="text-end">'.$fmt($colTop[$p]).'</td>';
    $h .= '<td class="text-end">'.$fmt($genel).'</td></tr></tfoot></table></div>';
    return $h;
}
