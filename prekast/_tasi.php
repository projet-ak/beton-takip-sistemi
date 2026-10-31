<?php
/**
 * _tasi.php — Prekast tablolarını CRM veritabanından KENDİ veritabanına taşıma çekirdeği (pkt_*).
 * Ekran: prekast/veritabani_tasi.php (yalnız admin).
 *
 * Gerekçe: CLAUDE.md §0 "her modül kendi veritabanında". Prekast bu kuralın tek istisnasıydı —
 * tabloları (`prekast_*`) CRM'in veritabanında duruyordu. Prekast tabloları CRM tablolarına JOIN
 * atmaz; taşımak yalnız VERİ kopyalamaktır.
 *
 * Akış (her adım ayrı düğme, geri alınabilir sırada):
 *   1. Hedef veritabanı (öneri `takbulut_prekast`) — DB kullanıcısının yetkisi varsa ekran oluşturur,
 *      yoksa aaPanel'den açılır.
 *   2. KOPYALA — `SHOW CREATE TABLE` ile birebir yapı (AUTO_INCREMENT dahil) + satırlar. Her tablo
 *      satır sayısı + İÇERİK ÖZETİ (md5) ile kaynakla karşılaştırılır. Hedefe `prekast_tasima`
 *      işaret satırı yazılır (zaman + tablo özetleri). Kaynağa DOKUNULMAZ.
 *   3. config.php'ye `define('PREKAST_DB_NAME', …)` eklenir → modül yeni veritabanından çalışır.
 *   4. Eski kopyaları kaldır — ancak kaynak kopyadan beri DEĞİŞMEDİYSE (her satırı hedefte demektir),
 *      önce CRM veritabanının tam yedeği alınır, sonra `prekast_*` tabloları CRM'den silinir.
 *
 * ⚠ Bağlantılar sayfanın `db_prekast.php`'sinden DEĞİL burada açılır: PREKAST_DB_NAME tanımlanmış ama
 *   veritabanı henüz yoksa db_prekast.php 503 ile durur — taşıma ekranı o durumda da açılabilmeli.
 * ⚠ İki bağlantı da `ATTR_STRINGIFY_FETCHES` ile açılır: içerik özeti iki taraftan aynı biçimde
 *   okunmalı (PHP 8.1+ emülasyonlu sorgularda tamsayıları yerel türde döndürür).
 */

const PKT_ISARET = 'prekast_tasima';   // hedefteki işaret tablosu — taşınan tablolar listesine girmez

/** Veritabanı adı güvenli mi (SQL'e tırnakla gömüleceği için katı). */
function pkt_ad_gecerli(string $ad): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $ad);
}

/** Prekast'ın ŞU AN durduğu veritabanı = CRM'inki (CRM_DB_NAME tanımsızsa ana DB). */
function pkt_kaynak_db(): string
{
    return defined('CRM_DB_NAME') && CRM_DB_NAME !== '' ? CRM_DB_NAME : DB_NAME;
}

/** config.php'de PREKAST_DB_NAME tanımlı ve kaynaktan farklı mı → modül yeni veritabanından çalışıyor. */
function pkt_aktif_ayri(): bool
{
    return defined('PREKAST_DB_NAME') && PREKAST_DB_NAME !== '' && PREKAST_DB_NAME !== pkt_kaynak_db();
}

/** Başka modüllerin kullandığı veritabanları — hedef bunlardan biri olamaz (prekast tabloları karışmasın). */
function pkt_dolu_db_adlari(): array
{
    $adlar = [DB_NAME];
    foreach (['DEMIR', 'SERAMIK', 'DEPO', 'AKARYAKIT', 'CRM', 'IT', 'PTS'] as $m) {
        $c = $m . '_DB_NAME';
        if (defined($c) && constant($c) !== '') $adlar[] = constant($c);
    }
    return array_values(array_unique($adlar));
}

function pkt_pdo_ac(string $db, string $user, string $pass): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ($db !== '' ? ';dbname=' . $db : '') . ';charset=utf8mb4',
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_STRINGIFY_FETCHES => true]
    );
}

function pkt_kaynak_pdo(): PDO
{
    $user = defined('CRM_DB_USER') && CRM_DB_USER !== '' ? CRM_DB_USER : DB_USER;
    $pass = defined('CRM_DB_PASS') ? CRM_DB_PASS : DB_PASS;
    return pkt_pdo_ac(pkt_kaynak_db(), $user, $pass);
}

/** Hedef bağlantısı — PREKAST_DB_USER/PASS tanımlıysa onlarla (aaPanel her DB'ye ayrı kullanıcı açar). */
function pkt_hedef_pdo(string $db): PDO
{
    $user = defined('PREKAST_DB_USER') && PREKAST_DB_USER !== '' ? PREKAST_DB_USER : DB_USER;
    $pass = defined('PREKAST_DB_PASS') ? PREKAST_DB_PASS : DB_PASS;
    return pkt_pdo_ac($db, $user, $pass);
}

/**
 * Hedefe bağlanmayı dener.
 * durum: ok · yok (veritabanı yok, kullanıcı oluşturabilir olabilir) · erisim (yetki/parola) · hata
 * @return array{pdo: ?PDO, durum: string, mesaj: string}
 */
function pkt_hedef_baglan(string $db): array
{
    try {
        return ['pdo' => pkt_hedef_pdo($db), 'durum' => 'ok', 'mesaj' => ''];
    } catch (PDOException $e) {
        $kod = (int)($e->errorInfo[1] ?? 0);
        $msg = $e->getMessage();
        if ($kod === 1049 || stripos($msg, 'Unknown database') !== false) $durum = 'yok';
        elseif (in_array($kod, [1044, 1045], true) || stripos($msg, 'Access denied') !== false) $durum = 'erisim';
        else $durum = 'hata';
        return ['pdo' => null, 'durum' => $durum, 'mesaj' => $msg];
    }
}

/** Hedef veritabanını kaynakla AYNI karakter seti/harmanlamayla oluşturur. Hata metni ya da null. */
function pkt_db_olustur(PDO $kaynak, string $db): ?string
{
    if (!pkt_ad_gecerli($db)) return 'Geçersiz veritabanı adı.';
    $cs = 'utf8mb4'; $col = '';
    try {
        $st = $kaynak->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
                                  FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([pkt_kaynak_db()]);
        if ($r = $st->fetch(PDO::FETCH_NUM)) { $cs = $r[0] ?: $cs; $col = $r[1] ?: ''; }
    } catch (Throwable $e) { /* varsayılan utf8mb4 */ }
    if (!preg_match('/^[a-z0-9_]+$/i', $cs) || ($col !== '' && !preg_match('/^[a-z0-9_]+$/i', $col))) {
        $cs = 'utf8mb4'; $col = '';
    }
    try {
        $sun = pkt_hedef_pdo('');
        $sun->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET $cs" . ($col !== '' ? " COLLATE $col" : ''));
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/** Veritabanındaki prekast_ tabloları (işaret tablosu hariç), adla sıralı. */
function pkt_tablolar(PDO $pdo): array
{
    $t = array_filter($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
        fn($ad) => str_starts_with((string)$ad, 'prekast_') && $ad !== PKT_ISARET
                   && preg_match('/^[A-Za-z0-9_]+$/', (string)$ad));
    sort($t);
    return array_values($t);
}

/**
 * Tablonun satır sayısı + içerik özeti. Satırlar birincil anahtara göre sıralanıp her hücre
 * ayraçlarla md5'e beslenir — iki veritabanındaki kopyanın BİREBİR aynı olduğunu böyle anlarız.
 * @return array{satir: int, ozet: string}
 */
function pkt_ozet(PDO $pdo, string $tablo): array
{
    $pk = [];
    foreach ($pdo->query("SHOW KEYS FROM `$tablo` WHERE Key_name = 'PRIMARY'")->fetchAll() as $k) {
        $pk[(int)$k['Seq_in_index']] = $k['Column_name'];
    }
    ksort($pk);
    if (!$pk) $pk = array_column($pdo->query("SHOW COLUMNS FROM `$tablo`")->fetchAll(), 'Field');
    $sira = implode(',', array_map(fn($c) => '`' . str_replace('`', '', $c) . '`', $pk));

    $h = hash_init('md5'); $n = 0;
    $st = $pdo->query("SELECT * FROM `$tablo` ORDER BY $sira");
    while ($r = $st->fetch(PDO::FETCH_NUM)) {
        $n++;
        hash_update($h, implode("\x1f", array_map(fn($v) => $v === null ? "\x00" : (string)$v, $r)) . "\x1e");
    }
    return ['satir' => $n, 'ozet' => hash_final($h)];
}

/** @return array<string, array{satir:int, ozet:string}> */
function pkt_ozetler(PDO $pdo, array $tablolar): array
{
    $o = [];
    foreach ($tablolar as $t) $o[$t] = pkt_ozet($pdo, $t);
    return $o;
}

/** İşaret tablosunun son satırı (son kopyalama): zaman, kaynak/hedef DB, tablo özetleri. */
function pkt_isaret_son(PDO $hedef): ?array
{
    try {
        $r = $hedef->query('SELECT * FROM `' . PKT_ISARET . '` ORDER BY id DESC LIMIT 1')->fetch();
    } catch (Throwable $e) {
        return null;   // tablo yok → hiç kopyalanmamış
    }
    if (!$r) return null;
    $r['ozet'] = json_decode((string)$r['ozet'], true) ?: [];
    return $r;
}

function pkt_isaret_yaz(PDO $hedef, string $kaynakDb, string $hedefDb, array $ozetler, string $kullanici): void
{
    $hedef->exec('CREATE TABLE IF NOT EXISTS `' . PKT_ISARET . "` (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        zaman      DATETIME NOT NULL,
        kaynak_db  VARCHAR(64) NOT NULL,
        hedef_db   VARCHAR(64) NOT NULL,
        ozet       TEXT NOT NULL      COMMENT 'tablo => {satir, ozet} — kopyalama anındaki içerik',
        kullanici  VARCHAR(100) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $hedef->prepare('INSERT INTO `' . PKT_ISARET . '` (zaman, kaynak_db, hedef_db, ozet, kullanici) VALUES (NOW(), ?, ?, ?, ?)');
    $st->execute([$kaynakDb, $hedefDb, json_encode($ozetler, JSON_UNESCAPED_UNICODE), $kullanici]);
}

/** İki özet kümesi birebir aynı mı (aynı tablolar, aynı satır sayısı, aynı içerik). */
function pkt_esit(array $a, array $b): bool
{
    ksort($a); ksort($b);
    if (array_keys($a) !== array_keys($b)) return false;
    foreach ($a as $t => $o) {
        if (($o['satir'] ?? -1) !== ($b[$t]['satir'] ?? -2) || ($o['ozet'] ?? '') !== ($b[$t]['ozet'] ?? '')) return false;
    }
    return true;
}

/** Hedefteki prekast tablolarının hepsi boş mu (ya da hiç yok mu). */
function pkt_hedef_bos(array $hedefOzet): bool
{
    foreach ($hedefOzet as $o) if ($o['satir'] > 0) return false;
    return true;
}

/**
 * Hedefe kopyalamaya izin var mı? null = var, metin = neden yok.
 * Kural: hedefte veri varsa yalnız (a) bizim son kopyamızdan beri DEĞİŞMEMİŞSE ya da (b) modül henüz
 * eski veritabanından çalışırken kullanıcı açıkça "üzerine yaz" derse. Modül yeni veritabanına
 * geçtikten sonra o veritabanı CANLIDIR — yeni kayıt almışsa üzerine asla yazılmaz.
 */
function pkt_kopya_izni(PDO $hedef, bool $aktifAyri, bool $ustuneYaz): ?string
{
    $ho = pkt_ozetler($hedef, pkt_tablolar($hedef));
    if (pkt_hedef_bos($ho)) return null;
    $is = pkt_isaret_son($hedef);
    if ($is && pkt_esit($is['ozet'], $ho)) return null;
    if ($aktifAyri) {
        return 'Prekast artık bu veritabanından çalışıyor ve son kopyadan sonra yeni kayıt almış — üzerine yazılamaz.';
    }
    return $ustuneYaz ? null
        : 'Hedef veritabanında son kopyadan farklı prekast verisi var. Üzerine yazmak için kutuyu işaretleyin.';
}

/** Satırları çoklu INSERT ile yazar. */
function pkt_satir_yaz(PDO $hedef, string $tablo, array $kolonlar, array $satirlar): void
{
    $kol = '`' . implode('`,`', array_map(fn($c) => str_replace('`', '', $c), $kolonlar)) . '`';
    $tek = '(' . implode(',', array_fill(0, count($kolonlar), '?')) . ')';
    $st  = $hedef->prepare("INSERT INTO `$tablo` ($kol) VALUES " . implode(',', array_fill(0, count($satirlar), $tek)));
    $deg = [];
    foreach ($satirlar as $r) foreach ($kolonlar as $c) $deg[] = $r[$c];
    $st->execute($deg);
}

/**
 * KOPYALA — hedefte tabloyu baştan kurar ve satırları aktarır, sonra iki tarafın özetini karşılaştırır.
 * Kaynağa yalnız OKUMA yapılır.
 * @return array{tamam: bool, tablolar: array, hata: ?string}
 */
function pkt_kopyala(PDO $kaynak, PDO $hedef, string $hedefDb, string $kullanici): array
{
    $tablolar = pkt_tablolar($kaynak);
    if (!$tablolar) return ['tamam' => false, 'tablolar' => [], 'hata' => 'Kaynakta prekast_ tablosu yok.'];

    $sonuc = [];
    try {
        $hedef->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tablolar as $t) {
            $create = $kaynak->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1] ?? '';
            if ($create === '') throw new RuntimeException("$t tablosunun yapısı okunamadı.");
            $hedef->exec("DROP TABLE IF EXISTS `$t`");
            $hedef->exec($create);

            $st = $kaynak->query("SELECT * FROM `$t`");
            $kolonlar = null; $tampon = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $kolonlar ??= array_keys($r);
                $tampon[] = $r;
                if (count($tampon) >= 200) { pkt_satir_yaz($hedef, $t, $kolonlar, $tampon); $tampon = []; }
            }
            if ($tampon) pkt_satir_yaz($hedef, $t, $kolonlar, $tampon);
        }
        $hedef->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
        return ['tamam' => false, 'tablolar' => $sonuc, 'hata' => $e->getMessage()];
    }

    // Doğrulama: iki taraf birebir aynı mı
    $kOzet = pkt_ozetler($kaynak, $tablolar);
    $hOzet = pkt_ozetler($hedef, $tablolar);
    $tamam = true;
    foreach ($tablolar as $t) {
        $esit = $kOzet[$t] === $hOzet[$t];
        $tamam = $tamam && $esit;
        $sonuc[$t] = ['kaynak' => $kOzet[$t]['satir'], 'hedef' => $hOzet[$t]['satir'], 'esit' => $esit];
    }
    if (!$tamam) {
        return ['tamam' => false, 'tablolar' => $sonuc,
                'hata' => 'Kopya kaynakla birebir tutmadı (kopyalama sırasında kaynağa kayıt girilmiş olabilir). Tekrar kopyalayın.'];
    }
    pkt_isaret_yaz($hedef, pkt_kaynak_db(), $hedefDb, $kOzet, $kullanici);
    return ['tamam' => true, 'tablolar' => $sonuc, 'hata' => null];
}

/** Eski kopyaları (CRM veritabanındaki prekast_ tablolarını) siler. @return string[] silinen tablolar */
function pkt_kaynak_sil(PDO $kaynak, array $tablolar): array
{
    $silinen = [];
    foreach ($tablolar as $t) {
        if (!str_starts_with($t, 'prekast_') || $t === PKT_ISARET || !preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;
        $kaynak->exec("DROP TABLE IF EXISTS `$t`");
        $silinen[] = $t;
    }
    return $silinen;
}
