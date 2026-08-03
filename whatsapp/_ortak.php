<?php
/**
 * _ortak.php — WhatsApp modülü ortak fonksiyonları (mesaj kuyruğu + saha olayları)
 *
 * Akış:  kaynak (WhatsApp botu / elle yapıştırma) → whatsapp/api/mesaj_al.php
 *        → mesaj_kuyrugu → AI ayrıştırma → whatsapp/mesajlar.php onay ekranı
 *        → irsaliyeler + saha_olaylari (whatsapp/saha_analiz.php'de raporlanır)
 *
 * Mesajlar ASLA doğrudan irsaliye olmaz; her kayıt insan onayından geçer.
 */

/** Kuyruk tablosunu garanti et (runtime migration). */
function mesaj_semasi_kur(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mesaj_kuyrugu (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        kaynak        VARCHAR(20)  NOT NULL DEFAULT 'whatsapp',
        grup_adi      VARCHAR(150) DEFAULT NULL,
        gonderen      VARCHAR(150) DEFAULT NULL,
        ham_metin     TEXT         NOT NULL,
        medya_url     VARCHAR(500) DEFAULT NULL,
        medya_json    TEXT         DEFAULT NULL,
        mesaj_hash    CHAR(64)     NOT NULL,
        ai_json       LONGTEXT     DEFAULT NULL,
        ai_durum      ENUM('bekliyor','islendi','hata') NOT NULL DEFAULT 'bekliyor',
        ai_hata       VARCHAR(500) DEFAULT NULL,
        durum         ENUM('bekliyor','onaylandi','reddedildi') NOT NULL DEFAULT 'bekliyor',
        irsaliye_id   INT          DEFAULT NULL,
        not_metni     VARCHAR(300) DEFAULT NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        islenen_at    DATETIME     DEFAULT NULL,
        islenen_by    INT          DEFAULT NULL,
        UNIQUE KEY uq_hash (mesaj_hash),
        KEY idx_durum (durum, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Mevcut kurulumlar için kademeli migration
    try { $pdo->exec("ALTER TABLE mesaj_kuyrugu ADD COLUMN medya_json TEXT DEFAULT NULL AFTER medya_url"); }
    catch (Throwable $e) { /* zaten var */ }
}

/** Mesaja ait tüm görsellerin listesi (medya_json varsa ondan, yoksa medya_url). */
function mesaj_gorseller(array $mesaj): array
{
    $liste = [];
    if (!empty($mesaj['medya_json'])) {
        $j = json_decode((string)$mesaj['medya_json'], true);
        if (is_array($j)) $liste = array_values(array_filter($j, 'is_string'));
    }
    if (!$liste && !empty($mesaj['medya_url'])) $liste = [(string)$mesaj['medya_url']];
    return $liste;
}

/** Aynı mesajın iki kez kuyruğa girmesini engelleyen anahtar. */
function mesaj_hash(string $kaynak, string $gonderen, string $metin, string $harici = ''): string
{
    if ($harici !== '') return hash('sha256', $kaynak . '|' . $harici);
    return hash('sha256', $kaynak . '|' . mb_strtolower(trim($gonderen)) . '|' . preg_replace('/\s+/u', ' ', mb_strtolower(trim($metin))));
}

/** Kuyruğa mesaj ekle. Mükerrer ise ['ok'=>true,'mukerrer'=>true] döner. */
function mesaj_kuyruga_ekle(PDO $pdo, array $m): array
{
    mesaj_semasi_kur($pdo);
    $metin = trim((string)($m['metin'] ?? ''));
    if ($metin === '' && empty($m['medya_url'])) {
        return ['ok' => false, 'msg' => 'Boş mesaj'];
    }
    $kaynak   = trim((string)($m['kaynak']   ?? 'whatsapp')) ?: 'whatsapp';
    $gonderen = trim((string)($m['gonderen'] ?? ''));
    $hash     = mesaj_hash($kaynak, $gonderen, $metin, trim((string)($m['mesaj_id'] ?? '')));

    $var = $pdo->prepare("SELECT id FROM mesaj_kuyrugu WHERE mesaj_hash = ? LIMIT 1");
    $var->execute([$hash]);
    if ($mevcut = $var->fetchColumn()) {
        return ['ok' => true, 'mukerrer' => true, 'id' => (int)$mevcut];
    }

    // Birden fazla görsel gelebilir: ilki medya_url'de, tamamı medya_json'da
    $gorseller = [];
    if (!empty($m['medya']) && is_array($m['medya'])) $gorseller = array_values(array_filter($m['medya'], 'is_string'));
    elseif (trim((string)($m['medya_url'] ?? '')) !== '') $gorseller = [trim((string)$m['medya_url'])];

    $pdo->prepare("INSERT INTO mesaj_kuyrugu (kaynak, grup_adi, gonderen, ham_metin, medya_url, medya_json, mesaj_hash)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$kaynak, trim((string)($m['grup'] ?? '')) ?: null, $gonderen ?: null,
                   $metin, $gorseller[0] ?? null,
                   $gorseller ? json_encode($gorseller, JSON_UNESCAPED_UNICODE) : null, $hash]);

    return ['ok' => true, 'mukerrer' => false, 'id' => (int)$pdo->lastInsertId()];
}

/**
 * Gelen webhook gövdesini sade mesaj dizisine çevirir.
 * Üç biçimi destekler: Meta Cloud API (entry/changes/messages),
 * toplu {"mesajlar":[...]}, ve tek mesaj nesnesi.
 */
function mesaj_webhook_coz(array $body): array
{
    if (isset($body['entry']) && is_array($body['entry'])) {
        $out = [];
        foreach ($body['entry'] as $entry) {
            foreach (($entry['changes'] ?? []) as $ch) {
                $val   = $ch['value'] ?? [];
                $adlar = [];
                foreach (($val['contacts'] ?? []) as $c) {
                    $adlar[(string)($c['wa_id'] ?? '')] = (string)($c['profile']['name'] ?? '');
                }
                foreach (($val['messages'] ?? []) as $msg) {
                    $from = (string)($msg['from'] ?? '');
                    $out[] = [
                        'kaynak'   => 'whatsapp',
                        'grup'     => $val['metadata']['display_phone_number'] ?? null,
                        'gonderen' => ($adlar[$from] ?? '') ?: $from,
                        'metin'    => (string)($msg['text']['body'] ?? ($msg['caption'] ?? '')),
                        'mesaj_id' => (string)($msg['id'] ?? ''),
                    ];
                }
            }
        }
        return $out;
    }
    if (isset($body['mesajlar']) && is_array($body['mesajlar'])) {
        return array_values(array_filter($body['mesajlar'], 'is_array'));
    }
    return [$body];
}

/** AI'ya verilecek tanım listeleri (eşleştirme için). */
function mesaj_tanimlar(PDO $pdo): array
{
    $al = function (string $sql) use ($pdo) {
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    };
    return [
        'tedarikciler' => $al("SELECT id, ad FROM tedarikciler ORDER BY ad"),
        'beton'        => $al("SELECT id, ad FROM beton_siniflari WHERE aktif=1 ORDER BY ad"),
        'projeler'     => $al("SELECT id, kod, ad FROM projeler ORDER BY kod"),
        'kivam'        => $al("SELECT id, ad FROM kivam_siniflari WHERE aktif=1 ORDER BY ad"),
    ];
}

/**
 * Mesaj metnini AI ile ayrıştır → irsaliye adayı kayıtlar.
 * @return array{ok:bool, kayitlar?:array, msg?:string}
 */
function mesaj_ai_ayikla(PDO $pdo, string $metin, array $gorseller = []): array
{
    if (!function_exists('ai_call')) {
        require_once __DIR__ . '/../includes/ai_call.php';
    }
    $t = mesaj_tanimlar($pdo);

    $liste = function (array $rows, string $alan = 'ad') {
        return implode(' | ', array_map(fn($r) => $r['id'] . '=' . ($r['kod'] ?? '') . ($r['kod'] ?? '' ? ' ' : '') . $r[$alan], $rows));
    };

    $system = "Sen bir şantiye giriş-kontrol asistanısın. WhatsApp saha grubundan gelen mesajı okur, "
        . "içindeki ARAÇ HAREKETLERİNİ ve EVRAK/GÖRSEL bildirimlerini çıkarıp SADECE JSON döndürürsün. "
        . "Açıklama yazma, sadece JSON.\n\n"
        . "Format:\n"
        . "{\"olaylar\":[{\"tur\":\"arac_giris|arac_cikis|arac|personel_giris|personel_cikis|yetki|is|diger\","
        . "\"arac_plaka\":\"\",\"arac_cinsi\":\"\",\"firma\":\"\",\"kisi\":\"\",\"yetkili\":\"\","
        . "\"tarih\":\"YYYY-AA-GG\",\"saat_bas\":\"HH:MM\",\"saat_bit\":\"HH:MM\",\"sure_saat\":null,"
        . "\"lokasyon\":\"\",\"aciklama\":\"\",\"guven\":0.0}],\n"
        . " \"evraklar\":[{\"tur\":\"kantar|irsaliye|tutanak|fatura|puantaj|ruhsat|foto|diger\",\"baslik\":\"\","
        . "\"belge_no\":\"\",\"firma\":\"\",\"arac_plaka\":\"\",\"tarih\":\"YYYY-AA-GG\","
        . "\"net_kg\":null,\"onaylayan\":\"\",\"aciklama\":\"\",\"guven\":0.0}]}\n\n"
        . "ARAÇ kuralları (ÖNCELİKLİ KONU):\n"
        . "- arac_giris: araç/kamyon/mikser/iş makinesi sahaya GİRDİ. saat_bas = giriş saati.\n"
        . "- arac_cikis: araç sahadan ÇIKTI. saat_bit = çıkış saati.\n"
        . "- arac: giriş ve çıkış AYNI mesajda verilmişse tek kayıt (saat_bas + saat_bit birlikte).\n"
        . "- arac_plaka: Türk plakası biçimine normalize et (ör. '34 abc 123' → '34ABC123'). Emin değilsen boş bırak.\n"
        . "- arac_cinsi: mikser, pompa, kamyon, ekskavatör, forklift vb. mesajda geçiyorsa yaz.\n"
        . "- sure_saat: süre açıkça yazılmışsa (ör. '3 saat bekledi') ondalık sayı olarak yaz; yoksa null.\n"
        . "- Aynı mesajda birden fazla araç varsa her biri AYRI kayıt.\n\n"
        . "EVRAK kuralları:\n"
        . "- Mesajda bir belge/görsel paylaşıldığı belirtiliyorsa (irsaliye fotoğrafı, tutanak, fatura,\n"
        . "  puantaj, ruhsat vb.) evrak kaydı çıkar. Sadece metin bahsi de sayılır.\n"
        . "- belge_no: irsaliye/fatura numarası gibi belge üzerindeki numara.\n"
        . "- onaylayan: mesajda 'X onayladı', 'X uygundur dedi', 'Y'nin onayıyla' gibi ifade varsa o kişi.\n"
        . "- Evrak yoksa \"evraklar\":[] döndür.\n\n"
        . "GÖRSEL VARSA (çok önemli):\n"
        . "- Mesajla birlikte fotoğraf gelebilir. Fotoğrafı OKU ve içindeki bilgileri kullan.\n"
        . "- KANTAR FİŞİ (tartı fişi) tipik alanları: FİŞ NO, PLAKA, OPERATÖR, GİRİŞ TARİHİ,\n"
        . "  ÇIKIŞ TARİHİ, FİRMA, MALZEME, 1./2. TARTIM, NET. Bunları gördüğünde:\n"
        . "    • tur=\"kantar\" bir EVRAK kaydı çıkar (belge_no=FİŞ NO, firma=FİRMA, net_kg=NET kg sayısı).\n"
        . "    • AYRICA tur=\"arac\" bir OLAY çıkar: arac_plaka=PLAKA, saat_bas=GİRİŞ saati,\n"
        . "      saat_bit=ÇIKIŞ saati, tarih=GİRİŞ tarihi. Böylece aracın sahada kalma süresi hesaplanır.\n"
        . "- Araç fotoğrafında plaka okunuyorsa onu kullan; metindeki plakayla çelişirse FİŞ/fotoğraftaki esastır.\n"
        . "- Fotoğraftaki tarihler GG.AA.YYYY biçiminde olabilir; YYYY-AA-GG'ye çevir.\n"
        . "- Görsel bulanık/okunmuyorsa uydurma; ilgili alanı boş bırak ve guven'i düşür.\n\n"
        . "GENEL:\n"
        . "- Bu modül BETON İRSALİYESİ OLUŞTURMAZ; sadece araç ve evrak takibi yapar.\n"
        . "- Sahayla ilgisi olmayan sohbet mesajlarında iki listeyi de boş döndür.\n"
        . "- tarih belirtilmemişse bugünü kullan: " . date('Y-m-d') . "\n"
        . "- guven: 0..1 arası. Tahmin ettiysen DÜŞÜK ver, uydurma.\n"
        . "- Kişi/firma adlarını mesajda yazıldığı gibi bırak.";

    // Görselleri AI'ya ekle (kantar fişi / irsaliye fotoğrafı okunsun)
    $parts = [];
    $kok   = dirname(__DIR__);
    foreach (array_slice($gorseller, 0, 4) as $g) {          // en fazla 4 görsel
        $yol = (strpos($g, 'http') === 0) ? null : $kok . '/' . ltrim($g, '/');
        if ($yol === null || !is_file($yol) || filesize($yol) > 6 * 1024 * 1024) continue;
        $mime = function_exists('guess_mime') ? guess_mime($yol, $yol) : 'image/jpeg';
        if (strpos($mime, 'image/') !== 0) continue;
        $parts[] = ['type' => 'image', 'mime' => $mime, 'data' => base64_encode(file_get_contents($yol))];
    }
    $parts[] = ['type' => 'text', 'text' => $metin !== '' ? $metin : '(mesajda metin yok, yalnız görsel var)'];

    $r = ai_call($system, $parts, 2000);
    if (empty($r['ok'])) {
        return ['ok' => false, 'msg' => $r['msg'] ?? 'AI çağrısı başarısız'];
    }

    $ham = trim((string)($r['text'] ?? ''));
    // Model bazen ```json ... ``` sarar
    if (preg_match('/\{.*\}/s', $ham, $mm)) $ham = $mm[0];
    $j = json_decode($ham, true);
    if (!is_array($j) || (!isset($j['olaylar']) && !isset($j['evraklar']))) {
        return ['ok' => false, 'msg' => 'AI yanıtı çözümlenemedi: ' . mb_substr($ham, 0, 180)];
    }
    return [
        'ok'      => true,
        'olaylar' => is_array($j['olaylar']  ?? null) ? $j['olaylar']  : [],
        'evrak'   => is_array($j['evraklar'] ?? null) ? $j['evraklar'] : [],
    ];
}

/** Saha olayları tablosu (analiz için). */
function saha_semasi_kur(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS saha_olaylari (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        mesaj_id    INT NOT NULL,
        tur         ENUM('arac_giris','arac_cikis','arac','personel_giris','personel_cikis','yetki','is','diger') NOT NULL DEFAULT 'diger',
        arac_cinsi  VARCHAR(80)  DEFAULT NULL,
        kisi        VARCHAR(150) DEFAULT NULL,
        firma       VARCHAR(150) DEFAULT NULL,
        yetkili     VARCHAR(150) DEFAULT NULL,
        arac_plaka  VARCHAR(30)  DEFAULT NULL,
        tarih       DATE         DEFAULT NULL,
        saat_bas    TIME         DEFAULT NULL,
        saat_bit    TIME         DEFAULT NULL,
        sure_saat   DECIMAL(6,2) DEFAULT NULL,
        lokasyon    VARCHAR(150) DEFAULT NULL,
        aciklama    TEXT         DEFAULT NULL,
        guven       DECIMAL(3,2) DEFAULT NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tarih (tarih),
        KEY idx_tur (tur, tarih),
        KEY idx_plaka (arac_plaka, tarih),
        KEY idx_mesaj (mesaj_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Mevcut kurulumlar için kademeli migration (kolon/ENUM genişletme)
    try { $pdo->exec("ALTER TABLE saha_olaylari ADD COLUMN arac_cinsi VARCHAR(80) DEFAULT NULL AFTER tur"); }
    catch (Throwable $e) { /* zaten var */ }
    try { $pdo->exec("ALTER TABLE saha_olaylari MODIFY COLUMN tur
            ENUM('arac_giris','arac_cikis','arac','personel_giris','personel_cikis','yetki','is','diger')
            NOT NULL DEFAULT 'diger'"); }
    catch (Throwable $e) { /* zaten güncel */ }

    // ── Evrak/görsel takibi ───────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS saha_evrak (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        mesaj_id    INT NOT NULL,
        tur         ENUM('kantar','irsaliye','tutanak','fatura','puantaj','ruhsat','foto','diger') NOT NULL DEFAULT 'diger',
        net_kg      DECIMAL(10,2) DEFAULT NULL,
        baslik      VARCHAR(200) DEFAULT NULL,
        belge_no    VARCHAR(100) DEFAULT NULL,
        firma       VARCHAR(150) DEFAULT NULL,
        arac_plaka  VARCHAR(30)  DEFAULT NULL,
        tarih       DATE         DEFAULT NULL,
        dosya_url   VARCHAR(500) DEFAULT NULL,
        gonderen    VARCHAR(150) DEFAULT NULL,
        onaylayan   VARCHAR(150) DEFAULT NULL,
        onay_user   INT          DEFAULT NULL,
        onay_at     DATETIME     DEFAULT NULL,
        durum       ENUM('bekliyor','onaylandi','reddedildi') NOT NULL DEFAULT 'bekliyor',
        aciklama    TEXT         DEFAULT NULL,
        guven       DECIMAL(3,2) DEFAULT NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_durum (durum, tarih),
        KEY idx_tur (tur, tarih),
        KEY idx_mesaj (mesaj_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $pdo->exec("ALTER TABLE saha_evrak ADD COLUMN net_kg DECIMAL(10,2) DEFAULT NULL AFTER tarih"); }
    catch (Throwable $e) { /* zaten var */ }
    try { $pdo->exec("ALTER TABLE saha_evrak MODIFY COLUMN tur
            ENUM('kantar','irsaliye','tutanak','fatura','puantaj','ruhsat','foto','diger')
            NOT NULL DEFAULT 'diger'"); }
    catch (Throwable $e) { /* zaten güncel */ }
}

/** Geçerli evrak türleri. */
function evrak_turler(): array
{
    return ['kantar','irsaliye','tutanak','fatura','puantaj','ruhsat','foto','diger'];
}

/** Türk plakasını normalize et: "34 abc 123" → "34ABC123". Tanınmazsa boşluksuz büyük harf. */
function saha_plaka_norm($v): ?string
{
    $v = mb_strtoupper(trim((string)$v), 'UTF-8');
    if ($v === '') return null;
    $v = str_replace(['İ','I'], 'I', $v);
    $sade = preg_replace('/[^A-Z0-9]/', '', $v);
    return $sade !== '' ? mb_substr($sade, 0, 30) : null;
}

/** AI'dan çıkan evrakları kaydet (aynı mesajın eski BEKLEYEN evrakları silinir). */
function evrak_kaydet(PDO $pdo, int $mesajId, array $evraklar, ?string $medyaUrl = null, ?string $gonderen = null): int
{
    saha_semasi_kur($pdo);
    // Onaylanmış/reddedilmiş kayıtlara dokunma — yalnız bekleyenler yenilenir
    $pdo->prepare("DELETE FROM saha_evrak WHERE mesaj_id=? AND durum='bekliyor'")->execute([$mesajId]);
    if (!$evraklar) return 0;

    $turler = evrak_turler();
    $kes = fn($v, $n) => ($v === null || $v === '') ? null : mb_substr((string)$v, 0, $n);

    $st = $pdo->prepare("INSERT INTO saha_evrak
        (mesaj_id,tur,baslik,belge_no,firma,arac_plaka,tarih,net_kg,dosya_url,gonderen,onaylayan,aciklama,guven)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $n = 0;
    foreach ($evraklar as $e) {
        if (!is_array($e)) continue;
        $tur   = in_array($e['tur'] ?? '', $turler, true) ? $e['tur'] : 'diger';
        $guven = $e['guven'] ?? null;
        $guven = ($guven === null || $guven === '') ? null : max(0, min(1, (float)$guven));

        $st->execute([
            $mesajId, $tur,
            $kes($e['baslik'] ?? null, 200), $kes($e['belge_no'] ?? null, 100),
            $kes($e['firma'] ?? null, 150), saha_plaka_norm($e['arac_plaka'] ?? null),
            saha_tarih_norm($e['tarih'] ?? ''),
            (($e['net_kg'] ?? null) === null || $e['net_kg'] === '') ? null : round((float)str_replace(',', '.', (string)$e['net_kg']), 2),
            $kes($medyaUrl, 500), $kes($gonderen, 150),
            $kes($e['onaylayan'] ?? null, 150), $kes($e['aciklama'] ?? null, 2000), $guven,
        ]);
        $n++;
    }
    return $n;
}

/** Geçerli olay türleri. */
function saha_turler(): array
{
    return ['arac_giris','arac_cikis','arac','personel_giris','personel_cikis','yetki','is','diger'];
}

/** "8:30" / "08.30" → "08:30:00"; geçersizse null. */
function saha_saat_norm($v): ?string
{
    $v = trim((string)$v);
    return preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $v, $m)
        ? sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]) : null;
}

/** Yalnız gerçekten var olan YYYY-AA-GG tarihini kabul et. */
function saha_tarih_norm($v): ?string
{
    $v = trim((string)$v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $v : null;
}

/** AI'dan çıkan olayları kaydet (aynı mesajın eski olayları silinir → yeniden çözümleme güvenli). */
function saha_olay_kaydet(PDO $pdo, int $mesajId, array $olaylar): int
{
    saha_semasi_kur($pdo);
    $pdo->prepare("DELETE FROM saha_olaylari WHERE mesaj_id=?")->execute([$mesajId]);
    if (!$olaylar) return 0;

    $turler = saha_turler();
    $kes    = fn($v, $n) => ($v === null || $v === '') ? null : mb_substr((string)$v, 0, $n);
    $saat   = 'saha_saat_norm';
    $tarih  = 'saha_tarih_norm';

    $st = $pdo->prepare("INSERT INTO saha_olaylari
        (mesaj_id,tur,arac_cinsi,kisi,firma,yetkili,arac_plaka,tarih,saat_bas,saat_bit,sure_saat,lokasyon,aciklama,guven)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $n = 0;
    foreach ($olaylar as $o) {
        if (!is_array($o)) continue;
        $tur = in_array($o['tur'] ?? '', $turler, true) ? $o['tur'] : 'diger';
        $sure = $o['sure_saat'] ?? null;
        $sure = ($sure === null || $sure === '') ? null : round((float)str_replace(',', '.', (string)$sure), 2);
        $guven = $o['guven'] ?? null;
        $guven = ($guven === null || $guven === '') ? null : max(0, min(1, (float)$guven));

        $st->execute([
            $mesajId, $tur, $kes($o['arac_cinsi'] ?? null, 80),
            $kes($o['kisi'] ?? null, 150), $kes($o['firma'] ?? null, 150), $kes($o['yetkili'] ?? null, 150),
            saha_plaka_norm($o['arac_plaka'] ?? null),
            $tarih($o['tarih'] ?? ''), $saat($o['saat_bas'] ?? ''), $saat($o['saat_bit'] ?? ''),
            $sure, $kes($o['lokasyon'] ?? null, 150), $kes($o['aciklama'] ?? null, 2000), $guven,
        ]);
        $n++;
    }
    return $n;
}

/** Kuyruk satırını AI'dan geçir ve sonucu kaydet. */
function mesaj_isle(PDO $pdo, int $id): array
{
    $s = $pdo->prepare("SELECT ham_metin, medya_url, medya_json, gonderen FROM mesaj_kuyrugu WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'msg' => 'Mesaj bulunamadı'];

    $gorseller = mesaj_gorseller($row);
    $r = mesaj_ai_ayikla($pdo, (string)$row['ham_metin'], $gorseller);
    if (!$r['ok']) {
        $pdo->prepare("UPDATE mesaj_kuyrugu SET ai_durum='hata', ai_hata=? WHERE id=?")
            ->execute([mb_substr($r['msg'] ?? 'hata', 0, 500), $id]);
        return $r;
    }
    $pdo->prepare("UPDATE mesaj_kuyrugu SET ai_durum='islendi', ai_hata=NULL, ai_json=? WHERE id=?")
        ->execute([json_encode(['olaylar' => $r['olaylar'], 'evrak' => $r['evrak']], JSON_UNESCAPED_UNICODE), $id]);

    // Araç/saha hareketleri
    try { $r['olay_sayisi'] = saha_olay_kaydet($pdo, $id, $r['olaylar'] ?? []); }
    catch (Throwable $e) { $r['olay_sayisi'] = 0; }

    // Evrak/görsel bildirimleri (mesajın medyası varsa ekli olarak taşınır)
    try { $r['evrak_sayisi'] = evrak_kaydet($pdo, $id, $r['evrak'] ?? [], $row['medya_url'] ?? null, $row['gonderen'] ?? null); }
    catch (Throwable $e) { $r['evrak_sayisi'] = 0; }

    return $r;
}
