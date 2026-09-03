<?php
/**
 * import.php — Depo Excel içe aktarma (ÇOKLU DOSYA)
 *
 * Takip 4 ayrı Excel dosyasında gelir; hepsi tek seferde seçilip yüklenebilir
 * (dosya[] multiple). Her dosya kendi başına işlenir, kendi transaction'ında.
 *
 * İki tür sayfa okunur:
 *   STOK   → DEMİRBAŞLAR · SARF MALZEME · EL ALETLERİ            → depo_kalemler
 *   HAREKET→ MALZEME GİRİŞ/ÇIKIŞ · TAŞERON MALZEME GİRİŞ/TESLİMAT → depo_hareketler
 *
 * Her ikisi de **tam yenileme**: ilgili kategori/tür önce silinir, sonra Excel'den
 * yeniden yazılır (Excel tek doğru kaynak ilkesi). Dosyada olmayan bölüme dokunulmaz.
 *
 * Her yüklemede **veri doğrulama raporu** üretilir: Excel'in kendi özet hücreleri
 * (KALEM SAYISI / STOK MALİ DEĞERİ), STOK sütunu = SAYIM+GELEN−GİDEN sağlaması,
 * negatif stok, mükerrer kalem, okunamayan miktar/tarih, boş firma/fiş no/onay,
 * çift girilmiş hareket satırları. Bölüm bazında son yükleme `depo_import_log`'a yazılır.
 *
 * ⚠ Sütunlar SABİT DEĞİL — başlık satırındaki metinden bulunur. Sayfalar arasında
 *   sütun düzeni farklı (ör. SARF'ta MALZEME KODU yok, hepsi bir kayıyor); sabit
 *   indeks kullanmak yanlış sütunu okumaya yol açıyordu.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

$pageTitle = 'Depo Excel İçe Aktarma';

/**
 * Sayfa adını "içerir" mantığıyla bulur (ör. "KARTAL-BATIYAKASI SARF MALZEME" → SARF MALZEME).
 *
 * $kullanilan: daha önce başka bir türe atanmış sayfa indeksleri. Şart —
 * "TAŞERON MALZEME GİRİŞ" adı "MALZEME GİRİŞ" ifadesini de İÇERİR; işaretlenmezse
 * aynı sayfa hem taşeron hem depo girişi olarak iki kez aktarılırdı.
 */
function dpSheet(SimpleXLSX $x, array $parcalar, array $kullanilan = []): ?int
{
    foreach ($x->sheetNames() as $i => $n) {
        if (in_array((int)$i, $kullanilan, true)) continue;
        $u = dpNorm($n);
        foreach ($parcalar as $p) if (mb_strpos($u, dpNorm($p)) !== false) return (int)$i;
    }
    return null;
}

/**
 * Başlık/sayfa adı normalizasyonu.
 * Türkçe harfler ASCII'ye katlanır: DEMİRBAŞLAR → DEMIRBASLAR, ÇIKAN → CIKAN,
 * MALZEME GİRİŞ → MALZEME GIRIS. Aksi halde 'Ş' ile 'S' eşleşmediği için
 * sayfa ve sütun aramaları sessizce boş dönüyordu.
 */
function dpNorm(string $s): string
{
    $s = str_replace(
        ['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
        ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'],
        $s
    );
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return preg_replace('/\s+/', ' ', $s);
}

/** Başlık satırını bulur: aranan başlıkların en çoğunu içeren ilk satır. */
function dpBaslikSatiri(array $rows, array $aranan, int $limit = 12): int
{
    $enIyi = 0; $enIyiSkor = 0;
    foreach (array_slice($rows, 0, $limit, true) as $ri => $row) {
        $skor = 0;
        foreach ($row as $c) {
            $u = dpNorm((string)$c);
            if ($u === '') continue;
            foreach ($aranan as $a) if (mb_strpos($u, dpNorm($a)) !== false) { $skor++; break; }
        }
        if ($skor > $enIyiSkor) { $enIyiSkor = $skor; $enIyi = $ri; }
    }
    return $enIyi;
}

/**
 * Başlık satırından alan→sütun haritası kurar.
 * @param array $tanim  alan => [aranacak başlık parçaları...] (ilk eşleşen kazanır)
 */
function dpHarita(array $baslik, array $tanim): array
{
    $h = []; $kullanilan = [];
    foreach ($tanim as $alan => $adaylar) {
        // Aday sırası önceliktir: önce en spesifik başlık denenir
        foreach ($adaylar as $a) {
            $ara = dpNorm($a);
            foreach ($baslik as $ci => $c) {
                if (isset($kullanilan[(int)$ci])) continue;   // bir sütun tek alana bağlanır
                $u = dpNorm((string)$c);
                if ($u !== '' && mb_strpos($u, $ara) !== false) {
                    $h[$alan] = (int)$ci; $kullanilan[(int)$ci] = true; break 2;
                }
            }
        }
    }
    return $h;
}

/** Haritadan değer okur (alan yoksa boş döner). */
function dpAl(array $row, array $h, string $alan): string
{
    if (!isset($h[$alan])) return '';
    return trim((string)($row[$h[$alan]] ?? ''));
}

// ── STOK sayfaları ───────────────────────────────────────────────────────────
const DP_STOK_ALAN = [
    'sira'        => ['S.NO', 'SIRA NO', 'SIRA'],
    'kod'         => ['MALZEME KODU', 'SERI NUMARASI', 'SERI NO'],
    'ad'          => ['MALZEME ADI', 'MALZEME CINSI'],
    'ozellik'     => ['MALZEME OZELLIKLERI', 'MARKA/OZELLIK', 'OZELLIK'],
    'birim'       => ['BIRIM'],          // "BİRİM FİYAT"tan önce gelmesi için aşağıda özel kontrol var
    'sayim'       => ['SAYIM'],
    'gelen'       => ['GELEN'],
    'giden'       => ['GIDEN', 'CIKAN'],
    'stok'        => ['STOK'],           // yalnız sağlama için (Excel'in kendi STOK sütunu)
    'birim_fiyat' => ['BIRIM FIYAT'],
    'disiplin'    => ['DISIPLIN'],
    'alan'        => ['BULUNDUGU ALAN', 'LOKASYON'],
    'alan_kisi'   => ['ALAN KISI', 'ZIMMETLI'],
];

// ── HAREKET sayfaları ────────────────────────────────────────────────────────
const DP_HRK_ALAN = [
    'tarih'        => ['MALZEME GELIS TARIH', 'TARIH'],
    'belge_tarihi' => ['IRSALIYE/FATURA TARIHI', 'FATURA TARIHI'],
    'belge_no'     => ['IRSALIYE NO', 'FIS NO', 'BELGE NO'],
    'malzeme'      => ['MALZEME CINSI VE TANIMI', 'MALZEME ADI VE TANIMI', 'MALZEME ADI', 'MALZEME'],
    'ozellik'      => ['OZELLIGI', 'OZELLIK'],
    'miktar'       => ['GELEN MIKTAR', 'GIDEN', 'MIKTAR'],
    'birim'        => ['BIRIMI', 'BIRIM'],
    'firma'        => ['GONDEREN FIRMA', 'CIKIS YAPILAN FIRMA', 'TASERON', 'FIRMA'],
    'teslim_alan'  => ['ACIKLAMA/TESLIM ALAN', 'TESLIM ALAN'],
    'onay'         => ['ONAY'],
    'lokasyon'     => ['LOKASYON'],
    'aciklama'     => ['ACIKLAMA'],
];

/** Bölüm tanımları: anahtar → [etiket, hedef, sayfa adı parçaları] */
const DP_BOLUM = [
    'demirbas'      => ['DEMİRBAŞLAR',              'Stok — Demirbaşlar',       'Demirbaş Takip'],
    'sarf'          => ['SARF MALZEME',             'Stok — Sarf Malzeme',      'Sarf Malzeme Stok'],
    'el_aleti'      => ['EL ALETLERİ',              'Stok — El Aletleri',       'Sarf Malzeme Stok'],
    'giris|depo'    => ['MALZEME GİRİŞ',            'Hareket — Depo Girişi',    'Malzeme Takip'],
    'cikis|depo'    => ['MALZEME ÇIKIŞ',            'Hareket — Depo Çıkışı',    'Malzeme Takip'],
    'giris|taseron' => ['TAŞERON MALZEME GİRİŞ',    'Hareket — Taşeron Girişi', 'Sarf Taşeron Teslimat'],
    'cikis|taseron' => ['TAŞERON MALZEME TESLİMAT', 'Hareket — Taşeron Çıkışı', 'Sarf Taşeron Teslimat'],
];

/** Bölüm → son yükleme kaydı. */
function dp_import_son(PDO $pdo): array
{
    $son = [];
    try {
        $q = $pdo->query("SELECT l.bolum, l.dosya, l.satir, l.kullanici, l.created FROM depo_import_log l
                          JOIN (SELECT bolum, MAX(id) mid FROM depo_import_log GROUP BY bolum) m ON m.mid = l.id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $son[$r['bolum']] = $r;
    } catch (Throwable $e) { /* tablo yoksa boş */ }
    return $son;
}

/**
 * Tek bir Excel dosyasını içe aktarır (kendi transaction'ı).
 * Dönüş: bolumler (aktarılan bölüm → satır), bulunan (etiketler), saglamalar, kontrol (veri doğrulama).
 * kontrol satırı: [seviye ok|uyari|bilgi, mesaj, detay[] (satır listesi)]
 */
function dp_import_dosya(PDO $pdo, SimpleXLSX $x, string $dosyaAd): array
{
    $sonuc = ['bolumler'=>[], 'bulunan'=>[], 'saglamalar'=>[], 'kontrol'=>[]];
    $kullanilanSayfa = [];   // bir sayfa yalnız bir türe aktarılır
    $kullanici = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;

    $stokSayfa = ['demirbas'=>['DEMIRBAS'], 'sarf'=>['SARF MALZEME'], 'el_aleti'=>['EL ALETLERI']];
    $hrkSayfa = [
        ['giris', 'taseron', ['TASERON MALZEME GIRIS']],
        ['cikis', 'taseron', ['TASERON MALZEME TESLIMAT', 'TASERON MALZEME CIKIS']],
        ['giris', 'depo',    ['MALZEME GIRIS']],
        ['cikis', 'depo',    ['MALZEME CIKIS']],
    ];
    $liste = fn(array $l) => count($l) > 12 ? array_merge(array_slice($l, 0, 12), ['… ve ' . (count($l) - 12) . ' satır daha']) : $l;
    $kisa  = fn(array $v) => implode(' / ', array_slice($v, 0, 6)) . (count($v) > 6 ? ' … +' . (count($v) - 6) : '');

    try {
        $pdo->beginTransaction();
        $logIns = $pdo->prepare("INSERT INTO depo_import_log (bolum, dosya, satir, kullanici) VALUES (?,?,?,?)");

        // ── STOK ────────────────────────────────────────────────────────
        $ins = $pdo->prepare("INSERT INTO depo_kalemler
            (kategori,sira,kod,ad,ozellik,birim,sayim,gelen,giden,birim_fiyat,disiplin,alan,alan_kisi)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($stokSayfa as $kat => $parcalar) {
            $si = dpSheet($x, $parcalar, $kullanilanSayfa);
            if ($si === null) continue;
            $kullanilanSayfa[] = $si;
            $rows = $x->rows($si, 8000);
            $hr   = dpBaslikSatiri($rows, ['MALZEME ADI', 'SAYIM', 'STOK', 'BIRIM']);
            $h    = dpHarita($rows[$hr] ?? [], DP_STOK_ALAN);
            // "BİRİM" ile "BİRİM FİYAT" aynı hücreye denk gelmesin
            if (isset($h['birim'], $h['birim_fiyat']) && $h['birim'] === $h['birim_fiyat']) unset($h['birim']);
            $katAd = dp_katAd($kat);
            if (!isset($h['ad'])) { $sonuc['kontrol'][] = ['uyari', "$katAd: \"MALZEME ADI\" sütunu bulunamadı, sayfa atlandı", []]; continue; }

            // Excel'in başlık ÜSTÜNDEKİ kendi özet hücreleri ("STOK MALİ DEĞERİ:" / "KALEM SAYISI:")
            // — kutsal kitap sağlaması: içe aktarım bittiğinde hesapla karşılaştırılır.
            $exMali = null; $exKalem = null;
            foreach (array_slice($rows, 0, $hr) as $oRow) {
                foreach ($oRow as $ci => $c) {
                    $u = dpNorm((string)$c);
                    if ($u === '') continue;
                    $hedef = str_contains($u, 'MALI DEGERI') ? 'mali' : (str_contains($u, 'KALEM SAYISI') ? 'kalem' : null);
                    if ($hedef === null) continue;
                    for ($cj = $ci + 1; $cj < count($oRow); $cj++) {
                        $vv = trim((string)($oRow[$cj] ?? ''));
                        if ($vv === '') continue;
                        if ($hedef === 'mali') $exMali = dp_sayi($vv); else $exKalem = dp_sayi($vv);
                        break;
                    }
                }
            }
            $tutarHesap = 0.0;
            $say = 0;
            $stokFark = []; $negatif = []; $bosBirim = 0; $fiyatsiz = 0; $grup = [];

            $pdo->prepare("DELETE FROM depo_kalemler WHERE kategori=?")->execute([$kat]);
            $varsayilanBirim = $GLOBALS['DP_KATEGORI'][$kat]['birim'] ?? 'Ad';
            for ($i = $hr + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $ad  = dpAl($row, $h, 'ad');
                if ($ad === '') continue;
                $sayim = dp_sayi(dpAl($row,$h,'sayim'));
                $gelen = dp_sayi(dpAl($row,$h,'gelen'));
                $giden = dp_sayi(dpAl($row,$h,'giden'));
                $bf    = dp_sayi(dpAl($row,$h,'birim_fiyat'));
                $stok  = $sayim + $gelen - $giden;
                $tutarHesap += $stok * $bf;
                // ── veri doğrulama ──
                if (isset($h['stok'])) {
                    $exStok = dp_sayi(dpAl($row,$h,'stok'));
                    if (abs($exStok - $stok) > 0.001)
                        $stokFark[] = 'satır ' . ($i+1) . " · $ad: Excel STOK " . dp_fmt($exStok) . ', hesap ' . dp_fmt($stok);
                }
                if ($stok < 0) $negatif[] = 'satır ' . ($i+1) . " · $ad: " . dp_fmt($stok);
                if (dpAl($row,$h,'birim') === '') $bosBirim++;
                if ($kat !== 'el_aleti' && $bf == 0) $fiyatsiz++;
                $kod = dpAl($row,$h,'kod');
                // Demirbaşta aynı adlı birçok gerçek kalem olur (20 ayakkabılık; MALZEME KODU da benzersiz değil)
                // → mükerrer kontrolü yalnız sarf/el aletinde: aynı ad+özellik iki satır = çift kart.
                if ($kat !== 'demirbas') $grup[dp_mal_norm($ad . ' ' . dpAl($row,$h,'ozellik'))][] = ($i+1) . " ($ad → sayım " . dp_fmt($sayim) . ')';

                $ins->execute([
                    $kat,
                    (int)dpAl($row,$h,'sira') ?: null,
                    $kod ?: null,
                    $ad,
                    dpAl($row,$h,'ozellik') ?: null,
                    dpAl($row,$h,'birim') ?: $varsayilanBirim,
                    $sayim, $gelen, $giden,
                    $bf ?: null,
                    dpAl($row,$h,'disiplin') ?: null,
                    dpAl($row,$h,'alan') ?: null,
                    dpAl($row,$h,'alan_kisi') ?: null,
                ]);
                $say++;
            }
            $sonuc['bolumler'][$kat] = $say;
            $sonuc['bulunan'][] = "$katAd ($say kalem)";
            $logIns->execute([$kat, $dosyaAd, $say, $kullanici]);

            // Sağlama: Excel'in yazdığı özetle hesap birebir mi?
            if ($exKalem !== null && $exKalem > 0) {
                $sonuc['saglamalar'][] = abs($exKalem - $say) < 0.5
                    ? ['ok', "$katAd: kalem sayısı Excel özetiyle birebir ($say)"]
                    : ['fark', "$katAd: Excel \"KALEM SAYISI\" " . dp_fmt($exKalem) . " yazıyor, aktarılan $say — Excel özet hücresi bayat olabilir"];
            }
            if ($exMali !== null && $exMali > 0) {
                $sonuc['saglamalar'][] = abs($exMali - $tutarHesap) <= max(1.0, $exMali * 0.001)
                    ? ['ok', "$katAd: mali değer Excel özetiyle birebir (" . number_format($tutarHesap, 2, ',', '.') . ' TL)']
                    : ['fark', "$katAd: Excel \"STOK MALİ DEĞERİ\" " . number_format($exMali, 2, ',', '.') . ' TL yazıyor, hesaplanan ' . number_format($tutarHesap, 2, ',', '.') . ' TL — birim fiyat/formül kontrol edilmeli'];
            }
            if (isset($h['stok'])) {
                $sonuc['kontrol'][] = $stokFark
                    ? ['uyari', "$katAd: " . count($stokFark) . ' satırda Excel STOK sütunu SAYIM+GELEN−GİDEN ile uyuşmuyor (Excel formülü bozuk/elle ezilmiş olabilir; sistem hesabı kullandı)', $liste($stokFark)]
                    : ['ok', "$katAd: STOK sütunu tüm satırlarda SAYIM+GELEN−GİDEN ile birebir", []];
            }
            if ($negatif) $sonuc['kontrol'][] = ['uyari', "$katAd: " . count($negatif) . ' kalemde stok EKSİ (giden > sayım+gelen)', $liste($negatif)];
            $mukerrer = array_filter($grup, fn($v) => count($v) > 1);
            if ($mukerrer) {
                $d = [];
                foreach ($mukerrer as $k => $v) $d[] = 'satır ' . $kisa($v);
                $sonuc['kontrol'][] = ['uyari', "$katAd: " . count($mukerrer) . ' malzeme aynı ad+özellikle birden çok kartta (Excel\'de birleştirilmeli)', $liste($d)];
            }
            if ($bosBirim) $sonuc['kontrol'][] = ['bilgi', "$katAd: $bosBirim satırda birim boş — \"$varsayilanBirim\" yazıldı", []];
            if ($fiyatsiz) $sonuc['kontrol'][] = [$fiyatsiz === $say ? 'bilgi' : 'uyari',
                $fiyatsiz === $say ? "$katAd: birim fiyat hiç girilmemiş, mali değer 0 görünecek" : "$katAd: $fiyatsiz kalemde birim fiyat boş (mali değere katılmadı)", []];
        }

        // ── HAREKET ─────────────────────────────────────────────────────
        $insH = $pdo->prepare("INSERT INTO depo_hareketler
            (tur,kaynak,tarih,belge_tarihi,belge_no,malzeme,ozellik,birim,miktar,firma,teslim_alan,onay,lokasyon,aciklama)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($hrkSayfa as [$tur, $kaynak, $parcalar]) {
            $si = dpSheet($x, $parcalar, $kullanilanSayfa);
            if ($si === null) continue;
            $kullanilanSayfa[] = $si;
            $rows = $x->rows($si, 8000);
            $hr   = dpBaslikSatiri($rows, ['TARIH', 'MALZEME', 'MIKTAR', 'BIRIM', 'FIRMA', 'ONAY']);
            $h    = dpHarita($rows[$hr] ?? [], DP_HRK_ALAN);
            if (isset($h['birim'], $h['miktar']) && $h['birim'] === $h['miktar']) unset($h['birim']);
            $anahtar = $tur . '|' . $kaynak;
            $bAd = dp_kaynakAd($kaynak) . ' ' . dp_turAd($tur);
            if (!isset($h['malzeme'])) { $sonuc['kontrol'][] = ['uyari', "$bAd: malzeme sütunu bulunamadı, sayfa atlandı", []]; continue; }

            $say = 0; $toplam = 0.0;
            $miktarYok = []; $tarihYok = []; $firmasiz = 0; $belgesiz = 0; $onaysiz = 0; $grup = []; $minT = null; $maxT = null;
            // Elle girilen günlük kayıtlar tam yenilemede korunur (Excel'de zaten yoklar)
            $pdo->prepare("DELETE FROM depo_hareketler WHERE tur=? AND kaynak=? AND elle=0")->execute([$tur, $kaynak]);
            for ($i = $hr + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $mal = dpAl($row, $h, 'malzeme');
                if ($mal === '') continue;
                $hamMiktar = dpAl($row,$h,'miktar');
                $miktar = dp_sayi($hamMiktar);
                $hamTarih = dpAl($row,$h,'tarih');
                $tarih = dp_tarih($hamTarih);
                $aciklama = dpAl($row,$h,'aciklama');
                $belge = dpAl($row,$h,'belge_no');
                $firma = dpAl($row,$h,'firma');
                // ── veri doğrulama ──
                if ($miktar == 0) {
                    // "1X5", "PALET" gibi metin miktarlar sayıya çevrilemez; bilgi kaybolmasın diye açıklamaya eklenir
                    if ($hamMiktar !== '' && !is_numeric(str_replace(',', '.', $hamMiktar))) $aciklama = trim("[Miktar: $hamMiktar] " . $aciklama);
                    $miktarYok[] = 'satır ' . ($i+1) . " · $mal" . ($belge !== '' ? " (fiş $belge)" : '') . ': ' . ($hamMiktar === '' ? 'miktar boş' : (is_numeric(str_replace(',', '.', $hamMiktar)) ? 'miktar 0' : "\"$hamMiktar\" sayı değil"));
                }
                if ($tarih === null) $tarihYok[] = 'satır ' . ($i+1) . " · $mal: \"$hamTarih\"";
                else { $minT = $minT === null ? $tarih : min($minT, $tarih); $maxT = $maxT === null ? $tarih : max($maxT, $tarih); }
                if ($firma === '') $firmasiz++;
                if ($belge === '') $belgesiz++;
                if (isset($h['onay']) && dpAl($row,$h,'onay') === '') $onaysiz++;
                $grup[($tarih ?? '') . '|' . dpNorm($belge) . '|' . dp_mal_norm($mal) . '|' . $miktar][] = $i + 1;

                $insH->execute([
                    $tur, $kaynak,
                    $tarih,
                    dp_tarih(dpAl($row,$h,'belge_tarihi')),
                    $belge ?: null,
                    mb_substr($mal, 0, 255),
                    dpAl($row,$h,'ozellik') ?: null,
                    dpAl($row,$h,'birim') ?: null,
                    $miktar,
                    $firma ?: null,
                    dpAl($row,$h,'teslim_alan') ?: null,
                    dpAl($row,$h,'onay') ?: null,
                    dpAl($row,$h,'lokasyon') ?: null,
                    $aciklama !== '' ? mb_substr($aciklama, 0, 255) : null,
                ]);
                $say++; $toplam += $miktar;
            }
            $sonuc['bolumler'][$anahtar] = $say;
            $sonuc['bulunan'][] = "$bAd ($say hareket)";
            $logIns->execute([$anahtar, $dosyaAd, $say, $kullanici]);

            $sonuc['kontrol'][] = ['bilgi', "$bAd: $say satır" . ($minT ? ", tarih aralığı " . format_date($minT) . ' → ' . format_date($maxT) : ''), []];
            if ($miktarYok) $sonuc['kontrol'][] = ['uyari', "$bAd: " . count($miktarYok) . ' satırda miktar okunamadı (0 yazıldı, ham değer açıklamaya eklendi)', $liste($miktarYok)];
            if ($tarihYok)  $sonuc['kontrol'][] = ['uyari', "$bAd: " . count($tarihYok) . ' satırda tarih okunamadı (boş bırakıldı)', $liste($tarihYok)];
            $mukerrer = array_filter($grup, fn($v) => count($v) > 1);
            if ($mukerrer) {
                $d = [];
                foreach ($mukerrer as $k => $v) { [$t, $b, $m, $q] = explode('|', $k, 4); $d[] = 'satır ' . $kisa($v) . " · $m" . ($b !== '' ? " (belge $b)" : '') . ' · ' . dp_fmt((float)$q) . ($t ? ' · ' . format_date($t) : ''); }
                $sonuc['kontrol'][] = ['uyari', "$bAd: " . count($mukerrer) . ' grup aynı tarih+belge+malzeme+miktarla birden çok satırda — Excel\'de çift girilmiş olabilir (hepsi aktarıldı, Excel esas)', $liste($d)];
            }
            if ($firmasiz) $sonuc['kontrol'][] = ['bilgi', "$bAd: $firmasiz satırda firma boş", []];
            if ($belgesiz) $sonuc['kontrol'][] = ['bilgi', "$bAd: $belgesiz satırda " . ($tur === 'giris' ? 'irsaliye' : 'fiş') . ' no boş', []];
            if ($onaysiz)  $sonuc['kontrol'][] = ['bilgi', "$bAd: $onaysiz satırda onay boş", []];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $sonuc;
}

function dp_fmt(float $n): string
{
    return number_format($n, abs($n - round($n)) < 0.0005 ? 0 : 2, ',', '.');
}

// ── POST: bir veya birden çok dosya ──────────────────────────────────────────
$raporlar = [];          // dosya başına sonuç
$genelUyari = [];        // dosyalar arası (aynı bölüm iki dosyada vb.)
$buYukleme = [];         // bölüm → dosya adı (bu yüklemede yenilenenler)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['dosya'])) {
    dp_hareket_semasi_kur($pdoDepo);
    dp_import_log_kur($pdoDepo);
    $f = $_FILES['dosya'];
    $dosyalar = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $ad) $dosyalar[] = ['name'=>$ad, 'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]];
    } else {
        $dosyalar[] = $f;
    }
    $dosyalar = array_values(array_filter($dosyalar, fn($d) => ($d['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$dosyalar) $genelUyari[] = 'Dosya seçilmedi.';

    foreach ($dosyalar as $d) {
        $ad = basename((string)$d['name']);
        $rap = ['ad'=>$ad, 'hata'=>null, 'sonuc'=>null];
        if ($d['error'] !== UPLOAD_ERR_OK) {
            $rap['hata'] = match ((int)$d['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu boyut sınırını aşıyor (upload_max_filesize).',
                UPLOAD_ERR_PARTIAL => 'Dosya eksik yüklendi, tekrar deneyin.',
                default => 'Yükleme hatası (kod ' . (int)$d['error'] . ').',
            };
        } elseif (!preg_match('/\.xlsx$/i', $ad)) {
            $rap['hata'] = 'Yalnız .xlsx dosyası kabul edilir.';
        } elseif (!($x = SimpleXLSX::parse($d['tmp_name']))) {
            $rap['hata'] = 'Excel okunamadı: ' . SimpleXLSX::parseError();
        } else {
            try {
                $s = dp_import_dosya($pdoDepo, $x, $ad);
                if (!$s['bolumler']) {
                    $rap['hata'] = 'Bu dosyada tanınan sayfa yok (sayfalar: ' . implode(', ', $x->sheetNames()) . '). Hiçbir şey değiştirilmedi.';
                } else {
                    $rap['sonuc'] = $s;
                    foreach ($s['bolumler'] as $b => $n) {
                        if (isset($buYukleme[$b]) && $buYukleme[$b] !== $ad)
                            $genelUyari[] = (DP_BOLUM[$b][0] ?? $b) . " bölümü hem \"{$buYukleme[$b]}\" hem \"$ad\" dosyasında var — son işlenen ($ad) esas alındı.";
                        $buYukleme[$b] = $ad;
                    }
                }
            } catch (Throwable $e) {
                $rap['hata'] = 'İçe aktarma hatası: ' . $e->getMessage() . ' (bu dosyanın hiçbir bölümü değiştirilmedi)';
            }
        }
        $raporlar[] = $rap;
    }
}

$sonYukleme = dp_import_son($pdoDepo);
$korunan = 0;
try { $korunan = (int)$pdoDepo->query("SELECT COUNT(*) FROM depo_hareketler WHERE elle=1")->fetchColumn(); } catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
$ikon = ['ok'=>'<i class="bi bi-patch-check-fill text-success"></i>', 'uyari'=>'<i class="bi bi-exclamation-triangle-fill text-warning"></i>', 'bilgi'=>'<i class="bi bi-info-circle text-secondary"></i>', 'fark'=>'<i class="bi bi-exclamation-triangle-fill text-warning"></i>'];
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Depo Excel İçe Aktarma</h4>

<?php foreach ($genelUyari as $g): ?><div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= h($g) ?></div><?php endforeach; ?>

<?php if ($raporlar): ?>
<?php
    $basarili = array_filter($raporlar, fn($r) => $r['sonuc'] !== null);
    $toplamUyari = 0;
    foreach ($basarili as $r) { foreach ($r['sonuc']['kontrol'] as $k) if ($k[0] === 'uyari') $toplamUyari++; foreach ($r['sonuc']['saglamalar'] as $s) if ($s[0] === 'fark') $toplamUyari++; }
?>
<div class="alert <?= $basarili ? 'alert-success' : 'alert-danger' ?> py-2">
    <i class="bi bi-<?= $basarili ? 'check-circle' : 'x-circle' ?> me-1"></i>
    <strong><?= count($basarili) ?>/<?= count($raporlar) ?> dosya aktarıldı.</strong>
    <?php if ($buYukleme): ?>Yenilenen bölümler: <?= h(implode(' · ', array_map(fn($b) => DP_BOLUM[$b][0] ?? $b, array_keys($buYukleme)))) ?>.<?php endif; ?>
    <?php if ($toplamUyari): ?><span class="badge bg-warning text-dark ms-1"><?= $toplamUyari ?> veri uyarısı</span><?php endif; ?>
    <?php if ($korunan): ?><span class="text-muted small ms-1">— elle girilen <?= $fmt0($korunan) ?> günlük kayıt korundu</span><?php endif; ?>
    <div class="small mt-1">
        <a href="index.php" class="alert-link">Dashboard</a> ·
        <a href="kalemler.php?k=demirbas" class="alert-link">Demirbaşlar</a> ·
        <a href="kalemler.php?k=sarf" class="alert-link">Sarf</a> ·
        <a href="hareketler.php" class="alert-link">Hareketler</a>
    </div>
</div>

<?php foreach ($raporlar as $r): ?>
<div class="card mb-2 <?= $r['hata'] ? 'border-danger' : '' ?>">
    <div class="card-header py-2 small d-flex align-items-center gap-2">
        <i class="bi bi-file-earmark-excel <?= $r['hata'] ? 'text-danger' : 'text-success' ?>"></i>
        <strong class="text-truncate"><?= h($r['ad']) ?></strong>
        <?php if ($r['sonuc']): ?><span class="text-muted ms-auto text-nowrap"><?= h(implode(' · ', $r['sonuc']['bulunan'])) ?></span><?php endif; ?>
    </div>
    <div class="card-body py-2 small">
        <?php if ($r['hata']): ?>
            <div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= h($r['hata']) ?></div>
        <?php else: ?>
            <?php foreach ($r['sonuc']['saglamalar'] as [$sd, $sm]): ?>
                <div><?= $ikon[$sd] ?> <?= h($sm) ?></div>
            <?php endforeach; ?>
            <?php foreach ($r['sonuc']['kontrol'] as [$sv, $sm, $detay]): ?>
                <?php if ($detay): ?>
                    <details class="mb-1"><summary style="cursor:pointer"><?= $ikon[$sv] ?> <?= h($sm) ?> <span class="text-muted">(satırları göster)</span></summary>
                        <ul class="mb-1 mt-1 text-muted" style="font-size:.8rem"><?php foreach ($detay as $dd): ?><li><?= h($dd) ?></li><?php endforeach; ?></ul>
                    </details>
                <?php else: ?>
                    <div><?= $ikon[$sv] ?> <?= h($sm) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="dpImportForm">
        <div class="col-md-9">
            <label class="form-label small">Depo Excel dosyaları (.xlsx) — <strong>4 dosyayı birden seçebilirsiniz</strong></label>
            <input type="file" name="dosya[]" id="dpDosya" class="form-control form-control-sm" accept=".xlsx" multiple required>
            <div id="dpSecilen" class="form-text"></div>
        </div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100" id="dpBtn"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Eşitle</button></div>
    </form>
</div></div>

<div class="card"><div class="card-body small">
    <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Bölümler ve son yükleme</div>
    <div class="table-responsive">
    <table class="table table-sm table-bordered mb-2" style="max-width:980px">
        <thead class="table-light"><tr><th>Excel sayfası</th><th>Dosya</th><th>Nereye yazılır</th><th>Son yükleme</th></tr></thead>
        <tbody>
        <?php foreach (DP_BOLUM as $b => [$sayfa, $hedef, $dosyaTip]): $s = $sonYukleme[$b] ?? null; ?>
            <tr class="<?= isset($buYukleme[$b]) ? 'table-success' : '' ?>">
                <td><?= h($sayfa) ?></td>
                <td class="text-muted"><?= h($dosyaTip) ?></td>
                <td><?= h($hedef) ?></td>
                <td>
                    <?php if ($s): ?>
                        <?= isset($buYukleme[$b]) ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '' ?>
                        <?= h(date('d.m.Y H:i', strtotime($s['created']))) ?> · <?= $fmt0($s['satir']) ?> satır
                        <span class="text-muted">· <?= h($s['dosya'] ?? '') ?><?= $s['kullanici'] ? ' · ' . h($s['kullanici']) : '' ?></span>
                    <?php else: ?><span class="text-muted">— henüz yüklenmedi / kayıt yok</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="text-muted">
        Sayfa adı <strong>içerir</strong> mantığıyla eşleşir — "KARTAL-BATIYAKASI SARF MALZEME" de bulunur.
        Sütunlar başlık metninden okunur, sabit sıra beklenmez. Her dosya <strong>yalnız kendi bölümlerini</strong> tam yeniler;
        dosyada bulunmayan bölümlere dokunulmaz. Elle girilen günlük hareketler korunur.
    </div>
    <div class="alert alert-info small mt-2 mb-0">
        <i class="bi bi-collection me-1"></i><strong>4 dosyayı tek seferde yükleyin:</strong>
        <em>Demirbaş Takip</em> · <em>Sarf Malzeme Stok</em> (sarf + el aletleri) · <em>Malzeme Takip</em> (depo giriş/çıkış) ·
        <em>Sarf Taşeron Malzeme Teslimat</em> (taşeron giriş/teslimat). Dosya seçme penceresinde Ctrl/⌘ ile hepsini işaretleyin.
        Sıra fark etmez; her dosya kendi başına işlenir, biri hatalıysa diğerleri yine aktarılır.
        Aktarım sonunda her dosya için <strong>veri doğrulama raporu</strong> çıkar: Excel özet hücreleri (kalem sayısı / mali değer),
        STOK = SAYIM+GELEN−GİDEN sağlaması, eksi stok, mükerrer kart, okunamayan miktar/tarih, çift girilmiş hareket satırları.
    </div>
</div></div>

<script>
(function(){
    var inp = document.getElementById('dpDosya'), out = document.getElementById('dpSecilen'), btn = document.getElementById('dpBtn');
    if (!inp) return;
    inp.addEventListener('change', function(){
        var f = inp.files, n = [];
        for (var i = 0; i < f.length; i++) n.push(f[i].name + ' (' + Math.round(f[i].size / 1024) + ' KB)');
        out.textContent = f.length ? f.length + ' dosya seçildi: ' + n.join(' · ') : '';
    });
    document.getElementById('dpImportForm').addEventListener('submit', function(){
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aktarılıyor… (' + inp.files.length + ' dosya)';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
