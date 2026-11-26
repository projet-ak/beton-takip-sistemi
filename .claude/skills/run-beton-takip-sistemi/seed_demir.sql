-- Demir örnek verisi (gerçek veri DEĞİL): 3 tedarikçi, 6 fatura, faturaya bağlı sevkiyatlar (biri tartılmış).
INSERT INTO demir_tedarikciler (ad, vkn) VALUES
 ('KARADENİZ DEMİR ÇELİK SANAYİ VE TİCARET A.Ş.','5090025659'),
 ('TOKAL DEMİR ÇELİK SANAYİ VE TİCARET ANONİM ŞİRKETİ','8460012345'),
 ('ÇAKIROĞLU DEMİR SANAYİ VE TİCARET LTD. ŞTİ.','2150012345');
SET @k=(SELECT id FROM demir_tedarikciler WHERE vkn='5090025659'), @t=(SELECT id FROM demir_tedarikciler WHERE vkn='8460012345'), @c=(SELECT id FROM demir_tedarikciler WHERE vkn='2150012345');
SET @c12=(SELECT id FROM demir_caplar WHERE ad='Ø12'), @c20=(SELECT id FROM demir_caplar WHERE ad='Ø20'), @c28=(SELECT id FROM demir_caplar WHERE ad='Ø28');

INSERT INTO demir_faturalar (fatura_no,ettn,tarih,tedarikci,tedarikci_id,irsaliye_liste,miktar_kg,tutar,kalemler) VALUES
 ('KRD2026000021650','9b0eca73-0000-4f1e-a1b2-000000021650','2026-11-20','KARADENİZ DEMİR ÇELİK SANAYİ VE TİCARET A.Ş.',@k,'["KRI2026000027357","KRI2026000027358"]',51080,901349.16,'[{"cap":20,"kg":25540,"irs":""},{"cap":28,"kg":25540,"irs":""}]'),
 ('KRD2026000021651','9b0eca73-0000-4f1e-a1b2-000000021651','2026-11-20','KARADENİZ DEMİR ÇELİK SANAYİ VE TİCARET A.Ş.',@k,'["KRI2026000027349"]',2120,75401.33,'[{"cap":12,"kg":2120,"irs":""}]'),
 ('TDC2026000002132','9b0eca73-0000-4f1e-a1b2-000000002132','2026-11-12','TOKAL DEMİR ÇELİK SANAYİ VE TİCARET ANONİM ŞİRKETİ',@t,'["TDC2026000002259"]',25940,903576.67,'[{"cap":12,"kg":25940,"irs":""}]'),
 ('TDC2026000002131','9b0eca73-0000-4f1e-a1b2-000000002131','2026-11-12','TOKAL DEMİR ÇELİK SANAYİ VE TİCARET ANONİM ŞİRKETİ',@t,'["TDC2026000002258"]',25820,899396.67,'[{"cap":12,"kg":25820,"irs":""}]'),
 ('CKE2026000005034','9b0eca73-0000-4f1e-a1b2-000000005034','2026-11-05','ÇAKIROĞLU DEMİR SANAYİ VE TİCARET LTD. ŞTİ.',@c,'["CKI2026000005315"]',25100,874120.00,'[{"cap":28,"kg":25100,"irs":""}]'),
 ('CKE2026000005150','9b0eca73-0000-4f1e-a1b2-000000005150','2026-11-06','ÇAKIROĞLU DEMİR SANAYİ VE TİCARET LTD. ŞTİ.',@c,'["CKI2026000005440"]',NULL,NULL,NULL);

INSERT INTO demir_sevkiyatlar (irsaliye_no,gelis_tarih,tedarikci_id,aciklama,fatura_id) VALUES
 ('KRI2026000027357','2026-11-20',@k,'[FATURADAN]',(SELECT id FROM demir_faturalar WHERE fatura_no='KRD2026000021650')),
 ('KRI2026000027349','2026-11-20',@k,'[FATURADAN]',(SELECT id FROM demir_faturalar WHERE fatura_no='KRD2026000021651')),
 ('TDC2026000002259','2026-11-12',@t,'[FATURADAN]',(SELECT id FROM demir_faturalar WHERE fatura_no='TDC2026000002132')),
 ('TDC2026000002258','2026-11-12',@t,NULL,(SELECT id FROM demir_faturalar WHERE fatura_no='TDC2026000002131')),
 ('CKI2026000005315','2026-11-05',@c,'[FATURADAN]',(SELECT id FROM demir_faturalar WHERE fatura_no='CKE2026000005034'));
INSERT INTO demir_sevkiyat_kalemleri (sevkiyat_id,cap_id,irsaliye_miktar,kantar_miktar) VALUES
 ((SELECT id FROM demir_sevkiyatlar WHERE irsaliye_no='KRI2026000027357'),@c20,25.540,NULL),
 ((SELECT id FROM demir_sevkiyatlar WHERE irsaliye_no='KRI2026000027349'),@c12,2.120,NULL),
 ((SELECT id FROM demir_sevkiyatlar WHERE irsaliye_no='TDC2026000002259'),@c12,25.940,NULL),
 ((SELECT id FROM demir_sevkiyatlar WHERE irsaliye_no='TDC2026000002258'),@c12,25.820,25.760),
 ((SELECT id FROM demir_sevkiyatlar WHERE irsaliye_no='CKI2026000005315'),@c28,25.100,NULL);
UPDATE demir_faturalar SET dosya_url='uploads/demir_fatura/2026/11/ornek.pdf' WHERE fatura_no<>'CKE2026000005150';
-- Çift faturalama kontrolü örnekleri: aynı gün aynı tutar (sipariş araç için ikiye bölünmüş) + aynı irsaliye iki faturada
INSERT INTO demir_faturalar (fatura_no,ettn,tarih,tedarikci,tedarikci_id,irsaliye_liste,miktar_kg,tutar,kalemler) VALUES
 ('KRD2026000020432','9b0eca73-0000-4f1e-a1b2-000000020432','2026-11-05','KARADENİZ DEMİR ÇELİK SANAYİ VE TİCARET A.Ş.',@k,'["KRI2026000026001"]',25120,875042.66,'[{"cap":20,"kg":25120,"irs":""}]'),
 ('KRD2026000020395','9b0eca73-0000-4f1e-a1b2-000000020395','2026-11-05','KARADENİZ DEMİR ÇELİK SANAYİ VE TİCARET A.Ş.',@k,'["KRI2026000026002"]',25120,875042.66,'[{"cap":20,"kg":25120,"irs":""}]'),
 ('TDC2026000002199','9b0eca73-0000-4f1e-a1b2-000000002199','2026-11-13','TOKAL DEMİR ÇELİK SANAYİ VE TİCARET ANONİM ŞİRKETİ',@t,'["TDC2026000002258"]',25820,899396.67,'[{"cap":12,"kg":25820,"irs":""}]');
