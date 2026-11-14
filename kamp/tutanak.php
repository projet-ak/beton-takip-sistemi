<?php
/**
 * kamp/tutanak.php — KAMP ODA / YATAK TESLİM TUTANAĞI (A4, ERN Taahhüt logolu)
 *  ?konaklama=ID — kişiye verilen yatak + odadaki (ve aynı konteynerin ortak alanındaki)
 *  demirbaşlar, kamp kuralları, çift imza. Konaklama kapanmışsa İADE bölümü dolu basılır.
 *  Yazdırma: @page margin:0 + kenar boşluğu sayfa içinde (tarayıcı üstbilgisi basılmasın — IT deseni).
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$id = (int)($_GET['konaklama'] ?? 0);
$st = $pdoKamp->prepare("SELECT k.*, p.ad, p.soyad, p.tc_no, p.sicil_no, p.gorev, p.telefon, p.kategori, f.ad AS firma_ad,
                                y.no AS yatak_no, y.tur AS yatak_tur, o.id AS oda_gid, o.ad AS oda_ad, o.kat, o.unite, o.bina_id, b.ad AS bina_ad
                           FROM kamp_konaklama k JOIN kamp_kisiler p ON p.id=k.kisi_id
                           LEFT JOIN kamp_firmalar f ON f.id=COALESCE(k.firma_id,p.firma_id)
                           LEFT JOIN kamp_yataklar y ON y.id=k.yatak_id
                           LEFT JOIN kamp_odalar o ON o.id=COALESCE(y.oda_id,k.oda_id)
                           LEFT JOIN kamp_binalar b ON b.id=o.bina_id
                          WHERE k.id=?");
$st->execute([$id]);
$k = $st->fetch();
if (!$k) { http_response_code(404); exit('Konaklama kaydı bulunamadı.'); }

// Odanın + aynı konteynerin ORTAK alanının demirbaşları
$db = [];
if ($k['oda_gid']) {
    $db = kamp_oda_demirbas($pdoKamp, (int)$k['oda_gid']);
    if ($k['unite']) {
        $s = $pdoKamp->prepare("SELECT d.*, o.ad AS oda_ad FROM kamp_demirbas d JOIN kamp_odalar o ON o.id=d.oda_id
                                 WHERE o.bina_id=? AND o.unite=? AND o.tur='ortak' ORDER BY d.ad");
        $s->execute([$k['bina_id'], $k['unite']]);
        foreach ($s->fetchAll() as $r) { $r['ortak'] = true; $db[] = $r; }
    }
}
$no = 'KMP-T-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
$KURAL = [
    'Kendisine teslim edilen yatak, dolap ve odadaki demirbaşları özenle kullanmayı; kasıt veya ihmalden doğan hasar ve kayıpların bedelinin hak edişinden / maaşından kesilmesini kabul eder.',
    'Odada yalnız kendisine tahsis edilen yatağı kullanır; kamp yönetiminin onayı olmadan yatak / oda değiştiremez, kampa misafir alamaz.',
    'Odada ısıtıcı, ocak, ütü vb. izinsiz elektrikli cihaz kullanmaz; sigara içmez, yangın ve iş güvenliği kurallarına uyar.',
    'Ortak alanların (banyo, WC, çamaşırhane) temizliğine ve kullanım saatlerine uyar.',
    'Kamptan ayrılırken yatağı, odanın anahtarını ve demirbaşları kamp sorumlusuna eksiksiz teslim eder; iade tutanağı imzalanmadan ayrılış işlemi tamamlanmaz.',
];
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($no) ?> — Kamp Teslim Tutanağı</title>
<style>
@page { size:A4; margin:0 }
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:11.5px;color:#111;margin:0;background:#e9ecef}
.toolbar{position:sticky;top:0;background:#00584E;color:#fff;padding:8px 14px;display:flex;gap:8px;align-items:center;z-index:5}
.toolbar button,.toolbar a{background:#fff;color:#00584E;border:0;border-radius:4px;padding:5px 12px;font-weight:600;cursor:pointer;text-decoration:none;font-size:12px}
.sheet{width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:12mm 14mm 10mm;box-shadow:0 2px 10px rgba(0,0,0,.15)}
.antet{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #00584E;padding-bottom:6px;margin-bottom:8px}
.antet .meta{text-align:right;font-size:10.5px;line-height:1.5}
h1{font-size:16px;text-align:center;margin:6px 0 10px;letter-spacing:.5px}
.sec{background:#00584E;color:#fff;font-weight:700;font-size:10.5px;padding:3px 7px;margin:10px 0 4px;letter-spacing:.5px}
table{width:100%;border-collapse:collapse}
td,th{border:1px solid #888;padding:4px 6px;vertical-align:top}
th{background:#f1f3f5;text-align:left;font-size:10.5px}
td.k{width:22%;background:#f8f9fa;font-weight:600}
.kutu{display:inline-block;width:11px;height:11px;border:1px solid #333;margin-right:3px;vertical-align:-1px}
ol{margin:4px 0 0 16px;padding:0} ol li{margin-bottom:3px;text-align:justify}
.imza{display:flex;gap:16px;margin-top:22px} .imza div{flex:1;border:1px solid #888;padding:6px;min-height:28mm;text-align:center}
.imza b{display:block;margin-bottom:4px} .imza .ad{margin-top:14mm;border-top:1px dotted #555;padding-top:3px}
.not{font-size:10px;color:#555;margin-top:8px}
@media print{body{background:#fff}.toolbar{display:none}.sheet{margin:0;box-shadow:none;min-height:0}tr,.imza{break-inside:avoid}}
</style></head><body>
<div class="toolbar"><strong><?= h($no) ?></strong><span style="opacity:.8"><?= h(trim($k['ad'] . ' ' . $k['soyad'])) ?></span>
  <button onclick="window.print()" style="margin-left:auto">Yazdır / PDF</button><a href="kisi_detay.php?id=<?= (int)$k['kisi_id'] ?>">Kişi kartı</a></div>
<div class="sheet">
  <div class="antet">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:44px" onerror="this.outerHTML='<b style=\'font-size:20px;color:#00584E\'>ERN TAAHHÜT</b>'">
      <div style="font-size:9.5px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">ŞANTİYE KAMP YÖNETİMİ</div></div>
    <div class="meta"><b>Tutanak No:</b> <?= h($no) ?><br><b>Düzenleme:</b> <?= date('d.m.Y') ?><br><b>Giriş:</b> <?= h(kamp_tarih_yaz($k['giris'])) ?></div>
  </div>
  <h1>KAMP ODA / YATAK TESLİM TUTANAĞI</h1>

  <div class="sec">KALAN KİŞİ</div>
  <table>
    <tr><td class="k">Adı Soyadı</td><td><?= h(trim($k['ad'] . ' ' . $k['soyad'])) ?></td><td class="k">TC Kimlik No</td><td><?= h($k['tc_no'] ?: '') ?></td></tr>
    <tr><td class="k">Firma</td><td><?= h($k['firma_ad'] ?: '') ?></td><td class="k">Görevi</td><td><?= h($k['gorev'] ?: '') ?></td></tr>
    <tr><td class="k">Telefon</td><td><?= h($k['telefon'] ?: '') ?></td><td class="k">Kategori</td><td><?= h(KAMP_KATEGORI[$k['kategori']][0] ?? '') ?></td></tr>
  </table>

  <div class="sec">TAHSİS EDİLEN YER</div>
  <table>
    <tr><td class="k">Bina / Blok</td><td><?= h($k['bina_ad'] ?: '') ?></td><td class="k">Kat</td><td><?= $k['kat'] !== null ? h(kamp_kat_ad((int)$k['kat'])) : '' ?></td></tr>
    <tr><td class="k">Oda</td><td><?= h($k['oda_ad'] ?: '') ?><?= $k['unite'] ? ' (konteyner ' . h($k['unite']) . ')' : '' ?></td><td class="k">Yatak</td><td><?= h($k['yatak_no'] ?: '') ?> <?= h(KAMP_YATAK_TUR[$k['yatak_tur']] ?? '') ?></td></tr>
    <tr><td class="k">Giriş tarihi</td><td><?= h(kamp_tarih_yaz($k['giris'])) ?></td><td class="k">Anahtar / kart</td><td><span class="kutu"></span>Verildi &nbsp; Adet: ......</td></tr>
  </table>

  <div class="sec">ODADAKİ DEMİRBAŞLAR</div>
  <table>
    <thead><tr><th style="width:5%">#</th><th>Demirbaş</th><th style="width:8%">Adet</th><th style="width:16%">Sistemdeki durum</th><th style="width:15%">Teslimde</th><th style="width:15%">İadede</th></tr></thead>
    <tbody>
    <?php if (!$db): ?><tr><td colspan="6" style="color:#777">Sistemde bu oda için demirbaş kaydı yok — teslim edilenleri elle yazın.</td></tr><?php endif; ?>
    <?php foreach ($db as $i => $d): ?>
      <tr><td><?= $i + 1 ?></td><td><?= h($d['ad']) ?><?= !empty($d['ortak']) ? ' <small>(ortak alan)</small>' : '' ?><?= $d['marka'] ? ' <small>— ' . h($d['marka']) . '</small>' : '' ?></td>
        <td><?= (int)$d['adet'] ?></td><td><?= h(KAMP_DB_DURUM[$d['durum']][0] ?? $d['durum']) ?></td>
        <td><span class="kutu"></span>Sağlam <span class="kutu"></span>Hasarlı</td><td><span class="kutu"></span>Sağlam <span class="kutu"></span>Eksik</td></tr>
    <?php endforeach; ?>
    <?php for ($i = 0; $i < max(0, 3 - count($db)); $i++): ?><tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?>
    </tbody>
  </table>

  <div class="sec">KAMP KURALLARI VE TAAHHÜT</div>
  <ol><?php foreach ($KURAL as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ol>

  <div class="sec">İADE (KAMPTAN AYRILIŞ)</div>
  <table>
    <tr><td class="k">Çıkış tarihi</td><td><?= $k['cikis'] ? h(kamp_tarih_yaz($k['cikis'])) : '.... / .... / ........' ?></td><td class="k">Sebep</td><td><?= h($k['cikis_sebep'] ?: '') ?></td></tr>
    <tr><td class="k">Eksik / hasar</td><td colspan="3" style="height:12mm"><?= $k['cikis'] ? h($k['aciklama'] ?: '') : '' ?></td></tr>
  </table>

  <div class="imza">
    <div><b>TESLİM EDEN</b>Kamp Sorumlusu<div class="ad">Ad Soyad / İmza</div></div>
    <div><b>TESLİM ALAN</b><?= h(trim($k['ad'] . ' ' . $k['soyad'])) ?><div class="ad">İmza</div></div>
    <div><b>İADEYİ ALAN</b>Kamp Sorumlusu<div class="ad">Ad Soyad / İmza / Tarih</div></div>
  </div>
  <div class="not">Bu tutanak iki nüsha düzenlenir; bir nüshası kamp yönetiminde, bir nüshası firmasında saklanır.</div>
</div>
</body></html>
