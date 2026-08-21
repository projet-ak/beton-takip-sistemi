<?php
/**
 * import.php — Depo Excel içe aktarma
 *
 * İki tür sayfa okunur:
 *   STOK   → DEMİRBAŞLAR · SARF MALZEME · EL ALETLERİ            → depo_kalemler
 *   HAREKET→ MALZEME GİRİŞ/ÇIKIŞ · TAŞERON MALZEME GİRİŞ/TESLİMAT → depo_hareketler
 *
 * Her ikisi de **tam yenileme**: ilgili kategori/tür önce silinir, sonra Excel'den
 * yeniden yazılır (Excel tek doğru kaynak ilkesi).
 *
 * ⚠ Sütunlar SABİT DEĞİL — başlık satırındaki metinden bulunur. Sayfalar arasında
 *   sütun düzeni farklı (ör. SARF'ta MALZEME KODU yok, hepsi bir kayıyor); sabit
 *   indeks kullanmak yanlış sütunu okumaya yol açıyordu.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/../includes/db_depo.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

$pageTitle = 'Depo Excel İçe Aktarma';
$sonuc = null; $hata = null; $atlanan = [];

/**
 * Sayfa adını "içerir" mantığıyla bulur (ör. "KARTAL-BATIYAKASI SARF MALZEME" → SARF MALZEME).
 *
 * $kullanilan: daha önce başka bir türe atanmış sayfa indeksleri. Şart —
 * "TAŞERON MALZEME GİRİŞ" adı "MALZEME GİRİŞ" ifadesini de İÇERİR; işaretlenmezse
 * aynı sayfa hem taşeron hem depo girişi olarak iki kez aktarılırdı.
 */
function dpSheet(SimpleXLSX $x, array $parcalar, array $kullanilan = []): ?int
{
    foreach ($x->sheetNames() as $i => $n) {
        if (in_array((int)$i, $kullanilan, true)) continue;
        $u = dpNorm($n);
        foreach ($parcalar as $p) if (mb_strpos($u, dpNorm($p)) !== false) return (int)$i;
    }
    return null;
}

/**
 * Başlık/sayfa adı normalizasyonu.
 * Türkçe harfler ASCII'ye katlanır: DEMİRBAŞLAR → DEMIRBASLAR, ÇIKAN → CIKAN,
 * MALZEME GİRİŞ → MALZEME GIRIS. Aksi halde 'Ş' ile 'S' eşleşmediği için
 * sayfa ve sütun aramaları sessizce boş dönüyordu.
 */
function dpNorm(string $s): string
{
    $s = str_replace(
        ['İ','I','ı','i','Ş','ş','Ğ','ğ','Ü','ü','Ö','ö','Ç','ç'],
        ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'],
        $s
    );
    $s = mb_strtoupper(trim($s), 'UTF-8');
    return preg_replace('/\s+/', ' ', $s);
}

/** Başlık satırını bulur: aranan başlıkların en çoğunu içeren ilk satır. */
function dpBaslikSatiri(array $rows, array $aranan, int $limit = 12): int
{
    $enIyi = 0; $enIyiSkor = 0;
    foreach (array_slice($rows, 0, $limit, true) as $ri => $row) {
        $skor = 0;
        foreach ($row as $c) {
            $u = dpNorm((string)$c);
            if ($u === '') continue;
            foreach ($aranan as $a) if (mb_strpos($u, dpNorm($a)) !== false) { $skor++; break; }
        }
        if ($skor > $enIyiSkor) { $enIyiSkor = $skor; $enIyi = $ri; }
    }
    return $enIyi;
}

/**
 * Başlık satırından alan→sütun haritası kurar.
 * @param array $tanim  alan => [aranacak başlık parçaları...] (ilk eşleşen kazanır)
 */
function dpHarita(array $baslik, array $tanim): array
{
    $h = []; $kullanilan = [];
    foreach ($tanim as $alan => $adaylar) {
        // Aday sırası önceliktir: önce en spesifik başlık denenir
        foreach ($adaylar as $a) {
            $ara = dpNorm($a);
            foreach ($baslik as $ci => $c) {
                if (isset($kullanilan[(int)$ci])) continue;   // bir sütun tek alana bağlanır
                $u = dpNorm((string)$c);
                if ($u !== '' && mb_strpos($u, $ara) !== false) {
                    $h[$alan] = (int)$ci; $kullanilan[(int)$ci] = true; break 2;
                }
            }
        }
    }
    return $h;
}

/** Haritadan değer okur (alan yoksa boş döner). */
function dpAl(array $row, array $h, string $alan): string
{
    if (!isset($h[$alan])) return '';
    return trim((string)($row[$h[$alan]] ?? ''));
}

// ── STOK sayfaları ───────────────────────────────────────────────────────────
const DP_STOK_ALAN = [
    'sira'        => ['S.NO', 'SIRA NO', 'SIRA'],
    'kod'         => ['MALZEME KODU', 'SERI NUMARASI', 'SERI NO'],
    'ad'          => ['MALZEME ADI', 'MALZEME CINSI'],
    'ozellik'     => ['MALZEME OZELLIKLERI', 'MARKA/OZELLIK', 'OZELLIK'],
    'birim'       => ['BIRIM'],          // "BİRİM FİYAT"tan önce gelmesi için aşağıda özel kontrol var
    'sayim'       => ['SAYIM'],
    'gelen'       => ['GELEN'],
    'giden'       => ['GIDEN', 'CIKAN'],
    'birim_fiyat' => ['BIRIM FIYAT'],
    'disiplin'    => ['DISIPLIN'],
    'alan'        => ['BULUNDUGU ALAN', 'LOKASYON'],
    'alan_kisi'   => ['ALAN KISI', 'ZIMMETLI'],
];

// ── HAREKET sayfaları ────────────────────────────────────────────────────────
const DP_HRK_ALAN = [
    'tarih'        => ['MALZEME GELIS TARIH', 'TARIH'],
    'belge_tarihi' => ['IRSALIYE/FATURA TARIHI', 'FATURA TARIHI'],
    'belge_no'     => ['IRSALIYE NO', 'FIS NO', 'BELGE NO'],
    'malzeme'      => ['MALZEME CINSI VE TANIMI', 'MALZEME ADI VE TANIMI', 'MALZEME ADI', 'MALZEME'],
    'ozellik'      => ['OZELLIGI', 'OZELLIK'],
    'miktar'       => ['GELEN MIKTAR', 'GIDEN', 'MIKTAR'],
    'birim'        => ['BIRIMI', 'BIRIM'],
    'firma'        => ['GONDEREN FIRMA', 'CIKIS YAPILAN FIRMA', 'TASERON', 'FIRMA'],
    'teslim_alan'  => ['ACIKLAMA/TESLIM ALAN', 'TESLIM ALAN'],
    'onay'         => ['ONAY'],
    'lokasyon'     => ['LOKASYON'],
    'aciklama'     => ['ACIKLAMA'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['dosya']['tmp_name'])) {
    if (!($x = SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        $hata = 'Excel okunamadı: ' . SimpleXLSX::parseError();
    } else {
        dp_hareket_semasi_kur($pdoDepo);
        $r = ['demirbas'=>0,'sarf'=>0,'el_aleti'=>0,
              'giris|depo'=>0,'cikis|depo'=>0,'giris|taseron'=>0,'cikis|taseron'=>0];
        $bulunan = [];
        $kullanilanSayfa = [];   // bir sayfa yalnız bir türe aktarılır

        // kategori => [sayfa adı parçaları]
        $stokSayfa = [
            'demirbas' => ['DEMIRBAS'],
            'sarf'     => ['SARF MALZEME'],
            'el_aleti' => ['EL ALETLERI'],
        ];
        // [tür, kaynak, sayfa adı parçaları]
        $hrkSayfa = [
            ['giris', 'taseron', ['TASERON MALZEME GIRIS']],
            ['cikis', 'taseron', ['TASERON MALZEME TESLIMAT', 'TASERON MALZEME CIKIS']],
            ['giris', 'depo',    ['MALZEME GIRIS']],
            ['cikis', 'depo',    ['MALZEME CIKIS']],
        ];

        try {
            $pdoDepo->beginTransaction();

            // ── STOK ────────────────────────────────────────────────────────
            $ins = $pdoDepo->prepare("INSERT INTO depo_kalemler
                (kategori,sira,kod,ad,ozellik,birim,sayim,gelen,giden,birim_fiyat,disiplin,alan,alan_kisi)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($stokSayfa as $kat => $parcalar) {
                $si = dpSheet($x, $parcalar, $kullanilanSayfa);
                if ($si === null) { $atlanan[] = dp_katAd($kat) . ' sayfası bulunamadı'; continue; }
                $kullanilanSayfa[] = $si;
                $rows = $x->rows($si, 8000);
                $hr   = dpBaslikSatiri($rows, ['MALZEME ADI', 'SAYIM', 'STOK', 'BIRIM']);
                $h    = dpHarita($rows[$hr] ?? [], DP_STOK_ALAN);
                // "BİRİM" ile "BİRİM FİYAT" aynı hücreye denk gelmesin
                if (isset($h['birim'], $h['birim_fiyat']) && $h['birim'] === $h['birim_fiyat']) unset($h['birim']);
                if (!isset($h['ad'])) { $atlanan[] = dp_katAd($kat) . ': "MALZEME ADI" sütunu bulunamadı'; continue; }

                $pdoDepo->prepare("DELETE FROM depo_kalemler WHERE kategori=?")->execute([$kat]);
                $varsayilanBirim = $GLOBALS['DP_KATEGORI'][$kat]['birim'] ?? 'Ad';
                for ($i = $hr + 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    $ad  = dpAl($row, $h, 'ad');
                    if ($ad === '') continue;
                    $ins->execute([
                        $kat,
                        (int)dpAl($row,$h,'sira') ?: null,
                        dpAl($row,$h,'kod') ?: null,
                        $ad,
                        dpAl($row,$h,'ozellik') ?: null,
                        dpAl($row,$h,'birim') ?: $varsayilanBirim,
                        dp_sayi(dpAl($row,$h,'sayim')),
                        dp_sayi(dpAl($row,$h,'gelen')),
                        dp_sayi(dpAl($row,$h,'giden')),
                        dp_sayi(dpAl($row,$h,'birim_fiyat')) ?: null,
                        dpAl($row,$h,'disiplin') ?: null,
                        dpAl($row,$h,'alan') ?: null,
                        dpAl($row,$h,'alan_kisi') ?: null,
                    ]);
                    $r[$kat]++;
                }
                $bulunan[] = dp_katAd($kat) . ' (' . $r[$kat] . ')';
            }

            // ── HAREKET ─────────────────────────────────────────────────────
            $insH = $pdoDepo->prepare("INSERT INTO depo_hareketler
                (tur,kaynak,tarih,belge_tarihi,belge_no,malzeme,ozellik,birim,miktar,firma,teslim_alan,onay,lokasyon,aciklama)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($hrkSayfa as [$tur, $kaynak, $parcalar]) {
                $si = dpSheet($x, $parcalar, $kullanilanSayfa);
                if ($si === null) continue;
                $kullanilanSayfa[] = $si;
                $rows = $x->rows($si, 8000);
                $hr   = dpBaslikSatiri($rows, ['TARIH', 'MALZEME', 'MIKTAR', 'BIRIM', 'FIRMA', 'ONAY']);
                $h    = dpHarita($rows[$hr] ?? [], DP_HRK_ALAN);
                if (isset($h['birim'], $h['miktar']) && $h['birim'] === $h['miktar']) unset($h['birim']);
                if (!isset($h['malzeme'])) { $atlanan[] = dp_turAd($tur).'/'.dp_kaynakAd($kaynak).': malzeme sütunu bulunamadı'; continue; }

                $anahtar = $tur . '|' . $kaynak;
                $pdoDepo->prepare("DELETE FROM depo_hareketler WHERE tur=? AND kaynak=?")->execute([$tur, $kaynak]);
                for ($i = $hr + 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    $mal = dpAl($row, $h, 'malzeme');
                    if ($mal === '') continue;
                    $insH->execute([
                        $tur, $kaynak,
                        dp_tarih(dpAl($row,$h,'tarih')),
                        dp_tarih(dpAl($row,$h,'belge_tarihi')),
                        dpAl($row,$h,'belge_no') ?: null,
                        mb_substr($mal, 0, 255),
                        dpAl($row,$h,'ozellik') ?: null,
                        dpAl($row,$h,'birim') ?: null,
                        dp_sayi(dpAl($row,$h,'miktar')),
                        dpAl($row,$h,'firma') ?: null,
                        dpAl($row,$h,'teslim_alan') ?: null,
                        dpAl($row,$h,'onay') ?: null,
                        dpAl($row,$h,'lokasyon') ?: null,
                        dpAl($row,$h,'aciklama') ?: null,
                    ]);
                    $r[$anahtar]++;
                }
                $bulunan[] = dp_kaynakAd($kaynak) . ' ' . dp_turAd($tur) . ' (' . $r[$anahtar] . ')';
            }

            $pdoDepo->commit();
            $sonuc = $r;
            if (!$bulunan) { $hata = 'Bu dosyada tanınan sayfa bulunamadı.'; $sonuc = null; }
        } catch (Throwable $e) {
            if ($pdoDepo->inTransaction()) $pdoDepo->rollBack();
            $hata = 'İçe aktarma hatası: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
?>
<h4 class="mb-3"><i class="bi bi-cloud-arrow-up text-primary me-2"></i>Depo Excel İçe Aktarma</h4>

<?php if($hata): ?><div class="alert alert-danger"><?= h($hata) ?></div><?php endif; ?>
<?php if($sonuc): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-1"></i><strong>İçe aktarma tamam.</strong>
    <div class="row g-2 mt-2 small">
        <div class="col-md-6">
            <div class="fw-semibold">Stok kalemleri</div>
            <div><?= $fmt0($sonuc['demirbas']) ?> demirbaş · <?= $fmt0($sonuc['sarf']) ?> sarf malzeme · <?= $fmt0($sonuc['el_aleti']) ?> el aleti</div>
        </div>
        <div class="col-md-6">
            <div class="fw-semibold">Hareketler</div>
            <div>Depo: <?= $fmt0($sonuc['giris|depo']) ?> giriş · <?= $fmt0($sonuc['cikis|depo']) ?> çıkış</div>
            <div>Taşeron: <?= $fmt0($sonuc['giris|taseron']) ?> giriş · <?= $fmt0($sonuc['cikis|taseron']) ?> teslimat</div>
        </div>
    </div>
    <div class="small mt-2">
        <a href="index.php" class="alert-link">Dashboard</a> ·
        <a href="kalemler.php?k=demirbas" class="alert-link">Demirbaşlar</a> ·
        <a href="hareketler.php" class="alert-link">Hareketler</a>
    </div>
</div>
<?php endif; ?>
<?php if($atlanan): ?>
<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
    Atlanan: <?= h(implode(' · ', $atlanan)) ?>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <div class="col-md-9">
            <label class="form-label small">Depo Excel dosyası (.xlsx)</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required>
        </div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i>Aktar / Eşitle</button></div>
    </form>
</div></div>

<div class="card"><div class="card-body small">
    <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Tanınan sayfalar</div>
    <div class="table-responsive">
    <table class="table table-sm table-bordered mb-2" style="max-width:720px">
        <thead class="table-light"><tr><th>Sayfa</th><th>Nereye yazılır</th><th>Yenileme</th></tr></thead>
        <tbody>
            <tr><td>DEMİRBAŞLAR</td><td>Stok — Demirbaşlar</td><td>kategori tam yenileme</td></tr>
            <tr><td>… SARF MALZEME</td><td>Stok — Sarf Malzeme</td><td>kategori tam yenileme</td></tr>
            <tr><td>EL ALETLERİ</td><td>Stok — El Aletleri</td><td>kategori tam yenileme</td></tr>
            <tr><td>MALZEME GİRİŞ</td><td>Hareket — Depo Girişi</td><td>tür tam yenileme</td></tr>
            <tr><td>MALZEME ÇIKIŞ</td><td>Hareket — Depo Çıkışı</td><td>tür tam yenileme</td></tr>
            <tr><td>TAŞERON MALZEME GİRİŞ</td><td>Hareket — Taşeron Girişi</td><td>tür tam yenileme</td></tr>
            <tr><td>TAŞERON MALZEME TESLİMAT</td><td>Hareket — Taşeron Çıkışı</td><td>tür tam yenileme</td></tr>
        </tbody>
    </table>
    </div>
    <div class="text-muted">
        Sayfa adı <strong>içerir</strong> mantığıyla eşleşir — "KARTAL-BATIYAKASI SARF MALZEME" de bulunur.
        Sütunlar başlık metninden okunur, sabit sıra beklenmez.
        Dosyada bulunmayan sayfalar <strong>silinmez</strong>, dokunulmadan bırakılır;
        böylece stok ve hareket dosyalarını ayrı ayrı yükleyebilirsiniz.
    </div>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
