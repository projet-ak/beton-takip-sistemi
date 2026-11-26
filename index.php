<?php
/**
 * index.php — Dashboard
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// config.php yoksa kuruluma yönlendir
if (!file_exists(__DIR__ . '/config.php')) {
    redirect('install.php');
}

require_auth();
require_once __DIR__ . '/includes/db.php';
beton_onay_gocu($pdo);   // onay akışı kapalı: bekleyenler geçerli sayılır

// Günlük otomatik yedek — admin dashboard'a her girdiğinde kontrol eder.
// 5 veritabanının (beton/demir/seramik/depo/akaryakit) her biri ayrı dosyaya alınır.
if (is_admin()) {
    require_once __DIR__ . '/includes/yedekleme.php';
    try { yedek_otomatik_calistir(__DIR__ . '/backups'); } catch (Throwable $__e) { /* sessizce geç */ }
}

$pageTitle = 'Dashboard — Beton Takip Sistemi';

// ── Proje filtresi ────────────────────────────────────────────────────────────
$projeId = isset($_GET['proje_id']) && ctype_digit($_GET['proje_id']) ? (int)$_GET['proje_id'] : 0;

// Projeler listesi (filtre seçici için)
try {
    $projeler = $pdo->query("
        SELECT id, COALESCE(NULLIF(kod,''), CONCAT('Proje #',id)) AS kod, COALESCE(aciklama,'') AS aciklama, aktif
        FROM projeler
        ORDER BY aktif DESC, kod
    ")->fetchAll();
} catch (Exception $e) {
    $projeler = [];
}

// Aktif proje sayısı
$aktifProjeSay = 0;
try {
    $aktifProjeSay = (int)$pdo->query("SELECT COUNT(*) FROM projeler WHERE aktif=1")->fetchColumn();
} catch (Exception $e) {
    $aktifProjeSay = 0;
}

// Proje filtresine göre WHERE
$projeFilter = '';
$projeParams = [];
if ($projeId > 0) {
    // Etkin proje = i.proje_id; yoksa parselin proje_id'si (özetlerle tutarlı — parsel→proje bağı).
    $projeFilter = ' AND COALESCE(i.proje_id, (SELECT p2.proje_id FROM parseller p2 WHERE p2.id = i.parsel_id)) = ?';
    $projeParams = [$projeId];
}

// ── Yardımcı: trend hesabı ────────────────────────────────────────────────────
function hesaplaTrend($yeni, $eski): ?array {
    if ((float)$eski == 0) return null;
    $pct = round(((float)$yeni - (float)$eski) / (float)$eski * 100, 1);
    return ['pct' => abs($pct), 'dir' => $pct >= 0 ? 'up' : 'down'];
}
function trendBadge(?array $t): string {
    if (!$t) return '';
    $cls  = $t['dir'] === 'up' ? 'trend-up'   : 'trend-down';
    $icon = $t['dir'] === 'up' ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    return '<span class="trend-badge ' . $cls . '"><i class="bi ' . $icon . '"></i>' . $t['pct'] . '%</span>';
}

// ── İstatistikler ─────────────────────────────────────────────────────────────
$st = $pdo->prepare("SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i WHERE i.tip='alis' AND i.durum<>'reddedildi'$projeFilter");
$st->execute($projeParams);
$toplamM3 = $st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler i WHERE i.tip='alis' AND i.durum<>'reddedildi'$projeFilter");
$st->execute($projeParams);
$irsaliyeSay = $st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler i WHERE i.tip='iade' AND i.durum<>'reddedildi'$projeFilter");
$st->execute($projeParams);
$iadeSay = $st->fetchColumn();

$tedarikciSay = $pdo->query("SELECT COUNT(*) FROM tedarikciler WHERE aktif=1")->fetchColumn();

// Bu ay
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i
     WHERE i.tip='alis' AND i.durum<>'reddedildi' AND DATE_FORMAT(i.tarih,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')$projeFilter"
);
$st->execute($projeParams);
$buAyM3 = $st->fetchColumn();

// Geçen ay (trend için)
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i
     WHERE i.tip='alis' AND i.durum<>'reddedildi' AND DATE_FORMAT(i.tarih,'%Y-%m')=DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 1 MONTH),'%Y-%m')$projeFilter"
);
$st->execute($projeParams);
$gecenAyM3 = $st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM irsaliyeler i
     WHERE i.tip='alis' AND i.durum<>'reddedildi' AND DATE_FORMAT(i.tarih,'%Y-%m')=DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 1 MONTH),'%Y-%m')$projeFilter"
);
$st->execute($projeParams);
$gecenAyIrsaliye = $st->fetchColumn();

// Bu hafta / geçen hafta
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i
     WHERE i.tip='alis' AND i.durum<>'reddedildi' AND YEARWEEK(i.tarih,1)=YEARWEEK(CURDATE(),1)$projeFilter"
);
$st->execute($projeParams);
$buHaftaM3 = $st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i
     WHERE i.tip='alis' AND i.durum<>'reddedildi' AND YEARWEEK(i.tarih,1)=YEARWEEK(DATE_SUB(CURDATE(),INTERVAL 7 DAY),1)$projeFilter"
);
$st->execute($projeParams);
$gecenHaftaM3 = $st->fetchColumn();

// Son teslimat
$st = $pdo->prepare(
    "SELECT i.tarih, i.miktar, i.irsaliye_no, t.ad AS tedarikci
     FROM irsaliyeler i LEFT JOIN tedarikciler t ON t.id=i.tedarikci_id
     WHERE i.tip='alis' AND i.durum<>'reddedildi'$projeFilter
     ORDER BY i.tarih DESC, i.id DESC LIMIT 1"
);
$st->execute($projeParams);
$sonTeslimat = $st->fetch();

// Günlük ortalama (bu ay gün sayısına göre)
$bugunGunNo  = (int)date('j');
$gunlukOrt   = $bugunGunNo > 0 ? round((float)$buAyM3 / $bugunGunNo, 1) : 0;

// Son 7 gün günlük dağılım (sparkline)
$st = $pdo->prepare(
    "SELECT DATE(tarih) AS gun, COALESCE(SUM(miktar),0) AS toplam
     FROM irsaliyeler i WHERE i.tip='alis' AND i.durum<>'reddedildi'
     AND i.tarih >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)$projeFilter
     GROUP BY DATE(tarih) ORDER BY gun"
);
$st->execute($projeParams);
$son7gunRaw = $st->fetchAll(PDO::FETCH_KEY_PAIR);

// 7 günlük dizi (boş günler 0)
$son7gunLabels = [];
$son7gunValues = [];
for ($d = 6; $d >= 0; $d--) {
    $gun = date('Y-m-d', strtotime("-$d days"));
    $son7gunLabels[] = date('d.m', strtotime($gun));
    $son7gunValues[] = (float)($son7gunRaw[$gun] ?? 0);
}

// Proje bazlı m³ özeti (top 5)
try {
    $st = $pdo->prepare(
        "SELECT p.kod, p.aciklama, COALESCE(SUM(i.miktar),0) AS toplam, COUNT(i.id) AS adet
         FROM projeler p
         LEFT JOIN irsaliyeler i
              ON COALESCE(i.proje_id, (SELECT p2.proje_id FROM parseller p2 WHERE p2.id = i.parsel_id)) = p.id
             AND i.tip='alis' AND i.durum<>'reddedildi'
         WHERE p.aktif=1
         GROUP BY p.id, p.kod, p.aciklama
         ORDER BY toplam DESC LIMIT 5"
    );
    $st->execute();
    $projeOzet = $st->fetchAll();
} catch (Exception $e) { $projeOzet = []; }

// ── Proje → Parsel → Blok → Kot hiyerarşisi (dökülen m³) ─────────────────────
// Etkin proje = i.proje_id; yoksa parselin proje_id'si (parsel→proje bağı).
$hiyerarsi = [];
try {
    $hq = "SELECT COALESCE(pr.kod,'—') AS proje, COALESCE(pr.aciklama,'') AS proje_ad,
                  COALESCE(par.ad,'— parselsiz —') AS parsel,
                  COALESCE(blk.ad,'— bloksuz —') AS blok,
                  COALESCE(kot.kot_degeri,'— kotsuz —') AS kot, COALESCE(kot.aciklama,'') AS kot_acik,
                  COUNT(*) AS adet, COALESCE(SUM(i.miktar),0) AS m3
           FROM irsaliyeler i
           LEFT JOIN parseller par ON par.id = i.parsel_id
           LEFT JOIN bloklar   blk ON blk.id = i.blok_id
           LEFT JOIN kotlar    kot ON kot.id = i.kot_id
           LEFT JOIN projeler  pr  ON pr.id  = COALESCE(i.proje_id, par.proje_id)
           WHERE i.tip='alis' AND i.durum<>'reddedildi'$projeFilter
           GROUP BY COALESCE(i.proje_id, par.proje_id), pr.kod, pr.aciklama, i.parsel_id, par.ad, i.blok_id, blk.ad, i.kot_id, kot.kot_degeri, kot.aciklama
           ORDER BY pr.kod, par.ad, blk.ad, kot.kot_degeri";
    $st = $pdo->prepare($hq);
    $st->execute($projeParams);
    foreach ($st->fetchAll() as $r) {
        $pk = $r['proje']; $pa = $r['parsel']; $bl = $r['blok'];
        if (!isset($hiyerarsi[$pk])) $hiyerarsi[$pk] = ['ad'=>$r['proje_ad'],'m3'=>0,'adet'=>0,'parseller'=>[]];
        $hiyerarsi[$pk]['m3'] += (float)$r['m3']; $hiyerarsi[$pk]['adet'] += (int)$r['adet'];
        if (!isset($hiyerarsi[$pk]['parseller'][$pa])) $hiyerarsi[$pk]['parseller'][$pa] = ['m3'=>0,'adet'=>0,'bloklar'=>[]];
        $hiyerarsi[$pk]['parseller'][$pa]['m3'] += (float)$r['m3']; $hiyerarsi[$pk]['parseller'][$pa]['adet'] += (int)$r['adet'];
        if (!isset($hiyerarsi[$pk]['parseller'][$pa]['bloklar'][$bl])) $hiyerarsi[$pk]['parseller'][$pa]['bloklar'][$bl] = ['m3'=>0,'adet'=>0,'kotlar'=>[]];
        $hiyerarsi[$pk]['parseller'][$pa]['bloklar'][$bl]['m3'] += (float)$r['m3']; $hiyerarsi[$pk]['parseller'][$pa]['bloklar'][$bl]['adet'] += (int)$r['adet'];
        $hiyerarsi[$pk]['parseller'][$pa]['bloklar'][$bl]['kotlar'][] = ['kot'=>$r['kot'],'acik'=>$r['kot_acik'],'m3'=>(float)$r['m3'],'adet'=>(int)$r['adet']];
    }
} catch (Throwable $e) { $hiyerarsi = []; }

// Bekleyen onaylar
$bekleyenSaha = 0;
$bekleyenTeknik = 0;
if (beton_onay_aktif()) try {   // onay akışı kapalıyken (varsayılan) bekleyen kayıt olmaz
    $bekleyenSaha   = (int)$pdo->query("SELECT COUNT(*) FROM irsaliyeler WHERE durum='beklemede'")->fetchColumn();
    $bekleyenTeknik = (int)$pdo->query("SELECT COUNT(*) FROM irsaliyeler WHERE durum='saha_onaylandi'")->fetchColumn();
} catch (Exception $e) {}

// Fatura eşleştirme + bekleyen belge durumu (dashboard aksiyon kartları)
$faturaOzet = null; $bekleyenBelge = 0;
try {
    // Eksik irsaliye sayacı yalnız BETON faturalarından — pompa kirası / fiyat farkı / hizmet
    // (liste includes/fatura.php FAT_TUR_IRSALIYESIZ ile AYNI tutulmalı)
    // faturasının irsaliyesi yoktur, "faturada var sistemde yok" diye sayılmamalı.
    // (tur kolonu eski kurulumlarda olmayabilir; yoksa eski davranış sürer.)
    $turVar = false;
    try { $turVar = (bool)$pdo->query("SHOW COLUMNS FROM faturalar LIKE 'tur'")->fetch(); } catch (Throwable $__e) {}
    $fs = $pdo->query("SELECT COUNT(*) adet, COALESCE(SUM(" .
            ($turVar ? "CASE WHEN tur IN ('pompa','fark','hizmet') THEN 0 ELSE eksik_adet END" : "eksik_adet")
          . "),0) eksik" . ($turVar ? ", COALESCE(SUM(tur IN ('pompa','fark','hizmet')),0) irsaliyesiz" : ", 0 irsaliyesiz")
          . " FROM faturalar")->fetch();
    if ($fs && (int)$fs['adet'] > 0) {
        $faturaOzet = [
            'adet'   => (int)$fs['adet'],
            'eksik'  => (int)$fs['eksik'],
            'irsaliyesiz' => (int)$fs['irsaliyesiz'],
            'bagli'  => (int)$pdo->query("SELECT COUNT(*) FROM irsaliyeler WHERE fatura_id IS NOT NULL")->fetchColumn(),
            'bagsiz' => (int)$pdo->query("SELECT COUNT(*) FROM irsaliyeler WHERE tip='alis' AND durum<>'reddedildi' AND fatura_id IS NULL")->fetchColumn(),
        ];
    }
} catch (Throwable $e) { $faturaOzet = null; }   // faturalar/fatura_id henüz kurulmadıysa gizle
try {
    $bd = __DIR__ . '/uploads/belge_bekleyen';
    if (is_dir($bd)) $bekleyenBelge = count(array_filter((array)glob($bd . '/*'),
        fn($g) => is_file($g) && !str_ends_with($g, '.json')));   // .json = bekleme not dosyası, belge değil
} catch (Throwable $e) { $bekleyenBelge = 0; }

// Kantar farkı özeti (bu ay) — Yıldızlar net − tedarikçi net, yalnız İKİ tartım da girilmiş
// irsaliyelerde. Eski hesap SUM(ABS(kantar_farki)) idi: tek tarafı boş/0 olan ya da Excel'den
// biri ton ("30.000" → 30) biri kg (30000) okunmuş satırlar tartımın tamamı kadar fark
// üretip toplamı milyonlarca kg'a çıkarıyordu. İki net arasında 2 kattan fazla fark varsa
// satır "birim şüpheli" sayılır, toplama girmez.
$kantarOzet = null;
try {
    $st = $pdo->prepare(
        "SELECT kantar_net_yildizlar y, kantar_net_tedarikci t
         FROM irsaliyeler i
         WHERE i.tip='alis' AND i.durum<>'reddedildi'
           AND kantar_net_yildizlar > 0 AND kantar_net_tedarikci > 0
           AND DATE_FORMAT(i.tarih,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')$projeFilter"
    );
    $st->execute($projeParams);
    $kantarOzet = ['n' => 0, 'net' => 0.0, 'mutlak' => 0.0, 'eksik' => 0, 'supheli' => 0];
    foreach ($st->fetchAll() as $r) {
        $y = (float)$r['y']; $t = (float)$r['t'];
        if (max($y, $t) > 2 * min($y, $t)) { $kantarOzet['supheli']++; continue; }
        $kantarOzet['n']++;
        $kantarOzet['net']    += $y - $t;
        $kantarOzet['mutlak'] += abs($y - $t);
        if ($y < $t) $kantarOzet['eksik']++;
    }
} catch (Exception $e) { $kantarOzet = null; }

// Trendler
$trendAy  = hesaplaTrend($buAyM3, $gecenAyM3);
$trendIrs = hesaplaTrend($irsaliyeSay, $gecenAyIrsaliye);
$trendHafta = hesaplaTrend($buHaftaM3, $gecenHaftaM3);

// Son 10 irsaliye
$st = $pdo->prepare("
    SELECT i.id, i.tip, i.irsaliye_no, i.tarih, i.miktar, i.birim, i.arac_plaka,
           t.ad AS tedarikci_adi,
           bs.ad AS beton_sinifi,
           p.ad AS parsel_adi,
           b.ad AS blok_adi,
           pr.kod AS proje_kod
    FROM irsaliyeler i
    LEFT JOIN tedarikciler t  ON t.id  = i.tedarikci_id
    LEFT JOIN beton_siniflari bs ON bs.id = i.beton_sinifi_id
    LEFT JOIN parseller p     ON p.id  = i.parsel_id
    LEFT JOIN bloklar b       ON b.id  = i.blok_id
    LEFT JOIN projeler pr     ON pr.id = i.proje_id
    WHERE 1=1 $projeFilter
    ORDER BY i.tarih DESC, i.id DESC
    LIMIT 10
");
$st->execute($projeParams);
$sonIrsaliyeler = $st->fetchAll();

// Aylık m³ (son 12 ay) – grafik
$st = $pdo->prepare("
    SELECT DATE_FORMAT(tarih,'%Y-%m') AS ay, SUM(miktar) AS toplam
    FROM irsaliyeler i
    WHERE i.tip='alis' AND i.durum<>'reddedildi' AND i.tarih >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $projeFilter
    GROUP BY ay
    ORDER BY ay
");
$st->execute($projeParams);
$aylikData = $st->fetchAll();

$chartLabels = json_encode(array_column($aylikData, 'ay'));
$chartValues = json_encode(array_map('floatval', array_column($aylikData, 'toplam')));

// Tedarikçi dağılımı
$st = $pdo->prepare("
    SELECT t.ad, COALESCE(SUM(i.miktar),0) AS toplam
    FROM tedarikciler t
    LEFT JOIN irsaliyeler i ON i.tedarikci_id = t.id AND i.tip='alis' AND i.durum<>'reddedildi'" . ($projeId > 0 ? " AND i.proje_id = ?" : "") . "
    WHERE t.aktif = 1
    GROUP BY t.id, t.ad
    ORDER BY toplam DESC
");
$st->execute($projeParams);
$dagılım = $st->fetchAll();

$pieLabels = json_encode(array_column($dagılım, 'ad'));
$pieValues = json_encode(array_map('floatval', array_column($dagılım, 'toplam')));

// Beton sınıfı dağılımı
$st = $pdo->prepare("
    SELECT bs.ad, COALESCE(SUM(i.miktar),0) AS toplam
    FROM beton_siniflari bs
    LEFT JOIN irsaliyeler i ON i.beton_sinifi_id = bs.id AND i.tip='alis' AND i.durum<>'reddedildi'" . ($projeId > 0 ? " AND i.proje_id = ?" : "") . "
    GROUP BY bs.id, bs.ad
    HAVING toplam > 0
    ORDER BY toplam DESC
    LIMIT 8
");
$st->execute($projeParams);
$betonDagilim = $st->fetchAll();

$betonLabels = json_encode(array_column($betonDagilim, 'ad'));
$betonValues = json_encode(array_map('floatval', array_column($betonDagilim, 'toplam')));

// Seçili projenin etiketi
$seciliProjeEtiket = '';
if ($projeId > 0) {
    foreach ($projeler as $p) {
        if ((int)$p['id'] === $projeId) {
            $seciliProjeEtiket = trim($p['kod'] . ' ' . $p['aciklama']);
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.kpi-card { display:flex; align-items:center; gap:.65rem; padding:.7rem 1rem; border-radius:11px;
            background:var(--bt-bg-soft); border:1px solid var(--bt-border-soft); }
.kpi-icon { width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0; }
.kpi-val  { font-size:.95rem;font-weight:700;line-height:1;color:var(--bt-text); }
.kpi-lbl  { font-size:.68rem;color:var(--bt-text-muted);margin-top:2px; }
.qa-btn   { display:flex;flex-direction:column;align-items:center;gap:.35rem;padding:.8rem;border-radius:12px;
            border:1.5px solid var(--bt-border);background:var(--bt-surface);color:var(--bt-text-muted);
            text-decoration:none;transition:.2s;font-size:.78rem;font-weight:600; }
.qa-btn i { font-size:1.4rem; }
.qa-btn:hover { border-color:var(--ern);color:var(--ern);background:var(--bt-tint);transform:translateY(-2px); }
.proje-bar-wrap { margin-bottom:.55rem; }
.proje-bar-label { display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem; }
.proje-bar-name  { font-size:.8rem;font-weight:600;color:var(--bt-text); }
.proje-bar-val   { font-size:.75rem;font-weight:700;color:var(--ern); }
.proje-bar-track { height:7px;border-radius:20px;background:var(--bt-border);overflow:hidden; }
.proje-bar-fill  { height:100%;border-radius:20px;background:linear-gradient(90deg,var(--ern-light),var(--ern-teal));transition:width .8s ease; }
</style>

<!-- Başlık + Filtre + Hızlı İşlemler -->
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-speedometer2 text-primary me-2"></i>Kontrol Paneli</h4>
        <?php if ($seciliProjeEtiket): ?>
        <div class="text-muted small mt-1"><i class="bi bi-funnel-fill me-1"></i>Filtre: <strong><?= h($seciliProjeEtiket) ?></strong></div>
        <?php endif; ?>
        <?php if ($sonTeslimat): ?>
        <div class="text-muted small mt-1">
            <i class="bi bi-clock me-1"></i>Son teslimat:
            <strong><?= format_date($sonTeslimat['tarih']) ?></strong>
            <?php if ($sonTeslimat['tedarikci']): ?> — <span class="text-primary"><?= h($sonTeslimat['tedarikci']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if (!empty($projeler)): ?>
        <form method="get" class="d-flex gap-2 align-items-center">
            <select name="proje_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:180px;">
                <option value="0">Tüm Projeler</option>
                <?php foreach ($projeler as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projeId === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= h(trim($p['kod'] . ' — ' . $p['aciklama'])) ?><?= $p['aktif'] ? '' : ' (Pasif)' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($projeId > 0): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Temizle"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Bekleyen Onay Uyarısı -->
<?php if (($bekleyenSaha > 0 || $bekleyenTeknik > 0) && can_edit()): ?>
<div class="row g-2 mb-3">
    <?php if ($bekleyenSaha > 0 && can_approve_saha()): ?>
    <div class="col-sm-6">
        <a href="irsaliyeler.php?tip=alis&durum=beklemede" class="d-flex align-items-center gap-3 p-3 rounded-3 text-decoration-none"
           style="background:rgba(255,193,7,.1);border:1.5px solid rgba(255,193,7,.35);">
            <div style="width:40px;height:40px;border-radius:10px;background:rgba(255,193,7,.2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                <i class="bi bi-hourglass-split" style="color:#e6a817"></i>
            </div>
            <div>
                <div class="fw-bold" style="color:#e6a817;font-size:.95rem;"><?= $bekleyenSaha ?> İrsaliye Saha Onayı Bekliyor</div>
                <div class="small text-muted">Saha şefi incelemesi gerekiyor</div>
            </div>
            <i class="bi bi-arrow-right ms-auto text-muted"></i>
        </a>
    </div>
    <?php endif; ?>
    <?php if ($bekleyenTeknik > 0 && can_approve_teknik()): ?>
    <div class="col-sm-6">
        <a href="irsaliyeler.php?tip=alis&durum=saha_onaylandi" class="d-flex align-items-center gap-3 p-3 rounded-3 text-decoration-none"
           style="background:rgba(0,88,78,.08);border:1.5px solid rgba(0,88,78,.2);">
            <div style="width:40px;height:40px;border-radius:10px;background:rgba(0,88,78,.12);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                <i class="bi bi-clipboard2-check" style="color:var(--ern)"></i>
            </div>
            <div>
                <div class="fw-bold" style="color:var(--ern);font-size:.95rem;"><?= $bekleyenTeknik ?> İrsaliye Teknik Onay Bekliyor</div>
                <div class="small text-muted">Teknik ofis incelemesi gerekiyor</div>
            </div>
            <i class="bi bi-arrow-right ms-auto text-muted"></i>
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Fatura & Belge durumu -->
<?php if (($faturaOzet || $bekleyenBelge > 0) && can_view_reports()): ?>
<div class="row g-3 mb-4">
    <?php if ($faturaOzet): ?>
    <div class="col-sm-6">
        <a href="fatura_eslestir.php<?= $faturaOzet['eksik'] > 0 ? '?eksik=1' : '' ?>" class="d-flex align-items-center gap-3 p-3 rounded-3 text-decoration-none h-100"
           style="background:rgba(13,110,253,.06);border:1.5px solid rgba(13,110,253,.2);">
            <div style="width:40px;height:40px;border-radius:10px;background:rgba(13,110,253,.12);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                <i class="bi bi-receipt-cutoff" style="color:#0d6efd"></i>
            </div>
            <div>
                <div class="fw-bold" style="color:#0d6efd;font-size:.95rem;">
                    <?= (int)$faturaOzet['adet'] ?> fatura işlendi · <?= (int)$faturaOzet['bagli'] ?> irsaliye bağlı
                </div>
                <div class="small text-muted">
                    <?= (int)$faturaOzet['bagsiz'] ?> irsaliye henüz faturasız<?= $faturaOzet['eksik'] > 0 ? ' · <span class="text-danger fw-semibold">'.(int)$faturaOzet['eksik'].' irsaliye faturada var ama sistemde yok — hangileri olduğunu gör</span>' : '' ?><?= !empty($faturaOzet['irsaliyesiz']) ? ' · '.(int)$faturaOzet['irsaliyesiz'].' pompa/fiyat farkı/hizmet faturası (irsaliyesiz)' : '' ?>
                </div>
            </div>
            <i class="bi bi-arrow-right ms-auto text-muted"></i>
        </a>
    </div>
    <?php endif; ?>
    <?php if ($bekleyenBelge > 0): ?>
    <div class="col-sm-6">
        <a href="belge_dagit.php" class="d-flex align-items-center gap-3 p-3 rounded-3 text-decoration-none h-100"
           style="background:rgba(220,53,69,.06);border:1.5px solid rgba(220,53,69,.25);">
            <div style="width:40px;height:40px;border-radius:10px;background:rgba(220,53,69,.12);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                <i class="bi bi-file-earmark-x" style="color:#dc3545"></i>
            </div>
            <div>
                <div class="fw-bold" style="color:#dc3545;font-size:.95rem;"><?= $bekleyenBelge ?> belge irsaliyesiz bekliyor</div>
                <div class="small text-muted">Eşleşmeyen kantar fişi/fatura — tıklayın, okunan bilgilerle ilgili irsaliyeye bağlayın</div>
            </div>
            <i class="bi bi-arrow-right ms-auto text-muted"></i>
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Hızlı İşlemler -->
<?php if (can_edit()): ?>
<?php $__qaN = 4 + (has_role('admin','teknik_ofis_admin') ? 1 : 0) + ((function_exists('toplu_yukleme_izinli') && toplu_yukleme_izinli('beton')) ? 1 : 0);
      $__qaCls = [4 => 'row-cols-2 row-cols-md-4', 5 => 'row-cols-3 row-cols-md-5', 6 => 'row-cols-3 row-cols-md-6'][$__qaN] ?? 'row-cols-3 row-cols-md-6'; ?>
<div class="row <?= $__qaCls ?> g-2 mb-4">
    <div class="col">
        <a href="irsaliye_form.php" class="qa-btn w-100">
            <i class="bi bi-plus-circle-fill" style="color:var(--ern)"></i>
            <span>Yeni İrsaliye</span>
        </a>
    </div>
    <div class="col">
        <a href="hizli_tarama.php" class="qa-btn w-100">
            <i class="bi bi-qr-code-scan" style="color:var(--ern-teal)"></i>
            <span>Hızlı Tara</span>
        </a>
    </div>
    <div class="col">
        <a href="irsaliyeler.php?tip=alis" class="qa-btn w-100">
            <i class="bi bi-list-ul" style="color:var(--ern-gold)"></i>
            <span>İrsaliyeler</span>
        </a>
    </div>
    <div class="col">
        <a href="raporlar.php" class="qa-btn w-100">
            <i class="bi bi-bar-chart-line" style="color:#3B82F6"></i>
            <span>Raporlar</span>
        </a>
    </div>
    <?php if (has_role('admin','teknik_ofis_admin')): ?>
    <div class="col">
        <a href="import.php" class="qa-btn w-100">
            <i class="bi bi-cloud-arrow-up" style="color:#6f42c1"></i>
            <span>Excel Aktar</span>
        </a>
    </div>
    <?php endif; ?>
    <?php if (function_exists('toplu_yukleme_izinli') && toplu_yukleme_izinli('beton')): ?>
    <div class="col">
        <a href="toplu_yukleme.php" class="qa-btn w-100">
            <i class="bi bi-cloud-upload" style="color:#0EA5C6"></i>
            <span>Toplu Yükleme</span>
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Ana İstatistik Kartları (ortak kpi_kart bileşeni) -->
<?php
$__ayAd = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
$__trendAlt = function (?array $t, string $metin) {
    return $t ? trendBadge($t) . ' ' . $metin : $metin;
};
$__qp = $projeId > 0 ? '&proje_id=' . $projeId : '';
echo kpi_satir([
    ['ad' => 'Toplam Beton', 'deger' => (float)$toplamM3, 'ondalik' => 1, 'birim' => 'm³', 'ikon' => 'bi-box-seam',
     'renk' => 'green', 'link' => 'raporlar.php',
     'alt' => $sonTeslimat ? 'Son teslimat <strong>' . format_date($sonTeslimat['tarih']) . '</strong>' : 'Henüz teslimat yok'],
    ['ad' => 'Bu Ay (' . $__ayAd[(int)date('n')] . ')', 'deger' => (float)$buAyM3, 'ondalik' => 1, 'birim' => 'm³',
     'ikon' => 'bi-calendar-month', 'renk' => 'teal', 'link' => 'irsaliyeler.php?tip=alis&yil=' . date('Y') . '&ay=' . (int)date('n') . $__qp,
     'alt' => $__trendAlt($trendAy, 'geçen aya göre · geçen ay <strong>' . format_number($gecenAyM3, 1) . ' m³</strong>')],
    ['ad' => 'Alış İrsaliyesi', 'deger' => (int)$irsaliyeSay, 'ikon' => 'bi-file-earmark-text', 'renk' => 'gold',
     'link' => 'irsaliyeler.php?tip=alis' . $__qp,
     'alt' => $faturaOzet ? '<strong>' . format_number($faturaOzet['bagsiz'], 0) . '</strong> henüz faturasız' : 'reddedilenler hariç'],
    ['ad' => 'İade İrsaliyesi', 'deger' => (int)$iadeSay, 'ikon' => 'bi-arrow-up-circle', 'renk' => 'red',
     'link' => 'irsaliyeler.php?tip=iade' . $__qp, 'alt_metin' => 'santrale geri gönderilen'],
    ['ad' => 'Aktif Proje', 'deger' => (int)$aktifProjeSay, 'ikon' => 'bi-diagram-3', 'renk' => 'blue',
     'link' => 'projeler.php', 'alt_metin' => 'parsel → blok → kot ağacı aşağıda'],
    ['ad' => 'Aktif Tedarikçi', 'deger' => (int)$tedarikciSay, 'ikon' => 'bi-truck', 'renk' => 'slate',
     'link' => 'tedarikciler.php',
     'alt' => $sonTeslimat && $sonTeslimat['tedarikci'] ? 'son: <strong>' . h($sonTeslimat['tedarikci']) . '</strong>' : ''],
], '', 'mb-3');

// İkinci satır: hafta / günlük ortalama / geçen ay / kantar farkı
$__kf = null;
if ($kantarOzet && $kantarOzet['n'] > 0) {
    $__kf = ['ad' => 'Kantar Farkı (bu ay)', 'deger' => round($kantarOzet['net']), 'ondalik' => 0, 'isaret' => true, 'birim' => 'kg',
             'ikon' => 'bi-speedometer', 'renk' => $kantarOzet['net'] < 0 ? 'red' : 'green', 'link' => 'raporlar.php',
             'alt' => '<strong>' . $kantarOzet['n'] . '</strong> tartım · ort. ' . format_number($kantarOzet['mutlak'] / $kantarOzet['n'], 0) . ' kg/irs.'
                    . ($kantarOzet['eksik'] ? ' · ' . $kantarOzet['eksik'] . ' eksik' : '')
                    . ($kantarOzet['supheli'] ? ' · <span class="text-danger">' . $kantarOzet['supheli'] . ' birim şüpheli</span>' : ''),
             'title' => 'Yıldızlar net − tedarikçi net, iki tartımı da girilmiş irsaliyeler. İki net arasında 2 kattan fazla fark olan (ton/kg karışmış) satırlar toplama girmez.'];
} else {
    $__kf = ['ad' => 'Kantar Farkı (bu ay)', 'deger' => '—', 'ikon' => 'bi-speedometer', 'renk' => 'slate',
             'alt_metin' => $kantarOzet && $kantarOzet['supheli'] ? $kantarOzet['supheli'] . ' kayıtta birim şüpheli (ton/kg)' : 'bu ay iki tartımı da girilmiş irsaliye yok'];
}
echo kpi_satir([
    ['ad' => 'Bu Hafta', 'deger' => (float)$buHaftaM3, 'ondalik' => 1, 'birim' => 'm³', 'ikon' => 'bi-calendar-week', 'renk' => 'teal',
     'alt' => $__trendAlt($trendHafta, 'geçen haftaya göre')],
    ['ad' => 'Günlük Ortalama', 'deger' => (float)$gunlukOrt, 'ondalik' => 1, 'birim' => 'm³/gün', 'ikon' => 'bi-calculator', 'renk' => 'green',
     'alt_metin' => $__ayAd[(int)date('n')] . ' · ' . (int)date('j') . ' güne bölündü'],
    ['ad' => 'Geçen Ay', 'deger' => (float)$gecenAyM3, 'ondalik' => 1, 'birim' => 'm³', 'ikon' => 'bi-clock-history', 'renk' => 'gold',
     'alt' => '<strong>' . format_number($gecenAyIrsaliye, 0) . '</strong> irsaliye'],
    $__kf,
], 'col-6 col-lg-3');
?>

<!-- Son 7 Gün + Tedarikçi Dağılımı -->
<div class="row g-4 mb-4">
    <div class="col-md-7">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4 pb-0 text-dark fs-6 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-activity text-primary me-2"></i> Son 7 Gün (m³)</span>
                <span class="small fw-normal text-muted">Toplam: <strong style="color:var(--ern)"><?= format_number(array_sum($son7gunValues),1) ?></strong> m³</span>
            </div>
            <div class="card-body px-4 pb-4 pt-3">
                <canvas id="chartSon7" height="100"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4 pb-0 text-dark fs-6">
                <i class="bi bi-pie-chart text-primary me-2"></i> Tedarikçi Dağılımı
            </div>
            <div class="card-body d-flex align-items-center justify-content-center px-4 pb-4 pt-3">
                <canvas id="chartPie" style="max-height:200px"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Aylık Tüketim + Proje Özeti -->
<div class="row g-4 mb-4">
    <div class="col-md-7">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4 pb-0 text-dark fs-6">
                <i class="bi bi-bar-chart-line text-primary me-2"></i> Aylık m³ Tüketimi (Son 12 Ay)
            </div>
            <div class="card-body px-4 pb-4 pt-3">
                <canvas id="chartAylik" height="120"></canvas>
            </div>
        </div>
    </div>
    <!-- Proje Özeti -->
    <div class="col-md-5">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4 pb-0 text-dark fs-6">
                <i class="bi bi-diagram-3 text-primary me-2"></i> Proje Bazlı m³
            </div>
            <div class="card-body px-4 pb-4 pt-3">
                <?php if (!empty($projeOzet) && array_sum(array_column($projeOzet,'toplam')) > 0):
                    $maxProje = max(array_column($projeOzet,'toplam')) ?: 1;
                    foreach ($projeOzet as $pz):
                        if ((float)$pz['toplam'] == 0) continue;
                        $pct = round((float)$pz['toplam'] / $maxProje * 100);
                        $isim = trim($pz['kod'] ?: '') ?: trim($pz['aciklama'] ?: 'Proje');
                ?>
                <div class="proje-bar-wrap">
                    <div class="proje-bar-label">
                        <span class="proje-bar-name"><?= h($isim) ?></span>
                        <span class="proje-bar-val"><?= format_number($pz['toplam'],1) ?> m³</span>
                    </div>
                    <div class="proje-bar-track">
                        <div class="proje-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-center text-muted py-4 small">
                    <i class="bi bi-diagram-3 d-block fs-2 mb-2 opacity-25"></i>
                    Henüz proje verisi yok
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($betonDagilim)): ?>
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4 pb-0 text-dark fs-6">
                <i class="bi bi-layers text-primary me-2"></i> Beton Sınıfı Dağılımı (m³)
            </div>
            <div class="card-body px-4 pb-4 pt-3">
                <canvas id="chartBeton" height="140"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 fw-bold pt-3 px-4 pb-0 text-dark fs-6">
                <i class="bi bi-list-ol text-primary me-2"></i> Beton Sınıfı Özeti
            </div>
            <div class="card-body px-4 pb-4 pt-3">
                <table class="table table-sm mb-0 table-borderless table-striped">
                    <thead class="table-light"><tr><th class="rounded-start">Beton Sınıfı</th><th class="text-end rounded-end">m³</th></tr></thead>
                    <tbody>
                    <?php foreach ($betonDagilim as $bd): ?>
                        <tr>
                            <td><?= h($bd['ad']) ?></td>
                            <td class="text-end fw-semibold text-primary"><?= format_number($bd['toplam'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Proje → Parsel → Blok → Kot hiyerarşisi -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-0 fw-bold pt-4 px-4 pb-2 text-dark fs-6">
        <i class="bi bi-diagram-3 text-primary me-2"></i>Proje → Parsel → Blok → Kot <span class="text-muted fw-normal small">(dökülen m³)</span>
    </div>
    <div class="card-body px-4 pb-4 pt-2">
        <?php if (!$hiyerarsi): ?>
            <div class="text-muted small py-3 text-center">Kayıt yok.</div>
        <?php else: $__fmt=fn($n)=>number_format((float)$n,2,',','.'); $pi=0; ?>
        <div class="accordion accordion-flush" id="hiyAcc">
        <?php foreach ($hiyerarsi as $proje=>$pv): $pi++; $pid="hp{$pi}"; ?>
            <div class="accordion-item border rounded mb-2">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $pid ?>">
                        <span class="badge bg-dark me-2"><?= h($proje) ?></span>
                        <span class="small text-muted me-2"><?= h($pv['ad']) ?></span>
                        <span class="ms-auto me-3 fw-bold text-primary"><?= $__fmt($pv['m3']) ?> m³</span>
                        <span class="small text-muted"><?= (int)$pv['adet'] ?> irs.</span>
                    </button>
                </h2>
                <div id="<?= $pid ?>" class="accordion-collapse collapse" data-bs-parent="#hiyAcc"><div class="accordion-body py-2">
                    <?php foreach ($pv['parseller'] as $parsel=>$pav): ?>
                        <div class="mb-2 ps-2 border-start border-2">
                            <div class="d-flex align-items-center py-1">
                                <i class="bi bi-map text-success me-2"></i><span class="fw-semibold"><?= h($parsel) ?></span>
                                <span class="ms-auto me-3 fw-semibold"><?= $__fmt($pav['m3']) ?> m³</span>
                                <span class="small text-muted"><?= (int)$pav['adet'] ?> irs.</span>
                            </div>
                            <?php foreach ($pav['bloklar'] as $blok=>$bv): ?>
                                <div class="ms-4 mb-1">
                                    <div class="d-flex align-items-center small py-1">
                                        <i class="bi bi-building text-secondary me-2"></i><span class="fw-semibold"><?= h($blok) ?></span>
                                        <span class="ms-auto me-3"><?= $__fmt($bv['m3']) ?> m³</span>
                                        <span class="text-muted"><?= (int)$bv['adet'] ?> irs.</span>
                                    </div>
                                    <div class="ms-4 d-flex flex-wrap gap-1 pb-1">
                                        <?php foreach ($bv['kotlar'] as $k): ?>
                                            <span class="badge bg-light text-dark border" title="<?= h($k['acik']) ?>">
                                                <i class="bi bi-layers me-1 text-muted"></i><?= h($k['kot']) ?><?= $k['acik']?' · '.h($k['acik']):'' ?>
                                                <span class="text-primary ms-1"><?= $__fmt($k['m3']) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Son irsaliyeler -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-0 fw-bold pt-4 px-4 pb-3 d-flex justify-content-between align-items-center flex-wrap gap-2 text-dark fs-6">
        <span><i class="bi bi-clock-history text-primary me-2"></i> Son İrsaliyeler</span>
        <a href="irsaliyeler.php<?= $projeId ? '?proje_id=' . $projeId : '' ?>" class="btn btn-sm btn-outline-primary">Tümünü Gör</a>
    </div>
    <div class="card-body p-0 px-4 pb-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sonIrsaliyelerTbl">
                <thead class="table-light">
                    <tr>
                        <th class="sortable" data-col="0" data-type="text">Tip <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="1" data-type="date">Tarih <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="2" data-type="text">İrsaliye No <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="3" data-type="text">Tedarikçi <span class="sort-ind">&#x21C5;</span></th>
                        <th class="sortable" data-col="4" data-type="text">Beton Sınıfı <span class="sort-ind">&#x21C5;</span></th>
                        <th>Parsel / Blok</th>
                        <th class="text-end sortable" data-col="6" data-type="num">Miktar <span class="sort-ind">&#x21C5;</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sonIrsaliyeler): ?>
                        <?php foreach ($sonIrsaliyeler as $r): ?>
                        <tr>
                            <td data-sort="<?= h($r['tip']) ?>">
                                <?php if ($r['tip'] === 'alis'): ?>
                                    <span class="badge bg-success"><i class="bi bi-arrow-down-circle me-1"></i>Alış</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-arrow-up-circle me-1"></i>İade</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap" data-sort="<?= h($r['tarih']) ?>"><?= format_date($r['tarih']) ?></td>
                            <td class="text-nowrap"><code><?= h($r['irsaliye_no'] ?: '-') ?></code></td>
                            <td><span class="badge bg-secondary"><?= h($r['tedarikci_adi'] ?? '-') ?></span></td>
                            <td><?= h($r['beton_sinifi'] ?? '-') ?></td>
                            <td class="small text-muted">
                                <?= h($r['parsel_adi'] ?? '') ?>
                                <?php if ($r['blok_adi']): ?> / <?= h($r['blok_adi']) ?><?php endif; ?>
                                <?php if ($r['proje_kod']): ?> <span class="badge bg-light text-dark border"><?= h($r['proje_kod']) ?></span><?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold text-nowrap" data-sort="<?= (float)$r['miktar'] ?>"><?= format_number($r['miktar'], 2) ?> <?= h($r['birim']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                Henüz irsaliye yok.
                                <?php if (can_edit()): ?>
                                <a href="irsaliye_form.php" class="btn btn-sm btn-primary ms-2">
                                    <i class="bi bi-plus-circle me-1"></i>İlk İrsaliyeyi Ekle
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const COLORS = ['#00584E','#00C9B1','#C9A84C','#007A6A','#00A896','#E05454','#3B82F6','#8B5CF6'];
    const dk = () => document.documentElement.getAttribute('data-dark') === '1';
    const gridC  = () => dk() ? 'rgba(255,255,255,.07)' : 'rgba(0,88,78,.08)';
    const tickC  = () => dk() ? '#6EA89E' : '#4E7068';

    // Son 7 Gün sparkline
    const son7Labels = <?= json_encode($son7gunLabels) ?>;
    const son7Values = <?= json_encode($son7gunValues) ?>;
    new Chart(document.getElementById('chartSon7'), {
        type: 'bar',
        data: {
            labels: son7Labels,
            datasets: [{
                label: 'm³',
                data: son7Values,
                backgroundColor: son7Values.map(function(v,i){
                    return i === son7Values.length-1 ? 'rgba(0,200,180,.9)' : 'rgba(0,88,78,.7)';
                }),
                borderRadius: 6,
            }]
        },
        options: {
            plugins: { legend: { display: false },
                tooltip: { callbacks: { label: function(ctx){ return ctx.parsed.y + ' m³'; } } }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: gridC() }, ticks: { color: tickC() } },
                x: { grid: { display: false }, ticks: { color: tickC() } }
            }
        }
    });

    // Aylık çubuk grafik
    new Chart(document.getElementById('chartAylik'), {
        type: 'bar',
        data: {
            labels: <?= $chartLabels ?>,
            datasets: [{
                label: 'm³',
                data: <?= $chartValues ?>,
                backgroundColor: 'rgba(0,88,78,.78)',
                hoverBackgroundColor: 'rgba(0,168,150,.9)',
                borderRadius: 6,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: gridC() }, ticks: { color: tickC() } }, x: { grid: { display: false }, ticks: { color: tickC() } } }
        }
    });

    // Tedarikçi halka grafik
    new Chart(document.getElementById('chartPie'), {
        type: 'doughnut',
        data: {
            labels: <?= $pieLabels ?>,
            datasets: [{ data: <?= $pieValues ?>, backgroundColor: COLORS }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });

    <?php if (!empty($betonDagilim)): ?>
    // Beton sınıfı yatay çubuk grafik
    new Chart(document.getElementById('chartBeton'), {
        type: 'bar',
        data: {
            labels: <?= $betonLabels ?>,
            datasets: [{
                label: 'm³',
                data: <?= $betonValues ?>,
                backgroundColor: COLORS,
                borderRadius: 4,
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
    <?php endif; ?>

    // ── Tablo sıralama ─────────────────────────────────────────────────────
    const tbl = document.getElementById('sonIrsaliyelerTbl');
    if (tbl) {
        const tbody = tbl.tBodies[0];
        const headers = tbl.querySelectorAll('th.sortable');
        headers.forEach(th => {
            th.addEventListener('click', () => {
                const col = +th.dataset.col;
                const type = th.dataset.type || 'text';
                const asc = !th.classList.contains('asc');
                headers.forEach(x => x.classList.remove('asc', 'desc'));
                th.classList.add(asc ? 'asc' : 'desc');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort((a, b) => {
                    const av = (a.cells[col]?.dataset.sort ?? a.cells[col]?.innerText ?? '').trim();
                    const bv = (b.cells[col]?.dataset.sort ?? b.cells[col]?.innerText ?? '').trim();
                    let cmp;
                    if (type === 'num') {
                        cmp = parseFloat(av.replace(',', '.')) - parseFloat(bv.replace(',', '.'));
                    } else if (type === 'date') {
                        cmp = new Date(av) - new Date(bv);
                    } else {
                        cmp = av.localeCompare(bv, 'tr');
                    }
                    return asc ? cmp : -cmp;
                });
                rows.forEach(r => tbody.appendChild(r));
            });
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
