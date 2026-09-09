<?php
/**
 * it/_ortak.php — IT Envanter modülü ortak çekirdek
 *
 * Tablolar (`it_` önekli, ayrı DB $pdoIt):
 *   it_cihazlar   — her satır bir varlık (bilgisayar, telefon, yazıcı, lisans…); envanter no benzersiz
 *   it_hareketler — cihazın yaşam günlüğü: giriş, zimmet, iade, servis, arıza, hurda, not
 *   it_belgeler   — cihaz başına sınırsız fotoğraf/fatura/garanti belgesi (uploads/it_envanter/{id}/)
 * Görseller DOSYA olarak tutulur; DB'de yalnız göreli URL. Kayıt silinmez, `hurda` durumuna alınır.
 */

/** Kategoriler: anahtar => [ad, ikon] */
const IT_KATEGORI = [
    'bilgisayar' => ['Masaüstü Bilgisayar', 'bi-pc-display'],
    'laptop'     => ['Dizüstü Bilgisayar',  'bi-laptop'],
    'monitor'    => ['Monitör',              'bi-display'],
    'yazici'     => ['Yazıcı / Tarayıcı',    'bi-printer'],
    'telefon'    => ['Telefon',              'bi-phone'],
    'tablet'     => ['Tablet',               'bi-tablet'],
    'ag'         => ['Ağ Cihazı',            'bi-router'],
    'sunucu'     => ['Sunucu / Depolama',    'bi-hdd-rack'],
    'yazilim'    => ['Yazılım / Lisans',     'bi-key'],
    'aksesuar'   => ['Aksesuar',             'bi-mouse'],
    'diger'      => ['Diğer',                'bi-box'],
];

/** Durumlar: anahtar => [ad, bootstrap rengi, ikon] */
const IT_DURUM = [
    'aktif'    => ['Kullanımda (zimmetli)', 'success',   'bi-person-check'],
    'depoda'   => ['Depoda / Boşta',        'secondary', 'bi-box-seam'],
    'serviste' => ['Serviste',              'info',      'bi-wrench'],
    'arizali'  => ['Arızalı',               'danger',    'bi-exclamation-triangle'],
    'hurda'    => ['Hurda / Kullanım dışı', 'dark',      'bi-trash'],
];

/** Hareket türleri: anahtar => [ad, renk, ikon] */
const IT_HAREKET = [
    'giris'   => ['Envantere giriş',   'primary',   'bi-plus-circle'],
    'zimmet'  => ['Zimmet verildi',    'success',   'bi-person-check'],
    'iade'    => ['Zimmet iadesi',     'secondary', 'bi-arrow-return-left'],
    'servis'  => ['Servise gönderildi','info',      'bi-wrench'],
    'ariza'   => ['Arıza bildirimi',   'danger',    'bi-exclamation-triangle'],
    'donus'   => ['Servisten döndü',   'success',   'bi-check2-circle'],
    'hurda'   => ['Hurdaya ayrıldı',   'dark',      'bi-trash'],
    'not'     => ['Not',               'warning',   'bi-chat-left-text'],
    'guncelleme' => ['Kayıt güncellendi', 'light', 'bi-pencil'],
];

/** Şema (runtime + kurulum). İdempotent. */
function it_semasi_kur(PDO $pdo): void
{
    static $yapildi = false;
    if ($yapildi) return;
    $yapildi = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_cihazlar (
        id INT AUTO_INCREMENT PRIMARY KEY,
        envanter_no VARCHAR(20) NOT NULL,
        kategori VARCHAR(20) NOT NULL DEFAULT 'diger',
        ad VARCHAR(150) NOT NULL COMMENT 'cihaz adı / tanımı',
        marka VARCHAR(80) NULL, model VARCHAR(120) NULL, seri_no VARCHAR(120) NULL,
        durum VARCHAR(20) NOT NULL DEFAULT 'depoda',
        zimmetli VARCHAR(120) NULL COMMENT 'zimmetli kişi', departman VARCHAR(80) NULL, lokasyon VARCHAR(120) NULL,
        zimmet_tarihi DATE NULL,
        alis_tarihi DATE NULL, garanti_bitis DATE NULL, fiyat DECIMAL(12,2) NULL,
        tedarikci VARCHAR(120) NULL, fatura_no VARCHAR(60) NULL,
        ip_adresi VARCHAR(45) NULL, mac_adresi VARCHAR(40) NULL, isletim_sistemi VARCHAR(80) NULL,
        ozellikler VARCHAR(255) NULL COMMENT 'işlemci / RAM / disk vb.',
        lisans_anahtari VARCHAR(160) NULL, lisans_adet INT NULL,
        foto_url VARCHAR(255) NULL COMMENT 'en yeni fotoğraf (it_belgeler)',
        notlar TEXT NULL,
        olusturan VARCHAR(80) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_env (envanter_no),
        KEY ix_kat (kategori), KEY ix_durum (durum), KEY ix_zimmet (zimmetli), KEY ix_seri (seri_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_hareketler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cihaz_id INT NOT NULL,
        tur VARCHAR(20) NOT NULL,
        tarih DATE NOT NULL,
        kisi VARCHAR(120) NULL COMMENT 'zimmet alan / teslim eden / servis firması',
        aciklama TEXT NULL,
        kullanici VARCHAR(80) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_cihaz (cihaz_id), KEY ix_tarih (tarih), KEY ix_tur (tur)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_belgeler (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cihaz_id INT NOT NULL,
        dosya_url VARCHAR(255) NOT NULL,
        ad VARCHAR(255) NULL, mime VARCHAR(80) NULL, boyut INT NULL,
        kullanici VARCHAR(80) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY ix_cihaz (cihaz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Türkçe duyarsız normalize (arama/karşılaştırma). */
function it_norm(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return str_replace(['İ','I','ı','Ş','Ğ','Ü','Ö','Ç'], ['I','I','I','S','G','U','O','C'], $s);
}

/** Yeni envanter no: IT-00001 (en büyük sıra + 1). */
function it_envanter_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT envanter_no FROM it_cihazlar WHERE envanter_no LIKE 'IT-%'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
        if (preg_match('/^IT-(\d+)$/', (string)$n, $m)) $max = max($max, (int)$m[1]);
    }
    return 'IT-' . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

function it_kategoriAd(string $k): string { return IT_KATEGORI[$k][0] ?? $k; }
function it_kategoriIkon(string $k): string { return IT_KATEGORI[$k][1] ?? 'bi-box'; }
function it_durumAd(string $d): string { return IT_DURUM[$d][0] ?? $d; }
function it_durumRenk(string $d): string { return IT_DURUM[$d][1] ?? 'secondary'; }
function it_durumBadge(string $d): string
{
    $x = IT_DURUM[$d] ?? [$d, 'secondary', 'bi-question-circle'];
    return '<span class="badge bg-' . $x[1] . '"><i class="bi ' . $x[2] . ' me-1"></i>' . htmlspecialchars($x[0]) . '</span>';
}

/** Garantinin bitmesine kaç gün var (negatif = geçmiş, null = tanımsız). */
function it_garanti_kalan(?string $bitis): ?int
{
    if (!$bitis) return null;
    return (int)floor((strtotime($bitis) - strtotime(date('Y-m-d'))) / 86400);
}

/** Form tarih alanı → Y-m-d veya null. */
function it_tarih(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/** Form sayı alanı (Türkçe virgül/binlik) → float|null. */
function it_sayi(?string $s): ?float
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace([' ', 'TL', '₺'], '', $s);
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : null;
}

/** Hareket kaydı ekler (cihaz yaşam günlüğü). */
function it_hareket_ekle(PDO $pdo, int $cihazId, string $tur, ?string $kisi, ?string $aciklama, ?string $tarih = null): void
{
    if (!isset(IT_HAREKET[$tur])) $tur = 'not';
    $kul = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? null;
    $pdo->prepare("INSERT INTO it_hareketler (cihaz_id, tur, tarih, kisi, aciklama, kullanici) VALUES (?,?,?,?,?,?)")
        ->execute([$cihazId, $tur, $tarih ?: date('Y-m-d'), $kisi ?: null, $aciklama ?: null, $kul]);
}

/**
 * Liste filtresi: [$whereSql, $params, $etkinFiltreler]. Serbest arama PHP tarafında değil
 * SQL LIKE ile — ad/marka/model/seri/envanter no/zimmetli/lokasyon alanlarında.
 */
function it_filtre(array $g): array
{
    $w = []; $p = []; $etkin = [];
    if (!empty($g['kategori']) && isset(IT_KATEGORI[$g['kategori']])) { $w[] = 'kategori=?'; $p[] = $g['kategori']; $etkin['kategori'] = $g['kategori']; }
    if (!empty($g['durum']) && isset(IT_DURUM[$g['durum']]))          { $w[] = 'durum=?';    $p[] = $g['durum'];    $etkin['durum'] = $g['durum']; }
    elseif (($g['durum'] ?? '') === '') { $w[] = "durum <> 'hurda'"; }   // varsayılan: hurdalar gizli
    foreach (['zimmetli', 'departman', 'lokasyon', 'marka'] as $k) {
        if (!empty($g[$k])) { $w[] = "$k=?"; $p[] = $g[$k]; $etkin[$k] = $g[$k]; }
    }
    if (!empty($g['garanti'])) {
        if ($g['garanti'] === 'bitiyor')  { $w[] = 'garanti_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)'; }
        if ($g['garanti'] === 'bitti')    { $w[] = 'garanti_bitis < CURDATE()'; }
        if ($g['garanti'] === 'devam')    { $w[] = 'garanti_bitis >= CURDATE()'; }
        $etkin['garanti'] = $g['garanti'];
    }
    if (!empty($g['q'])) {
        $q = '%' . trim($g['q']) . '%';
        $w[] = '(envanter_no LIKE ? OR ad LIKE ? OR marka LIKE ? OR model LIKE ? OR seri_no LIKE ? OR zimmetli LIKE ? OR lokasyon LIKE ? OR ip_adresi LIKE ? OR notlar LIKE ?)';
        for ($i = 0; $i < 9; $i++) $p[] = $q;
        $etkin['q'] = trim($g['q']);
    }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p, $etkin];
}

/** Bir sütunun dolu, benzersiz değerleri (filtre menüleri). Sütun whitelist'lidir. */
function it_secenekler(PDO $pdo, string $sutun): array
{
    if (!in_array($sutun, ['zimmetli', 'departman', 'lokasyon', 'marka', 'tedarikci'], true)) return [];
    try {
        return $pdo->query("SELECT DISTINCT $sutun FROM it_cihazlar WHERE $sutun IS NOT NULL AND $sutun<>'' ORDER BY $sutun")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

/** Dashboard / rapor özeti. */
function it_ozet(PDO $pdo): array
{
    $o = ['toplam'=>0,'aktif'=>0,'depoda'=>0,'serviste'=>0,'arizali'=>0,'hurda'=>0,'mali'=>0.0,'garantiBitiyor'=>0,'garantiBitti'=>0,'zimmetliKisi'=>0,'lisans'=>0];
    try {
        $r = $pdo->query("SELECT COUNT(*) toplam,
                SUM(durum='aktif') aktif, SUM(durum='depoda') depoda, SUM(durum='serviste') serviste,
                SUM(durum='arizali') arizali, SUM(durum='hurda') hurda,
                COALESCE(SUM(CASE WHEN durum<>'hurda' THEN fiyat END),0) mali,
                SUM(durum<>'hurda' AND garanti_bitis BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)) garantiBitiyor,
                SUM(durum<>'hurda' AND garanti_bitis < CURDATE()) garantiBitti,
                COUNT(DISTINCT CASE WHEN durum='aktif' AND zimmetli<>'' THEN zimmetli END) zimmetliKisi,
                SUM(kategori='yazilim' AND durum<>'hurda') lisans
            FROM it_cihazlar")->fetch();
        foreach ($o as $k => $v) $o[$k] = is_float($v) ? (float)($r[$k] ?? 0) : (int)($r[$k] ?? 0);
    } catch (Throwable $e) { /* tablo yok */ }
    return $o;
}

/** Cihazın belgeleri (en yeni önce). */
function it_belgeler(PDO $pdo, int $cihazId): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM it_belgeler WHERE cihaz_id=? ORDER BY id DESC");
        $st->execute([$cihazId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Belge/fotoğraf yükler → `uploads/it_envanter/{cihaz_id}/`. Önceki belgeler SİLİNMEZ.
 * Görselse cihazın `foto_url` alanı en yeni fotoğrafı gösterir (liste küçük resmi).
 * @return array{0:bool,1:string}
 */
function it_belge_yukle(PDO $pdo, int $cihazId, array $f, ?string $kullanici = null): array
{
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return [false, 'Dosya seçilmedi.'];
    $ad   = (string)($f['name'] ?? '');
    $mime = guess_mime($f['tmp_name'], $ad);
    if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp','image/heic'], true))
        return [false, h($ad) . ': desteklenmeyen tür (PDF, JPG, PNG, WEBP) — ' . $mime];
    if ((int)($f['size'] ?? 0) > 15 * 1024 * 1024) return [false, h($ad) . ': dosya 15 MB sınırını aşıyor.'];

    $dir = __DIR__ . '/../uploads/it_envanter/' . $cihazId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [false, 'Klasör oluşturulamadı: uploads/it_envanter/' . $cihazId];
    $ext  = strtolower(pathinfo($ad, PATHINFO_EXTENSION)) ?: 'bin';
    $yeni = 'belge_' . date('Ymd_His') . '_' . substr(md5($ad . microtime()), 0, 6) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $yeni)) return [false, h($ad) . ': dosya diske yazılamadı.'];

    $url = 'uploads/it_envanter/' . $cihazId . '/' . $yeni;
    $pdo->prepare("INSERT INTO it_belgeler (cihaz_id, dosya_url, ad, mime, boyut, kullanici) VALUES (?,?,?,?,?,?)")
        ->execute([$cihazId, $url, mb_substr($ad, 0, 255), $mime, (int)($f['size'] ?? 0), $kullanici]);
    if (str_starts_with($mime, 'image/'))
        $pdo->prepare("UPDATE it_cihazlar SET foto_url=? WHERE id=?")->execute([$url, $cihazId]);
    return [true, h($ad) . ' yüklendi.'];
}

/** Belgeyi siler (kayıt + disk); foto_url kalan en yeni görsele döner. */
function it_belge_sil(PDO $pdo, int $belgeId): bool
{
    $st = $pdo->prepare("SELECT * FROM it_belgeler WHERE id=?");
    $st->execute([$belgeId]);
    $b = $st->fetch();
    if (!$b) return false;
    $pdo->prepare("DELETE FROM it_belgeler WHERE id=?")->execute([$belgeId]);
    $v = $pdo->prepare("SELECT COUNT(*) FROM it_belgeler WHERE dosya_url=?");
    $v->execute([$b['dosya_url']]);
    if (!(int)$v->fetchColumn() && str_starts_with((string)$b['dosya_url'], 'uploads/it_envanter/'))
        @unlink(__DIR__ . '/../' . $b['dosya_url']);
    $son = $pdo->prepare("SELECT dosya_url FROM it_belgeler WHERE cihaz_id=? AND mime LIKE 'image/%' ORDER BY id DESC LIMIT 1");
    $son->execute([(int)$b['cihaz_id']]);
    $pdo->prepare("UPDATE it_cihazlar SET foto_url=? WHERE id=?")->execute([$son->fetchColumn() ?: null, (int)$b['cihaz_id']]);
    return true;
}

/** Çoklu $_FILES dizisini tek tek dosya dizilerine ayırır. */
function it_dosya_listesi(array $f): array
{
    if (!$f) return [];
    return is_array($f['name'] ?? null)
        ? array_map(fn($i) => ['name'=>$f['name'][$i], 'tmp_name'=>$f['tmp_name'][$i], 'error'=>$f['error'][$i], 'size'=>$f['size'][$i]], array_keys($f['name']))
        : [$f];
}
