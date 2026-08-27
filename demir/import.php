<?php
/**
 * demir/import.php — Demir Excel içe aktarma
 * "İNŞAAT DEMİRİ TAKİP TABLOSU" sayfasını okur; her satırı bir sevkiyat +
 * çap bazında kalem olarak ekler. Mükerrer irsaliye no'ları atlar.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/../includes/fatura.php';   // fat_irs_norm: taslak sevkiyat eşleşmesi (biçim farkı mükerrer üretmesin)
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSX;

$pageTitle = 'Demir Excel İçe Aktar — Demir Takip';

$rapor = null;   // sonuç raporu
$hata  = '';

// ── Yardımcılar ───────────────────────────────────────────────────────────────
function norm_cap(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    // Türkçe i türevlerini (İ, I, ı, i) tek harfe indir — "ÇELİK" vs "ÇELIK" eşleşsin
    $s = str_replace(['İ', 'I', 'ı', 'i'], 'I', $s);
    $s = str_replace(['MM', 'DEMIR'], '', $s);
    return preg_replace('/\s+/', '', $s);
}
function hdr_bul(array $rows, string $ara): int {
    foreach ($rows as $ri => $row) {
        foreach ($row as $v) {
            if ($v !== null && mb_stripos((string)$v, $ara) !== false) return $ri;
        }
        if ($ri > 12) break;
    }
    return -1;
}
function kol_bul(array $row, string $ara): int {
    foreach ($row as $c => $v) {
        if ($v !== null && mb_stripos((string)$v, $ara) !== false) return $c;
    }
    return -1;
}
function tarih_parse($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}
function num($v): ?float {
    $v = trim((string)$v);
    if ($v === '' ) return null;
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['dosya']['tmp_name'])) {
    $tmp = $_FILES['dosya']['tmp_name'];
    if ($_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
        $hata = 'Yükleme hatası (kod: ' . $_FILES['dosya']['error'] . ').';
    } elseif (!($xlsx = SimpleXLSX::parse($tmp))) {
        $hata = 'Excel okunamadı: ' . SimpleXLSX::parseError();
    } else {
        // Doğru sayfayı bul (İNŞAAT DEMİRİ TAKİP)
        $si = null;
        foreach ($xlsx->sheetNames() as $i => $n) {
            if (mb_stripos($n, 'DEMİR') !== false && mb_stripos($n, 'TAKİP') !== false) { $si = $i; break; }
        }
        if ($si === null) {
            $hata = '"İNŞAAT DEMİRİ TAKİP TABLOSU" sayfası bulunamadı.';
        } else {
            $rows = $xlsx->rows($si, 3000); // ilk 3000 satır (bellek koruması)

            $hdrRow = hdr_bul($rows, 'İRSALİYE NUMARASI');
            $capRow = hdr_bul($rows, 'KANGAL');
            if ($hdrRow < 0 || $capRow < 0) {
                $hata = 'Başlık satırları tanınamadı (İRSALİYE NUMARASI / KANGAL).';
            } else {
                $H = $rows[$hdrRow];
                // Alan kolonları
                $col = [
                    'irs_no'   => kol_bul($H, 'İRSALİYE NUMARASI'),
                    'irs_tar'  => kol_bul($H, 'İRSALİYE TARİHİ'),
                    'gelis'    => kol_bul($H, 'GELİŞ TARİHİ'),
                    'arac'     => kol_bul($H, 'ARAÇ PLAKA'),
                    'dorse'    => kol_bul($H, 'DORSE PLAKA'),
                    'kantar'   => kol_bul($H, 'KANTAR FİŞ'),
                    'tedarik'  => kol_bul($H, 'TEDARİKÇİ'),
                    'site'     => kol_bul($H, 'SITE'),
                    'verilen'  => kol_bul($H, 'VERİLEN FİRMA'),
                    'ifs_sip'  => kol_bul($H, 'IFS SİPARİŞ'),
                    'getiren'  => kol_bul($H, 'GETİREN FİRMA'),
                    'ifs_dur'  => kol_bul($H, 'IFS Giriş'),
                    'tir'      => kol_bul($H, 'TIR PLAKA'),
                ];

                // Çap kolon çiftleri (capRow: her çap adı -> col, col+1)
                $capDb = $pdoDemir->query("SELECT id, ad FROM demir_caplar")->fetchAll();
                $capMap = [];
                foreach ($capDb as $c) { $capMap[norm_cap($c['ad'])] = (int)$c['id']; }

                $capKol = []; // [ ['cap_id'=>, 'irs'=>col, 'knt'=>col+1] ]
                foreach ($rows[$capRow] as $c => $v) {
                    $v = trim((string)$v);
                    if ($v === '' || $c < ($col['tedarik'] + 1)) continue;
                    $n = norm_cap($v);
                    if (isset($capMap[$n])) {
                        $capKol[] = ['cap_id' => $capMap[$n], 'irs' => $c, 'knt' => $c + 1, 'ad' => $v];
                    }
                }

                // Eşleşme yardımcıları (get-or-create)
                $olusturulan = ['tedarikci'=>[], 'taseron'=>[], 'proje'=>[]];
                $getTed = function($ad) use ($pdoDemir, &$olusturulan) {
                    $ad = trim($ad); if ($ad==='') return null;
                    $s = $pdoDemir->prepare("SELECT id FROM demir_tedarikciler WHERE UPPER(ad)=UPPER(?)"); $s->execute([$ad]);
                    if ($id = $s->fetchColumn()) return (int)$id;
                    $pdoDemir->prepare("INSERT INTO demir_tedarikciler (ad) VALUES (?)")->execute([$ad]);
                    $olusturulan['tedarikci'][$ad] = 1;
                    return (int)$pdoDemir->lastInsertId();
                };
                $getTas = function($ad) use ($pdoDemir, &$olusturulan) {
                    $ad = trim($ad); if ($ad==='') return null;
                    $s = $pdoDemir->prepare("SELECT id FROM demir_taseronlar WHERE UPPER(ad)=UPPER(?)"); $s->execute([$ad]);
                    if ($id = $s->fetchColumn()) return (int)$id;
                    $pdoDemir->prepare("INSERT INTO demir_taseronlar (ad) VALUES (?)")->execute([$ad]);
                    $olusturulan['taseron'][$ad] = 1;
                    return (int)$pdoDemir->lastInsertId();
                };
                $getPrj = function($kod) use ($pdoDemir, &$olusturulan) {
                    $kod = trim($kod); if ($kod==='') return null;
                    $s = $pdoDemir->prepare("SELECT id FROM demir_projeler WHERE UPPER(kod)=UPPER(?)"); $s->execute([$kod]);
                    if ($id = $s->fetchColumn()) return (int)$id;
                    $pdoDemir->prepare("INSERT INTO demir_projeler (kod, aciklama) VALUES (?, '')")->execute([$kod]);
                    $olusturulan['proje'][$kod] = 1;
                    return (int)$pdoDemir->lastInsertId();
                };

                $eklenen = 0; $atlanan = 0; $guncellenen = 0; $hataliSatir = []; $bosGecti = 0; $silinen = 0;
                $fatSnapshot = []; $fatReattach = 0;
                $uid = current_user_id();
                $reset = !empty($_POST['reset']); // "temizle ve yeniden yükle" seçilmişse

                $pdoDemir->beginTransaction();
                try {
                    if ($reset) {
                        // Excel ile birebir eşleşme için mevcut sevkiyatları sıfırla (kalemler + sevkiyatlar).
                        // Tutanaklar/siparişler ayrı tablolardır, etkilenmez.
                        // [FATURADAN] TASLAKLARI SİLİNMEZ (fatura bağı kopmasın) — Excel'de karşılığı
                        // gelirse aşağıda güncellenir. Diğer kayıtların fatura bağları da snapshot'lanıp
                        // içe aktarma sonrası aynı (normalize) irsaliye no'lu yeni kayda geri bağlanır.
                        try {
                            $fatSnapshot = $pdoDemir->query("SELECT irsaliye_no, fatura_id FROM demir_sevkiyatlar
                                WHERE fatura_id IS NOT NULL AND COALESCE(aciklama,'') NOT LIKE '%[FATURADAN]%'
                                  AND irsaliye_no IS NOT NULL AND irsaliye_no <> ''")->fetchAll();
                        } catch (Throwable $eS) { $fatSnapshot = []; }
                        $silinen = (int)$pdoDemir->query("SELECT COUNT(*) FROM demir_sevkiyatlar
                                                          WHERE COALESCE(aciklama,'') NOT LIKE '%[FATURADAN]%'")->fetchColumn();
                        $pdoDemir->exec("DELETE k FROM demir_sevkiyat_kalemleri k
                                         JOIN demir_sevkiyatlar s ON s.id = k.sevkiyat_id
                                         WHERE COALESCE(s.aciklama,'') NOT LIKE '%[FATURADAN]%'");
                        $pdoDemir->exec("DELETE FROM demir_sevkiyatlar WHERE COALESCE(aciklama,'') NOT LIKE '%[FATURADAN]%'");
                    }

                    // Fatura ekranından açılmış TASLAK sevkiyatlar (normalize irsaliye no ile):
                    // Excel satırı bunları SİLMEDEN GÜNCELLER — id sabit kalır, fatura bağı korunur.
                    $taslakMap = [];
                    try {
                        foreach ($pdoDemir->query("SELECT id, irsaliye_no FROM demir_sevkiyatlar
                                                   WHERE COALESCE(aciklama,'') LIKE '%[FATURADAN]%'") as $tr) {
                            $tk = fat_irs_norm((string)$tr['irsaliye_no']);
                            if ($tk !== '' && !isset($taslakMap[$tk])) $taslakMap[$tk] = (int)$tr['id'];
                        }
                    } catch (Throwable $eS) {}
                    $insSevk = $pdoDemir->prepare("INSERT INTO demir_sevkiyatlar
                        (kod, irsaliye_no, irsaliye_tarih, gelis_tarih, arac_plaka, dorse_plaka, kantar_fis_no,
                         tedarikci_id, ifs_siparis_no, getiren_firma, ifs_giris_durumu, tir_plaka, proje_id, taseron_id, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $insKalem = $pdoDemir->prepare("INSERT INTO demir_sevkiyat_kalemleri
                        (sevkiyat_id, cap_id, irsaliye_miktar, kantar_miktar) VALUES (?,?,?,?)");
                    // Mükerrer kontrolü kod (proje+irsaliye) üzerinden: aynı irsaliye no birden fazla
                    // projeye/siteye bölünmüş olabilir (ör. KBO...468 hem U030 hem U031) — ikisi de sevkiyattır.
                    $varMi = $pdoDemir->prepare("SELECT id FROM demir_sevkiyatlar WHERE kod=? LIMIT 1");

                    for ($ri = $hdrRow + 1; $ri < count($rows); $ri++) {
                        $R = $rows[$ri];
                        $irsNo = trim((string)($R[$col['irs_no']] ?? ''));
                        if ($irsNo === '') { $bosGecti++; if ($bosGecti > 15) break; continue; }
                        $bosGecti = 0;

                        // Kalemleri topla
                        $kalemler = [];
                        foreach ($capKol as $ck) {
                            $irs = num($R[$ck['irs']] ?? '');
                            $knt = num($R[$ck['knt']] ?? '');
                            if (($irs !== null && $irs != 0) || ($knt !== null && $knt != 0)) {
                                $kalemler[] = [$ck['cap_id'], $irs ?? 0, $knt];
                            }
                        }
                        if (!$kalemler) { $hataliSatir[] = "Satır ".($ri+1).": $irsNo — miktar yok, atlandı"; continue; }

                        // Kod = proje(site) + irsaliye no → gerçek benzersiz kimlik
                        $prjKod = $col['site']>=0 ? trim((string)($R[$col['site']] ?? '')) : '';
                        $kod = $prjKod . $irsNo;

                        // Mükerrer? (aynı proje + aynı irsaliye) — tekrar yüklemek güvenli, split teslimatlar korunur
                        $varMi->execute([$kod]);
                        if ($varMi->fetchColumn()) { $atlanan++; continue; }

                        $tedId = $getTed((string)($R[$col['tedarik']] ?? ''));
                        $prjId = $col['site']>=0    ? $getPrj((string)($R[$col['site']] ?? '')) : null;
                        $tasId = $col['verilen']>=0 ? $getTas((string)($R[$col['verilen']] ?? '')) : null;

                        // [FATURADAN] taslağıysa: yeni kayıt AÇMA, taslağı Excel verisiyle doldur
                        // (fatura_id'ye dokunulmaz — bağ korunur; etiket kalkar, gerçek kayıt olur).
                        $irsNorm = fat_irs_norm($irsNo);
                        if ($irsNorm !== '' && isset($taslakMap[$irsNorm])) {
                            $tid = $taslakMap[$irsNorm];
                            $pdoDemir->prepare("UPDATE demir_sevkiyatlar SET
                                    kod=?, irsaliye_no=?, irsaliye_tarih=?, gelis_tarih=?, arac_plaka=?, dorse_plaka=?,
                                    kantar_fis_no=?, tedarikci_id=?, ifs_siparis_no=?, getiren_firma=?, ifs_giris_durumu=?,
                                    tir_plaka=?, proje_id=?, taseron_id=?, aciklama=NULL WHERE id=?")
                                ->execute([
                                    $kod, $irsNo,
                                    tarih_parse($R[$col['irs_tar']] ?? ''), tarih_parse($R[$col['gelis']] ?? ''),
                                    strtoupper(trim((string)($R[$col['arac']] ?? ''))) ?: null,
                                    strtoupper(trim((string)($R[$col['dorse']] ?? ''))) ?: null,
                                    trim((string)($R[$col['kantar']] ?? '')) ?: null,
                                    $tedId,
                                    $col['ifs_sip']>=0 ? (trim((string)($R[$col['ifs_sip']] ?? '')) ?: null) : null,
                                    $col['getiren']>=0 ? (trim((string)($R[$col['getiren']] ?? '')) ?: null) : null,
                                    $col['ifs_dur']>=0 ? (trim((string)($R[$col['ifs_dur']] ?? '')) ?: null) : null,
                                    $col['tir']>=0 ? (strtoupper(trim((string)($R[$col['tir']] ?? ''))) ?: null) : null,
                                    $prjId, $tasId, $tid,
                                ]);
                            $pdoDemir->prepare("DELETE FROM demir_sevkiyat_kalemleri WHERE sevkiyat_id=?")->execute([$tid]);
                            foreach ($kalemler as $kl) { $insKalem->execute([$tid, $kl[0], $kl[1], $kl[2]]); }
                            unset($taslakMap[$irsNorm]);
                            $guncellenen++;
                            continue;
                        }

                        $insSevk->execute([
                            $kod, $irsNo,
                            tarih_parse($R[$col['irs_tar']] ?? ''),
                            tarih_parse($R[$col['gelis']] ?? ''),
                            strtoupper(trim((string)($R[$col['arac']] ?? ''))) ?: null,
                            strtoupper(trim((string)($R[$col['dorse']] ?? ''))) ?: null,
                            trim((string)($R[$col['kantar']] ?? '')) ?: null,
                            $tedId,
                            $col['ifs_sip']>=0 ? (trim((string)($R[$col['ifs_sip']] ?? '')) ?: null) : null,
                            $col['getiren']>=0 ? (trim((string)($R[$col['getiren']] ?? '')) ?: null) : null,
                            $col['ifs_dur']>=0 ? (trim((string)($R[$col['ifs_dur']] ?? '')) ?: null) : null,
                            $col['tir']>=0 ? (strtoupper(trim((string)($R[$col['tir']] ?? ''))) ?: null) : null,
                            $prjId, $tasId, $uid,
                        ]);
                        $sevkId = (int)$pdoDemir->lastInsertId();
                        foreach ($kalemler as $kl) { $insKalem->execute([$sevkId, $kl[0], $kl[1], $kl[2]]); }
                        $eklenen++;
                    }

                    // Temizle-ve-yeniden-yükle: silinen kayıtların fatura bağlarını, aynı
                    // (normalize) irsaliye no'lu YENİ kayıtlara geri bağla.
                    if ($reset && $fatSnapshot) {
                        $noMap = [];
                        foreach ($pdoDemir->query("SELECT id, irsaliye_no FROM demir_sevkiyatlar
                                                   WHERE irsaliye_no IS NOT NULL AND irsaliye_no <> '' AND fatura_id IS NULL") as $nr) {
                            $nk = fat_irs_norm((string)$nr['irsaliye_no']);
                            if ($nk !== '') $noMap[$nk][] = (int)$nr['id'];
                        }
                        $updF = $pdoDemir->prepare("UPDATE demir_sevkiyatlar SET fatura_id = ? WHERE id = ?");
                        foreach ($fatSnapshot as $fs) {
                            $fk = fat_irs_norm((string)$fs['irsaliye_no']);
                            foreach ($noMap[$fk] ?? [] as $nid) { $updF->execute([(int)$fs['fatura_id'], $nid]); $fatReattach++; }
                            unset($noMap[$fk]);
                        }
                    }
                    $pdoDemir->commit();
                } catch (Throwable $e) {
                    $pdoDemir->rollBack();
                    $hata = 'İçe aktarma sırasında hata: ' . $e->getMessage();
                }

                if (!$hata) {
                    $rapor = [
                        'eklenen' => $eklenen, 'atlanan' => $atlanan, 'guncellenen' => $guncellenen,
                        'hatali'  => $hataliSatir, 'olusturulan' => $olusturulan,
                        'cap_sayi'=> count($capKol), 'silinen' => $silinen, 'reset' => $reset,
                        'fatura_bag' => $fatReattach,
                    ];
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="sevkiyatlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0"><i class="bi bi-file-earmark-excel text-success me-2"></i>Demir Excel İçe Aktar</h4>
</div>

<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($hata) ?></div><?php endif; ?>

<?php if ($rapor): ?>
<div class="alert alert-success">
    <h5 class="mb-2"><i class="bi bi-check-circle-fill me-1"></i>İçe aktarma tamamlandı</h5>
    <ul class="mb-0">
        <?php if ($rapor['reset']): ?><li class="text-danger"><strong><?= $rapor['silinen'] ?></strong> mevcut sevkiyat silindi (temizle ve yeniden yükle)</li><?php endif; ?>
        <li><strong><?= $rapor['eklenen'] ?></strong> sevkiyat eklendi (<?= $rapor['cap_sayi'] ?> çap kolonu okundu)</li>
        <?php if (!empty($rapor['guncellenen'])): ?><li><strong><?= $rapor['guncellenen'] ?></strong> faturadan açılmış taslak sevkiyat gerçek verilerle güncellendi (fatura bağı korundu)</li><?php endif; ?>
        <?php if (!empty($rapor['fatura_bag'])): ?><li><strong><?= $rapor['fatura_bag'] ?></strong> fatura bağı yeni kayıtlara geri bağlandı</li><?php endif; ?>
        <?php if ($rapor['atlanan']): ?><li><strong><?= $rapor['atlanan'] ?></strong> kayıt zaten vardı, atlandı (mükerrer irsaliye no)</li><?php endif; ?>
        <?php foreach (['tedarikci'=>'tedarikçi','taseron'=>'taşeron','proje'=>'proje'] as $k=>$lbl): if($rapor['olusturulan'][$k]): ?>
        <li><?= count($rapor['olusturulan'][$k]) ?> yeni <?= $lbl ?> oluşturuldu: <em><?= h(implode(', ', array_keys($rapor['olusturulan'][$k]))) ?></em></li>
        <?php endif; endforeach; ?>
    </ul>
    <?php if ($rapor['hatali']): ?>
    <hr><div class="small"><strong>Atlanan/uyarı satırları:</strong><ul class="mb-0"><?php foreach($rapor['hatali'] as $h): ?><li><?= h($h) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <hr>
    <a href="sevkiyatlar.php" class="btn btn-dark btn-sm"><i class="bi bi-truck me-1"></i> Sevkiyatlara Git</a>
    <a href="icmal.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-clipboard-data me-1"></i> İcmal</a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Excel Dosyası Yükle</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Demir Takip Excel dosyası (.xlsx)</label>
                <input type="file" name="dosya" class="form-control" accept=".xlsx" required>
                <div class="form-text">
                    <strong>İNŞAAT DEMİRİ TAKİP TABLOSU</strong> sayfası okunur. Her satır bir sevkiyat olarak,
                    çap bazında irsaliye/kantar miktarlarıyla eklenir. Aynı irsaliye no varsa atlanır (tekrar yüklemek güvenlidir).
                    Excel'deki tedarikçi/proje/taşeron sistemde yoksa otomatik oluşturulur.
                </div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="reset" value="1" id="resetChk"
                       onchange="if(this.checked && !confirm('DİKKAT: Tüm mevcut sevkiyatlar (ve kalemleri) silinip Excel\'den yeniden yüklenecek. Karekod/elle girilen sevkiyatlar da silinir. Devam edilsin mi?')) this.checked=false;">
                <label class="form-check-label" for="resetChk">
                    <strong>Temizle ve yeniden yükle</strong> — mevcut tüm sevkiyatları silip Excel ile birebir eşitler
                    (toplam tam olarak Excel GENEL TOPLAM'a eşit olur). Siparişler/tutanaklar etkilenmez.
                </label>
            </div>
            <button class="btn btn-success"><i class="bi bi-cloud-arrow-up me-1"></i> İçe Aktar</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
