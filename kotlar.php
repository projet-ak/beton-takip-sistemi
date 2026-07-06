<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Kotlar — Beton Takip Sistemi';

// Kot detayı (kat etiketi: "9. BK", "ZEMİN KAT"...) — eski kurulumlara kolon ekle
if (!$pdo->query("SHOW COLUMNS FROM kotlar LIKE 'aciklama'")->fetchColumn()) {
    $pdo->exec("ALTER TABLE kotlar ADD COLUMN aciklama VARCHAR(200) NULL AFTER kot_degeri");
}

function kot_norm(string $s): string {
    return str_replace(' ', '', mb_strtoupper(trim($s), 'UTF-8'));
}

// ── AJAX: bir kottaki dökümler (hangi blok, hangi kot, ne yapılmış) ───────────
if (isset($_GET['dokum']) && ctype_digit($_GET['dokum'])) {
    $kid = (int)$_GET['dokum'];
    $q = $pdo->prepare("
        SELECT i.id, i.tarih, i.irsaliye_no, i.tip, i.miktar, i.durum,
               bs.ad beton_sinifi, f.ad firma, ig.ad grup, aik.ad kalem
        FROM irsaliyeler i
        LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
        LEFT JOIN firmalar f ON f.id = i.firma_id
        LEFT JOIN imalat_gruplari ig ON ig.id = i.imalat_grup_id
        LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
        WHERE i.kot_id = ? AND i.durum <> 'reddedildi'
        ORDER BY i.tarih DESC, i.id DESC LIMIT 300");
    $q->execute([$kid]);
    $irs = $q->fetchAll();
    $fmt2 = fn($n) => number_format((float)$n, 2, ',', '.');
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="table-responsive" style="max-height:420px">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Tarih</th><th>İrsaliye No</th><th>Tip</th><th>İmalat</th><th>Beton</th><th>Firma</th><th class="text-end">Miktar (m³)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($irs as $r): ?>
            <tr>
                <td class="text-nowrap"><?= format_date($r['tarih']) ?></td>
                <td class="font-monospace small"><?= h($r['irsaliye_no'] ?: '—') ?></td>
                <td><?= $r['tip']==='iade' ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">İade</span>' : '<span class="badge bg-success-subtle text-success border border-success-subtle">Alış</span>' ?></td>
                <td class="small"><?= h(trim(($r['grup']?:'').' → '.($r['kalem']?:''),' →') ?: '—') ?></td>
                <td class="small"><span class="badge bg-secondary"><?= h($r['beton_sinifi'] ?: '—') ?></span></td>
                <td class="small"><?= h($r['firma'] ?: '—') ?></td>
                <td class="text-end fw-semibold <?= $r['tip']==='iade'?'text-danger':'' ?>"><?= ($r['tip']==='iade'?'−':'').$fmt2($r['miktar']) ?></td>
                <td class="text-end"><a href="irsaliye_detay.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-xs btn-outline-primary" title="İrsaliyeye git"><i class="bi bi-box-arrow-up-right"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$irs): ?><tr><td colspan="8" class="text-center text-muted py-4">Bu kotta henüz döküm yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
    exit;
}

// ── Excel VERİ sekmesinden kat etiketlerini yükle (kot değeri → KAT adı) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'etiket_yukle' && !empty($_FILES['dosya']['tmp_name'])) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (!($x = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        flash('error', 'Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError());
    } else {
        $vi = null;
        foreach ($x->sheetNames() as $i=>$n) { if (mb_strtoupper(trim($n),'UTF-8') === 'VERİ') { $vi = $i; break; } }
        if ($vi === null) {
            flash('error', '"VERİ" sekmesi bulunamadı.');
        } else {
            // Kot → KAT haritası: "D PARSEL KOTLAR" ve "KAT" başlıklı bitişik kolon çifti
            $rows = $x->rows($vi, 3000);
            $kotCol = -1; $katCol = -1;
            foreach ($rows as $ri=>$r) {
                foreach ($r as $ci=>$v) {
                    $s = mb_strtoupper(trim((string)$v),'UTF-8');
                    if ($kotCol < 0 && strpos($s,'KOTLAR') !== false) $kotCol = $ci;
                    if ($katCol < 0 && $s === 'KAT') $katCol = $ci;
                }
                if ($ri > 5) break;
            }
            if ($kotCol < 0 || $katCol < 0) {
                flash('error', 'VERİ sekmesinde "KOTLAR" / "KAT" kolon çifti bulunamadı.');
            } else {
                $harita = [];
                foreach ($rows as $r) {
                    $kv = trim((string)($r[$kotCol] ?? '')); $ka = trim((string)($r[$katCol] ?? ''));
                    if ($kv === '' || $ka === '' || mb_stripos($kv,'KOTLAR') !== false) continue;
                    $harita[kot_norm($kv)] = $ka; // aynı kot değeri tek kat etiketine gider
                }
                $upd = 0;
                if ($harita) {
                    $kotlarBos = $pdo->query("SELECT id, kot_degeri FROM kotlar WHERE aciklama IS NULL OR aciklama = ''")->fetchAll();
                    $set = $pdo->prepare("UPDATE kotlar SET aciklama = ? WHERE id = ?");
                    foreach ($kotlarBos as $k) {
                        $n = kot_norm($k['kot_degeri']);
                        if (isset($harita[$n])) { $set->execute([$harita[$n], (int)$k['id']]); $upd++; }
                    }
                }
                flash('success', "VERİ sekmesinden ".count($harita)." kot→kat eşlemesi okundu; boş olan {$upd} kot detayı dolduruldu (dolu olanlara dokunulmadı).");
            }
        }
    }
    redirect('kotlar.php');
}

// ── Excel KOT sekmesinden blok→kot yapısını içe aktar ─────────────────────────
// KOT sayfası: her SÜTUN bir blok (başlık satırı), altındaki değerler o bloğun kotları.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kot_yukle' && !empty($_FILES['dosya']['tmp_name'])) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (!($x = \Shuchkin\SimpleXLSX::parse($_FILES['dosya']['tmp_name']))) {
        flash('error', 'Excel okunamadı: ' . \Shuchkin\SimpleXLSX::parseError());
        redirect('kotlar.php');
    }
    // KOT sekmesini bul
    $ki = null;
    foreach ($x->sheetNames() as $i=>$n) { if (mb_strtoupper(trim($n),'UTF-8') === 'KOT') { $ki = $i; break; } }
    if ($ki === null) { flash('error', '"KOT" sekmesi bulunamadı.'); redirect('kotlar.php'); }

    // Parsel OTOMATİK: kullanıcıya sormuyoruz. Varsa mevcut ilk parsel; yoksa
    // Sayfa1'deki proje başlığından (ör. "KARTAL ESENTEPE") ya da "GENEL" ile oluştur.
    $parselId = (int)($pdo->query("SELECT id FROM parseller ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    if (!$parselId) {
        $parselAd = 'GENEL';
        // Sayfa1'de proje adını yakalamaya çalış (ilk sekme, ilk birkaç satır, dolu metin hücresi)
        try {
            $s1 = $x->rows(0, 6);
            foreach ($s1 as $r) { foreach ($r as $c) { $c = trim((string)$c);
                if ($c !== '' && !preg_match('/^[+\-]?[0-9.,]+$/', $c) && mb_strlen($c) >= 5 && strncmp($c,'VERİ',4)!==0) { $parselAd = mb_substr($c,0,100,'UTF-8'); break 2; } } }
        } catch (Throwable $e) {}
        $pdo->prepare("INSERT INTO parseller (ad, aktif) VALUES (?,1)")->execute([$parselAd]);
        $parselId = (int)$pdo->lastInsertId();
    }

    $rows = $x->rows($ki, 500);
    if (!$rows) { flash('error', 'KOT sekmesi boş.'); redirect('kotlar.php'); }
    $baslik = $rows[0]; // sütun başlıkları = blok adları

    // Blok GLOBAL eşleşir (ad UPPER, hangi parselde olursa olsun) — yoksa varsayılan parsele oluştur
    $blokBulGlobal = $pdo->prepare("SELECT id FROM bloklar WHERE UPPER(TRIM(ad))=UPPER(TRIM(?)) ORDER BY id LIMIT 1");
    $blokEkle = $pdo->prepare("INSERT INTO bloklar (parsel_id, ad, aktif) VALUES (?,?,1)");
    // Kot get-or-create (blok içinde kot_degeri UPPER/boşluksuz eşleşir); mevcutsa sıra güncelle
    $kotVar   = $pdo->prepare("SELECT id FROM kotlar WHERE blok_id=? AND REPLACE(UPPER(TRIM(kot_degeri)),' ','')=? LIMIT 1");
    $kotEkle  = $pdo->prepare("INSERT INTO kotlar (blok_id, kot_degeri, sira, aktif) VALUES (?,?,?,1)");
    $kotSira  = $pdo->prepare("UPDATE kotlar SET sira=? WHERE id=?");

    $yeniBlok = 0; $yeniKot = 0; $guncelKot = 0;
    $pdo->beginTransaction();
    try {
        foreach ($baslik as $ci => $blokAd) {
            $blokAd = trim((string)$blokAd);
            if ($blokAd === '' || $ci === 0) continue; // ilk kolon boş/etiket
            // blok global bul/oluştur
            $blokBulGlobal->execute([$blokAd]);
            $bid = (int)($blokBulGlobal->fetchColumn() ?: 0);
            if (!$bid) { $blokEkle->execute([$parselId, $blokAd]); $bid = (int)$pdo->lastInsertId(); $yeniBlok++; }
            // bu sütundaki kot değerleri (başlık satırından sonrası) — toplu eşitle
            $sira = 0;
            for ($ri = 1; $ri < count($rows); $ri++) {
                $val = trim((string)($rows[$ri][$ci] ?? ''));
                if ($val === '') continue;
                $sira++;
                $norm = str_replace(' ', '', mb_strtoupper($val,'UTF-8'));
                $kotVar->execute([$bid, $norm]);
                $kid = (int)($kotVar->fetchColumn() ?: 0);
                if ($kid) { $kotSira->execute([$sira, $kid]); $guncelKot++; }   // varsa sırasını Excel'e göre güncelle
                else      { $kotEkle->execute([$bid, $val, $sira]); $yeniKot++; }
            }
        }
        $pdo->commit();
        flash('success', "KOT sekmesi eşitlendi: {$yeniBlok} yeni blok, {$yeniKot} yeni kot eklendi, {$guncelKot} mevcut kot güncellendi (sıra).");
    } catch (Throwable $e) { $pdo->rollBack(); flash('error', 'İçe aktarma hatası: '.$e->getMessage()); }
    redirect('kotlar.php');
}

// ── Sil ──────────────────────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id = (int)$_GET['sil'];
    $k = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE kot_id = ?");
    $k->execute([$id]);
    if ($k->fetchColumn() > 0) {
        flash('warning', 'Bu kot irsaliyelerde kullanılıyor, silinemez.');
    } else {
        $pdo->prepare("DELETE FROM kotlar WHERE id = ?")->execute([$id]);
        flash('success', 'Kot silindi.');
    }
    redirect('kotlar.php');
}

// ── Düzenle ───────────────────────────────────────────────────────────────────
$formError = '';
$duzenle   = null;

if (isset($_GET['duzenle']) && ctype_digit($_GET['duzenle'])) {
    $s = $pdo->prepare("SELECT * FROM kotlar WHERE id = ?");
    $s->execute([(int)$_GET['duzenle']]);
    $duzenle = $s->fetch() ?: null;
}

// ── Kaydet / Güncelle ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'etiket_yukle') {
    $id         = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : null;
    $kot_degeri = trim($_POST['kot_degeri'] ?? '');
    $aciklama   = trim($_POST['aciklama'] ?? '');
    $blok_id    = isset($_POST['blok_id']) && ctype_digit($_POST['blok_id']) ? (int)$_POST['blok_id'] : null;
    $sira       = isset($_POST['sira']) && is_numeric($_POST['sira']) ? (int)$_POST['sira'] : 0;
    if (!$kot_degeri || !$blok_id) {
        $formError = 'Kot değeri ve blok alanları zorunludur.';
    } else {
        try {
            if ($id) {
                $pdo->prepare("UPDATE kotlar SET kot_degeri = ?, aciklama = ?, blok_id = ?, sira = ? WHERE id = ?")
                    ->execute([$kot_degeri, $aciklama ?: null, $blok_id, $sira, $id]);
                flash('success', 'Kot güncellendi.');
            } else {
                $pdo->prepare("INSERT INTO kotlar (kot_degeri, aciklama, blok_id, sira) VALUES (?, ?, ?, ?)")
                    ->execute([$kot_degeri, $aciklama ?: null, $blok_id, $sira]);
                flash('success', 'Kot eklendi.');
            }
            redirect('kotlar.php');
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $formError = 'Bu isim zaten kayıtlı.';
            } else {
                throw $e;
            }
        }
    }
}

$formAcik = isset($_GET['ekle']) || $duzenle;

// ── Bloklar dropdown (parsel adı / blok adı) ──────────────────────────────────
$bloklar = $pdo->query("
    SELECT b.id, CONCAT(p.ad, ' / ', b.ad) AS etiket
    FROM bloklar b
    JOIN parseller p ON p.id = b.parsel_id
    ORDER BY p.ad, b.ad
")->fetchAll();

// ── Döküm özetleri (kot başına: kaç irsaliye, kaç m³, hangi imalatlar) ────────
$dokum = [];
foreach ($pdo->query("
    SELECT i.kot_id, COUNT(*) adet,
           SUM(CASE WHEN i.tip='alis' THEN i.miktar ELSE -i.miktar END) m3,
           GROUP_CONCAT(DISTINCT aik.ad ORDER BY aik.ad SEPARATOR ', ') kalemler
    FROM irsaliyeler i
    LEFT JOIN ana_is_kalemleri aik ON aik.id = i.ana_is_kalemi_id
    WHERE i.kot_id IS NOT NULL AND i.durum <> 'reddedildi'
    GROUP BY i.kot_id") as $r) {
    $dokum[(int)$r['kot_id']] = $r;
}

// ── Liste ─────────────────────────────────────────────────────────────────────
$liste = $pdo->query("
    SELECT k.*, b.ad AS blok_adi, b.id AS b_id, p.ad AS parsel_adi
    FROM kotlar k
    JOIN bloklar b ON b.id = k.blok_id
    JOIN parseller p ON p.id = b.parsel_id
    ORDER BY p.ad, b.ad, k.sira, k.kot_degeri
")->fetchAll();

// Blok → kot gruplaması (blok ve katlar hiyerarşisi)
$grup = [];
foreach ($liste as $r) {
    $bid = (int)$r['b_id'];
    if (!isset($grup[$bid])) {
        $grup[$bid] = ['blok'=>$r['blok_adi'], 'parsel'=>$r['parsel_adi'], 'kots'=>[], 'toplam_m3'=>0.0, 'irs'=>0];
    }
    $d = $dokum[(int)$r['id']] ?? null;
    $grup[$bid]['kots'][] = $r;
    if ($d) { $grup[$bid]['toplam_m3'] += (float)$d['m3']; $grup[$bid]['irs'] += (int)$d['adet']; }
}

require_once __DIR__ . '/includes/header.php';
$fmt = fn($n) => number_format((float)$n, 2, ',', '.');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-arrow-up-square text-primary me-2"></i>Kotlar</h4>
        <small class="text-muted">Hangi blokta hangi kotta ne yapılmış — döküm rakamına tıklayın</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#kotYukle">
            <i class="bi bi-diagram-3 me-1"></i> KOT Sekmesinden Blok+Kot Aktar
        </button>
        <button class="btn btn-outline-success" data-bs-toggle="collapse" data-bs-target="#etiketYukle">
            <i class="bi bi-file-earmark-excel me-1"></i> VERİ'den Kat Etiketleri
        </button>
        <a href="kotlar.php?ekle=1" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Yeni Kot
        </a>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>

<div class="collapse mb-3" id="kotYukle"><div class="card card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="kot_yukle">
        <div class="col-md-9">
            <label class="form-label small">Beton Takip Excel (.xlsx) — <strong>KOT</strong> sekmesi tümüyle okunur (her sütun bir blok, altındaki değerler o bloğun kotları)</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required>
        </div>
        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-arrow-repeat me-1"></i> KOT Sekmesini Eşitle</button></div>
    </form>
    <div class="form-text mt-1">
        Tek tıkla <strong>toplu eşitleme</strong>: tüm bloklar ve kotları Excel'e göre işlenir. Parsel <strong>otomatik</strong> atanır (sormaz);
        bloklar <strong>ad bazında</strong> eşleşir (hangi parselde olursa olsun). Mevcut kotlar çoğaltılmaz, yalnız sıraları Excel'e göre güncellenir.
    </div>
</div></div>

<div class="collapse mb-3" id="etiketYukle"><div class="card card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="action" value="etiket_yukle">
        <div class="col-md-8">
            <label class="form-label small">Beton Takip Excel (.xlsx) — <strong>VERİ</strong> sekmesindeki kot→KAT eşlemesi okunur (ör. +58,40 → 9. BK)</label>
            <input type="file" name="dosya" class="form-control form-control-sm" accept=".xlsx" required>
        </div>
        <div class="col-md-4"><button class="btn btn-success btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i> Etiketleri Doldur</button></div>
    </form>
    <div class="form-text mt-1">Yalnız <strong>detayı boş</strong> olan kotlar doldurulur; elle girdiğiniz detaylar korunur.</div>
</div></div>

<?php if ($formAcik): ?>
<div class="card mb-4">
    <div class="card-header <?= $duzenle ? 'bg-warning text-dark' : 'bg-primary text-white' ?> fw-semibold">
        <i class="bi bi-<?= $duzenle ? 'pencil' : 'plus-circle' ?> me-1"></i>
        <?= $duzenle ? 'Kot Düzenle' : 'Yeni Kot Ekle' ?>
    </div>
    <div class="card-body">
        <?php if ($formError): ?>
            <div class="alert alert-danger"><?= h($formError) ?></div>
        <?php endif; ?>
        <form method="post">
            <?php if ($duzenle): ?>
                <input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>">
            <?php endif; ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Blok <span class="text-danger">*</span></label>
                    <select name="blok_id" class="form-select" required>
                        <option value="">— Seçiniz —</option>
                        <?php foreach ($bloklar as $b): ?>
                            <option value="<?= $b['id'] ?>"
                                <?= ((int)($duzenle['blok_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
                                <?= h($b['etiket']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Kot Değeri <span class="text-danger">*</span></label>
                    <input name="kot_degeri" class="form-control" required value="<?= h($duzenle['kot_degeri'] ?? '') ?>" placeholder="+72,50">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Detay (kat / açıklama)</label>
                    <input name="aciklama" class="form-control" value="<?= h($duzenle['aciklama'] ?? '') ?>" placeholder="ör. 5. BK / ZEMİN KAT / temel betonu">
                </div>
                <div class="col-md-1">
                    <label class="form-label">Sıra</label>
                    <input name="sira" type="number" class="form-control" value="<?= (int)($duzenle['sira'] ?? 0) ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-success"><i class="bi bi-save me-1"></i><?= $duzenle ? 'Güncelle' : 'Kaydet' ?></button>
                    <a href="kotlar.php" class="btn btn-secondary"><i class="bi bi-x-circle me-1"></i>İptal</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$grup): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
    Henüz kot yok. <strong>KOT sekmesinden</strong> içe aktarabilir veya <a href="kotlar.php?ekle=1">yeni kot</a> ekleyebilirsiniz.
</div></div>
<?php else: ?>
<div class="accordion" id="blokAccordion">
    <?php $bi = 0; foreach ($grup as $bid => $g): $bi++; $acId = 'blok'.$bid; ?>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button <?= $bi>1?'collapsed':'' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $acId ?>">
                <div class="d-flex align-items-center gap-2 flex-wrap w-100 pe-3">
                    <i class="bi bi-building text-primary"></i>
                    <span class="fw-bold"><?= h($g['blok']) ?></span>
                    <span class="text-muted small">(<?= h($g['parsel']) ?>)</span>
                    <span class="badge bg-secondary ms-1"><?= count($g['kots']) ?> kot</span>
                    <?php if ($g['toplam_m3'] > 0): ?>
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto"><?= $fmt($g['toplam_m3']) ?> m³ dökülmüş · <?= (int)$g['irs'] ?> irsaliye</span>
                    <?php endif; ?>
                </div>
            </button>
        </h2>
        <div id="<?= $acId ?>" class="accordion-collapse collapse <?= $bi===1?'show':'' ?>" data-bs-parent="#blokAccordion">
            <div class="accordion-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:60px">#</th>
                                <th>Kot Değeri</th>
                                <th>Detay (Kat)</th>
                                <th class="text-end">Dökülen (m³)</th>
                                <th class="text-center">İrsaliye</th>
                                <th>Yapılan İmalatlar</th>
                                <th class="text-end">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn=0; foreach ($g['kots'] as $r): $d = $dokum[(int)$r['id']] ?? null; $sn++; ?>
                            <tr>
                                <td class="text-muted small"><?= $sn ?></td>
                                <td class="fw-semibold font-monospace"><?= h($r['kot_degeri']) ?></td>
                                <td><?= $r['aciklama'] ? '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">'.h($r['aciklama']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end">
                                    <?php if ($d): ?>
                                        <a href="#" class="kot-dokum text-decoration-none fw-bold" data-id="<?= $r['id'] ?>"
                                           data-etiket="<?= h($g['parsel'].' / '.$g['blok'].' / '.$r['kot_degeri'].($r['aciklama']?' ('.$r['aciklama'].')':'')) ?>"><?= $fmt($d['m3']) ?></a>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="text-center"><?= $d ? '<span class="badge bg-light text-dark border">'.(int)$d['adet'].'</span>' : '<span class="text-muted">—</span>' ?></td>
                                <td class="small text-muted" style="max-width:280px"><?= $d && $d['kalemler'] ? h($d['kalemler']) : '—' ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="kotlar.php?duzenle=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
                                    <a href="kotlar.php?sil=<?= $r['id'] ?>" class="btn btn-xs btn-outline-danger"
                                       onclick="return confirm('Bu kotu silmek istediğinize emin misiniz?')"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Kot döküm popup -->
<div class="modal fade" id="dokumModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-droplet-half me-1"></i><span id="dokumBaslik">Dökümler</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="dokumBody"></div>
  </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(document.getElementById('dokumModal'));
    document.addEventListener('click', function(e){
        var a = e.target.closest('.kot-dokum');
        if (!a) return;
        e.preventDefault();
        document.getElementById('dokumBaslik').textContent = a.getAttribute('data-etiket');
        var body = document.getElementById('dokumBody');
        body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…</div>';
        modal.show();
        fetch('kotlar.php?dokum=' + encodeURIComponent(a.getAttribute('data-id')))
            .then(function(r){ return r.text(); })
            .then(function(html){ body.innerHTML = html; })
            .catch(function(){ body.innerHTML = '<div class="alert alert-danger mb-0">İçerik yüklenemedi.</div>'; });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
