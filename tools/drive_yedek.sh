#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# drive_yedek.sh — günlük yedeği alır ve Google Drive'a kopyalar (rclone).
#
# aaPanel → Zamanlanmış Görev (Cron) → Tür: Shell Script → her gün 03:30:
#     bash /www/wwwroot/ernsaha.com.tr/beton/tools/drive_yedek.sh
#
# Ön koşul: sunucuda rclone kurulu ve "ernyedek" adlı bir Google Drive bağlantısı
# tanımlı (kurulum adımları yedek.php → "Google Drive kopyası" kartında).
#
# Ne yapar:
#   1. php tools/yedek_cron.php yedek  → o günün otomatik yedeği (8 veritabanı)
#   2. rclone copy backups/ → Drive:ERN-Sistem-Yedek  (yalnız yeni dosyalar gider)
#   3. Drive'da SAKLA_GUN'den eski yedekleri siler (sunucuda 30 gün, Drive'da 90 gün)
#   4. Sonucu veritabanına yazar → yedek sayfasında görünür
#
# Ayarlar (gerekirse görev komutunun başına yazılır, ör. ERN_DRIVE_SAKLA_GUN=180 bash …):
REMOTE="${ERN_DRIVE_REMOTE:-ernyedek}"
KLASOR="${ERN_DRIVE_KLASOR:-ERN-Sistem-Yedek}"
SAKLA_GUN="${ERN_DRIVE_SAKLA_GUN:-90}"
WEB_KULLANICI="${ERN_WEB_KULLANICI:-www}"
# ─────────────────────────────────────────────────────────────────────────────
KOK="$(cd "$(dirname "$0")/.." && pwd)"
LOG="/tmp/ern_drive_yedek.log"

PHP=""
for p in "$(command -v php 2>/dev/null)" /www/server/php/85/bin/php /www/server/php/84/bin/php /www/server/php/83/bin/php /www/server/php/82/bin/php; do
    if [ -n "$p" ] && [ -x "$p" ]; then PHP="$p"; break; fi
done
if [ -z "$PHP" ]; then echo "HATA: php bulunamadı"; exit 1; fi
RCLONE="$(command -v rclone 2>/dev/null || echo /usr/bin/rclone)"

echo "== $(date '+%F %T') yedek alınıyor"
"$PHP" "$KOK/tools/yedek_cron.php" yedek
# Görev root olarak çalışır: oluşan dosyalar web kullanıcısına geçsin (yedek sayfası silebilsin)
if id "$WEB_KULLANICI" >/dev/null 2>&1; then chown -R "$WEB_KULLANICI:$WEB_KULLANICI" "$KOK/backups"; fi

if [ ! -x "$RCLONE" ]; then
    echo "HATA: rclone kurulu değil"
    "$PHP" "$KOK/tools/yedek_cron.php" drive 127 0 - 0 "rclone kurulu değil — kurulum adımları yedek sayfasında"
    exit 1
fi

echo "== Drive'a kopyalanıyor: $REMOTE:$KLASOR"
: > "$LOG"
"$RCLONE" copy "$KOK/backups" "$REMOTE:$KLASOR" \
    --include "*.sql.gz" --include "*.sql" \
    --retries 5 --low-level-retries 10 --contimeout 30s --timeout 5m \
    --log-level INFO --log-file "$LOG"
KOD=$?
KOPYA=$(grep -c "Copied (new)" "$LOG")

SIL=0
if [ "$KOD" -eq 0 ] && [ "$SAKLA_GUN" -gt 0 ]; then
    "$RCLONE" delete "$REMOTE:$KLASOR" --min-age "${SAKLA_GUN}d" \
        --include "*.sql.gz" --include "*.sql" --log-level INFO --log-file "$LOG"
    SIL=$(grep -c ": Deleted" "$LOG")
fi

TOPLAM=$("$RCLONE" lsf "$REMOTE:$KLASOR" --files-only 2>/dev/null | wc -l)
[ "$KOD" -eq 0 ] || TOPLAM="-"

"$PHP" "$KOK/tools/yedek_cron.php" drive "$KOD" "$KOPYA" "$TOPLAM" "$SIL" "$LOG"
echo "== bitti: rclone kodu=$KOD · yeni=$KOPYA · Drive'dan silinen=$SIL · Drive'da toplam=$TOPLAM"
exit "$KOD"
