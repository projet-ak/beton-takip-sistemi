<?php
/**
 * panel.php — TAZE dashboard yolu.
 *
 * index.php eski bir önbellek kaydına (tarayıcı/PWA veya sunucu-kenarı tam-sayfa
 * önbelleği) takılıp eski rakamı (5.247,9 / 589) gösteriyordu ve o kaydı silemedik.
 * Bu dosya YENİ bir yol olduğu için hiçbir önbellekte yoktur; tıpkı tani.php gibi
 * her zaman sunucudan taze gelir. İçerik olarak birebir index.php'yi çalıştırır,
 * yani aynı dashboard ama doğru, canlı rakamlarla (5.253,0 / 590).
 *
 * auth.php zaten no-store başlıkları gönderir; footer.php de eski PWA service
 * worker'ını kaldırır. Böylece bu sayfa bir daha donmaz.
 */
require __DIR__ . '/index.php';
