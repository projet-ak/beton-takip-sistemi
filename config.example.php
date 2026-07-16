<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'veritabani_adi');
define('DB_USER', 'kullanici_adi');
define('DB_PASS', 'sifre');

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

// ── Oturum / AI (opsiyonel) ──────────────────────────────────────────────────
// define('SESSION_LIFETIME', 3600);
// define('AI_PROVIDER', 'claude');
// define('CLAUDE_API_KEY', '');
