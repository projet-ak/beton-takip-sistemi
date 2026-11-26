#!/bin/bash
# Kullanım: .claude/skills/run-beton-takip-sistemi/up.sh <komut...>
# MariaDB + php -S başlatır (ilk seferde şema + örnek veri), komutu ERN_URL ile çalıştırır, sonra ikisini kapatır.
# Durum klasörü: $ERN_RUN_DIR (varsayılan /tmp/ern-run). Sıfırdan başlamak için klasörü silin.
set -u
SK=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$SK/../../.." && pwd)
R=${ERN_RUN_DIR:-/tmp/ern-run}; mkdir -p "$R"
DBP=${ERN_DB_PORT:-3399}; WP=${ERN_PORT:-8099}
export ERN_URL="http://127.0.0.1:$WP" ERN_RUN_DIR="$R" ERN_REPO="$REPO"
SQL="mariadb -h127.0.0.1 -P$DBP -uroot --default-character-set=utf8mb4"

# config.php git-ignored; varsa yedekle, çıkışta geri koy
[ -f "$REPO/config.php" ] && ! grep -q ERN_RUN_SKILL "$REPO/config.php" && cp "$REPO/config.php" "$R/config.php.orig"
cat > "$REPO/config.php" <<PHP
<?php // ERN_RUN_SKILL — run-beton-takip-sistemi up.sh tarafından yazıldı, çıkışta silinir
define('DB_HOST','127.0.0.1:$DBP'); define('DB_NAME','ern'); define('DB_USER','root'); define('DB_PASS','');
foreach (['DEMIR','SERAMIK','DEPO','AKARYAKIT','CRM','PREKAST','IT','PTS','KAMP'] as \$m) define(\$m.'_DB_NAME', 'ern_'.strtolower(\$m));
PHP
# Yönetici dashboard'u açınca uygulama backups/'a günlük otomatik yedek yazar → bu çalıştırmada oluşanlar silinir
mkdir -p "$REPO/backups"; ls "$REPO/backups" > "$R/backups.once"
temizle() {
  kill ${PP:-0} ${MP:-0} 2>/dev/null; wait ${MP:-0} 2>/dev/null
  ls "$REPO/backups" | grep -vxFf "$R/backups.once" | sed "s|^|$REPO/backups/|" | xargs -r rm -f
  rm -f "$REPO/config.php"; [ -f "$R/config.php.orig" ] && mv "$R/config.php.orig" "$REPO/config.php"
}
trap temizle EXIT

[ -d "$R/mdb/mysql" ] || mariadb-install-db --datadir="$R/mdb" --user=root --auth-root-authentication-method=normal >/dev/null 2>&1
mariadbd --datadir="$R/mdb" --port=$DBP --bind-address=127.0.0.1 --socket="$R/my.sock" --pid-file="$R/my.pid" \
  --user=root --skip-grant-tables >"$R/mdb.log" 2>&1 &
MP=$!
for i in $(seq 1 60); do $SQL -e "select 1" >/dev/null 2>&1 && break; sleep 0.25; done
php -S 127.0.0.1:$WP -t "$REPO" "$SK/router.php" >"$R/php.log" 2>&1 &
PP=$!
for i in $(seq 1 40); do curl -s -o /dev/null "$ERN_URL/login.php" && break; sleep 0.25; done

if [ ! -f "$R/.kuruldu" ]; then
  for d in ern ern_demir ern_seramik ern_depo ern_akaryakit ern_crm ern_prekast ern_it ern_pts ern_kamp; do
    $SQL -e "CREATE DATABASE IF NOT EXISTS $d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; done
  for p in kurulum.php demir/kurulum_demir.php depo/kurulum_depo.php crm/kurulum_crm.php prekast/kurulum_prekast.php \
           it/kurulum_it.php kamp/kurulum_kamp.php; do
    c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d csrf=tok -d kur=1 -d action=kur "$ERN_URL/$p"); echo "kurulum $p → $c"; done
  touch "$R/.kuruldu"
fi
# Sonradan eklenen modüller (seramik/akaryakıt GET ile, PTS POST islem=kur ile, Saha Takip tablolarını sayfa kurar)
if [ ! -f "$R/.kuruldu2" ]; then
  for p in seramik/kurulum_seramik.php akaryakit/kurulum_akaryakit.php whatsapp/mesajlar.php; do
    echo "kurulum $p → $(curl -s -o /dev/null -w '%{http_code}' "$ERN_URL/$p")"; done
  echo "kurulum pts/kurulum_pts.php → $(curl -s -o /dev/null -w '%{http_code}' -X POST -d csrf=tok -d islem=kur "$ERN_URL/pts/kurulum_pts.php")"
  touch "$R/.kuruldu2"
fi
# Örnek veri: her dosya bir kez yüklenir (işaret dosyası .seed_<ad>). Önce sayfası açılır — tabloyu runtime kuran sayfalar var.
for s in "seed.sql:ern:fatura_eslestir.php" "seed_demir.sql:ern_demir:demir/faturalar.php" \
         "seed_seramik.sql:ern_seramik:seramik/index.php" "seed_akaryakit.sql:ern_akaryakit:akaryakit/index.php" \
         "seed_crm.sql:ern_crm:crm/index.php" "seed_prekast.sql:ern_prekast:prekast/index.php"; do
  IFS=: read f db sayfa <<< "$s"
  [ -f "$R/.seed_$f" ] && continue
  curl -s -o /dev/null "$ERN_URL/$sayfa"
  $SQL "$db" < "$SK/$f" && touch "$R/.seed_$f" && echo "örnek veri yüklendi: $f → $db"
done

"$@"
