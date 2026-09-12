<?php
// ⚠ Yalnız KOMUT SATIRI aracı — web'den çalıştırılamaz.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Bu dosya yalnız komut satırından çalışır.'); }
/**
 * ikon_uret.php — ERN uygulama ikonlarını kaynak logodan üretir (scratchpad aracı).
 *
 * Kaynak: uploads/logo/ERN Holding_Logo_Beyaz.png (3508x2481, beyaz "ern" + HOLDING)
 * ⚠ "HOLDING" alt yazısı favicon'da 16px'te okunaksız bir lekeye dönüşüyor —
 *   yalnız "ern" markası (y 236..1651) kırpılıp kullanılır.
 * Çıktı: assets/icons/  (16/32/48/180/192/512 + maskable + ico + svg)
 */
$kok = '/home/user/beton-takip-sistemi';
$hedef = $kok . '/assets/icons';
@mkdir($hedef, 0755, true);

// ── 1) Kaynak markayı kırp ────────────────────────────────────────────────
$src = imagecreatefrompng($kok . '/uploads/logo/ERN Holding_Logo_Beyaz.png');
$MX0 = 100; $MX1 = 3402; $MY0 = 236; $MY1 = 1651;          // "ern" markasının kutusu
$mw = $MX1 - $MX0 + 1; $mh = $MY1 - $MY0 + 1;
$mark = imagecreatetruecolor($mw, $mh);
imagealphablending($mark, false); imagesavealpha($mark, true);
imagefill($mark, 0, 0, imagecolorallocatealpha($mark, 255, 255, 255, 127));
imagecopy($mark, $src, 0, 0, $MX0, $MY0, $mw, $mh);

/** Yuvarlak köşeli kare + dikey degrade zemin. */
function zemin(int $s, float $radyus, array $ust, array $alt): \GdImage
{
    $ss = 4;                                                // 4x süper örnekleme (pürüzsüz köşe)
    $b = imagecreatetruecolor($s * $ss, $s * $ss);
    imagealphablending($b, false); imagesavealpha($b, true);
    imagefill($b, 0, 0, imagecolorallocatealpha($b, 0, 0, 0, 127));
    $S = $s * $ss; $r = (int)round($radyus * $S);
    for ($y = 0; $y < $S; $y++) {
        $t = $y / max(1, $S - 1);
        $c = imagecolorallocate($b,
            (int)round($ust[0] + ($alt[0] - $ust[0]) * $t),
            (int)round($ust[1] + ($alt[1] - $ust[1]) * $t),
            (int)round($ust[2] + ($alt[2] - $ust[2]) * $t));
        for ($x = 0; $x < $S; $x++) {
            // köşe yarıçapı dışında kalan pikseller saydam bırakılır
            $dx = $x < $r ? $r - $x : ($x >= $S - $r ? $x - ($S - $r - 1) : 0);
            $dy = $y < $r ? $r - $y : ($y >= $S - $r ? $y - ($S - $r - 1) : 0);
            if ($dx && $dy && ($dx * $dx + $dy * $dy) > $r * $r) continue;
            imagesetpixel($b, $x, $y, $c);
        }
    }
    $out = imagecreatetruecolor($s, $s);
    imagealphablending($out, false); imagesavealpha($out, true);
    imagecopyresampled($out, $b, 0, 0, 0, 0, $s, $s, $S, $S);
    imagedestroy($b);
    return $out;
}

/**
 * İkon üret. $oran = markanın ikon genişliğine oranı.
 * $radyus = köşe yarıçapı (ikon boyutunun oranı; maskable'da 0 = tam kare, Android kendi maskesini uygular).
 */
function ikon(int $s, float $oran, float $radyus): \GdImage
{
    global $mark, $mw, $mh;
    $im = zemin($s, $radyus, [0x00, 0x6B, 0x5C], [0x00, 0x3D, 0x35]);   // --ern-light → --ern-dark
    $w = (int)round($s * $oran); $h = (int)round($w * $mh / $mw);
    $tmp = imagecreatetruecolor($w, $h);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 255, 255, 255, 127));
    imagecopyresampled($tmp, $mark, 0, 0, 0, 0, $w, $h, $mw, $mh);
    imagealphablending($im, true);
    imagecopy($im, $tmp, (int)round(($s - $w) / 2), (int)round(($s - $h) / 2), 0, 0, $w, $h);
    imagesavealpha($im, true);
    imagedestroy($tmp);
    return $im;
}

// ── 2) Boyutlar ───────────────────────────────────────────────────────────
// oran: küçük ikonda mark daha geniş (okunaklılık), büyükte nefes payı bırakılır
$isler = [
    ['favicon-16.png',   16,  0.86, 0.16],
    ['favicon-32.png',   32,  0.82, 0.18],
    ['favicon-48.png',   48,  0.80, 0.20],
    ['apple-touch-icon.png', 180, 0.74, 0.0],   // iOS köşeyi kendi yuvarlar → tam kare (şeffaf köşe iOS'ta siyah olur)
    ['icon-192.png',    192,  0.76, 0.22],
    ['icon-512.png',    512,  0.76, 0.22],
    ['maskable-512.png', 512, 0.56, 0.0],       // Android maskesi: mark %80 güvenli alanda kalmalı → tam kare zemin
];
foreach ($isler as [$ad, $s, $oran, $rad]) {
    $im = ikon($s, $oran, $rad);
    imagepng($im, "$hedef/$ad", 9);
    imagedestroy($im);
    echo str_pad($ad, 24) . " {$s}x{$s}\n";
}

// ── 3) favicon.ico (16/32/48, PNG gömülü — tüm modern tarayıcılar okur) ───
$parcalar = [];
foreach ([16, 32, 48] as $s) $parcalar[$s] = file_get_contents("$hedef/favicon-$s.png");
$n = count($parcalar);
$ico = pack('vvv', 0, 1, $n);                       // reserved, type=1 (icon), adet
$offset = 6 + 16 * $n;
foreach ($parcalar as $s => $veri) {
    $ico .= pack('CCCCvvVV', $s === 256 ? 0 : $s, $s === 256 ? 0 : $s, 0, 0, 1, 32, strlen($veri), $offset);
    $offset += strlen($veri);
}
foreach ($parcalar as $veri) $ico .= $veri;
file_put_contents($kok . '/favicon.ico', $ico);
echo "favicon.ico            " . strlen($ico) . " bayt (16+32+48)\n";

// ── Sidebar logoları (dış portal.ern.com.tr bağımlılığı yerine yerel) ──

function ern_kirp_kaydet(\GdImage $src, int $x0, int $y0, int $x1, int $y1, int $hedefW, string $cikti): void {
    $w = $x1 - $x0 + 1; $h = $y1 - $y0 + 1;
    $hh = (int)round($hedefW * $h / $w);
    $t = imagecreatetruecolor($hedefW, $hh);
    imagealphablending($t, false); imagesavealpha($t, true);
    imagefill($t, 0, 0, imagecolorallocatealpha($t, 255, 255, 255, 127));
    imagecopyresampled($t, $src, 0, 0, $x0, $y0, $hedefW, $hh, $w, $h);
    imagepng($t, $cikti, 9);
    echo basename($cikti) . " {$hedefW}x{$hh}\n";
}
// Yalnız "ern" markası (daraltılmış sidebar) — 3x retina
ern_kirp_kaydet($src, 100, 236, 3402, 1651, 144, $kok . '/assets/icons/ern-mark-white.png');
// Tam kilit: ern + HOLDING (geniş sidebar) — 3x retina, alt boşluk kırpılı
ern_kirp_kaydet($src, 100, 236, 3402, 2248, 180, $kok . '/assets/icons/ern-holding-white.png');
