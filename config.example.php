<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'veritabani_adi');
define('DB_USER', 'kullanici_adi');
define('DB_PASS', 'sifre');

// ⚠ MODÜL AYIRMA UYARISI: Aşağıdaki *_DB_NAME sabitleri tanımsızsa ilgili modül
//    ANA DB'yi kullanır (tablolar 'demir_'/'seramik_'/'depo_'/'akaryakit_' önekli).
//    Sabiti SONRADAN eklerseniz modül yeni/boş DB'ye bakar; eski veriler ana DB'de
//    kalır ve modül boş görünür. Önce tabloları yeni DB'ye taşıyın, sonra tanımlayın.
//    Aktif DB'yi ilgili modülün kurulum_*.php sayfasındaki rozetten görebilirsiniz.

// ── Demir modülü (ayrı DB — opsiyonel) ───────────────────────────────────────
// Ayrı bir MySQL DB kullanacaksanız (önerilir) tanımlayın; kullanıcı/şifre aynıysa
// yalnız DB adı yeterli. Tanımsızsa ana DB'de 'demir_' önekli tablolar kullanılır.
// define('DEMIR_DB_NAME', 'takbulut_demir');
// define('DEMIR_DB_USER', '');   // boşsa DB_USER
// define('DEMIR_DB_PASS', '');   // boşsa DB_PASS

// ── Seramik modülü (ayrı DB — opsiyonel) ─────────────────────────────────────
// define('SERAMIK_DB_NAME', 'takbulut_seramik');
// define('SERAMIK_DB_USER', '');  // boşsa DB_USER (ör. takbulut_betonapp)
// define('SERAMIK_DB_PASS', '');  // boşsa DB_PASS

// ── Depo modülü (Sarf/Demirbaş/El Aletleri — ayrı DB, opsiyonel) ──────────────
// define('DEPO_DB_NAME', 'takbulut_depo');
// define('DEPO_DB_USER', '');     // boşsa DB_USER (ör. takbulut_betonapp)
// define('DEPO_DB_PASS', '');     // boşsa DB_PASS

// ── Akaryakıt modülü (Mazot takip — ayrı DB, opsiyonel) ──────────────────────
// define('AKARYAKIT_DB_NAME', 'takbulut_akaryakit');
// define('AKARYAKIT_DB_USER', '');  // boşsa DB_USER (ör. takbulut_betonapp)
// define('AKARYAKIT_DB_PASS', '');  // boşsa DB_PASS

// ── Deploy (deploy.php / deploy2.php) ────────────────────────────────────────
// GÜÇLÜ, rastgele bir token üretin (ör. PHP: bin2hex(random_bytes(24))) ve buraya yazın.
// Bu dosya (config.php) git-ignored olduğundan token koda/sürüm kontrolüne girmez.
// define('DEPLOY_TOKEN', 'BURAYA_UZUN_RASTGELE_TOKEN');
// Private repo için GitHub PAT'i (repo yetkili) — URL'de taşımamak için:
// define('GITHUB_PAT', 'github_pat_xxx');

// ── Gelen mesaj kuyruğu (WhatsApp vb. → mesajlar.php) ────────────────────────
// whatsapp/api/mesaj_al.php bu token ile korunur. Güçlü ve rastgele olmalı
// (ör. PHP: bin2hex(random_bytes(24))). Meta Cloud API kullanılırsa webhook
// "Verify Token" alanına da AYNI değer yazılır.
// define('MESAJ_TOKEN', 'BURAYA_UZUN_RASTGELE_TOKEN');
// Reddedilen mesajlar kaç gün sonra görselleriyle silinsin (onaylılar arşiv, silinmez):
// define('MESAJ_SAKLAMA_GUN', 90);
// Meta Cloud API kullanılıyorsa gelen fotoğrafları indirmek için Graph erişim tokeni:
// define('WHATSAPP_GRAPH_TOKEN', '');

// ── Oturum / AI (opsiyonel) ──────────────────────────────────────────────────
// define('SESSION_LIFETIME', 3600);
// define('AKTIVITE_SAKLAMA_GUN', 90);   // aktivite kayıtları kaç gün saklansın (otomatik temizlik)
// define('AI_PROVIDER', 'claude');
// define('CLAUDE_API_KEY', '');
