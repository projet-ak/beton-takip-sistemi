<?php
/**
 * kamp/konaklama.php — konaklama işlemleri (yalnız POST): yerleştir · taşı · çıkış · düzelt · sil
 *
 * Yerleşim krokisi, kişi kartı ve oda sayfası aynı uca gönderir; işlem bitince `geri`
 * adresine döner (yalnız bu modülün sayfaları — kamp_geri()).
 * Yetki: yerleştir/taşı/çıkış → giriş VEYA değiştirme · tarih düzeltme / silme → değiştirme.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo','kamp_sorumlusu']);
require_once __DIR__ . '/../includes/db_kamp.php';
require_once __DIR__ . '/_ortak.php';
kamp_semasi_kur($pdoKamp);

$geri = kamp_geri($_POST['geri'] ?? $_GET['geri'] ?? '', 'yerlesim.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($geri);

$islem = (string)($_POST['islem'] ?? '');
$uid   = current_user_id();
$yaz   = yetki_var('giris') || yetki_var('duzenle');
try {
    switch ($islem) {
        case 'yerlestir':
        case 'tasi':
            if (!$yaz) throw new RuntimeException('Yerleştirme için yetkiniz yok.');
            $yatakId = (int)($_POST['yatak_id'] ?? 0);
            $kisiId  = (int)($_POST['kisi_id'] ?? 0);
            if (!$kisiId && $islem === 'yerlestir' && trim((string)($_POST['yeni_ad'] ?? '')) !== '') {
                // Pencereden yeni kişi — firma listede yoksa açılır
                $firmaId = (int)($_POST['yeni_firma_id'] ?? 0);
                if (!$firmaId && trim((string)($_POST['yeni_firma'] ?? '')) !== '')
                    $firmaId = (int)kamp_firma_bul($pdoKamp, (string)$_POST['yeni_firma'], true);
                $r = kamp_kisi_ekle($pdoKamp, [
                    'ad' => $_POST['yeni_ad'], 'soyad' => $_POST['yeni_soyad'] ?? '', 'tc_no' => $_POST['yeni_tc'] ?? '',
                    'firma_id' => $firmaId, 'gorev' => $_POST['yeni_gorev'] ?? '', 'kategori' => $_POST['yeni_kategori'] ?? 'isci',
                    'telefon' => $_POST['yeni_telefon'] ?? '',
                ]);
                $kisiId = $r['id'];
                if ($r['yeni']) kamp_audit('kamp_kisiler', $kisiId, 'INSERT', null, ['ad' => $_POST['yeni_ad'], 'soyad' => $_POST['yeni_soyad'] ?? '']);
                elseif (!empty($r['neden'])) flash('info', 'Bu ' . $r['neden'] . ' ile kayıtlı kişi zaten vardı — yeni kart açılmadı, mevcut kayıt kullanıldı.');
            }
            if (!$kisiId)  throw new RuntimeException('Kişi seçin ya da yeni kişi bilgisi girin.');
            if (!$yatakId) throw new RuntimeException('Yatak seçin.');
            $r = kamp_yerlestir($pdoKamp, $kisiId, $yatakId, (string)($_POST['tarih'] ?? date('Y-m-d')), (string)($_POST['aciklama'] ?? ''), $uid);
            kamp_audit('kamp_konaklama', $r['id'], 'INSERT', null,
                      ['kisi_id' => $kisiId, 'yatak_id' => $yatakId, 'giris' => $_POST['tarih'] ?? '', 'tasima' => $r['tasindi']]);
            $k = kamp_kisi_bul($pdoKamp, $kisiId);
            flash('success', kamp_kisi_ad($k) . ($r['tasindi'] ? ' taşındı → ' : ' yerleştirildi → ') . $r['yer']);
            break;

        case 'cikis':
            if (!$yaz) throw new RuntimeException('Çıkış işlemi için yetkiniz yok.');
            $id = (int)($_POST['konaklama_id'] ?? 0);
            $sebep = trim((string)($_POST['sebep'] ?? ''));
            $k = kamp_cikis($pdoKamp, $id, (string)($_POST['tarih'] ?? date('Y-m-d')), $sebep, (string)($_POST['aciklama'] ?? ''));
            kamp_audit('kamp_konaklama', $id, 'UPDATE', ['cikis' => null], ['cikis' => $_POST['tarih'] ?? '', 'sebep' => $sebep]);
            $p = kamp_kisi_bul($pdoKamp, (int)$k['kisi_id']);
            flash('success', kamp_kisi_ad($p ?? []) . ' için çıkış kaydedildi (' . kamp_tarih_yaz(kamp_tarih($_POST['tarih'] ?? '')) . ').');
            break;

        case 'duzelt':
            if (!yetki_var('duzenle')) throw new RuntimeException('Tarih düzeltmek için değiştirme yetkisi gerekir.');
            $id = (int)($_POST['konaklama_id'] ?? 0);
            $st = $pdoKamp->prepare("SELECT giris, cikis FROM kamp_konaklama WHERE id=?"); $st->execute([$id]);
            $eski = $st->fetch() ?: null;
            kamp_konaklama_duzelt($pdoKamp, $id, (string)($_POST['giris'] ?? ''), $_POST['cikis'] ?? null);
            if (isset($_POST['cikis_sebep'])) $pdoKamp->prepare("UPDATE kamp_konaklama SET cikis_sebep=? WHERE id=?")
                ->execute([trim((string)$_POST['cikis_sebep']) ?: null, $id]);
            kamp_audit('kamp_konaklama', $id, 'UPDATE', $eski, ['giris' => $_POST['giris'] ?? '', 'cikis' => $_POST['cikis'] ?? '']);
            flash('success', 'Konaklama tarihleri düzeltildi.');
            break;

        case 'sil':
            if (!yetki_var('duzenle')) throw new RuntimeException('Kayıt silmek için değiştirme yetkisi gerekir.');
            $id = (int)($_POST['konaklama_id'] ?? 0);
            $st = $pdoKamp->prepare("SELECT * FROM kamp_konaklama WHERE id=?"); $st->execute([$id]);
            $eski = $st->fetch() ?: throw new RuntimeException('Kayıt bulunamadı.');
            $pdoKamp->prepare("DELETE FROM kamp_konaklama WHERE id=?")->execute([$id]);
            kamp_audit('kamp_konaklama', $id, 'DELETE', $eski, null);
            flash('success', 'Konaklama kaydı silindi (yanlış giriş).');
            break;

        default:
            throw new RuntimeException('Bilinmeyen işlem.');
    }
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}
redirect($geri);
