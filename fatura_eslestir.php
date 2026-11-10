<?php
/**
 * fatura_eslestir.php — Fatura ↔ İrsaliye Eşleştirme (Mutabakat)
 *
 * Tedarikçi e-faturasındaki irsaliye listesini çıkarır, numaraları normalize edip
 * sistemdeki irsaliyelerle eşleştirir ve mutabakat raporu üretir.
 * Onaylandığında eşleşen irsaliyeler faturaya bağlanır (irsaliyeler.fatura_id).
 *
 * Okuma yolu: PDF/görsel yükle → pdftotext (varsa) → AI belge okuma → yoksa metni yapıştır.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fatura.php';
require_once __DIR__ . '/includes/belge.php';

$pageTitle = 'Fatura Eşleştirme — Beton Takip Sistemi';
try { fat_semasi_kur($pdo); } catch (Throwable $e) { flash('error', 'Şema hatası: '.$e->getMessage()); }

$sonuc = null;      // ['veri'=>..., 'eslesme'=>..., 'kaynak'=>..., 'dosya_url'=>...]
$hata  = null;

// ── 1) Çözümleme: dosya yükle veya metin yapıştır ────────────────────────────
if (($_POST['action'] ?? '') === 'coz') {
    $metin    = trim((string)($_POST['metin'] ?? ''));
    $kaynak   = 'yapistirma';
    $dosyaUrl = null;
    // Karekot: tarayıcıda okunan (gizli alan) ya da elle yapıştırılan içerik
    $qr       = fat_qr_coz((string)($_POST['qr'] ?? ''));

    if (!empty($_FILES['dosya']['tmp_name']) && is_uploaded_file($_FILES['dosya']['tmp_name'])) {
        $tmp  = $_FILES['dosya']['tmp_name'];
        $ad   = (string)$_FILES['dosya']['name'];
        $mime = guess_mime($tmp, $ad);
        $izin = ['application/pdf','image/jpeg','image/png','image/webp'];
        if (!in_array($mime, $izin, true)) {
            $hata = 'Desteklenmeyen dosya türü: '.$mime.' (PDF, JPG, PNG, WEBP)';
        } elseif ((int)$_FILES['dosya']['size'] > 20*1024*1024) {
            $hata = 'Dosya çok büyük (en fazla 20 MB).';
        } else {
            // Dosyayı sakla (fatura arşivi)
            $dir = __DIR__ . '/uploads/faturalar/' . date('Y/m');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $ext  = guvenli_uzanti($ad, $mime, 'jpg');
            $hedefAd = date('Ymd_His') . '_' . substr(md5($ad . microtime(true)), 0, 8) . '.' . $ext;
            if (@move_uploaded_file($tmp, $dir . '/' . $hedefAd)) {
                $dosyaUrl = 'uploads/faturalar/' . date('Y/m') . '/' . $hedefAd;
                $tam = $dir . '/' . $hedefAd;
            } else { $tam = $tmp; }

            $k = null;
            $okunan = fat_dosyadan_metin($tam, $mime, $k);
            if ($okunan !== null && $okunan !== '') { $metin = $okunan; $kaynak = $k ?: 'dosya'; }
            elseif ($metin === '' && !$qr) {
                $hata = 'Dosyadan metin okunamadı (sunucuda pdftotext yok ve AI okuma başarısız). '
                      . 'Faturayı PDF görüntüleyicide açıp metni kopyalayıp aşağıdaki kutuya yapıştırın.';
            }
        }
    }

    if (!$hata) {
        if ($metin === '' && !$qr) {
            $hata = 'Fatura dosyası yükleyin, fatura metnini yapıştırın veya karekot içeriğini girin.';
        } else {
            $veri = fat_metinden_cikar($metin);
            // Metinde karekot yoksa ayrı gelen karekot verisini uygula (karekot esastır)
            if ($qr && empty($veri['qr'])) $veri = fat_qr_birlestir($veri, $qr);
            $eslesme = fat_eslestir($pdo, $veri['irsaliyeler']);

            // Mükerrer kontrolü: fatura zaten kayıtlı mı, irsaliyeler başka faturaya bağlı mı
            $mevcut  = fat_mevcut($pdo, $veri['fatura_no'], $veri['ettn']);
            $baskasi = fat_baskaya_bagli($pdo, array_column($eslesme['eslesen'], 'id'), (int)($mevcut['id'] ?? 0));
            // Zaten kayıtlıysa hangi irsaliyelere bağlı olduğunu ekranda göster
            $mevcutIrs = $mevcut ? (fat_bagli_irsaliyeler($pdo, [(int)$mevcut['id']])[(int)$mevcut['id']] ?? []) : [];

            // Fatura TÜRÜ önerisi: irsaliye numarası yoksa metindeki anahtar kelimelerden
            // (POMPA / KİRA / NAKLİYE …) tür sezilir. Zaten kayıtlı faturanın türü esastır.
            $turOner = fat_tur_sez($metin, $veri);
            if ($mevcut && !empty($mevcut['tur'])) $turOner = ['tur' => fat_tur_norm($mevcut['tur']), 'gerekce' => ''];

            $sonuc = ['veri'=>$veri, 'eslesme'=>$eslesme, 'kaynak'=>$kaynak, 'dosya_url'=>$dosyaUrl,
                      'metin'=>$metin, 'mevcut'=>$mevcut, 'baskasi'=>$baskasi, 'mevcut_irs'=>$mevcutIrs,
                      'tur_oner'=>$turOner];
        }
    }
}

// ── 2) Kaydet: eşleşen irsaliyeleri faturaya bağla ──────────────────────────
if (($_POST['action'] ?? '') === 'kaydet') {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['irs_id'] ?? []))));

    // Tedarikçi seçilmemişse ve faturadan satıcı bilgisi çıkarıldıysa (isteğe bağlı kutu):
    // önce VKN, sonra normalize unvan ile mevcut tedarikçi aranır; yoksa OLUŞTURULUR.
    if (!(int)($_POST['tedarikci_id'] ?? 0) && !empty($_POST['tedarikci_olustur'])) {
        $sUnvan = trim((string)($_POST['satici_unvan'] ?? ''));
        $sVkn   = preg_replace('/\D/', '', (string)($_POST['satici_vkn'] ?? ''));
        try {
            $mevTed = fat_tedarikci_bul($pdo, $sVkn) ?: fat_tedarikci_bul_ad($pdo, $sUnvan);
            if ($mevTed) {
                $_POST['tedarikci_id'] = (int)$mevTed['id'];
                if ($sVkn !== '' && trim((string)$mevTed['vkn']) === '') {   // VKN'yi tamamla — bir daha sorulmasın
                    $pdo->prepare("UPDATE tedarikciler SET vkn = ? WHERE id = ?")->execute([$sVkn, (int)$mevTed['id']]);
                }
            } elseif ($sUnvan !== '') {
                $pdo->prepare("INSERT INTO tedarikciler (ad, vkn) VALUES (?, ?)")->execute([$sUnvan, $sVkn ?: null]);
                $_POST['tedarikci_id'] = (int)$pdo->lastInsertId();
                audit_log($pdo, 'tedarikciler', (int)$_POST['tedarikci_id'], 'INSERT', null,
                          ['fatura_otomatik' => true, 'ad' => $sUnvan, 'vkn' => $sVkn], current_user_id());
                flash('info', 'Yeni tedarikçi oluşturuldu: ' . $sUnvan . ($sVkn !== '' ? " (VKN $sVkn)" : ''));
            }
        } catch (Throwable $eT) { /* tedarikçi çözülemedi — aşağıdaki uyarı akışı devreye girer */ }
    }

    // İsteğe bağlı: faturada olup sistemde bulunmayan irsaliyeleri TASLAK olarak oluştur.
    // Taslak; fatura tarihi/tedarikçisiyle, 0 m³ ve [FATURADAN] etiketiyle açılır.
    // Excel aktarımı aynı numarayı getirdiğinde taslak SİLİNMEZ — gerçek verilerle
    // güncellenir (import.php normalize eşleşme), fatura bağı ve ekli belgeler korunur.
    $taslakOlusan = 0; $taslakUyari = '';
    if (!empty($_POST['eksik_olustur'])) {
        $eksikler = is_array($el0 = json_decode((string)($_POST['eksik_liste'] ?? ''), true))
                    ? array_slice(array_map('strval', $el0), 0, 500) : [];
        $tedId = (int)($_POST['tedarikci_id'] ?? 0);
        if (!$tedId) {
            $taslakUyari = 'Eksik irsaliyeler için taslak OLUŞTURULMADI: tedarikçi seçilmedi '
                         . '(irsaliye tedarikçisiz kaydedilemez). Tedarikçiyi seçip faturayı yeniden çözümleyin.';
        } elseif ($eksikler) {
            try {
                $t0 = fat_taslak_ac($pdo, $eksikler, $tedId, $_POST['tarih'] ?? null,
                                    trim((string)($_POST['fatura_no'] ?? '')), current_user_id());
                foreach ($t0['ids'] as $i0) $ids[] = $i0;
                $taslakOlusan = $t0['acilan'];
                $_POST['eksik_adet'] = 0; $_POST['eksik_liste'] = '[]';   // artık eksik değiller
            } catch (Throwable $e0) {
                $taslakUyari = 'Taslak irsaliye oluşturulamadı: ' . $e0->getMessage();
            }
        }
        $ids = array_values(array_unique($ids));
    }

    try {
        $r = fat_kaydet($pdo, [
            'fatura_no'    => trim((string)($_POST['fatura_no'] ?? '')),
            'tur'          => fat_tur_norm($_POST['tur'] ?? null),
            'tarih'        => fat_tarih_norm($_POST['tarih'] ?? '') ?: null,
            'tedarikci_id' => (int)($_POST['tedarikci_id'] ?? 0) ?: null,
            'tutar'        => $_POST['tutar'] ?? null,
            'miktar'       => $_POST['miktar'] ?? null,
            'ettn'         => trim((string)($_POST['ettn'] ?? '')) ?: null,
            'eksik_adet'   => (int)($_POST['eksik_adet'] ?? 0),
            'eksik_liste'  => is_array($el = json_decode((string)($_POST['eksik_liste'] ?? ''), true))
                              ? array_slice(array_map('strval', $el), 0, 500) : [],
            'notlar'       => trim((string)($_POST['notlar'] ?? '')) ?: null,
            'irsaliye_liste' => is_array($il = json_decode((string)($_POST['irsaliye_liste'] ?? ''), true))
                              ? array_slice(array_map('strval', $il), 0, 500) : [],
        ], $ids, current_user_id(), trim((string)($_POST['dosya_url'] ?? '')) ?: null);

        // Fatura dosyasını eşleşen her irsaliyenin belgelerine de ekle (irsaliye ekranından görünsün)
        $dosya   = trim((string)($_POST['dosya_url'] ?? ''));
        $faturaNo = trim((string)($_POST['fatura_no'] ?? ''));
        $eklenen = 0; $zaten = 0;
        if ($dosya !== '' && $ids) {
            // Mükerrer önleme fatura NUMARASINA bakar; dosya yolu her yüklemede değişir,
            // ona bakmak aynı faturayı ikinci kez eklerdi.
            $kimlik = ['fatura_no' => $faturaNo];
            foreach ($ids as $iid) {
                if (blg_mukerrer($pdo, (int)$iid, 'fatura', $kimlik)) { $zaten++; continue; }
                blg_ekle($pdo, (int)$iid, 'Fatura ' . $faturaNo, $dosya, 'fatura', $kimlik, current_user_id());
                $eklenen++;
            }
        }
        flash('success', "Fatura kaydedildi: {$r['baglanan']} irsaliye faturaya bağlandı"
                       . ($taslakOlusan ? ", {$taslakOlusan} eksik irsaliye TASLAK olarak oluşturuldu (Excel aktarımında gerçek verilerle güncellenir)" : "")
                       . ($eklenen ? ", fatura {$eklenen} irsaliyenin belgelerine eklendi" : "")
                       . ($zaten   ? ", {$zaten} irsaliyede zaten ekliydi (mükerrer eklenmedi)" : "") . ".");
        if ($taslakUyari) flash('warning', $taslakUyari);
    } catch (Throwable $e) {
        flash('error', 'Kayıt hatası: '.$e->getMessage());
    }
    redirect('fatura_eslestir.php');
}

// ── 2b) Eksik listesi kayıp eski kayıt: saklı PDF'ten yeniden tara ──────────
if (($_POST['action'] ?? '') === 'eksik_tara' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    $fid = (int)$_POST['fatura_id'];
    $f = $pdo->prepare("SELECT * FROM faturalar WHERE id = ?"); $f->execute([$fid]);
    $f = $f->fetch();
    if (!$f) { flash('error', 'Fatura bulunamadı.'); redirect('fatura_eslestir.php?eksik=1'); }

    if (!fat_tur_irsaliyeli($f['tur'] ?? 'beton')) {
        flash('warning', h($f['fatura_no']) . ' — ' . FAT_TUR[fat_tur_norm($f['tur'])][0]
            . ' faturasında irsaliye/m³ aranmaz. Beton faturasıysa listeden türünü "Beton (irsaliyeli)" yapın.');
        redirect('fatura_eslestir.php');
    }

    $tam = $f['dosya_url'] ? __DIR__ . '/' . $f['dosya_url'] : '';
    if ($tam === '' || !is_file($tam)) {
        flash('error', 'Bu faturanın kayıtlı dosyası yok (metin yapıştırarak işlenmiş) — faturayı yukarıdan yeniden yükleyin, kaydettiğinizde eksik numaralar dolar.');
        redirect('fatura_eslestir.php?eksik=1');
    }
    $mime = guess_mime($tam, basename($tam));
    $metin = fat_dosyadan_metin($tam, $mime);
    if ($metin === null || $metin === '') {
        flash('error', 'Dosya okunamadı (AI okuma başarısız) — faturayı yukarıdan yeniden yükleyip kaydedin.');
        redirect('fatura_eslestir.php?eksik=1');
    }
    $veri = fat_metinden_cikar($metin);
    $esl  = fat_eslestir($pdo, $veri['irsaliyeler']);

    // Eksik listesi + karekod-yalnız işlenmiş faturalarda BOŞ kalan alanlar
    // (m³ karekodda yoktur, yalnız metinden okunur) yeniden taramayla doldurulur.
    // Dolu alanların üzerine YAZILMAZ.
    $set = ['eksik_adet = ?', 'eksik_liste = ?'];
    $par = [count($esl['eksik']), json_encode(array_values($esl['eksik']), JSON_UNESCAPED_UNICODE)];
    $dolan = [];
    foreach ([['miktar_m3', $veri['miktar'], 'm³'], ['tutar', $veri['tutar'], 'tutar'],
              ['tarih', $veri['tarih'], 'tarih'], ['ettn', $veri['ettn'], 'ETTN']] as [$kol, $deger, $ad2]) {
        if ($deger !== null && $deger !== '' && ($f[$kol] === null || $f[$kol] === '')) {
            $set[] = "$kol = ?"; $par[] = $deger; $dolan[] = $ad2;
        }
    }
    $par[] = $fid;
    $pdo->prepare("UPDATE faturalar SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
    audit_log($pdo, 'faturalar', $fid, 'UPDATE', null,
              ['eksik_tara' => true, 'eksik' => $esl['eksik'], 'dolan' => $dolan], current_user_id());

    $mesaj = $esl['eksik'] ? 'Fatura yeniden tarandı — eksik irsaliyeler: ' . implode(', ', $esl['eksik'])
                           : 'Fatura yeniden tarandı — faturadaki tüm irsaliyeler sistemde var, eksik yok.';
    if ($dolan) $mesaj .= ' Boş alanlar dolduruldu: ' . implode(', ', $dolan) . '.';
    $m3Yok = ($f['miktar_m3'] === null || $f['miktar_m3'] === '') && ($veri['miktar'] === null || $veri['miktar'] === '');
    if ($m3Yok) $mesaj .= ' ⚠ m³ değeri metinden OKUNAMADI (PDF görüntü tabanlı olabilir) — listedeki m³ sütunundaki kutuya elle girebilirsiniz.';
    flash(($esl['eksik'] || $m3Yok) ? 'warning' : 'success', $mesaj);
    redirect(($_POST['geri'] ?? '') === 'liste' ? 'fatura_eslestir.php' : 'fatura_eslestir.php?eksik=1');
}

// ── 2c') Kayıtlı faturanın EKSİK irsaliyelerini taslak olarak aç ────────────
// Toplu "Çözümle ve Kaydet" eskiden taslak açmıyordu; o faturaların eksik numaraları
// `eksik_liste`de saklı — dosya yeniden okunmadan (AI harcamadan) taslak açılıp faturaya
// bağlanır. Mevcut bağlara DOKUNULMAZ (fat_kaydet her şeyi çözüp yeniden bağlardı);
// başka bir faturaya bağlı kayıt TAŞINMAZ. fatura_id=hepsi → eksik listesi olan tüm faturalar.
if (($_POST['action'] ?? '') === 'eksik_taslak') {
    $hepsi = ($_POST['fatura_id'] ?? '') === 'hepsi';
    $sql = "SELECT * FROM faturalar WHERE eksik_adet > 0 AND eksik_liste IS NOT NULL AND eksik_liste <> ''
            AND tur NOT IN ('" . implode("','", FAT_TUR_IRSALIYESIZ) . "')";
    $par = [];
    if (!$hepsi) {
        if (!ctype_digit((string)($_POST['fatura_id'] ?? ''))) redirect('fatura_eslestir.php?eksik=1');
        $sql .= " AND id = ?"; $par[] = (int)$_POST['fatura_id'];
    }
    $st = $pdo->prepare($sql . " ORDER BY tarih, id"); $st->execute($par);
    $topAcilan = 0; $topBag = 0; $islenen = 0; $tedsiz = []; $baskada = 0;
    foreach ($st->fetchAll() as $f) {
        $liste = json_decode((string)$f['eksik_liste'], true);
        if (!is_array($liste) || !$liste) continue;
        if (empty($f['tedarikci_id'])) { $tedsiz[] = $f['fatura_no']; continue; }
        try {
            $pdo->beginTransaction();
            $t0 = fat_taslak_ac($pdo, $liste, (int)$f['tedarikci_id'], $f['tarih'], $f['fatura_no'], current_user_id());
            $bag = $pdo->prepare("UPDATE irsaliyeler SET fatura_id = ?,
                    fatura_no = CASE WHEN fatura_no IS NULL OR TRIM(fatura_no) = '' THEN ? ELSE fatura_no END
                    WHERE id = ? AND (fatura_id IS NULL OR fatura_id = ?)");
            $yeniBag = [];
            foreach ($t0['ids'] as $iid) {
                $bag->execute([(int)$f['id'], $f['fatura_no'], $iid, (int)$f['id']]);
                if ($bag->rowCount()) $yeniBag[] = $iid; else $baskada++;
            }
            $pdo->prepare("UPDATE faturalar SET eksik_adet = 0, eksik_liste = NULL,
                    irsaliye_adet = (SELECT COUNT(*) FROM irsaliyeler WHERE fatura_id = ?) WHERE id = ?")
                ->execute([(int)$f['id'], (int)$f['id']]);
            $pdo->commit();
            // Fatura dosyası bağlanan irsaliyelerin belgelerine de eklenir (tekil akışla aynı)
            if (!empty($f['dosya_url'])) {
                $kimlik = ['fatura_no' => $f['fatura_no']];
                foreach ($yeniBag as $iid) {
                    if (blg_mukerrer($pdo, (int)$iid, 'fatura', $kimlik)) continue;
                    blg_ekle($pdo, (int)$iid, 'Fatura ' . $f['fatura_no'], $f['dosya_url'], 'fatura', $kimlik, current_user_id());
                }
            }
            audit_log($pdo, 'faturalar', (int)$f['id'], 'UPDATE', ['eksik_liste' => $liste],
                      ['eksik_taslak' => $t0['acilan_nolar'], 'baglanan' => count($yeniBag)], current_user_id());
            $topAcilan += $t0['acilan']; $topBag += count($yeniBag); $islenen++;
        } catch (Throwable $eT) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', h($f['fatura_no']) . ': taslak açılamadı — ' . $eT->getMessage());
        }
    }
    if ($islenen) flash('success', "{$islenen} faturada {$topAcilan} eksik irsaliye TASLAK olarak açıldı, "
                                  . "{$topBag} irsaliye faturaya bağlandı (Excel aktarımı gerçek verilerle günceller).");
    if ($baskada) flash('warning', "{$baskada} irsaliye başka bir faturaya bağlı olduğu için taşınmadı.");
    if ($tedsiz)  flash('warning', 'Tedarikçisi olmayan faturada taslak açılamaz (irsaliye tedarikçisiz kaydedilemez): '
                                  . implode(', ', $tedsiz) . ' — faturayı tekil ekranda tedarikçi seçerek yeniden kaydedin.');
    if (!$islenen && !$tedsiz) flash('info', 'Taslak açılacak eksik irsaliye bulunamadı.');
    redirect('fatura_eslestir.php?eksik=1');
}

// ── 2d) TOPLU FATURA KONTROLÜ: "bu 100 dosyadan hangisi işlenmiş?" ──────────
// Dosyalar KAYDEDİLMEZ; her birinin numarası dosya ADINDAN (e-fatura dosyaları
// çoğunlukla numarayla adlandırılır), olmazsa PDF içeriğinden/karekodundan
// çıkarılıp kayıtlı faturalarla (no VEYA ETTN, normalize) karşılaştırılır.
// Büyük seçimler tarayıcıda partilenir (belge_dagit ile aynı desen).
fat_kontrol_uc('fat_kontrol');   // kontrol_basla / kontrol_hata (assets/js/toplu_kontrol.js)
if (($_POST['action'] ?? '') === 'fatura_kontrol' && !empty($_FILES['dosyalar']['tmp_name'])) {
    @set_time_limit(300);
    // İşlenmemiş çıkan dosyalar bekleme klasöründe tutulur ki rapordaki
    // "işlenmemişleri çözümle ve kaydet" butonu yeniden yükletmeden işleyebilsin.
    $KONT_BEKLEME = __DIR__ . '/uploads/fatura_kontrol_bekleyen';
    if (!is_dir($KONT_BEKLEME)) @mkdir($KONT_BEKLEME, 0755, true);
    foreach ((array)@glob($KONT_BEKLEME . '/fk_*') as $eskiF) {          // 2 günden eski artıklar temizlenir
        if (@filemtime($eskiF) < time() - 2*86400) @unlink($eskiF);
    }
    $noIdx = []; $ettnIdx = []; $idIdx = []; $irsIdx = []; $dosyaIdx = [];
    foreach ($pdo->query("SELECT f.id, f.fatura_no, f.ettn, f.tarih, f.eksik_adet, f.dosya_url, f.irsaliye_liste, f.eksik_liste,
                                 (SELECT COUNT(*) FROM irsaliyeler i WHERE i.fatura_id = f.id) bagli
                          FROM faturalar f") as $fr) {
        $idIdx[(int)$fr['id']] = $fr;
        $k = fat_irs_norm((string)$fr['fatura_no']); if ($k !== '') $noIdx[$k] = $fr;
        $e0 = strtoupper(trim((string)$fr['ettn'])); if ($e0 !== '') $ettnIdx[$e0] = $fr;
        if (!empty($fr['dosya_url'])) $dosyaIdx[(int)$fr['id']] = (string)$fr['dosya_url'];
        // Dosya İRSALİYE numarasıyla adlandırılmış olabilir → o irsaliyeyi taşıyan fatura
        foreach (array_merge((array)(json_decode((string)$fr['irsaliye_liste'], true) ?: []),
                             (array)(json_decode((string)$fr['eksik_liste'], true) ?: [])) as $irsN) {
            $k = fat_irs_norm((string)$irsN); if ($k !== '') $irsIdx[$k] ??= $fr;
        }
    }
    try {
        foreach ($pdo->query("SELECT irsaliye_no, fatura_id FROM irsaliyeler WHERE fatura_id IS NOT NULL") as $sv) {
            $k = fat_irs_norm((string)$sv['irsaliye_no']);
            if ($k !== '' && isset($idIdx[(int)$sv['fatura_id']])) $irsIdx[$k] ??= $idIdx[(int)$sv['fatura_id']];
        }
    } catch (Throwable $eS) {}
    $kSonuc = [];
    foreach ($_FILES['dosyalar']['tmp_name'] as $i => $tmp) {
        $ad = (string)$_FILES['dosyalar']['name'][$i];
        try {   // tek bir sorunlu dosya partinin geri kalanını götürmesin
        if ((int)$_FILES['dosyalar']['error'][$i] !== UPLOAD_ERR_OK) {
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'hata', 'mesaj'=>'yükleme hatası']; continue;
        }
        $no = null; $ettn = null; $kaynak = 'dosya adı'; $t0 = null;
        if (preg_match('/([A-Z][A-Z0-9]{2}\d{13})/i', $ad, $m)) $no = strtoupper($m[1]);
        if ($no === null && guess_mime($tmp, $ad) === 'application/pdf') {
            $t0 = fat_pdftotext($tmp);
            if ($t0 !== null && $t0 !== '') {
                $v0 = fat_metinden_cikar($t0);
                $no = $v0['fatura_no']; $ettn = $v0['ettn']; $kaynak = 'PDF içeriği';
            }
        }
        // Eşleşme sırası: dosya adındaki fatura no → ETTN → dosya adındaki İRSALİYE no (PDF metni okunabiliyorsa
        // içerikteki fatura no ile doğrulanır) → PDF içeriği → sistemdeki fatura dosyasıyla BAYT BAYT aynılık.
        $fr = null; $irsYolu = false; $uyari = null; $v0 = null;
        if ($no !== null) $fr = $noIdx[fat_irs_norm($no)] ?? null;
        if (!$fr && $ettn !== null) $fr = $ettnIdx[strtoupper((string)$ettn)] ?? null;
        if (!$fr && $no !== null && $kaynak === 'dosya adı' && isset($irsIdx[fat_irs_norm($no)])) {
            $fr = $irsIdx[fat_irs_norm($no)]; $irsYolu = true; $kaynak = 'dosya adı (irsaliye no)';
        }
        $pdfMi = guess_mime($tmp, $ad) === 'application/pdf';
        if ((!$fr || $irsYolu) && str_starts_with($kaynak, 'dosya adı') && $pdfMi) {
            $t0 = fat_pdftotext($tmp);
            if ($t0 !== null && $t0 !== '') $v0 = fat_metinden_cikar($t0);
            if ($v0 && ($v0['fatura_no'] || $v0['ettn'])) {
                $frIc = ($v0['fatura_no'] ? ($noIdx[fat_irs_norm($v0['fatura_no'])] ?? null) : null)
                     ?: ($v0['ettn'] ? ($ettnIdx[strtoupper((string)$v0['ettn'])] ?? null) : null);
                if ($irsYolu) {
                    if ($frIc) {
                        $kaynak = (int)$frIc['id'] === (int)$fr['id'] ? 'dosya adı (irsaliye no) · PDF ile doğrulandı' : 'PDF içeriği';
                        $fr = $frIc;
                    } else {
                        // Aynı irsaliye kayıtlı başka faturada, bu PDF ise sistemde olmayan bir fatura
                        $uyari = 'İrsaliye ' . $no . ' sistemde ' . $fr['fatura_no'] . ' faturasında kayıtlı, bu PDF ise BAŞKA bir fatura ('
                               . ($v0['fatura_no'] ?: 'ETTN ' . $v0['ettn']) . ') — aynı irsaliye iki faturada: ÇİFT FATURALAMA ŞÜPHESİ, ödemeden önce kontrol edin';
                        $fr = null; $no = $v0['fatura_no'] ?: $no; $ettn = $v0['ettn']; $kaynak = 'PDF içeriği';
                    }
                } else {
                    $fr = $frIc;
                    if ($fr || $v0['fatura_no']) { $kaynak = 'PDF içeriği'; if ($v0['fatura_no']) $no = $v0['fatura_no']; }
                    // ETTN ile bulundu ama numara farklı → aynı fatura başka numarayla kayıtlı (ya da ETTN yanlış girilmiş)
                    if ($fr && $v0['fatura_no'] && fat_irs_norm((string)$fr['fatura_no']) !== fat_irs_norm((string)$v0['fatura_no'])) {
                        $kaynak = 'PDF içeriği (ETTN) · ⚠ PDF\'teki no ' . $v0['fatura_no'] . ', kayıtta farklı numara';
                    }
                }
            } elseif ($irsYolu) {
                $kaynak .= ' · içerik doğrulanamadı';
            }
        } elseif (!$fr && $kaynak === 'PDF içeriği' && $t0) {
            $v0 = fat_metinden_cikar($t0);
        }
        // Son çare: sistemdeki fatura dosyalarından biriyle bayt bayt aynı mı (taranmış PDF, anlamsız dosya adı)
        if (!$fr && !$uyari && ($fidA = fat_ayni_dosya($dosyaIdx, __DIR__, $tmp)) && isset($idIdx[$fidA])) {
            $fr = $idIdx[$fidA]; $kaynak = 'dosya içeriği (kayıtlı fatura dosyasıyla birebir aynı)';
        }
        if ($fr) $kSonuc[] = ['ad'=>$ad, 'no'=>$fr['fatura_no'] ?: $no, 'durum'=>'islenmis', 'kaynak'=>$kaynak, 'id'=>(int)$fr['id'],
                              'tarih'=>$fr['tarih'], 'bagli'=>(int)$fr['bagli'], 'eksik'=>(int)$fr['eksik_adet']];
        else {
            // Sistemde olmayan faturanın irsaliyeleri BAŞKA bir faturaya bağlıysa: çift faturalama şüphesi
            if (!$uyari && $v0 && !empty($v0['irsaliyeler'])) {
                $esl0 = fat_eslestir($pdo, $v0['irsaliyeler']);
                $bsk0 = fat_baskaya_bagli($pdo, array_map(fn($r) => (int)$r['id'], $esl0['eslesen']), 0);
                if ($bsk0) $uyari = count($bsk0) . ' irsaliyesi sistemde BAŞKA faturaya bağlı ('
                    . implode(', ', array_map(fn($b) => $b['irsaliye_no'] . ' → ' . $b['fatura_no'], array_slice($bsk0, 0, 5)))
                    . (count($bsk0) > 5 ? ' …' : '') . ') — aynı irsaliye iki faturada: ÇİFT FATURALAMA ŞÜPHESİ, ödemeden önce kontrol edin';
            }
            // Numarası okunamayan dosya da bekletilir — "ÇÖZÜMLE ve KAYDET" içeriği AI ile okuyabilir
            $satir = ['ad'=>$ad, 'no'=>$no, 'durum'=>($no === null && $ettn === null) ? 'okunamadi' : 'islenmemis',
                      'kaynak'=>($no === null && $ettn === null) ? null : $kaynak, 'uyari'=>$uyari];
            $ext = guvenli_uzanti($ad, '', 'pdf');
            if (preg_match('/^[a-z0-9]{1,6}$/', $ext)) {
                $pid = uniqid('fk_', true) . '.' . $ext;
                if (@move_uploaded_file($tmp, $KONT_BEKLEME . '/' . $pid)) $satir['dosya'] = $pid;
            }
            $kSonuc[] = $satir;
        }
        } catch (Throwable $eF) {
            $kSonuc[] = ['ad'=>$ad, 'durum'=>'hata', 'mesaj'=>'kontrol edilemedi — ' . mb_substr($eF->getMessage(), 0, 200)];
        }
    }
    $_SESSION['fat_kontrol'] = array_merge($_SESSION['fat_kontrol'] ?? [], $kSonuc);
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'islenen'    => count($kSonuc),
            'islenmis'   => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'islenmis')),
            'islenmemis' => count(array_filter($kSonuc, fn($r) => $r['durum'] === 'islenmemis')),
            'diger'      => count(array_filter($kSonuc, fn($r) => !in_array($r['durum'], ['islenmis','islenmemis'], true))),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('fatura_eslestir.php?kontrol=1');
}
$kontrolSonuc = null;
if (isset($_GET['kontrol']) && !empty($_SESSION['fat_kontrol'])) {
    $kontrolSonuc = $_SESSION['fat_kontrol'];
    $kSecilen = (int)($_SESSION['fat_kontrol_secilen'] ?? 0);
    unset($_SESSION['fat_kontrol'], $_SESSION['fat_kontrol_secilen']);
}

// ── 2e) İŞLENMEMİŞLERİ ÇÖZÜMLE ve KAYDET (kontrol raporundan, parti AJAX) ────
// Tam otomatik hat: metin çıkar (pdftotext→AI) → tedarikçiyi VKN/unvandan bul,
// yoksa OLUŞTUR → irsaliyeleri normalize eşleştir → fat_kaydet (bağlama + eksik
// listesi) → fatura dosyasını arşive taşı + eşleşen irsaliyelere belge olarak ekle.
// Taslak irsaliye AÇILMAZ (o karar tekil ekranda bilinçli verilir); eksikler raporlanır.
// ── 2d') ETTN ÇAKIŞMASI KARARI (işlem raporundan) ─────────────────────────────
//  (a) ettn_no_duzelt: aynı fatura, kayıtlı numara yanlış → kaydın numarası PDF'teki numaraya çevrilir.
//  (b) ettn_ayir: farklı fatura → eski kaydın ETTN'i temizlenir ve bekleyen dosya yeni fatura olarak işlenir.
if (in_array($_POST['action'] ?? '', ['ettn_no_duzelt', 'ettn_ayir'], true) && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    if (!can_edit()) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('fatura_eslestir.php'); }
    $fid  = (int)$_POST['fatura_id'];
    $pid  = basename((string)($_POST['dosya'] ?? ''));
    $tamB = preg_match('/^fk_[\w.]+$/', $pid) ? __DIR__ . '/uploads/fatura_kontrol_bekleyen/' . $pid : '';
    $st = $pdo->prepare("SELECT * FROM faturalar WHERE id = ?"); $st->execute([$fid]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { flash('error', 'Fatura bulunamadı.'); redirect('fatura_eslestir.php'); }
    if ($_POST['action'] === 'ettn_no_duzelt') {
        $yeni = strtoupper(trim((string)($_POST['yeni_no'] ?? '')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{4,49}$/', $yeni)) { flash('error', 'Geçersiz fatura numarası.'); redirect('fatura_eslestir.php'); }
        $c = $pdo->prepare("SELECT COUNT(*) FROM faturalar WHERE fatura_no = ? AND id <> ?"); $c->execute([$yeni, $fid]);
        if ((int)$c->fetchColumn() > 0) { flash('error', "{$yeni} numaralı başka bir fatura zaten kayıtlı — numara değiştirilmedi."); redirect('fatura_eslestir.php?fatura_ara=' . urlencode($yeni)); }
        $pdo->prepare("UPDATE faturalar SET fatura_no = ? WHERE id = ?")->execute([$yeni, $fid]);
        // İrsaliyelere yazılmış eski numara da düzelsin (yalnız bu faturaya bağlı ve eski numarayı taşıyanlar)
        $pdo->prepare("UPDATE irsaliyeler SET fatura_no = ? WHERE fatura_id = ? AND fatura_no = ?")->execute([$yeni, $fid, $f['fatura_no']]);
        audit_log($pdo, 'faturalar', $fid, 'UPDATE', ['fatura_no' => $f['fatura_no']], ['fatura_no' => $yeni, 'sebep' => 'ETTN çakışması — aynı fatura'], current_user_id());
        if ($tamB && is_file($tamB)) @unlink($tamB);
        flash('success', "Fatura numarası düzeltildi: {$f['fatura_no']} → {$yeni}. Bu dosya bir sonraki kontrolde \"işlenmiş\" görünecek.");
        redirect('fatura_eslestir.php?fatura_ara=' . urlencode($yeni));
    }
    // ettn_ayir
    if (!$tamB || !is_file($tamB)) { flash('error', 'Bekleyen dosya bulunamadı (süresi dolmuş olabilir) — Toplu Fatura Kontrolü\'nü yeniden çalıştırın.'); redirect('fatura_eslestir.php'); }
    $pdo->prepare("UPDATE faturalar SET ettn = NULL WHERE id = ?")->execute([$fid]);
    audit_log($pdo, 'faturalar', $fid, 'UPDATE', ['ettn' => $f['ettn']], ['ettn' => null, 'sebep' => 'ETTN çakışması — farklı fatura, ETTN temizlendi'], current_user_id());
    $_POST = ['action' => 'fatura_isle', 'dosya' => [$pid], 'ad' => [(string)($_POST['dosya_adi'] ?? $pid)], 'taslak' => '1', '_yonlendir' => '1'];
}

if (($_POST['action'] ?? '') === 'fatura_isle' && !empty($_POST['dosya'])) {
    @set_time_limit(600);
    $KONT_BEKLEME = __DIR__ . '/uploads/fatura_kontrol_bekleyen';
    $taslakAc = !empty($_POST['taslak']);   // faturadaki eksik irsaliyeleri taslak olarak aç
    $iSonuc = [];
    $adlar = array_values((array)($_POST['ad'] ?? []));
    foreach (array_values((array)$_POST['dosya']) as $iA => $pid) {
        $pid = basename((string)$pid);
        if (!preg_match('/^fk_[\w.]+$/', $pid)) continue;
        $tam = $KONT_BEKLEME . '/' . $pid;
        $ad  = $pid;
        // Raporda BİLGİSAYARDAKİ dosya adı görünsün (hangi dosyada sorun var anlaşılsın); yalnız gösterim içindir
        $orjAd = mb_substr(basename(str_replace('\\', '/', (string)($adlar[$iA] ?? ''))), 0, 200) ?: $pid;
        if (!is_file($tam)) { $iSonuc[] = ['ad'=>$orjAd, 'durum'=>'hata', 'mesaj'=>'dosya bulunamadı (süresi dolmuş olabilir — kontrolü yeniden çalıştırın)']; continue; }
        try {
            $mime  = guess_mime($tam, $ad);
            $metin = fat_dosyadan_metin($tam, $mime);
            $veri  = ($metin !== null && $metin !== '') ? fat_metinden_cikar($metin) : null;
            if (!$veri || !$veri['fatura_no']) {
                $iSonuc[] = ['ad'=>$orjAd, 'durum'=>'hata', 'mesaj'=>'fatura no çözülemedi — tekil "Fatura Yükle" ile elle işleyin']; continue;
            }
            // Mükerrer (bu arada işlenmiş olabilir)
            // ⚠ fat_mevcut fatura no VEYA ETTN ile eşleşir. Toplu kontrol dosya adındaki NUMARAYA bakar;
            //    numara farklı ama ETTN aynı bir kayıt varsa kontrol "işlenmemiş", burası "zaten" der ve
            //    dosya her kontrolde yeniden "işlenmemiş" çıkar. Bu ETTN ÇAKIŞMASI artık görünür kılınır:
            //    iki tarafın künyesi raporda yan yana, dosya karar verilene kadar bekleme klasöründe kalır.
            if ($mv = fat_mevcut($pdo, $veri['fatura_no'], $veri['ettn'])) {
                $cakisma = ($mv['eslesme_alani'] ?? '') === 'ettn'
                        && fat_irs_norm((string)$mv['fatura_no']) !== fat_irs_norm((string)$veri['fatura_no']);
                if (!$cakisma) @unlink($tam);
                $iSonuc[] = ['ad'=>$orjAd, 'no'=>$veri['fatura_no'], 'durum'=>'zaten',
                             'mesaj'=>$cakisma ? 'aynı ETTN başka numarayla kayıtlı' : 'zaten işlenmiş',
                             'kayit'=>['id'=>(int)$mv['id'], 'no'=>(string)$mv['fatura_no'], 'tarih'=>$mv['tarih'],
                                       'tutar'=>$mv['tutar'] !== null ? (float)$mv['tutar'] : null, 'ted'=>$mv['tedarikci'] ?? null,
                                       'alan'=>$mv['eslesme_alani'] ?? null],
                             'pdf'=>['tarih'=>$veri['tarih'], 'tutar'=>$veri['tutar'], 'ted'=>$veri['satici_unvan'] ?? null,
                                     'ettn'=>$veri['ettn']],
                             'cakisma'=>$cakisma, 'dosya'=>$cakisma ? $pid : null];
                continue;
            }
            // Tedarikçi: VKN → normalize unvan → oluştur
            $ted = fat_tedarikci_bul($pdo, $veri['satici_vkn'] ?? null)
                ?: fat_tedarikci_bul_ad($pdo, $veri['satici_unvan'] ?? null);
            $tedYeni = false;
            if (!$ted && !empty($veri['satici_unvan'])) {
                $pdo->prepare("INSERT INTO tedarikciler (ad, vkn) VALUES (?, ?)")
                    ->execute([$veri['satici_unvan'], preg_replace('/\D/', '', (string)($veri['satici_vkn'] ?? '')) ?: null]);
                $ted = ['id' => (int)$pdo->lastInsertId(), 'ad' => $veri['satici_unvan']];
                $tedYeni = true;
            }
            // Eşleştir + (isteğe bağlı) eksikleri taslak aç + arşive taşı + kaydet
            $esl = fat_eslestir($pdo, $veri['irsaliyeler']);
            $ids = array_map(fn($r) => (int)$r['id'], $esl['eslesen']);
            // Tür: irsaliye numarası yoksa metinden sezilir (pompa kirası / hizmet faturası)
            $turB = fat_tur_sez((string)$metin, $veri);
            $taslakAcilan = 0; $taslakUyari = '';
            if ($taslakAc && $esl['eksik'] && fat_tur_irsaliyeli($turB['tur'])) {
                if (empty($ted['id'])) {
                    $taslakUyari = 'tedarikçi bulunamadı — eksikler taslak açılmadı';
                } else {
                    $t0 = fat_taslak_ac($pdo, $esl['eksik'], (int)$ted['id'], $veri['tarih'], $veri['fatura_no'], current_user_id());
                    $ids = array_values(array_unique(array_merge($ids, $t0['ids'])));
                    $taslakAcilan = $t0['acilan'];
                    $esl['eksik'] = [];                                   // hepsi artık sistemde
                }
            }
            // ⚠ Başka bir faturaya ZATEN bağlı irsaliye otomatik akışta TAŞINMAZ (çift faturalandırma
            // şüphesi — fat_kaydet bağı sessizce yeni faturaya geçirirdi); raporda listelenir.
            $baskada = [];
            foreach (fat_baskaya_bagli($pdo, $ids) as $bid => $b) $baskada[$bid] = $b['irsaliye_no'] . ' → ' . $b['fatura_no'];
            if ($baskada) $ids = array_values(array_diff($ids, array_keys($baskada)));
            $dir = __DIR__ . '/uploads/faturalar/' . date('Y/m');
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $hedefAd  = date('Ymd_His') . '_' . substr(md5($pid . microtime(true)), 0, 8) . '.' . guvenli_uzanti($pid, '', 'pdf');
            $dosyaUrl = null;
            if (@rename($tam, $dir . '/' . $hedefAd)) $dosyaUrl = 'uploads/faturalar/' . date('Y/m') . '/' . $hedefAd;
            fat_kaydet($pdo, [
                'fatura_no'   => $veri['fatura_no'], 'tarih' => $veri['tarih'],
                'tur'         => $turB['tur'],
                'tedarikci_id'=> (int)($ted['id'] ?? 0) ?: null,
                'tutar'       => $veri['tutar'], 'miktar' => $veri['miktar'], 'ettn' => $veri['ettn'],
                'eksik_adet'  => count($esl['eksik']), 'eksik_liste' => $esl['eksik'],
                'irsaliye_liste' => (array)($veri['irsaliyeler'] ?? []),
                'notlar'      => 'Toplu kontrolden otomatik işlendi'
                                 . ($taslakAcilan ? ' — ' . $taslakAcilan . ' eksik irsaliye TASLAK açıldı' : '')
                                 . ($baskada ? ' — başka faturaya bağlı olduğu için bağlanmayan: ' . implode(', ', $baskada) : '')
                                 . (fat_tur_irsaliyeli($turB['tur']) ? '' : ' — ' . FAT_TUR[$turB['tur']][0] . ' olarak işaretlendi'),
            ], $ids, current_user_id(), $dosyaUrl);
            // Fatura dosyasını eşleşen irsaliyelerin belgelerine de ekle (tekil akışla aynı)
            $belgeEk = 0;
            if ($dosyaUrl && $ids) {
                $kimlik = ['fatura_no' => $veri['fatura_no']];
                foreach ($ids as $iid) {
                    if (blg_mukerrer($pdo, (int)$iid, 'fatura', $kimlik)) continue;
                    blg_ekle($pdo, (int)$iid, 'Fatura ' . $veri['fatura_no'], $dosyaUrl, 'fatura', $kimlik, current_user_id());
                    $belgeEk++;
                }
            }
            $iSonuc[] = ['ad'=>$orjAd, 'no'=>$veri['fatura_no'], 'durum'=>'kaydedildi',
                         'bagli'=>count($ids), 'eksik'=>count($esl['eksik']), 'belge'=>$belgeEk,
                         'taslak'=>$taslakAcilan, 'taslak_uyari'=>$taslakUyari, 'baskada'=>array_values($baskada),
                         'ted'=>($ted['ad'] ?? null), 'ted_yeni'=>$tedYeni,
                         'm3'=>$veri['miktar'], 'tutar'=>$veri['tutar']];
        } catch (Throwable $eI) {
            $iSonuc[] = ['ad'=>$orjAd, 'durum'=>'hata', 'mesaj'=>$eI->getMessage()];
        }
    }
    $_SESSION['fat_islem'] = array_merge($_SESSION['fat_islem'] ?? [], $iSonuc);
    if (!empty($_POST['_yonlendir'])) redirect('fatura_eslestir.php?islem=1');   // ETTN çakışması "ayrı kaydet" akışı
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'islenen'    => count($iSonuc),
        'kaydedilen' => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'kaydedildi')),
        'zaten'      => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'zaten')),
        'hata'       => count(array_filter($iSonuc, fn($r) => $r['durum'] === 'hata')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$islemSonuc = null;
if (isset($_GET['islem']) && !empty($_SESSION['fat_islem'])) {
    $islemSonuc = $_SESSION['fat_islem'];
    unset($_SESSION['fat_islem']);
}

// ── 2c) m³ elle düzeltme (Kayıtlı Faturalar listesinden) ────────────────────
/** Listeden yapılan işlemden sonra Kayıtlı Faturalar'a SÜZGEÇ korunarak dönülür (yalnız bilinen anahtarlar). */
function fat_liste_geri(): string {
    parse_str((string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY), $q);
    $q = array_intersect_key((array)$q, array_flip(['ted', 'bas', 'bit', 'tur', 'durum', 'fatura_ara']));
    $q = array_filter(array_map(fn($v) => is_string($v) ? $v : '', $q), fn($v) => $v !== '');
    return 'fatura_eslestir.php' . ($q ? '?' . http_build_query($q) : '') . '#kayitli-faturalar';
}

if (($_POST['action'] ?? '') === 'm3_duzelt' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    $fid = (int)$_POST['fatura_id'];
    $m3  = fat_sayi((string)($_POST['m3'] ?? ''));
    if ($m3 === null || $m3 < 0) {
        flash('error', 'Geçerli bir m³ değeri girin (örn. 250 veya 250,00).');
    } else {
        $pdo->prepare("UPDATE faturalar SET miktar_m3 = ? WHERE id = ?")->execute([$m3, $fid]);
        audit_log($pdo, 'faturalar', $fid, 'UPDATE', null, ['m3_elle' => $m3], current_user_id());
        flash('success', 'Fatura m³ değeri elle kaydedildi: ' . number_format($m3, 2, ',', '.') . ' m³.');
    }
    redirect(fat_liste_geri());
}

// ── 2e) Fatura türü değiştirme (Kayıtlı Faturalar listesinden) ──────────────
// "Bu fatura beton teslimi değil, pompa kirası" — tek tıkla işaretlenir; fatura
// o andan itibaren irsaliye/m³ mutabakatının dışında kalır.
if (($_POST['action'] ?? '') === 'tur_degistir' && ctype_digit((string)($_POST['fatura_id'] ?? ''))) {
    $fid = (int)$_POST['fatura_id'];
    $tur = fat_tur_norm($_POST['tur'] ?? null);
    $f = $pdo->prepare("SELECT fatura_no, tur, eksik_adet FROM faturalar WHERE id = ?"); $f->execute([$fid]);
    $f = $f->fetch();
    if (!$f) { flash('error', 'Fatura bulunamadı.'); redirect('fatura_eslestir.php'); }

    if (fat_tur_irsaliyeli($tur)) {
        $pdo->prepare("UPDATE faturalar SET tur = ? WHERE id = ?")->execute([$tur, $fid]);
        $ek = ' Beton faturası olarak mutabakata geri alındı — eksik irsaliyeleri görmek için "tara" düğmesini kullanın.';
    } else {
        // İrsaliyesiz türde "eksik irsaliye" kavramı yoktur; sayaç temizlenir.
        $pdo->prepare("UPDATE faturalar SET tur = ?, eksik_adet = 0, eksik_liste = NULL WHERE id = ?")->execute([$tur, $fid]);
        $ek = ' Bu fatura artık m³ / eksik irsaliye mutabakatına girmez.';
    }
    audit_log($pdo, 'faturalar', $fid, 'UPDATE', ['tur' => $f['tur']], ['tur' => $tur], current_user_id());
    flash('success', h($f['fatura_no']) . ' → tür: ' . FAT_TUR[$tur][0] . '.' . $ek);
    redirect(($_POST['geri'] ?? '') === 'eksik' ? 'fatura_eslestir.php?eksik=1' : fat_liste_geri());
}

// ── 3) Fatura sil (bağları çöz) ─────────────────────────────────────────────
if (is_admin() && isset($_GET['sil']) && ctype_digit((string)$_GET['sil'])) {
    $fid = (int)$_GET['sil'];
    try {
        $pdo->prepare("UPDATE irsaliyeler SET fatura_id = NULL WHERE fatura_id = ?")->execute([$fid]);
        $pdo->prepare("DELETE FROM faturalar WHERE id = ?")->execute([$fid]);
        audit_log($pdo, 'faturalar', $fid, 'DELETE');
        flash('success', 'Fatura kaydı silindi, irsaliye bağları çözüldü.');
    } catch (Throwable $e) { flash('error', 'Silme hatası: '.$e->getMessage()); }
    redirect(fat_liste_geri());
}

// Dashboard'daki "faturada var ama sistemde yok" kartından gelinirse eksikleri özetle
$eksikOzet = [];
if (isset($_GET['eksik'])) {
    try {
        foreach ($pdo->query("SELECT id, fatura_no, tarih, eksik_adet, eksik_liste, dosya_url FROM faturalar
                              WHERE eksik_adet > 0 AND tur NOT IN ('" . implode("','", FAT_TUR_IRSALIYESIZ) . "')
                              ORDER BY tarih DESC") as $r) {
            $liste = json_decode((string)$r['eksik_liste'], true);
            $eksikOzet[] = ['id' => (int)$r['id'], 'fatura_no' => $r['fatura_no'], 'tarih' => $r['tarih'],
                            'adet' => (int)$r['eksik_adet'], 'liste' => is_array($liste) ? $liste : [],
                            'dosya' => (string)$r['dosya_url']];
        }
    } catch (Throwable $e) { $eksikOzet = []; }
}

$tedarikciler = $pdo->query("SELECT id, ad, vkn FROM tedarikciler ORDER BY ad")->fetchAll();
$fatAra = trim((string)($_GET['fatura_ara'] ?? ''));
$fatTur = (string)($_GET['tur'] ?? '');
$fatTur = isset(FAT_TUR[$fatTur]) ? $fatTur : '';
// ── KAYITLI FATURALAR: TÜMÜ + SÜZGEÇLER (2026-11-10, kullanıcı: "tüm faturaları görmek istiyorum,
//    firma / tarih arası filtreleme olsun") — liste eskiden aramasızken LIMIT 100 ile kesiliyordu.
//    Artık TÜM kayıtlar okunur; süzgeçler (tedarikçi · tarih aralığı · tür · durum · arama) PHP'de uygulanır
//    ve başlık rozeti SÜZÜLEN kümenin toplamını verir. Çift faturalama kontrolü süzgeçten BAĞIMSIZ (tümü).
$FAT_DURUM = ['eksik' => 'Eksik irsaliyeli', 'tam' => 'İrsaliyesi tam', 'taslak' => 'Taslak irsaliyeli',
              'bagsiz' => 'Hiç irsaliye bağlı değil', 'cift' => 'Çift faturalama şüpheli'];
$fatTed   = (int)($_GET['ted'] ?? 0);
$fatBas   = fat_tarih_norm((string)($_GET['bas'] ?? '')) ?: '';
$fatBit   = fat_tarih_norm((string)($_GET['bit'] ?? '')) ?: '';
$fatDurum = isset($FAT_DURUM[$_GET['durum'] ?? '']) ? (string)$_GET['durum'] : '';
$fatSuzgec = ($fatAra !== '' || $fatTur !== '' || $fatTed || $fatBas !== '' || $fatBit !== '' || $fatDurum !== '');
$kayitli = $pdo->query("SELECT f.*, t.ad AS tedarikci,
                               (SELECT COUNT(*) FROM irsaliyeler i WHERE i.fatura_id = f.id) AS bagli
                        FROM faturalar f LEFT JOIN tedarikciler t ON t.id = f.tedarikci_id
                        ORDER BY f.tarih DESC, f.id DESC")->fetchAll();
$fatTumAdet = count($kayitli);
// Tür bazlı sayaçlar (süzgeçten BAĞIMSIZ — her zaman tüm faturalar)
$turSayac = [];
foreach ($pdo->query("SELECT tur, COUNT(*) adet FROM faturalar GROUP BY tur") as $__ts) {
    $turSayac[fat_tur_norm($__ts['tur'])] = ($turSayac[fat_tur_norm($__ts['tur'])] ?? 0) + (int)$__ts['adet'];
}
// Süzgeçte yalnız faturası olan tedarikçiler (adet ile)
$fatTedler = [];
foreach ($kayitli as $__f) {
    $__tid = (int)$__f['tedarikci_id'];
    if (!$__tid) continue;
    $fatTedler[$__tid] ??= ['ad' => (string)$__f['tedarikci'], 'adet' => 0];
    $fatTedler[$__tid]['adet']++;
}
uasort($fatTedler, fn($x, $y) => strcmp($x['ad'], $y['ad']));
// Her faturanın bağlı irsaliyeleri — listede satır açılınca gösterilir
$kayitliIrs = $kayitli ? fat_bagli_irsaliyeler($pdo, array_column($kayitli, 'id')) : [];
// Çift faturalama kontrolü — TÜM faturalar (süzgeçten ve aramadan bağımsız)
$cift = fat_cift_kontrol($pdo);

// Yapısal süzgeçler (tür · tedarikçi · tarih · durum)
$kayitli = array_values(array_filter($kayitli, function ($f) use ($fatTur, $fatTed, $fatBas, $fatBit, $fatDurum, $kayitliIrs, $cift) {
    if ($fatTur !== '' && fat_tur_norm($f['tur'] ?? 'beton') !== $fatTur) return false;
    if ($fatTed && (int)$f['tedarikci_id'] !== $fatTed) return false;
    $t = (string)($f['tarih'] ?? '');
    if ($fatBas !== '' && ($t === '' || $t < $fatBas)) return false;
    if ($fatBit !== '' && ($t === '' || $t > $fatBit)) return false;
    if ($fatDurum === '') return true;
    $beton = fat_tur_irsaliyeli($f['tur'] ?? 'beton');
    return match ($fatDurum) {
        'eksik'  => $beton && (int)$f['eksik_adet'] > 0,
        'tam'    => $beton && (int)$f['eksik_adet'] === 0 && (int)$f['bagli'] > 0,
        'taslak' => count(array_filter($kayitliIrs[(int)$f['id']] ?? [], 'irs_taslak_mi')) > 0,
        'bagsiz' => $beton && (int)$f['bagli'] === 0,
        'cift'   => isset($cift['fid'][(int)$f['id']]),
        default  => true,
    };
}));

// ── Fatura arama: no / ETTN / tedarikçi / bağlı-eksik irsaliye no (NORMALİZE — "12047"
//    yazınca SKB2026000012047'yi de bulur; biçim farkı aramayı kaçırmaz) ────────────────
if ($fatAra !== '') {
    $araU = mb_strtoupper($fatAra, 'UTF-8');
    $araN = fat_irs_norm($fatAra);                 // ANM2026-4710 ↔ ANM2026000004710
    $araS = preg_replace('/\D/', '', $fatAra);     // yalnız rakam: numaranın içinde geçsin yeter
    $kayitli = array_values(array_filter($kayitli, function ($f) use ($kayitliIrs, $araU, $araN, $araS) {
        foreach ([$f['fatura_no'], $f['ettn'], $f['tedarikci']] as $alan) {
            if ($alan !== null && mb_stripos((string)$alan, $araU) !== false) return true;
        }
        if ($araN !== '' && fat_irs_norm((string)$f['fatura_no']) === $araN) return true;
        $adaylar = array_map(fn($r) => (string)$r['irsaliye_no'], $kayitliIrs[(int)$f['id']] ?? []);
        foreach ((array)(json_decode((string)($f['eksik_liste'] ?? ''), true) ?: []) as $e) $adaylar[] = (string)$e;
        foreach ($adaylar as $no) {
            if (mb_stripos($no, $araU) !== false) return true;
            if ($araN !== '' && fat_irs_norm($no) === $araN) return true;
            if ($araS !== '' && strlen($araS) >= 3 && str_contains(preg_replace('/\D/', '', $no), $araS)) return true;
        }
        return false;
    }));
}

require_once __DIR__ . '/includes/header.php';
$fmt = fn($n,$d=2) => number_format((float)$n, $d, ',', '.');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Fatura Eşleştirme</h4>
        <small class="text-muted">Tedarikçi faturasındaki irsaliye listesini sistemdeki kayıtlarla karşılaştırın</small>
    </div>
</div>

<?php foreach(['success','error','warning','info'] as $t): $m=get_flash($t); if($m): ?>
<div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($m) ?></div>
<?php endif; endforeach; ?>
<?php if ($hata): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($hata) ?></div><?php endif; ?>

<?php if (isset($_GET['eksik'])): ?>
<div class="card mb-4 border-danger">
    <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Faturada Olup Sistemde Olmayan İrsaliyeler</div>
    <div class="card-body">
    <?php if (!$eksikOzet): ?>
        <div class="text-success"><i class="bi bi-check-circle me-1"></i>Eksik irsaliyesi olan fatura yok.</div>
    <?php else: ?>
        <?php $eListeli = count(array_filter($eksikOzet, fn($e) => !empty($e['liste']))); ?>
        <?php if ($eListeli > 1): ?>
        <form method="post" class="mb-3 pb-3 border-bottom d-flex align-items-center gap-2 flex-wrap"
              onsubmit="return confirm('<?= $eListeli ?> faturanın TÜM eksik irsaliyeleri taslak olarak açılıp faturalarına bağlanacak. Devam edilsin mi?')">
            <input type="hidden" name="action" value="eksik_taslak">
            <input type="hidden" name="fatura_id" value="hepsi">
            <button class="btn btn-warning btn-sm"><i class="bi bi-files me-1"></i><?= $eListeli ?> faturanın eksiklerini taslak olarak aç</button>
            <span class="small text-muted"><?= array_sum(array_map(fn($e) => count($e['liste']), $eksikOzet)) ?> numara · tedarikçisi olmayan fatura atlanır.</span>
        </form>
        <?php endif; ?>
    <?php foreach ($eksikOzet as $eo): ?>
        <div class="mb-3">
            <div class="fw-semibold"><code><?= h($eo['fatura_no']) ?></code>
                <span class="text-muted small"><?= h(format_date($eo['tarih'])) ?> · <?= (int)$eo['adet'] ?> eksik irsaliye</span></div>
            <?php if ($eo['liste']): ?>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach ($eo['liste'] as $n): ?>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= h($n) ?></span>
                <?php endforeach; ?>
            </div>
            <form method="post" class="mt-1 d-flex align-items-center gap-2 flex-wrap"
                  onsubmit="return confirm('<?= (int)$eo['adet'] ?> irsaliye TASLAK olarak açılıp bu faturaya bağlanacak. Devam edilsin mi?')">
                <input type="hidden" name="action" value="eksik_taslak">
                <input type="hidden" name="fatura_id" value="<?= (int)$eo['id'] ?>">
                <button class="btn btn-sm btn-warning py-0"><i class="bi bi-file-earmark-plus me-1"></i>Taslak olarak aç ve bağla</button>
                <span class="small text-muted">Sistemde artık varsa yeni kayıt açılmaz, yalnız bağlanır. Excel aktarımı taslağı gerçek verilerle günceller.</span>
            </form>
            <?php else: ?>
            <form method="post" class="mt-1 d-flex align-items-center gap-2 flex-wrap">
                <input type="hidden" name="action" value="eksik_tara">
                <input type="hidden" name="fatura_id" value="<?= (int)$eo['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-search me-1"></i>Eksik irsaliyeleri bul</button>
                <span class="small text-muted">Kayıtlı fatura dosyası yeniden okunur, eksik numaralar burada listelenir<?= $eo['dosya'] === '' ? ' — <strong>bu faturanın dosyası yok</strong>, yukarıdan yeniden yüklemek gerekir' : '' ?>.</span>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i> Fatura Yükle / Metin Yapıştır</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="coz">
            <div class="col-md-6">
                <label class="form-label">e-Fatura dosyası <span class="text-muted small">(PDF, JPG, PNG — en fazla 20 MB)</span></label>
                <input type="file" name="dosya" id="fatDosya" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <div class="form-text">Faturanın altındaki <strong>karekod</strong> otomatik okunur; metin katmanı yoksa AI devreye girer.</div>
                <input type="hidden" name="qr" id="fatQr">
                <div id="fatQrDurum" class="small mt-2"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">…veya fatura metnini yapıştırın</label>
                <textarea name="metin" class="form-control" rows="4" placeholder="Fatura No: SGA2026000000819&#10;Fatura Tarihi: 12-08-2026&#10;SKB2026000011503 SKB2026000011507 …"></textarea>
                <div class="form-text">Karekod içeriğini (<code>{"vkntckn":…}</code>) de buraya yapıştırabilirsiniz — otomatik tanınır.</div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Çözümle ve Eşleştir</button>
                <span class="text-muted small ms-2">Numara biçimi farkı otomatik giderilir: <code>ANM2026-4710</code> ↔ <code>ANM2026000004710</code></span>
            </div>
        </form>
    </div>
</div>

<!-- TOPLU FATURA KONTROLÜ: dosyalar kaydedilmez, yalnız "işlenmiş mi?" raporu üretilir -->
<div class="card mb-3 border-info">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1 text-info"></i> Toplu Fatura Kontrolü
        <span class="text-muted small fw-normal">— elinize 100 fatura mı geldi? Hepsini seçin, hangisi işlenmiş hangisi işlenmemiş tek raporda görün</span></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" id="kontrolForm">
            <input type="hidden" name="action" value="fatura_kontrol">
            <div class="col-md-7">
                <label class="form-label small">Fatura dosyaları <span class="text-muted">(çoklu seçim — PDF önerilir; dosyalar sisteme KAYDEDİLMEZ, yalnız kontrol edilir)</span></label>
                <input type="file" name="dosyalar[]" class="form-control form-control-sm" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xml" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-info btn-sm text-white" id="kontrolBtn"><i class="bi bi-search me-1"></i>Kontrol Et</button>
            </div>
            <div class="col-12 d-none" id="kntKutu">
                <div class="progress" style="height:20px"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" id="kntBar" style="width:0%">0%</div></div>
                <div class="small text-muted mt-1" id="kntDurum"></div>
            </div>
        </form>
        <div class="form-text">Numara önce <strong>dosya adından</strong> okunur (e-fatura dosyaları numarayla adlandırılır), bulunamazsa PDF içeriğinden çıkarılır; kayıtlı faturalarla <strong>no VEYA ETTN</strong> üzerinden normalize karşılaştırılır.</div>
    </div>
</div>

<?php if ($kontrolSonuc !== null):
    $kIslenmis   = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'islenmis');
    $kIslenmemis = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'islenmemis');
    $kDiger      = array_filter($kontrolSonuc, fn($r) => !in_array($r['durum'], ['islenmis','islenmemis'], true));
    $kCift       = array_filter($kontrolSonuc, fn($r) => !empty($r['uyari']));
    // Aynı kayıtlı faturaya düşen dosyalar (biri fatura no'suyla, biri irsaliye no'suyla adlandırılmış olabilir)
    $kIlkDosya = []; $kTekrar = [];
    foreach ($kontrolSonuc as $i => $r) {
        if ($r['durum'] !== 'islenmis' || empty($r['id'])) continue;
        if (isset($kIlkDosya[(int)$r['id']])) $kTekrar[$i] = $kIlkDosya[(int)$r['id']];
        else $kIlkDosya[(int)$r['id']] = (string)$r['ad'];
    } ?>
<div class="card mb-4 border-info">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-data me-1 text-info"></i> Kontrol Raporu
        <span class="badge bg-primary ms-1"><?= count($kontrolSonuc) ?> dosya</span>
        <?php if (!empty($kSecilen)): ?><span class="badge <?= $kSecilen === count($kontrolSonuc) ? 'bg-light text-dark border' : 'bg-danger' ?>" title="Bilgisayarda seçilen dosya sayısı"><?= $kSecilen ?> seçildi</span><?php endif; ?>
        <?php $kHataS = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'hata'); if ($kHataS): ?><span class="badge bg-dark"><i class="bi bi-exclamation-triangle me-1"></i><?= count($kHataS) ?> gönderilemedi / hata</span><?php endif; ?>
        <span class="badge bg-success"><?= count($kIslenmis) ?> işlenmiş</span>
        <?php if ($kTekrar): ?><span class="badge bg-info text-dark" title="Birden çok dosya aynı kayıtlı faturaya düşüyor"><?= count($kIlkDosya) ?> farklı fatura · <?= count($kTekrar) ?> tekrar dosya</span><?php endif; ?>
        <span class="badge bg-danger"><?= count($kIslenmemis) ?> işlenmemiş</span>
        <?php if ($kCift): ?><span class="badge bg-dark" title="Dosyadaki irsaliyeler sistemde BAŞKA bir faturaya bağlı"><i class="bi bi-exclamation-octagon me-1"></i><?= count($kCift) ?> çift faturalama şüphesi</span><?php endif; ?>
        <?php $kOkunamadi = array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'okunamadi'); if ($kOkunamadi): ?><span class="badge bg-secondary"><?= count($kOkunamadi) ?> numara okunamadı</span><?php endif; ?></div>
    <?php if (!empty($kSecilen) && $kSecilen !== count($kontrolSonuc)): ?>
    <div class="alert alert-danger small m-2 mb-0 py-2"><i class="bi bi-exclamation-octagon me-1"></i>
        <strong><?= $kSecilen ?> dosya seçildi, raporda <?= count($kontrolSonuc) ?> dosya var.</strong>
        Aradaki <?= abs($kSecilen - count($kontrolSonuc)) ?> dosya sunucudan cevap gelmeden kayboldu (sayfa kapatıldı ya da bağlantı koptu) — kontrolü yeniden çalıştırın.</div>
    <?php endif; ?>
    <?php if ($kHataS): ?>
    <div class="alert alert-warning small m-2 mb-0 py-2"><i class="bi bi-exclamation-triangle me-1"></i>
        <strong><?= count($kHataS) ?> dosya kontrol edilemedi</strong> — adları ve sebepleri tabloda koyu gri satırlarda. Bu dosyaları ayrıca (az sayıda) seçip yeniden kontrol edin;
        "sunucu boyut sınırı" diyorsa dosya çok büyüktür (sıkıştırın ya da tek tek "Fatura Yükle" ile işleyin).</div>
    <?php endif; ?>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Dosya</th><th>Fatura No</th><th>Durum</th><th>Sistemdeki Kayıt</th><th>Numara Kaynağı</th>
            </tr></thead>
            <tbody>
            <?php foreach ($kontrolSonuc as $kI => $r): ?>
                <tr class="<?= $r['durum'] === 'islenmemis' ? 'table-danger' : ($r['durum'] === 'islenmis' ? (isset($kTekrar[$kI]) ? 'table-info' : '') : ($r['durum'] === 'hata' ? 'table-dark' : 'table-secondary')) ?>">
                    <td class="small"><?= h($r['ad']) ?></td>
                    <td class="font-monospace small"><?= h((string)($r['no'] ?? '—')) ?></td>
                    <td>
                        <?php if ($r['durum'] === 'islenmis'): ?><span class="badge bg-success">✓ işlenmiş</span>
                            <?php if (isset($kTekrar[$kI])): ?><span class="badge bg-info text-dark" title="Listede daha önce geçen <?= h($kTekrar[$kI]) ?> ile AYNI fatura — ayrı kayıt sayılmaz">tekrar: <?= h($kTekrar[$kI]) ?></span><?php endif; ?>
                        <?php elseif ($r['durum'] === 'islenmemis'): ?><span class="badge bg-danger">✗ işlenmemiş</span>
                        <?php elseif ($r['durum'] === 'okunamadi'): ?><span class="badge bg-danger" title="Dosya adında ve PDF metninde numara bulunamadı (taranmış PDF olabilir) — ÇÖZÜMLE ve KAYDET içeriği AI ile okur">✗ numara okunamadı</span>
                        <?php else: ?><span class="badge bg-secondary text-wrap text-start" style="max-width:420px"><?= h($r['mesaj'] ?? 'hata') ?></span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($r['durum'] === 'islenmis'): ?>
                            <?= h(format_date($r['tarih'])) ?> · <?= (int)$r['bagli'] ?> irsaliye bağlı
                            <?php if ((int)$r['eksik'] > 0): ?><span class="badge bg-warning text-dark"><?= (int)$r['eksik'] ?> eksik</span><?php endif; ?>
                            · <a href="fatura_eslestir.php?fatura_ara=<?= urlencode((string)$r['no']) ?>">kaydı aç</a>
                        <?php elseif ($r['durum'] === 'islenmemis'): ?>
                            <span class="text-danger">sistemde yok — yukarıdaki "Fatura Yükle" ile işleyin</span>
                            <?php if (!empty($r['uyari'])): ?><div class="text-danger fw-semibold"><i class="bi bi-exclamation-octagon me-1"></i><?= h($r['uyari']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= h((string)($r['kaynak'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($kIslenmemis || array_filter($kontrolSonuc, fn($r) => $r['durum'] === 'okunamadi')): ?>
    <?php $islenebilir = array_values(array_filter($kontrolSonuc, fn($r) => in_array($r['durum'], ['islenmemis', 'okunamadi'], true) && !empty($r['dosya']))); ?>
    <div class="card-footer bg-white small">
        <strong class="text-danger">İşlenmemiş numaralar (kopyalanabilir):</strong>
        <code><?= h(implode(', ', array_map(fn($r) => (string)($r['no'] ?? $r['ad']), $kIslenmemis))) ?></code>
        <?php if ($islenebilir): ?>
        <div class="mt-2 pt-2 border-top">
            <button type="button" class="btn btn-success btn-sm" id="isleBtn"
                    data-dosyalar="<?= h(json_encode(array_map(fn($r) => ['d' => $r['dosya'], 'a' => $r['ad']], $islenebilir), JSON_UNESCAPED_UNICODE)) ?>">
                <i class="bi bi-cpu me-1"></i>İşlenmemiş <?= count($islenebilir) ?> faturayı ÇÖZÜMLE ve KAYDET
            </button>
            <span class="text-muted ms-2">Her fatura otomatik okunur, tedarikçisi bulunur/oluşturulur, irsaliyeleri bağlanır.</span>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="isleTaslak" checked>
                <label class="form-check-label" for="isleTaslak"><strong>Faturadaki TÜM irsaliyeler kayda geçsin</strong> —
                    sistemde olmayanlar <span class="badge bg-secondary">[FATURADAN]</span> taslak olarak açılıp faturaya bağlanır
                    <span class="text-muted">(zaten kayıtlı ya da önceden taslağı açılmış numara için yeni kayıt açılmaz; Excel aktarımı taslağı gerçek verilerle günceller)</span></label>
            </div>
            <div class="d-none mt-2" id="isleKutu">
                <div class="progress" style="height:20px"><div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="isleBar" style="width:0%">0%</div></div>
                <div class="small text-muted mt-1" id="isleDurum"></div>
                <div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i>AI okuma fatura başına birkaç saniye sürer — sayfayı kapatmayın.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script>
// İşlenmemişleri otomatik işle: dosyalar sunucuda bekliyor, 2'şerli partilerle çözümlenir (AI ağır iş)
(function () {
    var btn = document.getElementById('isleBtn');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        var dosyalar = JSON.parse(btn.dataset.dosyalar || '[]');
        if (!dosyalar.length) return;
        if (!confirm(dosyalar.length + ' fatura otomatik çözümlenip KAYDEDİLECEK. Devam edilsin mi?')) return;
        btn.disabled = true;
        document.getElementById('isleKutu').classList.remove('d-none');
        var bar = document.getElementById('isleBar'), durum = document.getElementById('isleDurum');
        var BATCH = 2, oz = {kaydedilen:0, zaten:0, hata:0};
        var csrfEl = document.querySelector('input[name=csrf]');
        for (var i = 0; i < dosyalar.length; i += BATCH) {
            var fd = new FormData();
            fd.append('action', 'fatura_isle');
            var tk = document.getElementById('isleTaslak');
            if (tk && tk.checked) fd.append('taslak', '1');
            if (csrfEl) fd.append('csrf', csrfEl.value);
            dosyalar.slice(i, i + BATCH).forEach(function (d) {
                if (typeof d === 'string') d = {d: d, a: d};
                fd.append('dosya[]', d.d); fd.append('ad[]', d.a || d.d);   // ad: rapor bilgisayardaki dosya adını göstersin
            });
            try {
                var j = await (await fetch('fatura_eslestir.php', { method: 'POST', body: fd })).json();
                oz.kaydedilen += j.kaydedilen || 0; oz.zaten += j.zaten || 0; oz.hata += j.hata || 0;
            } catch (err) { oz.hata += Math.min(BATCH, dosyalar.length - i); }
            var y = Math.round(100 * Math.min(i + BATCH, dosyalar.length) / dosyalar.length);
            bar.style.width = y + '%'; bar.textContent = y + '%';
            durum.textContent = Math.min(i + BATCH, dosyalar.length) + '/' + dosyalar.length + ' fatura işlendi — kaydedilen '
                              + oz.kaydedilen + (oz.zaten ? ', zaten işlenmiş ' + oz.zaten : '') + (oz.hata ? ', hata ' + oz.hata : '');
        }
        durum.textContent = 'Tamamlandı — işlem raporu açılıyor…';
        location.href = 'fatura_eslestir.php?islem=1';
    });
})();
</script>
<?php endif; ?>

<?php if ($islemSonuc !== null):
    $iKay = array_filter($islemSonuc, fn($r) => $r['durum'] === 'kaydedildi');
    $iZat = array_filter($islemSonuc, fn($r) => $r['durum'] === 'zaten');
    $iHat = array_filter($islemSonuc, fn($r) => $r['durum'] === 'hata');
    $iEksikli = array_filter($iKay, fn($r) => (int)($r['eksik'] ?? 0) > 0); ?>
<div class="card mb-4 border-success">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-cpu me-1 text-success"></i> Otomatik İşleme Raporu
        <span class="badge bg-success"><?= count($iKay) ?> kaydedildi</span>
        <?php if ($iZat): ?><span class="badge bg-secondary"><?= count($iZat) ?> zaten işlenmişti</span><?php endif; ?>
        <?php if ($iHat): ?><span class="badge bg-danger"><?= count($iHat) ?> işlenemedi</span><?php endif; ?></div>
    <div class="card-body p-0"><div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light" style="position:sticky;top:0"><tr>
                <th>Dosya</th><th>Fatura No</th><th>Durum</th><th>Tedarikçi</th><th class="text-end">m³</th><th class="text-end">Tutar</th>
                <th class="text-end">Bağlanan İrs.</th><th class="text-end">Taslak açılan</th><th class="text-end">Eksik</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($islemSonuc as $r): ?>
                <tr class="<?= $r['durum'] === 'hata' ? 'table-danger' : ($r['durum'] === 'zaten' ? 'table-secondary' : '') ?>">
                    <td class="small text-break" style="max-width:220px" title="Bilgisayarınızdaki dosya adı"><i class="bi bi-file-earmark-pdf text-muted me-1"></i><?= h((string)($r['ad'] ?? '')) ?></td>
                    <td class="font-monospace small"><?= h((string)($r['no'] ?? '—')) ?></td>
                    <td>
                        <?php if ($r['durum'] === 'kaydedildi'): ?><span class="badge bg-success">✓ kaydedildi</span>
                        <?php elseif ($r['durum'] === 'zaten'): ?><span class="badge bg-secondary">zaten işlenmiş</span>
                            <?php if (!empty($r['kayit']) && empty($r['cakisma'])): ?><span class="small text-muted">kayıt: <?= h($r['kayit']['no']) ?><?= ($r['kayit']['alan'] ?? '') === 'ettn' ? ' (ETTN ile)' : '' ?></span><?php endif; ?>
                            <?php if (!empty($r['cakisma'])): $k = $r['kayit']; $pd = $r['pdf']; ?>
                            <div class="alert alert-warning small py-2 px-2 mt-1 mb-0" style="min-width:420px">
                                <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>ETTN çakışması — aynı ETTN başka numarayla kayıtlı</div>
                                <table class="table table-sm table-borderless mb-1 small">
                                    <tr><td class="text-muted">Bu PDF</td><td class="font-monospace"><?= h((string)$r['no']) ?></td><td><?= h(format_date($pd['tarih'] ?? null)) ?></td><td class="text-end"><?= isset($pd['tutar']) && $pd['tutar'] !== null ? $fmt($pd['tutar']) . ' ₺' : '—' ?></td></tr>
                                    <tr><td class="text-muted">Sistemdeki kayıt</td><td class="font-monospace"><a href="fatura_eslestir.php?fatura_ara=<?= urlencode($k['no']) ?>"><?= h($k['no']) ?></a></td><td><?= h(format_date($k['tarih'] ?? null)) ?></td><td class="text-end"><?= $k['tutar'] !== null ? $fmt($k['tutar']) . ' ₺' : '—' ?></td></tr>
                                </table>
                                <div class="text-muted mb-1">Tarih ve tutar aynıysa aynı faturadır, kayıttaki numara yanlış yazılmıştır. Farklıysa eski kayda yanlış ETTN girilmiştir.</div>
                                <?php if (can_edit()): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Kayıtlı <?= h($k['no']) ?> faturasının numarası <?= h((string)$r['no']) ?> olarak değiştirilsin mi?')">
                                        <input type="hidden" name="action" value="ettn_no_duzelt"><input type="hidden" name="fatura_id" value="<?= (int)$k['id'] ?>">
                                        <input type="hidden" name="yeni_no" value="<?= h((string)$r['no']) ?>"><input type="hidden" name="dosya" value="<?= h((string)$r['dosya']) ?>">
                                        <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check2 me-1"></i>Aynı fatura — numarayı <?= h((string)$r['no']) ?> yap</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('<?= h($k['no']) ?> kaydının ETTN\'i silinecek ve bu PDF ayrı fatura olarak kaydedilecek. Devam edilsin mi?')">
                                        <input type="hidden" name="action" value="ettn_ayir"><input type="hidden" name="fatura_id" value="<?= (int)$k['id'] ?>">
                                        <input type="hidden" name="dosya_adi" value="<?= h((string)($r['ad'] ?? '')) ?>">
                                        <input type="hidden" name="dosya" value="<?= h((string)$r['dosya']) ?>">
                                        <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-files me-1"></i>Farklı fatura — ayrı kaydet</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?><span class="badge bg-danger" title="<?= h((string)($r['mesaj'] ?? '')) ?>">✗ işlenemedi</span> <span class="small text-danger"><?= h((string)($r['mesaj'] ?? '')) ?></span><?php endif; ?>
                    </td>
                    <td class="small"><?= h((string)($r['ted'] ?? '')) ?><?= !empty($r['ted_yeni']) ? ' <span class="badge bg-info text-dark">yeni oluşturuldu</span>' : '' ?></td>
                    <td class="text-end font-monospace"><?= isset($r['m3']) && $r['m3'] !== null ? $fmt($r['m3']) : '—' ?></td>
                    <td class="text-end font-monospace"><?= isset($r['tutar']) && $r['tutar'] !== null ? $fmt($r['tutar']) . ' ₺' : '—' ?></td>
                    <td class="text-end"><?= isset($r['bagli']) ? (int)$r['bagli'] : '—' ?><?= isset($r['belge']) && $r['belge'] ? ' <i class="bi bi-paperclip small text-muted" title="fatura ' . (int)$r['belge'] . ' irsaliyenin belgelerine eklendi"></i>' : '' ?></td>
                    <td class="text-end"><?= !empty($r['taslak']) ? '<span class="badge bg-warning text-dark">' . (int)$r['taslak'] . ' taslak</span>' : (isset($r['bagli']) ? '0' : '—') ?><?= !empty($r['taslak_uyari']) ? '<div class="small text-danger">' . h($r['taslak_uyari']) . '</div>' : '' ?><?= !empty($r['baskada']) ? '<div class="small text-danger" title="' . h(implode("\n", $r['baskada'])) . '"><i class="bi bi-exclamation-triangle"></i> ' . count($r['baskada']) . ' irsaliye başka faturada — bağlanmadı</div>' : '' ?></td>
                    <td class="text-end <?= (int)($r['eksik'] ?? 0) > 0 ? 'text-danger fw-bold' : '' ?>"><?= isset($r['eksik']) ? (int)$r['eksik'] : '—' ?></td>
                    <td><?php $acNo = (string)($r['kayit']['no'] ?? $r['no'] ?? ''); if ($acNo !== ''): ?><a href="fatura_eslestir.php?fatura_ara=<?= urlencode($acNo) ?>" class="small">kaydı aç</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
    <?php if ($iEksikli): ?>
    <div class="card-footer bg-white small">
        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
        <strong><?= count($iEksikli) ?> faturanın</strong> bazı irsaliyeleri sistemde bulunamadı (eksik) — bu numaralar
        <a href="fatura_eslestir.php?eksik=1">Eksik İrsaliyeler panelinde</a> listelenir; irsaliyeler girildikten sonra
        "yeniden tara" ile bağ kurulur, ya da o paneldeki <strong>"Taslak olarak aç ve bağla"</strong> düğmesiyle tek tıkla taslak açılır.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script src="<?= h(($rootPath ?? '') . 'assets/js/toplu_kontrol.js') ?>?v=1"></script>
<script>
// Toplu kontrol: partiler adet + BOYUT sınırıyla kurulur, düşen parti dosya dosya yeniden denenir,
// gönderilemeyen dosya sebebiyle rapora yazılır (seçilen = rapordaki dosya sayısı).
ERN_TOPLU_KONTROL.kur({form: document.getElementById('kontrolForm'), url: 'fatura_eslestir.php',
    sinir: <?= json_encode(fat_yukleme_sinir()) ?>});
</script>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script>if (typeof pdfjsLib !== 'undefined') pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';</script>
<script>
// ── Karekod (GİB e-Fatura QR) tarayıcı ───────────────────────────────────────
// Fatura seçilir seçilmez tarayıcıda okunur; sunucuya JSON olarak gider.
(function () {
    var dosyaEl = document.getElementById('fatDosya');
    var qrEl    = document.getElementById('fatQr');
    var durumEl = document.getElementById('fatQrDurum');
    if (!dosyaEl) return;

    function durum(sinif, ikon, mesaj) {
        durumEl.className = 'small mt-2 text-' + sinif;
        durumEl.innerHTML = '<i class="bi ' + ikon + ' me-1"></i>' + mesaj;
    }

    function kontrastArtir(img) {
        var s = img.data, d = new Uint8ClampedArray(s.length);
        for (var i = 0; i < s.length; i += 4) {
            var g = 0.299 * s[i] + 0.587 * s[i + 1] + 0.114 * s[i + 2];
            var v = Math.max(0, Math.min(255, 2.2 * (g - 128) + 128));
            d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255;
        }
        return new ImageData(d, img.width, img.height);
    }

    // e-Fatura karekodu mu? (JSON + 'no'/'ettn' alanı)
    function faturaQrMi(metin) {
        if (!metin || metin.indexOf('{') < 0) return false;
        try {
            var j = JSON.parse(metin.substring(metin.indexOf('{'), metin.lastIndexOf('}') + 1));
            return !!(j && (j.no || j.ettn));
        } catch (e) { return false; }
    }

    async function canvasTara(canvas, ctx) {
        if ('BarcodeDetector' in window) {
            try {
                var det = new BarcodeDetector({ formats: ['qr_code'] });
                var bulunan = await det.detect(canvas);
                for (var i = 0; i < bulunan.length; i++) {
                    if (faturaQrMi(bulunan[i].rawValue)) return bulunan[i].rawValue;
                }
            } catch (e) { /* jsQR'a düş */ }
        }
        if (typeof jsQR === 'undefined') return null;
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var r = jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
        if (r && faturaQrMi(r.data)) return r.data;
        var k = kontrastArtir(img);
        r = jsQR(k.data, k.width, k.height, { inversionAttempts: 'attemptBoth' });
        return (r && faturaQrMi(r.data)) ? r.data : null;
    }

    async function pdfTara(buf) {
        if (typeof pdfjsLib === 'undefined') return null;
        var pdf = await pdfjsLib.getDocument({ data: buf }).promise;
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        var sayfaSayisi = Math.min(pdf.numPages, 3);
        var olcekler = [2.5, 4.0];
        for (var s = 1; s <= sayfaSayisi; s++) {
            var page = await pdf.getPage(s);
            for (var o = 0; o < olcekler.length; o++) {
                var vp = page.getViewport({ scale: olcekler[o] });
                canvas.width = vp.width; canvas.height = vp.height;
                await page.render({ canvasContext: ctx, viewport: vp }).promise;
                var v = await canvasTara(canvas, ctx);
                if (v) return v;
            }
        }
        return null;
    }

    function gorselTara(dataUrl) {
        return new Promise(function (cozumle) {
            var im = new Image();
            im.onload = async function () {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d', { willReadFrequently: true });
                var buyut = Math.min(3, Math.max(1, 1600 / Math.max(im.width, im.height)));
                canvas.width = im.width * buyut; canvas.height = im.height * buyut;
                ctx.drawImage(im, 0, 0, canvas.width, canvas.height);
                cozumle(await canvasTara(canvas, ctx));
            };
            im.onerror = function () { cozumle(null); };
            im.src = dataUrl;
        });
    }

    dosyaEl.addEventListener('change', async function () {
        qrEl.value = '';
        var f = dosyaEl.files && dosyaEl.files[0];
        if (!f) { durumEl.innerHTML = ''; return; }
        durum('muted', 'bi-hourglass-split', 'Karekod aranıyor…');
        try {
            var deger = null;
            if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)) {
                deger = await pdfTara(await f.arrayBuffer());
            } else {
                deger = await gorselTara(URL.createObjectURL(f));
            }
            if (deger) {
                qrEl.value = deger;
                var j = JSON.parse(deger.substring(deger.indexOf('{'), deger.lastIndexOf('}') + 1));
                durum('success', 'bi-qr-code-scan',
                      'Karekod okundu: <strong>' + (j.no || '?') + '</strong> · ' + (j.tarih || '?') +
                      ' · ödenecek <strong>' + (j.odenecek || '?') + '</strong>');
            } else {
                durum('warning', 'bi-exclamation-triangle',
                      'Karekod bulunamadı — fatura yine de metinden okunacak. Karekod içeriğini elle yapıştırabilirsiniz.');
            }
        } catch (e) {
            durum('warning', 'bi-exclamation-triangle', 'Karekod taranamadı: ' + e.message);
        }
    });
})();
</script>

<?php if ($sonuc):
    $v = $sonuc['veri']; $e = $sonuc['eslesme'];
    $turOner    = $sonuc['tur_oner'] ?? ['tur'=>'beton','gerekce'=>''];
    $seciliTur  = fat_tur_norm($turOner['tur']);
    $turIrs     = fat_tur_irsaliyeli($seciliTur);
    $toplamIrs = count($v['irsaliyeler']);
    $eslesenAdet = count($e['eslesen']); $eksikAdet = count($e['eksik']);
    $farkM3 = ($v['miktar'] !== null) ? ($e['ozet']['miktar'] - (float)$v['miktar']) : null;
?>
<?php if (!$turIrs): ?>
<div class="alert alert-info d-flex gap-2">
    <i class="bi <?= h(FAT_TUR[$seciliTur][2]) ?> fs-4"></i>
    <div>
        <strong>Bu fatura beton teslimi gibi görünmüyor — önerilen tür: <?= h(FAT_TUR[$seciliTur][0]) ?></strong>
        <div class="small mt-1">
            <?= h((string)$turOner['gerekce']) ?>
            <?= h(FAT_TUR[$seciliTur][3]) ?>
            Yanlışsa aşağıdaki <strong>Fatura Türü</strong> alanından değiştirin.
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3" data-beton-mutabakat>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Faturadaki İrsaliye</div><div class="fs-5 fw-bold"><?= $toplamIrs ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Eşleşen</div><div class="fs-5 fw-bold text-success"><?= $eslesenAdet ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100 <?= $eksikAdet?'border border-danger':'' ?>"><div class="card-body py-2"><div class="text-muted small">Sistemde Yok</div><div class="fs-5 fw-bold <?= $eksikAdet?'text-danger':'text-success' ?>"><?= $eksikAdet ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Sistem m³ (eşleşen)</div><div class="fs-5 fw-bold"><?= $fmt($e['ozet']['miktar']) ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Fatura m³</div><div class="fs-5 fw-bold"><?= $v['miktar']!==null ? $fmt($v['miktar']) : '—' ?></div></div></div></div>
    <div class="col-6 col-lg"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="text-muted small">Fark</div>
        <div class="fs-5 fw-bold <?= ($farkM3===null)?'text-muted':(abs($farkM3)<0.01?'text-success':'text-danger') ?>"><?= $farkM3===null?'—':$fmt($farkM3) ?></div></div></div></div>
</div>

<?php if ($sonuc['kaynak'] === 'ai'): ?>
<div class="alert alert-info py-2 small"><i class="bi bi-stars me-1"></i>
    Fatura <strong>AI ile okundu</strong>. Aşağıdaki alanları faturayla karşılaştırıp gerekirse düzeltin.
</div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="action" value="kaydet">
<input type="hidden" name="dosya_url" value="<?= h((string)$sonuc['dosya_url']) ?>">
<input type="hidden" name="eksik_adet" value="<?= $eksikAdet ?>">
<input type="hidden" name="eksik_liste" value="<?= h(json_encode($e['eksik'], JSON_UNESCAPED_UNICODE)) ?>">
<input type="hidden" name="irsaliye_liste" value="<?= h(json_encode(array_values((array)($v['irsaliyeler'] ?? [])), JSON_UNESCAPED_UNICODE)) ?>">

<?php
$mevcut    = $sonuc['mevcut']  ?? null;
$baskasi   = $sonuc['baskasi'] ?? [];
$mevcutIrs = $sonuc['mevcut_irs'] ?? [];
// Şimdi eşleşenlerin hangileri bu faturaya ZATEN bağlı, hangileri yeni?
$mevcutIrsId = array_map(fn($r) => (int)$r['id'], $mevcutIrs);
$yeniBag = $eskiBag = 0;
foreach ($e['eslesen'] as $r) { in_array((int)$r['id'], $mevcutIrsId, true) ? $eskiBag++ : $yeniBag++; }
?>
<?php if ($mevcut): ?>
<div class="alert alert-warning">
    <h6 class="alert-heading"><i class="bi bi-files me-1"></i>Bu fatura sistemde ZATEN İŞLENMİŞ</h6>
    <div class="small">
        <strong><?= h($mevcut['fatura_no']) ?></strong> · <?= h(format_date($mevcut['tarih'])) ?>
        <?= $mevcut['tedarikci'] ? ' · '.h($mevcut['tedarikci']) : '' ?>
        · <strong><?= (int)$mevcut['bagli'] ?></strong> irsaliye bağlı
        · <?= h(format_date($mevcut['created_at'])) ?> tarihinde kaydedilmiş
        <?php if ($mevcut['eslesme_alani'] === 'ettn'): ?>
        <div class="mt-1"><i class="bi bi-info-circle me-1"></i>Fatura numarası farklı ama <strong>ETTN aynı</strong> —
            aynı e-faturanın başka numarayla girilmiş hali. ETTN faturanın değişmez kimliğidir.</div>
        <?php endif; ?>

        <?php if ($mevcutIrs): ?>
        <div class="mt-3 fw-semibold">Bu faturaya bağlı irsaliyeler (<?= count($mevcutIrs) ?>):</div>
        <div class="table-responsive mt-1">
            <table class="table table-sm table-bordered bg-body mb-1" style="max-width:820px">
                <thead class="table-light"><tr>
                    <th>İrsaliye No</th><th>Tarih</th><th>Plaka</th><th>Beton</th>
                    <th class="text-end">m³</th><th>Durum</th><th>Şimdiki faturada</th>
                </tr></thead>
                <tbody>
                <?php $mToplam = 0.0; foreach ($mevcutIrs as $mi): $mToplam += (float)$mi['miktar'];
                      $halaVar = in_array((int)$mi['id'], array_map(fn($x)=>(int)$x['id'], $e['eslesen']), true); ?>
                    <tr class="<?= $halaVar ? '' : 'table-warning' ?>">
                        <td><a href="irsaliye_detay.php?id=<?= (int)$mi['id'] ?>" target="_blank"><code><?= h($mi['irsaliye_no']) ?></code></a></td>
                        <td><?= h(format_date($mi['tarih'])) ?></td>
                        <td><?= h((string)$mi['arac_plaka']) ?></td>
                        <td><?= h((string)$mi['beton_sinifi']) ?></td>
                        <td class="text-end"><?= $fmt($mi['miktar']) ?></td>
                        <td><?= h($mi['durum']) ?></td>
                        <td><?= $halaVar ? '<span class="text-success">✓ var</span>'
                                         : '<span class="text-danger fw-bold">✗ yok — bağ kopacak</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold"><tr>
                    <td colspan="4" class="text-end">Toplam</td><td class="text-end"><?= $fmt($mToplam) ?></td><td colspan="2"></td>
                </tr></tfoot>
            </table>
        </div>
        <?php endif; ?>

        <div class="mt-2">
            <?php if ($yeniBag === 0 && $eskiBag > 0): ?>
                <i class="bi bi-check-circle-fill text-success me-1"></i>
                <strong>Değişiklik yok</strong> — şu an eşleşen <?= $eskiBag ?> irsaliyenin hepsi zaten bu faturaya bağlı.
                Tekrar kaydetmene gerek yok.
            <?php else: ?>
                Kaydedersen <strong>yeni kayıt oluşmaz</strong>, mevcut kayıt güncellenir:
                <strong><?= $yeniBag ?></strong> yeni bağ kurulur,
                <strong><?= $eskiBag ?></strong> bağ zaten var.
            <?php endif; ?>
            <a href="irsaliyeler.php?tip=tum&fatura_id=<?= (int)$mevcut['id'] ?>" class="alert-link ms-1">İrsaliye listesinde aç</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($baskasi): ?>
<div class="alert alert-danger">
    <h6 class="alert-heading"><i class="bi bi-exclamation-octagon-fill me-1"></i><?= count($baskasi) ?> irsaliye BAŞKA bir faturaya bağlı</h6>
    <div class="small">
        Aynı irsaliyenin iki faturada görünmesi <strong>çift faturalandırma</strong> anlamına gelebilir.
        Kaydedersen bu irsaliyeler eski faturadan kopar ve bu faturaya bağlanır.
        <ul class="mb-0 mt-1">
            <?php foreach ($baskasi as $b): ?>
            <li><code><?= h($b['irsaliye_no']) ?></code> → şu an
                <a href="irsaliyeler.php?tip=tum&fatura_id=<?= (int)$b['fatura_id'] ?>" class="alert-link"><?= h($b['fatura_no']) ?></a> faturasında</li>
            <?php endforeach; ?>
        </ul>
        <div class="mt-2">Taşınmasını istemediğin satırların işaretini aşağıdaki listeden kaldır.</div>
    </div>
</div>
<?php endif; ?>

<?php $qr = $v['qr'] ?? null; $qrTed = $qr ? fat_tedarikci_bul($pdo, $qr['vkn'] ?? null) : null; ?>
<?php if ($qr): ?>
<div class="card mb-3 border-success">
    <div class="card-header bg-white fw-semibold text-success"><i class="bi bi-qr-code-scan me-1"></i> Karekod Okundu — faturanın kendi beyanı</div>
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-md-3"><span class="text-muted">Fatura No</span><div class="fw-bold"><?= h((string)$qr['fatura_no']) ?></div></div>
            <div class="col-md-2"><span class="text-muted">Tarih</span><div class="fw-bold"><?= h(format_date($qr['tarih'])) ?></div></div>
            <div class="col-md-3"><span class="text-muted">Satıcı VKN</span>
                <div class="fw-bold"><?= h((string)$qr['vkn']) ?>
                    <?php if ($qrTed): ?><span class="badge bg-success ms-1"><?= h($qrTed['ad']) ?></span>
                    <?php else: ?><span class="badge bg-warning text-dark ms-1">tanımlı değil</span><?php endif; ?>
                </div></div>
            <div class="col-md-2"><span class="text-muted">Tip</span><div class="fw-bold"><?= h((string)$qr['tip']) ?></div></div>
            <div class="col-md-2"><span class="text-muted">Ödenecek</span><div class="fw-bold"><?= $qr['tutar']!==null?$fmt($qr['tutar']).' ₺':'—' ?></div></div>
            <?php if ($qr['matrah'] !== null): ?>
            <div class="col-md-3"><span class="text-muted">Mal/Hizmet Toplam</span><div><?= $fmt($qr['matrah']) ?> ₺</div></div>
            <?php endif; ?>
            <?php if ($qr['tevkifat'] !== null): ?>
            <div class="col-md-3"><span class="text-muted">KDV Tevkifat</span><div><?= $fmt($qr['tevkifat']) ?> ₺</div></div>
            <?php endif; ?>
            <div class="col-md-6"><span class="text-muted">ETTN</span><div class="text-break"><code><?= h((string)$qr['ettn']) ?></code></div></div>
        </div>
        <?php if (!$qrTed && !empty($qr['vkn'])): ?>
        <div class="alert alert-warning py-2 small mt-3 mb-0">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= h($qr['vkn']) ?></strong> VKN'si hiçbir tedarikçide kayıtlı değil — aşağıdaki
            <strong>"otomatik oluştur"</strong> kutusunu işaretli bırakın (kaydetmede VKN'siyle açılır) ya da tedarikçiyi elle seçin.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($v['uyari'])): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Karekod ile fatura metni çelişiyor</strong>
    <ul class="mb-0 mt-1 small"><?php foreach ($v['uyari'] as $u): ?><li><?= h($u) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text me-1"></i> Fatura Bilgileri</div>
    <div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Fatura No</label>
            <input type="text" name="fatura_no" class="form-control" value="<?= h((string)$v['fatura_no']) ?>" required></div>
        <div class="col-md-2"><label class="form-label">Tarih</label>
            <input type="date" name="tarih" class="form-control" value="<?= h((string)$v['tarih']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Tedarikçi</label>
            <?php
            // Öncelik: karekot/metin VKN (kesin) → satıcı unvanı (normalize ad) → eşleşen irsaliyelerin tedarikçisi
            $saticiVkn   = preg_replace('/\D/', '', (string)($v['satici_vkn'] ?? ''));
            $saticiUnvan = trim((string)($v['satici_unvan'] ?? ''));
            $onerTed = $qrTed ?: ($saticiVkn !== '' ? fat_tedarikci_bul($pdo, $saticiVkn) : null);
            if (!$onerTed && $saticiUnvan !== '') $onerTed = fat_tedarikci_bul_ad($pdo, $saticiUnvan);
            $onerId = $onerTed ? (int)$onerTed['id'] : 0;
            if (!$onerId && $e['eslesen']) {
                $ilk = $e['eslesen'][0];
                foreach ($tedarikciler as $td) if ($td['ad'] === $ilk['tedarikci']) $onerId = (int)$td['id'];
            }
            ?>
            <select name="tedarikci_id" class="form-select">
                <option value="">— seçiniz —</option>
                <?php foreach ($tedarikciler as $td): ?>
                    <option value="<?= (int)$td['id'] ?>" <?= $onerId===(int)$td['id']?'selected':'' ?>><?= h($td['ad']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label">Ödenecek Tutar</label>
            <input type="text" name="tutar" class="form-control" value="<?= $v['tutar']!==null?$fmt($v['tutar']):'' ?>"></div>
        <div class="col-md-2"><label class="form-label">Miktar (m³)</label>
            <input type="text" name="miktar" class="form-control" value="<?= $v['miktar']!==null?$fmt($v['miktar']):'' ?>"></div>
        <div class="col-md-3"><label class="form-label">Fatura Türü</label>
            <select name="tur" id="faturaTur" class="form-select">
                <?php foreach (FAT_TUR as $tk => $ti): ?>
                    <option value="<?= h($tk) ?>" <?= $seciliTur === $tk ? 'selected' : '' ?>><?= h($ti[0]) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="turAciklama"><?= h(FAT_TUR[$seciliTur][3]) ?></div></div>
        <div class="col-md-6"><label class="form-label">ETTN</label>
            <input type="text" name="ettn" class="form-control" value="<?= h((string)$v['ettn']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Not</label>
            <input type="text" name="notlar" class="form-control" placeholder="opsiyonel"></div>
        <?php if (!$onerId && ($saticiUnvan !== '' || $saticiVkn !== '')): ?>
        <input type="hidden" name="satici_unvan" value="<?= h($saticiUnvan) ?>">
        <input type="hidden" name="satici_vkn" value="<?= h($saticiVkn) ?>">
        <div class="col-12"><div class="form-check p-3 pt-2 ps-5 bg-info-subtle border border-info rounded mb-0">
            <input class="form-check-input" type="checkbox" name="tedarikci_olustur" value="1" id="tedOlustur" checked>
            <label class="form-check-label" for="tedOlustur">
                <strong>Tedarikçi kayıtlı değil — faturadan otomatik oluştur:</strong>
                <?= $saticiUnvan !== '' ? h($saticiUnvan) : '<em>unvan okunamadı</em>' ?><?= $saticiVkn !== '' ? ' (VKN '.h($saticiVkn).')' : '' ?>
                <div class="small text-muted mt-1">Kaydettiğinizde bu satıcı tedarikçi olarak açılır (VKN'siyle — bir daha sorulmaz)
                    ve fatura ona bağlanır. Yukarıdan elle tedarikçi seçerseniz bu kutu yok sayılır.</div>
            </label>
        </div></div>
        <?php endif; ?>
        <?php if ($v['brut_tutar'] !== null && $v['tutar'] !== null && abs($v['brut_tutar']-$v['tutar'])>0.01): ?>
        <div class="col-12"><div class="alert alert-secondary py-2 small mb-0">
            Tevkifatlı fatura: Vergiler Dahil Toplam <strong><?= $fmt($v['brut_tutar']) ?> ₺</strong>,
            Ödenecek <strong><?= $fmt($v['tutar']) ?> ₺</strong>.
        </div></div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-check2-circle text-success me-1"></i> Eşleşen İrsaliyeler (<?= $eslesenAdet ?>)</span>
        <?php if ($eslesenAdet): ?><small class="text-muted fw-normal">İşaretli olanlar faturaya bağlanır</small><?php endif; ?>
    </div>
    <div class="card-body p-0">
    <?php if (!$eslesenAdet): ?>
        <div class="p-3 text-muted">Faturadaki hiçbir irsaliye sistemde bulunamadı.</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
                <th style="width:36px"><input type="checkbox" class="form-check-input" checked onclick="document.querySelectorAll('.irs-chk').forEach(c=>c.checked=this.checked)"></th>
                <th>Faturada</th><th>Sistemde</th><th>Tarih</th><th>Tedarikçi</th><th>Beton</th><th>Plaka</th>
                <th class="text-end">m³</th><th>Durum</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($e['eslesen'] as $r): $cakisma = isset($baskasi[(int)$r['id']]); ?>
                <tr class="<?= $cakisma ? 'table-danger' : '' ?>">
                    <td><input type="checkbox" class="form-check-input irs-chk" name="irs_id[]" value="<?= (int)$r['id'] ?>" checked></td>
                    <td><code><?= h($r['fatura_gosterim']) ?></code></td>
                    <td><code><?= h($r['irsaliye_no']) ?></code></td>
                    <td><?= h(format_date($r['tarih'])) ?></td>
                    <td><?= h((string)$r['tedarikci']) ?></td>
                    <td><?= h((string)$r['beton_sinifi']) ?></td>
                    <td><?= h((string)$r['arac_plaka']) ?></td>
                    <td class="text-end"><?= $fmt($r['miktar']) ?></td>
                    <td><span class="badge bg-<?= $r['durum']==='reddedildi'?'danger':($r['durum']==='beklemede'?'secondary':'success') ?>"><?= h($r['durum']) ?></span>
                        <?php if (isset($baskasi[(int)$r['id']])): ?>
                        <span class="badge bg-danger" title="Bu irsaliye başka bir faturaya bağlı">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($baskasi[(int)$r['id']]['fatura_no']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="irsaliye_detay.php?id=<?= (int)$r['id'] ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold"><tr>
                <td colspan="7" class="text-end">Toplam</td>
                <td class="text-end"><?= $fmt($e['ozet']['miktar']) ?></td><td colspan="2"></td>
            </tr></tfoot>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php if ($eksikAdet): ?>
<div class="card mb-3 border-danger">
    <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> Sistemde Bulunamayan İrsaliyeler (<?= $eksikAdet ?>)</div>
    <div class="card-body">
        <p class="small text-muted">Bu numaralar faturada var ama sistemde kayıtlı değil. Genelde <strong>henüz girilmemiş</strong>
           irsaliyelerdir — Excel aktarımı/hızlı tarama ile girildikten sonra bu sayfayı tekrar çalıştırın.</p>
        <div class="d-flex flex-wrap gap-1">
            <?php foreach ($e['eksik'] as $n): ?><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= h($n) ?></span><?php endforeach; ?>
        </div>
        <div class="form-check mt-3 p-3 pt-2 ps-5 bg-warning-subtle border border-warning rounded">
            <input class="form-check-input" type="checkbox" name="eksik_olustur" value="1" id="eksikOlustur">
            <label class="form-check-label" for="eksikOlustur">
                <strong>Bu <?= $eksikAdet ?> irsaliyeyi taslak olarak oluştur ve faturaya bağla</strong> <span class="text-muted">(isteğe bağlı)</span>
                <div class="small text-muted mt-1">
                    Taslaklar fatura tarihi ve tedarikçisiyle, <strong>0 m³</strong> ve
                    <span class="badge bg-secondary">[FATURADAN]</span> notuyla açılır; fatura dosyası belge olarak eklenir.
                    Excel aktarımı aynı numarayı getirdiğinde taslak <strong>silinmez, gerçek verilerle güncellenir</strong> —
                    fatura bağı ve ekli belgeler korunur. Bunun için <strong>tedarikçi seçili olmalıdır</strong>.
                </div>
            </label>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mb-4">
    <?php /* ⚠ Düğme eskiden irsaliye yoksa KAPALIYDI → irsaliyesiz faturalar (fiyat farkı, pompa kirası)
             hiç kaydedilemiyordu. Artık her zaman açık; beton türünde irsaliye yoksa onay sorulur. */ ?>
    <button class="btn btn-<?= $baskasi ? 'warning' : 'success' ?>" id="faturaKaydetBtn"
            data-irs="<?= (int)($eslesenAdet + $eksikAdet) ?>"
            data-baskasi="<?= $baskasi ? 1 : 0 ?>">
        <i class="bi bi-save me-1"></i><?= $mevcut ? 'Mevcut Faturayı Güncelle' : 'Faturayı Kaydet' ?><span id="faturaKaydetEk"><?= $turIrs ? ' ve İrsaliyeleri Bağla' : '' ?></span>
    </button>
    <a href="fatura_eslestir.php" class="btn btn-outline-secondary">Vazgeç</a>
</div>
</form>

<script>
/* Fatura türü değişince: açıklama yenilenir; irsaliyesiz türde (pompa/hizmet)
   "eksik irsaliyeleri taslak oluştur" kutusu kapatılır — pompa kirası faturasından
   irsaliye taslağı açmak anlamsızdır. */
(function () {
    var sec = document.getElementById('faturaTur');
    if (!sec) return;
    var acik = <?= json_encode(array_map(fn($t) => [$t[0], $t[3]], FAT_TUR), JSON_UNESCAPED_UNICODE) ?>;
    var irsaliyesiz = <?= json_encode(FAT_TUR_IRSALIYESIZ) ?>;
    var not = document.getElementById('turAciklama');
    var kutu = document.getElementById('eksikOlustur');
    function uygula() {
        var t = sec.value;
        if (not && acik[t]) not.textContent = acik[t][1];
        var disi = irsaliyesiz.indexOf(t) !== -1;
        if (kutu) {
            kutu.disabled = disi;
            if (disi) kutu.checked = false;
            var kart = kutu.closest('.form-check');
            if (kart) kart.classList.toggle('opacity-50', disi);
        }
        document.querySelectorAll('[data-beton-mutabakat]').forEach(function (el) {
            el.classList.toggle('opacity-50', disi);
        });
        var ek = document.getElementById('faturaKaydetEk');
        if (ek) ek.textContent = disi ? '' : ' ve İrsaliyeleri Bağla';
    }
    sec.addEventListener('change', uygula);
    uygula();
})();
(function () {
    var btn = document.getElementById('faturaKaydetBtn');
    if (!btn) return;
    var irsaliyesiz = <?= json_encode(FAT_TUR_IRSALIYESIZ) ?>;
    btn.addEventListener('click', function (ev) {
        var sec = document.getElementById('faturaTur');
        var disi = sec && irsaliyesiz.indexOf(sec.value) !== -1;
        if (!disi && btn.dataset.irs === '0' &&
            !confirm('Faturada hiç irsaliye numarası bulunamadı.\n\nBeton (irsaliyeli) türünde irsaliyesiz kaydedilsin mi?\n'
                   + '(Fiyat farkı, pompa kirası vb. ise "Fatura Türü" alanından türünü seçin.)')) { ev.preventDefault(); return; }
        if (btn.dataset.baskasi === '1' &&
            !confirm('İşaretli irsaliyelerden bazıları başka bir faturaya bağlı. Bu faturaya taşınsın mı?')) { ev.preventDefault(); }
    });
})();
</script>
<?php endif; ?>

<?php if ($cift['irs'] || $cift['tutar']): ?>
<div class="alert <?= $cift['irs'] ? 'alert-danger' : 'alert-warning' ?> mb-3">
    <div class="fw-semibold mb-1"><i class="bi bi-exclamation-octagon me-1"></i>Çift faturalama kontrolü</div>
    <?php if ($cift['irs']): ?>
    <div class="small mb-1"><strong><?= count($cift['irs']) ?> irsaliye birden fazla FARKLI faturada geçiyor</strong> — aynı teslimat iki kez faturalanmış olabilir, ödemeden önce tedarikçiyle kontrol edin:</div>
    <ul class="small mb-2">
        <?php foreach ($cift['irs'] as $x): ?>
        <li><code><?= h($x['ham']) ?></code> →
            <?php foreach ($x['f'] as $fno): ?><a href="fatura_eslestir.php?fatura_ara=<?= urlencode($fno) ?>" class="badge bg-danger text-decoration-none me-1"><?= h($fno) ?></a><?php endforeach; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($cift['tutar']): ?>
    <div class="small mb-1"><strong><?= count($cift['tutar']) ?> grup</strong> aynı tedarikçi · aynı gün · aynı tutarlı farklı fatura (şüphe — kalemleri karşılaştırın; sabit bedelli pompa kirası olabilir):</div>
    <ul class="small mb-0">
        <?php foreach ($cift['tutar'] as $x): $f0 = reset($x); ?>
        <li><?= h((string)$f0['tedarikci']) ?> · <?= h(format_date($f0['tarih'])) ?> · <?= $fmt((float)$f0['tutar']) ?> ₺ →
            <?php foreach ($x as $f1): ?><a href="fatura_eslestir.php?fatura_ara=<?= urlencode((string)$f1['fatura_no']) ?>" class="badge bg-warning text-dark text-decoration-none me-1"><?= h((string)$f1['fatura_no']) ?></a><?php endforeach; ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php elseif ($fatTumAdet): ?>
<div class="small text-success mb-2"><i class="bi bi-shield-check me-1"></i>Çift faturalama kontrolü: hiçbir irsaliye iki farklı faturada geçmiyor; aynı gün aynı tutarlı fatura yok.</div>
<?php endif; ?>
<div class="card" id="kayitli-faturalar">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-archive me-1"></i> Kayıtlı Faturalar</span>
        <?php
        // m³ toplamı yalnız BETON faturalarından — pompa kirası/hizmet faturasının m³'ü yoktur
        $betonFat = array_values(array_filter($kayitli, fn($x) => fat_tur_irsaliyeli($x['tur'] ?? 'beton')));
        $digerFat = count($kayitli) - count($betonFat);
        ?>
        <span class="badge bg-primary" title="<?= $fatSuzgec ? 'Süzgeçle eşleşen faturaların toplamı' : 'Kayıtlı faturaların TAMAMI' ?>"><?= count($kayitli) ?><?= $fatSuzgec ? ' / ' . $fatTumAdet : '' ?> fatura
            · <?= $fmt(array_sum(array_map(fn($x)=>(float)$x['miktar_m3'], $betonFat))) ?> m³
            · <?= $fmt(array_sum(array_map(fn($x)=>(float)$x['tutar'], $kayitli))) ?> ₺
            <?= $digerFat ? '· ' . $digerFat . ' irsaliyesiz (pompa/fiyat farkı/hizmet)' : '' ?></span>
    </div>
    <form method="get" action="fatura_eslestir.php#kayitli-faturalar" class="row g-2 align-items-end px-3 py-2 border-bottom bg-light-subtle small">
        <div class="col-6 col-md-3 col-xl-2">
            <label class="form-label small mb-0">Tedarikçi</label>
            <select name="ted" class="form-select form-select-sm">
                <option value="">Tümü</option>
                <?php foreach ($fatTedler as $tid => $ti): ?>
                <option value="<?= (int)$tid ?>" <?= $fatTed === (int)$tid ? 'selected' : '' ?>><?= h($ti['ad']) ?> (<?= (int)$ti['adet'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2 col-xl-2">
            <label class="form-label small mb-0">Tarih (başlangıç)</label>
            <input type="date" name="bas" value="<?= h($fatBas) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2 col-xl-2">
            <label class="form-label small mb-0">Tarih (bitiş)</label>
            <input type="date" name="bit" value="<?= h($fatBit) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-6 col-md-2 col-xl-2">
            <label class="form-label small mb-0">Tür</label>
            <select name="tur" class="form-select form-select-sm">
                <option value="">Tüm türler</option>
                <?php foreach (FAT_TUR as $tk => $ti): ?>
                <option value="<?= h($tk) ?>" <?= $fatTur === $tk ? 'selected' : '' ?>><?= h($ti[0]) ?> (<?= (int)($turSayac[$tk] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label class="form-label small mb-0">Durum</label>
            <select name="durum" class="form-select form-select-sm">
                <option value="">Hepsi</option>
                <?php foreach ($FAT_DURUM as $dk => $da): ?>
                <option value="<?= h($dk) ?>" <?= $fatDurum === $dk ? 'selected' : '' ?>><?= h($da) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
            <label class="form-label small mb-0">Ara</label>
            <input type="text" name="fatura_ara" value="<?= h($fatAra) ?>" class="form-control form-control-sm" placeholder="Fatura no / irsaliye no / ETTN">
        </div>
        <div class="col-12 col-md-6 col-xl-12 d-flex gap-1 justify-content-xl-end">
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Süz</button>
            <?php if ($fatSuzgec): ?><a href="fatura_eslestir.php#kayitli-faturalar" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Süzgeci temizle</a><?php endif; ?>
        </div>
    </form>
    <div class="card-body p-0">
    <?php if (!$kayitli): ?>
        <div class="p-3 text-muted"><?= $fatSuzgec ? 'Süzgeçle eşleşen fatura bulunamadı.' : 'Henüz kayıtlı fatura yok.' ?></div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr>
                <th class="text-center">#</th>
                <th>Fatura No</th><th>Tür</th><th>Tarih</th><th>Tedarikçi</th><th class="text-end">Tutar</th>
                <th class="text-end">m³</th><th class="text-end">Bağlı İrs.</th><th class="text-end">Eksik</th><th>Dosya</th><th></th>
            </tr></thead>
            <tbody>
            <?php $sira = count($kayitli); foreach ($kayitli as $f): $fIrs = $kayitliIrs[(int)$f['id']] ?? [];
                  $fTur = fat_tur_norm($f['tur'] ?? 'beton'); $fBeton = fat_tur_irsaliyeli($fTur); ?>
                <tr class="<?= $fBeton ? '' : 'table-light' ?>">
                    <td class="text-center text-muted"><?= $sira-- ?></td>
                    <td><code><?= h($f['fatura_no']) ?></code>
                        <?php if (($cift['fid'][(int)$f['id']] ?? '') === 'irs'): ?><span class="badge bg-danger ms-1" title="Bu faturadaki bir irsaliye başka bir faturada da geçiyor — çift faturalama şüphesi">çift irsaliye</span>
                        <?php elseif (($cift['fid'][(int)$f['id']] ?? '') === 'tutar'): ?><span class="badge bg-warning text-dark ms-1" title="Aynı tedarikçi, aynı gün, aynı tutarlı başka fatura var">aynı tutar</span><?php endif; ?></td>
                    <td>
                        <div class="dropdown">
                            <a class="badge bg-<?= h(FAT_TUR[$fTur][1]) ?> text-decoration-none" href="#" role="button"
                               data-bs-toggle="dropdown" title="<?= h(FAT_TUR[$fTur][3]) ?> — değiştirmek için tıklayın">
                                <i class="bi <?= h(FAT_TUR[$fTur][2]) ?> me-1"></i><?= h(FAT_TUR[$fTur][0]) ?>
                            </a>
                            <div class="dropdown-menu p-2" style="min-width:260px">
                                <div class="small text-muted px-1 pb-1">Fatura türünü değiştir</div>
                                <?php foreach (FAT_TUR as $tk => $ti): if ($tk === $fTur) continue; ?>
                                <form method="post" class="d-grid">
                                    <input type="hidden" name="action" value="tur_degistir">
                                    <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                                    <input type="hidden" name="tur" value="<?= h($tk) ?>">
                                    <button class="btn btn-sm btn-outline-<?= h($ti[1]) ?> mb-1 text-start">
                                        <i class="bi <?= h($ti[2]) ?> me-1"></i><?= h($ti[0]) ?>
                                        <span class="d-block small text-muted"><?= h($ti[3]) ?></span>
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </td>
                    <td><?= h(format_date($f['tarih'])) ?></td>
                    <td><?= h((string)$f['tedarikci']) ?></td>
                    <td class="text-end"><?= $f['tutar']!==null?$fmt($f['tutar']).' ₺':'—' ?></td>
                    <td class="text-end">
                        <?php if (!$fBeton): ?>
                            <span class="text-muted" title="<?= h(FAT_TUR[$fTur][0]) ?> faturasının m³'ü yoktur">—</span>
                        <?php elseif ($f['miktar_m3'] !== null): ?><?= $fmt($f['miktar_m3']) ?>
                        <?php else: ?>
                        <div class="d-inline-flex align-items-center gap-1">
                            <?php if ($f['dosya_url']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="eksik_tara">
                                <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                                <input type="hidden" name="geri" value="liste">
                                <button class="btn btn-sm btn-outline-secondary py-0" title="m³ faturada var ama kayıtta boş — PDF yeniden okunur, boş alanlar dolar">
                                    <i class="bi bi-arrow-clockwise"></i> tara</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline-flex align-items-center gap-1" title="Faturadaki m³ değerini elle girin">
                                <input type="hidden" name="action" value="m3_duzelt">
                                <input type="hidden" name="fatura_id" value="<?= (int)$f['id'] ?>">
                                <input type="text" name="m3" class="form-control form-control-sm py-0 px-1 text-end"
                                       style="width:64px" placeholder="m³" inputmode="decimal">
                                <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check-lg"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($fIrs): ?>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button"
                                data-bs-toggle="collapse" data-bs-target="#fi<?= (int)$f['id'] ?>">
                            <?= count($fIrs) ?> <i class="bi bi-chevron-down small"></i>
                        </button>
                        <?php else: ?><?= (int)$f['bagli'] ?><?php endif; ?>
                    </td>
                    <td class="text-end <?= (int)$f['eksik_adet']?'text-danger fw-bold':'' ?>">
                        <?php $fTaslak = count(array_filter($fIrs, 'irs_taslak_mi')); ?>
                        <?php if (!$fBeton): ?>
                        <span class="text-muted" title="İrsaliye eşleştirmesine girmeyen fatura">—</span>
                        <?php elseif ((int)$f['eksik_adet'] > 0): $fel = json_decode((string)($f['eksik_liste'] ?? ''), true); ?>
                        <a href="?eksik=1" class="text-danger text-decoration-none" title="<?= h(is_array($fel) ? implode(', ', $fel) : 'numara listesi için tıklayın') ?>"><?= (int)$f['eksik_adet'] ?> <i class="bi bi-box-arrow-up-right small"></i></a>
                        <?php elseif ($fTaslak): ?>
                        <span class="badge bg-warning text-dark" title="Faturadan otomatik açılmış taslak irsaliyeler — Excel aktarımıyla gerçek veriler gelmeden EKSİK sayılır<?= beton_onay_aktif() ? ', onaylanamaz' : '' ?>"><?= $fTaslak ?> taslak</span>
                        <?php else: ?>0<?php endif; ?>
                    </td>
                    <td><?php if ($f['dosya_url']): ?><a href="<?= h($f['dosya_url']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary py-0" href="irsaliyeler.php?fatura_id=<?= (int)$f['id'] ?>">İrsaliyeler</a>
                        <?php if (is_admin()): ?>
                        <a class="btn btn-sm btn-outline-danger py-0" href="?sil=<?= (int)$f['id'] ?>" onclick="return confirm('Fatura kaydı silinsin, irsaliye bağları çözülsün mü?')"><i class="bi bi-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($fIrs): ?>
                <tr class="collapse" id="fi<?= (int)$f['id'] ?>">
                    <td colspan="11" class="bg-body-tertiary">
                        <div class="small fw-semibold mb-1">
                            <?= h($f['fatura_no']) ?> faturasına bağlı irsaliyeler (<?= count($fIrs) ?>)
                        </div>
                        <table class="table table-sm table-bordered bg-body mb-0" style="max-width:760px">
                            <thead class="table-light"><tr>
                                <th>İrsaliye No</th><th>Tarih</th><th>Plaka</th><th>Beton</th><th class="text-end">m³</th><th>Durum</th>
                            </tr></thead>
                            <tbody>
                            <?php $ft = 0.0; foreach ($fIrs as $fi): $ft += (float)$fi['miktar']; ?>
                                <tr>
                                    <td><a href="irsaliye_detay.php?id=<?= (int)$fi['id'] ?>" target="_blank"><code><?= h($fi['irsaliye_no']) ?></code></a>
                                        <?php if (irs_taslak_mi($fi)): ?><span class="badge bg-warning text-dark" title="Faturadan otomatik açıldı — Excel aktarımıyla dolar<?= beton_onay_aktif() ? ', o zamana kadar onaylanamaz' : '' ?>">TASLAK</span><?php endif; ?></td>
                                    <td><?= h(format_date($fi['tarih'])) ?></td>
                                    <td><?= h((string)$fi['arac_plaka']) ?></td>
                                    <td><?= h((string)$fi['beton_sinifi']) ?></td>
                                    <td class="text-end"><?= $fmt($fi['miktar']) ?></td>
                                    <td><?= h($fi['durum']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold"><tr>
                                <td colspan="4" class="text-end">Toplam</td>
                                <td class="text-end"><?= $fmt($ft) ?></td>
                                <td class="<?= ($f['miktar_m3']!==null && abs($ft-(float)$f['miktar_m3'])>0.01) ? 'text-danger' : 'text-success' ?>">
                                    <?php if ($f['miktar_m3'] !== null): ?>
                                        fatura <?= $fmt($f['miktar_m3']) ?> m³
                                        <?= abs($ft-(float)$f['miktar_m3'])>0.01 ? '(fark '.$fmt($ft-(float)$f['miktar_m3']).')' : '✓' ?>
                                    <?php endif; ?>
                                </td>
                            </tr></tfoot>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
