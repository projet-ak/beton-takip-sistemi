-- Beton Takip Sistemi — Docker ilk kurulum SQL
-- Bu dosya MySQL container ilk başladığında çalışır

CREATE DATABASE IF NOT EXISTS `beton_takip`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `beton_takip`;

-- Yetki
GRANT ALL PRIVILEGES ON `beton_takip`.* TO 'beton'@'%';
FLUSH PRIVILEGES;
