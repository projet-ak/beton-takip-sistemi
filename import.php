<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin']);
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Excel Veri Aktarımı — Beton Takip Sistemi';

// ── Excel'den çıkarılan 60 kayıt ──────────────────────────────────────────────
$excelRows = [
    ['sira_no'=>1,'fatura_no'=>null,'arac_plaka'=>'06 CTY 239','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000000735','tedarikci'=>'ANADOLU BETON','tarih'=>'05.02.2026','mikser_cikis'=>'00:00:00','kantar_giris'=>'00:00:00','kantar_cikis'=>'00:00:00','kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>6.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'YILDIZLAR  D PARSEL GİRİŞ KAPISI MOBİLİZASYON'],
    ['sira_no'=>2,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000812','tedarikci'=>'ANADOLU BETON','tarih'=>'07.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C16','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'YILDIZLAR D PARSEL MOBİLİZASYON YILDIZLAR GİRİŞ YOL BETONU'],
    ['sira_no'=>3,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000806','tedarikci'=>'ANADOLU BETON','tarih'=>'07.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'YILDIZLAR D PARSEL MOBİLİZASYON GİRİŞ GÜVENLİK TURNİKE'],
    ['sira_no'=>4,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000833','tedarikci'=>'ANADOLU BETON','tarih'=>'09.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>9.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'YILDIZLAR MOBİLİZASYON D PARSEL ELEKTİRİK KÖŞK ALTI'],
    ['sira_no'=>5,'fatura_no'=>null,'arac_plaka'=>'34 MYZ 032','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000832','tedarikci'=>'ANADOLU BETON','tarih'=>'09.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>9.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'YILDIZLAR MOBİLİZASYON D PARSEL ELEKTİRİK KÖŞK ALTI'],
    ['sira_no'=>6,'fatura_no'=>null,'arac_plaka'=>'34 KPR 612','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000942','tedarikci'=>'ANADOLU BETON','tarih'=>'12.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZSASYON BETENO PARSEL GİRİŞİ YOL BETONU YILDIZLAR'],
    ['sira_no'=>7,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000944','tedarikci'=>'ANADOLU BETON','tarih'=>'12.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZSASYON BETENO PARSEL GİRİŞİ YOL BETONU YILDIZLAR'],
    ['sira_no'=>8,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000946','tedarikci'=>'ANADOLU BETON','tarih'=>'12.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZSASYON BETENO PARSEL GİRİŞİ YOL BETONU YILDIZLAR'],
    ['sira_no'=>9,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000950','tedarikci'=>'ANADOLU BETON','tarih'=>'12.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZSASYON BETENO PARSEL GİRİŞİ YOL BETONU YILDIZLAR'],
    ['sira_no'=>10,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000000952','tedarikci'=>'ANADOLU BETON','tarih'=>'12.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZSASYON BETENO PARSEL GİRİŞİ YOL BETONU YILDIZLAR'],
    ['sira_no'=>11,'fatura_no'=>null,'arac_plaka'=>'06 CTY 239','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001119','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>12,'fatura_no'=>null,'arac_plaka'=>'34 KPR 612','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001118','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>13,'fatura_no'=>null,'arac_plaka'=>'34 MYZ 032','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001120','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>14,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001122','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>15,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001123','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>16,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001124','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>17,'fatura_no'=>null,'arac_plaka'=>'34 KPR 612','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001125','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>18,'fatura_no'=>null,'arac_plaka'=>'06 CTY 239','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001126','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>19,'fatura_no'=>null,'arac_plaka'=>'06 CTL 158','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001127','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>20,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001130','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>21,'fatura_no'=>null,'arac_plaka'=>'06 CTL 158','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001134','tedarikci'=>'ANADOLU BETON','tarih'=>'16.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON DEPO TEMEL BETONU'],
    ['sira_no'=>22,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001303','tedarikci'=>'ANADOLU BETON','tarih'=>'21.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>5.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D-C PARSEL ARASI YOL KENARI KORKULUK PARAPET'],
    ['sira_no'=>23,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001293','tedarikci'=>'ANADOLU BETON','tarih'=>'21.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON KANTAR RAMPA BETONU'],
    ['sira_no'=>24,'fatura_no'=>null,'arac_plaka'=>'06 CTL 158','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001292','tedarikci'=>'ANADOLU BETON','tarih'=>'21.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON KANTAR RAMPA BETONU'],
    ['sira_no'=>25,'fatura_no'=>null,'arac_plaka'=>'34 MYZ 032','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001295','tedarikci'=>'ANADOLU BETON','tarih'=>'21.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>6.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL MOBİLİZASYON KANTAR RAMPA BETONU'],
    ['sira_no'=>26,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001340','tedarikci'=>'ANADOLU BETON','tarih'=>'23.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>27,'fatura_no'=>null,'arac_plaka'=>'06 CTY 239','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001344','tedarikci'=>'ANADOLU BETON','tarih'=>'23.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>28,'fatura_no'=>null,'arac_plaka'=>'34 MYZ 032','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001342','tedarikci'=>'ANADOLU BETON','tarih'=>'23.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>7.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>29,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001345','tedarikci'=>'ANADOLU BETON','tarih'=>'23.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>30,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001371','tedarikci'=>'ANADOLU BETON','tarih'=>'24.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>31,'fatura_no'=>null,'arac_plaka'=>'06 CTL 158','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001367','tedarikci'=>'ANADOLU BETON','tarih'=>'24.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>32,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001364','tedarikci'=>'ANADOLU BETON','tarih'=>'24.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>33,'fatura_no'=>null,'arac_plaka'=>'06 CTL 158','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001358','tedarikci'=>'ANADOLU BETON','tarih'=>'24.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'OSMAN_CAMCI','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_BLOK','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'OSMAN CAMCI A PARSEL 7 NOLU DUVAR KAZIK BETONU'],
    ['sira_no'=>34,'fatura_no'=>null,'arac_plaka'=>'06 CTY 239','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001384','tedarikci'=>'ANADOLU BETON','tarih'=>'25.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>5.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'YILDIZLAR','imalat_grup'=>'Diger','ana_is_kalemi'=>'DOLGU BETONU','parsel'=>'A_PARSEL','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'A PARSEL YILDIZLAR DOLGU İÇİN PARAPET BETONU'],
    ['sira_no'=>35,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001377','tedarikci'=>'ANADOLU BETON','tarih'=>'25.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>2.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL FORE KAZIK 7 NOLU PERDE BETONU'],
    ['sira_no'=>36,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001376','tedarikci'=>'ANADOLU BETON','tarih'=>'25.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL FORE KAZIK 7 NOLU PERDE BETONU'],
    ['sira_no'=>37,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001397','tedarikci'=>'ANADOLU BETON','tarih'=>'25.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL FORE KAZIK 7 NOLU PERDE BETONU'],
    ['sira_no'=>38,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001407','tedarikci'=>'ANADOLU BETON','tarih'=>'25.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL FORE KAZIK 7 NOLU PERDE BETONU'],
    ['sira_no'=>39,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001411','tedarikci'=>'ANADOLU BETON','tarih'=>'25.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL FORE KAZIK 7 NOLU PERDE BETONU'],
    ['sira_no'=>40,'fatura_no'=>null,'arac_plaka'=>'34 MYZ 032','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001417','tedarikci'=>'ANADOLU BETON','tarih'=>'26.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL KAZIK 7 NOLU DUVAR BETONU'],
    ['sira_no'=>41,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001423','tedarikci'=>'ANADOLU BETON','tarih'=>'2026-02-26','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>4.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'A_PARSEL','imalat_grup'=>'Zemin','ana_is_kalemi'=>'FORE KAZIK','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL KAZIK 7 NOLU DUVAR BETONU'],
    ['sira_no'=>0,'fatura_no'=>null,'arac_plaka'=>'06CTY232','kivam_sinifi'=>null,'irsaliye_no'=>'ANM2026000001443','tedarikci'=>'ANADOLU BETON','tarih'=>'2026-02-27','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0,'kantar_net_tedarikci'=>0,'kantar_farki'=>0,'beton_sinifi'=>'C30','miktar'=>7.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>null,'katki2'=>null,'firma'=>null,'imalat_grup'=>null,'ana_is_kalemi'=>null,'parsel'=>null,'blok'=>null,'kot'=>null,'aciklama'=>null],
    ['sira_no'=>42,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000001512','tedarikci'=>'ANADOLU BETON','tarih'=>'28.02.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C20','miktar'=>9.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'GROBETON','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU İSTİNAT PERDESİ'],
    ['sira_no'=>43,'fatura_no'=>null,'arac_plaka'=>'34 KPR 612','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001574','tedarikci'=>'ANADOLU BETON','tarih'=>'03.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'DÖŞEME','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU DUVAR TEMELİ'],
    ['sira_no'=>44,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001570','tedarikci'=>'ANADOLU BETON','tarih'=>'03.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'DÖŞEME','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU DUVAR TEMELİ'],
    ['sira_no'=>45,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001571','tedarikci'=>'ANADOLU BETON','tarih'=>'03.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'DÖŞEME','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU DUVAR TEMELİ'],
    ['sira_no'=>46,'fatura_no'=>null,'arac_plaka'=>'06 CTL 158','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001573','tedarikci'=>'ANADOLU BETON','tarih'=>'03.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>8.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'DÖŞEME','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU DUVAR TEMELİ'],
    ['sira_no'=>47,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001741','tedarikci'=>'ANADOLU BETON','tarih'=>'07.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>1.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>null,'imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'GENEL','blok'=>'GENEL','kot'=>'GENEL','aciklama'=>null],
    ['sira_no'=>48,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001740','tedarikci'=>'ANADOLU BETON','tarih'=>'07.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>4.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>null,'imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'GENEL','blok'=>'GENEL','kot'=>'GENEL','aciklama'=>null],
    ['sira_no'=>49,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001809','tedarikci'=>'ANADOLU BETON','tarih'=>'10.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>6.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL DEPO VE WC KONTEYNIR TEMEL BETONU'],
    ['sira_no'=>50,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001814','tedarikci'=>'ANADOLU BETON','tarih'=>'10.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>5.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL DEPO VE WC KONTEYNIR TEMEL BETONU'],
    ['sira_no'=>51,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S3','irsaliye_no'=>'ANM2026000001805','tedarikci'=>'ANADOLU BETON','tarih'=>'10.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C30','miktar'=>10.0,'birim'=>'M3','pompa'=>'MİKSERLİ','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Diger','ana_is_kalemi'=>'MOBİLİZASYON','parsel'=>'D_PARSEL_1','blok'=>'MOBILIZASYON','kot'=>'MOBILIZASYON','aciklama'=>'D PARSEL DEPO VE WC KONTEYNIR TEMEL BETONU'],
    ['sira_no'=>52,'fatura_no'=>null,'arac_plaka'=>'06 CTY 239','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000001956','tedarikci'=>'ANADOLU BETON','tarih'=>'13.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>3.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'PARAPET','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU İSTİNAT PERDESİ'],
    ['sira_no'=>53,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000001953','tedarikci'=>'ANADOLU BETON','tarih'=>'13.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'PARAPET','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU İSTİNAT PERDESİ'],
    ['sira_no'=>54,'fatura_no'=>null,'arac_plaka'=>'06 CTY 232','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000001951','tedarikci'=>'ANADOLU BETON','tarih'=>'13.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'PARAPET','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU İSTİNAT PERDESİ'],
    ['sira_no'=>55,'fatura_no'=>null,'arac_plaka'=>'34 BML 414','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000001954','tedarikci'=>'ANADOLU BETON','tarih'=>'13.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'PARAPET','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU İSTİNAT PERDESİ'],
    ['sira_no'=>56,'fatura_no'=>null,'arac_plaka'=>'34 MYZ 032','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000001952','tedarikci'=>'ANADOLU BETON','tarih'=>'13.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'PARAPET','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU İSTİNAT PERDESİ'],
    ['sira_no'=>57,'fatura_no'=>null,'arac_plaka'=>'06 CTL 070','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000002144','tedarikci'=>'ANADOLU BETON','tarih'=>'17.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>9.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'KOLON-PERDE','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU PERDE BETONU'],
    ['sira_no'=>58,'fatura_no'=>null,'arac_plaka'=>'34 BML 534','kivam_sinifi'=>'S4','irsaliye_no'=>'ANM2026000002137','tedarikci'=>'ANADOLU BETON','tarih'=>'17.03.2026','mikser_cikis'=>null,'kantar_giris'=>null,'kantar_cikis'=>null,'kantar_net_yildiz'=>0.0,'kantar_net_tedarikci'=>0.0,'kantar_farki'=>0.0,'beton_sinifi'=>'C35','miktar'=>10.0,'birim'=>'M3','pompa'=>'POMPALI','katki1'=>'BRÜT BETON','katki2'=>null,'firma'=>'DENER','imalat_grup'=>'Kaba','ana_is_kalemi'=>'KOLON-PERDE','parsel'=>'A_PARSEL','blok'=>'ISTINAT_CEVRE_DUVARI','kot'=>'ISTINAT_CEVRE_DUVARI','aciklama'=>'A PARSEL 7 NOLU PERDE BETONU'],
];

// Yardımcı: tabloda yoksa ekle, ID döndür
function getOrCreate(PDO $pdo, string $tablo, string $sutun, string $deger): ?int {
    if (!$deger) return null;
    $deger = trim($deger);
    $s = $pdo->prepare("SELECT id FROM $tablo WHERE $sutun = ?");
    $s->execute([$deger]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO $tablo ($sutun) VALUES (?)")->execute([$deger]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateImalat(PDO $pdo, string $ad): ?int {
    if (!$ad) return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM imalat_gruplari WHERE ad = ?");
    $s->execute([$ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO imalat_gruplari (ad, sira) VALUES (?, 99)")->execute([$ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateAnaKalem(PDO $pdo, int $grupId, string $ad): ?int {
    if (!$ad) return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM ana_is_kalemleri WHERE imalat_grup_id = ? AND ad = ?");
    $s->execute([$grupId, $ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO ana_is_kalemleri (imalat_grup_id, ad, sira) VALUES (?, ?, 99)")->execute([$grupId, $ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateParsel(PDO $pdo, string $ad): ?int {
    if (!$ad) return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM parseller WHERE ad = ?");
    $s->execute([$ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO parseller (ad) VALUES (?)")->execute([$ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateBlok(PDO $pdo, int $parselId, string $ad): ?int {
    if (!$ad) return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM bloklar WHERE parsel_id = ? AND ad = ?");
    $s->execute([$parselId, $ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO bloklar (parsel_id, ad) VALUES (?, ?)")->execute([$parselId, $ad]);
    return (int)$pdo->lastInsertId();
}

function getOrCreateKot(PDO $pdo, int $blokId, string $ad): ?int {
    if (!$ad) return null;
    $ad = trim($ad);
    $s = $pdo->prepare("SELECT id FROM kotlar WHERE blok_id = ? AND kot_degeri = ?");
    $s->execute([$blokId, $ad]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO kotlar (blok_id, kot_degeri) VALUES (?, ?)")->execute([$blokId, $ad]);
    return (int)$pdo->lastInsertId();
}

function parseTarih(string $tarih): ?string {
    if (!$tarih) return null;
    // Format: 05.02.2026 → 2026-02-05
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $tarih, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return null;
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $added = 0; $skipped = 0; $errors = [];

    foreach ($excelRows as $r) {
        try {
            $tarih = parseTarih($r['tarih'] ?? '');
            if (!$tarih) { $skipped++; continue; }

            // FK lookups / auto-create
            $tedarikciId = getOrCreate($pdo, 'tedarikciler', 'ad', trim($r['tedarikci'] ?? ''));
            if (!$tedarikciId) { $skipped++; continue; }

            $betonId    = getOrCreate($pdo, 'beton_siniflari', 'ad',  trim($r['beton_sinifi'] ?? ''));
            $pompaId    = getOrCreate($pdo, 'pompa_turleri',   'ad',  trim($r['pompa'] ?? ''));
            $katki1Id   = getOrCreate($pdo, 'katki_listesi',   'ad',  trim($r['katki1'] ?? ''));
            $katki2Id   = getOrCreate($pdo, 'katki_listesi',   'ad',  trim($r['katki2'] ?? ''));
            $firmaId    = getOrCreate($pdo, 'firmalar',        'ad',  trim($r['firma'] ?? ''));
            $kivamId    = getOrCreate($pdo, 'kivam_siniflari', 'ad',  trim($r['kivam_sinifi'] ?? ''));

            $imalatGrupId  = getOrCreateImalat($pdo, trim($r['imalat_grup'] ?? ''));
            $anaKalemId    = $imalatGrupId ? getOrCreateAnaKalem($pdo, $imalatGrupId, trim($r['ana_is_kalemi'] ?? '')) : null;
            $parselId      = getOrCreateParsel($pdo, trim($r['parsel'] ?? ''));
            $blokId        = $parselId ? getOrCreateBlok($pdo, $parselId, trim($r['blok'] ?? '')) : null;
            $kotId         = $blokId   ? getOrCreateKot($pdo, $blokId, trim($r['kot'] ?? ''))   : null;

            // Duplicate check by irsaliye_no
            if ($r['irsaliye_no']) {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM irsaliyeler WHERE irsaliye_no = ?");
                $dup->execute([$r['irsaliye_no']]);
                if ($dup->fetchColumn() > 0) { $skipped++; continue; }
            }

            $mikserCikis = ($r['mikser_cikis'] && $r['mikser_cikis'] !== '00:00:00') ? $r['mikser_cikis'] : null;
            $kantarGiris = ($r['kantar_giris'] && $r['kantar_giris'] !== '00:00:00') ? $r['kantar_giris'] : null;
            $kantarCikis = ($r['kantar_cikis'] && $r['kantar_cikis'] !== '00:00:00') ? $r['kantar_cikis'] : null;

            $pdo->prepare("INSERT INTO irsaliyeler
                (tip, sira_no, fatura_no, arac_plaka, kivam_sinifi_id, irsaliye_no,
                 tedarikci_id, tarih,
                 mikser_cikis_saati, kantar_giris_saati, kantar_cikis_saati,
                 kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
                 beton_sinifi_id, miktar, birim, pompa_id,
                 katki1_id, katki2_id, firma_id,
                 imalat_grup_id, ana_is_kalemi_id,
                 parsel_id, blok_id, kot_id, aciklama)
                VALUES ('alis',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $r['sira_no'], $r['fatura_no'], $r['arac_plaka'],
                    $kivamId, $r['irsaliye_no'],
                    $tedarikciId, $tarih,
                    $mikserCikis, $kantarGiris, $kantarCikis,
                    $r['kantar_net_yildiz'], $r['kantar_net_tedarikci'], $r['kantar_farki'],
                    $betonId, $r['miktar'], $r['birim'], $pompaId,
                    $katki1Id, $katki2Id, $firmaId,
                    $imalatGrupId, $anaKalemId,
                    $parselId, $blokId, $kotId, $r['aciklama'],
                ]);
            $added++;
        } catch (PDOException $e) {
            $errors[] = "Sıra {$r['sira_no']}: " . h($e->getMessage());
            $skipped++;
        }
    }
    $result = compact('added', 'skipped', 'errors');
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-earmark-spreadsheet text-primary me-2"></i>Excel Veri Aktarımı</h4>
    <a href="irsaliyeler.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>İrsaliyelere Dön</a>
</div>

<?php if ($result): ?>
<div class="alert alert-<?= $result['added'] > 0 ? 'success' : 'info' ?> d-flex align-items-start gap-3">
    <i class="bi bi-check-circle-fill fs-4 mt-1"></i>
    <div>
        <strong>Aktarım Tamamlandı</strong><br>
        <span class="text-success fw-bold"><?= $result['added'] ?> kayıt eklendi</span>,
        <span class="text-secondary"><?= $result['skipped'] ?> kayıt atlandı (zaten mevcut veya hatalı)</span>
        <?php if ($result['errors']): ?>
        <ul class="mt-2 mb-0 small text-danger">
            <?php foreach (array_slice($result['errors'], 0, 10) as $e): ?>
            <li><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-warning text-dark fw-semibold">
        <i class="bi bi-exclamation-triangle me-1"></i> Aktarım Hakkında
    </div>
    <div class="card-body">
        <ul class="mb-3">
            <li>Toplam <strong><?= count($excelRows) ?></strong> kayıt aktarılmaya hazır (Beton Takip Tablosu ALIŞLAR sayfası)</li>
            <li>İrsaliye numarası zaten mevcut olan kayıtlar <strong>atlanır</strong> (tekrar eklenmez)</li>
            <li>Tedarikçi, beton sınıfı, pompa, katkı, firma, parsel, blok, kot gibi referans veriler <strong>otomatik oluşturulur</strong></li>
            <li>Bu işlem geri alınamaz — önce mevcut verileri kontrol edin</li>
        </ul>
        <form method="post" onsubmit="return confirm('<?= count($excelRows) ?> kayıt aktarılacak. Devam etmek istiyor musunuz?')">
            <button name="import" value="1" class="btn btn-warning">
                <i class="bi bi-cloud-upload me-1"></i> Excel Verilerini Aktar (<?= count($excelRows) ?> kayıt)
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-1"></i> Aktarılacak Kayıtlar (Önizleme)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:500px;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>#</th>
                        <th>İrsaliye No</th>
                        <th>Tedarikçi</th>
                        <th>Tarih</th>
                        <th>Beton</th>
                        <th class="text-end">Miktar</th>
                        <th>Pompa</th>
                        <th>Parsel</th>
                        <th>Açıklama</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($excelRows as $i => $r): ?>
                    <tr>
                        <td class="text-muted small"><?= $r['sira_no'] ?></td>
                        <td class="font-monospace small"><?= h($r['irsaliye_no'] ?? '-') ?></td>
                        <td><?= h($r['tedarikci'] ?? '-') ?></td>
                        <td class="text-nowrap"><?= h($r['tarih'] ?? '-') ?></td>
                        <td><span class="badge bg-secondary"><?= h($r['beton_sinifi'] ?? '-') ?></span></td>
                        <td class="text-end"><?= number_format($r['miktar'], 1) ?> <?= h($r['birim']) ?></td>
                        <td><?= h($r['pompa'] ?? '-') ?></td>
                        <td><?= h($r['parsel'] ?? '-') ?></td>
                        <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($r['aciklama'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
