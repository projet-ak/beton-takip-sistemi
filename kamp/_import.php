<?php
/**
 * kamp/_import.php — Excel'den kamp kadrosu içe aktarma çekirdeği (kim_*)
 *
 * Bir satır = bir kişi (+ isteğe bağlı yeri ve tarihleri). Sütunlar başlık METNİNDEN bulunur
 * (KIM_ALAN eş anlamlıları, Türkçe harf duyarsız); ekranda elle değiştirilebilir.
 *  • Kişi eşleşmesi: TC no → sicil no → ad soyad (tek kayıtsa). Mevcut kişide yalnız BOŞ alanlar dolar.
 *  • Yer: bina (ad / kod; tek bina varsa boş bırakılabilir) → oda (ad) → yatak (no; yoksa odanın ilk boş yatağı).
 *  • Çıkış tarihi doluysa GEÇMİŞ konaklama olarak yazılır; boşsa kişi yerleştirilir (başka yerdeyse taşınır).
 *  • Tekrar yükleme mükerrer açmaz: aynı odada açık kaydı olan kişi "değişmeyen", aynı giriş tarihli geçmiş kayıt atlanır.
 */
require_once __DIR__ . '/_ortak.php';

const KIM_ALAN = [
    'ad_soyad' => ['Ad Soyad', ['ADI SOYADI','AD SOYAD','ADSOYAD','ISIM SOYISIM','ADI SOYADI UNVANI','PERSONEL','PERSONEL ADI','PERSONEL ADI SOYADI','CALISAN','ISIM']],
    'ad'       => ['Ad',       ['AD','ADI','ISMI','FIRST NAME']],
    'soyad'    => ['Soyad',    ['SOYAD','SOYADI','SOYISIM','LAST NAME']],
    'tc_no'    => ['TC No',    ['TC','TC NO','TCNO','TC KIMLIK','TC KIMLIK NO','T C KIMLIK NO','KIMLIK NO','TCKN']],
    'sicil_no' => ['Sicil No', ['SICIL','SICIL NO']],
    'firma'    => ['Firma',    ['FIRMA','FIRMA ADI','FIRMASI','TASERON','TASERON FIRMA','SIRKET','ALT YUKLENICI']],
    'gorev'    => ['Görev',    ['GOREV','GOREVI','UNVAN','UNVANI','MESLEK','MESLEGI','POZISYON']],
    'kategori' => ['Kategori', ['KATEGORI','STATU','PERSONEL TIPI','TIP']],
    'telefon'  => ['Telefon',  ['TELEFON','TEL','TELEFON NO','CEP','CEP TELEFONU','GSM']],
    'bina'     => ['Bina',     ['BINA','BLOK','BINA BLOK','KAMP','KAMP BLOK','YERLESKE']],
    'kat'      => ['Kat',      ['KAT']],
    'oda'      => ['Oda',      ['ODA','ODA NO','KONTEYNER','KONTEYNER NO','ODA KONTEYNER']],
    'yatak'    => ['Yatak',    ['YATAK','YATAK NO','RANZA','RANZA NO']],
    'giris'    => ['Giriş Tarihi', ['GIRIS','GIRIS TARIHI','KAMPA GIRIS','KAMPA GIRIS TARIHI','GELIS TARIHI','GELIS','BASLAMA TARIHI','ISE GIRIS']],
    'cikis'    => ['Çıkış Tarihi', ['CIKIS','CIKIS TARIHI','KAMPTAN CIKIS','AYRILIS','AYRILIS TARIHI','ISTEN CIKIS']],
    'sebep'    => ['Çıkış Sebebi', ['CIKIS SEBEBI','AYRILIS SEBEBI','SEBEP']],
    'notlar'   => ['Not',      ['NOT','NOTLAR','ACIKLAMA']],
];

function kim_excel_ac(string $yol): object
{
    if (!class_exists('Shuchkin\\SimpleXLSX')) require_once __DIR__ . '/../vendor/autoload.php';
    $magic = (string)file_get_contents($yol, false, null, 0, 4);
    if ($magic === "\xD0\xCF\x11\xE0") {
        $x = \Shuchkin\SimpleXLS::parseFile($yol);
        if (!$x) throw new RuntimeException('Eski Excel (.xls) okunamadı: ' . \Shuchkin\SimpleXLS::parseError());
        return $x;
    }
    $x = \Shuchkin\SimpleXLSX::parse($yol);
    if (!$x) throw new RuntimeException('Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError());
    return $x;
}

/** Sayfayı ızgara olarak okur (boş satırlar atılır; [excel_satir_no, hücreler]). */
function kim_grid(object $x, int $sayfa, int $limit = 5000): array
{
    $o = [];
    foreach ($x->rows($sayfa) as $i => $r) {
        if (count($o) >= $limit) break;
        $r = array_map(fn($c) => is_string($c) ? trim($c) : $c, $r);
        if (!array_filter($r, fn($c) => $c !== '' && $c !== null)) continue;
        $o[] = [$i + 1, $r];
    }
    return $o;
}

/** Başlık satırı: ilk 15 satırda en çok bilinen başlık taşıyan satır. */
function kim_baslik(array $grid): int
{
    $en = 0; $puanEn = -1;
    foreach (array_slice($grid, 0, 15) as $i => [, $r]) {
        $p = 0;
        foreach ($r as $c) if (kim_alan_bul((string)$c) !== null) $p++;
        if ($p > $puanEn) { $puanEn = $p; $en = $i; }
    }
    return $en;
}

function kim_alan_bul(string $baslik): ?string
{
    $n = kamp_norm($baslik);
    if ($n === '') return null;
    foreach (KIM_ALAN as $alan => [, $es]) if (in_array($n, $es, true)) return $alan;
    foreach (KIM_ALAN as $alan => [, $es]) foreach ($es as $e) if (strlen($e) >= 5 && str_contains($n, $e)) return $alan;
    return null;
}

/** Sütun → alan haritası (her alan tek sütuna bağlanır; ad+soyad ayrıysa ad_soyad atlanır). */
function kim_harita(array $baslik): array
{
    $h = []; $kullanilan = [];
    foreach ($baslik as $i => $b) {
        $a = kim_alan_bul((string)$b);
        if ($a && !isset($kullanilan[$a])) { $h[$i] = $a; $kullanilan[$a] = true; }
    }
    if (isset($kullanilan['ad'], $kullanilan['soyad'])) foreach ($h as $i => $a) if ($a === 'ad_soyad') unset($h[$i]);
    return $h;
}

function kim_kategori(string $kat, string $gorev): string
{
    $n = kamp_norm($kat . ' ' . $gorev);
    foreach (KAMP_KATEGORI as $k => [$ad]) if ($kat !== '' && kamp_norm($kat) === kamp_norm($ad)) return $k;
    // Kelime BAŞI eşleşir (Türkçe ek alır: "MÜHENDİSİ", "FORMENİ", "ŞEFİ")
    if (preg_match('/\b(FORMEN|USTABASI|USTA BASI|EKIPBASI|EKIP BASI)/', $n)) return 'formen';
    if (preg_match('/\b(MUHENDIS|MUH\b|MIMAR|STAFF|TEKNIK|SEF\b|SEFI\b|MUDUR|UZMAN|ISG\b|IDARI|OFIS)/', $n)) return 'staff';
    if (preg_match('/\b(MISAFIR|ZIYARETCI|DENETCI)/', $n)) return 'misafir';
    return 'isci';
}

/** Satırı alanlara çözer. */
function kim_satir(array $r, array $harita): array
{
    $v = [];
    foreach ($harita as $i => $a) { $c = $r[$i] ?? ''; $v[$a] = is_string($c) ? trim(preg_replace('/\s+/u', ' ', $c)) : $c; }
    if (empty($v['ad']) && !empty($v['ad_soyad'])) {
        $p = explode(' ', (string)$v['ad_soyad']);
        $v['soyad'] = count($p) > 1 ? array_pop($p) : '';
        $v['ad'] = implode(' ', $p);
    }
    foreach (['ad','soyad','firma','gorev','bina','oda','yatak','telefon','sicil_no','notlar','sebep','kategori','kat'] as $k) $v[$k] = trim((string)($v[$k] ?? ''));
    $v['tc_no'] = preg_replace('/\D+/', '', (string)($v['tc_no'] ?? ''));
    $v['giris_t'] = isset($v['giris']) ? kamp_tarih($v['giris']) : null;
    $v['cikis_t'] = isset($v['cikis']) ? kamp_tarih($v['cikis']) : null;
    $v['kategori_k'] = kim_kategori($v['kategori'], $v['gorev']);
    return $v;
}

/** Yer çözümleme: [yatak_id|null, oda_id|null, metin, hata|null]. */
function kim_yer(PDO $pdo, array $v, array &$onb): array
{
    if ($v['bina'] === '' && $v['oda'] === '' && $v['yatak'] === '') return [null, null, '', null];
    if (!isset($onb['oda'])) {
        $onb['bina'] = kamp_binalar($pdo);
        $onb['oda'] = $pdo->query("SELECT id, bina_id, ad, kat, tur FROM kamp_odalar WHERE aktif=1")->fetchAll();
    }
    $binaId = null;
    if ($v['bina'] !== '') {
        foreach ($onb['bina'] as $id => $b) if (kamp_norm($b['ad']) === kamp_norm($v['bina']) || ($b['kod'] && kamp_norm($b['kod']) === kamp_norm($v['bina']))) $binaId = $id;
        if (!$binaId) foreach ($onb['bina'] as $id => $b) if (str_contains(kamp_norm($b['ad']), kamp_norm($v['bina']))) { $binaId = $binaId ? -1 : $id; }
        if (!$binaId || $binaId < 0) return [null, null, '', 'bina bulunamadı: ' . $v['bina']];
    } elseif (count($onb['bina']) === 1) $binaId = (int)array_key_first($onb['bina']);
    if ($v['oda'] === '') return [null, null, '', 'oda boş'];
    $aday = array_values(array_filter($onb['oda'], fn($o) => kamp_norm($o['ad']) === kamp_norm($v['oda']) && (!$binaId || (int)$o['bina_id'] === $binaId)));
    if (count($aday) !== 1) return [null, null, '', count($aday) ? 'oda birden çok binada var — bina sütununu doldurun: ' . $v['oda'] : 'oda bulunamadı: ' . $v['oda']];
    $oda = $aday[0];
    if (!kamp_oda_yatakli($oda['tur'])) return [null, null, '', $oda['ad'] . ' yatak odası değil'];
    if ($v['yatak'] !== '') {
        $s = $pdo->prepare("SELECT id, no FROM kamp_yataklar WHERE oda_id=? AND aktif=1"); $s->execute([(int)$oda['id']]);
        foreach ($s->fetchAll() as $y) if (kamp_norm($y['no']) === kamp_norm($v['yatak'])) return [(int)$y['id'], (int)$oda['id'], $oda['ad'] . ' › ' . $y['no'], null];
        return [null, (int)$oda['id'], '', 'yatak bulunamadı: ' . $oda['ad'] . ' › ' . $v['yatak']];
    }
    return [0, (int)$oda['id'], $oda['ad'], null];   // 0 = odanın ilk boş yatağı
}

function kim_ilk_bos(PDO $pdo, int $odaId): ?int
{
    $s = $pdo->prepare("SELECT y.id FROM kamp_yataklar y LEFT JOIN kamp_konaklama k ON k.yatak_id=y.id AND k.cikis IS NULL
                         WHERE y.oda_id=? AND y.aktif=1 AND k.id IS NULL ORDER BY y.sira, y.id LIMIT 1");
    $s->execute([$odaId]);
    return ($id = $s->fetchColumn()) ? (int)$id : null;
}

/** Kişi eşleşmesi: TC → sicil → normalize ad soyad (tek kayıtsa). */
function kim_kisi_bul(PDO $pdo, array $v, array &$onb): array
{
    if (!isset($onb['kisi'])) {
        $onb['kisi'] = [];
        foreach ($pdo->query("SELECT * FROM kamp_kisiler") as $k) $onb['kisi'][(int)$k['id']] = $k;
    }
    if ($v['tc_no'] !== '') foreach ($onb['kisi'] as $k) if ($k['tc_no'] === $v['tc_no']) return [$k, 'TC no'];
    if ($v['sicil_no'] !== '') foreach ($onb['kisi'] as $k) if ((string)$k['sicil_no'] === $v['sicil_no']) return [$k, 'sicil no'];
    $n = kamp_norm($v['ad'] . ' ' . $v['soyad']);
    $aday = array_values(array_filter($onb['kisi'], fn($k) => kamp_norm($k['ad'] . ' ' . $k['soyad']) === $n));
    if (count($aday) === 1) {
        // TC'si dolu ve farklı olan kayıt başka biridir
        if ($v['tc_no'] !== '' && $aday[0]['tc_no'] && $aday[0]['tc_no'] !== $v['tc_no']) return [null, null];
        return [$aday[0], 'ad soyad'];
    }
    if (count($aday) > 1) return [null, 'cok'];
    return [null, null];
}

/**
 * Aktarım. $opt: firma_ac (bool), yerlestir (bool).
 * Dönüş: rapor dizisi (okunan = yeni + guncellenen + degismeyen + atlanan).
 */
function kim_import(PDO $pdo, array $grid, int $basSatir, array $harita, array $opt = []): array
{
    $s = ['okunan' => 0, 'yeni' => 0, 'guncellenen' => 0, 'degismeyen' => 0, 'atlanan' => 0,
          'yerlesen' => 0, 'tasinan' => 0, 'gecmis' => 0, 'yer_ayni' => 0, 'firma_acilan' => 0,
          'liste_atlanan' => [], 'liste_yer' => [], 'liste_yeni' => []];
    $onb = []; $gorulen = [];
    $uid = function_exists('current_user_id') ? current_user_id() : null;
    foreach (array_slice($grid, $basSatir + 1) as [$no, $r]) {
        $v = kim_satir($r, $harita);
        if ($v['ad'] === '' && $v['soyad'] === '') continue;           // boş / ara toplam satırı
        if (preg_match('/^(TOPLAM|GENEL TOPLAM|ARA TOPLAM)$/', kamp_norm($v['ad'] . ' ' . $v['soyad']))) continue;
        $s['okunan']++;
        $ad = kamp_buyuk(trim($v['ad'] . ' ' . $v['soyad']));
        $anah = $v['tc_no'] ?: kamp_norm($ad);
        if (isset($gorulen[$anah])) { $s['atlanan']++; $s['liste_atlanan'][] = "Satır $no · $ad — dosyada ikinci kez (satır {$gorulen[$anah]})"; continue; }
        $gorulen[$anah] = $no;
        try {
            // firma
            $firmaId = null;
            if ($v['firma'] !== '') {
                $firmaId = kamp_firma_bul($pdo, $v['firma'], false);
                if (!$firmaId && !empty($opt['firma_ac'])) { $firmaId = kamp_firma_bul($pdo, $v['firma'], true); $s['firma_acilan']++; }
            }
            [$kisi, $nasil] = kim_kisi_bul($pdo, $v, $onb);
            if ($nasil === 'cok') { $s['atlanan']++; $s['liste_atlanan'][] = "Satır $no · $ad — aynı adlı birden çok kişi kayıtlı, TC no ile ayırt edin"; continue; }
            if (!$kisi) {
                $r2 = kamp_kisi_ekle($pdo, ['ad' => $v['ad'], 'soyad' => $v['soyad'], 'tc_no' => $v['tc_no'], 'sicil_no' => $v['sicil_no'],
                                            'firma_id' => $firmaId, 'gorev' => $v['gorev'], 'kategori' => $v['kategori_k'],
                                            'telefon' => $v['telefon'], 'notlar' => $v['notlar']]);
                $kisiId = $r2['id'];
                $onb['kisi'][$kisiId] = kamp_kisi_bul($pdo, $kisiId);
                $s['yeni']++; $s['liste_yeni'][] = $ad;
            } else {
                $kisiId = (int)$kisi['id'];
                $deg = [];
                foreach (['tc_no' => $v['tc_no'], 'sicil_no' => $v['sicil_no'], 'gorev' => kamp_buyuk($v['gorev']), 'telefon' => $v['telefon'], 'firma_id' => $firmaId] as $k => $yeni)
                    if (($yeni !== '' && $yeni !== null) && ($kisi[$k] === null || $kisi[$k] === '')) $deg[$k] = $yeni;
                if ($deg) {
                    $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($deg)));
                    $pdo->prepare("UPDATE kamp_kisiler SET $set, updated_at=NOW() WHERE id=?")->execute([...array_values($deg), $kisiId]);
                    $onb['kisi'][$kisiId] = array_merge($kisi, $deg);
                    $s['guncellenen']++;
                } else $s['degismeyen']++;
            }
            if (empty($opt['yerlestir'])) continue;
            [$yatakId, $odaId, $yerMetin, $hata] = kim_yer($pdo, $v, $onb);
            if ($yatakId === null && $odaId === null && $hata === null) continue;   // yer sütunu boş
            if ($hata) { $s['liste_yer'][] = "Satır $no · $ad — $hata"; continue; }
            $giris = $v['giris_t'] ?? date('Y-m-d');
            if ($v['cikis_t']) {   // geçmiş konaklama
                $c = $pdo->prepare("SELECT COUNT(*) FROM kamp_konaklama WHERE kisi_id=? AND giris=?"); $c->execute([$kisiId, $giris]);
                if ($c->fetchColumn()) { $s['yer_ayni']++; continue; }
                kamp_konaklama_gecmis($pdo, $kisiId, $yatakId ?: null, $odaId, $giris, $v['cikis_t'], $v['sebep']);
                $s['gecmis']++;
                continue;
            }
            $acik = kamp_acik_konaklama($pdo, $kisiId);
            if ($acik && (int)$acik['oda_id'] === $odaId && (!$yatakId || (int)$acik['yatak_id'] === $yatakId)) { $s['yer_ayni']++; continue; }
            if (!$yatakId) $yatakId = kim_ilk_bos($pdo, $odaId);
            if (!$yatakId) { $s['liste_yer'][] = "Satır $no · $ad — $yerMetin odasında boş yatak yok"; continue; }
            if ($acik && $giris < $acik['giris']) $giris = date('Y-m-d');   // taşımada eski girişten geri gidilemez
            $r3 = kamp_yerlestir($pdo, $kisiId, $yatakId, $giris, 'Excel aktarımı', $uid);
            $r3['tasindi'] ? $s['tasinan']++ : $s['yerlesen']++;
        } catch (Throwable $e) {
            $s['liste_yer'][] = "Satır $no · $ad — " . $e->getMessage();
        }
    }
    return $s;
}
