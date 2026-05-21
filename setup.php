<?php
// Beton Takip — İlk Kurulum Scripti
// Kullanım: bu dosyayı public_html'e yükle, tarayıcıda aç, sil.

$zipUrl  = 'https://github.com/projet-ak/beton-takip-sistrmi-v1/archive/refs/heads/claude/organize-control-panel-hUe4z.zip';
$zipFile = sys_get_temp_dir() . '/beton_deploy_' . time() . '.zip';
$destDir = __DIR__;

echo "<pre>\n";

// İndir
echo "Zip indiriliyor...\n";
$ctx = stream_context_create(['http' => ['timeout' => 120, 'follow_location' => true]]);
$bytes = file_put_contents($zipFile, file_get_contents($zipUrl, false, $ctx));
if (!$bytes) { die("HATA: Zip indirilemedi.\n"); }
echo "İndirildi: " . round($bytes / 1024) . " KB\n";

// Aç
echo "Dosyalar açılıyor...\n";
$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) { die("HATA: Zip açılamadı.\n"); }

$count = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name  = $zip->getNameIndex($i);
    $parts = explode('/', $name, 2);
    if (count($parts) < 2 || $parts[1] === '') continue;

    $rel  = $parts[1];
    $dest = $destDir . '/' . $rel;

    // config.php ve backups'a dokunma
    if ($rel === 'config.php' || str_starts_with($rel, 'backups/') || $rel === 'setup.php') continue;

    if (str_ends_with($name, '/')) {
        @mkdir($dest, 0755, true);
    } else {
        @mkdir(dirname($dest), 0755, true);
        file_put_contents($dest, $zip->getFromIndex($i));
        $count++;
    }
}
$zip->close();
@unlink($zipFile);

echo "Tamamlandı: $count dosya kopyalandı.\n";
echo "setup.php siliniyor...\n";
@unlink(__FILE__);
echo "\n✓ KURULUM TAMAMLANDI — <a href='install.php'>install.php</a>'ye git veya <a href='login.php'>giriş yap</a>\n";
echo "</pre>";
