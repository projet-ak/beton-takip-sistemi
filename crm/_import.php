<?php
/**
 * _import.php — CRM "UretimArizalari" günlük raporunun içe aktarma çekirdeği
 *
 * Rapor her gün CRM'den alınır ve **o anda açık olan arızaların anlık görüntüsüdür**.
 * Bu yüzden tam yenileme değil BİRLEŞTİRME yapılır (bkz. _ortak.php başlığı):
 *   yeni satır → kayıt açılır · mevcut satır → güncellenir · dosyada olmayan açık kayıt
 *   → arıza kapanmış sayılır (otomatik çözüldü, isteğe bağlı).
 *
 * Kimlik `crm_anahtar()` ile içerikten üretilir (Excel'de ID kolonu yok) ve
 * `crm_arizalar.kayit_anahtari` UNIQUE olduğundan aynı dosya defalarca yüklense de
 * mükerrer kayıt oluşmaz.
 */

use Shuchkin\SimpleXLSX;

/** Alan → başlıkta aranacak metinler. SIRA ÖNEMLİ: bir sütun tek alana bağlanır. */
const CRM_ALAN = [
    'konut'              => ['KONUT'],
    'ada'                => ['ADA'],
    'parsel'             => ['PARSEL'],
    'blok'               => ['BLOK'],
    'daire_no'           => ['DAIRE NO'],
    'daire_tipi'         => ['GENEL DAIRE TIPI', 'DAIRE TIPI'],
    'kat'                => ['KAT'],
    'donem'              => ['DONEM'],
    'kaynak'             => ['URETIM ARIZA KAYNAK', 'KAYNAK'],
    'eksik_kusur'        => ['EKSIKKUSUR', 'EKSIK KUSUR'],
    'olcek'              => ['URETIM ARIZA OLCEG', 'OLCEG'],
    'aciliyet'           => ['ACILIYET'],
    'sikayet_turu'       => ['SIKAYET TURU'],
    'sikayet_konusu'     => ['SIKAYET KONUSU'],
    'sikayet_aciklamasi' => ['SIKAYET ACIKLAMASI'],
    'ariza_tipi'         => ['URETIM ARIZA TIPI', 'ARIZA TIPI'],
    'sorumlu'            => ['SORUMLU'],
    'sonlandiran'        => ['SONLANDIRAN'],
    'olusturma'          => ['OLUSTURMA'],
    'cozumlenme'         => ['COZUM'],
    'durum_aciklamasi'   => ['DURUM'],
    // "Aciklama" EN SONA: "Sikayet Aciklamasi" ve "Durum Aciklamasi" da ACIKLAMA içerir,
    // önce gelseydi onların sütununu kapardı.
    'aciklama'           => ['ACIKLAMA'],
];

/** Üretim arızası sayfasını bulur: adından ya da başlık satırındaki kolonlardan. */
function crm_sayfa(SimpleXLSX $x): ?int
{
    foreach ($x->sheetNames() as $i => $n) {
        $u = crm_norm($n);
        if (str_contains($u, 'URETIM') && str_contains($u, 'ARIZA')) return (int)$i;
    }
    // Ad tutmadıysa içeriğe bak (sayfa "Sayfa1" diye gelebilir)
    foreach ($x->sheetNames() as $i => $n) {
        foreach (array_slice($x->rows((int)$i, 5), 0, 5) as $row) {
            $u = crm_norm(implode(' ', array_map('strval', $row)));
            if (str_contains($u, 'KONUT') && str_contains($u, 'SIKAYET')) return (int)$i;
        }
    }
    return null;
}

/** Başlık satırını bulur (KONUT + SIKAYET geçen ilk satır). */
function crm_baslik_satiri(array $rows): int
{
    foreach (array_slice($rows, 0, 15, true) as $ri => $row) {
        $u = crm_norm(implode(' ', array_map('strval', $row)));
        if (str_contains($u, 'KONUT') && str_contains($u, 'SIKAYET')) return (int)$ri;
    }
    return 0;
}

/** Başlık satırından alan→sütun haritası (bir sütun yalnız bir alana bağlanır). */
function crm_harita(array $baslik): array
{
    $h = []; $dolu = [];
    foreach (CRM_ALAN as $alan => $adaylar) {
        foreach ($adaylar as $a) {
            $ara = crm_norm($a);
            foreach ($baslik as $ci => $c) {
                if (isset($dolu[(int)$ci])) continue;
                $u = crm_norm((string)$c);
                if ($u !== '' && str_contains($u, $ara)) { $h[$alan] = (int)$ci; $dolu[(int)$ci] = true; break 2; }
            }
        }
    }
    return $h;
}

/** Haritadan hücre değeri. */
function crm_al(array $row, array $h, string $alan): string
{
    return isset($h[$alan]) ? trim((string)($row[$h[$alan]] ?? '')) : '';
}

/**
 * Günlük raporu içe aktarır (tek transaction).
 *
 * @param array $opt  kapat: dosyada olmayan açık kayıtlar çözüldü sayılsın mı (varsayılan true)
 *                    rapor_tarihi: Y-m-d (varsayılan bugün) · dosya: dosya adı (günlüğe yazılır)
 *                    kullanici: günlüğe yazılacak ad
 * @return array satir/yeni/guncellenen/kapanan/yenidenAcilan/kapananlar/uyari
 * @throws Throwable  hata durumunda geri alınır ve yeniden fırlatılır (hiçbir şey değişmez)
 */
function crm_import(PDO $pdo, SimpleXLSX $x, array $opt = []): array
{
    crm_semasi_kur($pdo);
    $kapat  = $opt['kapat'] ?? true;
    $rapor  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($opt['rapor_tarihi'] ?? '')) ? $opt['rapor_tarihi'] : date('Y-m-d');
    $raporSon = $rapor . ' 23:59:59';
    $sonuc  = ['satir'=>0, 'yeni'=>0, 'guncellenen'=>0, 'kapanan'=>0, 'yenidenAcilan'=>0,
               'kapananlar'=>[], 'uyari'=>[], 'rapor_tarihi'=>$rapor];

    $si = crm_sayfa($x);
    if ($si === null) throw new RuntimeException('Dosyada "UretimArizalari" sayfası bulunamadı (sayfalar: ' . implode(', ', $x->sheetNames()) . ').');
    $rows = $x->rows($si, 20000);
    $hr   = crm_baslik_satiri($rows);
    $h    = crm_harita($rows[$hr] ?? []);
    foreach (['konut','olusturma','sikayet_konusu'] as $zorunlu) {
        if (!isset($h[$zorunlu])) throw new RuntimeException('Beklenen sütun bulunamadı: ' . $zorunlu . '. Rapor biçimi değişmiş olabilir.');
    }

    $alanlar = array_keys(CRM_ALAN);
    $kolonlar = array_merge(['kayit_anahtari'], $alanlar, ['kat_sira']);
    $ins = $pdo->prepare("INSERT INTO crm_arizalar (" . implode(',', $kolonlar) . ",durum,ilk_gorulme,son_gorulme)
                          VALUES (" . implode(',', array_fill(0, count($kolonlar), '?')) . ",?,?,?)");
    $set = implode(',', array_map(fn($a) => "$a=?", $alanlar));
    $upd = $pdo->prepare("UPDATE crm_arizalar SET $set, kat_sira=?, son_gorulme=? WHERE id=?");
    $ac  = $pdo->prepare("UPDATE crm_arizalar SET durum='acik', cozumlenme=NULL, kapanis_kaynagi=NULL WHERE id=?");
    $kap = $pdo->prepare("UPDATE crm_arizalar SET durum='cozuldu', cozumlenme=?, kapanis_kaynagi='excel' WHERE id=?");
    $bul = $pdo->prepare("SELECT id, durum FROM crm_arizalar WHERE kayit_anahtari=?");

    $pdo->beginTransaction();
    try {
        // Dosyadaki anahtarlar geçici tabloya — "dosyada olmayanı kapat" adımı bunu kullanır
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS crm_gelen");
        $pdo->exec("CREATE TEMPORARY TABLE crm_gelen (anahtar CHAR(32) PRIMARY KEY)");
        $gelenIns = $pdo->prepare("INSERT IGNORE INTO crm_gelen (anahtar) VALUES (?)");

        $dosyaIci = [];   // aynı dosyada birebir tekrar eden satırlar
        for ($i = $hr + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $a = [];
            foreach ($alanlar as $alan) $a[$alan] = crm_al($row, $h, $alan);
            if ($a['konut'] === '' && $a['sikayet_konusu'] === '') continue;   // boş satır
            $a['olusturma']  = crm_tarih($a['olusturma']);
            $a['cozumlenme'] = crm_tarih($a['cozumlenme']);
            $sonuc['satir']++;

            $anahtar = crm_anahtar($a);
            if (isset($dosyaIci[$anahtar])) continue;   // dosya içinde birebir aynı satır
            $dosyaIci[$anahtar] = true;
            $gelenIns->execute([$anahtar]);

            $deger = array_map(fn($alan) => $a[$alan] === '' ? null : $a[$alan], $alanlar);
            $katSira = crm_kat_sira($a['kat']);

            $bul->execute([$anahtar]);
            $mevcut = $bul->fetch();
            if ($mevcut) {
                $id = (int)$mevcut['id'];
                $upd->execute(array_merge($deger, [$katSira, $rapor, $id]));
                $sonuc['guncellenen']++;
                // Daha önce kapanmış sayılmış ama raporda yine açık geliyorsa geri aç (kendini düzeltir)
                if ($mevcut['durum'] === 'cozuldu' && $a['cozumlenme'] === null) {
                    $ac->execute([$id]);
                    $sonuc['yenidenAcilan']++;
                }
            } else {
                $ins->execute(array_merge([$anahtar], $deger, [$katSira, 'acik', $rapor, $rapor]));
                $id = (int)$pdo->lastInsertId();
                $sonuc['yeni']++;
            }
            // Raporun kendi çözüm tarihi doluysa kapanış Excel'den gelir
            if ($a['cozumlenme'] !== null) $kap->execute([$a['cozumlenme'], $id]);
        }

        // Dosyada olmayan açık kayıtlar → arıza kapanmış
        if ($kapat && $sonuc['satir'] > 0) {
            $ornek = $pdo->prepare("SELECT konut, sikayet_konusu, sikayet_aciklamasi, ariza_tipi, olusturma
                                    FROM crm_arizalar
                                    WHERE durum='acik' AND olusturma <= ?
                                      AND kayit_anahtari NOT IN (SELECT anahtar FROM crm_gelen)
                                    ORDER BY olusturma LIMIT 200");
            $ornek->execute([$raporSon]);
            $sonuc['kapananlar'] = $ornek->fetchAll();

            $kapa = $pdo->prepare("UPDATE crm_arizalar SET durum='cozuldu', cozumlenme=?, kapanis_kaynagi='otomatik'
                                   WHERE durum='acik' AND olusturma <= ?
                                     AND kayit_anahtari NOT IN (SELECT anahtar FROM crm_gelen)");
            $kapa->execute([$rapor . ' 00:00:00', $raporSon]);
            $sonuc['kapanan'] = $kapa->rowCount();
        }

        $pdo->prepare("INSERT INTO crm_import_log (dosya, rapor_tarihi, satir, yeni, guncellenen, kapanan, kullanici)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([(string)($opt['dosya'] ?? ''), $rapor, $sonuc['satir'], $sonuc['yeni'],
                       $sonuc['guncellenen'], $sonuc['kapanan'], $opt['kullanici'] ?? null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($sonuc['satir'] === 0) $sonuc['uyari'][] = 'Dosyada veri satırı bulunamadı.';
    return $sonuc;
}
