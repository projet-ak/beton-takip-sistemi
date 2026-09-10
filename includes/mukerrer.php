<?php
/**
 * includes/mukerrer.php — TÜM MODÜLLER için mükerrer kayıt tespiti ve BİRLEŞTİRME çekirdeği
 *
 * Sorun: aynı tedarikçi/firma/personel/araç iki kez açılınca raporlar bölünür, bakiyeler
 * yanlış çıkar. Her modülde ayrı ayrı arama yerine **tek kayıt defteri** (`MK_KURAL`) tutulur:
 * hangi tabloda hangi alanlar aynıysa o satırlar aynı varlıktır, birleştirmede hangi bağlar
 * (FK'ler) hedefe taşınır.
 *
 * Tespit: her kural için satırlar birden çok "anahtar" (ör. Ad · VKN) üzerinden normalize
 * edilip birleşim-bul (union-find) ile gruplanır — A ile B adından, B ile C VKN'den eşleşirse
 * üçü TEK grup olur. Normalizasyon `mk_norm()` (Türkçe harf duyarsız, noktalama/boşluk atılır).
 *
 * Birleştirme: ASIL kayıt korunur (en dolu kart), diğerlerinin **bağlı kayıtları hedefe taşınır**,
 * hedefte BOŞ olan alanlar kaynaklardan tamamlanır, kaynak satırlar silinir — hepsi tek
 * transaction'da, `audit_log`'a yazılarak. Bağı taşınamayan (tabloya erişilemeyen) durumda
 * işlem geri alınır; veri kaybı olmaz.
 *
 * ⚠ Hareket/belge tabloları (irsaliye, arıza, iş satırı…) `birlestir=false` ile YALNIZ RAPORLANIR;
 * onların temizliği kendi ekranlarında yapılır (beton: veri_kontrol.php).
 */

/** Türkçe harf duyarsız normalize: "Şafi Beton A.Ş." → "SAFIBETONAS" */
function mk_norm(?string $s): string
{
    $s = trim((string)$s);
    if ($s === '') return '';
    $s = strtr($s, ['İ'=>'I','ı'=>'I','i'=>'I','I'=>'I','Ş'=>'S','ş'=>'S','Ğ'=>'G','ğ'=>'G',
                    'Ü'=>'U','ü'=>'U','Ö'=>'O','ö'=>'O','Ç'=>'C','ç'=>'C','Â'=>'A','â'=>'A']);
    $s = mb_strtoupper($s, 'UTF-8');
    return preg_replace('/[^A-Z0-9]/u', '', $s) ?? '';
}

/**
 * Kayıt defteri: modül => kural anahtarı => tanım.
 *   ad         : ekranda görünen başlık
 *   tablo      : taranacak tablo (SABİT — asla kullanıcı girdisi değil)
 *   etiket     : satırı tanıtan kolonlar (ekranda birleşik gösterilir)
 *   anahtar    : [[başlık, [kolonlar]], …] — kolonlar birleşip tek anahtar olur (AND),
 *                farklı anahtarlar ise ALTERNATİFTİR (OR) ve grupları birleştirir
 *   bagli      : [[tablo, kolon], …] — birleştirmede kaynak id'leri hedefe çevrilecek FK'ler
 *   birlestir  : false ise yalnız rapor (hareket/belge tabloları)
 *   ozel       : birleştirmeyi devralan fonksiyon adı (ör. personel: cihaz zimmetlerini de taşır)
 *   not        : ekranda gösterilecek açıklama
 */
const MK_KURAL = [
    'beton' => [
        'tedarikciler'     => ['ad'=>'Tedarikçiler', 'tablo'=>'tedarikciler', 'etiket'=>['ad','vkn'],
                               'anahtar'=>[['Ad',['ad']], ['VKN',['vkn']]],
                               'bagli'=>[['irsaliyeler','tedarikci_id'], ['faturalar','tedarikci_id']]],
        'firmalar'         => ['ad'=>'Firmalar (döküm yapan)', 'tablo'=>'firmalar', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]], 'bagli'=>[['irsaliyeler','firma_id']]],
        'projeler'         => ['ad'=>'Projeler', 'tablo'=>'projeler', 'etiket'=>['kod','aciklama'],
                               'anahtar'=>[['Kod',['kod']], ['Açıklama',['aciklama']]],
                               'bagli'=>[['irsaliyeler','proje_id'], ['parseller','proje_id']]],
        'parseller'        => ['ad'=>'Parseller', 'tablo'=>'parseller', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]],
                               'bagli'=>[['irsaliyeler','parsel_id'], ['bloklar','parsel_id']]],
        'bloklar'          => ['ad'=>'Bloklar', 'tablo'=>'bloklar', 'etiket'=>['ad'],
                               'anahtar'=>[['Parsel + Ad',['parsel_id','ad']]],
                               'bagli'=>[['irsaliyeler','blok_id'], ['kotlar','blok_id']]],
        'kotlar'           => ['ad'=>'Kotlar', 'tablo'=>'kotlar', 'etiket'=>['kot_degeri','aciklama'],
                               'anahtar'=>[['Blok + Kot',['blok_id','kot_degeri']]],
                               'bagli'=>[['irsaliyeler','kot_id']]],
        'beton_siniflari'  => ['ad'=>'Beton Sınıfları', 'tablo'=>'beton_siniflari', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]], 'bagli'=>[['irsaliyeler','beton_sinifi_id']]],
        'kivam_siniflari'  => ['ad'=>'Kıvam Sınıfları', 'tablo'=>'kivam_siniflari', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]], 'bagli'=>[['irsaliyeler','kivam_sinifi_id']]],
        'pompa_turleri'    => ['ad'=>'Pompa Türleri', 'tablo'=>'pompa_turleri', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]], 'bagli'=>[['irsaliyeler','pompa_id']]],
        'katki_listesi'    => ['ad'=>'Katkılar', 'tablo'=>'katki_listesi', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]], 'bagli'=>[]],
        'imalat_gruplari'  => ['ad'=>'İmalat Grupları', 'tablo'=>'imalat_gruplari', 'etiket'=>['ad'],
                               'anahtar'=>[['Ad',['ad']]],
                               'bagli'=>[['irsaliyeler','imalat_grup_id'], ['ana_is_kalemleri','imalat_grup_id']]],
        'ana_is_kalemleri' => ['ad'=>'Ana İş Kalemleri', 'tablo'=>'ana_is_kalemleri', 'etiket'=>['ad'],
                               'anahtar'=>[['Grup + Ad',['imalat_grup_id','ad']]],
                               'bagli'=>[['irsaliyeler','ana_is_kalemi_id']]],
        'irsaliyeler'      => ['ad'=>'İrsaliyeler (aynı no)', 'tablo'=>'irsaliyeler', 'etiket'=>['irsaliye_no','tarih','miktar'],
                               'anahtar'=>[['İrsaliye No',['irsaliye_no']]], 'bagli'=>[], 'birlestir'=>false,
                               'not'=>'Mükerrer irsaliyeler <a href="veri_kontrol.php">Veri Kontrol</a> ekranından temizlenir (en eski kayıt korunur).'],
        'faturalar'        => ['ad'=>'Faturalar (aynı no / ETTN)', 'tablo'=>'faturalar', 'etiket'=>['fatura_no','tarih','tutar'],
                               'anahtar'=>[['Fatura No',['fatura_no']], ['ETTN',['ettn']]], 'bagli'=>[], 'birlestir'=>false,
                               'not'=>'Fatura eşleştirme ekranı aynı no/ETTN\'yi zaten günceller; buradakiler eski kayıtlardır.'],
        'users'            => ['ad'=>'Kullanıcılar', 'tablo'=>'users', 'etiket'=>['username','full_name'],
                               'anahtar'=>[['Kullanıcı adı',['username']]], 'bagli'=>[], 'birlestir'=>false,
                               'not'=>'Kullanıcı adı benzersizdir; çıkan grup varsa <a href="kullanicilar.php">Kullanıcılar</a> ekranından düzeltin.'],
    ],
    'demir' => [
        'demir_tedarikciler' => ['ad'=>'Tedarikçiler', 'tablo'=>'demir_tedarikciler', 'etiket'=>['ad','vkn'],
                                 'anahtar'=>[['Ad',['ad']], ['VKN',['vkn']]],
                                 'bagli'=>[['demir_sevkiyatlar','tedarikci_id'], ['demir_faturalar','tedarikci_id']]],
        'demir_taseronlar'   => ['ad'=>'Taşeronlar', 'tablo'=>'demir_taseronlar', 'etiket'=>['ad','kod'],
                                 'anahtar'=>[['Ad',['ad']], ['Kod',['kod']], ['VKN',['vkn']]],
                                 'bagli'=>[['demir_sevkiyatlar','taseron_id'], ['demir_siparisler','taseron_id'],
                                           ['demir_tutanaklar','taseron_id'], ['demir_hurda','taseron_id'],
                                           ['demir_sozlesmeler','taseron_id'],
                                           ['demir_iade_tutanaklar','iade_eden_id'], ['demir_iade_tutanaklar','teslim_alan_id']]],
        'demir_projeler'     => ['ad'=>'Projeler', 'tablo'=>'demir_projeler', 'etiket'=>['kod','aciklama'],
                                 'anahtar'=>[['Kod',['kod']], ['Açıklama',['aciklama']]],
                                 'bagli'=>[['demir_sevkiyatlar','proje_id'], ['demir_siparisler','proje_id'],
                                           ['demir_tutanaklar','proje_id'], ['demir_iade_tutanaklar','proje_id'],
                                           ['demir_sozlesmeler','proje_id']]],
        'demir_caplar'       => ['ad'=>'Çaplar', 'tablo'=>'demir_caplar', 'etiket'=>['ad','tip'],
                                 'anahtar'=>[['Ad + Tip',['ad','tip']]],
                                 'bagli'=>[['demir_sevkiyat_kalemleri','cap_id'], ['demir_siparis_kalemleri','cap_id'],
                                           ['demir_tutanak_kalemleri','cap_id'], ['demir_iade_kalemleri','cap_id'],
                                           ['demir_talep_kalemleri','cap_id'], ['demir_metraj','cap_id']]],
        'demir_sozlesmeler'  => ['ad'=>'Sözleşmeler', 'tablo'=>'demir_sozlesmeler', 'etiket'=>['sozlesme_no','konu'],
                                 'anahtar'=>[['Sözleşme No',['sozlesme_no']]],
                                 'bagli'=>[['demir_tutanaklar','sozlesme_id'], ['demir_siparisler','sozlesme_id']]],
        'demir_siparisler'   => ['ad'=>'Siparişler (aynı IFS no)', 'tablo'=>'demir_siparisler', 'etiket'=>['ifs_siparis_no','tarih'],
                                 'anahtar'=>[['IFS Sipariş No',['ifs_siparis_no']]], 'bagli'=>[], 'birlestir'=>false,
                                 'not'=>'IFS sipariş no benzersiz olmalıdır — mükerrer kayıt bakiyeyi ÇİFT sayar, birini silin.'],
        'demir_sevkiyatlar'  => ['ad'=>'Sevkiyatlar (aynı irsaliye no)', 'tablo'=>'demir_sevkiyatlar', 'etiket'=>['irsaliye_no','tarih'],
                                 'anahtar'=>[['İrsaliye No',['irsaliye_no']]], 'bagli'=>[], 'birlestir'=>false],
        'demir_tutanaklar'   => ['ad'=>'Tutanaklar (aynı no)', 'tablo'=>'demir_tutanaklar', 'etiket'=>['tutanak_no','tarih'],
                                 'anahtar'=>[['Tutanak No',['tutanak_no']]], 'bagli'=>[], 'birlestir'=>false],
    ],
    'seramik' => [
        'seramik_malzemeler' => ['ad'=>'Malzemeler', 'tablo'=>'seramik_malzemeler', 'etiket'=>['ad','tur','birim'],
                                 'anahtar'=>[['Ad',['ad']]],
                                 'bagli'=>[['seramik_giris','malzeme_id'], ['seramik_cikis','malzeme_id'],
                                           ['seramik_sayim','malzeme_id'], ['seramik_palet','malzeme_id'],
                                           ['seramik_metraj','malzeme_id']]],
        'seramik_firmalar'   => ['ad'=>'Firmalar', 'tablo'=>'seramik_firmalar', 'etiket'=>['ad'],
                                 'anahtar'=>[['Ad',['ad']]], 'bagli'=>[['seramik_giris','firma_id']]],
        'seramik_taseronlar' => ['ad'=>'Taşeronlar', 'tablo'=>'seramik_taseronlar', 'etiket'=>['ad','kod'],
                                 'anahtar'=>[['Ad',['ad']], ['Kod',['kod']]], 'bagli'=>[['seramik_cikis','taseron_id']]],
    ],
    'depo' => [
        'depo_kalemler' => ['ad'=>'Stok kalemleri (aynı ad + özellik)', 'tablo'=>'depo_kalemler',
                            'etiket'=>['ad','ozellik','birim'],
                            'anahtar'=>[['Kategori + Ad + Özellik',['kategori','ad','ozellik']]],
                            'bagli'=>[['depo_hareketler','kalem_id']], 'birlestir'=>false,
                            'not'=>'Depo stoğu Excel\'den <strong>tam yenileme</strong> ile gelir; mükerrer kart genelde Excel\'de iki satır demektir — düzeltme Excel tarafında yapılmalı, aksi halde bir sonraki yüklemede geri gelir.'],
    ],
    'akaryakit' => [
        'akaryakit_araclar' => ['ad'=>'Araç / makineler', 'tablo'=>'akaryakit_araclar', 'etiket'=>['sofor','cinsi','plaka'],
                                'anahtar'=>[['Şoför + Cinsi',['anahtar']], ['Plaka',['plaka']]],
                                'bagli'=>[['akaryakit_tuketim','arac_id'], ['akaryakit_cikislar','arac_id'],
                                          ['akaryakit_tutanak','arac_id']]],
    ],
    'crm' => [
        'crm_arizalar' => ['ad'=>'Arızalar (aynı kimlik)', 'tablo'=>'crm_arizalar',
                           'etiket'=>['konut','sikayet_konusu','olusturma'],
                           'anahtar'=>[['Kayıt anahtarı',['kayit_anahtari']]], 'bagli'=>[], 'birlestir'=>false,
                           'not'=>'Kimlik içerikten üretilir ve UNIQUE\'tir; grup çıkarsa şema eski demektir.'],
    ],
    'prekast' => [
        'prekast_isler' => ['ad'=>'İş satırları (aynı kimlik)', 'tablo'=>'prekast_isler',
                            'etiket'=>['cizelge','blok','daire'],
                            'anahtar'=>[['Kayıt anahtarı',['kayit_anahtari']]], 'bagli'=>[], 'birlestir'=>false],
    ],
    'it' => [
        'it_personel'   => ['ad'=>'Personel', 'tablo'=>'it_personel', 'etiket'=>['sicil_no','ad','soyad'],
                            'anahtar'=>[['Sicil No',['sicil_no']], ['E-posta',['eposta']], ['Ad + Soyad',['ad','soyad']]],
                            'bagli'=>[['it_cihazlar','personel_id']], 'ozel'=>'mk_personel_birlestir'],
        'it_cihazlar'   => ['ad'=>'Cihazlar', 'tablo'=>'it_cihazlar', 'etiket'=>['envanter_no','ad','seri_no'],
                            'anahtar'=>[['Envanter No',['envanter_no']], ['Seri No',['seri_no']],
                                        ['IFS Seri Nesne No',['varlik_kodu']], ['Cihaz Kodu',['cihaz_kodu']],
                                        ['MAC Adresi',['mac_adresi']]],
                            'bagli'=>[['it_hareketler','cihaz_id'], ['it_belgeler','cihaz_id']]],
        'it_lokasyonlar'=> ['ad'=>'Lokasyonlar', 'tablo'=>'it_lokasyonlar', 'etiket'=>['kod','ad'],
                            'anahtar'=>[['Üst + Ad',['ust_id','ad']], ['Proje Kodu',['kod']]],
                            'bagli'=>[['it_cihazlar','lokasyon_id'], ['it_personel','lokasyon_id'],
                                      ['it_lokasyonlar','ust_id']]],
        'it_tanimlar'   => ['ad'=>'Tanımlar (üretici / model / tedarikçi)', 'tablo'=>'it_tanimlar', 'etiket'=>['tur','ad'],
                            'anahtar'=>[['Tür + Ad',['tur','ad']]], 'bagli'=>[]],
    ],
];

/** Modülün PDO bağlantısı (istek başına önbellekli). Bağlanamazsa null. */
function mk_pdo(string $modul): ?PDO
{
    static $cache = [];
    if (array_key_exists($modul, $cache)) return $cache[$modul];
    $kok = dirname(__DIR__);
    $dosya = ['beton'=>'db.php', 'demir'=>'db_demir.php', 'seramik'=>'db_seramik.php', 'depo'=>'db_depo.php',
              'akaryakit'=>'db_akaryakit.php', 'crm'=>'db_crm.php', 'prekast'=>'db_prekast.php', 'it'=>'db_it.php'][$modul] ?? null;
    $degisken = ['beton'=>'pdo', 'demir'=>'pdoDemir', 'seramik'=>'pdoSeramik', 'depo'=>'pdoDepo',
                 'akaryakit'=>'pdoAkaryakit', 'crm'=>'pdoCrm', 'prekast'=>'pdoPrekast', 'it'=>'pdoIt'][$modul] ?? null;
    $cache[$modul] = null;
    // Sayfa bağlantıyı zaten kurduysa onu kullan — db*.php'yi yeniden yüklemek gereksiz
    // (config.php yoksa db.php login/install'a YÖNLENDİRİP çıkar; buradan tetiklenmemeli).
    if ($degisken && isset($GLOBALS[$degisken]) && $GLOBALS[$degisken] instanceof PDO) { $cache[$modul] = $GLOBALS[$degisken]; return $cache[$modul]; }
    if (!$dosya || !file_exists($kok . '/includes/' . $dosya) || !file_exists($kok . '/config.php')) return null;
    try {
        require_once $kok . '/includes/' . $dosya;
        if (isset($GLOBALS[$degisken]) && $GLOBALS[$degisken] instanceof PDO) $cache[$modul] = $GLOBALS[$degisken];
    } catch (Throwable $e) { /* modül kurulu değil */ }
    return $cache[$modul];
}

/** Kuralın normalize edilmiş halini döndürür (varsayılanlar dolu). */
function mk_kural(string $modul, string $anahtar): ?array
{
    $k = MK_KURAL[$modul][$anahtar] ?? null;
    if (!$k) return null;
    return $k + ['bagli'=>[], 'birlestir'=>true, 'ozel'=>null, 'not'=>'', 'etiket'=>['ad'], 'modul'=>$modul, 'kod'=>$anahtar];
}

/**
 * Bir kuraldaki mükerrer grupları bulur.
 * Dönüş: [['anahtarlar'=>['Ad'=>'…'], 'kayitlar'=>[satır,…], 'asil'=>id], …]
 * Hata (tablo yok / DB kapalı) durumunda boş dizi döner — çağıran `mk_hata()` ile öğrenir.
 */
function mk_gruplar(PDO $pdo, array $k, int $limit = 200): array
{
    // Yalnız gereken kolonlar okunur — irsaliyeler gibi büyük tablolarda SELECT * belleği şişirir
    $kolonlar = ['id'];
    foreach ($k['anahtar'] as [$_, $kk]) foreach ($kk as $c) $kolonlar[] = $c;
    foreach ($k['etiket'] as $c) $kolonlar[] = $c;
    $kolonlar = array_values(array_unique(array_filter($kolonlar, fn($c) => preg_match('/^[a-z_][a-z0-9_]*$/', $c))));
    try { $rows = $pdo->query("SELECT " . implode(',', $kolonlar) . " FROM {$k['tablo']}")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { $rows = $pdo->query("SELECT * FROM {$k['tablo']}")->fetchAll(PDO::FETCH_ASSOC); }
    if (count($rows) < 2) return [];

    $ust = [];                                    // birleşim-bul
    $bul = function ($x) use (&$ust, &$bul) { while (($ust[$x] ?? $x) !== $x) $x = $ust[$x]; return $x; };
    $birlestir = function ($a, $b) use (&$ust, $bul) { $a = $bul($a); $b = $bul($b); if ($a !== $b) $ust[$b] = $a; };

    $indeks = []; $esles = [];                    // id => [anahtar başlığı => değer]
    foreach ($k['anahtar'] as [$baslik, $kolonlar]) {
        $harita = [];
        foreach ($rows as $r) {
            $parca = [];
            foreach ($kolonlar as $c) {
                if (!array_key_exists($c, $r)) { $parca = []; break; }
                $v = mk_norm((string)($r[$c] ?? ''));
                if ($v === '') { $parca = []; break; }          // anahtarın bir parçası boşsa eşleşme yok
                $parca[] = $v;
            }
            if (!$parca) continue;
            $harita[implode('|', $parca)][] = (int)$r['id'];
            $esles[(int)$r['id']][$baslik] = implode(' · ', array_map(fn($c) => (string)($r[$c] ?? ''), $kolonlar));
        }
        foreach ($harita as $deger => $idler) {
            if (count($idler) < 2) continue;
            for ($i = 1; $i < count($idler); $i++) $birlestir($idler[0], $idler[$i]);
            foreach ($idler as $id) $indeks[$id] = true;
        }
    }
    if (!$indeks) return [];

    $satir = []; foreach ($rows as $r) $satir[(int)$r['id']] = $r;
    $gruplar = [];
    foreach (array_keys($indeks) as $id) $gruplar[$bul($id)][] = $id;

    $sonuc = [];
    foreach ($gruplar as $idler) {
        if (count($idler) < 2) continue;
        sort($idler);
        $kayitlar = array_map(fn($i) => $satir[$i], $idler);
        $sonuc[] = ['kayitlar'=>$kayitlar, 'asil'=>mk_asil($kayitlar, $k),
                    'anahtarlar'=>$esles[$idler[0]] ?? []];
        if (count($sonuc) >= $limit) break;
    }
    return $sonuc;
}

/** Grup içindeki ASIL (korunacak) kayıt: en dolu kart; eşitlikte en eski id. */
function mk_asil(array $kayitlar, array $k): int
{
    $enIyi = null; $enPuan = -1;
    foreach ($kayitlar as $r) {
        $puan = 0;
        foreach ($r as $kol => $v) {
            if ($kol === 'id') continue;
            if ($v !== null && trim((string)$v) !== '' && (string)$v !== '0') $puan += 2;
        }
        foreach ($k['anahtar'] as [$_, $kolonlar]) foreach ($kolonlar as $c)
            if (trim((string)($r[$c] ?? '')) !== '') $puan += 5;     // anahtar alanı dolu olan ağır basar
        // Eşitlikte daha AÇIKLAYICI etiket kazansın ("Anmar Beton" > "ANMAR"), yoksa en eski kayıt
        $puan = $puan * 1000 + min(999, mb_strlen(mk_etiket($r, $k)));
        if ($puan > $enPuan) { $enPuan = $puan; $enIyi = (int)$r['id']; }
    }
    return (int)$enIyi;
}

/** Satır etiketi (ekranda gösterilecek kısa ad). */
function mk_etiket(array $r, array $k): string
{
    $p = [];
    foreach ($k['etiket'] as $c) { $v = trim((string)($r[$c] ?? '')); if ($v !== '') $p[] = $v; }
    return $p ? implode(' · ', $p) : ('#' . ($r['id'] ?? '?'));
}

/**
 * BİRLEŞTİRME: kaynak kayıtların bağları hedefe taşınır, hedefteki boş alanlar kaynaklardan
 * tamamlanır, kaynaklar silinir. Tek transaction — herhangi bir adım patlarsa hiçbir şey değişmez.
 * Dönüş: ['tasinan'=>['tablo.kolon'=>adet], 'tamamlanan'=>['alan'=>değer], 'silinen'=>n]
 */
function mk_birlestir(PDO $pdo, array $k, int $hedefId, array $kaynakIdler, ?int $uid = null): array
{
    $kaynakIdler = array_values(array_unique(array_map('intval', $kaynakIdler)));
    $kaynakIdler = array_values(array_filter($kaynakIdler, fn($i) => $i > 0 && $i !== $hedefId));
    if (!$kaynakIdler) throw new RuntimeException('Birleştirilecek kayıt seçilmedi.');
    if (empty($k['birlestir'])) throw new RuntimeException('Bu tabloda birleştirme kapalıdır (yalnız rapor).');

    if (!empty($k['ozel']) && function_exists($k['ozel'])) {
        $n = 0;
        foreach ($kaynakIdler as $kid) $n += (int)($k['ozel']($pdo, $hedefId, $kid) ? 1 : 0);
        return ['tasinan'=>[], 'tamamlanan'=>[], 'silinen'=>$n, 'ozel'=>true];
    }

    $st = $pdo->prepare("SELECT * FROM {$k['tablo']} WHERE id=?");
    $st->execute([$hedefId]);
    $hedef = $st->fetch(PDO::FETCH_ASSOC);
    if (!$hedef) throw new RuntimeException('Korunacak kayıt bulunamadı.');
    $kaynaklar = [];
    foreach ($kaynakIdler as $kid) { $st->execute([$kid]); if ($r = $st->fetch(PDO::FETCH_ASSOC)) $kaynaklar[] = $r; }
    if (!$kaynaklar) throw new RuntimeException('Birleştirilecek kayıt bulunamadı.');

    $rapor = ['tasinan'=>[], 'tamamlanan'=>[], 'silinen'=>0];
    $pdo->beginTransaction();
    try {
        // 1) Hedefte BOŞ olan alanlar kaynaklardan tamamlanır (dolu alan asla ezilmez)
        $set = []; $par = [];
        foreach ($hedef as $kol => $v) {
            if (in_array($kol, ['id','created_at','created','updated_at'], true)) continue;
            if ($v !== null && trim((string)$v) !== '') continue;
            foreach ($kaynaklar as $s) {
                $y = $s[$kol] ?? null;
                if ($y === null || trim((string)$y) === '') continue;
                $set[] = "$kol=?"; $par[] = $y; $rapor['tamamlanan'][$kol] = $y; break;
            }
        }
        if ($set) { $par[] = $hedefId; $pdo->prepare("UPDATE {$k['tablo']} SET " . implode(',', $set) . " WHERE id=?")->execute($par); }

        // 2) Bağlı kayıtlar hedefe taşınır
        $soru = implode(',', array_fill(0, count($kaynakIdler), '?'));
        foreach ($k['bagli'] as [$tablo, $kolon]) {
            try {
                $u = $pdo->prepare("UPDATE $tablo SET $kolon=? WHERE $kolon IN ($soru)");
                $u->execute([$hedefId, ...$kaynakIdler]);
                if ($u->rowCount()) $rapor['tasinan']["$tablo.$kolon"] = $u->rowCount();
            } catch (Throwable $e) {
                // Tablo yoksa (modül kurulmamış) atla; VARSA ve hata verdiyse işlemi durdur
                if (!mk_tablo_yok($pdo, $tablo)) throw new RuntimeException("\"$tablo.$kolon\" bağı taşınamadı: " . $e->getMessage());
            }
        }

        // 3) Kaynaklar silinir
        $d = $pdo->prepare("DELETE FROM {$k['tablo']} WHERE id IN ($soru)");
        $d->execute($kaynakIdler);
        $rapor['silinen'] = $d->rowCount();

        if ($pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Denetim kaydı ANA DB'ye yazılır (audit_log yalnız orada)
    try {
        $ana = mk_pdo('beton');
        if ($ana) audit_log($ana, $k['tablo'], $hedefId, 'MERGE',
            ['birlestirilen_idler' => $kaynakIdler, 'modul' => $k['modul'] ?? ''],
            ['tasinan' => $rapor['tasinan'], 'tamamlanan' => array_keys($rapor['tamamlanan'])], $uid);
    } catch (Throwable $e) {}
    return $rapor;
}

/** Tablo gerçekten yok mu (yoksa hata yutulur, varsa hata ciddiye alınır). */
function mk_tablo_yok(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM $tablo LIMIT 1"); return false; }
    catch (Throwable $e) { return true; }
}

/**
 * IT personeli için özel birleştirme (cihaz zimmetleri + notlar birleşir).
 * `it/_import.php` yüklüyse oradaki köklü sürüm kullanılır.
 */
function mk_personel_birlestir(PDO $pdo, int $hedefId, int $kaynakId): bool
{
    if (function_exists('pim_personel_birlestir')) return (bool)pim_personel_birlestir($pdo, $hedefId, $kaynakId);
    $k = mk_kural('it', 'it_personel');
    $k['ozel'] = null;
    mk_birlestir($pdo, $k, $hedefId, [$kaynakId]);
    return true;
}

/**
 * TÜM modüllerin özeti: her kural için mükerrer grup sayısı.
 * Dönüş: [modül => [kural => ['ad'=>…, 'grup'=>n, 'kayit'=>n, 'birlestir'=>bool, 'hata'=>?string]]]
 * Erişilemeyen modül/tablo sessizce 'hata' ile işaretlenir — bir modülün kapalı olması ekranı bozmaz.
 */
function mk_ozet(?array $modulller = null): array
{
    $sonuc = [];
    foreach (MK_KURAL as $modul => $kurallar) {
        if ($modulller !== null && !in_array($modul, $modulller, true)) continue;
        $pdo = mk_pdo($modul);
        foreach ($kurallar as $kod => $_) {
            $k = mk_kural($modul, $kod);
            $satir = ['ad'=>$k['ad'], 'grup'=>0, 'kayit'=>0, 'birlestir'=>(bool)$k['birlestir'], 'hata'=>null];
            if (!$pdo) { $satir['hata'] = 'modül bağlantısı yok'; $sonuc[$modul][$kod] = $satir; continue; }
            try {
                $g = mk_gruplar($pdo, $k);
                $satir['grup'] = count($g);
                foreach ($g as $x) $satir['kayit'] += count($x['kayitlar']);
            } catch (Throwable $e) { $satir['hata'] = 'tablo yok'; }
            $sonuc[$modul][$kod] = $satir;
        }
    }
    return $sonuc;
}
