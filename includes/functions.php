<?php
/**
 * functions.php — Genel yardımcı fonksiyonlar
 */

/**
 * Tarihi Türkçe formatla: d.m.Y
 */
function format_date(?string $d): string
{
    if (empty($d)) {
        return '-';
    }
    $ts = strtotime($d);
    return $ts !== false ? date('d.m.Y', $ts) : htmlspecialchars($d);
}

/**
 * Sayıyı Türkçe formatla (virgül ondalık, nokta binler ayracı)
 */
function format_number($n, int $decimals = 2): string
{
    return number_format((float)$n, $decimals, ',', '.');
}

/**
 * Rol adını Türkçe karşılığıyla döner
 */
function role_label(string $role): string
{
    $map = [
        'admin'             => 'Yönetici',
        'teknik_ofis_admin' => 'Teknik Ofis Yöneticisi',
        'teknik_ofis'       => 'Teknik Ofis',
        'saha_sefi'         => 'Saha Şefi',
        'depo'              => 'Depo',
    ];
    return $map[$role] ?? htmlspecialchars($role);
}

/**
 * HTTP yönlendirmesi + çıkış
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Flash mesajı oturuma yaz.
 * $msg null ise yazma yapılmaz (sadece ayarlamak için kullanılır).
 *
 * Kullanım: flash('success', 'İşlem başarılı.')
 */
function flash(string $key, ?string $msg = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if ($msg !== null) {
        $_SESSION['_flash'][$key] = $msg;
    }
}

/**
 * Flash mesajı oku ve sil; yoksa null döner.
 *
 * Kullanım: $msg = get_flash('success')
 */
function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

/**
 * XSS korumalı HTML çıkışı için kısaltma
 */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Audit log kaydı yaz
 *
 * @param PDO    $pdo
 * @param string $tablo      Tablo adı
 * @param int    $kayitId    Kayıt ID
 * @param string $islem      INSERT | UPDATE | DELETE
 * @param mixed  $eskiDeger  Eski değer (dizi veya null)
 * @param mixed  $yeniDeger  Yeni değer (dizi veya null)
 * @param int|null $kullaniciId
 */
function audit_log(PDO $pdo, string $tablo, int $kayitId, string $islem, $eskiDeger = null, $yeniDeger = null, ?int $kullaniciId = null): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (tablo, kayit_id, islem, eski_deger, yeni_deger, kullanici_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $tablo,
            $kayitId,
            $islem,
            $eskiDeger !== null ? json_encode($eskiDeger, JSON_UNESCAPED_UNICODE) : null,
            $yeniDeger !== null ? json_encode($yeniDeger, JSON_UNESCAPED_UNICODE) : null,
            $kullaniciId,
        ]);
    } catch (PDOException $e) {
        // Audit log başarısız olsa bile ana işlemi bozmayalım
        error_log('audit_log error: ' . $e->getMessage());
    }
}

/**
 * Aktif kullanıcı ID'sini döner (oturumdan)
 */
function current_user_id(): ?int
{
    return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
}
