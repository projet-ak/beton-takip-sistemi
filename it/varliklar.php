<?php
/**
 * it/varliklar.php — MERKEZİ VARLIK İZLEME
 *
 * "Hangi lokasyonda ne var?" sorusunun tek ekrandaki cevabı. BT envanteri, ağ ve güvenlik
 * altyapısı, IP telefon/santral, kamera/NVR/turnike, TV ve sarf malzeme AYNI tabloda
 * (`it_cihazlar`) durur; bu ekran onları **grup** (BT / Ağ / İletişim / Güvenlik / Multimedya /
 * Yazılım / Sarf) başlıkları altında toplar ve cihaz tipine göre ANLAMLI sütunları gösterir —
 * kamerada NVR bağı ve IP, IP telefonda dahili, superbox'ta IMEI/operatör, NVR'de disk kapasitesi.
 *
 * Aramada IP · MAC · seri no · envanter no · varlık kodu · dahili · telefon · IMEI birlikte taranır
 * (`it_filtre`), böylece "192.168.1.45" ya da "FUAE1HA" yazınca cihaz doğrudan bulunur.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';

it_semasi_kur($pdoIt);
$pageTitle = 'Merkezi Varlık İzleme — IT Envanter';

$grup = (string)($_GET['grup'] ?? '');
if (!isset(IT_GRUP[$grup])) $grup = '';

[$wsql, $par, $etkin] = it_filtre($_GET + ['grup' => $grup]);
$lokId = (int)($_GET['lokasyon_id'] ?? 0); $perId = (int)($_GET['personel_id'] ?? 0);
$ek = [];
if ($lokId) { $ek[] = 'lokasyon_id IN (' . implode(',', array_map('intval', it_lokasyon_altlar($pdoIt, $lokId))) . ')'; $etkin['lokasyon_id'] = $lokId; }
if ($perId) { $ek[] = 'personel_id=' . $perId; $etkin['personel_id'] = $perId; }
if ($ek) $wsql = ($wsql ? $wsql . ' AND ' : ' WHERE ') . implode(' AND ', $ek);

/** Grup rozetleri: her grupta kaç varlık var (hurdalar hariç). */
$grupSayim = []; $toplamVarlik = 0;
try {
    foreach ($pdoIt->query("SELECT kategori, COUNT(*) n FROM it_cihazlar WHERE durum<>'hurda' GROUP BY kategori") as $r) {
        $g = it_grup((string)$r['kategori']);
        $grupSayim[$g] = ($grupSayim[$g] ?? 0) + (int)$r['n'];
        $toplamVarlik += (int)$r['n'];
    }
} catch (Throwable $e) {}

// ── Excel (filtrelere saygılı) ───────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../includes/XlsxWriter.php';
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar $wsql ORDER BY kategori, envanter_no");
    $st->execute($par);
    $xl = new \XlsxWriter('Varlık Listesi');
    $xl->header(['Grup','Kategori','Envanter No','Varlık Kodu','Cihaz','Marka','Model','Seri No','Durum',
                 'IP Adresi','MAC Adresi','Dahili','Telefon No','IMEI','Operatör','Firmware','Lisans Durumu',
                 'Disk / Port','Kullanım Amacı','Adet','Lokasyon','Zimmetli','Departman','Garanti Bitiş','Fiyat (TL)','Notlar']);
    foreach ($st->fetchAll() as $r) {
        $xl->row([
            ['v'=>IT_GRUP[it_grup($r['kategori'])][0] ?? ''], ['v'=>it_kategoriAd($r['kategori'])],
            ['v'=>$r['envanter_no']], ['v'=>$r['varlik_kodu'] ?? ''], ['v'=>$r['ad']], ['v'=>$r['marka']], ['v'=>$r['model']],
            ['v'=>$r['seri_no']], ['v'=>it_durumAd($r['durum'])],
            ['v'=>$r['ip_adresi']], ['v'=>$r['mac_adresi']], ['v'=>$r['dahili_no'] ?? ''], ['v'=>$r['telefon_no'] ?? ''],
            ['v'=>$r['imei'] ?? ''], ['v'=>$r['operator'] ?? ''], ['v'=>$r['firmware'] ?? ''], ['v'=>$r['lisans_durumu'] ?? ''],
            ['v'=>$r['kapasite'] ?? ''], ['v'=>$r['kullanim_amaci'] ?? ''], ['v'=>$r['adet'] ?? '', 't'=>'number'],
            ['v'=>$r['lokasyon']], ['v'=>$r['zimmetli']], ['v'=>$r['departman']],
            ['v'=>$r['garanti_bitis'], 't'=>'date'], ['v'=>(float)$r['fiyat'], 't'=>'number'], ['v'=>$r['notlar']],
        ]);
    }
    $xl->download('it_varliklar_' . ($grup ?: 'tumu') . '_' . date('Ymd_Hi') . '.xlsx');
}

// ── Liste ────────────────────────────────────────────────────────────────────
$oz = $pdoIt->prepare("SELECT COUNT(*) adet, COALESCE(SUM(fiyat),0) mali,
                              SUM(durum='aktif') aktif, SUM(durum IN ('serviste','arizali')) sorunlu,
                              COUNT(DISTINCT CASE WHEN lokasyon_id IS NOT NULL THEN lokasyon_id END) lokasyon,
                              SUM(ip_adresi IS NOT NULL AND ip_adresi<>'') ipli
                       FROM it_cihazlar $wsql");
$oz->execute($par);
$oz = $oz->fetch() ?: ['adet'=>0,'mali'=>0,'aktif'=>0,'sorunlu'=>0,'lokasyon'=>0,'ipli'=>0];

$adet = 150;
$sayfa = max(1, (int)($_GET['s'] ?? 1));
$sonSayfa = max(1, (int)ceil((int)$oz['adet'] / $adet));
if ($sayfa > $sonSayfa) $sayfa = $sonSayfa;
$st = $pdoIt->prepare("SELECT * FROM it_cihazlar $wsql ORDER BY kategori, envanter_no LIMIT $adet OFFSET " . (($sayfa - 1) * $adet));
$st->execute($par);
$liste = $st->fetchAll();

// Bağlı cihaz adları (kamera → NVR, turnike → geçiş ünitesi) tek sorguda
$bagliAd = [];
$bagliIdler = array_values(array_filter(array_map(fn($r) => (int)($r['bagli_id'] ?? 0), $liste)));
if ($bagliIdler) {
    try {
        $q = $pdoIt->query("SELECT id, envanter_no, ad FROM it_cihazlar WHERE id IN (" . implode(',', array_unique($bagliIdler)) . ")");
        foreach ($q as $b) $bagliAd[(int)$b['id']] = trim($b['envanter_no'] . ' · ' . $b['ad']);
    } catch (Throwable $e) {}
}

// Arıza/servis geçmişi sayısı (sorun yaşayan cihazlar hemen görünsün)
$arizaSayi = [];
try {
    foreach ($pdoIt->query("SELECT cihaz_id, COUNT(*) n FROM it_hareketler WHERE tur IN ('ariza','servis') GROUP BY cihaz_id") as $r)
        $arizaSayi[(int)$r['cihaz_id']] = (int)$r['n'];
} catch (Throwable $e) {}

$qs = fn(array $x = []) => '?' . http_build_query(array_filter(array_merge($_GET, $x), fn($v) => $v !== '' && $v !== null));
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.vg-serit { display:flex; flex-wrap:wrap; gap:.4rem; }
.vg-serit a { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .8rem; border-radius:999px;
              border:1px solid var(--bt-border, rgba(0,0,0,.12)); text-decoration:none; font-size:.84rem; font-weight:600;
              color:var(--bt-text-soft, #64748b); }
.vg-serit a.aktif { background:var(--ern, #00584E); color:#fff; border-color:transparent; }
.vg-tablo td, .vg-tablo th { font-size:.83rem; vertical-align:middle; }
.vg-kod { font-family:var(--bs-font-monospace); font-size:.78rem; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i>Merkezi Varlık İzleme
        <?php if ($grup): ?><span class="text-muted fw-normal">· <?= h(IT_GRUP[$grup][0]) ?></span><?php endif; ?></h4>
    <div class="d-flex gap-2">
        <a href="varliklar.php<?= h($qs(['export'=>'xlsx'])) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <a href="cihazlar.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>Klasik Liste</a>
        <?php if (yetki_var('giris')): ?><a href="cihaz_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Yeni Varlık</a><?php endif; ?>
    </div>
</div>

<div class="vg-serit mb-3">
    <a href="varliklar.php" class="<?= $grup === '' ? 'aktif' : '' ?>"><i class="bi bi-grid-3x3-gap"></i>Tümü
        <span class="badge bg-light text-dark border"><?= (int)$toplamVarlik ?></span></a>
    <?php foreach (IT_GRUP as $g => [$gAd, $gIk]): if (empty($grupSayim[$g]) && $g !== $grup) continue; ?>
    <a href="varliklar.php?grup=<?= h($g) ?>" class="<?= $grup === $g ? 'aktif' : '' ?>"><i class="bi <?= h($gIk) ?>"></i><?= h($gAd) ?>
        <span class="badge bg-light text-dark border"><?= (int)($grupSayim[$g] ?? 0) ?></span></a>
    <?php endforeach; ?>
</div>

<div class="row g-2 mb-3">
    <?php foreach ([['Listelenen', number_format((int)$oz['adet'], 0, ',', '.'), 'bi-box-seam', ''],
                    ['Kullanımda', number_format((int)$oz['aktif'], 0, ',', '.'), 'bi-person-check', 'text-success'],
                    ['Serviste / Arızalı', number_format((int)$oz['sorunlu'], 0, ',', '.'), 'bi-wrench', ((int)$oz['sorunlu'] ? 'text-danger' : '')],
                    ['IP adresli', number_format((int)$oz['ipli'], 0, ',', '.'), 'bi-hdd-network', ''],
                    ['Lokasyon', number_format((int)$oz['lokasyon'], 0, ',', '.'), 'bi-geo-alt', ''],
                    ['Mali Değer', number_format((float)$oz['mali'], 0, ',', '.') . ' ₺', 'bi-cash-coin', 'text-primary']] as [$et, $dg, $ik, $cls]): ?>
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="small text-muted"><i class="bi <?= $ik ?> me-1"></i><?= $et ?></div>
        <div class="fs-5 fw-bold <?= $cls ?>"><?= $dg ?></div>
    </div></div></div>
    <?php endforeach; ?>
</div>

<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
    <?php if ($grup): ?><input type="hidden" name="grup" value="<?= h($grup) ?>"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Arama <span class="text-muted">(IP · MAC · seri · dahili · IMEI)</span></label>
            <input name="q" class="form-control form-control-sm" value="<?= h($etkin['q'] ?? '') ?>" placeholder="192.168.1.45 · 00:1A:2B · FUAE1HA…"></div>
        <div class="col-md-2"><label class="form-label small mb-1">Cihaz Tipi</label>
            <select name="kategori" class="form-select form-select-sm"><option value="">Tümü</option>
                <?php foreach (it_kategori_agaci() as $g2 => $kats): if ($grup && $g2 !== $grup) continue; ?>
                <optgroup label="<?= h(IT_GRUP[$g2][0] ?? $g2) ?>">
                    <?php foreach ($kats as $k => $ad): ?><option value="<?= h($k) ?>" <?= ($etkin['kategori'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
                </optgroup><?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Lokasyon <span class="text-muted">(alt dahil)</span></label>
            <select name="lokasyon_id" class="form-select form-select-sm"><option value="">Tümü</option><?= it_lokasyon_options($pdoIt, $lokId, false) ?></select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Durum</label>
            <select name="durum" class="form-select form-select-sm"><option value="">Hurda hariç tümü</option>
                <?php foreach (IT_DURUM as $k => [$ad]): ?><option value="<?= h($k) ?>" <?= ($etkin['durum'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Garanti</label>
            <select name="garanti" class="form-select form-select-sm"><option value="">Tümü</option>
                <?php foreach (['bitiyor'=>'60 günde bitiyor', 'bitti'=>'Bitti', 'devam'=>'Devam ediyor'] as $k => $ad): ?>
                <option value="<?= $k ?>" <?= ($etkin['garanti'] ?? '') === $k ? 'selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-1 d-flex gap-1">
            <button class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
            <?php if ($etkin): ?><a href="varliklar.php<?= $grup ? '?grup=' . h($grup) : '' ?>" class="btn btn-outline-secondary btn-sm" title="Süzgeci temizle"><i class="bi bi-x-lg"></i></a><?php endif; ?>
        </div>
    </div>
</div></form>

<?php if (!$liste): ?>
<div class="alert alert-light border text-center py-4">
    <i class="bi bi-inbox fs-3 text-muted d-block mb-2"></i>
    Bu süzgece uyan varlık yok.
    <?php if (yetki_var('giris')): ?><div class="mt-2"><a href="cihaz_form.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Yeni varlık ekle</a></div><?php endif; ?>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
<div class="table-responsive"><table class="table table-hover align-middle mb-0 vg-tablo">
    <thead class="table-light"><tr>
        <th>Envanter</th><th>Varlık</th><th>Marka / Model</th><th>Seri No</th>
        <th>Ağ / Hat</th><th>Teknik</th><th>Lokasyon</th><th>Zimmetli</th><th>Durum</th><th class="text-end">Garanti</th>
    </tr></thead>
    <tbody>
    <?php $sonGrup = null; foreach ($liste as $r):
        $kat = (string)$r['kategori']; $g3 = it_grup($kat);
        if ($grup === '' && $g3 !== $sonGrup): $sonGrup = $g3; ?>
        <tr class="table-light"><td colspan="10" class="fw-semibold small py-1">
            <i class="bi <?= h(IT_GRUP[$g3][1] ?? 'bi-box') ?> me-1"></i><?= h(IT_GRUP[$g3][0] ?? $g3) ?></td></tr>
    <?php endif;
        $kalan = it_garanti_kalan($r['garanti_bitis'] ?? null);
        // Ağ / hat sütunu: cihaz tipine göre anlamlı olan bilgi
        $ag = [];
        if (!empty($r['ip_adresi']))  $ag[] = '<span class="vg-kod">' . h($r['ip_adresi']) . '</span>';
        if (!empty($r['mac_adresi'])) $ag[] = '<span class="vg-kod text-muted">' . h($r['mac_adresi']) . '</span>';
        if (!empty($r['dahili_no']))  $ag[] = '<span class="badge bg-light text-dark border">Dahili ' . h($r['dahili_no']) . '</span>';
        if (!empty($r['telefon_no'])) $ag[] = '<span class="vg-kod">' . h($r['telefon_no']) . '</span>';
        if (!empty($r['imei']))       $ag[] = '<span class="vg-kod text-muted">IMEI ' . h($r['imei']) . '</span>';
        // Teknik sütunu
        $tek = [];
        if (!empty($r['operator']))      $tek[] = h($r['operator']);
        if (!empty($r['firmware']))      $tek[] = 'FW ' . h($r['firmware']);
        if (!empty($r['kapasite']))      $tek[] = h($r['kapasite']);
        if (!empty($r['lisans_durumu'])) $tek[] = '<span class="text-primary">' . h($r['lisans_durumu']) . '</span>';
        if (!empty($r['kullanim_amaci']))$tek[] = '<span class="text-muted">' . h($r['kullanim_amaci']) . '</span>';
        if (!empty($r['bagli_id']) && isset($bagliAd[(int)$r['bagli_id']]))
            $tek[] = '<i class="bi bi-link-45deg"></i> ' . h($bagliAd[(int)$r['bagli_id']]);
        if (($r['adet'] ?? null) !== null && $r['adet'] !== '') $tek[] = '<strong>' . (int)$r['adet'] . '</strong> adet';
        if (!$tek && !empty($r['ozellikler'])) $tek[] = '<span class="text-muted">' . h(mb_substr($r['ozellikler'], 0, 40)) . '</span>';
    ?>
        <tr>
            <td class="vg-kod"><a href="cihaz_detay.php?id=<?= (int)$r['id'] ?>" class="text-decoration-none"><?= h($r['envanter_no']) ?></a>
                <?php if (!empty($arizaSayi[(int)$r['id']])): ?>
                <span class="badge bg-danger-subtle text-danger-emphasis ms-1" title="arıza / servis kaydı"><i class="bi bi-wrench"></i> <?= (int)$arizaSayi[(int)$r['id']] ?></span>
                <?php endif; ?></td>
            <td><i class="bi <?= h(it_kategoriIkon($kat)) ?> me-1 text-muted"></i><?= h($r['ad']) ?>
                <div class="small text-muted"><?= h(it_kategoriAd($kat)) ?></div></td>
            <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="vg-kod"><?= h($r['seri_no'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
            <td><?= $ag ? implode('<br>', $ag) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $tek ? implode('<br>', $tek) : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?= h(!empty($r['lokasyon_id']) ? it_lokasyon_etiket($pdoIt, (int)$r['lokasyon_id']) : ($r['lokasyon'] ?: '—')) ?></td>
            <td class="small"><?= $r['zimmetli']
                ? (!empty($r['personel_id']) ? '<a href="personel_detay.php?id=' . (int)$r['personel_id'] . '" class="text-decoration-none">' . h($r['zimmetli']) . '</a>' : h($r['zimmetli']))
                : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge bg-<?= h(IT_DURUM[$r['durum']][1] ?? 'secondary') ?>"><?= h(it_durumAd($r['durum'])) ?></span></td>
            <td class="text-end small text-nowrap">
                <?php if ($kalan === null): ?><span class="text-muted">—</span>
                <?php elseif ($kalan < 0): ?><span class="text-danger">bitti</span>
                <?php elseif ($kalan <= 60): ?><span class="text-warning-emphasis"><?= (int)$kalan ?> gün</span>
                <?php else: ?><span class="text-success"><?= h(date('d.m.Y', strtotime($r['garanti_bitis']))) ?></span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php if ($sonSayfa > 1): ?>
<div class="card-footer bg-white d-flex justify-content-between align-items-center small">
    <span class="text-muted">Sayfa <?= $sayfa ?> / <?= $sonSayfa ?> · toplam <?= number_format((int)$oz['adet'], 0, ',', '.') ?> varlık</span>
    <div class="btn-group btn-group-sm">
        <?php if ($sayfa > 1): ?><a class="btn btn-outline-secondary" href="varliklar.php<?= h($qs(['s'=>$sayfa-1])) ?>">← Önceki</a><?php endif; ?>
        <?php if ($sayfa < $sonSayfa): ?><a class="btn btn-outline-secondary" href="varliklar.php<?= h($qs(['s'=>$sayfa+1])) ?>">Sonraki →</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
