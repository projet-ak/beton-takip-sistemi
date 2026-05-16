<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!file_exists(__DIR__ . '/../config.php')) { http_response_code(503); echo json_encode(['ok'=>false,'msg'=>'Kurulum yapılmamış']); exit; }

require_auth(['admin','teknik_ofis_admin','saha_sefi']);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Sil (GET ?sil=ID) ─────────────────────────────────────────────────────
if (isset($_GET['sil']) && ctype_digit($_GET['sil'])) {
    $id  = (int)$_GET['sil'];
    $s   = $pdo->prepare("SELECT dosya_adi FROM irsaliye_fotolar WHERE id=?");
    $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Kayıt bulunamadı']); exit; }

    $dosya = __DIR__ . '/../uploads/irsaliye_fotolar/' . $row['dosya_adi'];
    if (file_exists($dosya)) @unlink($dosya);
    $pdo->prepare("DELETE FROM irsaliye_fotolar WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Yükle (POST) ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'msg'=>'Sadece POST desteklenir']);
    exit;
}

$irsaliyeId = isset($_POST['irsaliye_id']) && ctype_digit($_POST['irsaliye_id'])
    ? (int)$_POST['irsaliye_id'] : 0;

if (!$irsaliyeId) { echo json_encode(['ok'=>false,'msg'=>'Geçersiz irsaliye_id']); exit; }

// İrsaliye var mı?
$kontrol = $pdo->prepare("SELECT id FROM irsaliyeler WHERE id=?");
$kontrol->execute([$irsaliyeId]);
if (!$kontrol->fetch()) { echo json_encode(['ok'=>false,'msg'=>'İrsaliye bulunamadı']); exit; }

$izinliTipler = ['image/jpeg','image/png','application/pdf'];
$maxBoyut     = 15 * 1024 * 1024; // 15 MB
$hedefDizin   = __DIR__ . '/../uploads/irsaliye_fotolar/';
if (!is_dir($hedefDizin)) mkdir($hedefDizin, 0755, true);

$yuklenenler = [];
$hatalar     = [];

$dosyalar = $_FILES['dosyalar'] ?? [];
if (!isset($dosyalar['name'][0])) {
    echo json_encode(['ok'=>false,'msg'=>'Dosya seçilmedi']); exit;
}

$sayac = count($dosyalar['name']);
for ($i = 0; $i < $sayac; $i++) {
    if ($dosyalar['error'][$i] !== UPLOAD_ERR_OK) {
        $hatalar[] = "Dosya {$i}: Yükleme hatası ({$dosyalar['error'][$i]})";
        continue;
    }
    if ($dosyalar['size'][$i] > $maxBoyut) {
        $hatalar[] = "Dosya {$i}: 15 MB sınırı aşıldı";
        continue;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($dosyalar['tmp_name'][$i]);
    if (!in_array($mime, $izinliTipler, true)) {
        $hatalar[] = "Dosya {$i}: Geçersiz dosya türü ({$mime})";
        continue;
    }

    $ext      = match($mime) {
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'application/pdf'  => 'pdf',
        default            => 'bin'
    };
    $yeniAd   = 'irs_' . $irsaliyeId . '_' . uniqid() . '.' . $ext;
    $hedefYol = $hedefDizin . $yeniAd;

    if (!move_uploaded_file($dosyalar['tmp_name'][$i], $hedefYol)) {
        $hatalar[] = "Dosya {$i}: Taşıma başarısız";
        continue;
    }

    $pdo->prepare("INSERT INTO irsaliye_fotolar (irsaliye_id, dosya_adi, dosya_yolu, created_by)
                   VALUES (?, ?, ?, ?)")
        ->execute([$irsaliyeId, $yeniAd, 'uploads/irsaliye_fotolar/' . $yeniAd, current_user_id()]);

    $yuklenenler[] = $yeniAd;
}

if (empty($hatalar)) {
    echo json_encode(['ok'=>true,'yuklenen'=>count($yuklenenler)]);
} else {
    echo json_encode(['ok'=>count($yuklenenler)>0,'msg'=>implode('; ',$hatalar),'yuklenen'=>count($yuklenenler)]);
}
