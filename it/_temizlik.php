<?php
/**
 * it/_temizlik.php — SAHİPSİZ DOSYA TEMİZLİĞİ çekirdeği (itt_*)
 *
 * IT belgeleri sunucuda `uploads/it_envanter/{cihaz_id}/` altında dosya olarak durur, DB'de
 * (`it_belgeler.dosya_url`, `it_cihazlar.foto_url`) yalnız göreli yolu tutulur. Belge kartından
 * silmek dosyayı da siler (`it_belge_sil`), ama iki yoldan diskte ARTIK dosya kalabilir:
 *   • `it_belge_sil` dosyayı `@unlink` ile siler — sunucuda izin yoksa kayıt kalkar, dosya sessizce
 *     yerinde kalır (hata ekrana yansımaz);
 *   • bu kural gelmeden önceki sürümlerden ya da yarıda kesilen yüklemelerden kalan dosyalar.
 * (2026-10-15, kullanıcı: "eski sürümler tamamen silinmesi gerekiyor, yer kaplamasın".)
 *
 * KURALLAR
 *  • Yalnız IT'nin KENDİ klasörlerine bakılır (`IT_TEMIZLIK_KOK`) — §0: başka modülün uploads'una dokunulmaz.
 *  • Bir dosya "sahipsiz" = hiçbir `it_belgeler.dosya_url` ve hiçbir `it_cihazlar.foto_url` ona işaret etmiyor.
 *  • ⚠ YENİ dosyalar KORUNUR (`IT_TEMIZLIK_YENI_SN`): `it_belge_kaydet` dosyayı ÖNCE diske yazar, kaydı
 *    SONRA ekler — tarama tam o arada çalışırsa yükleme sürmekte olan dosya sahipsiz görünürdü.
 *  • Form aktarımının geçici klasörü (`it_form_bekleyen`) DB'ye hiç yazılmaz, oturumda tutulur:
 *    orada yalnız `IT_TEMIZLIK_BEKLEYEN_SN`'den eski dosya artık sayılır (form_import.php de 2 günde siler).
 *  • Silme anında her dosya YENİDEN doğrulanır (klasör içinde mi · bağlantı değil mi · hâlâ sahipsiz mi ·
 *    yeterince eski mi) — ekran açıkken yüklenen bir belge yanlışlıkla silinmesin.
 *  • Silinemeyen dosya SESSİZCE geçilmez: sebebiyle raporlanır (izin sorunu böyle görünür olur).
 */

const IT_TEMIZLIK_KOK = [
    'envanter' => 'uploads/it_envanter',       // cihaz belgeleri / fotoğrafları — DB'deki yollarla karşılaştırılır
    'bekleyen' => 'uploads/it_form_bekleyen',  // tahsis formu aktarımının geçici dosyaları — yaşa göre
];
const IT_TEMIZLIK_YENI_SN     = 2 * 3600;     // bundan yeni dosyaya dokunulmaz (yükleme sürüyor olabilir)
const IT_TEMIZLIK_BEKLEYEN_SN = 2 * 86400;    // geçici form dosyaları bu yaştan sonra artık sayılır

/** Uygulama kökü (uploads'un bulunduğu dizin), sonunda '/' olmadan. */
function itt_kok(): string
{
    return rtrim(str_replace('\\', '/', (string)realpath(__DIR__ . '/..')), '/');
}

/** DB'de saklanan yolu karşılaştırılabilir biçime getirir: "./uploads/x" · "/uploads/x" → "uploads/x". */
function itt_yol(string $url): string
{
    $url = str_replace('\\', '/', trim($url));
    while (str_starts_with($url, './')) $url = substr($url, 2);
    return ltrim($url, '/');
}

/** DB'de bir kayda bağlı olan tüm dosya yolları (url => true). */
function itt_kullanilan(PDO $pdo): array
{
    $k = [];
    foreach ($pdo->query("SELECT dosya_url FROM it_belgeler WHERE dosya_url IS NOT NULL AND dosya_url<>''") as $r)
        $k[itt_yol((string)$r['dosya_url'])] = true;
    foreach ($pdo->query("SELECT foto_url FROM it_cihazlar WHERE foto_url IS NOT NULL AND foto_url<>''") as $r)
        $k[itt_yol((string)$r['foto_url'])] = true;
    return $k;
}

/** "1,2 MB" biçimi. */
function itt_boyut(int $b): string
{
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB', 'TB']; $i = -1; $v = (float)$b;
    do { $v /= 1024; $i++; } while ($v >= 1024 && $i < count($u) - 1);
    return number_format($v, $v < 10 ? 1 : 0, ',', '.') . ' ' . $u[$i];
}

/**
 * Bir yolu temizlik için DOĞRULAR. Geçerliyse [tam yol, kök anahtarı] döner, değilse [null, sebep].
 * ⚠ Formdan gelen her yol buradan geçer: kök klasör dışı, '..', bağlantı (symlink) ve klasör reddedilir.
 */
function itt_dogrula(string $url): array
{
    $url = itt_yol($url);
    if ($url === '' || str_contains($url, '..') || str_contains($url, "\0")) return [null, 'geçersiz yol'];
    $kokAnahtar = null;
    foreach (IT_TEMIZLIK_KOK as $a => $kok) if (str_starts_with($url, $kok . '/')) { $kokAnahtar = $a; break; }
    if ($kokAnahtar === null) return [null, 'IT klasörü dışında'];
    $kok = itt_kok();
    $tam = $kok . '/' . $url;
    if (is_link($tam)) return [null, 'bağlantı (symlink) — dokunulmadı'];
    if (!is_file($tam)) return [null, 'dosya yok (zaten silinmiş)'];
    $gercek = str_replace('\\', '/', (string)realpath($tam));
    $gKok   = str_replace('\\', '/', (string)realpath($kok . '/' . IT_TEMIZLIK_KOK[$kokAnahtar]));
    if ($gercek === '' || $gKok === '' || !str_starts_with($gercek, $gKok . '/')) return [null, 'IT klasörü dışında'];
    return [$tam, $kokAnahtar];
}

/**
 * Diski tarar. Döner:
 *   sahipsiz  — silinebilir dosyalar [url, boyut, zaman, kok, cihaz_id, cihaz_var, neden] (büyükten küçüğe)
 *   korunan   — sahipsiz ama yeni olduğu için dokunulmayanlar
 *   kirik     — DB'de kaydı olup diskte dosyası OLMAYAN belgeler (listede görünür ama açılmaz)
 *   ozet      — sayılar ve boyutlar
 */
function itt_tara(PDO $pdo, ?int $simdi = null): array
{
    $simdi = $simdi ?? time();
    $kok = itt_kok();
    $kullanilan = itt_kullanilan($pdo);
    $cihazlar = [];
    foreach ($pdo->query("SELECT id FROM it_cihazlar") as $r) $cihazlar[(int)$r['id']] = true;

    $o = ['dosya'=>0, 'boyut'=>0, 'kullanilan'=>0, 'kullanilan_boyut'=>0, 'sahipsiz'=>0, 'sahipsiz_boyut'=>0,
          'korunan'=>0, 'korunan_boyut'=>0, 'kirik'=>0];
    $sahipsiz = []; $korunan = [];

    foreach (IT_TEMIZLIK_KOK as $a => $klasor) {
        $dir = $kok . '/' . $klasor;
        if (!is_dir($dir)) continue;
        // FOLLOW_SYMLINKS verilmez: bağlantılı klasörlerin içine inilmez
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile() || $f->isLink()) continue;
            $ad = $f->getFilename();
            if ($ad[0] === '.' || strcasecmp($ad, 'index.html') === 0 || strcasecmp($ad, 'index.php') === 0) continue;
            $url = itt_yol(substr(str_replace('\\', '/', $f->getPathname()), strlen($kok) + 1));
            $boyut = (int)$f->getSize(); $zaman = (int)$f->getMTime();
            $o['dosya']++; $o['boyut'] += $boyut;

            if ($a === 'envanter' && isset($kullanilan[$url])) { $o['kullanilan']++; $o['kullanilan_boyut'] += $boyut; continue; }

            $cihazId = ($a === 'envanter' && preg_match('#^uploads/it_envanter/(\d+)/#', $url, $m)) ? (int)$m[1] : 0;
            $satir = ['url'=>$url, 'boyut'=>$boyut, 'zaman'=>$zaman, 'kok'=>$a, 'cihaz_id'=>$cihazId,
                      'cihaz_var'=>$cihazId && isset($cihazlar[$cihazId])];
            $sinir = $a === 'bekleyen' ? IT_TEMIZLIK_BEKLEYEN_SN : IT_TEMIZLIK_YENI_SN;
            if ($simdi - $zaman < $sinir) {
                $satir['neden'] = $a === 'bekleyen' ? 'form aktarımı sürüyor olabilir' : 'yeni yüklendi — kaydı henüz yazılıyor olabilir';
                $korunan[] = $satir; $o['korunan']++; $o['korunan_boyut'] += $boyut;
                continue;
            }
            $satir['neden'] = $a === 'bekleyen'
                ? 'tahsis formu aktarımından kalan geçici dosya'
                : ($satir['cihaz_var'] ? 'hiçbir belge kaydına bağlı değil' : 'cihaz kaydı yok (silinmiş/birleştirilmiş)');
            $sahipsiz[] = $satir; $o['sahipsiz']++; $o['sahipsiz_boyut'] += $boyut;
        }
    }
    usort($sahipsiz, fn($x, $y) => $y['boyut'] <=> $x['boyut'] ?: strcmp($x['url'], $y['url']));

    // Kırık bağlantılar: kayıt var, dosya yok (yalnız IT klasöründeki yollar — dış URL'ler sayılmaz)
    $kirik = [];
    $st = $pdo->query("SELECT b.id, b.cihaz_id, b.dosya_url, b.ad, b.tur, c.envanter_no, c.cihaz_kodu, c.ad cihaz_ad
                         FROM it_belgeler b LEFT JOIN it_cihazlar c ON c.id=b.cihaz_id ORDER BY b.id");
    foreach ($st as $r) {
        $u = itt_yol((string)$r['dosya_url']);
        if (!str_starts_with($u, IT_TEMIZLIK_KOK['envanter'] . '/')) continue;
        if (!is_file($kok . '/' . $u)) $kirik[] = $r + ['url'=>$u];
    }
    $o['kirik'] = count($kirik);

    return ['sahipsiz'=>$sahipsiz, 'korunan'=>$korunan, 'kirik'=>$kirik, 'ozet'=>$o];
}

/**
 * Verilen yolları siler — HER BİRİ silme anında yeniden doğrulanır (bkz. dosya başı).
 * Döner: silinen [url=>boyut] · hata [url=>sebep] · bosalan (kaldırılan boş klasör sayısı) · boyut (açılan alan)
 */
function itt_sil(PDO $pdo, array $urller, ?int $simdi = null): array
{
    $simdi = $simdi ?? time();
    $kullanilan = itt_kullanilan($pdo);         // sayfa açıldıktan sonra yüklenen belgeyi de görsün
    $s = ['silinen'=>[], 'hata'=>[], 'bosalan'=>0, 'boyut'=>0];
    $klasorler = [];
    foreach (array_unique(array_map(fn($u) => itt_yol((string)$u), $urller)) as $url) {
        if ($url === '') continue;
        [$tam, $kokA] = itt_dogrula($url);
        if ($tam === null) { $s['hata'][$url] = $kokA; continue; }
        if ($kokA === 'envanter' && isset($kullanilan[$url])) { $s['hata'][$url] = 'artık bir kayda bağlı — silinmedi'; continue; }
        $sinir = $kokA === 'bekleyen' ? IT_TEMIZLIK_BEKLEYEN_SN : IT_TEMIZLIK_YENI_SN;
        if ($simdi - (int)@filemtime($tam) < $sinir) { $s['hata'][$url] = 'yeni dosya — korundu'; continue; }
        $boyut = (int)@filesize($tam);
        if (!@unlink($tam)) {
            $e = error_get_last();
            $s['hata'][$url] = 'silinemedi' . (is_writable(dirname($tam)) ? '' : ' (klasöre yazma izni yok)')
                             . (!empty($e['message']) ? ' — ' . str_replace(itt_kok() . '/', '', $e['message']) : '');
            continue;
        }
        clearstatcache(true, $tam);
        $s['silinen'][$url] = $boyut; $s['boyut'] += $boyut;
        $klasorler[dirname($tam)] = true;
    }
    // Boşalan cihaz klasörlerini kaldır (kök klasörün kendisine dokunma)
    $koklar = array_map(fn($k) => itt_kok() . '/' . $k, IT_TEMIZLIK_KOK);
    foreach (array_keys($klasorler) as $d) {
        if (in_array($d, $koklar, true) || !is_dir($d) || is_link($d)) continue;
        $icerik = @scandir($d);
        if ($icerik !== false && count(array_diff($icerik, ['.', '..'])) === 0 && @rmdir($d)) $s['bosalan']++;
    }
    return $s;
}
