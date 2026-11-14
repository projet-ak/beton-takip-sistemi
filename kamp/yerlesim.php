<?php
/**
 * kamp/yerlesim.php — YERLEŞİM KROKİSİ (modülün ana çalışma ekranı)
 *
 * Bina → kat → konteyner (ünite) → oda → yatak, şu an kimin yattığıyla birlikte çizilir.
 * Konteyner ikiye bölünmüşse çizim sahadaki gibidir: SOL ODA · ORTAK (banyo/WC) · SAĞ ODA.
 * Boş yatağa tıkla → yerleştir (kayıtlı kişi ya da yeni kişi); dolu yatağa tıkla → taşı / çıkış.
 * İşlemler kamp/konaklama.php'ye POST edilir ve buraya döner.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

// Yerleştirme penceresinin kişi araması (JSON)
if (isset($_GET['kisi_ara'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(kamp_kisi_ara($pdoKamp, (string)$_GET['kisi_ara']), JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'Yerleşim — Kamp Takip';
$yaz       = yetki_var('giris') || yetki_var('duzenle');
$binalar   = kamp_binalar($pdoKamp);
$doluluk   = kamp_doluluk($pdoKamp);
$binaSec   = (int)($_GET['bina'] ?? 0);
if ($binaSec && !isset($binalar[$binaSec])) $binaSec = 0;
$agac      = kamp_yapi($pdoKamp, $binaSec ?: null);
$bosYatak  = $yaz ? kamp_bos_yataklar($pdoKamp) : [];

// Oda bazında sorunlu demirbaş sayısı (rozet)
$dbSorun = [];
foreach ($pdoKamp->query("SELECT oda_id, COUNT(*) n FROM kamp_demirbas WHERE durum IN ('arizali','bakimda','kayip') AND oda_id IS NOT NULL GROUP BY oda_id") as $r)
    $dbSorun[(int)$r['oda_id']] = (int)$r['n'];

$geri = 'yerlesim.php' . ($binaSec ? '?bina=' . $binaSec : '');
$top  = ['yatak' => 0, 'dolu' => 0];
foreach ($doluluk as $id => $d) if (!$binaSec || $binaSec === $id) { $top['yatak'] += $d['yatak']; $top['dolu'] += $d['dolu']; }

/** Bir odanın kartını çizer. */
function ky_oda(array $o, array $dbSorun, bool $yaz): string
{
    $tur = KAMP_ODA_TUR[$o['tur']] ?? KAMP_ODA_TUR['diger'];
    $sy  = kamp_oda_sayac($o);
    $h   = '<div class="ky-oda ky-' . htmlspecialchars($o['tur']) . (kamp_oda_yatakli($o['tur']) ? '' : ' ky-yataksiz') . '">';
    // Konteynerin ortak alanında ad yerine kısa "Ortak" yazılır (dar sütun; tam ad title'da)
    $gor = ($o['konum'] ?? '') === 'orta' && !empty($o['unite']) ? 'Ortak' : $o['ad'];
    $h  .= '<div class="ky-oda-bas"><a href="oda.php?id=' . (int)$o['id'] . '" class="ky-oda-ad" title="' . htmlspecialchars($o['ad']) . ' — oda kartı: yataklar, demirbaş, geçmiş">'
         . '<i class="bi ' . $tur[1] . ' me-1"></i>' . htmlspecialchars($gor) . '</a>';
    if (!empty($dbSorun[(int)$o['id']]))
        $h .= ' <a href="demirbas.php?oda=' . (int)$o['id'] . '" class="badge bg-danger text-decoration-none" title="Arızalı / bakımda / kayıp demirbaş"><i class="bi bi-tools"></i> ' . $dbSorun[(int)$o['id']] . '</a>';
    if (kamp_oda_yatakli($o['tur']) && $sy['yatak'])
        $h .= '<span class="ky-say ' . ($sy['dolu'] >= $sy['yatak'] ? 'tam' : ($sy['dolu'] ? 'kismi' : 'bos')) . '">' . $sy['dolu'] . '/' . $sy['yatak'] . '</span>';
    $h  .= '</div>';
    if (!kamp_oda_yatakli($o['tur'])) {
        $h .= '<div class="ky-tur"><i class="bi ' . $tur[1] . '"></i> ' . htmlspecialchars($tur[0]) . '</div>';
    } elseif (!$o['yataklar']) {
        $h .= '<div class="ky-tur text-muted small">yatak tanımlı değil</div>';
    } else {
        // Ranza yatakları ("3-Alt", "3-Üst"…) aynı numarada TEK ranza bloğunda üst üste çizilir (üst yukarıda)
        $grup = []; $sira = ['ranza_ust' => 0, 'ranza_orta' => 1, 'ranza_alt' => 2];
        foreach ($o['yataklar'] as $y) {
            $rk = str_starts_with((string)$y['tur'], 'ranza') && preg_match('/^(\d+)-/', (string)$y['no'], $m) ? 'r' . $m[1] : 'y' . $y['id'];
            $grup[$rk][] = $y;
        }
        $h .= '<div class="ky-yataklar">';
        foreach ($grup as $rk => $gy) {
            if ($rk[0] === 'r') usort($gy, fn($a, $b) => ($sira[$a['tur']] ?? 9) <=> ($sira[$b['tur']] ?? 9));
            $h .= $rk[0] === 'r' ? '<div class="ky-ranza-grup" title="Ranza ' . htmlspecialchars(substr($rk, 1)) . '">' : '';
        foreach ($gy as $y) {
            $et = $o['ad'] . ' › ' . $y['no'];
            if (!(int)$y['aktif']) {
                $h .= '<span class="ky ky-disi" title="Kullanım dışı yatak' . ($y['aciklama'] ? ': ' . htmlspecialchars($y['aciklama']) : '') . '">'
                    . '<b>' . htmlspecialchars($y['no']) . '</b><small>kullanım dışı</small></span>';
                continue;
            }
            $ranza = str_starts_with((string)$y['tur'], 'ranza') ? ' ky-ranza' : '';
            if (!empty($y['konaklama_id'])) {
                $ad = trim($y['kisi_ad'] . ' ' . $y['kisi_soyad']);
                $gun = kamp_sure($y['giris'], null);
                $ara = kamp_norm($ad . ' ' . ($y['firma_ad'] ?? '') . ' ' . ($y['gorev'] ?? ''));
                $h .= '<button type="button" class="ky ky-dolu' . $ranza . ' ky-k-' . htmlspecialchars((string)$y['kategori']) . '"'
                    . ' data-dolu="1" data-konaklama="' . (int)$y['konaklama_id'] . '" data-kisi="' . (int)$y['kisi_id'] . '"'
                    . ' data-ad="' . htmlspecialchars($ad) . '" data-firma="' . htmlspecialchars((string)$y['firma_ad']) . '"'
                    . ' data-gorev="' . htmlspecialchars((string)$y['gorev']) . '" data-tel="' . htmlspecialchars((string)$y['telefon']) . '"'
                    . ' data-giris="' . htmlspecialchars(kamp_tarih_yaz($y['giris'])) . '" data-gun="' . $gun . '"'
                    . ' data-et="' . htmlspecialchars($et) . '" data-ara="' . htmlspecialchars($ara) . '"'
                    . ' title="' . htmlspecialchars($ad . ' — ' . ($y['firma_ad'] ?: 'firma yok') . ' · ' . kamp_tarih_yaz($y['giris']) . ' (' . $gun . ' gece)') . '">'
                    . '<b>' . htmlspecialchars($y['no']) . '</b><span class="ky-ad">' . htmlspecialchars($ad) . '</span>'
                    . '<small>' . htmlspecialchars((string)($y['firma_ad'] ?: '—')) . '</small></button>';
            } else {
                $h .= '<button type="button" class="ky ky-bos' . $ranza . '"' . ($yaz ? '' : ' disabled')
                    . ' data-yatak="' . (int)$y['id'] . '" data-et="' . htmlspecialchars($et) . '" data-ara=""'
                    . ' title="Boş yatak' . ($yaz ? ' — yerleştirmek için tıklayın' : '') . '">'
                    . '<b>' . htmlspecialchars($y['no']) . '</b><span class="ky-ad">boş</span>'
                    . '<small>' . htmlspecialchars(KAMP_YATAK_TUR[$y['tur']] ?? '') . '</small></button>';
            }
        }
            $h .= $rk[0] === 'r' ? '</div>' : '';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

require __DIR__ . '/../includes/header.php';
?>
<style>
.ky-pill .badge{font-weight:600}
.ky-kat{margin-bottom:1.25rem}
.ky-kat-bas{display:flex;align-items:center;gap:.5rem;font-weight:700;margin:.25rem 0 .6rem;padding-bottom:.35rem;border-bottom:2px solid var(--bs-border-color)}
.ky-ucol{display:flex;flex-direction:column}
.ky-unite{border:3px solid var(--bs-emphasis-color);border-radius:6px;padding:4px;display:flex;gap:4px;background:var(--bs-body-bg);flex:1 1 auto}
.ky-unite-ad{font-size:.72rem;font-weight:700;color:var(--bs-secondary-color);margin-bottom:2px}
.ky-unite .ky-oda{flex:1 1 0;min-width:0;border:2px solid var(--bs-emphasis-color)}
.ky-unite .ky-oda.ky-ortak{flex:0 0 22%;border-style:dashed}
.ky-oda{border:1px solid var(--bs-border-color);border-radius:6px;padding:.4rem;background:var(--bs-tertiary-bg);height:100%}
.ky-oda.ky-yataksiz{background:repeating-linear-gradient(45deg,var(--bs-tertiary-bg),var(--bs-tertiary-bg) 6px,var(--bs-secondary-bg) 6px,var(--bs-secondary-bg) 12px)}
.ky-oda-bas{display:flex;align-items:center;gap:.3rem;margin-bottom:.3rem;font-size:.82rem}
.ky-oda-ad{font-weight:700;text-decoration:none;color:var(--bs-emphasis-color);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ky-say{margin-left:auto;font-size:.72rem;font-weight:700;padding:.05rem .4rem;border-radius:999px}
.ky-say.bos{background:rgba(var(--bs-success-rgb),.15);color:var(--bs-success-text-emphasis)}
.ky-say.kismi{background:rgba(var(--bs-warning-rgb),.2);color:var(--bs-warning-text-emphasis)}
.ky-say.tam{background:rgba(var(--bs-danger-rgb),.15);color:var(--bs-danger-text-emphasis)}
.ky-tur{font-size:.78rem;color:var(--bs-secondary-color);text-align:center;padding:.6rem 0}
.ky-yataklar{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:4px}
.ky{display:flex;flex-direction:column;align-items:flex-start;text-align:left;border-radius:5px;padding:.25rem .4rem;font-size:.74rem;line-height:1.15;border:1px solid transparent;min-height:52px;width:100%;overflow:hidden}
.ky b{font-size:.68rem;opacity:.75}
.ky .ky-ad{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.ky small{font-size:.66rem;opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.ky-bos{background:var(--bs-body-bg);border:1px dashed var(--bs-success);color:var(--bs-success-text-emphasis);cursor:pointer}
.ky-bos:hover:not([disabled]){background:rgba(var(--bs-success-rgb),.12)}
.ky-dolu{background:rgba(var(--bs-primary-rgb),.13);border-color:rgba(var(--bs-primary-rgb),.35);color:var(--bs-emphasis-color);cursor:pointer}
.ky-dolu.ky-k-formen{background:rgba(var(--bs-warning-rgb),.2);border-color:rgba(var(--bs-warning-rgb),.55)}
.ky-dolu.ky-k-isci{background:rgba(var(--bs-secondary-rgb),.15);border-color:rgba(var(--bs-secondary-rgb),.45)}
.ky-dolu.ky-k-misafir{background:rgba(var(--bs-info-rgb),.18);border-color:rgba(var(--bs-info-rgb),.5)}
.ky-dolu:hover{filter:brightness(.95)}
.ky-ranza{border-left-width:4px}
.ky-ranza-grup{display:flex;flex-direction:column;gap:2px;padding:2px;border:1px solid var(--bs-border-color);border-radius:6px;background:var(--bs-secondary-bg)}
.ky-ranza-grup .ky{min-height:44px}
.ky-disi{background:var(--bs-secondary-bg);color:var(--bs-secondary-color);text-decoration:line-through}
.ky-soluk{opacity:.18}
.ky-vurgu{outline:3px solid var(--bs-danger);outline-offset:1px}
.ky-ara-sonuc .list-group-item{cursor:pointer}
@media print{.ky-arac,.sidebar,.ern-topbar{display:none!important}}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3 ky-arac">
  <h4 class="mb-0"><i class="bi bi-grid-3x3-gap text-primary me-2"></i>Yerleşim Krokisi</h4>
  <span class="badge bg-primary-subtle text-primary-emphasis fs-6"><?= $top['dolu'] ?> / <?= $top['yatak'] ?> dolu</span>
  <?php if ($top['yatak']): ?><span class="text-muted small">%<?= (int)round($top['dolu'] * 100 / $top['yatak']) ?> doluluk · <?= $top['yatak'] - $top['dolu'] ?> boş yatak</span><?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <input type="search" id="kyVurgu" class="form-control form-control-sm" style="width:230px" placeholder="Kişi / firma vurgula…">
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i></button>
    <?php if (yetki_var('duzenle')): ?><a href="yapi.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-building-gear me-1"></i>Yapı</a><?php endif; ?>
  </div>
</div>

<?php if (!$binalar): ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
  <i class="bi bi-buildings display-5 text-muted"></i>
  <h5 class="mt-3">Henüz bina tanımlı değil</h5>
  <p class="text-muted">Önce kampın binalarını, katlarını, konteynerlerini ve yataklarını tanımlayın — hızlı yapı üretici
    "8 konteyner × 2 oda" gibi düzenleri tek seferde açar.</p>
  <a href="yapi.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Kamp yapısını oluştur</a>
</div></div>
<?php else: ?>

<div class="d-flex flex-wrap gap-2 mb-3 ky-arac">
  <a href="yerlesim.php" class="btn btn-sm <?= !$binaSec ? 'btn-primary' : 'btn-outline-secondary' ?> ky-pill">Tüm binalar</a>
  <?php foreach ($binalar as $id => $b): $d = $doluluk[$id] ?? ['dolu'=>0,'yatak'=>0]; ?>
  <a href="yerlesim.php?bina=<?= $id ?>" class="btn btn-sm <?= $binaSec === $id ? 'btn-primary' : 'btn-outline-secondary' ?> ky-pill">
    <i class="bi <?= KAMP_BINA_TUR[$b['tur']][1] ?? 'bi-building' ?> me-1"></i><?= h($b['ad']) ?>
    <span class="badge <?= $binaSec === $id ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= $d['dolu'] ?>/<?= $d['yatak'] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<div class="d-flex flex-wrap gap-3 small text-muted mb-3 ky-arac">
  <span><span class="ky ky-bos d-inline-flex" style="width:auto;min-height:0;padding:0 .4rem">boş</span> tıkla → yerleştir</span>
  <span><span class="ky ky-dolu d-inline-flex" style="width:auto;min-height:0;padding:0 .4rem">staff</span></span>
  <span><span class="ky ky-dolu ky-k-formen d-inline-flex" style="width:auto;min-height:0;padding:0 .4rem">formen</span></span>
  <span><span class="ky ky-dolu ky-k-isci d-inline-flex" style="width:auto;min-height:0;padding:0 .4rem">işçi</span></span>
  <span>çerçeveli blok = ranza (üst yatak yukarıda)</span>
</div>

<?php foreach ($binalar as $bid => $b): if ($binaSec && $binaSec !== $bid) continue; $katlar = $agac[$bid] ?? []; $d = $doluluk[$bid] ?? null; ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
    <i class="bi <?= KAMP_BINA_TUR[$b['tur']][1] ?? 'bi-building' ?> text-primary"></i>
    <strong><?= h($b['ad']) ?></strong>
    <span class="text-muted small"><?= h(KAMP_BINA_TUR[$b['tur']][0] ?? $b['tur']) ?></span>
    <?php if ($d): ?><span class="badge bg-secondary"><?= $d['dolu'] ?> / <?= $d['yatak'] ?> dolu</span><?php endif; ?>
    <?php if (yetki_var('duzenle')): ?><a href="yapi.php?bina=<?= $bid ?>" class="ms-auto small">yapıyı düzenle →</a><?php endif; ?>
  </div>
  <div class="card-body">
  <?php if (!$katlar): ?>
    <div class="text-muted small">Bu binada oda yok. <a href="yapi.php?bina=<?= $bid ?>">Oda / konteyner ekle</a></div>
  <?php endif; ?>
  <?php foreach ($katlar as $kat => $odalar):
      $ks = ['yatak'=>0,'dolu'=>0]; foreach ($odalar as $o) if (kamp_oda_yatakli($o['tur'])) { $x = kamp_oda_sayac($o); $ks['yatak'] += $x['yatak']; $ks['dolu'] += $x['dolu']; }
      // Üniteye göre grupla (konteyner); ünitesiz odalar tek tek
      $grup = [];
      foreach ($odalar as $o) $grup[$o['unite'] !== null && $o['unite'] !== '' ? 'u:' . $o['unite'] : 'o:' . $o['id']][] = $o;
  ?>
    <div class="ky-kat">
      <div class="ky-kat-bas"><?= h(kamp_kat_ad((int)$kat)) ?>
        <span class="badge bg-light text-dark border"><?= $ks['dolu'] ?> / <?= $ks['yatak'] ?></span></div>
      <div class="row g-2">
      <?php foreach ($grup as $gk => $gOda):
          if (str_starts_with($gk, 'u:')):
              usort($gOda, fn($a, $c) => (['sol'=>0,'orta'=>1,'sag'=>2][$a['konum']] ?? 1) <=> (['sol'=>0,'orta'=>1,'sag'=>2][$c['konum']] ?? 1) ?: $a['sira'] <=> $c['sira']); ?>
        <div class="col-md-6 col-xxl-4 ky-ucol">
          <div class="ky-unite-ad"><i class="bi bi-box me-1"></i>Konteyner <?= h(substr($gk, 2)) ?></div>
          <div class="ky-unite"><?php foreach ($gOda as $o) echo ky_oda($o, $dbSorun, $yaz); ?></div>
        </div>
      <?php else: $o = $gOda[0]; $genis = count($o['yataklar']) > 6; ?>
        <div class="<?= $genis ? 'col-lg-6 col-xxl-4' : 'col-sm-6 col-lg-4 col-xxl-3' ?>"><?= ky_oda($o, $dbSorun, $yaz) ?></div>
      <?php endif; endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($yaz): ?>
<!-- Yerleştir -->
<div class="modal fade" id="mYer" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <form method="post" action="konaklama.php" id="fYer" class="d-flex flex-column" style="min-height:0">
      <input type="hidden" name="islem" value="yerlestir">
      <input type="hidden" name="geri" value="<?= h($geri) ?>">
      <input type="hidden" name="yatak_id" id="yerYatak">
      <input type="hidden" name="kisi_id" id="yerKisi">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Yerleştir — <span id="yerEt"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3">
          <li class="nav-item"><button type="button" class="nav-link active" data-sekme="kayitli">Kayıtlı kişi</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-sekme="yeni">Yeni kişi</button></li>
        </ul>
        <div data-panel="kayitli">
          <input type="search" id="yerAra" class="form-control" placeholder="Ad soyad, TC, firma ile ara…" autocomplete="off">
          <div class="list-group ky-ara-sonuc mt-2" id="yerSonuc"></div>
          <div class="alert alert-success py-2 mt-2 d-none" id="yerSecili"></div>
        </div>
        <div data-panel="yeni" class="d-none">
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label small">Ad *</label><input name="yeni_ad" class="form-control" id="yeniAd"></div>
            <div class="col-md-6"><label class="form-label small">Soyad</label><input name="yeni_soyad" class="form-control"></div>
            <div class="col-md-6"><label class="form-label small">Firma</label>
              <select name="yeni_firma_id" class="form-select" data-ara><?= kamp_firma_options($pdoKamp, null) ?></select></div>
            <div class="col-md-6"><label class="form-label small">… listede yoksa yeni firma adı</label><input name="yeni_firma" class="form-control" placeholder="ör. YILDIZLAR İNŞAAT"></div>
            <div class="col-md-4"><label class="form-label small">Kategori</label>
              <select name="yeni_kategori" class="form-select"><?php foreach (KAMP_KATEGORI as $k => [$ad]): ?><option value="<?= $k ?>"<?= $k === 'isci' ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small">Görev</label><input name="yeni_gorev" class="form-control" placeholder="ör. KALIPÇI"></div>
            <div class="col-md-4"><label class="form-label small">Telefon</label><input name="yeni_telefon" class="form-control"></div>
            <div class="col-md-6"><label class="form-label small">TC Kimlik No</label><input name="yeni_tc" class="form-control" inputmode="numeric" maxlength="11"></div>
          </div>
        </div>
        <hr>
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label small">Giriş tarihi</label><input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-8"><label class="form-label small">Açıklama</label><input name="aciklama" class="form-control" maxlength="200"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button>
        <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Yerleştir</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<!-- Dolu yatak -->
<div class="modal fade" id="mDolu" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person me-2"></i><span id="doluAd"></span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <table class="table table-sm mb-3">
        <tr><th class="text-muted fw-normal" style="width:35%">Yatak</th><td id="doluEt"></td></tr>
        <tr><th class="text-muted fw-normal">Firma</th><td id="doluFirma"></td></tr>
        <tr><th class="text-muted fw-normal">Görev</th><td id="doluGorev"></td></tr>
        <tr><th class="text-muted fw-normal">Telefon</th><td id="doluTel"></td></tr>
        <tr><th class="text-muted fw-normal">Giriş</th><td id="doluGiris"></td></tr>
      </table>
      <a href="#" id="doluKart" class="btn btn-outline-primary btn-sm mb-3"><i class="bi bi-person-vcard me-1"></i>Kişi kartı / geçmiş</a>
      <a href="#" id="doluTutanak" target="_blank" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-printer me-1"></i>Oda teslim tutanağı</a>
      <?php if ($yaz): ?>
      <div class="accordion" id="doluAcc">
        <div class="accordion-item">
          <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accTasi"><i class="bi bi-arrow-left-right me-2"></i>Başka yatağa taşı</button></h2>
          <div id="accTasi" class="accordion-collapse collapse" data-bs-parent="#doluAcc"><div class="accordion-body">
            <form method="post" action="konaklama.php">
              <input type="hidden" name="islem" value="tasi"><input type="hidden" name="geri" value="<?= h($geri) ?>">
              <input type="hidden" name="kisi_id" class="js-kisi">
              <label class="form-label small">Yeni yatak (boş yataklar)</label>
              <select name="yatak_id" class="form-select mb-2" required data-ara>
                <option value="">— seçin —</option>
                <?php $og = null; foreach ($bosYatak as $y): $g = $y['bina_ad'] . ' · ' . kamp_kat_ad((int)$y['kat']);
                  if ($g !== $og) { if ($og !== null) echo '</optgroup>'; echo '<optgroup label="' . h($g) . '">'; $og = $g; } ?>
                  <option value="<?= (int)$y['id'] ?>"><?= h($y['oda_ad'] . ' › ' . $y['no']) ?></option>
                <?php endforeach; if ($og !== null) echo '</optgroup>'; ?>
              </select>
              <div class="row g-2"><div class="col-6"><label class="form-label small">Taşınma tarihi</label>
                <input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div></div>
              <button class="btn btn-primary btn-sm mt-2">Taşı</button>
              <div class="form-text">Eski yatak bu tarihte kapanır, yenisi aynı gün açılır — geçmiş korunur, icmalde yeni giriş sayılmaz.</div>
            </form>
          </div></div>
        </div>
        <div class="accordion-item">
          <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accCikis"><i class="bi bi-box-arrow-right me-2"></i>Kamptan çıkış</button></h2>
          <div id="accCikis" class="accordion-collapse collapse" data-bs-parent="#doluAcc"><div class="accordion-body">
            <form method="post" action="konaklama.php">
              <input type="hidden" name="islem" value="cikis"><input type="hidden" name="geri" value="<?= h($geri) ?>">
              <input type="hidden" name="konaklama_id" class="js-kon">
              <div class="row g-2">
                <div class="col-6"><label class="form-label small">Çıkış tarihi</label>
                  <input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                <div class="col-6"><label class="form-label small">Sebep</label>
                  <input name="sebep" class="form-control" list="dlSebep"></div>
                <div class="col-12"><label class="form-label small">Açıklama (demirbaş teslim durumu vb.)</label><input name="aciklama" class="form-control" maxlength="200"></div>
              </div>
              <button class="btn btn-danger btn-sm mt-2" onclick="return confirm('Çıkış kaydedilsin mi?')">Çıkışı kaydet</button>
            </form>
          </div></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div></div>
</div>
<datalist id="dlSebep"><?php foreach (KAMP_CIKIS_SEBEP as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>

<script src="../assets/js/secici.js"></script>
<script>
(function(){
  function norm(s){ s=(s||'').toLocaleUpperCase('tr-TR');
    var m={'İ':'I','I':'I','Ş':'S','Ğ':'G','Ü':'U','Ö':'O','Ç':'C'}; s=s.replace(/[İIŞĞÜÖÇ]/g,function(c){return m[c];});
    return s.replace(/[^A-Z0-9]+/g,' ').trim(); }
  var M = function(id){ var el=document.getElementById(id); return el && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(el) : null; };

  // ── vurgu ──
  var vg = document.getElementById('kyVurgu');
  if (vg) vg.addEventListener('input', function(){
    var q = norm(vg.value), kel = q ? q.split(' ') : [];
    document.querySelectorAll('.ky').forEach(function(b){
      if (!kel.length) { b.classList.remove('ky-soluk','ky-vurgu'); return; }
      var a = b.getAttribute('data-ara') || '', ok = !!a && kel.every(function(k){ return a.indexOf(k) >= 0; });
      b.classList.toggle('ky-vurgu', ok); b.classList.toggle('ky-soluk', !ok);
    });
    var ilk = document.querySelector('.ky-vurgu'); if (ilk) ilk.scrollIntoView({block:'center', behavior:'smooth'});
  });

  // ── yatak tıklama ──
  document.addEventListener('click', function(e){
    var b = e.target.closest('.ky'); if (!b || b.disabled || b.classList.contains('ky-disi')) return;
    if (b.dataset.dolu) {
      ['Ad','Et','Firma','Gorev','Tel'].forEach(function(k){ var el=document.getElementById('dolu'+k); if (el) el.textContent = b.dataset[k.toLowerCase()] || '—'; });
      document.getElementById('doluGiris').textContent = b.dataset.giris + ' · ' + b.dataset.gun + ' gece';
      document.getElementById('doluKart').href = 'kisi_detay.php?id=' + b.dataset.kisi;
      document.getElementById('doluTutanak').href = 'tutanak.php?konaklama=' + b.dataset.konaklama;
      document.querySelectorAll('#mDolu .js-kisi').forEach(function(i){ i.value = b.dataset.kisi; });
      document.querySelectorAll('#mDolu .js-kon').forEach(function(i){ i.value = b.dataset.konaklama; });
      var m = M('mDolu'); if (m) m.show();
    } else if (b.dataset.yatak) {
      document.getElementById('yerYatak').value = b.dataset.yatak;
      document.getElementById('yerEt').textContent = b.dataset.et;
      secKisi(null); sekme('kayitli');
      var a = document.getElementById('yerAra'); a.value=''; document.getElementById('yerSonuc').innerHTML='';
      var m = M('mYer'); if (m) { m.show(); setTimeout(function(){ a.focus(); }, 350); }
    }
  });

  // ── yerleştir penceresi ──
  var fYer = document.getElementById('fYer'); if (!fYer) return;
  function sekme(s){
    fYer.querySelectorAll('[data-sekme]').forEach(function(t){ t.classList.toggle('active', t.dataset.sekme===s); });
    fYer.querySelectorAll('[data-panel]').forEach(function(p){ p.classList.toggle('d-none', p.dataset.panel!==s); });
    fYer.dataset.sekme = s;
    if (s === 'yeni') secKisi(null);
  }
  fYer.querySelectorAll('[data-sekme]').forEach(function(t){ t.addEventListener('click', function(){ sekme(t.dataset.sekme); }); });
  function secKisi(k){
    document.getElementById('yerKisi').value = k ? k.id : '';
    var s = document.getElementById('yerSecili');
    if (!k) { s.classList.add('d-none'); return; }
    s.classList.remove('d-none');
    s.className = 'alert py-2 mt-2 ' + (k.yer ? 'alert-warning' : 'alert-success');
    s.innerHTML = '<strong></strong> <span class="small"></span>' + (k.yer ? '<div class="small mt-1"><i class="bi bi-arrow-left-right me-1"></i>Şu an <b class="js-yer"></b> yatağında (giriş <span class="js-gir"></span>) — kaydedince <u>TAŞINACAK</u>.</div>' : '');
    s.querySelector('strong').textContent = k.ad;
    s.querySelector('span.small').textContent = [k.firma, k.gorev].filter(Boolean).join(' · ');
    if (k.yer) { s.querySelector('.js-yer').textContent = k.yer; s.querySelector('.js-gir').textContent = k.giris; }
  }
  var ara = document.getElementById('yerAra'), sonuc = document.getElementById('yerSonuc'), zam;
  ara.addEventListener('input', function(){
    clearTimeout(zam); var q = ara.value.trim();
    if (q.length < 2) { sonuc.innerHTML=''; return; }
    zam = setTimeout(function(){
      fetch('yerlesim.php?kisi_ara=' + encodeURIComponent(q), {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(js){
        sonuc.innerHTML = '';
        if (!js.length) { sonuc.innerHTML = '<div class="list-group-item text-muted small">Bulunamadı — "Yeni kişi" sekmesinden ekleyin.</div>'; return; }
        js.forEach(function(k){
          var a = document.createElement('button'); a.type='button'; a.className='list-group-item list-group-item-action';
          a.innerHTML = '<div class="d-flex"><strong></strong><span class="ms-auto small text-muted"></span></div><div class="small"></div>';
          a.querySelector('strong').textContent = k.ad;
          a.querySelector('span').textContent = [k.firma, k.kat].filter(Boolean).join(' · ');
          var alt = a.querySelector('div.small');
          alt.textContent = k.yer ? ('📍 ' + k.yer) : 'kampta değil';
          alt.className = 'small ' + (k.yer ? 'text-warning-emphasis' : 'text-success');
          a.addEventListener('click', function(){ secKisi(k); sonuc.innerHTML=''; ara.value = k.ad; });
          sonuc.appendChild(a);
        });
      }).catch(function(){ sonuc.innerHTML = '<div class="list-group-item text-danger small">Arama yapılamadı.</div>'; });
    }, 250);
  });
  fYer.addEventListener('submit', function(e){
    if (fYer.dataset.sekme === 'yeni') {
      if (!document.getElementById('yeniAd').value.trim()) { e.preventDefault(); alert('Yeni kişinin adını yazın.'); }
    } else if (!document.getElementById('yerKisi').value) {
      e.preventDefault(); alert(ara.value.trim() ? 'Listeden bir kişi seçin (ya da "Yeni kişi" sekmesini kullanın).' : 'Kişi seçin.');
    }
  });
  fYer.dataset.sekme = 'kayitli';
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
