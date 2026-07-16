<?php
/** taseronlar.php — Seramik çıkış taşeronları (tanım) */
$rootPath='../';
require_once __DIR__.'/../includes/functions.php'; require_once __DIR__.'/../includes/auth.php';
if(!file_exists(__DIR__.'/../config.php')){redirect('../install.php');}
require_auth(['admin','teknik_ofis_admin']); require_once __DIR__.'/../includes/db_seramik.php';
$pageTitle='Taşeronlar — Seramik'; $tablo='seramik_taseronlar'; $baslik='Çıkış Taşeronları'; $ikon='bi-people';
require __DIR__.'/_tanim.php';
