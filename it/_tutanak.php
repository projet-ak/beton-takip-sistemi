<?php
/**
 * it/_tutanak.php — A4 TUTANAK SUNUM KATMANI (zimmet · iade · transfer · hurda ORTAK)
 *
 * Dört tutanak sayfası da aynı kâğıdı basıyor ama yazdırma CSS'i her dosyada ELLE kopyalanmıştı;
 * hurda tutanağında 2026-09-17'de çözülen üç sorun diğer üçünde duruyordu (2026-10-08, kullanıcı:
 * "zimmet formu bu şekilde çıkıyor, bir sayfaya sığsın ikinci sayfaya kaymasın, diğer formlarda
 * böyleyse düzenleyelim"):
 *   1) ⚠⚠ `.sheet { min-height:297mm }` yazdırmada SIFIRLANMIYORDU: @page kenar boşlukları
 *      yüzünden basılabilir alan ~277mm olduğundan 297mm'lik kâğıt TEK SATIR içerikle bile
 *      taşıyor ve belge HER ZAMAN 2 sayfa çıkıyordu.
 *   2) Tarayıcının kendi üstbilgisi (URL · saat · sayfa no) basılıyordu — `@page { margin:0 }`
 *      çizecek yeri bırakmaz; kenar boşluğu bunun yerine tablo HÜCRELERİNE taşınır.
 *   3) 2. sayfaya taşan belge ANTETSİZ başlıyordu — belge `thead`/`tfoot`'lu TEK bir tabloya
 *      sarılır, tarayıcı sayfa taşmasında ikisini KENDİ tekrarlar ve yer ayırır.
 *      ⚠ `position:fixed` ile de tekrar eder ama akıştan çıktığı için negatif offset taşma
 *      sayılıp BOŞ bir sayfa açıyordu (tek cihazlık belge yine 2 sayfa görünüyordu).
 * Ayrıca zimmet tutanağında `.evrak` (imzalı evrak yükleme paneli) yazdırmada GİZLENMİYORDU —
 * kullanıcının çıktısının tepesinde "İmzalı Evrak — yüklenmedi" kutusu basılıyordu.
 *
 * Kullanım (sayfa sırası): tut_stil('<sayfaya özel CSS>') → tut_antet([...]) → gövde → tut_kapat()
 *                          → tut_sayfa_js()
 * Sayfaya özel sınıflar `tut_stil()`'in parametresiyle eklenir; ORTAK ölçüler burada durur ve
 * tek yerden düzeltilir.
 */

/** Ortak yazdırma CSS'i + sayfaya özel ek kurallar. */
function tut_stil(string $ek = ''): void
{
    ?>
<style>
  * { box-sizing:border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color:#111; margin:0; background:#eceff0; }
  /* Ekran ölçüleri baskıyla AYNI: 210mm genişlik + aynı hücre boşlukları → sayfa sayısı ölçümü
     (tut_sayfa_js) baskıya birebir taşınır. Kenar boşluğu `.sheet` padding'inde DEĞİL, tablo
     hücrelerinde: thead/tfoot her sayfada tekrar ettiği için üst/alt boşluğu da onlar verir. */
  .sheet { width:210mm; min-height:297mm; margin:10px auto; background:#fff; padding:0; box-shadow:0 0 8px rgba(0,0,0,.15); }
  .antet { display:flex; justify-content:space-between; align-items:flex-start;
           border-bottom:3px solid #00584E; padding-bottom:6px; background:#fff; }
  .antet .logo { font-size:22px; font-weight:800; color:#00584E; letter-spacing:-.5px; }
  .antet .meta { text-align:right; font-size:10.5px; color:#555; line-height:1.55; }
  .antet-alt { margin-top:10px; text-align:center; font-size:9.5px; color:#999;
               border-top:1px solid #e3e3e3; padding-top:5px; }
  table.sayfa { width:100%; border-collapse:collapse; }
  table.sayfa > thead > tr > td { padding:12mm 14mm 0; border:0; }
  table.sayfa > tbody > tr > td { padding:0 14mm;      border:0; }
  table.sayfa > tfoot > tr > td { padding:0 14mm 10mm; border:0; }
  .kapanis { break-inside:avoid; page-break-inside:avoid; }
  .doc-title { text-align:center; margin:14px 0 4px; font-size:17px; font-weight:800; letter-spacing:.8px; }
  .doc-no { text-align:center; font-size:12px; color:#00584E; font-weight:700; margin-bottom:12px; }
  .info { width:100%; border-collapse:collapse; margin-bottom:10px; font-size:11.5px; }
  .info td { border:1px solid #cfcfcf; padding:5px 8px; }
  .info td.k { background:#f5f7f7; font-weight:600; width:19%; color:#444; }
  .sec { margin:12px 0 4px; font-size:11px; font-weight:700; color:#00584E; letter-spacing:.6px;
         border-bottom:1px solid #cfcfcf; padding-bottom:3px; break-after:avoid; page-break-after:avoid; }
  table.items { width:100%; border-collapse:collapse; font-size:11.5px; }
  table.items th, table.items td { border:1px solid #bbb; padding:5px 7px; vertical-align:top; }
  table.items th { background:#00584E; color:#fff; font-weight:600; font-size:10.5px; letter-spacing:.3px; }
  table.items tr { break-inside:avoid; page-break-inside:avoid; }
  table.items .alt { font-size:10px; color:#666; }
  table.items td.r, table.items th.r { text-align:right; }
  table.items tfoot td { background:#f5f7f7; font-weight:700; }
  .mono { font-family: Consolas, monospace; font-size:11px; }
  .blok { break-inside:avoid; page-break-inside:avoid; }
  table.kunye { width:100%; border-collapse:collapse; font-size:10.5px; }
  table.kunye td { border:1px solid #d5d5d5; padding:3px 7px; }
  table.kunye td.k { background:#f7f9f9; font-weight:600; width:17%; color:#444; }
  .note { font-size:11px; color:#222; margin:10px 0 0; line-height:1.6; text-align:justify; }
  .note ol { margin:5px 0 0 16px; padding:0; }
  .note li { margin-bottom:2px; }
  .signs { display:flex; justify-content:space-between; gap:10px; margin-top:20px; }
  .sign { flex:1; text-align:center; }
  .sign .line { border-top:1px solid #333; margin-top:34px; padding-top:5px; font-size:11px; font-weight:700; }
  .sign .ad { font-size:10.5px; color:#222; min-height:13px; }
  .sign .sub { font-size:9.5px; color:#888; }
  .toolbar { text-align:center; padding:10px; }
  .toolbar button, .toolbar a { font:inherit; padding:8px 18px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; margin:0 4px; }
  .btn-print { background:#00584E; color:#fff; }
  .btn-back { background:#e0e0e0; color:#333; }
  .evrak { max-width:210mm; margin:0 auto 10px; background:#fff; border:1px solid #d5d5d5; border-left:5px solid #00584E; border-radius:8px; padding:12px 16px; font-size:13px; }
  .evrak .basi { font-weight:700; color:#00584E; margin-bottom:4px; }
  .evrak .rozet { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; }
  .evrak .var { background:#e6f4ea; color:#1b6b3a; } .evrak .yok { background:#fff4e0; color:#8a5a00; }
  .evrak form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px; }
  .evrak input[type=file] { flex:1 1 240px; font:inherit; }
  .evrak button { background:#1b6b3a; color:#fff; border:none; border-radius:8px; padding:8px 16px; font:inherit; cursor:pointer; }
  .evrak .uyari { color:#b00; } .evrak .tamam { color:#1b6b3a; }
<?= $ek ?>
  @page { size:A4; margin:0; }
  @media print {
    body { background:#fff; }
    /* ⚠ min-height SIFIRLANMALI — 297mm'lik kâğıt @page boşluklarıyla taşıp 2. sayfa açıyordu. */
    .sheet { margin:0; box-shadow:none; width:auto; min-height:0; padding:0; }
    /* thead/tfoot her sayfada yeniden basılır; gövde ikisinin arasında akar. */
    table.sayfa > thead { display:table-header-group; }
    table.sayfa > tfoot { display:table-footer-group; }
    .toolbar, .evrak { display:none; }
  }
</style>
    <?php
}

/**
 * Belgeyi açar: her sayfada tekrar eden antet (thead) + alt bilgi (tfoot) + gövde hücresi.
 *   $o['kicker'] antet altındaki küçük satır · $o['meta'] = ['Etiket' => 'değer html', …]
 *   $o['alt']    alt bilgi metni (sayfa sayısı buna eklenir)
 */
function tut_antet(array $o): void
{
    $kicker = (string)($o['kicker'] ?? 'BİLGİ İŞLEM — IT ENVANTER');
    $meta   = (array)($o['meta'] ?? []);
    $alt    = (string)($o['alt'] ?? '');
    ?>
<div class="sheet">
<table class="sayfa"><thead><tr><td>
  <div class="antet">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:44px" onerror="this.outerHTML='<div class=\'logo\'>ERN TAAHHÜT</div>'"><div style="font-size:9.5px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px"><?= htmlspecialchars($kicker) ?></div></div>
    <div class="meta">
      <?php foreach ($meta as $et => $dg): ?><?= htmlspecialchars((string)$et) ?>: <strong><?= $dg ?></strong><br><?php endforeach; ?>
    </div>
  </div>
</td></tr></thead>
<tfoot><tr><td>
  <div class="antet-alt"><?= htmlspecialchars($alt) ?><span id="sayfaBilgi"></span></div>
</td></tr></tfoot>
<tbody><tr><td>
  <div class="govde">
    <?php
}

/** Gövdeyi ve belge tablosunu kapatır. */
function tut_kapat(): void
{
    echo "  </div>\n</td></tr></tbody></table>\n</div>\n";
}

/**
 * Alt bilgiye "N sayfa" yazar — okuyan "devamı var mı" diye şüphelenmesin.
 * Ekrandaki .sheet geometrisi baskıyla aynı olduğundan ölçüm birebir taşınır; görseller geç
 * yüklendiği için load + beforeprint'te yenilenir.
 */
function tut_sayfa_js(): void
{
    ?>
<script>
(function () {
  var govde = document.querySelector('.govde'), et = document.getElementById('sayfaBilgi');
  if (!govde || !et) return;
  function mm() { var d = document.createElement('div'); d.style.cssText = 'height:100mm;position:absolute;visibility:hidden';
    document.body.appendChild(d); var h = d.getBoundingClientRect().height / 100; d.remove(); return h; }
  function yuk(sec) { var e = document.querySelector(sec); return e ? e.getBoundingClientRect().height : 0; }
  function say() {
    var birim = mm(); if (!birim) return;
    // Kullanılabilir gövde yüksekliği = A4 − her sayfada tekrar eden thead/tfoot (kenar boşlukları dahil)
    var sayfaIc = 297 * birim - yuk('table.sayfa > thead') - yuk('table.sayfa > tfoot');
    if (sayfaIc <= 0) return;
    var n = Math.max(1, Math.ceil((govde.getBoundingClientRect().height - 1) / sayfaIc));
    et.textContent = ' · ' + n + ' sayfa';
  }
  window.addEventListener('load', say);
  window.addEventListener('beforeprint', say);
  say();
})();
</script>
    <?php
}
