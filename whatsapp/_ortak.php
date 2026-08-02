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

    $pdo->prepare("INSERT INTO mesaj_kuyrugu (kaynak, grup_adi, gonderen, ham_metin, medya_url, mesaj_hash)
                   VALUES (?,?,?,?,?,?)")
        ->execute([$kaynak, trim((string)($m['grup'] ?? '')) ?: null, $gonderen ?: null,
                   $metin, trim((string)($m['medya_url'] ?? '')) ?: null, $hash]);

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
function mesaj_ai_ayikla(PDO $pdo, string $metin): array
{
    if (!function_exists('ai_call')) {
        require_once __DIR__ . '/ai_call.php';
    }
    $t = mesaj_tanimlar($pdo);

    $liste = function (array $rows, string $alan = 'ad') {
        return implode(' | ', array_map(fn($r) => $r['id'] . '=' . ($r['kod'] ?? '') . ($r['kod'] ?? '' ? ' ' : '') . $r[$alan], $rows));
    };

    $system = "Sen bir şantiye saha asistanısın. Sana WhatsApp grubundan gelen serbest metin verilir; "
        . "içindeki BETON SEVKİYATI ve SAHA OLAYLARINI çıkarıp SADECE JSON döndürürsün. Açıklama yazma, sadece JSON.\n\n"
        . "Format:\n{\"kayitlar\":[{\"tip\":\"alis|iade\",\"irsaliye_no\":\"\",\"tarih\":\"YYYY-AA-GG\","
        . "\"arac_plaka\":\"\",\"miktar\":0,\"tedarikci_id\":null,\"beton_sinifi_id\":null,\"proje_id\":null,"
        . "\"kivam_sinifi_id\":null,\"aciklama\":\"\",\"guven\":0.0}],\n"
        . " \"olaylar\":[{\"tur\":\"personel_giris|personel_cikis|yetki|arac|is|diger\",\"kisi\":\"\",\"firma\":\"\","
        . "\"yetkili\":\"\",\"arac_plaka\":\"\",\"tarih\":\"YYYY-AA-GG\",\"saat_bas\":\"HH:MM\",\"saat_bit\":\"HH:MM\","
        . "\"sure_saat\":null,\"lokasyon\":\"\",\"aciklama\":\"\",\"guven\":0.0}]}\n\n"
        . "SEVKİYAT kuralları:\n"
        . "- Bir mesajda birden fazla sevkiyat olabilir; her biri ayrı kayıt.\n"
        . "- Sevkiyat yoksa \"kayitlar\":[] döndür.\n"
        . "- ID alanlarını AŞAĞIDAKİ listelerden eşleştir; emin değilsen null bırak (UYDURMA).\n"
        . "- miktar m³ cinsinden sayı (virgül yerine nokta).\n\n"
        . "OLAY kuralları (tür seçimi):\n"
        . "- personel_giris / personel_cikis: birinin sahaya girmesi/çıkması. kisi = kişi adı, firma = bağlı olduğu firma/taşeron.\n"
        . "- yetki: birine izin/onay/yetki verilmesi (ör. 'X'e giriş izni verildi'). yetkili = izni VEREN, kisi = izin ALAN.\n"
        . "- arac: araç/iş makinesi sahada çalışması. arac_plaka, saat_bas, saat_bit, sure_saat (saat cinsinden ondalık).\n"
        . "- is: imalat/iş bildirimi (döküm başladı, kalıp söküldü vb.).\n"
        . "- diger: sahayla ilgili ama yukarıdakilere girmeyen bilgi.\n"
        . "- Sahayla ilgisi olmayan sohbet mesajlarında \"olaylar\":[] döndür.\n"
        . "- saat_bas/saat_bit yoksa null; sadece süre yazıyorsa sure_saat doldur.\n"
        . "- lokasyon: blok/kot/parsel gibi yer bilgisi (ör. 'A blok +3.20').\n\n"
        . "GENEL:\n"
        . "- tarih belirtilmemişse bugünü kullan: " . date('Y-m-d') . "\n"
        . "- guven: 0..1 arası, çıkardığın bilgiye ne kadar güvendiğin. Tahmin ettiysen düşük ver.\n"
        . "- Kişi/firma adlarını mesajda yazıldığı gibi bırak, düzeltme.\n\n"
        . "TEDARİKÇİLER: "   . $liste($t['tedarikciler']) . "\n"
        . "BETON SINIFLARI: " . $liste($t['beton']) . "\n"
        . "PROJELER: "        . $liste($t['projeler']) . "\n"
        . "KIVAM: "           . $liste($t['kivam']);

    $r = ai_call($system, [['type' => 'text', 'text' => $metin]], 2000);
    if (empty($r['ok'])) {
        return ['ok' => false, 'msg' => $r['msg'] ?? 'AI çağrısı başarısız'];
    }

    $ham = trim((string)($r['text'] ?? ''));
    // Model bazen ```json ... ``` sarar
    if (preg_match('/\{.*\}/s', $ham, $mm)) $ham = $mm[0];
    $j = json_decode($ham, true);
    if (!is_array($j) || (!isset($j['kayitlar']) && !isset($j['olaylar']))) {
        return ['ok' => false, 'msg' => 'AI yanıtı çözümlenemedi: ' . mb_substr($ham, 0, 180)];
    }
    return [
        'ok'       => true,
        'kayitlar' => is_array($j['kayitlar'] ?? null) ? $j['kayitlar'] : [],
        'olaylar'  => is_array($j['olaylar']  ?? null) ? $j['olaylar']  : [],
    ];
}

/** Saha olayları tablosu (analiz için). */
function saha_semasi_kur(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS saha_olaylari (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        mesaj_id    INT NOT NULL,
        tur         ENUM('personel_giris','personel_cikis','yetki','arac','is','diger') NOT NULL DEFAULT 'diger',
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
        KEY idx_mesaj (mesaj_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Geçerli olay türleri. */
function saha_turler(): array
{
    return ['personel_giris','personel_cikis','yetki','arac','is','diger'];
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
        (mesaj_id,tur,kisi,firma,yetkili,arac_plaka,tarih,saat_bas,saat_bit,sure_saat,lokasyon,aciklama,guven)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $n = 0;
    foreach ($olaylar as $o) {
        if (!is_array($o)) continue;
        $tur = in_array($o['tur'] ?? '', $turler, true) ? $o['tur'] : 'diger';
        $sure = $o['sure_saat'] ?? null;
        $sure = ($sure === null || $sure === '') ? null : round((float)str_replace(',', '.', (string)$sure), 2);
        $guven = $o['guven'] ?? null;
        $guven = ($guven === null || $guven === '') ? null : max(0, min(1, (float)$guven));

        $st->execute([
            $mesajId, $tur,
            $kes($o['kisi'] ?? null, 150), $kes($o['firma'] ?? null, 150), $kes($o['yetkili'] ?? null, 150),
            $kes(isset($o['arac_plaka']) ? strtoupper((string)$o['arac_plaka']) : null, 30),
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
    $s = $pdo->prepare("SELECT ham_metin FROM mesaj_kuyrugu WHERE id=?");
    $s->execute([$id]);
    $metin = $s->fetchColumn();
    if ($metin === false) return ['ok' => false, 'msg' => 'Mesaj bulunamadı'];

    $r = mesaj_ai_ayikla($pdo, (string)$metin);
    if (!$r['ok']) {
        $pdo->prepare("UPDATE mesaj_kuyrugu SET ai_durum='hata', ai_hata=? WHERE id=?")
            ->execute([mb_substr($r['msg'] ?? 'hata', 0, 500), $id]);
        return $r;
    }
    $pdo->prepare("UPDATE mesaj_kuyrugu SET ai_durum='islendi', ai_hata=NULL, ai_json=? WHERE id=?")
        ->execute([json_encode($r['kayitlar'], JSON_UNESCAPED_UNICODE), $id]);

    // Saha olayları (personel giriş/çıkış, yetki, araç saati…) analiz için ayrı tabloya
    try { $r['olay_sayisi'] = saha_olay_kaydet($pdo, $id, $r['olaylar'] ?? []); }
    catch (Throwable $e) { $r['olay_sayisi'] = 0; }

    return $r;
}
