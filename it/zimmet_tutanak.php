<?php
/**
 * it/zimmet_tutanak.php — ZİMMET TUTANAĞI (A4, ERN Taahhüt logolu)
 *   ?id=…    tek cihaz
 *   ?kisi=…  kişinin üzerindeki TÜM aktif cihazlar tek tutanakta
 * Tarayıcıdan Yazdır → "PDF olarak kaydet"; imzalı kopya cihaz kartına belge olarak yüklenir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
require_once __DIR__ . '/../includes/db_it.php';
require_once __DIR__ . '/_ortak.php';
it_semasi_kur($pdoIt);

$id   = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$kisi = trim((string)($_GET['kisi'] ?? ''));
$pid  = (int)($_GET['personel_id'] ?? 0);
$per  = null;
if ($pid) {
    $per = it_personel_bul($pdoIt, $pid);
    if (!$per) die('Personel bulunamadı.');
    $liste = it_personel_cihazlari($pdoIt, $pid);
    if (!$liste) die('Bu personele zimmetli cihaz yok.');
    $kisi = it_personel_ad($per);
    $no = 'ZMT-P' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT) . '-' . date('Ymd');
    $geri = 'personel_detay.php?id=' . $pid;
} elseif ($id) {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE id=?"); $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) die('Cihaz bulunamadı.');
    $kisi = (string)$c['zimmetli'];
    $per  = it_personel_bul($pdoIt, (int)($c['personel_id'] ?? 0));
    $liste = [$c];
    $no = 'ZMT-' . $c['envanter_no'];
    $geri = 'cihaz_detay.php?id=' . $id;
} elseif ($kisi !== '') {
    $st = $pdoIt->prepare("SELECT * FROM it_cihazlar WHERE zimmetli=? AND " . it_envanterde() . " ORDER BY envanter_no"); $st->execute([$kisi]);
    $liste = $st->fetchAll();
    if (!$liste) die('Bu kişiye zimmetli cihaz yok.');
    $no = 'ZMT-' . strtoupper(substr(md5(it_norm($kisi)), 0, 6)) . '-' . date('Ymd');
    $geri = 'cihazlar.php?zimmetli=' . urlencode($kisi);
} else { die('Cihaz ya da kişi belirtilmedi.'); }

$ilk = $liste[0];
$departman = $per['birim'] ?? ($ilk['departman'] ?: '');
$lokasyon  = $per && $per['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$per['lokasyon_id']) : ($ilk['lokasyon_id'] ? it_lokasyon_yol($pdoIt, (int)$ilk['lokasyon_id']) : ($ilk['lokasyon'] ?: ''));
$f2 = fn($n) => number_format((float)$n, 2, ',', '.');
$toplam = array_sum(array_map(fn($r) => (float)($r['fiyat'] ?? 0), $liste));
$teslimEden = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Zimmet Tutanağı <?= h($no) ?></title>
<style>
  * { box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:18mm 16mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #00584E; padding-bottom:10px; }
  .top .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:22%; }
  table.items { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
  table.items th, table.items td { border:1px solid #bbb; padding:6px 8px; vertical-align:top; }
  table.items th { background:#00584E; color:#fff; font-weight:600; }
  table.items td.r, table.items th.r { text-align:right; }
  table.items tfoot td { background:#f5f7f7; font-weight:700; }
  .mono { font-family: Consolas, monospace; font-size:11.5px; }
  .note { font-size:11.5px; color:#333; margin:16px 0; line-height:1.65; text-align:justify; }
  .note ol { margin:6px 0 0 18px; padding:0; }
  .signs { display:flex; justify-content:space-between; margin-top:40px; }
  .sign { width:45%; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:56px; padding-top:6px; font-size:12px; font-weight:600; }
  .sign .sub { font-size:11px; color:#666; }
  .foot { margin-top:24px; text-align:center; font-size:10px; color:#999; border-top:1px solid #eee; padding-top:8px; }
  .toolbar { text-align:center; padding:10px; }
  .toolbar button, .toolbar a { font:inherit; padding:8px 18px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; margin:0 4px; }
  .btn-print { background:#00584E; color:#fff; }
  .btn-back { background:#e0e0e0; color:#333; }
  @media print { body { background:#fff; } .sheet { margin:0; box-shadow:none; width:auto; padding:12mm; } .toolbar { display:none; } }
</style>
</head>
<body>
<div class="toolbar">
  <button class="btn-print" onclick="window.print()">🖨 Yazdır / PDF Kaydet</button>
  <a class="btn-back" href="<?= h($geri) ?>">← Geri</a>
</div>

<div class="sheet">
  <div class="top">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px" onerror="this.outerHTML='<div class=\\'logo\\'>ERN TAAHHÜT</div>'"><div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">IT ENVANTER</div></div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= date('d.m.Y') ?></strong><br>
      Proje: <strong>Batı Yakası</strong>
    </div>
  </div>

  <div class="doc-title">BİLGİ İŞLEM DEMİRBAŞ ZİMMET TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($no) ?></div>

  <table class="info">
    <tr><td class="k">Zimmet Alan</td><td><strong><?= h($kisi ?: '—') ?></strong><?= $per && $per['unvan'] ? ' — ' . h($per['unvan']) : '' ?></td>
        <td class="k">Sicil No</td><td><?= h($per['sicil_no'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">Birim / Departman</td><td><?= h($departman ?: '—') ?></td>
        <td class="k">Telefon</td><td><?= h($per['telefon'] ?? '') ?: '—' ?></td></tr>
    <tr><td class="k">Lokasyon / Proje</td><td><?= h($lokasyon ?: '—') ?></td>
        <td class="k">Zimmet Tarihi</td><td><?= $ilk['zimmet_tarihi'] ? format_date($ilk['zimmet_tarihi']) : date('d.m.Y') ?></td></tr>
    <?php if ($per && $per['ise_giris']): ?><tr><td class="k">İşe Giriş</td><td><?= format_date($per['ise_giris']) ?></td><td class="k">Cihaz adedi</td><td><?= count($liste) ?></td></tr><?php endif; ?>
  </table>

  <table class="items">
    <thead><tr><th style="width:30px">S.No</th><th style="width:78px">Envanter No</th><th>Cihaz</th><th>Marka / Model</th><th>Seri No</th><th class="r" style="width:88px">Değer (TL)</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="mono"><?= h($r['envanter_no']) ?></td>
        <td><?= h($r['ad']) ?><div style="font-size:10.5px;color:#666"><?= h(it_kategoriAd($r['kategori'])) ?><?= $r['ozellikler'] ? ' · ' . h($r['ozellikler']) : '' ?></div></td>
        <td><?= h(trim(($r['marka'] ?? '') . ' ' . ($r['model'] ?? '')) ?: '—') ?></td>
        <td class="mono"><?= h($r['seri_no'] ?: '—') ?></td>
        <td class="r"><?= $r['fiyat'] !== null ? $f2($r['fiyat']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="5" class="r">TOPLAM (<?= count($liste) ?> kalem)</td><td class="r"><?= $f2($toplam) ?></td></tr></tfoot>
  </table>

  <div class="note">
    Yukarıda envanter numarası, tanımı ve seri numarası belirtilen bilgi işlem demirbaş(lar)ı, çalışır ve eksiksiz
    durumda <strong><?= h($kisi ?: '—') ?></strong> adlı personele iş amaçlı kullanılmak üzere zimmetle teslim edilmiştir.
    Zimmet alan personel;
    <ol>
      <li>Cihazı yalnız işle ilgili amaçlarla, özenle ve kurumsal bilgi güvenliği kurallarına uygun kullanmayı,</li>
      <li>Arıza, kayıp veya hasar durumunda derhal bilgi işlem birimine bildirmeyi,</li>
      <li>Kurumsal veri, hesap ve lisansları üçüncü kişilerle paylaşmamayı,</li>
      <li>Görevden ayrılma veya talep halinde cihazı aksesuarları ve verileriyle birlikte eksiksiz iade etmeyi</li>
    </ol>
    kabul ve taahhüt eder. İşbu tutanak iki nüsha düzenlenmiş; bir nüshası personele verilmiş, diğeri bilgi işlem arşivine alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">Teslim Eden — ERN Taahhüt Bilgi İşlem</div><div class="sub"><?= h($teslimEden) ?> · İmza</div></div>
    <div class="sign"><div class="line">Teslim Alan — <?= h($kisi ?: 'Personel') ?></div><div class="sub">Ad Soyad / İmza</div></div>
  </div>

  <div class="foot">ERN Taahhüt IT Envanter — <?= date('d.m.Y H:i') ?></div>
</div>
</body></html>
