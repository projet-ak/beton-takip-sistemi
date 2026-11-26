-- Örnek veri: ekranlar dolu görünsün diye (gerçek veri DEĞİL). up.sh ilk kurulumda bir kez yükler.
INSERT INTO tedarikciler (ad, vkn) VALUES
 ('SAFİ BETON ÜRETİM VE TİCARET A.Ş.','7371227729'),
 ('ANADOLU BETON SANAYİ VE TİCARET LTD. ŞTİ.','0680012345'),
 ('AKÇANSA ÇİMENTO SANAYİ VE TİCARET A.Ş.','0150012345');
SET @s=(SELECT id FROM tedarikciler WHERE vkn='7371227729'), @a=(SELECT id FROM tedarikciler WHERE vkn='0680012345'), @k=(SELECT id FROM tedarikciler WHERE vkn='0150012345');

INSERT INTO faturalar (fatura_no,tur,tarih,tedarikci_id,tutar,miktar_m3,ettn,irsaliye_adet,eksik_adet,eksik_liste,irsaliye_liste) VALUES
 ('BFA2026000001611','beton','2026-11-20',@s,1918273.28,170.00,'9b0eca73-c862-4f1e-a1b2-000000001611',16,2,'["MBT2026000013660","MBT2026000013661"]',NULL),
 ('SGA2026000001252','beton','2026-11-18',@s,  845120.50,169.00,'9b0eca73-c862-4f1e-a1b2-000000001252',21,0,'[]',NULL),
 ('AND2026000000949','pompa','2026-11-15',@a,   33600.00,NULL,  '9b0eca73-c862-4f1e-a1b2-000000000949',0,0,'[]',NULL),
 ('BFA2026000001525','fark', '2026-11-04',@s,1918273.28,NULL,  '9b0eca73-c862-4f1e-a1b2-000000001525',0,0,'[]',NULL),
 ('AKC2026000004410','beton','2026-11-02',@k,  412880.00, 82.50,'9b0eca73-c862-4f1e-a1b2-000000004410',9,1,'["AKI2026000009911"]',NULL),
 ('BFA2026000001416','beton','2026-10-10',@s,  998412.75,124.00,'9b0eca73-c862-4f1e-a1b2-000000001416',14,0,'[]',NULL),
 ('SGB2026000000088','beton','2026-10-10',@s,  120000.00, 18.00,'9b0eca73-c862-4f1e-a1b2-000000000088',0,0,'[]',NULL);

-- 60 irsaliye, son 30 güne dağılmış
INSERT INTO irsaliyeler (tip,durum,irsaliye_no,tedarikci_id,tarih,miktar,arac_plaka)
SELECT 'alis','onaylandi', CONCAT('MBT2026', LPAD(13600+n,9,'0')), IF(n%3=0,@a,@s),
       DATE_SUB(CURDATE(), INTERVAL (n%30) DAY), 8 + (n%5)*1.5, CONCAT('34 ABC ', 100+n)
FROM (SELECT a.n*10+b.n AS n FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) a
      CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b) t;
UPDATE irsaliyeler SET fatura_id=(SELECT id FROM faturalar WHERE fatura_no='BFA2026000001611'), fatura_no='BFA2026000001611' WHERE id<=16;
