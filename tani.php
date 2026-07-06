<?php
/**
 * tani.php — Dashboard/DB tanılama (tek seferlik teşhis)
 * Yeni bir URL olduğu için önbelleğe takılmaz. Dashboard'un neden 5.247,9/589
 * gösterdiğini, Veri Kontrol'ün 5.253/590 gösterdiğini yan yana kanıtlar.
 * Admin girişi gerekir. Sorun çözülünce silinebilir.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

// Önbelleğe kesinlikle takılmasın
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-LiteSpeed-Purge: *');
header('Content-Type: text/html; charset=utf-8');

function q1($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

$dbName   = q1($pdo, "SELECT DATABASE()");
$dbHost   = defined('DB_HOST') ? DB_HOST : '(DB_HOST tanımsız)';

// Dashboard'un birebir kullandığı sorgular (projeFilter YOK — Tüm Projeler)
$d_sum   = q1($pdo, "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler i WHERE i.tip='alis'");
$d_count = q1($pdo, "SELECT COUNT(*) FROM irsaliyeler i WHERE i.tip='alis'");
$d_iade  = q1($pdo, "SELECT COUNT(*) FROM irsaliyeler i WHERE i.tip='iade'");

// Genel sayımlar
$topKayit = q1($pdo, "SELECT COUNT(*) FROM irsaliyeler");
$topSum   = q1($pdo, "SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler");

// tip × durum kırılımı
$kir = $pdo->query("SELECT tip, durum, COUNT(*) c, COALESCE(SUM(miktar),0) m FROM irsaliyeler GROUP BY tip, durum ORDER BY tip,durum")->fetchAll(PDO::FETCH_ASSOC);

// Diskteki index.php gerçekten güncel mi? toplamM3 sorgu satırını çıkar
$idxPath = __DIR__ . '/index.php';
$idxSrc  = is_readable($idxPath) ? file_get_contents($idxPath) : '';
$idxMtime = is_file($idxPath) ? date('Y-m-d H:i:s', filemtime($idxPath)) : '?';
$idxMd5   = $idxSrc ? md5($idxSrc) : '?';
// toplamM3'ü hesaplayan satırı bul
$idxToplamLine = '';
if (preg_match('/\$toplamM3\s*=\s*\$st->fetchColumn\(\);/', $idxSrc)) {
    if (preg_match('/(SELECT COALESCE\(SUM\(miktar\),0\) FROM irsaliyeler i WHERE i\.tip=\'alis\'[^"]*)"/', $idxSrc, $mm)) {
        $idxToplamLine = $mm[1];
    }
}
$idxHasB0705 = strpos($idxSrc, 'B0705') !== false;

// miktarı 5.1 civarı olan / ondalıklı kayıtlar (fark 5,1 idi)
$suphe = $pdo->query("SELECT id, irsaliye_no, tip, durum, miktar, tarih FROM irsaliyeler WHERE miktar <> ROUND(miktar) OR miktar < 6 ORDER BY miktar LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

$token = substr(md5(uniqid('', true)), 0, 8);
?><!doctype html><meta charset="utf-8">
<title>Tanılama</title>
<style>
body{font-family:system-ui,Arial;margin:24px;color:#222;line-height:1.5}
h2{margin-top:28px}
table{border-collapse:collapse;margin:8px 0}
td,th{border:1px solid #ccc;padding:4px 10px;text-align:left}
.big{font-size:1.4rem;font-weight:700}
.ok{color:#0a7a3f}.bad{color:#c00;font-weight:700}
code{background:#f4f4f4;padding:1px 5px;border-radius:4px}
.box{background:#f7f7f9;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin:10px 0}
</style>

<h1>Dashboard / Veritabanı Tanılama</h1>
<p>Bu sayfa <b>her açılışta canlı</b> çalışır (önbelleksiz). Üstteki jeton her yenilemede değişmeli:
<b>Jeton: <?= $token ?></b> · Sunucu saati: <?= date('Y-m-d H:i:s') ?></p>

<div class="box">
    <div>Bağlı veritabanı: <b><?= h($dbName) ?></b> @ <b><?= h($dbHost) ?></b></div>
</div>

<h2>1) Dashboard sorguları (canlı, bu DB üzerinde)</h2>
<table>
<tr><th>Değer</th><th>Sonuç</th><th>Dashboard'da beklenen</th></tr>
<tr><td>SUM(miktar) WHERE tip='alis' → <b>Toplam m³</b></td><td class="big"><?= number_format((float)$d_sum,1,',','.') ?></td><td>Ekranda 5.247,9 yazıyor</td></tr>
<tr><td>COUNT(*) WHERE tip='alis' → <b>Alış İrsaliyesi</b></td><td class="big"><?= (int)$d_count ?></td><td>Ekranda 589 yazıyor</td></tr>
<tr><td>COUNT(*) WHERE tip='iade' → <b>İade</b></td><td class="big"><?= (int)$d_iade ?></td><td>Ekranda 0 yazıyor</td></tr>
<tr><td>COUNT(*) tüm kayıt</td><td class="big"><?= (int)$topKayit ?></td><td>—</td></tr>
<tr><td>SUM(miktar) tüm kayıt</td><td class="big"><?= number_format((float)$topSum,1,',','.') ?></td><td>—</td></tr>
</table>
<p><b>Yorum:</b>
<?php if ((int)$d_count == 590 && abs((float)$d_sum - 5253) < 0.5): ?>
<span class="ok">Bu DB, dashboard sorgusuyla 590 / 5.253 veriyor. Ekranda hâlâ 589/5.247,9 görüyorsanız,
o ekran DİSKTEKİ ESKİ index.php'den ya da bir önbellek katmanından geliyor demektir (aşağıdaki bölüm 3'e bakın).</span>
<?php else: ?>
<span class="bad">Bu DB, dashboard sorgusuyla <?= (int)$d_count ?> / <?= number_format((float)$d_sum,1,',','.') ?> veriyor.
Yani ekrandaki 589/5.247,9 GERÇEKTEN bu veritabanından geliyor — sorun önbellek değil, VERİDE.
Aşağıdaki kırılım ve şüpheli kayıtlara bakın.</span>
<?php endif ?>
</p>

<h2>2) tip × durum kırılımı (WHERE yok — tüm satırlar)</h2>
<table>
<tr><th>tip</th><th>durum</th><th>kayıt</th><th>m³</th></tr>
<?php foreach ($kir as $r): ?>
<tr><td><?= h($r['tip']) ?></td><td><?= h($r['durum']) ?></td><td><?= (int)$r['c'] ?></td><td><?= number_format((float)$r['m'],2,',','.') ?></td></tr>
<?php endforeach ?>
</table>

<h2>3) Diskteki index.php güncel mi?</h2>
<table>
<tr><td>index.php değişim tarihi (mtime)</td><td><b><?= h($idxMtime) ?></b></td></tr>
<tr><td>index.php MD5</td><td><code><?= h($idxMd5) ?></code></td></tr>
<tr><td>B0705 rozeti dosyada var mı?</td><td><?= $idxHasB0705 ? '<span class="ok">EVET</span>' : '<span class="bad">HAYIR — eski dosya!</span>' ?></td></tr>
<tr><td>toplamM3 sorgu satırı (dosyadan)</td><td><code><?= h($idxToplamLine ?: '(bulunamadı)') ?></code></td></tr>
</table>
<p class="box">Repo'daki güncel index.php'nin MD5'i ile bu değeri karşılaştıracağım — farklıysa deploy bu dosyayı güncellememiş demektir.</p>

<h2>4) Şüpheli kayıtlar (ondalıklı veya &lt;6 m³)</h2>
<table>
<tr><th>id</th><th>irsaliye_no</th><th>tip</th><th>durum</th><th>miktar</th><th>tarih</th></tr>
<?php foreach ($suphe as $r): ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= h($r['irsaliye_no']) ?></td><td><?= h($r['tip']) ?></td><td><?= h($r['durum']) ?></td><td><b><?= h($r['miktar']) ?></b></td><td><?= h($r['tarih']) ?></td></tr>
<?php endforeach ?>
</table>
</html>
