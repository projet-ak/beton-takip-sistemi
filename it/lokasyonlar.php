<?php
/**
 * it/lokasyonlar.php — Lokasyon yönetimi TANIMLAR ekranına taşındı (tek yerden yönetim).
 * Eski bağlantılar/yer imleri kırılmasın diye bu dosya yalnız yönlendirir.
 */
$rootPath = '../';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
if (!file_exists(__DIR__ . '/../config.php')) { redirect('../install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','depo','it_sorumlusu']);
redirect('tanimlar.php?t=lokasyon');
