<?php
/** firmalar.php — Seramik gönderen firmalar (tanım) */
$rootPath='../';
require_once __DIR__.'/../includes/functions.php'; require_once __DIR__.'/../includes/auth.php';
if(!file_exists(__DIR__.'/../config.php')){redirect('../install.php');}
require_auth(['admin','teknik_ofis_admin']); require_once __DIR__.'/../includes/db_seramik.php';
$pageTitle='Firmalar — Seramik'; $tablo='seramik_firmalar'; $baslik='Gönderen Firmalar'; $ikon='bi-shop';
require __DIR__.'/_tanim.php';
