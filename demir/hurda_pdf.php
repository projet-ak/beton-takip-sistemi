<?php
/**
 * demir/hurda_pdf.php — Hurda satış/düşüm tutanağının yazdırılabilir (A4) görünümü.
 * Taşeron ile imzalaşmak için: tarayıcıdan Yazdır → "PDF olarak kaydet".
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth();
require_once __DIR__ . '/../includes/db_demir.php';
require_once __DIR__ . '/_iade_ortak.php';

$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { redirect('taseron_bakiye.php'); }

$s = $pdoDemir->prepare("
    SELECT hd.*, t.ad AS taseron_adi, t.kod AS taseron_kod, t.vkn AS taseron_vkn
    FROM demir_hurda hd
    LEFT JOIN demir_taseronlar t ON t.id = hd.taseron_id
    WHERE hd.id=?");
$s->execute([$id]);
$hd = $s->fetch();
if (!$hd) { die('Hurda kaydı bulunamadı.'); }

// Eski kayıtlarda no yoksa üret ve kaydet
if (empty($hd['hurda_no'])) {
    $hd['hurda_no'] = hurda_no_uret($pdoDemir, (string)($hd['taseron_kod'] ?? ''));
    $pdoDemir->prepare("UPDATE demir_hurda SET hurda_no=? WHERE id=?")->execute([$hd['hurda_no'], $id]);
}

// Taşeronun bakiye özeti (tutanak öncesi bağlam için): teslim/iade/hurda toplamları
$tid = (int)$hd['taseron_id'];
$topTeslim = (float)$pdoDemir->query("SELECT COALESCE(SUM(tk.miktar_ton),0) FROM demir_tutanak_kalemleri tk
    JOIN demir_tutanaklar tu ON tu.id=tk.tutanak_id WHERE tu.taseron_id={$tid}")->fetchColumn();
try {
    $st = $pdoDemir->prepare("SELECT t.ad FROM demir_taseronlar t WHERE t.id=?"); $st->execute([$tid]);
    $adU = mb_strtoupper((string)$st->fetchColumn(), 'UTF-8');
    $adU = str_replace(['İ','I','ı','i'],'I',$adU);
    foreach ($pdoDemir->query("SELECT firma, tip, miktar_ton FROM demir_tutanak_takip") as $r) {
        $f = str_replace(['İ','I','ı','i'],'I', mb_strtoupper((string)$r['firma'],'UTF-8'));
        if ($f === $adU && $r['tip']==='teslim') $topTeslim += (float)$r['miktar_ton'];
    }
} catch (Throwable $e) { /* defter yoksa geç */ }
$topHurda = (float)$pdoDemir->query("SELECT COALESCE(SUM(miktar_ton),0) FROM demir_hurda WHERE taseron_id={$tid}")->fetchColumn();

$fmt = fn($n) => number_format((float)$n, 3, ',', '.');
?>
<!DOCTYPE html>
<html lang="tr"><head>
<meta charset="UTF-8">
<title>Hurda Tutanağı <?= h($hd['hurda_no']) ?></title>
<style>
  * { box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#f0f0f0; }
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:18mm 16mm; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .top { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #00584E; padding-bottom:10px; }
  .top .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .top .logo small { display:block; font-size:11px; font-weight:600; color:#555; letter-spacing:2px; }
  .doc-title { text-align:center; margin:18px 0 6px; font-size:18px; font-weight:800; letter-spacing:1px; }
  .doc-no { text-align:center; font-size:13px; color:#00584E; font-weight:700; margin-bottom:16px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:14px; font-size:12.5px; }
  .info td { border:1px solid #cfcfcf; padding:6px 9px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:26%; }
  table.items { width:100%; border-collapse:collapse; font-size:12.5px; margin-top:4px; }
  table.items th, table.items td { border:1px solid #bbb; padding:7px 9px; }
  table.items th { background:#00584E; color:#fff; font-weight:600; }
  table.items td.r, table.items th.r { text-align:right; }
  table.items tfoot td { background:#f5f7f7; font-weight:700; }
  .note { font-size:11.5px; color:#333; margin:16px 0; line-height:1.65; text-align:justify; }
  .signs { display:flex; justify-content:space-between; margin-top:46px; }
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
  <a class="btn-back" href="taseron_bakiye.php">← Geri</a>
</div>

<div class="sheet">
  <div class="top">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:46px" onerror="this.outerHTML='<div class=\\'logo\\'>ERN TAAHHÜT</div>'"><div style="font-size:10px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">DEMİR TAKİP</div></div>
    <div style="text-align:right;font-size:11px;color:#555">
      Tarih: <strong><?= format_date($hd['tarih']) ?></strong>
    </div>
  </div>

  <div class="doc-title">HURDA DEMİR SATIŞ / DÜŞÜM TUTANAĞI</div>
  <div class="doc-no">Tutanak No: <?= h($hd['hurda_no']) ?></div>

  <table class="info">
    <tr><td class="k">Taşeron Firma</td><td><?= h($hd['taseron_adi'] ?: '—') ?></td>
        <td class="k">VKN</td><td><?= h($hd['taseron_vkn'] ?: '—') ?></td></tr>
    <tr><td class="k">Tutanak Tarihi</td><td><?= format_date($hd['tarih']) ?></td>
        <td class="k">Kayıt Tarihi</td><td><?= format_date(substr((string)$hd['created_at'],0,10)) ?></td></tr>
    <?php if ($hd['aciklama']): ?>
    <tr><td class="k">Açıklama</td><td colspan="3"><?= h($hd['aciklama']) ?></td></tr>
    <?php endif; ?>
  </table>

  <table class="items">
    <thead><tr><th style="width:36px">S.No</th><th>Malzeme / İşlem</th><th class="r" style="width:140px">Miktar (ton)</th></tr></thead>
    <tbody>
      <tr><td>1</td><td>İnşaat demiri — iş sonu hurda satışı (çaptan bağımsız düşüm)</td><td class="r"><?= $fmt($hd['miktar_ton']) ?></td></tr>
    </tbody>
    <tfoot><tr><td colspan="2" class="r">TOPLAM DÜŞÜLEN</td><td class="r"><?= $fmt($hd['miktar_ton']) ?></td></tfoot>
  </table>

  <table class="info" style="margin-top:14px">
    <tr><td class="k">Firmaya Toplam Teslim Edilen</td><td class="r" style="text-align:right"><?= $fmt($topTeslim) ?> ton</td>
        <td class="k">Toplam Hurda Düşümü (bu dahil)</td><td style="text-align:right"><?= $fmt($topHurda) ?> ton</td></tr>
  </table>

  <div class="note">
    İşbu tutanak ile; <strong><?= h($hd['taseron_adi'] ?: '—') ?></strong> firmasına proje kapsamında teslim edilen
    inşaat demirlerinden, iş sonunda kullanılamaz durumda kalan ve hurda olarak satışı yapılan yukarıda miktarı
    belirtilen <strong><?= $fmt($hd['miktar_ton']) ?> ton</strong> demirin, firmaya teslim edilen toplam demir
    miktarından (net teslim bakiyesinden) <strong>çaptan bağımsız olarak düşülmesi</strong> hususunda taraflar
    karşılıklı mutabık kalmıştır. İşbu tutanak iki nüsha olarak düzenlenmiş ve taraflarca imza altına alınmıştır.
  </div>

  <div class="signs">
    <div class="sign"><div class="line">ERN Taahhüt (Yüklenici)</div><div class="sub">Ad Soyad / İmza / Kaşe</div></div>
    <div class="sign"><div class="line"><?= h($hd['taseron_adi'] ?: 'Taşeron Firma') ?></div><div class="sub">Ad Soyad / İmza / Kaşe</div></div>
  </div>

  <div class="foot">ERN Taahhüt Demir Takip Sistemi — <?= date('d.m.Y H:i') ?></div>
</div>
</body></html>
