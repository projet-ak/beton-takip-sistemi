<?php
/**
 * mesaj.php — Gelen mesaj kuyruğu (WhatsApp / manuel) yardımcıları
 *
 * Akış:  kaynak (WhatsApp botu / elle yapıştırma) → api/mesaj_al.php → mesaj_kuyrugu
 *        → AI ayrıştırma (mesaj_ai_ayikla) → mesajlar.php onay ekranı → irsaliyeler
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

    $system = "Sen bir şantiye beton irsaliye asistanısın. Sana WhatsApp'tan gelen serbest metin verilir; "
        . "içindeki BETON SEVKİYAT bilgilerini çıkarıp SADECE JSON döndürürsün. Açıklama yazma, sadece JSON.\n\n"
        . "Format: {\"kayitlar\":[{\"tip\":\"alis|iade\",\"irsaliye_no\":\"\",\"tarih\":\"YYYY-AA-GG\","
        . "\"arac_plaka\":\"\",\"miktar\":0,\"tedarikci_id\":null,\"beton_sinifi_id\":null,\"proje_id\":null,"
        . "\"kivam_sinifi_id\":null,\"aciklama\":\"\",\"guven\":0.0}]}\n\n"
        . "Kurallar:\n"
        . "- Bir mesajda birden fazla sevkiyat olabilir; her biri ayrı kayıt.\n"
        . "- Sevkiyatla ilgisi olmayan mesajlarda {\"kayitlar\":[]} döndür.\n"
        . "- ID alanlarını AŞAĞIDAKİ listelerden eşleştir; emin değilsen null bırak (uydurma).\n"
        . "- miktar m³ cinsinden sayı (virgül yerine nokta).\n"
        . "- tarih belirtilmemişse bugünün tarihini kullan: " . date('Y-m-d') . "\n"
        . "- guven: 0..1 arası, çıkardığın bilgiye ne kadar güvendiğin.\n\n"
        . "TEDARİKÇİLER: "   . $liste($t['tedarikciler']) . "\n"
        . "BETON SINIFLARI: " . $liste($t['beton']) . "\n"
        . "PROJELER: "        . $liste($t['projeler']) . "\n"
        . "KIVAM: "           . $liste($t['kivam']);

    $r = ai_call($system, [['type' => 'text', 'text' => $metin]], 1500);
    if (empty($r['ok'])) {
        return ['ok' => false, 'msg' => $r['msg'] ?? 'AI çağrısı başarısız'];
    }

    $ham = trim((string)($r['text'] ?? ''));
    // Model bazen ```json ... ``` sarar
    if (preg_match('/\{.*\}/s', $ham, $mm)) $ham = $mm[0];
    $j = json_decode($ham, true);
    if (!is_array($j) || !isset($j['kayitlar']) || !is_array($j['kayitlar'])) {
        return ['ok' => false, 'msg' => 'AI yanıtı çözümlenemedi: ' . mb_substr($ham, 0, 180)];
    }
    return ['ok' => true, 'kayitlar' => $j['kayitlar']];
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
    return $r;
}
