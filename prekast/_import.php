<?php
/**
 * _import.php — Prekast "İŞ TAKİP / HAKKEDİŞ ÇİZELGESİ" Excel'inin içe aktarma çekirdeği
 *
 * Çizelge sabit bir iş listesidir; her gün aynı satırlar gelir, yalnız **durumlar dolar**
 * (Kesim → Silikon → Metraj → Hakkediş). Bu yüzden tam yenileme DEĞİL BİRLEŞTİRME yapılır:
 *   yeni satır → kayıt açılır · mevcut satır → güncellenir · dosyada olmayan satır → silinmez,
 *   `dosyada=0` ile işaretlenir (çizelgeden çıkarılmış olabilir, veri kaybolmaz).
 *
 * Bir satır ilk kez "Yapıldı" olduğunda o günün rapor tarihi damgalanır
 * (`kesim_tarih` / `silikon_tarih`) — ilerleme takvimi ve günlük trend buradan çıkar.
 * Her yükleme ayrıca `prekast_gunluk` tablosuna o günün anlık toplamını yazar.
 *
 * Excel'de ID kolonu yoktur; kimlik `pk_anahtar()` ile içerikten üretilir
 * (çizelge+blok+daire+tekrar) ve UNIQUE olduğundan aynı dosya defalarca yüklense de
 * mükerrer kayıt oluşmaz.
 */

use Shuchkin\SimpleXLSX;

/**
 * Alan → başlıkta aranacak metinler. **SIRA ÖNEMLİ**: bir sütun yalnız bir alana bağlanır.
 * "Kesim Yapılan Daire" ve "Silikon Yapılan Daire" de DAIRE içerdiğinden 'daire' EN SONA konur.
 */
const PK_ALAN = [
    'sira'        => ['NO'],
    'blok'        => ['BLOK'],
    'kesim'       => ['KESIM'],
    'silikon'     => ['SILIKON'],
    'metraj'      => ['METRAJ'],
    'birim_fiyat' => ['BIRIM FIYAT', 'B.FIYAT', 'BFIYAT'],
    'hakkedis'    => ['HAKKEDIS'],
    'daire'       => ['DAIRE'],
];

/** Çizelge sayfasını bulur: başlık satırında BLOK + DAIRE geçen ilk sayfa. */
function pk_sayfa(SimpleXLSX $x): ?int
{
    // İCMALLİ kitapta "İCMAL" (Blok / Kesim Yapılan Daire…) ve "HESAPLAMA" (Blok (Temiz) / Daire /
    // Kesim Metrajı…) sayfaları da BLOK+DAİRE içerir; iş listesini ayıran şey HAKKEDİŞ sütunudur.
    // Önce HAKKEDİŞ'li sayfa aranır (gizli olsa bile — kitapta Sayfa1 gizlenmiş durumda), yoksa eski kural.
    $aday = null;
    foreach ($x->sheetNames() as $i => $n) {
        foreach (array_slice($x->rows((int)$i, 20), 0, 20) as $row) {
            $u = pk_norm(implode(' ', array_map('strval', $row)));
            if (!str_contains($u, 'BLOK') || !str_contains($u, 'DAIRE')) continue;
            if (str_contains($u, 'HAKKEDIS')) return (int)$i;
            if ($aday === null && !str_contains($u, 'ICMAL') && !str_contains($u, 'TEMIZ')) $aday = (int)$i;
        }
    }
    return $aday;
}

/**
 * Kitaptaki "İCMAL" sayfasını okur (varsa): blok → [kesimDaire, kesimMt, silikonDaire, silikonMt, oran].
 * Excel'in kendi hesabıdır; sistem icmaliyle KARŞILAŞTIRMAK için alınır, veri olarak yazılmaz.
 */
function pk_excel_icmal(SimpleXLSX $x): ?array
{
    foreach ($x->sheetNames() as $i => $n) {
        if (!str_contains(pk_norm($n), 'ICMAL')) continue;
        $rows = $x->rows((int)$i, 200);
        $hr = -1; $h = [];
        foreach ($rows as $ri => $row) {
            $u = pk_norm(implode(' ', array_map('strval', $row)));
            if (str_contains($u, 'BLOK') && str_contains($u, 'KESIM')) { $hr = $ri; break; }
        }
        if ($hr < 0) return null;
        // Başlık → sütun (bir sütun tek alana): sıra önemli, "Silikon / Kesim" oranı en sona
        $alan = ['kesimDaire'=>'KESIM YAPILAN', 'kesimMt'=>'KESIM (MT)', 'silikonDaire'=>'SILIKON YAPILAN',
                 'silikonMt'=>'SILIKON (MT)', 'oran'=>'SILIKON / KESIM'];
        foreach ($rows[$hr] as $ci => $c) {
            $u = pk_norm((string)$c);
            foreach ($alan as $k => $ara) if (!isset($h[$k]) && $u !== '' && str_contains($u, $ara)) { $h[$k] = $ci; break; }
        }
        if (!isset($h['kesimDaire'], $h['silikonDaire'])) return null;
        $out = ['blok'=>[], 'toplam'=>null];
        for ($ri = $hr + 1; $ri < count($rows); $ri++) {
            $b = trim((string)($rows[$ri][0] ?? ''));
            if ($b === '') continue;
            $v = [];
            foreach ($h as $k => $ci) $v[$k] = pk_sayi($rows[$ri][$ci] ?? '');
            if (pk_norm($b) === 'TOPLAM') $out['toplam'] = $v; else $out['blok'][pk_norm($b)] = $v;
        }
        return $out;
    }
    return null;
}

/** Başlık satırının indeksi (BLOK + DAIRE geçen ilk satır). */
function pk_baslik_satiri(array $rows): int
{
    foreach (array_slice($rows, 0, 20, true) as $ri => $row) {
        $u = pk_norm(implode(' ', array_map('strval', $row)));
        if (str_contains($u, 'BLOK') && str_contains($u, 'DAIRE')) return (int)$ri;
    }
    return 0;
}

/** Başlık satırından alan→sütun haritası (bir sütun tek alana bağlanır). */
function pk_harita(array $baslik): array
{
    $h = []; $dolu = [];
    foreach (PK_ALAN as $alan => $adaylar) {
        foreach ($adaylar as $a) {
            $ara = pk_norm($a);
            foreach ($baslik as $ci => $c) {
                if (isset($dolu[(int)$ci])) continue;
                $u = pk_norm((string)$c);
                if ($u !== '' && str_contains($u, $ara)) { $h[$alan] = (int)$ci; $dolu[(int)$ci] = true; break 2; }
            }
        }
    }
    return $h;
}

/** Haritadan hücre değeri. */
function pk_al(array $row, array $h, string $alan): string
{
    return isset($h[$alan]) ? trim((string)($row[$h[$alan]] ?? '')) : '';
}

/** Başlık satırının üstündeki ilk dolu hücre = çizelge adı. */
function pk_cizelge_adi(array $rows, int $hr, string $sayfaAdi): string
{
    for ($i = 0; $i < $hr; $i++) {
        foreach ($rows[$i] ?? [] as $c) {
            $v = trim((string)$c);
            if ($v !== '') return preg_replace('/\s+/', ' ', $v);
        }
    }
    return trim($sayfaAdi);
}

/**
 * Çizelge adından iş tipini çıkarır ("… C ve B PARSEL **T PROFİL** MONTAJ İŞİ …" → "T PROFİL").
 * Bulamazsa boş döner — modül birden çok iş tipini (T profil, denizlik…) yan yana taşıyabilsin diye.
 */
function pk_is_tipi(string $cizelge): string
{
    // Kelime kelime yürünür: normalize edilmiş metin üzerinde aranır ama HAM kelimeler
    // döndürülür — aksi halde "T PROFİL" → "T PROFIL" olur (Türkçe harf kaybı).
    $ham  = preg_split('/\s+/u', trim($cizelge), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $norm = array_map('pk_norm', $ham);
    $son  = ['MONTAJ', 'ISI', 'IS', 'HAKKEDIS', 'CIZELGE', 'CIZELGESI'];
    $bas = -1;
    foreach ($norm as $i => $w) if ($w === 'PARSEL') { $bas = $i + 1; break; }
    if ($bas < 0) $bas = 0;
    $bit = count($ham);
    for ($i = $bas; $i < count($norm); $i++) if (in_array($norm[$i], $son, true)) { $bit = $i; break; }
    return $bit > $bas ? implode(' ', array_slice($ham, $bas, $bit - $bas)) : '';
}

/** Dosya adındaki tarihi rapor tarihi sayar (28.08.2026 / 2026-08-28 / 28-08-2026). */
function pk_dosya_tarihi(string $ad): ?string
{
    if (preg_match('/(20\d{2})[.\-_](\d{1,2})[.\-_](\d{1,2})/', $ad, $m))
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    if (preg_match('/(\d{1,2})[.\-_](\d{1,2})[.\-_](20\d{2})/', $ad, $m))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/**
 * Günlük çizelgeyi içe aktarır (tek transaction).
 *
 * @param array $opt rapor_tarihi (Y-m-d, varsayılan bugün) · dosya · kullanici
 * @return array okunan/satir/yeni/guncellenen/degismeyen/yeniKesim/yeniSilikon/dusen/
 *               atlanan[]/degisenler[]/uyari[]/kontrol[]/toplamMetraj/toplamHakkedis/cizelge/is_tipi
 * @throws Throwable hata durumunda geri alınır (hiçbir şey değişmez)
 */
function pk_import(PDO $pdo, SimpleXLSX $x, array $opt = []): array
{
    pk_semasi_kur($pdo);
    $rapor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($opt['rapor_tarihi'] ?? '')) ? $opt['rapor_tarihi'] : date('Y-m-d');
    $s = ['okunan'=>0, 'satir'=>0, 'yeni'=>0, 'guncellenen'=>0, 'degismeyen'=>0, 'sablon'=>0,
          'yeniKesim'=>0, 'yeniSilikon'=>0, 'dusen'=>0, 'atlanan'=>[], 'degisenler'=>[],
          'uyari'=>[], 'kontrol'=>[], 'toplamMetraj'=>0.0, 'toplamHakkedis'=>0.0,
          'cizelge'=>'', 'is_tipi'=>'', 'rapor_tarihi'=>$rapor];

    $si = pk_sayfa($x);
    if ($si === null) throw new RuntimeException('Dosyada çizelge sayfası bulunamadı (BLOK/DAİRE başlıkları yok). Sayfalar: ' . implode(', ', $x->sheetNames()) . '.');
    $rows = $x->rows($si, 20000);
    $hr   = pk_baslik_satiri($rows);
    $h    = pk_harita($rows[$hr] ?? []);
    foreach (['blok','daire','kesim'] as $zorunlu)
        if (!isset($h[$zorunlu])) throw new RuntimeException('Beklenen sütun bulunamadı: ' . $zorunlu . '. Çizelge biçimi değişmiş olabilir.');

    $adlar   = $x->sheetNames();
    $cizelge = pk_cizelge_adi($rows, $hr, (string)($adlar[$si] ?? 'Çizelge'));
    $isTipi  = pk_is_tipi($cizelge);
    $s['cizelge'] = $cizelge; $s['is_tipi'] = $isTipi;

    $bul = $pdo->prepare("SELECT id, kesim, silikon, kesim_tarih, silikon_tarih, metraj, hakkedis, durum
                          FROM prekast_isler WHERE kayit_anahtari=?");
    $ins = $pdo->prepare("INSERT INTO prekast_isler
            (kayit_anahtari, cizelge, is_tipi, sira, blok, daire, daire_sira, tekrar,
             kesim, kesim_metin, kesim_tarih, silikon, silikon_metin, silikon_tarih,
             metraj, birim_fiyat, hakkedis, durum, dosyada, ilk_gorulme, son_gorulme)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)");
    $upd = $pdo->prepare("UPDATE prekast_isler SET cizelge=?, is_tipi=?, sira=?, daire_sira=?,
             kesim=?, kesim_metin=?, kesim_tarih=?, silikon=?, silikon_metin=?, silikon_tarih=?,
             metraj=?, birim_fiyat=?, hakkedis=?, durum=?, dosyada=1, son_gorulme=? WHERE id=?");

    $pdo->beginTransaction();
    try {
        // Bu çizelgenin tüm satırları önce "dosyada değil" işaretlenir; aktarılanlar geri açılır.
        // Kalanlar = çizelgeden çıkarılmış satırlar (silinmez, rozetle gösterilir).
        $sifirla = $pdo->prepare("UPDATE prekast_isler SET dosyada=0 WHERE cizelge=?");
        $sifirla->execute([$cizelge]);

        $tekrarlar = [];    // blok|daire → kaçıncı
        $capraz    = [];    // hakkediş sağlaması için satır listesi
        for ($i = $hr + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $ham = trim(implode('', array_map('strval', $row)));
            if ($ham === '') continue;                      // tamamen boş satır

            $blok  = pk_al($row, $h, 'blok');
            $daire = pk_al($row, $h, 'daire');
            if ($blok === '' && $daire === '') {
                // Çizelgenin altındaki boş şablon satırları (yalnız No + birim fiyat dolu, iş yok):
                // yüzlercesi olduğundan atlanan listesini şişirmez, tek sayaçta toplanır.
                $s['sablon']++;
                continue;
            }
            $s['okunan']++;
            if ($blok === '' || $daire === '') {
                $s['atlanan'][] = ['satir'=>$i+1, 'sebep'=>($blok === '' ? 'Blok boş' : 'Daire boş') . ' — eşleştirilemez',
                                   'ozet'=>trim($blok . ' / ' . $daire)];
                continue;
            }

            $ka     = pk_norm($blok) . '|' . pk_norm($daire);
            $tekrar = $tekrarlar[$ka] = ($tekrarlar[$ka] ?? 0) + 1;
            $anahtar = pk_anahtar($cizelge, $blok, $daire, $tekrar);

            $kesimM   = pk_al($row, $h, 'kesim');
            $silikonM = pk_al($row, $h, 'silikon');
            $kesim    = pk_yapildi($kesimM);
            $silikon  = pk_yapildi($silikonM);
            $metraj   = pk_sayi(pk_al($row, $h, 'metraj'));
            $bf       = pk_sayi(pk_al($row, $h, 'birim_fiyat'));
            $hak      = pk_sayi(pk_al($row, $h, 'hakkedis'));
            $sira     = (int)pk_sayi(pk_al($row, $h, 'sira'));
            $daireSira = (int)preg_replace('/\D+/', '', $daire);
            $durum    = pk_durum($kesim, $silikon);

            $s['satir']++;
            $s['toplamMetraj']   += $metraj;
            $s['toplamHakkedis'] += $hak;
            $capraz[] = ['satir'=>$i+1, 'blok'=>$blok, 'daire'=>$daire,
                         'metraj'=>$metraj, 'bf'=>$bf, 'hak'=>$hak, 'silikon'=>$silikon];

            $bul->execute([$anahtar]);
            $m = $bul->fetch();
            if ($m) {
                // Durum damgaları: ilk kez "Yapıldı" görüldüğü rapor günü kalıcı olur.
                $kt = $kesim   ? ($m['kesim_tarih']   ?: $rapor) : null;
                $st = $silikon ? ($m['silikon_tarih'] ?: $rapor) : null;
                if ($kesim   && !(int)$m['kesim'])   { $s['yeniKesim']++;   $s['degisenler'][] = ['tur'=>'kesim',   'blok'=>$blok, 'daire'=>$daire, 'metraj'=>$metraj, 'hakkedis'=>$hak]; }
                if ($silikon && !(int)$m['silikon']) { $s['yeniSilikon']++; $s['degisenler'][] = ['tur'=>'silikon', 'blok'=>$blok, 'daire'=>$daire, 'metraj'=>$metraj, 'hakkedis'=>$hak]; }
                $upd->execute([$cizelge, $isTipi, $sira ?: null, $daireSira,
                               (int)$kesim, $kesimM ?: null, $kt, (int)$silikon, $silikonM ?: null, $st,
                               $metraj, $bf, $hak, $durum, $rapor, (int)$m['id']]);
                $degisti = (int)$m['kesim'] !== (int)$kesim || (int)$m['silikon'] !== (int)$silikon
                        || abs((float)$m['metraj'] - $metraj) > 0.001 || abs((float)$m['hakkedis'] - $hak) > 0.001;
                if ($degisti) $s['guncellenen']++; else $s['degismeyen']++;
            } else {
                $ins->execute([$anahtar, $cizelge, $isTipi, $sira ?: null, $blok, $daire, $daireSira, $tekrar,
                               (int)$kesim, $kesimM ?: null, $kesim ? $rapor : null,
                               (int)$silikon, $silikonM ?: null, $silikon ? $rapor : null,
                               $metraj, $bf, $hak, $durum, $rapor, $rapor]);
                $s['yeni']++;
                if ($kesim)   $s['yeniKesim']++;
                if ($silikon) $s['yeniSilikon']++;
            }
        }

        if ($s['satir'] === 0) throw new RuntimeException('Dosyada iş satırı bulunamadı — yükleme iptal edildi.');

        $ds = $pdo->prepare("SELECT COUNT(*) FROM prekast_isler WHERE cizelge=? AND dosyada=0");
        $ds->execute([$cizelge]);
        $s['dusen'] = (int)$ds->fetchColumn();

        // Günün anlık fotoğrafı (trend grafiği bundan çizilir; aynı gün tekrar yüklenirse üzerine yazar)
        $ozet = $pdo->prepare("SELECT COUNT(*) satir, SUM(kesim=1) kesim, SUM(silikon=1) silikon,
                                      COALESCE(SUM(metraj),0) metraj, COALESCE(SUM(hakkedis),0) hakkedis
                               FROM prekast_isler WHERE cizelge=? AND dosyada=1");
        $ozet->execute([$cizelge]);
        $o = $ozet->fetch() ?: [];
        $pdo->prepare("INSERT INTO prekast_gunluk
                (rapor_tarihi, cizelge, satir, kesim, silikon, metraj, hakkedis, yeni_satir, yeni_kesim, yeni_silikon, dosya, kullanici)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE satir=VALUES(satir), kesim=VALUES(kesim), silikon=VALUES(silikon),
                    metraj=VALUES(metraj), hakkedis=VALUES(hakkedis),
                    yeni_satir=yeni_satir+VALUES(yeni_satir), yeni_kesim=yeni_kesim+VALUES(yeni_kesim),
                    yeni_silikon=yeni_silikon+VALUES(yeni_silikon), dosya=VALUES(dosya), kullanici=VALUES(kullanici)")
            ->execute([$rapor, $cizelge, (int)($o['satir'] ?? 0), (int)($o['kesim'] ?? 0), (int)($o['silikon'] ?? 0),
                       (float)($o['metraj'] ?? 0), (float)($o['hakkedis'] ?? 0),
                       $s['yeni'], $s['yeniKesim'], $s['yeniSilikon'],
                       (string)($opt['dosya'] ?? ''), $opt['kullanici'] ?? null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // ——— Veri doğrulama (Excel esas; sistem yalnız uyarır) ———
    $capr = []; $bfBos = []; $silikonsuzMetraj = []; $metrajsizSilikon = [];
    foreach ($capraz as $r) {
        if ($r['bf'] > 0 && abs($r['metraj'] * $r['bf'] - $r['hak']) > 0.5)
            $capr[] = 'Satır ' . $r['satir'] . ' (' . $r['blok'] . '/' . $r['daire'] . '): '
                    . number_format($r['metraj'], 2, ',', '.') . ' × ' . number_format($r['bf'], 2, ',', '.')
                    . ' = ' . number_format($r['metraj'] * $r['bf'], 2, ',', '.')
                    . ' ama çizelgede ' . number_format($r['hak'], 2, ',', '.');
        if ($r['bf'] <= 0)                              $bfBos[] = 'Satır ' . $r['satir'];
        if (!$r['silikon'] && $r['metraj'] > 0)         $silikonsuzMetraj[] = 'Satır ' . $r['satir'] . ' (' . $r['blok'] . '/' . $r['daire'] . ')';
        if ($r['silikon'] && $r['metraj'] <= 0)         $metrajsizSilikon[] = 'Satır ' . $r['satir'] . ' (' . $r['blok'] . '/' . $r['daire'] . ')';
    }
    $s['kontrol'][] = ['tip'=>$capr ? 'uyari' : 'ok',
                       'baslik'=>'Hakkediş sağlaması (metraj × birim fiyat)',
                       'mesaj'=>$capr ? count($capr) . ' satırda tutmuyor' : 'Tüm satırlar tutuyor',
                       'satirlar'=>$capr];
    if ($bfBos) $s['kontrol'][] = ['tip'=>'uyari', 'baslik'=>'Birim fiyat girilmemiş',
                                   'mesaj'=>count($bfBos) . ' satır', 'satirlar'=>$bfBos];
    if ($metrajsizSilikon) $s['kontrol'][] = ['tip'=>'uyari', 'baslik'=>'Silikon yapıldı ama metraj yok',
                                              'mesaj'=>count($metrajsizSilikon) . ' satır — hakkediş yazılmamış olabilir',
                                              'satirlar'=>$metrajsizSilikon];
    if ($silikonsuzMetraj) $s['kontrol'][] = ['tip'=>'bilgi', 'baslik'=>'Metraj var ama silikon işaretli değil',
                                              'mesaj'=>count($silikonsuzMetraj) . ' satır', 'satirlar'=>$silikonsuzMetraj];
    // ——— Excel İCMAL sayfası ↔ sistem icmali (aynı mantık: benzersiz daire + ortalama metraj) ———
    $xi = pk_excel_icmal($x);
    if ($xi) {
        $si2 = pk_icmal($pdo, $cizelge);
        $fark = []; $f2 = fn($n) => number_format((float)$n, 2, ',', '.');
        foreach ($xi['blok'] as $b => $e) {
            $g = $si2['blok'][$b] ?? null;
            if (!$g) { $fark[] = "Blok $b Excel icmalinde var, çizelgede yok (kesim " . (int)$e['kesimDaire'] . ' daire)'; continue; }
            if ((int)$e['kesimDaire'] !== $g['kesimDaire'])
                $fark[] = "Blok $b kesim daire: Excel " . (int)$e['kesimDaire'] . ' / sistem ' . $g['kesimDaire'];
            if ((int)$e['silikonDaire'] !== $g['silikonDaire'])
                $fark[] = "Blok $b silikon daire: Excel " . (int)$e['silikonDaire'] . ' / sistem ' . $g['silikonDaire'];
            if (isset($e['kesimMt']) && abs($e['kesimMt'] - $g['kesimMt']) > 0.05)
                $fark[] = "Blok $b kesim mt: Excel " . $f2($e['kesimMt']) . ' / sistem ' . $f2($g['kesimMt']);
        }
        foreach ($si2['blok'] as $b => $g) if (!isset($xi['blok'][$b]))
            $fark[] = "Blok $b çizelgede var, Excel icmalinde yok (kesim " . $g['kesimDaire'] . ' daire)';
        $s['excelIcmal'] = $xi;
        $s['kontrol'][] = ['tip'=>$fark ? 'uyari' : 'ok',
            'baslik'=>'Excel İCMAL sayfası ↔ sistem icmali',
            'mesaj'=>$fark ? count($fark) . ' farklılık — Excel\'in İCMAL/HESAPLAMA sayfası bayat olabilir (sayaç/metraj sütunları formül değil, elle yazılı); sistem icmali güncel iş satırlarından hesaplanır'
                           : 'Blok bazında daire sayıları ve kesim metrajı Excel ile tutuyor',
            'satirlar'=>$fark];
    }
    if ($s['dusen']) $s['uyari'][] = $s['dusen'] . ' kayıt bu çizelgede var ama dosyada yok — silinmedi, "çizelgede yok" olarak işaretlendi.';
    // Hesap tutmalı: okunan = satır + atlanan
    $fark = $s['okunan'] - ($s['satir'] + count($s['atlanan']));
    if ($fark !== 0) $s['uyari'][] = "Satır sayımı tutmuyor ($fark satır fark) — lütfen bildirin.";
    return $s;
}
