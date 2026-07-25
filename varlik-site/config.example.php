<?php
/**
 * config.example.php — ERN Varlık Yönetim sitesi deploy ayarı
 * Bu dosyayı `config.php` olarak KOPYALAYIN ve değerleri doldurun.
 * config.php git-ignored'dır; sırlar sürüm kontrolüne girmez.
 */

// Güçlü, rastgele bir token (ör. PHP: bin2hex(random_bytes(24))).
// deploy.php?token=BU_DEGER ile güncelleme yapılır.
define('DEPLOY_TOKEN', 'BURAYA_UZUN_RASTGELE_TOKEN');

// Repo PRIVATE olduğundan GitHub PAT gerekli ("repo" içeriğini okuma yetkili).
define('GITHUB_PAT', 'github_pat_xxx');
