<?php
/**
 * pts/_ortak.php — PTS (Personel Takip Sistemi) çekirdeği
 *
 * ArUco kartlarıyla personel giriş-çıkış takibi. Kamera personelin kartındaki
 * ArUco işaretçisini TARAYICIDA okur (assets/vendor/aruco.js — js-aruco2),
 * sunucuya yalnız marker ID gelir. Sunucuda OpenCV ya da native bağımlılık YOKTUR.
 *
 * ⚠⚠ PERSONEL BU MODÜLDE TUTULMAZ: kişi kaydı **`it_personel`** tablosudur
 * (IT Envanter modülü). PTS yalnız "hangi karta hangi kişi bağlı" ve "kim ne zaman
 * girdi/çıktı" bilgisini ekler. Bu yüzden PTS tabloları IT ile AYNI veritabanında
 * durur (bkz. includes/db_pts.php) ve JOIN'ler sıradan tek-DB JOIN'idir.
 * Böylece bir kişinin puantajı, zimmetli cihazları ve İK bilgisi tek kartta buluşur.
 */

require_once __DIR__ . '/../it/_ortak.php';   // it_personel yardımcıları, it_norm, it_buyuk

/** ArUco sözlüğü — kiosk, kart üretimi ve sunucu AYNI değeri kullanmalı. */
const PTS_SOZLUK = 'ARUCO_MIP_36h12';

/**
 * Sözlükteki kod sayısı ID üst sınırını belirler (ARUCO_MIP_36h12 → 250 kod).
 * ⚠ assets/vendor/aruco.js içindeki sözlükle birlikte değişmeli; 1000 markerlı bir
 * sözlüğe geçilecekse hem burası hem kiosk/kart sayfasındaki PTS_SOZLUK güncellenir.
 */
const PTS_MAX_MARKER = 249;

/** Hareket yönü: anahtar => [ad, renk, ikon] */
const PTS_YON = [
    'giris' => ['Giriş', 'success', 'bi-box-arrow-in-right'],
    'cikis' => ['Çıkış', 'secondary', 'bi-box-arrow-right'],
];

/** Geçiş noktasının yön davranışı. */
const PTS_NOKTA_YON = [
    'otomatik' => 'Otomatik (son hareketin tersi)',
    'giris'    => 'Yalnız GİRİŞ',
    'cikis'    => 'Yalnız ÇIKIŞ',
];

/** Aynı kart bu süre içinde tekrar okunursa yeni kayıt açılmaz (kamera aynı kareyi defalarca görür). */
function pts_debounce(): int
{
    return defined('PTS_SCAN_DEBOUNCE') ? max(1, (int)PTS_SCAN_DEBOUNCE) : 30;
}

function pts_yonAd(?string $y): string  { return PTS_YON[(string)$y][0] ?? (string)$y; }
function pts_yonRozet(?string $y): string
{
    $x = PTS_YON[(string)$y] ?? [(string)$y, 'secondary', 'bi-question'];
    return '<span class="badge bg-' . $x[1] . '"><i class="bi ' . $x[2] . ' me-1"></i>' . htmlspecialchars($x[0]) . '</span>';
}

/** Dakikayı "8s 15dk" biçiminde yazar. */
function pts_sure(int $dk): string
{
    if ($dk <= 0) return '—';
    $s = intdiv($dk, 60); $k = $dk % 60;
    return ($s ? $s . 's ' : '') . $k . 'dk';
}

// ─────────────────────────────────────────────────────────────── şema
/**
 * Şemayı kurar (runtime + kurulum_pts.php). İdempotenttir.
 * ⚠ **Transaction DIŞINDA çağrılmalı** — MySQL'de DDL örtük commit yapar ve
 * içeride çağrılırsa sonraki commit() "no active transaction" ile patlar
 * (IT personel aktarımında yaşanan hata; bkz. CLAUDE.md).
 */
function pts_semasi_kur(PDO $pdo): void
{
    static $kuruldu = false;
    if ($kuruldu) return;

    // ⚠ ENUM KULLANILMAZ: komşu IT tabloları da VARCHAR kullanıyor (aynı veritabanı) ve
    // SQLite'lı duman testi ENUM'u ayrıştıramıyor. Geçerli değerler PTS_YON / PTS_NOKTA_YON
    // sabitlerinde durur, doğrulama uygulama katmanındadır.

    // Kartlar. ⚠ Postgres tarafında "aktif kart" kısmi UNIQUE index ile garanti
    // ediliyordu; MySQL'de kısmi index YOK. Bu yüzden garanti UYGULAMA katmanında:
    // pts_kart_ver() transaction içinde çakışmayı kontrol eder, pts_kart_celiskileri()
    // de kurulum ekranında ihlal var mı diye tarar.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pts_kartlar (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        marker_id    INT NOT NULL,
        sozluk       VARCHAR(40) NOT NULL DEFAULT '" . PTS_SOZLUK . "',
        personel_id  INT NOT NULL,
        verildi      DATETIME NOT NULL,
        iptal        DATETIME NULL,
        kullanici_id INT NULL,
        KEY pts_kart_personel (personel_id),
        KEY pts_kart_marker (sozluk, marker_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Geçiş noktası = kart okutulan fiziksel yer (kapı, turnike, kiosk tableti).
    // Kiosk cihazı oturum AÇMAZ; kendini `cihaz_anahtari` ile tanıtır.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pts_noktalar (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        kod            VARCHAR(40)  NOT NULL,
        ad             VARCHAR(120) NOT NULL,
        cihaz_anahtari VARCHAR(64)  NOT NULL,
        yon            VARCHAR(10) NOT NULL DEFAULT 'otomatik',   -- PTS_NOKTA_YON
        lokasyon_id    INT NULL,
        aktif          TINYINT(1) NOT NULL DEFAULT 1,
        son_gorulme    DATETIME NULL,
        created_at     DATETIME NULL,
        UNIQUE KEY pts_nokta_kod (kod),
        UNIQUE KEY pts_nokta_anahtar (cihaz_anahtari)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Giriş/çıkış hareketleri. Fotoğraf DB'de tutulmaz; diske yazılır, burada yolu durur.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pts_hareketler (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        personel_id  INT NOT NULL,
        kart_id      INT NULL,
        nokta_id     INT NULL,
        marker_id    INT NULL,
        yon          VARCHAR(10) NOT NULL,                       -- PTS_YON: giris | cikis
        zaman        DATETIME NOT NULL,
        elle         TINYINT(1) NOT NULL DEFAULT 0,
        foto_url     VARCHAR(255) NULL,
        aciklama     VARCHAR(255) NULL,
        kullanici_id INT NULL,
        created_at   DATETIME NULL,
        KEY pts_hrk_personel (personel_id, zaman),
        KEY pts_hrk_zaman (zaman)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $kuruldu = true;
}

// ─────────────────────────────────────────────────────── kart işlemleri
/**
 * Sicil numarasından ArUco marker ID türetir: rakamlar okunur, baştaki sıfırlar
 * atılır ("00042" → 42). Kural TEK yerde durur ki panel ile kart sayfası ayrışmasın.
 *
 * @throws RuntimeException sicil rakam içermiyorsa ya da sözlüğün sınırını aşıyorsa
 */
function pts_marker_sicilden(string $sicil): int
{
    $rakam = preg_replace('/\D+/', '', $sicil);
    if ($rakam === '' || $rakam === null) {
        throw new RuntimeException('"' . $sicil . '" sicil numarası rakam içermiyor, ArUco ID türetilemedi.');
    }
    $deger = ltrim($rakam, '0');
    $deger = $deger === '' ? 0 : $deger;
    if (strlen((string)$deger) > 9) {
        throw new RuntimeException('"' . $sicil . '" sicil numarası çok büyük.');
    }
    $id = (int)$deger;
    if ($id > PTS_MAX_MARKER) {
        throw new RuntimeException('Sicil ' . $sicil . ' için ArUco ID ' . $id . ' olurdu; kullanılan sözlük en fazla '
            . PTS_MAX_MARKER . ' destekliyor. Sicil aralığı büyükse 1000 markerlı bir sözlüğe geçilmeli.');
    }
    return $id;
}

/** Personelin AKTİF kartı (iptal edilmemiş). */
function pts_aktif_kart(PDO $pdo, int $personelId): ?array
{
    $st = $pdo->prepare("SELECT * FROM pts_kartlar WHERE personel_id=? AND iptal IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$personelId]);
    return $st->fetch() ?: null;
}

/** Marker'ın aktif sahibi (varsa). */
function pts_marker_sahibi(PDO $pdo, int $markerId, string $sozluk = PTS_SOZLUK): ?array
{
    $st = $pdo->prepare("SELECT k.*, p.ad, p.soyad, p.sicil_no
                           FROM pts_kartlar k JOIN it_personel p ON p.id = k.personel_id
                          WHERE k.marker_id=? AND k.sozluk=? AND k.iptal IS NULL LIMIT 1");
    $st->execute([$markerId, $sozluk]);
    return $st->fetch() ?: null;
}

/**
 * Karta personel bağlar. Kişinin önceki kartı varsa İPTAL edilir (kart kaybolunca
 * yenisi verilir, eskisi kapanır) — geçmiş hareketler eski karta bağlı kalır.
 *
 * @throws RuntimeException marker başka bir aktif personelde ise
 */
function pts_kart_ver(PDO $pdo, int $personelId, int $markerId, ?int $kullaniciId = null, string $sozluk = PTS_SOZLUK): array
{
    if ($markerId < 0 || $markerId > PTS_MAX_MARKER) {
        throw new RuntimeException('ArUco ID 0 ile ' . PTS_MAX_MARKER . ' arasında olmalı.');
    }
    $p = it_personel_bul($pdo, $personelId);
    if (!$p) throw new RuntimeException('Personel bulunamadı.');
    if (!empty($p['isten_cikis'])) throw new RuntimeException('İşten ayrılmış personele kart tanımlanamaz.');

    $disTx = $pdo->inTransaction();
    if (!$disTx) $pdo->beginTransaction();
    try {
        $sahip = pts_marker_sahibi($pdo, $markerId, $sozluk);
        if ($sahip && (int)$sahip['personel_id'] !== $personelId) {
            throw new RuntimeException('ArUco ID ' . $markerId . ' şu an '
                . trim($sahip['ad'] . ' ' . $sahip['soyad']) . ' üzerinde kayıtlı. Önce o kartı iptal edin.');
        }
        $pdo->prepare("UPDATE pts_kartlar SET iptal=? WHERE personel_id=? AND iptal IS NULL")
            ->execute([date('Y-m-d H:i:s'), $personelId]);
        $pdo->prepare("INSERT INTO pts_kartlar (marker_id, sozluk, personel_id, verildi, kullanici_id) VALUES (?,?,?,?,?)")
            ->execute([$markerId, $sozluk, $personelId, date('Y-m-d H:i:s'), $kullaniciId]);
        $id = (int)$pdo->lastInsertId();
        if (!$disTx) $pdo->commit();
        return ['id' => $id, 'marker_id' => $markerId, 'sozluk' => $sozluk, 'personel_id' => $personelId];
    } catch (Throwable $e) {
        if (!$disTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Aktif kartı iptal eder. Kaç kart kapandığını döndürür. */
function pts_kart_iptal(PDO $pdo, int $personelId): int
{
    $st = $pdo->prepare("UPDATE pts_kartlar SET iptal=? WHERE personel_id=? AND iptal IS NULL");
    $st->execute([date('Y-m-d H:i:s'), $personelId]);
    return $st->rowCount();
}

/**
 * "Bir kişide tek aktif kart, bir markerda tek aktif kişi" kuralının ihlalleri.
 * MySQL'de kısmi UNIQUE index olmadığı için kural uygulamada tutuluyor; bu tarama
 * kurulum ekranında elle bozulmuş veriyi (doğrudan SQL ile) yakalamak içindir.
 */
function pts_kart_celiskileri(PDO $pdo): array
{
    $c = [];
    foreach ([['personel_id', 'personel'], ['marker_id', 'marker']] as [$kol, $et]) {
        $st = $pdo->query("SELECT $kol AS deger, COUNT(*) adet FROM pts_kartlar
                            WHERE iptal IS NULL GROUP BY $kol HAVING COUNT(*) > 1");
        foreach ($st->fetchAll() as $r) $c[] = ['tur' => $et, 'deger' => $r['deger'], 'adet' => (int)$r['adet']];
    }
    return $c;
}

// ───────────────────────────────────────────────────── geçiş noktaları
/** Kiosk cihazının kimliği. Sunucuda üretilir, panelden kopyalanıp cihaza bir kez girilir. */
function pts_anahtar_uret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/** Cihaz anahtarından geçiş noktasını bulur (kiosk oturum açmaz). */
function pts_nokta_anahtarla(PDO $pdo, string $anahtar): ?array
{
    $anahtar = trim($anahtar);
    if ($anahtar === '') return null;
    $st = $pdo->prepare("SELECT * FROM pts_noktalar WHERE cihaz_anahtari=? AND aktif=1 LIMIT 1");
    $st->execute([$anahtar]);
    return $st->fetch() ?: null;
}

// ──────────────────────────────────────────────────────── kart okutma
/**
 * Kiosk bir ArUco marker okuduğunda çağrılır — PTS'nin kalbi.
 *
 * Yön kuralı: geçiş noktası SABİT yönlüyse (giriş kapısı / çıkış kapısı) o yön
 * kullanılır; 'otomatik' ise personelin **son hareketinin tersi** alınır.
 * Debounce: kart pencere içinde tekrar okunursa yeni kayıt AÇILMAZ, mevcut durum
 * geri bildirilir (`tekrar => true`) — kamera aynı kareyi saniyede defalarca görür.
 *
 * @return array kiosk ekranında gösterilecek sonuç
 * @throws RuntimeException kart tanımsızsa
 */
function pts_scan(PDO $pdo, int $markerId, ?array $nokta, ?string $zorunluYon = null, ?string $sozluk = null): array
{
    $sozluk = $sozluk ?: PTS_SOZLUK;
    $st = $pdo->prepare("SELECT k.id AS kart_id, p.id AS personel_id, p.ad, p.soyad, p.sicil_no, p.unvan, p.birim
                           FROM pts_kartlar k JOIN it_personel p ON p.id = k.personel_id
                          WHERE k.marker_id=? AND k.sozluk=? AND k.iptal IS NULL LIMIT 1");
    $st->execute([$markerId, $sozluk]);
    $kart = $st->fetch();
    if (!$kart) throw new RuntimeException('ArUco ID ' . $markerId . ' hiçbir personele tanımlı değil.');
    if (!empty($kart['isten_cikis'] ?? null)) throw new RuntimeException('Personel işten ayrılmış.');

    $adSoyad = trim($kart['ad'] . ' ' . $kart['soyad']);

    // Son hareket: hem debounce hem yön kararı buradan çıkar.
    $sh = $pdo->prepare("SELECT yon, zaman FROM pts_hareketler WHERE personel_id=? ORDER BY zaman DESC, id DESC LIMIT 1");
    $sh->execute([(int)$kart['personel_id']]);
    $son = $sh->fetch() ?: null;

    $simdi = time();
    if ($son && ($simdi - strtotime($son['zaman'])) < pts_debounce()) {
        return ['tekrar' => true, 'personel_id' => (int)$kart['personel_id'], 'ad_soyad' => $adSoyad,
                'sicil_no' => $kart['sicil_no'], 'unvan' => $kart['unvan'], 'birim' => $kart['birim'],
                'yon' => $son['yon'], 'zaman' => $son['zaman'], 'hareket_id' => null];
    }

    $noktaYon = $nokta['yon'] ?? 'otomatik';
    if ($zorunluYon !== null && isset(PTS_YON[$zorunluYon]))      $yon = $zorunluYon;
    elseif (isset(PTS_YON[$noktaYon]))                            $yon = $noktaYon;
    else                                                          $yon = ($son && $son['yon'] === 'giris') ? 'cikis' : 'giris';

    $zaman = date('Y-m-d H:i:s', $simdi);
    $pdo->prepare("INSERT INTO pts_hareketler (personel_id, kart_id, nokta_id, marker_id, yon, zaman, created_at)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([(int)$kart['personel_id'], (int)$kart['kart_id'], $nokta['id'] ?? null, $markerId, $yon, $zaman, $zaman]);
    $hid = (int)$pdo->lastInsertId();

    if (!empty($nokta['id'])) {
        $pdo->prepare("UPDATE pts_noktalar SET son_gorulme=? WHERE id=?")->execute([$zaman, (int)$nokta['id']]);
    }

    return ['tekrar' => false, 'personel_id' => (int)$kart['personel_id'], 'ad_soyad' => $adSoyad,
            'sicil_no' => $kart['sicil_no'], 'unvan' => $kart['unvan'], 'birim' => $kart['birim'],
            'yon' => $yon, 'zaman' => $zaman, 'hareket_id' => $hid];
}

/**
 * Kiosk'un gönderdiği JPEG karesini diske yazar ve harekete bağlar.
 * ⚠ Görüntü DB'de TUTULMAZ (yedek şişmesin); `uploads/pts_gecis/Y-m-d/` altına
 * yazılır, tabloda yalnız göreli yol durur. Yazılamazsa geçiş yine geçerlidir —
 * kanıt görüntüsü kaydın tamamlayıcısıdır, ön koşulu değil.
 */
function pts_foto_kaydet(PDO $pdo, int $hareketId, string $dataUrl, string $kok = '../'): ?string
{
    if (!preg_match('#^data:image/jpe?g;base64,#i', $dataUrl)) return null;
    $ham = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($ham === false || strlen($ham) < 512 || strlen($ham) > 4 * 1024 * 1024) return null;

    $gun    = date('Y-m-d');
    $gorel  = 'uploads/pts_gecis/' . $gun . '/' . $hareketId . '.jpg';
    $klasor = rtrim($kok, '/') . '/uploads/pts_gecis/' . $gun;
    if (!is_dir($klasor) && !@mkdir($klasor, 0775, true)) return null;
    if (@file_put_contents(rtrim($kok, '/') . '/' . $gorel, $ham) === false) return null;

    $pdo->prepare("UPDATE pts_hareketler SET foto_url=? WHERE id=?")->execute([$gorel, $hareketId]);
    return $gorel;
}

// ───────────────────────────────────────────────────────────── puantaj
/**
 * Günlük puantaj: kişi × gün → ilk giriş, son çıkış, çalışılan dakika.
 *
 * ⚠ **Toplama SQL'de değil PHP'de yapılır.** Postgres sürümü pencere fonksiyonu +
 * `FILTER (WHERE …)` + `EXTRACT(EPOCH …)` kullanıyordu; bunların hiçbiri MySQL ile
 * SQLite arasında taşınabilir değil (FILTER MySQL'de yok, TIMESTAMPDIFF SQLite'ta yok)
 * ve duman testi SQLite üzerinde koşuyor. Ham hareketleri sade bir sorguyla çekip
 * katlamak hem taşınabilir hem de test edilebilir.
 *
 * Çalışılan süre = gün içindeki ardışık giriş→çıkış çiftlerinin toplamı. Çıkış
 * yapmadan günü kapatan personelde son girişten sonrası SAYILMAZ ve o hareket
 * `eksik` olarak raporlanır (puantajda uyarı rozeti).
 *
 * @return array{satirlar:array, toplam_dk:int, eksik:int}
 */
function pts_gunluk(PDO $pdo, string $bas, string $bit, array $f = []): array
{
    $w = ['h.zaman >= ?', 'h.zaman <= ?'];
    $p = [$bas . ' 00:00:00', $bit . ' 23:59:59'];
    if (!empty($f['personel_id'])) { $w[] = 'h.personel_id=?';  $p[] = (int)$f['personel_id']; }
    if (!empty($f['birim']))       { $w[] = 'p.birim=?';        $p[] = $f['birim']; }
    if (!empty($f['lokasyon_id'])) {
        $alt = it_lokasyon_altlar($pdo, (int)$f['lokasyon_id']);
        if ($alt) { $w[] = 'p.lokasyon_id IN (' . implode(',', array_fill(0, count($alt), '?')) . ')'; foreach ($alt as $x) $p[] = $x; }
    }
    $st = $pdo->prepare("SELECT h.personel_id, h.yon, h.zaman, h.id,
                                p.ad, p.soyad, p.sicil_no, p.unvan, p.birim
                           FROM pts_hareketler h
                           JOIN it_personel p ON p.id = h.personel_id
                          WHERE " . implode(' AND ', $w) . "
                          ORDER BY h.personel_id, h.zaman, h.id");
    $st->execute($p);

    $grup = [];
    foreach ($st->fetchAll() as $r) {
        $gun = substr((string)$r['zaman'], 0, 10);
        $k   = $r['personel_id'] . '|' . $gun;
        if (!isset($grup[$k])) {
            $grup[$k] = ['personel_id' => (int)$r['personel_id'], 'gun' => $gun,
                         'ad_soyad' => trim($r['ad'] . ' ' . $r['soyad']), 'sicil_no' => $r['sicil_no'],
                         'unvan' => $r['unvan'], 'birim' => $r['birim'],
                         'ilk_giris' => null, 'son_cikis' => null, 'dakika' => 0, 'eksik' => 0, 'hareket' => 0];
        }
        $grup[$k]['hareket']++;
        if ($r['yon'] === 'giris' && $grup[$k]['ilk_giris'] === null) $grup[$k]['ilk_giris'] = $r['zaman'];
        if ($r['yon'] === 'cikis')                                    $grup[$k]['son_cikis'] = $r['zaman'];
        $grup[$k]['_h'][] = $r;
    }

    $toplam = 0; $eksikTop = 0;
    foreach ($grup as $k => &$g) {
        $acik = null;                       // eşleşmeyi bekleyen giriş
        foreach ($g['_h'] as $h) {
            if ($h['yon'] === 'giris') {
                if ($acik !== null) $g['eksik']++;      // önceki giriş çıkışsız kapandı
                $acik = $h['zaman'];
            } else {                                     // çıkış
                if ($acik !== null) {
                    $g['dakika'] += max(0, (int)round((strtotime($h['zaman']) - strtotime($acik)) / 60));
                    $acik = null;
                }
                // çıkışla başlayan gün (önceki günden devreden) süreye eklenmez
            }
        }
        if ($acik !== null) $g['eksik']++;               // gün çıkışsız kapandı
        unset($g['_h']);
        $toplam += $g['dakika']; $eksikTop += $g['eksik'];
    }
    unset($g);

    $satirlar = array_values($grup);
    usort($satirlar, fn($a, $b) => [$b['gun'], $a['ad_soyad']] <=> [$a['gun'], $b['ad_soyad']]);
    return ['satirlar' => $satirlar, 'toplam_dk' => $toplam, 'eksik' => $eksikTop];
}

/** Şu an İÇERİDE olan personel (son hareketi 'giris' olanlar). */
function pts_iceridekiler(PDO $pdo): array
{
    $st = $pdo->query("SELECT h.personel_id, h.zaman, h.nokta_id, p.ad, p.soyad, p.sicil_no, p.birim
                         FROM pts_hareketler h
                         JOIN it_personel p ON p.id = h.personel_id
                        WHERE h.yon='giris'
                          AND h.id = (SELECT MAX(h2.id) FROM pts_hareketler h2 WHERE h2.personel_id = h.personel_id)
                        ORDER BY h.zaman DESC");
    return $st->fetchAll();
}

/** Dashboard sayaçları. */
function pts_ozet(PDO $pdo): array
{
    $bugun = date('Y-m-d');
    $o = ['kart' => 0, 'kartsiz' => 0, 'nokta' => 0, 'iceride' => 0,
          'bugun_giris' => 0, 'bugun_hareket' => 0, 'son_okuma' => null];
    try {
        $o['kart']    = (int)$pdo->query("SELECT COUNT(*) FROM pts_kartlar WHERE iptal IS NULL")->fetchColumn();
        $o['nokta']   = (int)$pdo->query("SELECT COUNT(*) FROM pts_noktalar WHERE aktif=1")->fetchColumn();
        $o['iceride'] = count(pts_iceridekiler($pdo));
        $st = $pdo->prepare("SELECT COUNT(DISTINCT personel_id) g, COUNT(*) h FROM pts_hareketler WHERE zaman >= ?");
        $st->execute([$bugun . ' 00:00:00']);
        $r = $st->fetch() ?: [];
        $o['bugun_giris']   = (int)($r['g'] ?? 0);
        $o['bugun_hareket'] = (int)($r['h'] ?? 0);
        $o['son_okuma']     = $pdo->query("SELECT MAX(zaman) FROM pts_hareketler")->fetchColumn() ?: null;
        // Kartı olmayan çalışan personel — "kart dağıtımı bitti mi" sorusunun cevabı
        $o['kartsiz'] = (int)$pdo->query("SELECT COUNT(*) FROM it_personel p
                                           WHERE p.isten_cikis IS NULL
                                             AND NOT EXISTS (SELECT 1 FROM pts_kartlar k
                                                              WHERE k.personel_id=p.id AND k.iptal IS NULL)")->fetchColumn();
    } catch (Throwable $e) { /* şema henüz kurulmamış olabilir */ }
    return $o;
}

/** Hareket defteri süzgeci (whitelist; ham input asla SQL'e girmez). */
function pts_filtre(array $g): array
{
    $w = []; $p = []; $etkin = [];
    if (!empty($g['bas'])) { $w[] = 'h.zaman >= ?'; $p[] = $g['bas'] . ' 00:00:00'; $etkin['bas'] = $g['bas']; }
    if (!empty($g['bit'])) { $w[] = 'h.zaman <= ?'; $p[] = $g['bit'] . ' 23:59:59'; $etkin['bit'] = $g['bit']; }
    if (!empty($g['yon']) && isset(PTS_YON[$g['yon']])) { $w[] = 'h.yon=?'; $p[] = $g['yon']; $etkin['yon'] = $g['yon']; }
    if (!empty($g['nokta_id'])) { $w[] = 'h.nokta_id=?'; $p[] = (int)$g['nokta_id']; $etkin['nokta_id'] = (int)$g['nokta_id']; }
    if (!empty($g['personel_id'])) { $w[] = 'h.personel_id=?'; $p[] = (int)$g['personel_id']; $etkin['personel_id'] = (int)$g['personel_id']; }
    if (($g['foto'] ?? '') === 'var') { $w[] = "h.foto_url IS NOT NULL AND h.foto_url<>''"; $etkin['foto'] = 'var'; }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p, $etkin];
}
