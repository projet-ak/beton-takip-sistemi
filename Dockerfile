FROM php:8.3-apache

# Apache modülleri
RUN a2enmod rewrite headers

# Sistem paketleri
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    libonig-dev \
    unzip \
    git \
    zbar-tools \
    && rm -rf /var/lib/apt/lists/*

# PHP eklentileri
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install \
    pdo \
    pdo_mysql \
    gd \
    mbstring \
    zip \
    bcmath \
    opcache

# PHP üretim ayarları
RUN echo "upload_max_filesize=64M"   >> /usr/local/etc/php/conf.d/beton.ini \
 && echo "post_max_size=64M"         >> /usr/local/etc/php/conf.d/beton.ini \
 && echo "memory_limit=256M"         >> /usr/local/etc/php/conf.d/beton.ini \
 && echo "max_execution_time=120"    >> /usr/local/etc/php/conf.d/beton.ini \
 && echo "opcache.enable=1"          >> /usr/local/etc/php/conf.d/beton.ini

# Apache sanal host
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Klasör izinleri (uploads, backups, scans)
RUN mkdir -p uploads/irsaliye_fotolar uploads/scans backups \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html

EXPOSE 80
