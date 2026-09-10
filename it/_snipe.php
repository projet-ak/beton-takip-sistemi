<?php
/**
 * it/_snipe.php — SNIPE-IT KÖPRÜSÜ (sn_*): cihaz FOTOĞRAFLARINI ve zimmet TUTANAKLARINI
 * Snipe-IT'den REST API ile çekip envanterimizdeki cihaz kartlarına bağlar.
 *
 * Neden API? Snipe-IT'nin Excel dışa aktarımında görsel/belge YOKTUR; dosyalar
 *   · fotoğraf → `public/uploads/assets/` (herkese açık disk)
 *   · belge    → `storage/private_uploads/assets/` (yalnız uygulama üzerinden)
 * yollarında durur ve dosya↔cihaz bağı `action_logs` tablosundadır. İkisine de tek yerden
 * ulaşmanın temiz yolu API'dir:
 *   GET  /api/v1/hardware?limit&offset            → varlık listesi (id, asset_tag, serial, image URL)
 *   GET  /api/v1/hardware/{id}/files              → o varlığa yüklenmiş dosyalar (JSON)
 *   GET  /api/v1/hardware/{id}/files/{file_id}    → dosyanın KENDİSİ (indirir)
 * Kimlik: `Authorization: Bearer <API token>` (Snipe-IT → kullanıcı menüsü → Manage API Keys).
 *
 * ⚠ Token SIRDIR: forma girilirse yalnız oturumda tutulur, DB'ye/diske YAZILMAZ.
 *   Kalıcı olsun isteniyorsa `config.php`'ye (git-ignored) SNIPE_URL / SNIPE_TOKEN eklenir.
 */

require_once __DIR__ . '/_ortak.php';

/** Sunucu adresi ve token: önce config.php sabitleri, yoksa oturumdaki form değerleri. */
function sn_ayar(): array
{
    $url = defined('SNIPE_URL')   && SNIPE_URL   !== '' ? (string)SNIPE_URL   : (string)($_SESSION['it_snipe']['url'] ?? '');
    $tok = defined('SNIPE_TOKEN') && SNIPE_TOKEN !== '' ? (string)SNIPE_TOKEN : (string)($_SESSION['it_snipe']['token'] ?? '');
    return [rtrim(trim($url), '/'), trim($tok), defined('SNIPE_URL') && SNIPE_URL !== ''];
}

/**
 * API isteği. $ham=true ise gövde JSON'a çevrilmez (dosya indirme).
 * @return array{0:bool, 1:mixed, 2:string} [ok, veri|ham gövde, hata]
 */
function sn_istek(string $yol, bool $ham = false, int $zamanAsimi = 30): array
{
    [$url, $token] = sn_ayar();
    if ($url === '' || $token === '') return [false, null, 'Snipe-IT adresi veya API anahtarı girilmedi.'];
    if (!preg_match('#^https?://#i', $url)) return [false, null, 'Adres http:// veya https:// ile başlamalı.'];

    $ch = curl_init($url . '/api/v1/' . ltrim($yol, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $zamanAsimi,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: ' . ($ham ? '*/*' : 'application/json')],
        CURLOPT_USERAGENT      => 'ERN-IT-Envanter/1.0',
    ]);
    $govde = curl_exec($ch);
    $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hata  = curl_error($ch);
    unset($ch);                                  // ⚠ curl_close() PHP 8.5'te deprecated

    if ($govde === false)      return [false, null, 'Bağlantı kurulamadı: ' . ($hata ?: 'bilinmeyen hata')];
    if ($kod === 401 || $kod === 403) return [false, null, 'Yetki reddedildi (HTTP ' . $kod . ') — API anahtarını kontrol edin.'];
    if ($kod === 404)          return [false, null, 'Uç bulunamadı (404) — adres doğru mu? (' . h($yol) . ')'];
    if ($kod >= 400)           return [false, null, 'Snipe-IT hata döndürdü: HTTP ' . $kod];
    if ($ham)                  return [true, $govde, ''];

    $j = json_decode((string)$govde, true);
    if (!is_array($j)) return [false, null, 'Yanıt JSON değil — adres Snipe-IT API kökü mü? (' . mb_substr(strip_tags((string)$govde), 0, 80) . ')'];
    // Snipe-IT hata gövdesi: {"status":"error","messages":"…"}
    if (($j['status'] ?? '') === 'error') return [false, null, 'Snipe-IT: ' . (is_string($j['messages'] ?? null) ? $j['messages'] : 'işlem reddedildi')];
    return [true, $j, ''];
}

/** Bağlantı testi: toplam varlık sayısını döndürür. @return array{0:bool,1:int,2:string} */
function sn_test(): array
{
    [$ok, $j, $hata] = sn_istek('hardware?limit=1');
    if (!$ok) return [false, 0, $hata];
    return [true, (int)($j['total'] ?? 0), ''];
}

/** Tüm varlıkları sayfa sayfa çeker (id / asset_tag / serial / model / image). */
function sn_cihazlar(int $sayfaBoy = 100, int $enFazla = 5000): array
{
    $hepsi = []; $offset = 0;
    while (count($hepsi) < $enFazla) {
        [$ok, $j] = sn_istek('hardware?limit=' . $sayfaBoy . '&offset=' . $offset);
        if (!$ok) break;
        $satir = $j['rows'] ?? [];
        if (!$satir) break;
        foreach ($satir as $a) {
            $hepsi[] = [
                'id'        => (int)($a['id'] ?? 0),
                'etiket'    => trim((string)($a['asset_tag'] ?? '')),
                'seri'      => trim((string)($a['serial'] ?? '')),
                'ad'        => trim((string)($a['name'] ?? '')) ?: trim((string)(($a['model']['name'] ?? ''))),
                'foto'      => trim((string)($a['image'] ?? '')),
            ];
        }
        $offset += $sayfaBoy;
        if ($offset >= (int)($j['total'] ?? 0)) break;
    }
    return $hepsi;
}

/**
 * Bir varlığın dosya listesi: [[id, ad, notlar, url, diskte], …]
 * ⚠ `exists_on_disk` = kayıt var ama dosya sunucudan silinmiş olabilir; `url` = doğrudan indirme
 * adresi (API ucu hata verirse yedek yol).
 */
function sn_dosyalar(int $snipeId): array
{
    [$ok, $j] = sn_istek('hardware/' . $snipeId . '/files');
    if (!$ok) return [];
    $r = [];
    foreach ($j['rows'] ?? [] as $f) {
        $r[] = ['id'     => (int)($f['id'] ?? 0),
                'ad'     => trim((string)($f['filename'] ?? $f['name'] ?? '')) ?: ('dosya-' . (int)($f['id'] ?? 0)),
                'notlar' => trim((string)($f['note'] ?? $f['notes'] ?? '')),
                'url'    => trim((string)($f['url'] ?? '')),
                'diskte' => !array_key_exists('exists_on_disk', $f) || (bool)$f['exists_on_disk']];
    }
    return $r;
}

/**
 * Snipe-IT varlıklarını bizim cihazlarımızla eşleştirir.
 * Sıra: snipe_id (daha önce eşleşmiş) → cihaz kodu (asset tag) → IFS nesne no → seri no → envanter no.
 * @return array{0:array<int,array>, 1:array} [snipeId => ['cihaz'=>satır,'nasil'=>…], eşleşmeyenler]
 */
function sn_eslestir(PDO $pdo, array $snCihazlar): array
{
    $bySnipe = $byKod = $byIfs = $bySeri = $byEnv = [];
    foreach ($pdo->query("SELECT * FROM it_cihazlar") as $c) {
        if (!empty($c['snipe_id']))                        $bySnipe[(int)$c['snipe_id']] = $c;
        if (trim((string)($c['cihaz_kodu'] ?? '')) !== '') $byKod[it_norm($c['cihaz_kodu'])] = $c;
        if (trim((string)($c['varlik_kodu'] ?? '')) !== '')$byIfs[it_norm($c['varlik_kodu'])] = $c;
        if (trim((string)($c['seri_no'] ?? '')) !== '')    $bySeri[it_norm($c['seri_no'])] = $c;
        if (trim((string)$c['envanter_no']) !== '')        $byEnv[it_norm($c['envanter_no'])] = $c;
    }
    $eslesen = []; $yok = [];
    foreach ($snCihazlar as $a) {
        $et = it_norm($a['etiket']); $sr = it_norm($a['seri']);
        $c = null; $nasil = '';
        if ($a['id'] && isset($bySnipe[$a['id']]))   { $c = $bySnipe[$a['id']]; $nasil = 'snipe id'; }
        elseif ($et !== '' && isset($byKod[$et]))    { $c = $byKod[$et];        $nasil = 'cihaz kodu'; }
        elseif ($et !== '' && isset($byIfs[$et]))    { $c = $byIfs[$et];        $nasil = 'IFS nesne no'; }
        elseif ($sr !== '' && isset($bySeri[$sr]))   { $c = $bySeri[$sr];       $nasil = 'seri no'; }
        elseif ($et !== '' && isset($byEnv[$et]))    { $c = $byEnv[$et];        $nasil = 'envanter no'; }
        if ($c) $eslesen[$a['id']] = ['cihaz' => $c, 'nasil' => $nasil, 'sn' => $a];
        else    $yok[] = $a;
    }
    return [$eslesen, $yok];
}

/** Dosya adından belge türü: zimmet/tutanak geçenler imzalı tutanak sayılır. */
function sn_belge_turu(string $ad, string $notlar = ''): string
{
    $n = it_norm($ad . ' ' . $notlar);
    foreach (['ZIMMET', 'TUTANAK', 'TESLIM', 'IMZALI'] as $k) if (str_contains($n, $k)) return 'zimmet';
    if (str_contains($n, 'TRANSFER') || str_contains($n, 'SEVK')) return 'transfer';
    return 'belge';
}

/**
 * URL'den (tam adres) dosya indirir → geçici dosya yolu ya da null.
 * ⚠ Fotoğraf normalde herkese açık diskte durur ama kurulum uploads klasörünü kimlik
 * doğrulamasının arkasına almış olabilir; adres Snipe sunucusunun kendisiyse **token da
 * gönderilir** (açık dosyada zararsız, korumalı kurulumda çalışmayı sağlar).
 */
function sn_url_indir(string $url, int $zamanAsimi = 30): ?string
{
    if (!preg_match('#^https?://#i', $url)) return null;
    [$kok, $token] = sn_ayar();
    $baslik = [];
    if ($token !== '' && $kok !== ''
        && strcasecmp((string)parse_url($url, PHP_URL_HOST), (string)parse_url($kok, PHP_URL_HOST)) === 0)
        $baslik[] = 'Authorization: Bearer ' . $token;

    $tmp = tempnam(sys_get_temp_dir(), 'sn_');
    if ($tmp === false) return null;
    $fp = @fopen($tmp, 'wb');
    if (!$fp) { @unlink($tmp); return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                            CURLOPT_TIMEOUT => $zamanAsimi, CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_HTTPHEADER => $baslik,
                            CURLOPT_USERAGENT => 'ERN-IT-Envanter/1.0']);
    $ok  = curl_exec($ch);
    $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); fclose($fp);
    if (!$ok || $kod >= 400 || !filesize($tmp)) { @unlink($tmp); return null; }
    return $tmp;
}

/**
 * API'den dosya indirir (Bearer'lı) → [geçici dosya yolu | null, hata mesajı].
 *
 * ⚠⚠ Snipe-IT dosya indirme ucu **hataları da HTTP 200 ile ve JSON gövdeyle** döndürür
 * (`{"status":"error","messages":"…"}` — invalid_id / file_not_found). HTTP koduna bakan bir
 * denetim bunu "dosya" sanıp diske yazıyordu; sonuç: her ek "desteklenmeyen tür — application/json".
 * Bu yüzden gövde JSON hata mı diye AYRICA bakılır ve gerçek mesaj yukarı taşınır.
 * API ucu hata verirse dosya listesindeki doğrudan `url` yedek yol olarak denenir.
 */
function sn_dosya_indir(int $snipeId, int $dosyaId, string $yedekUrl = ''): array
{
    [$ok, $govde, $hata] = sn_istek('hardware/' . $snipeId . '/files/' . $dosyaId, true, 60);
    if ($ok && is_string($govde) && $govde !== '') {
        $j = sn_json_hata($govde);
        if ($j === null) {                                   // gerçek dosya
            $tmp = tempnam(sys_get_temp_dir(), 'sn_');
            if ($tmp === false) return [null, 'geçici dosya açılamadı'];
            if (@file_put_contents($tmp, $govde) === false) { @unlink($tmp); return [null, 'geçici dosyaya yazılamadı']; }
            return [$tmp, ''];
        }
        $hata = $j;                                          // Snipe-IT'in kendi hata metni
    }
    if ($yedekUrl !== '') {                                  // yedek: doğrudan indirme adresi
        $tmp = sn_url_indir($yedekUrl, 60);
        if ($tmp !== null && sn_json_hata((string)@file_get_contents($tmp)) === null) return [$tmp, ''];
        if ($tmp !== null) @unlink($tmp);
    }
    return [null, $hata ?: 'indirilemedi'];
}

/** Gövde bir Snipe-IT JSON HATASI mı? Öyleyse mesajı, değilse null döner. */
function sn_json_hata(string $govde): ?string
{
    $bas = ltrim(substr($govde, 0, 8));
    if ($bas === '' || ($bas[0] !== '{' && $bas[0] !== '[')) return null;   // JSON'a benzemiyor → dosya
    $j = json_decode($govde, true);
    if (!is_array($j)) return null;
    if (($j['status'] ?? '') !== 'error') return null;
    $m = $j['messages'] ?? null;
    if (is_array($m)) $m = implode(' · ', array_map(fn($x) => is_array($x) ? implode(' ', $x) : (string)$x, $m));
    return trim((string)$m) ?: 'Snipe-IT dosyayı vermedi';
}

/**
 * BİR PARTİ cihazı işler (uzun listede zaman aşımına düşmesin diye sayfa parça parça çağırır).
 * Aynı dosya iki kez EKLENMEZ: cihazın mevcut belgelerinin içerik md5'i ile karşılaştırılır.
 * @return array sayaçlar + satır listesi
 */
function sn_parti_isle(PDO $pdo, array $eslesen, array $snipeIdler, array $opt): array
{
    $kul   = $opt['kullanici'] ?? null;
    $foto  = !empty($opt['foto']);
    $belge = !empty($opt['belge']);
    $r = ['foto'=>0, 'belge'=>0, 'atlanan'=>0, 'hata'=>[], 'satir'=>[]];

    foreach ($snipeIdler as $sid) {
        $sid = (int)$sid;
        if (!isset($eslesen[$sid])) continue;
        $c      = $eslesen[$sid]['cihaz'];
        $sn     = $eslesen[$sid]['sn'];
        $cihazId = (int)$c['id'];
        $md5ler = it_belge_md5ler($pdo, $cihazId);
        $satir  = ['kim' => trim(($c['cihaz_kodu'] ?: $c['envanter_no']) . ' ' . $c['ad']), 'foto'=>0, 'belge'=>0, 'atlanan'=>0];

        // Snipe id'yi kalıcılaştır → sonraki çekimlerde eşleşme birebir olur
        if ((int)($c['snipe_id'] ?? 0) !== $sid)
            $pdo->prepare("UPDATE it_cihazlar SET snipe_id=? WHERE id=?")->execute([$sid, $cihazId]);

        // 1) Cihaz fotoğrafı (public disk, tam URL olarak gelir)
        if ($foto && $sn['foto'] !== '') {
            $tmp = sn_url_indir($sn['foto']);
            // ⚠ Fotoğraf ucu da hata yerine JSON döndürebilir — dosya sanıp kaydetme
            if ($tmp !== null && sn_json_hata((string)@file_get_contents($tmp)) !== null) { @unlink($tmp); $tmp = null; }
            if ($tmp) {
                $m = md5_file($tmp);
                if (isset($md5ler[$m])) { $r['atlanan']++; $satir['atlanan']++; }
                else {
                    $ad = basename(parse_url($sn['foto'], PHP_URL_PATH) ?: 'foto.jpg');
                    [$b, $msj] = it_belge_kaydet($pdo, $cihazId, $tmp, $ad, $kul, 'belge');
                    if ($b) { $r['foto']++; $satir['foto']++; $md5ler[$m] = true; } else $r['hata'][] = $satir['kim'] . ': ' . strip_tags($msj);
                }
                @unlink($tmp);
            } else $r['hata'][] = $satir['kim'] . ': fotoğraf indirilemedi';
        }

        // 2) Varlığa yüklenmiş dosyalar (imzalı tutanak, fatura, garanti…)
        if ($belge) {
            foreach (sn_dosyalar($sid) as $d) {
                if (!$d['diskte']) {                     // kayıt var ama dosya Snipe sunucusunda yok
                    $r['hata'][] = $satir['kim'] . ': ' . $d['ad'] . ' — dosya Snipe-IT sunucusunda bulunamadı (kayıt var, dosya silinmiş)';
                    continue;
                }
                [$tmp, $ih] = sn_dosya_indir($sid, $d['id'], $d['url']);
                if (!$tmp) { $r['hata'][] = $satir['kim'] . ': ' . $d['ad'] . ' — ' . $ih; continue; }
                $m = md5_file($tmp);
                if (isset($md5ler[$m])) { $r['atlanan']++; $satir['atlanan']++; @unlink($tmp); continue; }
                [$b, $msj] = it_belge_kaydet($pdo, $cihazId, $tmp, $d['ad'], $kul, sn_belge_turu($d['ad'], $d['notlar']));
                if ($b) { $r['belge']++; $satir['belge']++; $md5ler[$m] = true; } else $r['hata'][] = $satir['kim'] . ': ' . strip_tags($msj);
                @unlink($tmp);
            }
        }
        if ($satir['foto'] || $satir['belge'] || $satir['atlanan']) $r['satir'][] = $satir;
    }
    return $r;
}
