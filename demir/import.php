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

                $eklenen = 0; $atlanan = 0; $hataliSatir = []; $bosGecti = 0; $silinen = 0;
                $uid = current_user_id();
                $reset = !empty($_POST['reset']); // "temizle ve yeniden yükle" seçilmişse

                $pdoDemir->beginTransaction();
                try {
                    if ($reset) {
                        // Excel ile birebir eşleşme için mevcut sevkiyatları sıfırla (kalemler + sevkiyatlar).
                        // Tutanaklar/siparişler ayrı tablolardır, etkilenmez.
                        $silinen = (int)$pdoDemir->query("SELECT COUNT(*) FROM demir_sevkiyatlar")->fetchColumn();
                        $pdoDemir->exec("DELETE FROM demir_sevkiyat_kalemleri");
                        $pdoDemir->exec("DELETE FROM demir_sevkiyatlar");
                    }
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
                    $pdoDemir->commit();
                } catch (Throwable $e) {
                    $pdoDemir->rollBack();
                    $hata = 'İçe aktarma sırasında hata: ' . $e->getMessage();
                }

                if (!$hata) {
                    $rapor = [
                        'eklenen' => $eklenen, 'atlanan' => $atlanan,
                        'hatali'  => $hataliSatir, 'olusturulan' => $olusturulan,
                        'cap_sayi'=> count($capKol), 'silinen' => $silinen, 'reset' => $reset,
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
