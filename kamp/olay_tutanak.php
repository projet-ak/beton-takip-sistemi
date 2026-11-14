<?php
/**
 * kamp/olay_tutanak.php — ZİYARETÇİ OLAY TUTANAĞI (A4, ERN Taahhüt logolu) · KMP-O-00001
 *
 *  ?id=<ziyaret id>. Başlık kayda göre değişir (kamp_olay_baslik):
 *    ihlal=1 → İZİNSİZ GİRİŞ OLAY TUTANAĞI · gece izinsiz → GECE KURALI İHLALİ TUTANAĞI ·
 *    olay bilgisi girilmiş → ZİYARETÇİ OLAY TUTANAĞI · diğer → ZİYARETÇİ GİRİŞ-ÇIKIŞ TUTANAĞI
 *  İçerik ziyaret kaydı + olay bilgisinden (kamp_ziyaretler.olay) gelir; BOŞ alanlar noktalı satır
 *  olarak basılır → sahada el ile doldurulup imzalanır. Kimin misafiri olduğu tespit edilemediyse
 *  "TESPİT EDİLEMEDİ" yazar (boşluk, unutulmuş sanılmasın).
 *  Aynı gece kampta bulunan diğer ziyaretçiler ve ziyaret edilen kamp kişisinin o gece kaldığı yatak
 *  da basılır — "o gece kim vardı" sorusunun cevabı belgede dursun.
 *  POST: tutanak_yukle (giriş VEYA değiştirme) → imzalı taramayı kayda bağlar (uploads/kamp/ziyaret/{id}/,
 *        tahmin edilemeyen ad; ziyaretler.php?foto=ID&tur=tutanak ile açılır) · tutanak_sil (değiştirme)
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

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$z  = kamp_ziyaret_bul($pdoKamp, $id);
if (!$z) { http_response_code(404); exit('Ziyaret kaydı bulunamadı.'); }
$yaz = yetki_var('giris') || yetki_var('duzenle');
$duz = yetki_var('duzenle');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['islem'] ?? '') {
            case 'tutanak_yukle':
                if (!$yaz) throw new RuntimeException('İmzalı tutanak yüklemek için yetkiniz yok.');
                if (empty($_FILES['tutanak']) || ($_FILES['tutanak']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)
                    throw new RuntimeException('Yüklenecek dosyayı seçin.');
                $url = kamp_ziyaret_dosya_kaydet($pdoKamp, $id, $_FILES['tutanak'], 'tutanak_url');
                kamp_audit('kamp_ziyaretler', $id, 'UPDATE', ['tutanak_url' => $z['tutanak_url']], ['tutanak_url' => $url]);
                flash('success', 'İmzalı tutanak kayda bağlandı.');
                break;
            case 'tutanak_sil':
                if (!$duz) throw new RuntimeException('İmzalı tutanağı kaldırmak için değiştirme yetkisi gerekir.');
                if ($z['tutanak_url']) {
                    $pdoKamp->prepare("UPDATE kamp_ziyaretler SET tutanak_url=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
                    kamp_ziyaret_dosya_sil($z['tutanak_url']);
                    kamp_audit('kamp_ziyaretler', $id, 'UPDATE', ['tutanak_url' => $z['tutanak_url']], ['tutanak_url' => null]);
                }
                flash('success', 'İmzalı tutanak kaldırıldı.');
                break;
            default: throw new RuntimeException('Bilinmeyen işlem.');
        }
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('olay_tutanak.php?id=' . $id);
}

$i      = kamp_ziyaret_isaret($z);
$z['i'] = $i;
$o      = kamp_olay($z);
$no     = kamp_olay_no($id);
$baslik = kamp_olay_baslik($z);
$gece   = kamp_gece_tarihi($z['giris']);

// Ziyaret edilen kamp kişisi + o gece kaldığı yer
$kisi = $z['kisi_id'] ? kamp_kisi_bul($pdoKamp, (int)$z['kisi_id']) : null;
$yer  = $kisi ? kamp_kisi_tarihteki_yer($pdoKamp, (int)$z['kisi_id'], $gece) : null;

// Aynı gece kampta bulunan diğer ziyaretçiler (gece penceresine değenler; yoksa aynı gün gelenler)
$diger = array_values(array_filter(kamp_ziyaret_liste($pdoKamp, $i['gece'] ? ['gece' => $gece] : ['bas' => substr($z['giris'], 0, 10), 'bit' => substr($z['giris'], 0, 10)]),
                                   fn($r) => (int)$r['id'] !== $id));

$sure = function (array $z): string {
    $dk = (int)round(((($z['cikis'] ? strtotime($z['cikis']) : time())) - strtotime($z['giris'])) / 60);
    if ($dk < 60) return max(0, $dk) . ' dk';
    if ($dk < 1440) return intdiv($dk, 60) . ' sa ' . ($dk % 60) . ' dk';
    return intdiv($dk, 1440) . ' gün ' . intdiv($dk % 1440, 60) . ' sa';
};
$bos = fn(string $s, int $n = 28) => $s !== '' ? nl2br(h($s)) : '<span class="nok">' . str_repeat('.', $n) . '</span>';
$satirlar = function (string $s, int $adet) {
    if ($s !== '') return '<div class="metin">' . nl2br(h($s)) . '</div>';
    return str_repeat('<div class="cizgi"></div>', $adet);
};
$fotoGorsel = $z['foto_url'] && preg_match('/\.(jpe?g|png|webp)$/i', $z['foto_url']);
$msg = ['success' => get_flash('success'), 'error' => get_flash('error')];
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($no) ?> — <?= h(mb_convert_case($baslik, MB_CASE_TITLE, 'UTF-8')) ?></title>
<style>
@page { size:A4; margin:0 }
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111;margin:0;background:#e9ecef}
.toolbar{position:sticky;top:0;background:#00584E;color:#fff;padding:8px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;z-index:5}
.toolbar button,.toolbar a{background:#fff;color:#00584E;border:0;border-radius:4px;padding:5px 12px;font-weight:600;cursor:pointer;text-decoration:none;font-size:12px}
.evrak{width:210mm;margin:10px auto 0;background:#fff;border:1px solid #cfd4da;border-radius:6px;padding:8px 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px}
.evrak .ok{color:#146c43;font-weight:700} .evrak .yok{color:#997404;font-weight:700}
.evrak input[type=file]{font-size:12px} .evrak button{background:#00584E;color:#fff;border:0;border-radius:4px;padding:4px 10px;font-weight:600;cursor:pointer}
.evrak button.sil{background:#fff;color:#b02a37;border:1px solid #b02a37}
.mesaj{width:210mm;margin:8px auto 0;padding:6px 10px;border-radius:5px;font-size:12px}
.mesaj.ok{background:#d1e7dd;color:#0a3622} .mesaj.hata{background:#f8d7da;color:#58151c}
.sheet{width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:10mm 13mm 7mm;box-shadow:0 2px 10px rgba(0,0,0,.15)}
.antet{display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid #00584E;padding-bottom:6px;margin-bottom:6px}
.antet .meta{text-align:right;font-size:10px;line-height:1.5}
h1{font-size:15.5px;text-align:center;margin:5px 0 3px;letter-spacing:.5px}
h1.kirmizi{color:#a4161a}
.alt-baslik{text-align:center;font-size:10px;color:#555;margin-bottom:6px}
.sec{background:#00584E;color:#fff;font-weight:700;font-size:10px;padding:2px 7px;margin:6px 0 3px;letter-spacing:.5px;break-after:avoid}
.sec.kirmizi{background:#a4161a}
table{width:100%;border-collapse:collapse}
td,th{border:1px solid #888;padding:3px 6px;vertical-align:top}
th{background:#f1f3f5;text-align:left;font-size:10px}
td.k{width:19%;background:#f8f9fa;font-weight:600}
.nok{color:#999;letter-spacing:1px}
.tespit{color:#a4161a;font-weight:700}
.kutu{display:inline-block;width:10px;height:10px;border:1px solid #333;margin-right:4px;vertical-align:-1px;text-align:center;line-height:9px;font-size:9px;font-weight:700}
.islem{columns:2;column-gap:14px;padding:3px 6px;border:1px solid #888} .islem div{break-inside:avoid;margin:1px 0}
.metin{border:1px solid #888;padding:4px 6px;min-height:10mm;white-space:normal}
.cizgi{border-bottom:1px dotted #777;height:5.2mm}
.kimlik{display:flex;gap:8px} .kimlik table{flex:1} .kimlik img{width:34mm;max-height:42mm;object-fit:contain;border:1px solid #888;background:#fafafa}
.blok{break-inside:avoid}
.imza{display:grid;grid-template-columns:repeat(<?= $z['kisi_id'] ? 5 : 4 ?>,1fr);gap:8px;margin-top:8px;break-inside:avoid}
.imza > div{border:1px solid #888;padding:5px;min-height:24mm;text-align:center;font-size:10px}
.imza b{display:block;margin-bottom:3px;font-size:9.5px} .imza .ad{margin-top:10mm;border-top:1px dotted #555;padding-top:3px;font-size:9.5px}
.kapanis{margin-top:6px;text-align:justify;break-inside:avoid}
.not{font-size:8.5px;color:#555;margin-top:4px}
@media print{body{background:#fff}.toolbar,.evrak,.mesaj{display:none}.sheet{margin:0;box-shadow:none;min-height:0}tr,.imza,.blok,.kapanis{break-inside:avoid}}
</style></head><body>
<div class="toolbar"><strong><?= h($no) ?></strong><span style="opacity:.85"><?= h($z['ad_soyad']) ?> · <?= h(kamp_zaman_yaz($z['giris'])) ?></span>
  <button onclick="window.print()" style="margin-left:auto">Yazdır / PDF</button>
  <?php if ($duz): ?><a href="ziyaret_form.php?id=<?= $id ?>">Olay bilgisini düzenle</a><?php endif; ?>
  <a href="ziyaretler.php?durum=hepsi">Ziyaretçi defteri</a></div>
<?php if ($msg['success']): ?><div class="mesaj ok"><?= h($msg['success']) ?></div><?php endif; ?>
<?php if ($msg['error']): ?><div class="mesaj hata"><?= h($msg['error']) ?></div><?php endif; ?>
<div class="evrak">
  <strong>İmzalı tutanak:</strong>
  <?php if ($z['tutanak_url']): ?>
    <span class="ok">✓ yüklendi</span> <a href="ziyaretler.php?foto=<?= $id ?>&amp;tur=tutanak" target="_blank">aç</a>
  <?php else: ?><span class="yok">⚠ yüklenmedi</span> <span style="color:#666">— yazdır, imzalat, tara ve buradan yükle.</span><?php endif; ?>
  <?php if ($yaz): ?>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;margin-left:auto">
    <input type="hidden" name="islem" value="tutanak_yukle"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="file" name="tutanak" accept="image/*,application/pdf" required>
    <button><?= $z['tutanak_url'] ? 'Değiştir' : 'Yükle' ?></button></form>
  <?php endif; ?>
  <?php if ($duz && $z['tutanak_url']): ?>
  <form method="post" onsubmit="return confirm('İmzalı tutanak kayıttan kaldırılsın mı?')">
    <input type="hidden" name="islem" value="tutanak_sil"><input type="hidden" name="id" value="<?= $id ?>"><button class="sil">Kaldır</button></form>
  <?php endif; ?>
</div>

<div class="sheet">
  <div class="antet">
    <div><img src="../uploads/logo/ERN%20Taahhut_Logo_Renkli.png" alt="ERN Taahhüt" style="height:42px" onerror="this.outerHTML='<b style=\'font-size:20px;color:#00584E\'>ERN TAAHHÜT</b>'">
      <div style="font-size:9.5px;font-weight:600;color:#555;letter-spacing:2px;margin-top:3px">ŞANTİYE KAMP YÖNETİMİ</div></div>
    <div class="meta"><b>Tutanak No:</b> <?= h($no) ?><br><b>Olay tarihi:</b> <?= h(kamp_zaman_yaz($o['tespit_zaman'] ?: $z['giris'])) ?><br><b>Düzenleme:</b> <?= date('d.m.Y H:i') ?></div>
  </div>
  <h1 class="<?= $i['ihlal'] || $i['izinsiz'] ? 'kirmizi' : '' ?>"><?= h($baslik) ?></h1>
  <div class="alt-baslik">Kamp gece kuralı: <?= h(kamp_gece_etiket()) ?> arası kampta bulunma ancak yazılı gece izni ve onaylayan ile mümkündür.</div>

  <div class="blok"><div class="sec<?= $i['ihlal'] || $i['izinsiz'] ? ' kirmizi' : '' ?>">OLAY BİLGİSİ</div>
  <table>
    <tr><td class="k">Kampa giriş</td><td><?= h(kamp_zaman_yaz($z['giris'])) ?><?= $i['gece_giris'] ? ' <b>(gece saatinde)</b>' : '' ?></td>
        <td class="k">Kamptan çıkış</td><td><?= $z['cikis'] ? h(kamp_zaman_yaz($z['cikis'])) . ' <small>(' . $sure($z) . ')</small>' : '<span class="nok">.... / .... / ........ &nbsp; ....:....</span>' ?></td></tr>
    <tr><td class="k">Bina / bölüm</td><td><?= $bos((string)($z['bina_ad'] ?? '')) ?></td>
        <td class="k">Gece izni</td><td><?= $z['gece_izin'] ? 'VAR — onaylayan: ' . h($z['onaylayan'] ?: '—') : ($i['gece'] ? '<span class="tespit">YOK</span>' : 'Gerekmiyor (gündüz)') ?></td></tr>
    <tr><td class="k">Tespit zamanı</td><td><?= $o['tespit_zaman'] ? h(kamp_zaman_yaz($o['tespit_zaman'])) : '<span class="nok">.... / .... / ........ &nbsp; ....:....</span>' ?></td>
        <td class="k">Tespit eden</td><td><?= $bos($o['tespit_eden']) ?></td></tr>
    <tr><td class="k">Tespit yeri</td><td><?= $bos($o['tespit_yeri']) ?></td>
        <td class="k">Nasıl fark edildi</td><td><?= $bos($o['nasil']) ?></td></tr>
    <tr><td class="k">Kayıt durumu</td><td colspan="3"><?= $i['ihlal'] ? '<span class="tespit">İZİNSİZ GİRİŞ</span> — kişi kampa izin alınmadan girmiş, sonradan tespit edilmiştir.'
          : ($i['izinsiz'] ? '<span class="tespit">GECE İZNİ YOK</span> — kişi gece saatlerinde izinsiz olarak kampta bulunmuştur.'
          : 'Ziyaretçi defterine kayıtlı giriş' . ($z['onaylayan'] ? ' — onaylayan: ' . h($z['onaylayan']) : '')) ?></td></tr>
  </table></div>

  <div class="blok"><div class="sec">KAMPA GİREN KİŞİ</div>
  <div class="kimlik"><table>
    <tr><td class="k">Adı Soyadı</td><td><b><?= h($z['ad_soyad']) ?></b></td><td class="k">Uyruğu</td><td><?= $bos((string)$z['uyruk'], 18) ?></td></tr>
    <tr><td class="k">Belge türü / no</td><td><?= $z['kimlik_no'] ? h((KAMP_KIMLIK_TUR[$z['kimlik_tur']] ?? '') . ' — ' . $z['kimlik_no']) : '<span class="nok">' . str_repeat('.', 28) . '</span>' ?></td>
        <td class="k">Telefon</td><td><?= $bos((string)$z['telefon'], 18) ?></td></tr>
    <tr><td class="k">Geldiği firma / kurum</td><td><?= $bos((string)$z['kurum']) ?></td><td class="k">Araç plakası</td><td><?= $bos((string)$z['plaka'], 14) ?></td></tr>
    <tr><td class="k">Ziyaret amacı</td><td colspan="3"><?= $bos((string)$z['amac'], 50) ?></td></tr>
  </table>
  <?php if ($fotoGorsel): ?><img src="ziyaretler.php?foto=<?= $id ?>" alt="kimlik / kişi görüntüsü" onerror="this.remove()"><?php endif; ?></div></div>

  <div class="blok"><div class="sec">KİMİN MİSAFİRİ / İÇERİ ALAN</div>
  <table>
    <tr><td class="k">Ziyaret edilen</td><td><?= $z['ziyaret_edilen'] ? '<b>' . h($z['ziyaret_edilen']) . '</b>' : '<span class="tespit">TESPİT EDİLEMEDİ</span>' ?></td>
        <td class="k">Firması / görevi</td><td><?= $kisi ? (h(trim(($yer['firma_ad'] ?? $kisi['firma_ad'] ?? '') . ($kisi['gorev'] ? ' · ' . $kisi['gorev'] : ''), ' ·')) ?: '—') : '<span class="nok">' . str_repeat('.', 20) . '</span>' ?></td></tr>
    <tr><td class="k">O gece kaldığı yer</td><td><?= $yer ? h(trim(($yer['bina_ad'] ?? '') . ($yer['kat'] !== null ? ' · ' . kamp_kat_ad((int)$yer['kat']) : '') . ($yer['oda_ad'] ? ' · oda ' . $yer['oda_ad'] : '') . ($yer['yatak_no'] ? ' · yatak ' . $yer['yatak_no'] : ''), ' ·'))
          : ($kisi ? 'Kampta kalmıyordu' : '<span class="nok">' . str_repeat('.', 28) . '</span>') ?></td>
        <td class="k">Telefonu</td><td><?= $kisi ? h($kisi['telefon'] ?: '—') : '<span class="nok">' . str_repeat('.', 18) . '</span>' ?></td></tr>
    <tr><td class="k">İçeri alan / getiren</td><td colspan="3"><?= $o['iceri_alan'] !== '' ? '<b>' . h($o['iceri_alan']) . '</b>' : '<span class="tespit">TESPİT EDİLEMEDİ</span> <span class="nok">' . str_repeat('.', 40) . '</span>' ?></td></tr>
  </table></div>

  <div class="blok"><div class="sec">OLAYIN ANLATIMI</div><?= $satirlar((string)$z['aciklama'], 3) ?></div>

  <div class="blok"><div class="sec">YAPILAN İŞLEMLER</div>
  <div class="islem"><?php foreach (KAMP_OLAY_ISLEM as $k => $ad): $var = in_array($k, $o['islem'], true); ?>
    <div><span class="kutu"><?= $var ? 'X' : '' ?></span><?= h($ad) ?></div><?php endforeach; ?></div>
  <table style="margin-top:3px"><tr><td class="k">Kolluk birimi</td><td><?= $bos($o['kolluk_birim']) ?></td><td class="k">Kolluk tutanak / olay no</td><td><?= $bos($o['kolluk_no'], 18) ?></td></tr></table></div>

  <?php if ($diger): ?>
  <div class="blok"><div class="sec"><?= $i['gece'] ? h(kamp_tarih_yaz($gece)) . ' GECESİ KAMPTA BULUNAN DİĞER ZİYARETÇİLER' : 'AYNI GÜN KAMPA GELEN DİĞER ZİYARETÇİLER' ?> (<?= count($diger) ?>)</div>
  <table><thead><tr><th>Ziyaretçi</th><th style="width:20%">Giriş</th><th style="width:20%">Çıkış</th><th>Kimin misafiri</th><th style="width:16%">Durum</th></tr></thead><tbody>
  <?php foreach (array_slice($diger, 0, 8) as $d): ?>
    <tr><td><?= h($d['ad_soyad']) ?></td><td><?= h(kamp_zaman_yaz($d['giris'])) ?></td><td><?= $d['cikis'] ? h(kamp_zaman_yaz($d['cikis'])) : 'içeride' ?></td>
      <td><?= h($d['ziyaret_edilen'] ?: '—') ?></td><td><?= h(kamp_ziyaret_uyari_metin($d['i']) ?: ($d['gece_izin'] ? 'gece izinli' : 'kayıtlı')) ?></td></tr>
  <?php endforeach; ?>
  <?php if (count($diger) > 8): ?><tr><td colspan="5" style="color:#555">… ve <?= count($diger) - 8 ?> ziyaretçi daha (ziyaretçi defterinde).</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php endif; ?>

  <div class="blok"><div class="sec">TANIKLAR</div><?= $satirlar($o['taniklar'], 2) ?></div>
  <div class="blok"><div class="sec">KAMPA GİREN KİŞİNİN BEYANI</div><?= $satirlar($o['beyan'], 3) ?></div>
  <div class="blok"><div class="sec">SONUÇ / ALINAN KARAR</div><?= $satirlar($o['sonuc'], 2) ?></div>

  <div class="kapanis">İşbu tutanak <?= h(kamp_zaman_yaz($o['tespit_zaman'] ?: $z['giris'])) ?> tarihinde meydana gelen olay hakkında, olayı tespit eden ve hazır bulunanlar tarafından
    düzenlenmiş; okunarak imza altına alınmıştır. İmzadan kaçınan kişi olursa durum bu tutanağa ayrıca yazılır.
  <div class="imza">
    <div><b>TESPİT EDEN</b><?= h($o['tespit_eden']) ?><div class="ad">Ad Soyad / İmza</div></div>
    <div><b>KAMP SORUMLUSU</b>&nbsp;<div class="ad">Ad Soyad / İmza</div></div>
    <div><b>TANIK</b>&nbsp;<div class="ad">Ad Soyad / İmza</div></div>
    <?php if ($z['kisi_id']): ?><div><b>ZİYARET EDİLEN KAMP KİŞİSİ</b><?= h($z['ziyaret_edilen']) ?><div class="ad">İmza</div></div><?php endif; ?>
    <div><b>KAMPA GİREN KİŞİ</b><?= h($z['ad_soyad']) ?><div class="ad">İmza</div></div>
  </div></div>
  <div class="not">Kişisel veriler (kimlik, telefon, görüntü) yalnız bu olayın incelenmesi ve yasal yükümlülükler için işlenir, yetkili kişiler dışında paylaşılmaz.
    Tutanak iki nüsha düzenlenir: bir nüsha kamp yönetiminde, bir nüsha şantiye / proje müdürlüğünde saklanır<?= $o['kolluk_no'] ? '; kolluk tutanağının sureti eklenir' : '' ?>.</div>
</div>
</body></html>
