#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# ERN Beton Takip — VPS Otomatik Kurulum
# Ubuntu 22.04 için / Hetzner CX22 tavsiye edilir
# Kullanım: sudo bash deploy-vps.sh
# ═══════════════════════════════════════════════════════════════════

set -e

DOMAIN="${1:-beton.ernholding.com}"
DB_PASS="${2:-$(openssl rand -base64 16)}"
DB_ROOT="${3:-$(openssl rand -base64 16)}"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ERN Beton Takip — VPS Kurulumu"
echo "  Domain : $DOMAIN"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── 1. Sistem güncellemesi ─────────────────────────────────────────
apt-get update -qq
apt-get upgrade -y -qq

# ── 2. Apache + PHP 8.3 ───────────────────────────────────────────
apt-get install -y -qq \
  apache2 \
  software-properties-common

add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq \
  php8.3 \
  php8.3-mysql \
  php8.3-gd \
  php8.3-mbstring \
  php8.3-zip \
  php8.3-bcmath \
  php8.3-opcache \
  php8.3-xml \
  php8.3-curl \
  libapache2-mod-php8.3

# ── 3. MySQL 8.0 ─────────────────────────────────────────────────
apt-get install -y -qq mysql-server

mysql -e "CREATE DATABASE IF NOT EXISTS beton_takip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'beton'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL ON beton_takip.* TO 'beton'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# ── 4. PHP ayarları ───────────────────────────────────────────────
PHP_INI="/etc/php/8.3/apache2/conf.d/beton.ini"
cat > $PHP_INI << PHPINI
upload_max_filesize = 64M
post_max_size       = 64M
memory_limit        = 256M
max_execution_time  = 120
opcache.enable      = 1
opcache.memory_consumption = 128
PHPINI

# ── 5. Proje dosyaları ────────────────────────────────────────────
WEB_DIR="/var/www/beton"
mkdir -p $WEB_DIR
# Dosyaları buraya kopyalayın: scp -r . root@sunucu:/var/www/beton/
# VEYA git clone kullanın:
# git clone https://github.com/KULLANICI/beton-takip.git $WEB_DIR

# config.php oluştur
cat > $WEB_DIR/config.php << CONFIG
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'beton_takip');
define('DB_USER', 'beton');
define('DB_PASS', '$DB_PASS');
CONFIG

# Klasör izinleri
mkdir -p $WEB_DIR/uploads/irsaliye_fotolar $WEB_DIR/uploads/scans $WEB_DIR/backups
chown -R www-data:www-data $WEB_DIR
chmod -R 755 $WEB_DIR
chmod -R 775 $WEB_DIR/uploads $WEB_DIR/backups

# ── 6. Apache sanal host ─────────────────────────────────────────
a2enmod rewrite headers ssl

cat > /etc/apache2/sites-available/beton.conf << VHOST
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $WEB_DIR

    <Directory $WEB_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options SAMEORIGIN

    ErrorLog  /var/log/apache2/beton_error.log
    CustomLog /var/log/apache2/beton_access.log combined
</VirtualHost>
VHOST

a2ensite beton.conf
a2dissite 000-default.conf
systemctl restart apache2

# ── 7. SSL (Let's Encrypt) ────────────────────────────────────────
apt-get install -y -qq certbot python3-certbot-apache
certbot --apache -d $DOMAIN --non-interactive --agree-tos \
  --email admin@ernholding.com --redirect || echo "SSL kurulumu atlandı"

# ── 8. UFW Firewall ───────────────────────────────────────────────
ufw --force enable
ufw allow ssh
ufw allow 80
ufw allow 443

# ── 9. Güvenlik: config.php dış erişim engeli ────────────────────
echo '<Files "config.php"><Require all denied></Files>' >> $WEB_DIR/.htaccess

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  KURULUM TAMAMLANDI!"
echo "  Site: https://$DOMAIN"
echo "  DB Şifre: $DB_PASS  (güvenli yere kaydedin!)"
echo ""
echo "  Sonraki adımlar:"
echo "  1. Dosyaları sunucuya kopyalayın"
echo "  2. https://$DOMAIN/install.php → ilk kurulum"
echo "  3. https://$DOMAIN/migrate.php → DB güncelleme"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
