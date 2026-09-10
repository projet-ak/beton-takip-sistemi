# CLAUDE.md — Proje Hafızası & Geliştirme Rehberi

Bu dosya, gelecekteki Claude oturumları için projenin tam haritasını, iş akışlarını,
konvansiyonları ve dağıtım sürecini içerir. Yeni bir görevden önce **bunu oku**.

---

## 1. Proje Özeti

**ERN Holding — Beton & Demir Takip Sistemi.** Saf PHP (PDO/MySQL) + Bootstrap 5.3
tabanlı, **çok modüllü** irsaliye/sevkiyat takip uygulaması.

- **Beton modülü** = kök dizin (`/`). Hazır beton irsaliyeleri, iki aşamalı onay, raporlar.
- **Demir modülü** = `demir/` alt klasörü. İnşaat demiri (rebar) sipariş→sevkiyat→kantar→
  taşerona teslim (tutanak)→icmal zinciri. **Ayrı veritabanı** kullanır.
- **Seramik modülü** = `seramik/` alt klasörü. Seramik ambar giriş/çıkış + canlı stok.
  **Ayrı veritabanı** (`takbulut_seramik`, `SERAMIK_DB_NAME`). Tablolar `seramik_` önekli.
  Stok = **AMBAR MEVCUT'a sabit** (SAYIM−GİDEN) + elle giriş − elle çıkış (Excel log'ları
  SAYIM/GİDEN'de zaten sayılı olduğundan stoka eklenmez). `includes/db_seramik.php` → `$pdoSeramik`.
  Sayfalar: index(dashboard + aylık giriş/çıkış Chart.js trendi) · girisler/giris_form · cikislar/cikis_form · stok · paletler ·
  import (Giriş/Çıkış/Mevcut/Palet tam yenileme) · malzemeler/firmalar/taseronlar · kurulum_seramik · **zayiat** (malzeme bazlı teorik m² vs ambar çıkışı; limit varsayılan %7; tablo `seramik_metraj` runtime). raporlar'a **PDF/Yazdır** (ERN Taahhüt logolu) eklendi. raporlar (Chart.js + Excel: tür/malzeme stok, aylık giriş/çıkış).
  Malzeme eşleşmesi `sr_norm()` (I/İ katlama, *→X). `seramik/_ortak.php` ortak yardımcılar.
- **Depo modülü** = `depo/` alt klasörü. Sarf malzeme + demirbaş + el aletleri stok/zimmet takibi.
  **Ayrı veritabanı** (`takbulut_depo`, `DEPO_DB_NAME`). Tek tablo `depo_kalemler` (kategori ENUM
  `demirbas`/`sarf`/`el_aleti`). **Stok = SAYIM + GELEN − GİDEN** (kalem defteri modeli; ayrı log yok).
  `includes/db_depo.php` → `$pdoDepo`. **Hareket defteri** `depo_hareketler` (tur giris/cikis × kaynak depo/taseron;
  tarih, belge_tarihi, belge_no [irsaliye no / fiş no], malzeme, ozellik, birim, miktar, firma [gönderen/çıkış
  yapılan/taşeron], teslim_alan, onay, lokasyon, aciklama, elle [günlük kayıt], kalem_id [stok bağı], hurda [hurdaya ayırma çıkışı — Hurdalar filtresi/rozeti/KPI; hurda satırında **imzalı evrak yükleme** `evrak_url` → `uploads/depo_hareket/{id}/`]) — `depo_kalemler` stoğun FOTOĞRAFI, `depo_hareketler`
  o stoğu oluşturan TEK TEK HAREKETLER. Sayfalar: index(dashboard: kategori kartları + mali değer KPI +
  tükenen liste + **hareket özeti/son hareketler/en çok hareket gören firmalar**) · kalemler(kategori bazlı liste,
  ?k=demirbas|sarf|el_aleti, arama, stok/tutar) · kalem_form(ekle/düzenle; düzenlemede **Hurdaya Ayır** butonu → sebep/miktar/teslim alan formu → hurda çıkışı + stok düşümü + HURDAYA AYIRMA TUTANAĞI) · **hareketler**(giriş/çıkış defteri:
  tür/kaynak/firma/tarih aralığı/serbest metin filtreleri + KPI + sayfalama + Excel; elle kayıtlarda düzenle/sil;
  **her harekete belge ekleme** [girişte irsaliye/fatura, çıkışta imzalı fiş — evrak_url artık hurdaya özel değil];
  malzeme adı tıklanır → malzeme_ekstre) · **malzeme_ekstre**(malzeme kartı/İZAH: sayım bazı + tarih sıralı hareketler
  + yürüyen bakiye; bakiye eksiye düşerse kırmızı satır + "sayım öncesi stok VEYA giriş kaydı eksik" açıklama bandı —
  "olmayan ürünü nereden verdiniz?" sorusunun cevabı; stok kartı eşleşmesi dp_mal_norm ile, benzer ad önerileri) ·
  **lokasyonlar**(alan/raf bazlı stok akordeonu [kalem+stok+mali değer] + sahaya çıkışların lokasyon özeti) ·
  **firma_ekstre**(firma/taşeron seç → firmadan gelen / firmaya verilen; malzeme bazlı net + tarihli döküm) · **hareket_form**(günlük elle giriş/çıkış: `elle=1` işaretli — Excel tam yenilemesinde KORUNUR [import `elle=0` siler];
  **aranabilir malzeme açılır menüsü** — sayfanın kendi JSON ucu `?kalem_ara=` → `dp_kalem_ara()`: süzme SQL LIKE ile DEĞİL
  PHP'de `dp_mal_norm` ile (Türkçe harf duyarsız: "ampul"→"Ampül"; LIKE'ta 'İ' i/ı ile eşleşmediğinden kayıtlar sessizce
  düşüyordu), kelime sırası serbest ("salter kompakt 200"→"Tmş Kompakt Şalter 200 A"), ad başı→ad içi→özellik/kod sıralı,
  satırda stok+birim rozeti · kategori · lokasyon; ok tuşları/Enter/Esc, ⌄ düğmesi en çok stoklu 25 kalemi açar. Seçimde
  özellik/birim/lokasyon boşsa otomatik dolar, `kalem_id` gizli alana yazılır, **"stok kalemine işle"** onay kutusu
  etkinleşir (kapatılırsa yalnız deftere yazılır) ve çıkışta **"bu çıkıştan sonra kalan"** canlı hesaplanır — eksiye
  düşerse kırmızı uyarı. Kayıt → **hareket_sonuc.php**) ·
  **hareket_sonuc**(İŞLEM SONU ekranı: kayıt özeti + **fişin tüm kalemleri** tablosu, "Çıkış Fişini Yazdır",
  **"Aynı fişe malzeme ekle"** [fiş no/tarih/firma/teslim alan/onay/lokasyon URL ile forma taşınır → yalnız malzeme+miktar
  girilir; formda bilgi bandı], **imzalı fişi GERİ YÜKLE** (`dp_evrak_kaydet` → `uploads/depo_hareket/{id}/`, belge fişin
  TÜM satırlarına bağlanır) ve fişin iframe ön izlemesi) ·
  **hareket_tutanak**(A4 belge, **ERN Taahhüt** logolu: giriş=TESLİM ALMA TUTANAĞI, çıkış=**DEPO MALZEME ÇIKIŞ FİŞİ**,
  hurda=HURDAYA AYIRMA TUTANAĞI. **Bir fiş = bir belge**: `dp_fis_satirlari()` [tür+kaynak+hurda+belge no+tarih+firma]
  ile aynı fişin bütün kalemleri tek sayfada listelenir + TOPLAM satırı + sahada elle yazmak için 8'e kadar boş satır;
  fiş no yoksa belge no DEP-C-00001 üretilir; `gomulu=1` araç çubuğunu gizler [ön izleme çerçevesi]) · import(**ÇOKLU DOSYA** `dosya[]` multiple — 7 sayfa/bölüm: DEMİRBAŞLAR/
  SARF MALZEME/EL ALETLERİ → stok, MALZEME GİRİŞ-ÇIKIŞ + TAŞERON MALZEME GİRİŞ-TESLİMAT → hareket; her biri kendi
  türünde tam yenileme, dosyada olmayan sayfaya dokunulmaz — takip 2026-08'den beri **4 AYRI dosya** geliyor
  [Demirbaş Takip / Sarf Malzeme Stok (sarf+el aletleri) / Malzeme Takip (giriş+çıkış) / Sarf Taşeron Teslimat],
  **4'ü birden seçilip tek seferde yüklenir**; her dosya `dp_import_dosya()` ile kendi transaction'ında işlenir (biri
  bozuksa diğerleri yine aktarılır), aynı bölüm iki dosyada varsa son işlenen esas + uyarı. Dosya başına **veri
  doğrulama raporu** (`kontrol`: ok/uyari/bilgi + satır listesi `<details>`): Excel özet hücreleri sağlaması
  [DEMİRBAŞLAR "STOK MALİ DEĞERİ", SARF "KALEM SAYISI"], **STOK sütunu = SAYIM+GELEN−GİDEN** satır satır, eksi stok,
  sarf/el aleti mükerrer ad+özellik kartı (demirbaşta yapılmaz — aynı adlı 20 ayakkabılık gerçektir, MALZEME KODU da
  benzersiz değil), boş birim/birim fiyat, hareketlerde okunamayan miktar ("1X5", "PALET" → 0 yazılır, ham değer
  açıklamaya `[Miktar: 1X5]` eklenir) / tarih, boş firma-fiş no-onay sayıları, **aynı tarih+belge+malzeme+miktar tekrarı**
  (Excel'de çift giriş şüphesi; hepsi aktarılır, Excel esas). Bölüm bazında son yükleme `depo_import_log`
  (bolum/dosya/satir/kullanici/created; `dp_import_log_kur` _ortak + kurulum) — sayfadaki "Bölümler ve son yükleme"
  tablosu hangi dosyanın ne zaman yüklendiğini gösterir, bu yüklemede yenilenenler yeşil. 2026-09-03 dosyalarıyla
  doğrulandı: 1809 demirbaş (mali 14.664.437,90 TL Excel'le birebir) / 609 sarf (fiyat girilmemiş, mali 0) / 26 el aleti /
  1449+1955 depo giriş-çıkış / 511+854 taşeron giriş-teslimat; taşeron teslimatta 2025 tarihli mekanik ekipman satırları gerçektir) · raporlar (KPI'lar [kalem/mali değer/tükenen/hurda/zimmet] + kategori/disiplin mali değer grafikleri + **aylık giriş-çıkış hareket trendi** + firma bazlı çıkış + en değerli / **en çok çıkan** malzemeler + **el aletleri zimmet dağılımı** + tükenenler; **4 ayrı Excel** [özet/değerli/çok çıkan/tükenen] + **ERN Taahhüt logolu PDF/Yazdır**) · kurulum_depo. El aletleri: fiyat/disiplin yok, **Seri No + Zimmetli Kişi** var. Demirbaş/
  sarf: birim fiyat → **mali değer** (STOK × B.Fiyat). `depo/_ortak.php` (dp_sayi, DP_KATEGORI, dp_ozet, dp_mal_norm, **dp_kalem_ara** [malzeme açılır menüsü aramasi],
  **dp_fis_satirlari** [aynı fişin satırları], **dp_evrak_kaydet** [imzalı belgeyi fişin tüm satırlarına bağlar],
  **dp_evrak_bagi_kaldir/dp_evrak_dosya_temizle** [dosya birden çok satıra bağlı olabildiğinden diskten yalnız
  SON bağ koptuğunda silinir], dp_import_log_kur).
- **Akaryakıt modülü** = `akaryakit/` alt klasörü. Şantiye mazot (dizel) stok + araç/makine bazında
  aylık tüketim takibi. **Ayrı veritabanı** (`takbulut_akaryakit`, `AKARYAKIT_DB_NAME`).
  `includes/db_akaryakit.php` → `$pdoAkaryakit`. Tablolar: `akaryakit_araclar` (araç/makine kaydı,
  anahtar = **Şoför + Cinsi** normalize, get-or-create), `akaryakit_donemler` (ay bazlı stok:
  **Devir + Gelen = Toplam; Toplam − Kullanılan = Kalan**; her ayın Kalan'ı sonraki ayın Devir'i =
  zincir; + `gunluk` JSON: üst bloktan günlük seriler `{gelen:{gün:Lt}, kullanilan:{gün:Lt}}` —
  YENİ GELEN satırının gün hücreleri mazotun geldiği günü, KULLANILAN satırınınki o günün toplam
  tüketimini verir [gün d → col 7+2d 0-based]; stok.php'de **Günlük Akış** modali gün gün
  gelen/kullanılan/kalan gösterir, günlük gelen toplamı aylıktan farklıysa uyarır — Excel'de geliş
  günleri çoğu ay girilmemiş), `akaryakit_tuketim` (dönem×araç: aylık tüketim/çalışma/ortalama/okumalar + **günlük 31
  günün Mazot/Km detayı JSON** `gunluk`), `akaryakit_tutanak` (aylık imzalı tüketim raporu satırları).
  Sayfalar: index(dashboard: stok KPI + aylık tüketim/kalan grafik + firma doughnut + en çok tüketen) ·
  **hareketler**(GÜNLÜK HAREKET DEFTERİ — "bugün tanka ne geldi, hangi araca ne verildi" tek ekranda.
  **ÜÇ kaynak birleşir, veri kopyalanmaz**: (1) **EXCEL günlük hücreleri** `ak_excel_gunluk()` — çıkış =
  `akaryakit_tuketim.gunluk` `[{g,mz,km}]` × araç (tarih = dönem yıl-ay + gün; **km=0 Excel'de
  "girilmemiş"** → sayaç "—"), giriş = `akaryakit_donemler.gunluk.gelen` `{gün:Lt}`; (2) ELLE giriş →
  yeni tablo `akaryakit_girisler` (tarih, belge_no [irsaliye/fiş], tedarikci, plaka [tanker], miktar_lt,
  birim_fiyat, tutar [boşsa miktar×fiyat], teslim_alan, aciklama, **evrak_url** → `uploads/akaryakit_giris/{id}/`);
  (3) ELLE çıkış → `akaryakit_cikislar` (cikislar.php'nin tablosu, olduğu yerden okunur).
  **Excel esastır → elle↔Excel EŞLEŞTİRME**: elle satır aynı tarih + aynı araç (girişte aynı tarih) +
  aynı miktar (±0,5) Excel satırıyla eşleşirse `sayilir=false` olur — listede soluk, "Excel'e işlendi ✓"
  rozeti, toplama/bakiyeye GİRMEZ (aynı litre iki kez sayılmaz); eşleşmeyen elle satır sayılır ve
  "Excel'de yok" rozetiyle ay sonunda Excel'e aktarılacaklar listesini verir (KPI + bantta adet/Lt).
  **SENTETİK "Excel özet" satırları**: Excel'de çoğu ay YENİ GELEN'in geliş günü yazılmaz (yalnız aylık
  toplam) ve araç satırlarında detayı olmayan tüketim olabilir; aylık özet − günlük hücreler farkı satır
  olarak eklenir (geliş ayın 1'ine, açıklanamayan tüketim ayın sonuna; `sentetik=1`, italik, mavi rozet)
  — aksi halde ay sonu bakiyesi Excel KALAN'ından sapıyordu (Ağustos −689 görünüyordu, Excel 9.272).
  Gerçek dosyayla doğrulandı: **Ocak–Ağustos 2026'nın 8 ayında defter sonu = Excel KALAN birebir**.
  **Yürüyen bakiye** açılışı `ak_defter_acilis()`: ay seçili + Excel dönemi → **Excel DEVRİ**; serbest
  tarih aralığı → başlangıç ayının Excel devri + ay başından başlangıca kadarki hareketler (dönem yoksa
  defterin tamamı). ⚠ Tür/araç süzgeci açıkken **bakiye sütunu gizlenir** (hareketlerin bir kısmı
  listede olmadığından yanıltırdı). **Mutabakat bandı**: Excel aylık özet ↔ Excel günlük hücreler
  (tutmuyorsa kırmızı + sentetik satır açıklaması) + Excel'de olmayan elle kayıt sayısı.
  **Varsayılan görünüm TÜMÜ** (zincir en eski Excel döneminin devriyle başlar, her ay sonu Excel KALAN'a iner);
  **Dönem** açılır menüsü = Excel dönem adları (OCAK 2026…) + yalnız elle kaydı olan aylar ("… (yalnız elle)"),
  `?ay=YYYY-MM` ile taşınır. Filtreler dönem | tarih aralığı / tür / araç / serbest arama (Excel satırlarında ak_norm ile) + KPI +
  Excel dışa aktarma [Kaynak/Durum sütunlu, bakiye yalnız sayılanla ilerler]. ⚠ Kaynak dosyada
  "EYLÜL 2026" sayfasının A1 başlığı "AĞUSTOS 2026" kalmış (kopyalanıp düzeltilmemiş) — import dönemi
  **sayfa adından** aldığı için sorun çıkarmaz) ·
  aylik(dönem seçmeli araç tüketim tablosu + günlük detay modal) · stok(dönem zinciri, uyuşmayan geçiş
  kırmızı, elle düzelt) · **cikislar**(günlük mazot çıkışı: araç seçimi otomatik doldurur, ay filtresi + KPI;
  tablo `akaryakit_cikislar` runtime+kurulum, **imzalı evrak yükleme** `evrak_url` → `uploads/akaryakit_cikis/{id}/`; stok zincirine KARIŞMAZ — Excel esastır, ay sonunda Excel'e işlenir) ·
  **cikis_tutanak**(sahadaki basılı AKARYAKIT ÇIKIŞ FİŞİ'nin [EYS.ABR.01.FR.05] birebir kopyası: Projesi satırı=açıklama, şirket/kiralık/taşeron kutucukları=arac_tipi, sayaç plakalıysa KİLOMETRE değilse Ç.SAATİ satırına; ERN Taahhüt logolu, AKY-C-00001 no) ·
  araclar/arac_form(CRUD; listede Toplam Tüketim'e tıklayınca **Yakıt Geçmişi modali** — AJAX `?gecmis=arac_id`, dönem dönem + gün gün "kim ne zaman ne kadar aldı" tarihli döküm, günlük detay girilmemiş aylar not düşülür) · tutanaklar + tutanak_pdf(A4) · raporlar (Chart.js + Excel: aylık tüketim, firma/araç bazlı) · import · kurulum_akaryakit.
  Import: aylık sayfalar (OCAK 2026…) + TUTANAK sayfaları **dönem bazlı tam yenileme**; sayfa altındaki **imza bloğu** (DEPO ŞEFİ…/MALİ İŞLER ŞEFİ:/İMZA:/TARİH:) `ak_imza_satiri()` ile atlanır — aksi halde 'İMZA:' adlı araçlar oluşuyordu; `ak_imza_temizle()` eski çöp araç/tüketim/tutanak kayıtlarını import sonunda siler; **Excel'in
  TOPLAM hücreleri bayat olduğundan stok hesaplanır** (devir+gelen, −kullanılan). Gün d → Mazot col
  `7+2d`, Km col `8+2d` (gün 1=col9…31=col69); özet col 71 aylık/72 çalışma/73 ortalama/74-76 okuma.
  `akaryakit/_ortak.php` (ak_sayi, **ak_sayi_form**, ak_norm, ak_donemSira TR ay→sıra, ak_aracId,
  ak_donemler, ak_giris_semasi_kur, ak_defter_filtre, **ak_excel_gunluk**, **ak_defter**, ak_defter_acilis, ak_donem_ay).
  ⚠ **`ak_sayi` Excel içindir, `ak_sayi_form` FORM içindir**: Excel'de "6.000" ondalıktır, elle
  yazılan alanda ise Türkçe binlik ayracıdır — "6.000 Lt" 6 litre olarak kaydediliyordu (hem
  hareketler hem cikislar'da). `ak_sayi_form` noktayı yalnız **üçerli gruplar** biçimindeyse
  (1.234 · 1.234.567) binlik sayar, "6.5" ondalık kalır.
- **CRM modülü** = `crm/` alt klasörü. **Üretim Arızaları** (konut teslim sonrası eksik/kusur) takibi.
  **Ayrı veritabanı** (`takbulut_crm`, `CRM_DB_NAME`), tablolar `crm_` önekli. `includes/db_crm.php` → `$pdoCrm`.
  **Kaynak: CRM'den GÜNLÜK alınan "UretimArizalari" Excel raporu** — rapor o anda AÇIK olan arızaların anlık
  görüntüsüdür (tüm satırlar "Etkin", Çözümlenme Tarihi `1.01.0001` = boş). Bu yüzden içe aktarma **tam yenileme
  DEĞİL BİRLEŞTİRME**: dosyada olup sistemde olmayan → yeni arıza · her ikisinde olan → güncellenir ·
  **sistemde açık ama dosyada yok → arıza kapanmış sayılır** (otomatik "çözüldü"; kısmi rapor gelirse kutu kapatılır) ·
  kapatılmış kayıt raporda yine görünürse **yeniden açılır** (hatalı kapanış kendini düzeltir) · raporun kendi
  Çözümlenme Tarihi doluysa kapanış oradan alınır. Böylece tek dosyadan "kaç yeni geldi / kaç kapandı / ne kadar
  bekledi" çıkar. ⚠ Excel'de **ID kolonu yok**: kimlik `crm_anahtar()` ile içerikten üretilir
  (konut + açılış anı + şikayet zinciri + açıklama → md5, `kayit_anahtari` UNIQUE) — **aynı dosya defalarca
  yüklense de mükerrer kayıt oluşmaz**. Excel alanları her aktarımda ezilir; sistem içi `ic_not` ve `evrak_url`
  KORUNUR. Tablolar: `crm_arizalar` (konut/ada/parsel/blok/kat[+`kat_sira`: bodrum<zemin<kat sıralaması]/daire
  no+tipi, dönem, kaynak, eksik-kusur, ölçek, aciliyet, şikayet türü→konusu→açıklaması, arıza tipi, açıklama,
  sorumlu, sonlandıran, olusturma/cozumlenme, durum ENUM acik/cozuldu, kapanis_kaynagi excel|otomatik|elle,
  ilk_gorulme/son_gorulme) · `crm_import_log` (dosya/rapor tarihi/satır/yeni/güncellenen/kapanan/kullanıcı).
  Sayfalar: **index** (dashboard: rapor güncellik bandı [son yükleme + kaç yeni/kaç kapandı, 2 günden eskiyse
  uyarı] + KPI'lar [açık · bu ay yeni · bu ay çözülen · ort. açık kalma · 30+ gün bekleyen · toplam] + **aylık
  gelen/çözülen çubuk + "birikmiş açık" çizgisi** (`crm_aylik_seri()`: kaydı olmayan aylar da doldurulur —
  sorgu yalnız dolu ayları verdiğinden zaman ekseni çarpılıyordu; seri bugüne kadar uzar, çizgi ay sonundaki
  kümülatif açık yükü sağ eksende gösterir. İlk raporda hiç kapanış olmadığından yalnız gelen/çözülen çizgileri
  boş görünüyordu; bant "çözülen serisi ikinci rapordan itibaren dolar" der. ⚠ **Chart.js canvas'ları sabit
  yükseklikli `<div style="height:…">` içinde** olmalı — `maintainAspectRatio:false` yükseklik veren bir kutu
  ister, yoksa grafik ezilir/şişer) + şikayet türü doughnut + blok yığılmış bar + en sık arıza tipleri + en uzun süredir açık /
  en çok arızalı daireler / son gelen arızalar) · **arizalar** (filtreler: durum/blok/kat/tür/konu/detay/sorumlu/
  tarih aralığı/serbest arama, whitelist sıralama, sayfalama, **Excel dışa aktarma**, toplu çöz/yeniden aç, 90+
  gün açık satır sarı) · **ariza_detay** (tüm CRM alanları + aynı dairenin diğer arızaları + elle çöz/yeniden aç +
  **iç not** + **çoklu belge/fotoğraf**: her arızaya sınırsız dosya, tablo `crm_ariza_belgeler`
  (ariza_id/dosya_url/ad/mime/boyut/kullanıcı), dosyalar **`uploads/crm_ariza/{ariza_id}/` klasöründe**,
  DB'de yalnız göreli URL; görsellerde küçük önizleme, tek tek silme [dosya başka kayıtta kullanılmıyorsa
  diskten de silinir], listede ataç rozeti. ⚠ Eski tek-belgelik `crm_arizalar.evrak_url` yeni belge **en
  yenisini** gösterir; şema kurulumunda eski değerler belge tablosuna taşınır (idempotent). Önceki sürüm her
  yüklemede eski dosyayı SİLİYORDU — artık silmez) · **raporlar** (tarih aralığı filtresi + KPI + aylık
  trend [tarih filtresinden BAĞIMSIZ — filtre yalnız gelen tarafını kesip çözülenle uyumsuz grafik üretiyordu]
  + açık arıza yaş dağılımı [0-7/8-30/31-90/90+] + tür/konu/detay/arıza tipi/blok/kat/daire tipi/sorumlu
  kırılımları [toplam·açık·çözülen·ort. çözüm günü] + en çok arızalı daireler; **ERN_RAPOR** ile Excel'e Aktar +
  PDF İndir + Yazdır) · **import** (çoklu dosya; dosya adındaki tarih rapor tarihi sayılır; kapanan arızaların
  listesi `<details>` ile gösterilir; **her satırın hesabı verilir** — `okunan = yeni + güncellenen + atlanan`
  eşitliği ekranda gösterilir, tutmazsa uyarı. **Atlanan satırlar sebebiyle listelenir** (Excel satır no +
  içerik): 'aynı dosyada birebir tekrar' · 'konut ve şikayet konusu boş — arıza satırı değil'. **Aynı kimlik
  farklı içerik artık ATLANMAZ**: `md5(anahtar#n)` ile ayrı kayıt açılır (veri kaybı olmaz; dosya sırası sabit
  olduğundan tekrar yüklemede yine aynı kimlik üretilir, mükerrer oluşmaz)) · kurulum_crm. Çekirdek: `crm/_ortak.php` (crm_norm, crm_tarih, crm_anahtar,
  crm_kat_sira, crm_semasi_kur, crm_ozet, crm_filtre, crm_secenekler) + `crm/_import.php` (CRM_ALAN başlık
  haritası — "Aciklama" EN SONA, yoksa "Sikayet/Durum Aciklamasi" sütununu kapar; crm_sayfa/crm_harita/crm_import).
  İlk dosya (2026-09-03) ile doğrulandı: 610 açık arıza, 211 daire, 7 blok, 2025-07-30 → 2026-08-31.
- **Prekast modülü** = `prekast/` alt klasörü. Cephe prekast (T profil) **montaj iş takibi + hakkediş**.
  **CRM ile aynı veritabanını paylaşır** (`takbulut_crm`, `CRM_DB_NAME`), tablolar `prekast_` önekli;
  `includes/db_prekast.php` → `$pdoPrekast` (istenirse `PREKAST_DB_NAME` ile ayrılır).
  **Kaynak: sahadan GÜNLÜK gelen "İŞ TAKİP / HAKKEDİŞ ÇİZELGESİ" Excel'i.** Çizelge SABİT bir iş
  listesidir; her gün aynı satırlar gelir, yalnız **durumlar dolar**: Kesim → Silikon → Metraj →
  Hakkediş (= metraj × birim fiyat). Bu yüzden içe aktarma tam yenileme DEĞİL **BİRLEŞTİRME**
  (CRM ile aynı mantık): dosyada olup sistemde olmayan → yeni iş · her ikisinde olan → güncellenir ·
  **sistemde olup dosyada olmayan → SİLİNMEZ**, `dosyada=0` ile "çizelgede yok" işaretlenir (arşiv).
  Bir satır **ilk kez "Yapıldı"** olduğunda o günün rapor tarihi damgalanır (`kesim_tarih`/`silikon_tarih`)
  — ilerleme takvimi ve günlük trend buradan çıkar. ⚠ Excel'de **ID kolonu yok** ve **blok+daire tekrar
  ediyor** (aynı dairede birden çok cephe işi: B/61 iki satır, F/21 üç satır): kimlik `pk_anahtar()` ile
  çizelge + blok + daire + **o gruptaki tekrar sırası**ndan üretilir (`kayit_anahtari` UNIQUE) — aynı dosya
  defalarca yüklense de mükerrer kayıt oluşmaz. Sistem içi `ic_not` aktarımda KORUNUR. Tablolar:
  `prekast_isler` (cizelge/is_tipi/sira/blok/daire[+`daire_sira`]/tekrar, kesim+kesim_metin+kesim_tarih,
  silikon+silikon_metin+silikon_tarih, metraj, birim_fiyat, hakkedis, durum ENUM bekliyor/kesim/tamam,
  dosyada, ilk/son_gorulme, ic_not) · `prekast_gunluk` (rapor_tarihi+cizelge UNIQUE: o günün anlık toplamı
  satır/kesim/silikon/metraj/hakkediş + yeni_satir/yeni_kesim/yeni_silikon/dosya/kullanıcı — **trend grafiği
  bundan çizilir**). Sayfalar: **index** (canlı dashboard: çizelge güncellik bandı [son yükleme + kaç yeni
  iş/kesim/silikon] + KPI'lar [toplam iş · tamamlanan · silikon bekleyen · kesim bekleyen · metraj · hakkediş]
  + genel tamamlanma çubuğu [+ bekleyen işlerin **tahmini hakkedişi** = ort. metraj × birim fiyat] +
  **günlük ilerleme grafiği** (`pk_gunluk_seri()`: gün gün tamamlanan/kesilen çubuk + birikmiş toplam çizgi,
  sağ eksen; boş günler doldurulur) + iş durumu doughnut + blok bazında ilerleme/hakkediş +
  silikon bekleyenler [kesimden bu yana geçen gün, 14 günü aşan kırmızı] / son tamamlananlar / en yüksek
  metrajlı daireler) · **isler** (filtreler: durum/blok/daire/çizelge/serbest arama/tamamlanma tarih aralığı/
  **kapsam** [çizelgede duranlar · çizelgeden düşenler · hepsi], whitelist sıralama, sayfalama, **Excel dışa
  aktarma**) · **is_detay** (çizelge alanları + **ilerleme takvimi** [kesim/silikon damgaları, kesimden
  silikona kaç gün] + aynı dairedeki diğer işler + **iç not**; hakkediş metraj × birim fiyatla tutmuyorsa
  rozet — Excel esas) · **raporlar** (tarih aralığı **tamamlanma tarihine** uygulanır [dönem hakkedişi] +
  KPI + aylık tamamlanan iş & hakkediş + silikon bekleyenlerin yaş dağılımı + blok/çizelge/iş tipi/daire
  kırılımları [iş·kesim·tamamlanan·%·metraj·hakkediş] + günlük ilerleme; **ERN_RAPOR** ile Excel'e Aktar +
  PDF İndir + Yazdır) · **icmal** ("Blok İcmali": Excel'in İCMAL sayfasının mantığı CANLI uygulanır —
  `pk_icmal()`: **kesim/silikon yapılan daire benzersiz blok|daire üzerinden sayılır** (aynı dairedeki ikinci iş
  ayrı daire değildir; HESAPLAMA'daki ikinci B|61 satırının sayacı 0'dır), kesim/silikon (mt) her satırdan toplanır,
  **metrajı ölçülmemiş satırlara ölçülenlerin ortalaması yazılır** (Excel I2 = H2/ölçülen adet) ve bu tahmini
  kısım tabloda "~x tahmini" alt satırı + rozetle ayrı gösterilir [hakkediş yalnız ölçülen metrajdan doğar];
  blok bazında Kesim Yapılan Daire / Kesim (mt) / Silikon Yapılan Daire / Silikon (mt) / Silikon÷Kesim ilerleme
  çubuğu + TOPLAM + 6 KPI + Chart.js bar; çizelge seçmeli; ERN_RAPOR Excel/PDF/Yazdır) · **import** (çoklu dosya, dosya adındaki tarih rapor tarihi sayılır; **her satırın
  hesabı verilir** [okunan = yeni + güncellenen + değişmeyen + atlanan], boş şablon satırları [blok+daire boş,
  yalnız No + birim fiyat dolu] tek sayaçta toplanır, **veri doğrulama** [hakkediş = metraj × birim fiyat
  çapraz kontrolü, birim fiyatı boş satır, silikon yapıldı ama metraj yok, metraj var ama silikon işaretsiz]
  + "durumu ilerleyen işler" listesi + **"Excel İCMAL sayfası ↔ sistem icmali" kontrolü**: dosyada İCMAL
  sayfası varsa `pk_excel_icmal()` ile okunur, blok bazında kesim/silikon daire + kesim mt sistem icmaliyle
  karşılaştırılır, farklar satır satır listelenir) · kurulum_prekast. Çekirdek: `prekast/_ortak.php` (pk_norm, pk_sayi,
  pk_yapildi, pk_anahtar, PK_DURUM/pk_durum, pk_semasi_kur, pk_ozet, pk_son_import, pk_gunluk_seri, pk_filtre,
  pk_secenekler) + `prekast/_import.php` (PK_ALAN başlık haritası — **"Daire" EN SONA**, yoksa "Kesim/Silikon
  Yapılan Daire" sütunlarını kapar; **pk_sayfa** [başlığında HAKKEDİŞ geçen sayfa tercih edilir — İCMAL ve
  HESAPLAMA sayfaları da BLOK+DAİRE başlığı taşıdığından ilk eşleşen sayfa alınırsa yanlış sayfa okunur; iş
  sayfası "Sayfa1 (2)" Excel'de GİZLİDİR, SimpleXLSX yine okur]/pk_baslik_satiri/pk_harita/pk_cizelge_adi/
  pk_is_tipi/pk_dosya_tarihi/**pk_excel_icmal**/pk_import). İlk dosya (KARTAL BATIYAKASI C ve B PARSEL **T PROFİL** MONTAJ İŞİ) ile
  doğrulandı: 82 iş satırı, 5 blok (F42/C17/D11/B10/H2), kesim 82, silikon 47, metraj **250,50** ve hakkediş
  **473.445,00 TL** Excel'le birebir; birim fiyat sabit 1890, metraj×fiyat sağlamasında 0 sapma.
  ⚠ **İCMALLİ kitap (2026-09-08)**: iş sayfası 64 satır (kesim 64, silikon 24, metraj 125,95, hakkediş
  238.045,50 birebir); İCMAL sayfası HESAPLAMA üzerinden SUMIFS ile hesaplanır ama HESAPLAMA'nın **sayaç/metraj
  sütunları (D–G) formül değil elle yazılıdır ve BAYATTIR** (35 artık satır F|6…F|63, Silikon(mt)=Kesim(mt)
  kopyası 416,34; Excel İCMAL B9/C20/D18/F45/H5 derken güncel iş satırları B8/C17/D8/F26/H1 verir). Bu yüzden
  sistem icmali Excel'in İCMAL hücrelerini KOPYALAMAZ, aynı mantığı güncel satırlara uygular; import raporunda
  fark uyarı olarak listelenir (14 farklılık). Satır 65'te blok "D " sonda boşluklu — pk_al trim eder.
- **IT Envanter modülü** = `it/` alt klasörü (MODULLER anahtarı `it`, şeritte "IT"). Bilgi işlem varlıkları:
  bilgisayar / laptop / monitör / yazıcı / telefon / tablet / ağ cihazı / sunucu / **yazılım lisansı** / aksesuar.
  **Ayrı veritabanı** (`takbulut_it`, `IT_DB_NAME`; `includes/db_it.php` → `$pdoIt`), tablolar `it_` önekli:
  `it_cihazlar` (envanter_no UNIQUE **otomatik IT-00001**, kategori, ad, marka/model/seri_no, durum
  [aktif=kullanımda·depoda·serviste·arizali·hurda], zimmetli/departman/lokasyon/zimmet_tarihi, alış/garanti/fiyat/
  tedarikçi/fatura no, ip/mac/işletim sistemi/özellikler, lisans_anahtari/lisans_adet, foto_url [en yeni görsel],
  notlar) · `it_hareketler` (cihazın YAŞAM GÜNLÜĞÜ: giris/zimmet/iade/servis/donus/ariza/hurda/not/guncelleme;
  tarih, kişi, açıklama, kullanıcı) · `it_belgeler` (cihaz başına sınırsız fotoğraf/fatura/garanti belgesi →
  **`uploads/it_envanter/{cihaz_id}/`**, DB'de yalnız göreli URL; görsel yüklenince `foto_url` güncellenir, silmede
  kalan en yeni görsele döner). **Kayıt silinmez, hurdaya alınır** (listede varsayılan gizli, durum filtresiyle görünür).
  Sayfalar: **index** (KPI: toplam/kullanımda/depoda/serviste+arızalı/garantisi 60 günde bitecek/mali değer + kategori
  doughnut + durum bar + departman bazlı zimmet + garantisi bitenler + serviste/arızalı + en çok cihazı olan kişiler
  [kişi bazlı toplu tutanak linki] + son hareketler) · **cihazlar** (filtre: arama [envanter no/ad/marka/model/seri/kişi/
  lokasyon/IP/not LIKE] · kategori · durum · zimmetli · departman · garanti [60 günde bitiyor/bitti/devam]; whitelist
  sıralama, sayfalama 100, **Excel** `?export=xlsx` XlsxWriter 22 sütun; küçük foto/kategori ikonu; garanti rozeti) ·
  **cihaz_form** (ekle/düzenle; datalist önerileri [kişi/departman/lokasyon/marka/tedarikçi]; **zimmetli girilince durum
  otomatik kullanımda**, kişisiz "kullanımda" yalnız yazılım lisansında kalır; fiyat `it_sayi` Türkçe binlik/virgül;
  kategori yazılımsa lisans alanları, değilse teknik alanlar [JS]; düzenlemede zimmet değişimi zimmet/iade hareketi,
  diğer kritik alan değişimleri "guncelleme" hareketi olarak günlüğe yazılır; tek dosya yükleme) ·
  **cihaz_detay** (tüm alanlar + yaşam günlüğü + belgeler; sağ panel İŞLEM formu: zimmet ver/devret [devirde eski
  kişiye iade satırı], zimmet iade [durum depoda], servise gönder / servisten döndü [zimmetliyse aktif'e döner],
  arıza bildir, hurdaya ayır [zimmet düşer], not; çoklu belge yükleme; aynı kişinin diğer cihazları) ·
  **zimmet_tutanak** (A4 ERN Taahhüt logolu **BİLGİ İŞLEM DEMİRBAŞ ZİMMET TUTANAĞI**: `?id=` tek cihaz [no ZMT-IT-00001]
  ya da `?kisi=` kişinin TÜM aktif cihazları tek tutanakta; taahhüt maddeleri + teslim eden/alan imza; imzalı kopya
  belge olarak yüklenir) · **raporlar** (KPI + aylık hareket trendi [12 ay, yığılmış] + yaş dağılımı [alış tarihi
  <1/1-3/3-5/5+ yıl] + garanti durumu + **kategori × durum matrisi** + departman/lokasyon/marka kırılımları + en değerli
  cihazlar; **ERN_RAPOR** Excel/PDF/Yazdır) · **kurulum_it** (şema + uploads klasörü + DB rozeti; yalnız admin/toa).
  **PERSONEL & LOKASYON (2026-09-09)**: `it_personel` (sicil_no [benzersiz, form denetimi], ad, soyad, unvan, birim,
  lokasyon_id, telefon, eposta, ise_giris, **isten_cikis NULL = çalışıyor**, notlar) + `it_lokasyonlar` (**hiyerarşik**:
  ust_id, tur proje/bina/birim/depo, kod [U030…], ad, sira, aktif; `IT_LOK_SEED` varsayılan ağaç: Kartal Batı Yakası
  Projesi → 1. Etap U030 / 2. Etap U031 / Millet Bahçesi U039 / Şantiye Teknik Ofis / Şantiye Depo; ERN Holding
  İstanbul Merkez Binası → Gayrimenkul Geliştirme Direktörlüğü / Satış Ofisi / Kurumsal İletişim Direktörlüğü /
  Yönetim Kurulu / Yönetim (Patron) Ofisleri / Bilgi İşlem Deposu — `it_lokasyon_seed` kurulumda tablo boşsa ve
  lokasyonlar.php düğmesiyle, aynı üst+ad varsa atlar) + `it_cihazlar.personel_id` / `lokasyon_id` (runtime ALTER;
  eski `zimmetli`/`lokasyon` METİN alanları `it_cihaz_bag_esitle()` ile eş zamanlı tutulur — tutanak, liste ve
  eski kayıtlar için; kişi adı/birimi değişince personel_form bağlı cihazları da günceller, lokasyon adı değişince
  lokasyonlar.php yol metnini yeniler). Yardımcılar: it_lokasyonlar (istek önbelleği), it_lokasyon_duz (derinlikli
  düz liste), it_lokasyon_yol ("Kartal Batı Yakası Projesi › U030 1. Etap"), it_lokasyon_etiket, **it_lokasyon_altlar**
  (proje seçilince etapları da kapsayan filtre), it_lokasyon_options (girintili select), it_personel_liste/bul/ad/
  aktif/options (data-birim/data-lok ile form otomatik dolar), it_personel_cihazlari. Sayfalar: **personel** (filtre
  durum çalışan/ayrılan/hepsi · lokasyon [alt dahil] · birim · arama; sicil/ad/unvan/birim/lokasyon/telefon/giriş/çıkış
  + zimmetli cihaz adedi ve değeri; **işten ayrılmış + açık zimmet** satırı kırmızı + üst uyarı; Excel) ·
  **personel_form** (mükerrer sicil engeli; birim boşsa birim türündeki lokasyonun adı; **işten çıkış tarihi üzerinde
  zimmet varken kaydedilmez**) · **personel_detay** (kart + üzerindeki cihazlar + zimmet geçmişi [personel_id VEYA eski
  kisi metni] + **"Tümünü iade al"** [her cihaza iade hareketi, depoya] + **İşten çıkış** [açık zimmet varsa düğme
  kapalı] / çıkışı geri al; kişi bazlı toplu tutanak) · **lokasyonlar** (ağaç tablosu: tür/kod/cihaz [alt dahil]/
  kullanımda/personel sayıları + alt ekle/düzenle/sil [bağlı kayıt varsa silinmez, pasife alınır] + kendi altına
  taşıma engeli + varsayılan yapıyı yükle). Cihaz formu ve detaydaki "Zimmet ver" artık **personel seçer** (serbest
  metin yok; ayrılmış personele zimmet verilemez), lokasyon select; cihaz listesinde lokasyon [alt dahil] +
  personel_id filtresi; zimmet tutanağı `?personel_id=` (sicil, unvan, birim, telefon, işe giriş) — `?kisi=` eski
  metin kayıtları için kalır; dashboard'da **ayrılmış ama zimmetli** kırmızı bant + proje/bina bazlı cihaz grafiği
  (alt lokasyonlar köke toplanır); raporlar proje/bina + etap/birim kırılımı. Smoke: itsm'de kurulum seed 13 satır,
  mükerrer sicil, cikis engeli → tumunu_iade → cikis, lokasyon_id=1 (Kartal) filtresi etapları kapsıyor.
  **PERSONEL İÇE AKTARMA (2026-09-09)** `it/import.php` + çekirdek `it/_import.php` (pim_*): İK / Active Directory /
  Microsoft 365 "export users" dosyası → personel listesi. **Üç biçim**: .xlsx (SimpleXLSX, en dolu sayfa seçilir) ·
  .csv (ayraç ; , sekme | sezilir, BOM + Windows-1254 → UTF-8) · **Excel "Web Sayfası" .xls/.htm = HTML tablo**
  (DOMDocument; meta charset'e göre iconv, sonra meta charset UTF-8'e YAZILIR — libxml meta'ya bakıp dönüştürülmüş
  metni ikinci kez çözüyordu; `<script>` blokları atılır — çerçeve dosyasının JS'inde "<table" metni geçer).
  ⚠ Excel bu biçimde İKİ parça üretir: kısa **çerçeve** (frameset) + `…_dosyalar/sheet001.htm` (asıl veri). Kullanıcının
  ilk gönderdiği `exportusers20260909.xls` yalnız çerçeveydi → `pim_html_oku` bunu tanır, "sheet001.htm'i ya da .xlsx
  yükle" der. İkili BIFF .xls (D0CF11E0) desteklenmez → ".xlsx olarak kaydet" mesajı. **3 adım**: yükle → oturumda
  grid (`$_SESSION['it_pim']`, ≤5000 satır) + `pim_baslik_satiri` (ilk 15 satırda bilinen başlıkla en çok eşleşen) +
  `pim_harita` otomatik sütun→alan eşleme (`PIM_ALAN` TR/EN eş anlamlılar: Ad/First Name/Given Name, Soyad/Last Name/SN,
  Display Name→ad_soyad [ayrı Ad+Soyad varsa atlanır; "NAME" yalnız SURNAME yoksa ad_soyad], Title/Job Title→unvan,
  Department/OU→birim, Office/Location/Proje→lokasyon, Mobile>Office phone→telefon, Email/UPN→eposta [örnekte '@' yoksa
  nota], Hire Date→ise_giris, Account enabled/Status→durum, username/manager/company/city→**notlar** [birden çok sütun
  "Başlık: değer" satırı olarak birikir]) → ön izleme ekranı (her sütun için select, başlık satırı seçici, ilk 12 satırın
  çözümlenmiş hali, lokasyon eşleşmesi yeşil/sarı) → **BİRLEŞTİRME** `pim_import` (transaction): eşleşme **sicil →
  e-posta → normalize ad+soyad** (aynı ad soyadlı 2+ kayıt → atlanır "elle eşleyin"); yeni → INSERT, mevcut → yalnız
  dosyada DOLU alanlar güncellenir (boş hücre silmez; ad/birim değişince bağlı cihazların zimmetli/departman metni de
  güncellenir), `notlar` KORUNUR (yeni not satırları eklenir), zaten ayrılmış kişinin çıkış tarihi ezilmez.
  **Çakışma koruması**: sicil eşleşti ama ad VE soyad bambaşka → atlanır ("sicil başka kişiye kayıtlı"); e-posta/ad ile
  eşleşti ama iki tarafta da dolu ve farklı sicil → atlanır ("sicil çakışması"); e-posta eşleşti ama ad+soyad farklı →
  atlanır. Çakışan kişi yine "dosyada görüldü" sayılır (aksi halde "dosyada yok → ayrıldı" kuralına düşüyordu).
  Dosya içi tekrar (aynı sicil/e-posta/ad) atlanır. Seçenekler: **baş harf büyütme** `pim_bas_harf` (yalnız TAMAMEN
  BÜYÜK metinde; İ/I Türkçe: "İSMAİL"→"İsmail" — mb_strtolower 'İ'yi bozar, elle) · **pasif hesap = ayrılmış** (durum
  sütunu false/disabled/pasif → isten_cikis bugün, çıkış sütunu doluysa o) · **dosyada olmayan çalışan = ayrılmış**
  (varsayılan KAPALI; **üzerinde zimmet olan kişi ATLANIR** ve raporda kırmızı). Yardımcılar: pim_norm (TR→ASCII),
  pim_tarih (Y-m-d[ H:i:s] · d.m.Y · d/m/Y · Excel seri; <1950 boş), **pim_tarih_bos** ("1.01.0001"/"01.01.1900" boş
  sayılır, not düşülmez), pim_durum, pim_telefon (+90/5xx → "0532 123 45 67"), pim_ad_ayir (son kelime soyad; "Soyad,
  Ad" tanınır), **pim_lokasyon_bul** (kod token → ad eşit → ad içerir; birden çok kod eşleşirse **en derin düğüm**
  kazanır: "Kartal U031 2. Etap" → U031, KARTAL kökü değil; birim adı ağaçta seçili lokasyonun altında bir düğümse o
  alınır: Merkez + "Satış Ofisi" → Merkez › Satış Ofisi; eşleşmeyen lokasyon metni raporda rozet + kişinin notuna
  "Lokasyon (dosyadan): …"). Rapor: okunan = yeni + güncellenen + değişmeyen + atlanan sağlaması + listeler (atlanan
  sebepli, yeni, güncellenen [değişen alanlar eski → yeni], dosyada olmayanlar). Log `it_import_log` (pim_log_kur;
  kurulum + runtime), sayfada "Son yüklemeler". **ŞABLON SİSTEME GÖRE ÜRETİLİR (2026-09-09)**: `?sablon=1`
  içe aktarmanın tanıdığı 12 sütunu (`PIM_SABLON_BASLIK`) + **sizdeki gerçek lokasyon (proje kodlular önce,
  "U030 — 1. Etap"), birim ve unvan** değerleriyle 3 örnek satır + 5 boş satır yazar; `?sablon=mevcut`
  kayıtlı personeli aynı düzende indirir (`pim_sablon_satiri`) → Excel'de düzelt, geri yükle: sütunlar
  otomatik eşleşir, eşleşme sicilden yapılır, mükerrer oluşmaz (round-trip testi: 179 satır → 0 yeni /
  178 değişmeyen). Dosya yükleme kartında geçerli lokasyon etiketleri rozet olarak listelenir. Yetki: `import.php` adı `sayfa_islemi` ile
  **giris**; sidebar "Personel İçe Aktar" `can_edit()`. Test: itsm `run2.php` (oturum sess.json'da adımlar arası
  taşınır, `$_FILES` simülasyonu; db_it.php `SqlitePatch` MySQL DDL'yi SQLite'a çevirir) — xlsx TR başlık + "Adı
  Soyadı" tek sütun, HTML win-1254 M365 başlıkları, CSV ; ayraçlı, çerçeve-yalnız .xls mesajı, çakışma/zimmet engeli.
  **2026-09-09 ikinci tur (gerçek 183 satırlık İK listesiyle)**: ⚠ **"There is no active transaction" hatası** —
  `pim_log_kur()` (CREATE TABLE) transaction'ın İÇİNDE çağrılıyordu; MySQL'de DDL **örtük commit** yapar, sonraki
  `commit()` patlıyor ve aktarım "geri alındı" görünüyordu. Şema kurulumu artık `beginTransaction`'dan ÖNCE,
  commit/rollBack `inTransaction()` ile korumalı — **şema kuran her fonksiyon transaction dışında çağrılmalı**.
  **Eksik bilgili satır artık ATLANMAZ** ("bilgisi olan işlensin, olmayan boş kalsın"): yalnız ad ya da yalnız soyad
  varsa kayıt açılır, eksik alan boş kalır ve raporda "eksik alan" bandında listelenir; satır ancak ad+soyadın İKİSİ
  de boşsa atlanır. Rapordaki satır numarası artık **dosyadaki gerçek satır** (boş satırlar süzüldüğü için kayıyordu;
  `satir_no` haritası oturumda taşınır — gerçek dosyada başlık 4. satırda). **TAM YENİLEME (sil ve ekle)** kutusu
  (`yetki_var('duzenle')`, ayrı onay diyaloğu): mevcut personel silinir, yalnız dosyadakiler kalır — ama **üzerinde
  zimmet olan kişi SİLİNMEZ** (cihaz bağı kopmasın), korunur + güncellenir ve raporda "korundu" rozetiyle listelenir;
  tam yenilemede "dosyada olmayan → ayrıldı" seçeneği kapatılır. **Lokasyon eşleştirme** üç kademe daha kazandı:
  boşluksuz içerme ("Batıyakası" → Kartal Batı Yakası Projesi), **kelime bazlı yazım hatası toleransı**
  (levenshtein ≤2: "Gayrimenkul Drektörlüğü" → Gayrimenkul Geliştirme Direktörlüğü) ve birim adının ağaçtaki alt
  düğümle inceltilmesi. Boş E-posta sütunu artık nota düşmez (yalnız DEĞER varken '@' yoksa e-posta sayılmaz).
  Eşleşmeyen lokasyonlar rapordan **tek tıkla kök lokasyon olarak eklenir** (`islem=lok_ekle`) → dosya tekrar
  yüklenince kişiler bağlanır. **MÜKERRER KAYIT ÖNLEMİ (DB tarafı)**: `pim_mukerrer_gruplar()` aynı sicil / aynı
  e-posta / aynı normalize ad+soyad kartlarını gruplar (grup içi ASIL kayıt = en dolu kart, sicil no ağır basar);
  **LİSTEYİ TEMİZLE** (`personel.php`, `islem=tumunu_sil`, `yetki_var('duzenle')` + kutuya "SIL" yazma onayı):
  yeniden yüklemeden önce personel listesini sıfırlar; **üzerinde zimmetli cihaz olan kişi SİLİNMEZ**
  (cihaz bağı kopmasın diye korunur, yeniden yüklemede sicilden eşleşir) ve flash mesajında adlarıyla
  raporlanır. Cihaz/hareket/tanım kayıtları etkilenmez. Tek adımda yapmak isteyen import ekranındaki
  "TAM YENİLEME (sil ve ekle)" kutusunu kullanır.
  `personel.php?mukerrer=1` panelinde grup grup gösterilir ve `pim_personel_birlestir()` ile birleştirilir —
  cihaz zimmetleri hedefe TAŞINIR, hedefte boş olan alanlar kaynaktan tamamlanır, notlar birleşir, kaynak silinir
  (transaction'lı). İçe aktarma bittiğinde mükerrer grup varsa rapor bandında uyarı + panele bağlantı çıkar.
  Gerçek dosyayla doğrulandı: 183 satır → 178 kişi (tam yenilemede 3 silindi, 1 zimmetli korundu), **aynı dosya
  ikinci kez yüklendiğinde 0 yeni / 140 değişmeyen** (mükerrer oluşmuyor).
  **CİHAZ İÇE AKTARMA (2026-09-09)** `it/cihaz_import.php` + çekirdek `it/_cihaz_import.php` (cim_*): kurumsal
  envanter/ERP çıktısı ("Hızlı Rapor — Zimmet Edilen Demirbaş Listesi": Cıhaz Kodu · Serı Nesne Kodu · Serı Nesne
  Adı · Ilk/Mevcut Proje · Kısı · Marka · Model · Sası No · Serı No · İşlemci/RAM/Ekran kartı/HDD) → cihaz envanteri.
  **Dosya okuma, başlık satırı bulma, normalize ve lokasyon eşleştirme personel aktarımıyla ORTAK** (`_import.php`:
  pim_oku/pim_baslik_satiri/pim_norm/pim_tarih/pim_lokasyon_bul) — iki içe aktarma aynı davranır, tek yerde düzeltilir;
  aynı 3 adım (yükle → sütun eşleme ön izlemesi → birleştirme + rapor), aynı `it_import_log` (dosya adına ` [cihaz]`
  eklenir, sayfa yalnız kendi kayıtlarını listeler). **TAM YENİLEME YOKTUR** (cihazın yaşam günlüğü, belgeleri ve
  fotoğrafları silinmemeli): eşleşme **varlık kodu → envanter no → seri no**; yeni satır INSERT + "giris" (zimmetliyse
  + "zimmet") hareketi, mevcut satırda yalnız dosyada DOLU gelen alanlar güncellenir (boş hücre mevcut veriyi silmez),
  notlar birikir. `CIM_ALAN` TR/EN eş anlamlı başlık haritası; `ozellik` ve `notlar` ÇOK sütuna bağlanabilir
  (İşlemci Marka/Model, RAM, RAM Tipi, Ekran Kartı, HDD… tek "Başlık: değer" dizisinde birleşir), diğer alanlar tek
  sütun. `cim_kategori()` cihaz ADINDAN kategori çıkarır ("Monitör"→monitor, "Dizüstü"→laptop; Kategori sütunu varsa
  o esas), `cim_marka()` kurum kodu önekini atar ("M0026-AOC"→AOC), `cim_durum()` metinden IT_DURUM anahtarı üretir
  (yoksa kişi varsa aktif, yoksa depoda). **Seri no boşsa Şasi No seri sayılır**, ikisi de doluysa şasi teknik nota
  gider; "İlk Proje" cihazın notuna yazılır. Envanter no yoksa **veya başka cihazdayken** `it_envanter_no()` ile
  otomatik IT-00001 üretilir. **Kişi** `cim_personel_bul()` ile normalize ad+soyaddan personel kartına bağlanır
  (aynı adlı 2+ kişi → bağlanmaz, raporda listelenir); zimmet gerçekten değişirse günlüğe **iade + zimmet** yazılır —
  yalnızca BÜYÜK HARF → Baş Harf farkı hareket saymaz (pim_norm ile karşılaştırılır). Seçenek: **"eşleşmeyen kişiler
  için personel kartı aç"** (`cim_personel_ekle`, `duzenle` yetkisi) — açılınca cihazlar doğrudan o kartlara zimmetlenir;
  rapordan sonradan tek tıkla da yapılır. Eşleşmeyen lokasyonlar rapordan kök lokasyon olarak eklenir (`islem=lok_ekle`).
  Şablon **sisteme göre üretilir**: `?sablon=1` tanınan 21 sütun + gerçek lokasyon/personel değerleriyle örnek satırlar,
  `?sablon=mevcut` kayıtlı envanteri aynı düzende indirir (`cim_sablon_satiri`) → Excel'de düzelt, geri yükle.
  ⚠ `IT_KATEGORI`/`IT_DURUM` değerleri **konumsal dizidir** (`[ad, ikon]` / `[ad, renk, ikon]`) — `['ad']` ile
  okunmaz, `[0]` ile okunur. Yetki: `sayfa_islemi()` regex'ine `cihaz_import` eklendi → **giris**; sidebar
  "Cihaz İçe Aktar" + cihazlar.php'de "İçe Aktar" düğmesi `can_edit()`. Gerçek dosyayla doğrulandı (189 satır):
  1. yükleme 189 yeni / 0 atlanan (okunan = yeni + güncellenen + değişmeyen + atlanan tutar), kişi kartı açma
  seçeneğiyle 2. yükleme 0 yeni / 185 güncellenen (yalnız zimmetli adı düzeldi) + 116 personel kartı, 3. yükleme
  **0 yeni / 0 güncellenen / 189 değişmeyen** (mükerrer cihaz oluşmuyor, sahte zimmet hareketi yazılmıyor);
  `?sablon=mevcut` round-trip 192 satır → 0 yeni / 192 değişmeyen.
  **VARLIK GRUPLARI + YENİ KATEGORİLER + MERKEZİ İZLEME (2026-09-09)** — envanter artık yalnız BT cihazı değil;
  ağ/güvenlik altyapısı, IP telefon/santral, kamera/NVR/turnike, TV, sarf ve bileşenler de aynı tabloda
  (`it_cihazlar`) tutulur. **Yeni tablo/ekran açılmaz**: `IT_KATEGORI` satırı + `IT_GRUP` üst başlığı yeterlidir.
  • **`IT_GRUP`** (8): BT Envanteri · Ağ ve Güvenlik · İletişim Sistemleri · Güvenlik Sistemleri · Multimedya ·
  Yazılım ve Lisans · Sarf ve Aksesuar · Diğer. `IT_KATEGORI` artık `[ad, ikon, grup]` — ⚠ **anahtarlar VERİDİR**,
  mevcutları yeniden adlandırma; eski 11 anahtar korundu, 15 yenisi eklendi (26): switch · firewall ·
  access_point · superbox · ip_telefon · santral · hat · kamera · nvr · kartli_gecis · turnike · tv ·
  projeksiyon · sarf · bilesen. Yardımcılar `it_grup()`, `it_grup_kategorileri()`, `it_kategori_agaci()`.
  • **`IT_EK_ALAN` — cihaz tipine özel alanlar** (runtime ALTER `it_ek_alan_semasi_kur`, transaction DIŞINDA;
  `varlik_kodu` da buraya taşındı ki cihaz içe aktarma yüklenmese de kolon var olsun): dahili_no · telefon_no ·
  imei · operator · firmware · lisans_durumu · yonetim_kullanici · **yonetim_sifre** · bagli_id · kapasite ·
  kullanim_amaci · adet. Her alan yalnız kendi kategorilerinde görünür (IP telefonda dahili, superbox'ta
  IMEI/operatör, NVR'de disk kapasitesi + yönetim bilgisi, kamerada bağlı NVR, TV'de kullanım amacı, sarfta adet).
  **Kategori değişirse o kategoride görünmeyen alanlar TEMİZLENİR** — monitöre dönüşen kayıtta "dahili no" hayalet
  veri olarak kalmasın. **Yönetim şifresi** yalnız `yetki_var('duzenle')` olana gösterilir/yazılır, formda boş
  bırakılırsa mevcut şifre KORUNUR, detayda "göster" bağlantısıyla açılır.
  • **`it/varliklar.php` — MERKEZİ VARLIK İZLEME**: grup şeridi (sayaçlı) + KPI (listelenen/kullanımda/serviste-
  arızalı/IP adresli/lokasyon/mali değer) + filtreler [arama · cihaz tipi (gruplu optgroup) · lokasyon (alt dahil) ·
  durum · garanti] + gruba göre başlıklı tablo. Sütunlar cihaz tipine göre ANLAMLI doldurulur: "Ağ / Hat" =
  IP·MAC·dahili·telefon·IMEI, "Teknik" = operatör·firmware·kapasite·lisans durumu·kullanım amacı·**bağlı cihaz**
  (kamera→NVR bağlantılı link)·adet. Envanter no yanında **arıza/servis rozeti** (`it_hareketler`'den). Excel
  dışa aktarma 26 sütun. `it_filtre()` **grup** süzgecini ve genişletilmiş aramayı destekler: tek kutudan
  IP · MAC · seri no · envanter no · varlık kodu · dahili · telefon · IMEI (gerçek veriyle doğrulandı — her biri
  tek sonuç döndürür).
  • Dashboard'a **varlık grupları şeridi** (kategori sorgusundan türetilir, ek sorgu yok → merkezi izlemeye giriş),
  raporlara **Varlık Grubu × Durum** tablosu (grup adı tıklanınca o grup merkezi izlemede açılır), Tanımlar'ın
  Kategoriler sekmesine grup başlıkları eklendi. Cihaz formunda kategori select'i **optgroup**'lu.
  • Cihaz içe aktarmada `cim_kategori()` yeni tipleri tanır — ⚠ **sıra önemli**: "IP KAMERA" genel aksesuar
  'kamera'sına değil güvenlik kategorisine, "IP TELEFON" cep telefonuna değil iletişime düşmeli, bu yüzden özel
  tipler haritada önce denenir.
  • **Sidebar'dan "Personel İçe Aktar" KALDIRILDI** (kullanıcı isteği); `it/import.php` duruyor ve
  `personel.php`'deki "İçe Aktar" düğmesinden açılıyor. Yerine "Merkezi Varlık İzleme" açılır menüsü geldi
  (Tüm varlıklar + 8 grup). `IT_GRUP` header'da `defined()` ile korumalı okunur.
  ⚠ **2026-09-10: "Cihaz İçe Aktar" da sidebar'dan KALDIRILDI** (aynı gerekçe) — `it/cihaz_import.php`
  yerinde duruyor ve **Cihazlar & Lisanslar** ekranındaki "İçe Aktar" düğmesinden açılıyor. İki içe aktarma
  da artık kendi liste sayfasından girilir; sidebar'da menü satırı yok.
  **KAYIP ve HİBE DURUMLARI (2026-09-10)** — `IT_DURUM`'a iki durum eklendi: **kayip** (Kayıp / Çalıntı) ve
  **hibe** (Hibe / Devredildi). Cihaz kaydı yine SİLİNMEZ; bu ikisi hurda ile birlikte **envanterden DÜŞEN**
  durumlardır: `IT_DURUM_DUSEN = ['hurda','kayip','hibe']` ve SQL parçası **`it_envanterde($alias='')`**
  bu listeden ÜRETİLİR (`durum NOT IN (…)`; JOIN'lerde `it_envanterde('c')`). Eskiden 20 yerde elle yazılan
  `durum<>'hurda'` bu fonksiyonla değiştirildi — ⚠ **yeni bir "artık bizde değil" durumu eklemek için yalnız
  IT_DURUM + IT_DURUM_DUSEN'e satır eklemek yeterli**, sorgulara dokunulmaz. PHP tarafında `it_durum_dustu()`.
  Düşen durumlar: varsayılan listelerde gizli (durum filtresiyle görünür, satır soluk), mali değere ·
  kategori/lokasyon/marka sayımlarına · garanti uyarılarına · personel zimmet sayılarına GİRMEZ.
  `it_ozet()` artık `kayip`/`hibe` sayaçlarını ve toplamları `dusen` alanını döndürür; dashboard "Toplam Cihaz"
  ve raporlardaki "Cihaz (envanterde)" KPI'ı `toplam − dusen` ile hesaplanır, aylık trend grafiğindeki seri
  "Envanterden düşen" (hurda+kayıp+hibe) olur. **Cihaz detayında iki yeni işlem**: *Kayıp / çalıntı bildir* ve
  *Hibe et / devret* — hurda ile aynı kalıpta (zimmet düşer, `it_hareketler`'e `kayip`/`hibe` satırı yazılır);
  form "Kişi" alanı işleme göre etiketlenir (hibe → "Hibe edilen kurum / kişi", kayıp → "Kaybı bildiren kişi")
  ve açıklama kutusu tutanak/protokol no ister. Envanterden düşmüş cihazda işlem menüsü yalnız "Not ekle"
  bırakır. `cim_durum()` Excel'den "KAYIP/ÇALINTI/ZAYİ" → kayip, "HİBE/DEVİR" → hibe okur; personel zimmet
  geçmişi ve Tanımlar › Durumlar sekmesi yeni durumları sayımlarıyla listeler.
  **SNIPE-IT BELGE & FOTOĞRAF KÖPRÜSÜ (2026-09-10)** `it/snipe_cek.php` + çekirdek `it/_snipe.php` (sn_*) —
  ⚠ Snipe-IT'nin **Excel çıktısında görsel/belge YOKTUR**; dosyalar sunucuda `public/uploads/assets/`
  (fotoğraf, açık disk) ve `storage/private_uploads/assets/` (belge, yalnız uygulama üzerinden) yollarında
  durur, dosya↔cihaz bağı da `action_logs` tablosundadır. İkisine tek yerden ulaşmanın yolu **REST API**:
  `GET /api/v1/hardware?limit&offset` (id · asset_tag · serial · **image** tam URL) ·
  `GET /api/v1/hardware/{id}/files` (dosya listesi) · `GET /api/v1/hardware/{id}/files/{file_id}` (dosyanın
  KENDİSİ). Kimlik `Authorization: Bearer <token>` (Snipe-IT → kullanıcı menüsü → Manage API Keys).
  • **Token SIRDIR**: formdan girilirse yalnız `$_SESSION`'da tutulur, DB'ye/diske YAZILMAZ; kalıcı isteniyorsa
  `config.php`'ye (git-ignored) `SNIPE_URL` / `SNIPE_TOKEN` eklenir — `sn_ayar()` önce sabitlere bakar.
  • **3 adım**: bağlan (test + eşleşme önizlemesi) → **15'erli partiler** hâlinde indirme (sayfa kendini
  yeniler, ilerleme çubuğu; uzun listede zaman aşımına düşmesin diye) → rapor.
  • **Eşleşme** `sn_eslestir()`: **snipe_id → cihaz kodu (asset tag) → IFS nesne no → seri no → envanter no**.
  Eşleşen cihaza Snipe id'si yazılır (yeni kolon **`it_cihazlar.snipe_id`**, `it_ek_alan_semasi_kur`) → sonraki
  çekimler birebir olur; `CIM_ALAN`'a `snipe_id` eklendiği için Excel'deki **"Kimlik"** sütunu da bunu doldurur.
  • **Mükerrer yok**: indirilen her dosyanın md5'i cihazın mevcut belgeleriyle karşılaştırılır
  (`it_belge_md5ler`), aynı bayt ikinci kez eklenmez — istendiği kadar tekrar çalıştırılır.
  `it_belge_yukle` artık ortak `it_belge_kaydet()`e devreder (form yüklemesi taşır, indirme kopyalar).
  • `sn_belge_turu()` dosya adı/notundan tür çıkarır: **zimmet/tutanak/teslim/imzalı → `tur='zimmet'`**
  (listede yeşil rozet), transfer/sevk → `transfer`, gerisi `belge`. Fotoğraf `foto_url`u günceller.
  • `sn_url_indir()` fotoğrafı çekerken, adres Snipe sunucusunun kendisiyse **token da gönderir** (uploads
  klasörü kimlik doğrulaması arkasındaysa da çalışsın). ⚠ `curl_close()` PHP 8.5'te deprecated — `unset()`.
  • Yetki: `sayfa_islemi()` regex'ine `snipe_cek` eklendi → **giris**. Giriş noktası: Cihaz İçe Aktar
  ekranındaki "Snipe-IT'den Belge Çek" düğmesi (sidebar'a yeni satır eklenmedi).
  ⚠⚠ **GERÇEK SUNUCUDA ÇIKAN İKİ HATA (2026-09-10, ilk çalıştırma)** — her ek "desteklenmeyen tür —
  application/json" diye reddedildi:
  (1) **Snipe-IT dosya indirme ucu HATALARI DA `HTTP 200` + JSON gövde ile döndürür**
  (`{"status":"error","messages":"…"}`; kaynak: `UploadedFilesController::show()` — invalid_id ve
  file_not_found dalları `response()->json(..., 200)`). HTTP koduna bakan denetim bu gövdeyi DOSYA sanıp
  diske yazıyordu. Artık `sn_json_hata()` gövdenin JSON hata olup olmadığına bakar, **gerçek Snipe mesajını**
  yukarı taşır; fotoğraf ucu için de aynı kontrol var. Dosya listesindeki **`url`** alanı (transformer'ın
  `uploads_file_url()`'ü) **yedek indirme yolu** olarak denenir — API ucu hata verse de dosya iner.
  Listeden **`exists_on_disk`** de okunur: false ise indirmeye kalkışılmaz, "dosya Snipe-IT sunucusunda
  bulunamadı (kayıt var, dosya silinmiş)" diye raporlanır.
  (2) **Office ekleri (.xlsx/.docx) reddediliyordu**: hem izin listesinde yoklardı hem de finfo bir .xlsx'i
  **`application/zip`** (eski .xls'i `application/CDFV2`) diye sezer. `IT_BELGE_MIME` PDF + görsellere
  Word/Excel türlerini ekledi ve yeni **`it_belge_mime()`** sezilen tür listede yoksa **genel kapsayıcı**
  (zip/octet-stream/CDFV2…) olup olmadığına bakıp UZANTIDAN karar verir (uzantı haritasında yalnız güvenli
  türler var). Gerçek eklerde .xlsx, .docx, taranmış .pdf ve .jpg'ler artık iniyor.
  • Sahte Snipe-IT sunucusuyla doğrulandı (418 varlık): 1. tur 60 fotoğraf + 150 belge indi,
  **2. tur 0 belge / 120 atlandı**, 3. tur **0 yeni / 135 atlandı**; zimmet adlı dosyalar `zimmet`
  türüyle işaretlendi; hatalı token → "Yetki reddedildi (HTTP 401)", yanlış adres → bağlantı hatası.
  **TRANSFER = PROJELER ARASI SEVK (2026-09-10)** — iş kuralı: cihaz **bir projeden (Batı Yakası vb.)
  İHTİYAÇ DUYAN BAŞKA PROJEYE** gönderilir; ilgili kişi gönderir, karşı taraf teslim alır. Yolda geçen süre
  takip edilebilsin diye ayrı bir DURUM: `IT_DURUM`'a **`transfer` (Transfer / yolda)**, `IT_HAREKET`'e de
  `transfer` eklendi. **Envanterden DÜŞMEZ** (IT_DURUM_DUSEN'e girmez — cihaz hâlâ bizim, mali değere ve
  sayımlara girer), ama **zimmet düşer** ve depodaki kullanılabilir stok sayılmaz.
  • **Cihaz kartında iki işlem**: *Transfere çıkar* (hedef proje/lokasyon + **isteyen/teslim alacak kişi**
  seçilir → durum transfer, zimmet düşer, lokasyon hedefe taşınır) ve durum transfer'ken menüde beliren
  *Transfer teslim alındı (depoya)* (durum depoda, hedef düzeltilebilir, teslim alan yazılır).
  • ⚠ **Günlük satırı SABİT BİÇİMDE** yazılır: `Sevk: <kaynak> → <hedef> · gönderen: X ·
  isteyen/teslim alacak: Y · <not>`. `it_transfer_son()` **'Sevk:' önekiyle** çıkış satırını bulur (teslim
  alma satırı da `tur='transfer'` olduğundan ayırt edilmeli) ve **id'ye göre** sıralar (geriye dönük tarihli
  yeni sevk, eski tarihli kayda yenilmesin). `it_transfer_gunleri()` aynı kuralla **"kaç gündür yolda"**
  hesaplar — listede ve cihaz kartında rozet, 14 günü aşan kırmızı.
  • **`it/transfer_tutanak.php` — CİHAZ TRANSFER (SEVK) TUTANAĞI** (A4, ERN Taahhüt logolu): gönderen ↔ hedef
  proje kutuları, gönderen/isteyen, sevk tarihi, cihaz künyesi tablosu, taahhüt maddeleri, çift imza.
  `?id=` tek cihaz · **`?lok=` o lokasyona yolda olan TÜM cihazlar tek tutanakta** (bir sevkiyat = bir belge).
  **İmzalı kopya geri yüklenir** (`it_belgeler.tur='transfer'`; dosya diske bir kez yazılır, tutanaktaki
  diğer cihazlara aynı URL ile bağ satırı eklenir) — `it_belge_yukle` artık 'zimmet' | 'transfer' | 'belge'
  kabul eder, evrak rozeti ikisini birden sayar. Cihaz listesinde transferdeki satırda tutanak düğmesi +
  imzalı sevk tutanağı yoksa sarı ⚠ rozeti.
  • Dashboard'a "Transfer (yolda)" KPI'ı, raporlara aynı gösterge (Excel + PDF dahil); `it_ozet()` `transfer`
  sayacını döndürür. `cim_durum()` Snipe-IT'nin "Transfer" durumunu artık **depoda değil transfer** okur
  (kaynak dosyada 101 satır). Tanımlar › Durumlar sekmesi yeni durumu sayımıyla listeler.
  **KİMLİK KODLARI + DONANIM KÜNYESİ + HIZLI ARAMA (2026-09-10)** — kaynak: kurumsal **DEMİRBAŞ ZİMMET FORMU**
  (YILDIZLAR GRUP çıktısı, PDF). Sistemdeki cihaz kartı o formun yanında eksik kalıyordu; formdaki her alan
  karşılandı.
  • **Kimlik kodları kategoriden BAĞIMSIZ** ve cihaz formunda ayrı bir blokta: **varlik_kodu** = formdaki
  *Nesne No* (IFS demirbaş kodu, `FRM-0002-82026-2552600167`) — DB'de vardı ama **formda yoktu**, artık
  girilebiliyor · **sasi_no** = *Şasi No / Seri No*'nun ikinci yarısı — eskiden içe aktarmada seri boşsa seriye
  taşınıp doluysa `ozellikler` metnine gömülüp KAYBOLUYORDU, artık kendi kolonunda (seri boşken seriye de
  kopyalanır ki aramada bulunsun) · **imei** — eskiden yalnız telefon/tablet/superbox'ta görünüyordu, "artık her
  cihazda IMEI var" denince çekirdeğe alındı. Üçü de `cihaz_detay`'da ve aramada.
  • **Donanım künyesi** (IT_EK_ALAN, yeni kolonlar): `islemci` · `ram` · `ekran_karti` · `disk` · `anakart` ·
  `ekran_boyutu` · `kiralik_firma` (+ mevcut `kapasite`). Serbest metin `ozellikler` KALDI ama artık **özet**
  alanı: boş bırakılırsa `it_ozellik_ozet()` künyeden üretir (liste/Excel/tutanak satırı bundan beslenir).
  • **Cihaz içe aktarma** artık donanım sütunlarını ayrı alanlara yazar. `CIM_COKLU` = birden çok sütundan
  beslenebilen alanlar (ozellik · notlar · islemci · ram · ekran_karti · disk): "Islemcı Marka" + "Islemcı Model"
  tek alanda " · " ile birleşir. ⚠ ozellik/notlar "Başlık: değer" olarak birikir **ama sütun başlığı zaten
  genelse** (`CIM_GENEL_BASLIK`: NOT/AÇIKLAMA/TEKNİK ÖZELLİK…) önek EKLENMEZ — aksi halde `?sablon=mevcut`
  round-trip'inde not her turda "Not: <eski not>" diye kendi üstüne sarılıyordu. Şablon 21 → **31 sütun**.
  Gerçek dosyayla doğrulandı: 189 satır → 0 yeni / 142 güncellenen (künye doldu) / 47 değişmeyen; 189 IFS nesne
  no, 125 şasi no, 87 işlemci kaydı; `?sablon=mevcut` round-trip 198 satır → **0 yeni / 0 güncellenen /
  198 değişmeyen**, eşleşmeyen sütun yok.
  • **ZİMMET TUTANAĞI kurumsal forma hizalandı** (`zt_kunye()`): cihaz tablosunun altına her cihaz için
  **ÖZELLİKLER** bloğu (Nesne Açıklama · Nesne Türü/Kategori + Marka · Model · Şasi/Seri No · Kullanım Durumu ·
  Zimmetlenen Personel · Lokasyon · Kiralanan Firma · Kapasite · İşlemci · RAM · Ekran Kartı · HDD · Anakart ·
  Ekran Boyutu · IMEI · IP/MAC · İşletim Sistemi · Cihaz Kodu — **yalnız DOLU alanlar**, ikişerli sütun) +
  **KULLANICI BİLGİLERİ** bloğu (ad soyad · sicil · mail · telefon · birim/unvan · lokasyon).
  • **Ana ekranda HIZLI ARAMA** (`it/index.php` en üstte, autofocus): tek kutudan seri no · IFS nesne no · IMEI ·
  envanter no · MAC · IP · dahili · ad soyad → `varliklar.php`'ye gider; sonuç satırında cihaz + **kimde** +
  **hangi lokasyonda** + **durum** birlikte görünür. Merkezi izlemede Seri No sütunu altında şasi ve IFS kodu da
  yazar (aranan numarayı göz teyit etsin), Teknik sütununa donanım künyesi eklendi, Excel 26 → **34 sütun**.
  Gerçek veriyle doğrulandı: IFS kodu · şasi · envanter no · seri no aramalarının her biri tek sonuç döndürüp
  kişi + lokasyon + durumu gösteriyor.
  **SNIPE-IT AKTARIMI + CİHAZ KODU + MALİ GİZLEME + İMZALI EVRAK (2026-09-10)** — kaynak: **Snipe-IT
  "Export Assets"** dosyası (412 satır, 40 sütun; sayfa adı "Table").
  • **ÜÇ AYRI KİMLİK KOLONU** ayrıştırıldı — eskiden hepsi `envanter_no`ya sıkışıyordu:
  `envanter_no` = **BİZİM sabit numaramız** (IT-00001; tutanaklarda geçer, **içe aktarma onu ASLA ezmez**) ·
  **yeni `cihaz_kodu`** = kurum içi demirbaş etiketi (M160 / N221) · `varlik_kodu` = **IFS Seri Nesne No**
  (FRM-0002-…). Runtime ALTER `it_ek_alan_semasi_kur` + kurulum; `it_filtre` araması ve
  `cihazlar.php` / `varliklar.php` / `cihaz_detay` / `cihaz_form` / zimmet tutanağı / Excel çıktıları üçünü de
  gösterir (liste başlıkları **Envanter No · Cihaz Kodu · IFS Seri Nesne No**, sıralama anahtarları `kod`/`ifs`).
  **Geçiş** `cim_semasi_kur` içinde idempotent: `envanter_no` IT-… biçiminde DEĞİLSE değeri `cihaz_kodu`ya
  kopyalanır (envanter no yerinde kalır). ⚠ Bunu yapmadan önce Snipe satırı mevcut kaydın envanter no'sunu
  başka bir cihazın koduyla ezmeye çalışıyor ve **UNIQUE ihlali** veriyordu.
  • **Snipe-IT sütun sözlüğü** `CIM_ALAN`'a eklendi: Demirbaş Etiketi→cihaz_kodu · IFS Cihaz Kodu→varlik_kodu ·
  **Model→ kurum içi kod, Model No.→ gerçek model** (yeni `model_no` alanı) · Çıkış Yapılmış Olan Kişi→kisi ·
  **Çalışan Numarası→`sicil_no`** · Başlık→**`unvan`** (personel kartında boşsa doldurulur, dolu unvan ezilmez) ·
  Konum→lokasyon · Varsayılan Konum→ilk_lokasyon · Şirket→`sirket` · Çıkış Tarihi→`zimmet_tarihi` ·
  Garanti Süresi Sona Erdi→garanti_bitis (plain "Garanti" = "24 ay" SÜREdir, tarih değil — eşlenmez).
  ⚠ Bare **'ZIMMET'** kisi eş anlamlılarından ÇIKARILDI: "Son zimmet teslim tarihi" gevşek eşleşmeyle kişiye
  düşüp `ozellikler`i kirletiyordu. Fiyat `cim_fiyat()` ile hem "8,598,960.00" (ABD) hem "8.598.960,00" (TR) okur.
  • **Kimlik ayrıştırma** (`cim_satir_cozumle`): `cim_ifs_kodu()` FRM-/ORT-/ZFRM- kalıbını tanır → etiket IFS
  biçimindeyse `varlik_kodu`ya taşınır ve `$kodTasindi` işaretlenir; **yalnız o zaman** `cim_kod_mu()` ile
  Model'deki kısa kod (N288) cihaz kodu sayılır — aksi halde "A2604" gibi **gerçek model numaraları** demirbaş
  etiketi sanılıyordu. Kişi hücresindeki parantezli kullanıcı adı `cim_kisi_ad()` ile atılır
  ("TUĞBA AKYAZI KUBLAY (TUĞBAAKYAZI)"). Ad boşsa `cim_ad_mi()` gerçek ada benziyorsa Model, değilse
  "Kategori — Marka" TÜRETİLİR — ⚠ **türetilmiş ad mevcut kaydı GÜNCELLEMEZ** (elle verilmiş "Dizüstü
  Bilgisayar" adları her aktarımda bozuluyordu); yalnız yeni kayda yazılır.
  • **Eşleşme sırası** IFS nesne no → cihaz kodu → envanter no → seri no. **Aynı DB kaydına iki dosya satırı
  denk gelirse ikincisi ATLANIR** ve çelişki raporlanır — yoksa satırlar birbirini ezip her aktarımda
  gidip geliyordu (N282 ↔ N405). Personel eşleşmesi **önce SİCİL** (`cim_personel_sicil`), sonra ad+soyad;
  "kişi kartı aç" seçeneği kartı sicil + unvanla açar.
  • **Yeni kategoriler**: `drone` (Drone / İHA) · `fotograf` (Fotoğraf / Video Kamerası) — ikisi de multimedya;
  `cim_kategori` haritasına PLOTER/PLOTTER→yazici, PROJEKTOR→projeksiyon eklendi. ⚠ `fotograf` haritada
  **en sona** konur, yoksa "IP KAMERA" güvenlik yerine ona düşer. `cim_durum()` Snipe sözlüğünü okur:
  **"… Atanmış" → aktif** (depo kelimelerinden ÖNCE denenir, "Boş / Yedek Atanmış" kullanımdadır) ·
  Boş/Yedek · Transfer · Bekliyor · Dağıtılabilir → depoda · Servis/Onarım → serviste · Hurda → hurda ·
  Kayıp/Çalınmış → kayip · Hibe → hibe.
  • **GARANTİ + FİYAT GİZLENDİ** — `it_mali_goster()` (varsayılan **false**; `config.php`'de
  `define('IT_MALI_GOSTER', true);` ile geri açılır). Kapalıyken cihaz listesi/merkezi izleme/cihaz kartı/
  cihaz formu/zimmet tutanağı/dashboard/raporlar (Excel + PDF dahil) garanti sütununu, garanti süzgecini,
  garanti grafiğini, fiyat ve mali değer alanlarını göstermez; formda mevcut değerler **gizli alanla korunur**
  (kaydetmek veriyi silmez). Yerine dashboard'da **"İmzalı evrakı eksik zimmet"**, listelerde **imzalı evrak**
  sayacı çıkar. Kaynak dosyada 412 satırın yalnız 1'inde garanti, 3'ünde fiyat vardı.
  • **İMZALI EVRAK** — `it_belgeler.tur` ('belge' | **'zimmet'**; runtime ALTER). `it_belge_yukle(..., $tur)`,
  `it_belge_sayilari()`. **`zimmet_tutanak.php`'ye geri yükleme paneli** (depo `hareket_sonuc` desenli, yazdırmada
  gizli): tutanağı yazdır → imzalat → tara → yükle; belge **tutanaktaki TÜM cihazlara** bağlanır (dosya diske bir
  kez yazılır, diğer cihazlara aynı URL ile bağ satırı eklenir — `it_belge_sil` dosyayı yalnız son bağ koptuğunda
  siler) ve her cihazın yaşam günlüğüne not düşülür. `cihaz_detay`'da ayrı "İmzalı Zimmet Tutanağı" kutusu
  (yeşil/sarı durum bandı) + belge kartında ✓ ikonu; `cihazlar.php`'de **Evrak sütunu**: yeşil = imzalı tutanak var,
  gri = başka belge var, sarı ⚠ = zimmetli ama imzalı evrak yok.
  • **LİSTE SÜTUNLARI (2026-09-10, kullanıcı isteği)**: cihaz listesinden **Envanter No sütunu KALDIRILDI**
  (üç kimlik yan yana karışıklık yapıyordu; alan DB'de ve Excel'de duruyor). Cihaz kartına giriş artık
  **Cihaz Kodu · IFS Seri Nesne No · Cihaz adı** üzerinden — cihaz kodu boşsa o hücrede envanter no gösterilir
  ki satırın her zaman tıklanabilir bir kimliği olsun. **Her sütun sıralanabilir** (Seri No da eklendi),
  varsayılan sıralama `kod`. Aynı desen **personel** ekranına da uygulandı: Lokasyon (`lok_ad` alt sorgusu) ve
  Telefon sütunları sıralanabilir oldu; personel kartındaki cihaz tablosu da Cihaz Kodu + IFS No gösterir.
  Zimmet değeri / Değer sütunları `it_mali_goster()` ile gizlenir (liste, Excel, personel kartı).
  • Şablon 31 → **32 sütun** (Envanter No · Cihaz Kodu · IFS Seri Nesne No …). Mükerrer merkezi (`MK_KURAL`)
  it_cihazlar anahtarlarına `cihaz_kodu` eklendi. Gerçek dosyayla doğrulandı: **412 satır → 251 yeni /
  159 güncellenen / 2 atlanan** (1 boş satır + 1 kimlik çakışması), **2. yükleme 0 yeni / 0 güncellenen /
  410 değişmeyen**; `?sablon=mevcut` round-trip 449 satır → **0 yeni / 0 güncellenen / 449 değişmeyen**,
  eşleşmeyen sütun yok.
  **TANIMLAR EKRANI `it/tanimlar.php` (2026-09-09)** — tek sayfa, sekmeli (Snipe-IT'deki "tanım tablosu seç"
  düzeni): **Lokasyonlar · Kategoriler · Üreticiler · Modeller · Tedarikçiler · Şirketler · Durumlar · Personel**.
  • *Lokasyonlar* = `it_lokasyonlar` (hiyerarşi korunur) + yeni **sehir / adres / renk** kolonları; satırda ad
  (girintili ağaç, renk noktası), proje kodu, şehir, adres, tür, **cihaz [alt lokasyonlar dahil]**, **kişi** sayıları;
  ekle/düzenle tek satırlık form, mükerrer ad engeli (aynı üst altında), kendi altına taşıma engeli, silmede bağlı
  kayıt varsa **pasife alınır**, boş tabloda "varsayılan yapıyı yükle". `it/lokasyonlar.php` artık buraya
  **yönlendirir** (eski bağlantılar kırılmasın; sidebar "Tanımlar (lokasyon, marka…)").
  • *Üretici / Model / Tedarikçi / Şirket* = yeni tablo **`it_tanimlar`** (tur, ad, kod, aciklama, renk, sira, aktif;
  `it_tanim_semasi_kur` + kurulum): cihaz kartındaki alanlar **serbest METİN kalır** (eski kayıtlar bozulmaz), bu
  liste formdaki **datalist önerisinin kaynağıdır** (`it_tanim_oneri` = tanımlar + cihazlarda geçen değerler birleşik)
  ve "Kullanım" sütunu tanımın kaç cihazda geçtiğini gösterir (`it_tanim_kullanim`, it_norm ile eşleşir).
  Cihaz formuna **Şirket** alanı eklendi (`it_cihazlar.sirket`, runtime ALTER) + Model alanına datalist.
  • *Kategoriler / Durumlar* = sistem sabiti (IT_KATEGORI / IT_DURUM: ikon, renk ve iş kuralları koda bağlı) —
  eklenmez/silinmez, yalnız cihaz sayımlarıyla listelenir.
  • *Personel* sekmesi = KPI (çalışan/ayrılan/zimmeti olan) + son 10 kişi + tam listeye / yeni personele /
  Excel içe aktarmaya kısayol. ⚠ `mb_strtoupper` Türkçe 'i'yi 'I' yapar ("ÜRETICILER") — başlıklar PHP'de
  büyütülmez, CSS `text-uppercase` kullanılır.
  ⚠⚠ **`includes/header.php` DEĞİŞKEN SIZINTISI (2026-09-09, canlıda fatal)**: header her sayfaya dahil edilir;
  içindeki `foreach (… as $p)` satırı sayfanın kendi `$p` değişkenini (personel_detay.php'de personel satırı)
  STRING'e çeviriyordu → "Fatal error: Cannot access offset of type string on string ... personel_detay.php:62".
  Header/footer/403 içindeki tüm geçici değişkenler artık **`$__` önekli** (`$__parts`, `$__ph`, `$__mk`, `$__mv`) —
  **bu dosyalara önekisiz değişken yazma**. Aynı sınıftan ikinci sızıntı `includes/footer.php`'deki mobil alt
  menüde bulundu (`foreach ($__navItems as [$sf,$et,$ik])` → `$__nSf`/`$__nEt`/`$__nIk`); footer sayfanın SONUNDA
  dahil edildiğinden çıktıyı bozmuyordu ama sayfanın `$sf`/`$et`/`$ik` değişkenlerini eziyordu (itsm koşucusunun
  oturum dosyası yolu `$sf` böyle "raporlar.php" oluyordu). Regresyon testi: itsm artık **gerçek** header/footer
  ile koşar (stub değil); personel_detay/personel/tanimlar/cihazlar/cihaz_detay/cihaz_import/index/raporlar
  sayfaları fatal vermeden render oluyor.
  Çekirdek `it/_ortak.php`: IT_KATEGORI / IT_DURUM / IT_HAREKET sabitleri, it_semasi_kur, it_envanter_no, it_filtre,
  it_secenekler (sütun whitelist), it_ozet, it_garanti_kalan, it_tarih, it_sayi, it_hareket_ekle, it_belgeler/
  it_belge_yukle/it_belge_sil, it_dosya_listesi. **Yetki**: sayfalar `require_auth([admin,toa,to,depo,it_sorumlusu])`;
  yazma `yetki_var('giris'/'duzenle')` ile; yeni rol **`it_sorumlusu`** (IT Sorumlusu) şablonu: it tam + beton/depo oku;
  `depo` şablonuna it oku+giriş eklendi. Header'da Kurulum linki GERÇEK role bakar (matris eşlemesi duzenle→toa sayar,
  kurulum sayfası ise rol bazlı → 403 olurdu). Test: scratchpad `itsm/` (SQLite; `it_patch.php` DATE_ADD/CURDATE/IF
  eşlemeleri, `run.php` sayfa/POST koşucusu) — form/liste/detay işlemleri/tutanak/dashboard/rapor doğrulandı.
- Geliştirici: **Tayyar Akbulut**. Sürüm: v3.0. Canlı: `https://ernsaha.com.tr/beton/` (eski: takbulut.com/beton/).

> **⭐ TEMEL İLKE — Excel şablonu "kutsal kitap" (tek doğru kaynak).** Sistem, ilgili Excel
> şablonunu **birebir yansıtır**; veri/toplam çelişkisinde **Excel esastır**, sistem ona göre
> düzeltilir. **Beton modülü → Beton Takip Excel** şablonu; **Demir modülü → Demir Takip Excel**
> şablonu. Yeni içe aktarma/rapor/özet eklerken hedef her zaman şablonla eşitlik olmalı
> (tam yenileme/eşitleme desenleri buradan gelir). Şablon sayfaları: Sayfa1(irsaliyeler), VERİ(tanımlar),
> KOT(blok→kot), İCMAL, imalat/zayiat sayfaları (PRP Bina Üstyapı, İksa/Temel Altı Kazık, İstinat…),
> METRAJ, MOBİLİZASYON. İmalat sayfaları `metraj_takip.php` ile sisteme yansıtılır.

### Teknoloji
- Backend: PHP (framework yok), PDO/MySQL 8, prepared statements her yerde.
- Frontend: Bootstrap 5.3.3 + Bootstrap Icons, Chart.js 4.4.4, Google Fonts (Outfit), sunucu-tarafı render + PWA (`manifest.json`, `sw.js`).
- Excel: `Shuchkin\SimpleXLSX` (composer, okuma) + `includes/XlsxWriter.php` (yazma; **varsayılan ERN Taahhüt logolu** — kurucu 2. parametre false ile kapatılır, başlık 4. satıra kayar) + client-side **ExcelJS** (formatlı rapor). **Rapor dışa aktarma ortak katmanı `assets/js/ern_rapor.js`**: ERN_RAPOR.wb/title/hdr/save (logolu çok sayfalı ExcelJS) + ERN_RAPOR.popup({mode:'pdf'|'print'}) (logolu A4 penceresi, jsPDF doğrudan kaydet + yazdır). TÜM modül raporları (beton hariç kendi eski deseninde) bu katmanı kullanır: Excel'e Aktar + PDF İndir + Yazdır üçlüsü. Sayfa script'ten önce `window.ERN_ROOT` tanımlar ('' veya '../').
- AI: Claude (Haiku 4.5) / Gemini / OpenRouter — `AI_PROVIDER` ile seçilir (`includes/ai_call.php`).

---

## 2. Git Branch Stratejisi & Dağıtım (ÖNEMLİ)

- **`claude/blissful-heisenberg-j6jhyk`** = geliştirme branch'i (buraya commit/push).
- **`claude/organize-control-panel-hUe4z`** = **deploy branch'i**. Buraya push → canlıya gider.
- **Akış**: değişikliği blissful'a commit + push → deploy branch'ine ff-merge + push.
  Her iki branch'i senkron tut (bazen kullanıcı GitHub web'den deploy branch'ine commit atar;
  push reddedilirse `git fetch` + merge/senkronla).

### Barındırma (2026-08 itibarıyla)
- **Canlı sunucu = kendi VPS'imiz** (Netlen, Ubuntu, IP 45.74.158.99), panel **aaPanel** (TR arayüz).
  Site kökü: `/www/wwwroot/ernsaha.com.tr/beton/`. cPanel/takbulut.com **terk edildi** (arşiv).
- `ernsahaoperasyon.com.tr` **ayrı bir projedir** (kurumsal tanıtım sitesi, `varlik-site/`);
  bu uygulamayla ilgisi yok, oraya dokunma.
- PHP 8.5. `curl_close()` deprecated → kullanma. `fileinfo` eklentisi kapalı olabilir:
  MIME tespiti için `mime_content_type()`/`finfo_*` **doğrudan çağrılmaz**, `guess_mime()`
  (functions.php) kullanılır — eklenti yoksa uzantıdan tahmin eder.

### Deploy yöntemi
- **`deploy2.php`** (tercih edilen): tarayıcıdan `deploy2.php?token=...` açılır;
  GitHub'dan deploy branch zip'ini çekip `__DIR__`'e açar. Yalnız **`config.php` + `backups/`**
  korumalıdır (deploy dosyaları artık sır içermediğinden normal güncellenir). **`DEPLOY_TOKEN`
  ve `GITHUB_PAT` `config.php`'de** (git-ignored) tanımlanır — koda/git'e sır girmez. `setup.php`
  kaldırıldı. ⚠️ Token/PAT'ı **buraya (CLAUDE.md) yazma** — güvenlik.
- GitHub Actions FTP workflow'u **kaldırıldı** (cPanel terk edildi; tek deploy yolu deploy2.php).
- deploy2.php **kalıntı temizliği** yapar: repodan taşınan/kaldırılan dosyalar `$obsolete`
  listesindedir ve her deploy'da sunucudan silinir. Dosya taşırken bu listeye ekle.
- **Not**: her kod düzenlemesinde `php -l` ile lint et; gömülü JS'i `node --check` ile doğrula.

---

## 3. Paylaşılan Altyapı (`includes/`)

- **`db.php`** → `$pdo` (beton DB). config.php yoksa install.php'ye yönlendirir.
- **`db_demir.php`** → `$pdoDemir` (demir DB). `DEMIR_DB_NAME` config'de tanımlıysa **ayrı MySQL DB**,
  değilse ana DB (`demir_` önekli tablolar çakışmaz). Opsiyonel `DEMIR_DB_USER`/`DEMIR_DB_PASS`.
- **Giriş deneme sınırı (login.php)**: 15 dk içinde **3 hatalı deneme → 15 dk kilit** (IP VEYA
  kullanıcı adı bazlı; tablo `giris_denemeleri` runtime CREATE, 1 günden eski kayıtlar silinir).
  Kilitliyken denemeler sayaca yazılmaz (süre uzamaz); başarılı giriş sayacı sıfırlar; hatalı
  denemede kalan hak gösterilir. Config ile ayar: `LOGIN_DENEME_LIMIT`, `LOGIN_KILIT_DK`.
- **`auth.php`** — kimlik/yetki + **oturum idle timeout**:
  - `SESSION_LIFETIME` (varsayılan **3600 sn**), config'de override. `gc_maxlifetime` = +300.
  - Her istekte `last_activity` yenilenir; aşılırsa oturum temizlenir + login'e yönlendirir.
  - **`require_auth()` login yönlendirmesi `$rootPath` kullanır** (alt klasör `demir/`'den doğru
    `login.php`'ye gider — bunu bozma; yoksa 404 olur).
  - **Roller ENUM**: `admin`, `teknik_ofis_admin`, `teknik_ofis`, `saha_sefi`, `depo`.
  - **KULLANICI BAZLI MODÜL ERİŞİMİ** (rolden bağımsız, 2026-09): `users.modul_erisim` (VARCHAR, virgüllü
    liste; **boş/NULL = sınırsız** — eski kullanıcılar etkilenmez). Sabitler/fonksiyonlar auth.php'de:
    `MODULLER` (anahtar → [ad, ikon, giriş sayfası]; beton/demir/seramik/depo/akaryakit/crm/prekast/whatsapp/it),
    `MODUL_MUAF` (login/logout/kurulum/kullanicilar/yedek/aktivite… — denetimden muaf kök sayfalar),
    `aktif_modul()` (PHP_SELF klasöründen), `modul_erisimi()` (izin listesi; **admin her zaman sınırsız**;
    değer her istekte DB'den okunur — static önbellekli, config.php gerekirse yüklenir — böylece admin
    değişikliği anında geçerli olur, yeniden giriş beklenmez), `can_module()`, `ilk_modul_sayfasi()`,
    `modul_erisim_semasi()` (runtime ALTER). Denetim **`require_auth()` içinde tek noktada** yapılır:
    izin yoksa 403; kök `index.php` istisnası → 403 yerine izinli ilk modüle **yönlendirir** (kullanıcı
    çıkmaz sokakta kalmaz). ⚠ `/api/` yolları muaf — demir sayfaları kök `../api/demir_*.php`'yi çağırır,
    modül denetimi bunları kırardı; uçlar kendi `require_auth` rol kontrolünü yapar.
    `includes/403.php` mesajı + **kullanıcının girebildiği modüllerin linklerini** gösterir.
    `header.php` modül şeridi `MODULLER` + `can_module()` ile üretilir (izinsiz modül hiç görünmez).
    `login.php` girişte `modul_erisim`i oturuma yazar ve **izinli ilk modülün ana sayfasına** yönlendirir
    (`?redirect=` verilmişse o önceliklidir).
  - **MODÜL ADLANDIRMA / GİZLEME / SIRALAMA** (2026-09): tablo `modul_ayarlar`
    (anahtar PK, `ad` [boş=varsayılan], `gizli`, `sira`) — `MODULLER` sabiti sistemin VARSAYILANI kalır,
    bu tablo yalnız **üzerine yazar**; tablo yoksa/erişilemezse sistem varsayılanlarla sorunsuz çalışır.
    Fonksiyonlar auth.php'de: `modul_ayar_semasi()` (runtime CREATE + kurulum), `modul_ayarlari()`
    (istek başına tek okuma, static önbellek), **`modul_listesi(bool $gizliDahil=false)`** (MODULLER +
    ayarlar birleşimi, `sira` sonra doğal sıraya göre sıralı; anahtar => [ad, ikon, sayfa, gizli, sira,
    dogal, varsayilan_ad]), `modul_ad()`, `modul_gizli()`. **Gizli modül `can_module()` içinde
    admin dışında herkese kapalıdır** (yoksa modülü geri açacak kimse kalmazdı) ve menülerde/şeritte
    hiç görünmez; 403 mesajı "yönetici tarafından kapatılmış" der. `ilk_modul_sayfasi()` gizlileri
    atlar (beton gizliyse ilk görünür modüle düşer). Tüketiciler: `header.php` modül şeridi + `$__modAd`,
    `includes/403.php`, `kullanicilar.php` (gizli modül rozetle işaretlenir ama izin verilebilir —
    modül geri açıldığında kullanıcı beklemesin). Yönetim ekranı **`moduller.php`** (admin, Araçlar menüsü).
  - **GELİŞMİŞ YETKİ MATRİSİ** (2026-09): rol artık **etiket + başlangıç şablonu**; gerçek yetki kullanıcı
    bazlı **modül × işlem** matrisidir — `users.yetkiler` (TEXT JSON `{"beton":["oku","giris"],"crm":["oku","rapor"]}`)
    + `users.unvan` (serbest görev adı, topbar/sidebar'da rol etiketi yerine gösterilir) + `role` VARCHAR(40)
    (ENUM'dan genişletildi; runtime `yetki_semasi()` + kurulum). İşlemler `YETKI_ISLEMLER`: **oku** (modülü
    açma/liste/detay) · **giris** (yeni kayıt, import, tarama) · **duzenle** (mevcut kaydı değiştirme/silme,
    tanımlar) · **onay** (saha/teknik onay, toplu onay) · **rapor** (raporlar, icmal/zayiat ekranları,
    `?export=`/`?indir=`). Roller `ROLLER` (ad, rozet rengi, açıklama): eski 5 rol + **kalite** (Kalite Birimi),
    **proje_muduru**, **direktor** (Direktör/Üst Yönetim), **izleyici** (salt okuma), **it_sorumlusu** (IT Sorumlusu);
    `yetki_sablon($rol)` her rolün varsayılan matrisi (kullanıcı ekranında "Rol şablonunu uygula"). ⚠ Matrissiz
    (eski) kullanıcı klasik 5 rol dışında bir roldeyse `yetki_var` ŞABLONUNU uygular (rol listesi kapıları ise
    in_array kalır). `yetki_normalize()` bilinmeyen
    modül/işlemi düşürür ve oku dışındaki her işleme **oku'yu otomatik ekler**.
    ⚠ **Matris NULL olan eski kullanıcılar rol bazlı ESKİ davranışla aynen çalışır** (geriye uyumluluk);
    admin her zaman sınırsız (matris kaydedilse de yok sayılır). Matrisi olan kullanıcıda:
    `yetki_matris()` (istek başına DB'den, `kullanici_yetki_satiri()` static), `yetki_var($islem, $mod=null)`
    (mod boşsa `aktif_modul()`), `yetki_yazma()` (giris∨duzenle∨onay), `modul_erisimi()` = matrisin anahtarları,
    tüm `can_*()` matrise bakar (can_edit=giris∨duzenle, can_view_reports=rapor, can_approve_*=onay,
    can_manage_definitions=duzenle, can_create_irsaliye=giris, can_edit_irsaliye: duzenle→her durumda /
    yalnız giris→beklemede) ve **`has_role()` bir YETKİ SEVİYESİ sorusuna dönüşür**: listedeki en zayıf klasik
    rolün ima ettiği işlem aranır (`admin,toa`→duzenle · `…,teknik_ofis`→duzenle∨giris · `…,saha_sefi`→
    giris∨duzenle∨onay · `…,depo`→giris∨duzenle · yalnız admin→false; **tek rol sorulursa gerçek rol**
    karşılaştırılır, ör. `has_role('depo')`). Böylece 100+ sayfadaki `require_auth([...])` / `has_role(...)`
    çağrıları değiştirilmeden matrisle çalışır. `require_auth()`: matrisli kullanıcıda rol listesi ATLANIR
    (yalnız `['admin']` sayfaları ve `kurulum*` her zaman rol bazlı), yerine **`sayfa_islemi()`** sayfanın
    gerektirdiği işlemi çıkarır: rapor sayfaları listesi/export → rapor; `import*`, `*_form` (id'siz),
    toplu_irsaliye/hizli_tarama/belge_dagit/fatura_eslestir/faturalar + api kaydetme uçları → giris;
    `*_form?id=`/`?edit=`/POST id>0 → duzenle; diğer sayfalarda **POST → yazma** (giris∨duzenle∨onay);
    geri kalan GET → oku. Yetkisiz: 403 sayfası "X modülünde 'değiştirme' yetkiniz yok" (API/JSON isteğinde
    JSON 403). `api/demir_*.php` demir modülü sayılır. Saha Takip: `$__waHome`/`ilk_modul_sayfasi` whatsapp
    modülünün kendi yazma yetkisine bakar; whatsapp sayfalarındaki eski rol kapıları matrisli kullanıcıda
    atlanır (require_auth zaten denetler). Sidebar Hızlı Tarama / Belge Oku `can_edit()` ile gizlenir.
    Test: scratchpad `yetki_test.php` (10 senaryo) + `yetki_case.php` (require_auth 23 sayfa kapısı) + `msm/runk2.php`.
  - Yetki fonksiyonları: `can_edit()`, `can_edit_irsaliye($row)` (durum bazlı), `can_approve_saha()`,
    `can_approve_teknik()`, `can_view_reports()`, `can_manage_definitions()` (admin+teknik_ofis_admin),
    `can_manage_users()` (admin), `has_role(...)`, `is_admin()`.
- **`mukerrer.php` + `includes/mukerrer.php` (mk_*)** — **MÜKERRER KAYIT MERKEZİ, TÜM MODÜLLER** (2026-09-09,
  admin, Araçlar → "Mükerrer Kayıtlar"). Aynı tedarikçi/firma/personel/araç iki kez açılınca raporlar bölünür,
  bakiyeler yanlış çıkar. **`MK_KURAL` kayıt defteri** modül => tablo => [anahtarlar, etiket, bağlı FK'ler,
  birleştirilebilir mi] tutar; beton (13 tablo) · demir (8) · seramik (3) · depo · akaryakıt · crm · prekast ·
  it (4) kapsanır. **Tespit** `mk_gruplar()`: her tablo kendi anahtarlarıyla (ör. tedarikçide *Ad* ve *VKN*)
  `mk_norm()` ile Türkçe harf duyarsız + noktalama atılarak normalize edilir ("SAFİ BETON A.Ş." = "Safi Beton AS")
  ve **birleşim-bul (union-find)** ile gruplanır — A ile B *addan*, B ile C *VKN'den* eşleşirse üçü TEK grup olur.
  Yalnız gereken kolonlar okunur (irsaliyeler gibi büyük tablolarda `SELECT *` belleği şişirirdi).
  **Birleştirme** `mk_birlestir()` tek transaction: bağlı hareketler korunan kayda TAŞINIR, hedefte **BOŞ olan**
  alanlar kaynaklardan tamamlanır (dolu alan asla ezilmez), kaynaklar silinir; bir adım patlarsa hiçbir şey
  değişmez, `audit_log`'a MERGE olarak yazılır. Olmayan bağlı tablo (kurulmamış modül) atlanır, gerçek hata
  işlemi durdurur. ASIL (korunacak) kayıt = en dolu kart (anahtar alanı dolu olan ağır basar, eşitlikte daha
  açıklayıcı etiket) — ekranda değiştirilebilir; seçim değişince o satırın birleştirme kutusu kapanır (kayıt hem
  hedef hem kaynak olamaz). **Hareket/belge tabloları** (irsaliye, sevkiyat, tutanak, fatura, arıza, iş satırı,
  depo stok kartı, kullanıcı) `birlestir=false` ile **yalnız raporlanır** — temizlikleri kendi ekranlarında
  (beton: `veri_kontrol.php`), depo kartları Excel tam yenilemesinden geldiği için düzeltme Excel tarafında.
  IT personelinde cihaz zimmetlerini de taşıyan `pim_personel_birlestir` devralır (`ozel` kancası).
  `mk_pdo()` modül bağlantısını istek başına önbellekler ve **sayfa zaten kurmuşsa onu kullanır**
  (config.php yoksa db.php login'e yönlendirip çıkar, buradan tetiklenmemeli). `MODUL_MUAF`'a eklendi.
- **`functions.php`** — `h()` (XSS), `flash()/get_flash()`, `format_date/number()`, `role_label()`,
  `redirect()`, `audit_log()`, `current_user_id()`.
- **`header.php`** — layout + **modül algılama** (`$__module` = PHP_SELF `/demir/` içeriyorsa 'demir').
  Topbar'da **Beton/Demir geçiş banner'ı**; modüle göre sidebar menüsü. **Oturum sayacı** (topbar,
  geri sayım, fetch/XHR'de sıfırlanır, 0'da logout).
  Demir sayfaları `$rootPath='../'` set etmeli (linkler ve login yönlendirmesi buna dayanır).
- **TEMA SİSTEMİ** (2026-09) — topbar'daki 🎨 düğmesinde **üç bağımsız ayar**, hepsi cihaza özel
  (`localStorage`, sunucuya yazılmaz): **görünüm** `ern_tema` = aydinlik|koyu|**sistem** (OS'i izler,
  OS teması değişince sayfa yenilenmeden uyar) · **renk teması** `ern_renk` = ern|mavi|antrasit|turuncu ·
  **yüksek kontrast** `ern_kontrast` = 0|1. HTML kökündeki `data-dark` / `data-renk` / `data-kontrast`
  özniteliklerine yazılır. ⚠ Üçü de **`header.php`'nin `<head>` içindeki boot script'inde, ilk boyamadan
  ÖNCE** uygulanır — sonradan uygulansa sayfa bir kare yanlış renkte görünüp "zıplar". Eski tek anahtar
  `beton_dark` **korunur** (ilk açılışta `ern_tema`ya göç eder, her değişiklikte senkron yazılır).
  `localStorage` erişimi try/catch'lidir (gizli sekme / depolama kapalı).
  - **CSS mimarisi** (`assets/css/style.css`): marka rengi artık **tek kaynaktan** gelir —
    `--ern` + **`--ern-rgb`** (gölge/tint hesapları `rgba(var(--ern-rgb),…)`) + `--ern-dark/darker/
    light/ultra/teal` + `--sidebar-1/2` (sidebar degrade durakları) + **`--ern-hc` / `--ern-hc-dark`**
    (yüksek kontrastta beyaz/siyah zemin üstünde AA geçen koyu/açık tonlar). Palet blokları
    (`html[data-renk="…"]`) yalnız bu değişkenleri ezer, **yerleşim/ölçü hiç değişmez**.
    `--bs-primary/-rgb/link-color` marka değişkenlerine bağlandı, böylece `text-primary`/`bg-primary`
    de paleti izler. Koyu temada bağlantı rengi `--ern-ultra`ya çekildi (koyu yeşil link siyah zeminde
    ≈2:1 okunuyordu), `--bt-tint/--bt-ring` de `color-mix` ile paleti izler (sabit teal turuncu temada yamalıydı).
  - **Yüksek kontrast** (`html[data-kontrast="1"]` + koyu için `[data-dark="1"]` bileşimi): saf beyaz/siyah
    zemin, soluk griler kalkar, **gölge yerine 2px net kenarlık**, bağlantılar **altı çizili** (rengi ayırt
    edemeyenler için ikinci ipucu), odak halkası 3px, tablo ızgarası görünür, `--bs-table-bg` yüzeye bağlı
    (Bootstrap varsayılanı şeffaf olduğundan koyu kontrastta hücreler beyaz kalıyordu), düğmelerde degrade
    yerine düz renk, animasyon/transform kapalı. Aksan HC'de `--ern-hc`/`--ern-hc-dark`e döner —
    aksi halde renkli aksan siyah/beyaz üstünde AA eşiğini geçmez.
  - **Chart.js** renkleri sabit hex yerine `--bt-text-muted`/`--bt-border-soft`'tan okunur; `data-dark`,
    `data-renk`, `data-kontrast` değişince MutationObserver açık grafikleri `update('none')` ile yeniler.
  - Playwright + WCAG oran ölçümüyle doğrulandı: yüksek kontrastta ölçülen tüm metin/zemin çiftleri
    **AAA** (7:1+), çoğu 12–21:1; normal temalarda AA.
- **`footer.php`** — footer, mobil bottom-nav, `ai_chat_widget.php`, app.js, service worker.
- **`ai_call.php`** — `ai_call($system,$parts,$maxTokens)` → Claude/Gemini/OpenRouter.
- **`config.example.php`** — sadece DB_HOST/NAME/USER/PASS şablonu. Gerçek `config.php` git-ignored.
  Ek sabitler: `SESSION_LIFETIME`, `DEMIR_DB_NAME`, `AI_PROVIDER`, `CLAUDE_API_KEY` vb.

---

## 4. Beton Modülü (kök dizin)

- **`index.php`** — Dashboard; admin girişinde günlük otomatik yedek (`backups/`, gzip, 30 gün). **Fatura & belge aksiyon kartları** (işlenen fatura/bağlı irsaliye/faturasız irsaliye/eksik sayısı → fatura_eslestir; bekleyen belge sayısı → belge_dagit; tablolar yoksa gizli; eksik varsa kart ?eksik=1 paneline gider). **Proje → Parsel → Blok → Kot hiyerarşi akordeonu** (dökülen m³, etkin proje `COALESCE(i.proje_id, par.proje_id)`; proje filtresine saygılı).
- **`irsaliyeler.php`** — Alış/İade/Tüm liste. Filtreler, toplu saha/teknik onay, **toplu güncelleme** (`toplu_islem=guncelle`: Proje→Parsel→Blok→Kot kademeli modal + Açıklama; yalnız doldurulan alanlar değişir, `can_edit()`), **whitelist sıralama**
  (sütun başlığına tıkla), CSV/XLSX export.
- **`irsaliye_form.php` / `irsaliye_detay.php`** — ekle/düzenle (durum bazlı yetki) / detay + **belge yükleme**: tür seçimli (Fotoğraf/Kantar Fişi/Fatura/İrsaliye/Diğer); kantar/fatura/irsaliye seçilirse **AI okur**, okunan alanlar belge kartında gösterilir (düşük güvende uyarı) ve "İrsaliyeye yaz" ile boş kantar alanlarına aktarılır. Bağlı fatura kartı (`irsaliyeler.fatura_id`) + "Aynı Faturadakiler" bağlantısı. Aynı dosya birden çok irsaliyeye bağlıysa diskten yalnız son bağ koptuğunda silinir.
- **`toplu_irsaliye.php`** — **toplu irsaliye girişi** (`can_edit()`): ortak bilgiler (tarih/tedarikçi/beton/pompa/kıvam/
  Proje→Parsel→Blok→Kot kademeli/imalat/firma/açıklama) bir kez seçilir, altta dinamik satırlarla İrsaliye No + m³ +
  plaka + kantar; Enter yeni satır açar, canlı satır/m³ özeti. Mükerrer fat_irs_norm ile atlanır; `[FATURADAN]` taslağına
  denk gelen satır taslağı GÜNCELLER (fatura bağı korunur). Durum: admin/teknik_ofis_admin → onaylandi, diğerleri beklemede.
  Menü: İrsaliyeler → Toplu Giriş.
- **`hizli_tarama.php`** (~2900 satır) — **QR+DataMatrix+OCR+AI tarama motoru** (§7). Toplu irsaliye tarama.
- **`raporlar.php`** — Chart.js + ExcelJS (formatlı xlsx) + jsPDF/AutoTable (PDF). `can_view_reports()`.
  **Proje bazlı özet** (U030/U031/U039): etkin proje = `COALESCE(i.proje_id, par.proje_id)` (parsel→proje bağı ile geçmiş kayıtları da kapsar); doughnut grafik + tablo + Excel "Tedarikçi & Beton" sayfasında PROJE bölümü.
- **`zayiat_takip.php`** — **Beton Zayiat Takibi** (`can_view_reports()`): Zayiat = Dökülen (alış−iade, reddedilen hariç) − Teorik Metraj. Teorik metraj **3 seviyede** girilir (kot/blok/imalat kalemi; modal, kademeli seçim), satır bazında **limit %** (varsayılan 5, fore kazık 15 — kalem adında FORE geçerse otomatik önerilir). Sekmeli görünüm + KPI + teorik-vs-dökülen bar grafik + satıra tıklayınca irsaliye popup (irsaliyeye geçişli). **Proje bazında zayiat özeti** (tabloların üstünde): parsel→proje bağıyla tanımlı metraj satırları projeye toplanır (teorik/dökülen/zayiat/oran/limit aşımı) + proje bazlı teorik-vs-dökülen grafik. Tablo `beton_metraj` (runtime + kurulum). Durum: LİMİT AŞIMI (kırmızı) / Yaklaşıyor (>%80 limit) / Devam ediyor (dökülen<teorik) / Normal. |
- **`belge_dagit.php`** (`admin`/`teknik_ofis_admin`/`teknik_ofis`/`saha_sefi`, "Belge Oku & Dağıt" menüsü) — **toplu belge okuma + otomatik irsaliye bağlama**: kantar fişi/fatura/irsaliye görselleri çoklu yüklenir (100+ dosya desteklenir: >12 dosyalık seçim tarayıcıda **6'şarlı partilere** bölünüp AJAX ile sırayla gönderilir — PHP `max_file_uploads`≈20 sınırı ve zaman aşımı böyle aşılır; sonuçlar `$_SESSION[blg_sonuclar]`'da birikir, `?rapor=1` tek rapor gösterir) → `includes/belge.php` `blg_ai_oku()` ile AI okur (fiş no, irsaliye no, plaka, tarih, tartımlar, net kg, saatler) → `blg_irsaliye_bul()` **irsaliye no (normalize) → plaka+tarih → plaka** sırasıyla ilgili irsaliyeyi bulur → belge o irsaliyenin klasörüne taşınıp `irsaliye_fotolar`'a `tur='kantar'` olarak eklenir. Kantar değerleri **yalnız boş alanlara** yazılır (`blg_kantar_uygula`, elle girilen veri ezilmez; kantar_farki yeniden hesaplanır). **Mükerrer önleme** `blg_mukerrer()`: (1) belge kimliği — fiş no/irsaliye no/fatura no, biçim farkı normalize edilir, (2) dosya içeriği md5 (aynı baytlar aynı belgedir; önce boyut karşılaştırılır), (3) kimlik taşıyan türlerde dosya adı — fotoğraflarda ad karşılaştırması YAPILMAZ (telefonlar farklı fotoğraflara IMG_0001.jpg der). **Toplu Belge Kontrolü** (`belge_kontrol`): 100+ belge seçilir, AI HARCAMADAN dosya içeriği (boyut→md5) kayıtlı belgelerle karşılaştırılır → yeşil 'zaten ekli' (bağlı irsaliye linkiyle) / kırmızı 'sistemde yok' raporu (15'erli parti, `?kontrol=1`); raporun altındaki **'OKU ve DAĞIT'** butonu AI'ya yalnız eksikleri gönderir (`belge_isle`, 3'erli parti → `?rapor=1`). Ortak işleme çekirdeği `bd_isle()`. Eşleşmeyen belge kaybolmaz: `uploads/belge_bekleyen/` altında **süresiz** bekler (yanında `.json` not dosyası: ad/tür/okunan), sayfadaki **Bekleyen Belgeler** listesinden aday irsaliyeye bağlanır / AI ile yeniden okutulur / elle silinir. Dashboard fatura kartındaki eksik sayısı `fatura_eslestir.php?eksik=1`e götürür — `faturalar.eksik_liste` (JSON, runtime kolon) eksik irsaliye NUMARALARINI saklar ve panelde rozet olarak listelenir.
- **`fatura_eslestir.php`** (`can_view_reports()`, "Fatura Eşleştirme" menüsü) — **Fatura ↔ İrsaliye mutabakatı**: tedarikçi e-Faturası (PDF/JPG) yüklenir veya metni yapıştırılır → `includes/fatura.php` fatura no/tarih/ETTN/brüt+ödenecek tutar/m³ ve **irsaliye listesini** çıkarır, numaraları **normalize edip** (`ANM2026-4710` ↔ `ANM2026000004710` — düz karşılaştırma HİÇ eşleşme bulmaz) sistemdeki irsaliyelerle eşleştirir. Eşleşen/eksik listesi + m³ farkı KPI'ı. **Mükerrer önleme**: `fat_mevcut()` faturayı **no VEYA ETTN** ile arar (ETTN faturanın değişmez kimliği — no farklı yazılsa da yakalar) ve "zaten işlenmiş" uyarısında **bağlı irsaliye listesini** tablo olarak gösterir (`fat_bagli_irsaliyeler`; her satır 'şimdiki faturada ✓ var / ✗ yok — bağ kopacak' işaretli, m³ toplamı ile) + "<n> yeni bağ / <n> zaten var" ya da hiç değişiklik yoksa "tekrar kaydetmene gerek yok"; kaydetme UPDATE olur, yeni kayıt açılmaz. **Toplu Fatura Kontrolü**: 100 fatura dosyası seçilir (KAYDEDİLMEZ), numara dosya adından → PDF içeriğinden çıkarılıp kayıtlı faturalarla no/ETTN normalize karşılaştırılır; yeşil işlenmiş / kırmızı işlenmemiş raporu + kopyalanabilir işlenmemiş listesi (15'erli parti AJAX, sonuç `$_SESSION[fat_kontrol]` → `?kontrol=1`). İşlenmemiş dosyalar `uploads/fatura_kontrol_bekleyen/`e alınır ve rapordaki **"ÇÖZÜMLE ve KAYDET"** butonu tam otomatik işler (`action=fatura_isle`, 2'şerli parti: metin çıkar → tedarikçi VKN/unvandan bul-yoksa oluştur → normalize eşleştir → fat_kaydet + belge ekleme; TASLAK AÇMAZ, eksikler raporlanır; sonuç `?islem=1` işlem raporu, bekleyenler 2 günde temizlenir). Kayıtlı Faturalar listesinde **fatura arama** (`fatura_ara`: no/ETTN/tedarikçi/bağlı+eksik irsaliye no — normalize + rakam-parçası eşleşmeli, aramada LIMIT kalkar) ve bağlı sayısına tıklayınca irsaliyeler açılır (fatura m³ ile karşılaştırmalı); `fat_baskaya_bagli()` **başka bir faturaya bağlı irsaliyeleri** kırmızı satır + rozetle gösterir (çift faturalandırma uyarısı, onay diyaloğu). Onayda `faturalar` kaydı + `irsaliyeler.fatura_id` bağı (`fatura_no` taramada ETTN ile dolabildiğinden **yalnız boşsa** doldurulur). **Eksik irsaliyeden taslak oluşturma** (isteğe bağlı kutu): sistemde bulunamayan numaralar fatura tarihi+tedarikçisiyle, 0 m³ ve `[FATURADAN]` açıklama etiketiyle taslak irsaliye olarak açılır, faturaya bağlanır, fatura dosyası belge olarak eklenir (tedarikçi seçili olmalı — `tedarikci_id NOT NULL`). **Taslak onay koruması** (`irs_taslak_mi()` functions.php): `[FATURADAN]` etiketli irsaliyeler toplu onayda atlanır, formdan onayda engellenir (etiket silinerek elle tamamlanabilir); listede/detayda sarı TASLAK rozeti, fatura listesinde Eksik sütununda "N taslak" rozeti. **Karekod (GİB e-Fatura QR)**: faturanın altındaki karekod tarayıcıda okunur (jsQR + pdf.js, `BarcodeDetector` varsa o) ve `fat_qr_coz()` ile çözülür — fatura no/tarih/ETTN/**satıcı VKN**/ödenecek tutar buradan **birebir** gelir, metinle çelişirse karekod esas alınır ve fark uyarı olarak listelenir (`fat_qr_birlestir`). VKN ile tedarikçi otomatik seçilir (`fat_tedarikci_bul`); karekod yoksa **metinden satıcı VKN+unvan** çıkarılır (`satici_vkn/satici_unvan` — SAYIN bloğundaki VKN alıcınındır, diğeri satıcının) ve normalize unvanla da eşleşir (`fat_tedarikci_bul_ad`/`fat_unvan_norm`: "SAFİ BETON ÜRETİM VE TİCARET A.Ş." ↔ "SAFİ BETON"). Hiç eşleşmezse **"tedarikçiyi otomatik oluştur" kutusu** (varsayılan işaretli): kaydetmede tedarikçi unvan+VKN ile açılır, VKN'siz mevcut kayda VKN tamamlanır. ⚠ Karekottaki `vergidahil` tevkifat **düşülmüş** tutardır (kağıttaki brütten farklı), bu yüzden brüt tutar karekottan değil metinden alınır. Karekod içeriği metin kutusuna yapıştırılırsa da otomatik tanınır. PDF okuma sırası: `pdftotext -layout` (varsa) → **AI belge okuma** (`ai_call`, Claude/Gemini; OpenRouter belge desteklemez) → metin yapıştırma. Dosya `uploads/faturalar/Y/m/`. Tablo `faturalar` (runtime `fat_semasi_kur` + kurulum). `irsaliyeler.php?fatura_id=` ile faturanın irsaliyeleri listelenir.
- **`aktivite.php`** (admin, Araçlar menüsü) — **Kullanıcı Aktivite Raporu**, 2 sekme: **Özet** (kullanıcı başına oturum sayısı/toplam süre/ort. oturum/sayfa görüntüleme/son görülme/en çok modül + KPI + retention temizlik) ve **Detay** (kullanıcı+tarih+tür filtreli zaman çizelgesi: sayfa gezinme `kullanici_aktivite` + kayıt değişiklikleri `audit_log` UNION). Her iki sekme **Excel'e aktarılır** (`XlsxWriter`, filtrelere saygılı; süreler dk). İzleme `header.php`→`aktivite_izle()` (functions.php): her render'da `kullanici_oturum` upsert (giriş/son_aktivite/sayfa_sayisi) + `kullanici_aktivite` insert **yalnız sayfa/modül değişince** (yenileme şişirmez); ana DB bağlantısı istek başına **static önbellekli** (`aktivite_pdo`); **olasılıksal otomatik retention** (`aktivite_temizle`, ~%0.25 istek, `AKTIVITE_SAKLAMA_GUN` varsayılan 90). Tablo yoksa runtime oluşur (+ kurulum.php).
- **`veri_kontrol.php`** (admin+teknik_ofis_admin, Araçlar menüsü) — **Veri Kontrol & Mutabakat**: DB özetleri, **mükerrer irsaliye grupları** (UPPER/TRIM normalize) + tümünü/grup bazlı temizlik (en eski kayıt korunur, audit_log), no'suz şüpheli tekrarlar (yalnız liste), **Excel mutabakatı** (toplam farkı + DB'de fazla / Excel'de eksik no listeleri). Dashboard toplamları `durum<>'reddedildi'` filtreli.
- **`import.php` (beton)** — **tüm sayfalar otomatik taranır** (sayfa seçimi yok): başlığı algılanan sayfalar veri sayılır, adında İADE geçen sayfa `tip='iade'`; VERİ/KOT/Kaşe atlanır. Mükerrer kontrolü **fat_irs_norm ile normalize** (SKB2026-12047 ↔ SKB2026000012047 aynı belge). `[FATURADAN]` etiketli taslaklar (fatura_eslestir'den açılmış) mükerrer sayılıp atlanmaz — Excel satırı taslağı **UPDATE eder** (id sabit → fatura bağı + ekler korunur; Excel fatura no boşsa taslağınki ezilmez). Admin için **"tam yenileme"** kutusu: önce TÜM irsaliyeleri siler (transaction + audit_log), sonra aktarır — Excel ile birebir eşitler. **Bağlı ek koruma (tam yenilemede)**: silmeden önce `irsaliye_fotolar` (tur/okunan dahil) ve `irsaliyeler.fatura_id` `irsaliye_no` ile snapshot alınır, import sonrası aynı **normalize** no'lu yeni kayda yeniden bağlanır (dosyalar diskte kalır; eşleşmeyen sayılar raporlanır).
- **`kotlar.php`** — **blok → kot akordeon görünümü** (her blok bir akordeon başlığı: parsel + kot adedi + toplam dökülen m³/irsaliye; açınca o bloğun kot listesi sıra no ile). Kot **detay/kat etiketi** (`kotlar.aciklama`, runtime ALTER + kurulum) + **VERİ sekmesinden kat etiketi doldurma** (kot→KAT haritası; yalnız boş detaylar) + **KOT sekmesinden blok+kot içe aktarma** (`action=kot_yukle`: her sütun=blok, altındaki değerler=kot; hedef parsel seç/yeni ad; blok+kot get-or-create UPPER-normalize, idempotent, sıra Excel'den) + kot başına **döküm özeti** (m³/irsaliye/imalat kalemleri), m³'e tıklayınca o kottaki irsaliyeler popup — "hangi blok hangi kot ne yapılmış".
- **`login.php`** — **Şantiye İş Takip Sistemi / Batı Yakası Projesi** markası; iki beyaz ERN logolu koyu yeşil panel + Batı Yakası proje rozeti (dış SVG, onerror fallback) + **modül tanıtım kartları `modul_listesi(true)`'den DİNAMİK** (9 modül; **9+ kartta 3 sütun** `.feature-pills.cok`, 760px altında yetki notu gizlenir; yöneticinin verdiği ad/sıra login'e yansır, GİZLİ modüller de listelenir — tanıtım amaçlı; sonda tam satır "Mobil Uygulama — Pek Yakında" kartı; kısa ad `$__pillAd`, açıklama `$__pillAcik` — yeni modül eklenince iki diziye satır ekle; 820px'ten kısa ekranda kartlar/logo sıkışır, 1280×700'de bile kırpılmaz) + altta "kullanıcı bazlı yetki: okuma·veri girişi·değiştirme·onay·rapor" notu + dalga animasyonu + geliştirici kredisi + **"Sistemi Tanıyın" → tanitim.php** bağlantısı.
- **`tanitim.php`** — **halka açık tanıtım/show sayfası** (auth YOK): login ile aynı marka dili (--ern yeşil paleti, Outfit, dalga SVG). Hero'da ERN Holding + ERN Taahhüt beyaz logoları, Batı Yakası rozeti, CTA→login.php. **Canlı yuvarlanmış sayaçlar** (irsaliye/m³/demir ton/belge/modül; DB'ler try/catch korumalı — `config.php` yoksa `file_exists` guard'ı ile atlanır çünkü db.php redirect+exit yapar, try yakalayamaz; sayılar 100'e yuvarlanır "1.200+" hissi için). 6 modül kartı + 8 özellik kartı (QR/AI/fatura mutabakatı/zayiat/evrak arşivi/rapor/PWA) + 3 adımlı akış + IntersectionObserver count-up animasyonu + çift logolu footer.
  QR/AI beton = GİB e-İrsaliye QR (JSON) + KGS/THBB DataMatrix (E1) + tesseract + AI.
- **`site-kok/index.html`** — tanitim.php'nin **bağımsız statik kopyası**, `ernsaha.com.tr` KÖK dizini için
  (kullanıcı aaPanel'den elle yükler; deploy2 kapsamı dışında). PHP/DB yok: sayaçlar sabit, logo/giriş
  bağlantıları `/beton/...` mutlak yollu. tanitim.php güncellenince kopya scratchpad `site_kok_uret.php` ile
  üretilir (PHP blokları sabit sayaç/yol/yıl-JS ile değiştirilir; kalan `<?` varsa hata verir) — elle eşitleme yapma.
  2026-09-08: tanitim + kök sayfa 8 modül (CRM, Prekast eklendi; demir ikonu rulers), 2026-09-09: 9. modül IT Envanter (sayaç 9, "Dokuz Modül") + dashed "Mobil Uygulama — Pek
  Yakında" kartı (`.modul.yakinda`, altın rozet), sayaç 8, Kurumsal Güvence metni yetki matrisi + 6 DB.
- **Tanım sayfaları** (`can_manage_definitions()`): beton_siniflari, katki_listesi, pompa_turleri,
  kivam_siniflari, parseller→bloklar→kotlar, imalat_gruplari→ana_is_kalemleri, firmalar, tedarikciler.
  **Parsel→Proje bağı**: `parseller.proje_id` (runtime ALTER + kurulum) — parsele proje (U030/U031…) atanır;
  `parseller.php`'de dropdown, `irsaliye_form.php`'de parsel seçilince Proje alanı JS ile otomatik dolar
  (option `data-proje`). Excel'deki A_PARSEL→U030, D_PARSEL_1/2→U031 ilişkisi böyle kalıcılaşır.
  Desen: CRUD + mükerrer engel (UPPER) + kullanımda ise silme engeli + `tanim_modal.php`.
- **`prp_ustyapi.php`** (`can_view_reports()`, "Bina Üstyapı" menüsü — taşeron **PRP İnşaat**) — **Bina Üstyapı zayiat tablosu, blok seçmeli, Excel görünümünde**. `metraj_sayfa`'daki "PRP BİNA ÜSTYAPI" grid'ini okur; blok kolon haritası (A_2@9, B_4@18, C_1@28, C_2@37, D_3@46 → KOT/İMALAT col6/7; E_BLOK@57 → col55/56; base+0..7 = metraj/ilerleme/sahada/projeye/zayiat oranı/sözleşme B(%5)/sözleşmeli miktar/fiili). KOT birleşik (rowspan), KOLON-PERDE ayrı satır (mavi metraj), DÖŞEME grubu (döşeme+dolgu+merdiven+parapet) tek metrajla birleşik — görselle birebir. İLERLEME/sözleşme %'li gösterim, #N/A boş. **CANLI zayiat**: SAHADA DÖKÜLEN gerçek irsaliyelerden (`irsaliyeler`⋈`bloklar`/`kotlar`/`imalat_gruplari.ad`; KOLON-PERDE kendi, DÖŞEME satırı döşeme+dolgu+merdiven+parapet+kiriş grubu) blok+kot(float norm)+imalat eşleşmesiyle toplanır; ZAİYAT ORANI=(Sahada−A)/A, A=metraj×ilerleme; %5 aşımda satır kırmızı (`prp-asim`) + Fiili Zayiat=Sahada−A×1.05; KPI'da Sahada Dökülen + Limit Aşımı adedi.
- **`istinat.php`** (`can_view_reports()`, "İstinat Duvarları" menüsü) — 2 alt sekme: (1) **İstinat — Dener İnşaat** (İSTİNAT DENER; parsel/tip bazlı kart yapısı + **CANLI zayiat**: sahada dökülen `zy_hesap` ile parsel+imalat eşleşmesinden — ISTINAT_CEVRE_DUVARI→İSTİNAT DUVARI+ÇEVRE DUVARI imalatları, GROBETON→istinat blok filtreli; limit %4; ham grid `renderBolumluSayfa()` yalnız yapısal parse boşsa), (2) **İstinat Duvarı (Metraj)** (İSTİNAT DUVAR: parsel/duvar no/beton/alan/yükseklik/metraj/ilerleme/yapılan temiz liste + toplam metraj/yapılan/kalan KPI). `metraj_sayfa`'dan okur.
- **`temel_kazik.php`** (`can_view_reports()`, "Temel & Kazık" menüsü) — **Temel & Kazık imalatları**, 4 alt sekme: (1) **Temel Beton — PRP İnşaat** (PRP TEMEL sayfası, blok bazlı kart: TEMEL/GROBETON proje metrajı/ilerleme/sözleşme %5; her blokta yalnız ilk TEMEL+GROBETON, GENEL TOPLAM hariç), (2) **Kazık Listesi** (KAZIK sayfası: pafta/parsel/duvar/açıklama/boy/adet/çap/toplam beton/yapılan-kalan; toplam beton KPI), (3) **İksa Kazık** + (4) **Temel Altı Kazık** (`renderKazikSheet()`: bölüm başlıklı — parsel/blok — temiz tablo; Excel tarih-formatlı hücreler `exSerial()` ile sayıya çevrilir; İLERLEME/ORANI/SÖZLEŞME sütunları % gösterilir). `metraj_sayfa`'dan okur.
- **`metraj_sayfasi.php`** (`can_view_reports()`, "Metraj" menüsü) — Excel "METRAJ" sayfası: blok bazlı (renk başlık) Blok/Bölüm/Kot/Döşeme/Kolon-Perde/Temel/Genel Toplam tablosu + Genel Toplam KPI. `metraj_sayfa`'dan okur.
- **`mobilizasyon.php`** (`can_view_reports()`, "Mobilizasyon" menüsü) — Excel "MOBİLİZASYON" sayfası: firma/iş bölümlü tablo (Osman Camcı, Yıldızlar, PRP İnşaat…), sahada dökülen + zayiat; tarih-serial çevirme. `metraj_sayfa`'dan okur.
- **Not:** Eski genel "İmalat Sayfaları" (`metraj_takip.php`) **kaldırıldı**; her Excel imalat sayfası artık kendi menüsünde (Bina Üstyapı, Temel & Kazık, İstinat Duvarları, Beton İcmali, Metraj, Mobilizasyon). İçe aktarma `import.php` (Dinamik Excel Aktarımı) → `storeImalatSheets()` → `metraj_sayfa`.
- **`icmal_beton.php`** (`can_view_reports()`, "Beton İcmali" menüsü) — Excel "İCMAL" sayfasını iki anlaşılır bölümde: **Beton İcmali özeti** (col4/col5: kalem→miktar, TOPLAM/KALAN vurgulu, negatif kırmızı) + **Tamamlanan İmalatlar** (firma bazlı, col11/12/14/15: firma[rowspan]/imalat/güncel metraj/ilerleme% progress bar). `metraj_sayfa`'daki İCMAL grid'inden okur.
- **`metraj_sayfa` tablosu** (runtime, `import.php` `storeImalatSheets()` ve `metraj_takip.php` CREATE): `ad` UNIQUE, `veri` LONGTEXT (JSON grid). Excel imalat sayfaları Sayfa1/VERİ/KOT hariç buraya kaydedilir; `prp_ustyapi.php`, `icmal_beton.php`, `metraj_takip.php` buradan okur.
- **`whatsapp/` modülü = AYRI MODÜL** (topbar sekmesi **"Saha Takip"**, `$__module='whatsapp'`) — saha WhatsApp grubundan **araç giriş/çıkış** ve **evrak/görsel** takibi. ⚠️ Bu modül **beton irsaliyesi OLUŞTURMAZ**; irsaliye işlemleri Beton modülünde kalır.
  - **`whatsapp/mesajlar.php`** (`can_edit()`) — onay kuyruğu: mesaj AI ile çözümlenir, çıkan araç hareketleri + evraklar gösterilir, onay/ret verilir. Ret'te o mesajın araç hareketleri silinir.
  - **`whatsapp/arac_takip.php`** (`can_view_reports()`) — **kaç araç girdi, ne kadar kaldı**. Süre 3 kaynaktan: `sure_saat` → aynı kayıtta `saat_bas`+`saat_bit` → `arac_giris`/`arac_cikis` eşleştirme (aynı gün+plaka, gece yarısını aşan geçişler dahil). Eşleşmeyenler "açık" rozetiyle ayrı sayılır.
  - **`whatsapp/evraklar.php`** — paylaşılan belgeler **türüne göre gruplu** (irsaliye/tutanak/fatura/puantaj/ruhsat/foto), görseliyle ve **onayı veren kişiyle**. Tek tek onay/ret.
  - **`whatsapp/saha_analiz.php`** (`can_view_reports()`) — genel saha hareketi (personel giriş/çıkış, yetkilendirme) + zaman çizelgesi.
  - Giriş uçları: **`whatsapp/api/mesaj_al.php`** (genel + Meta webhook) ve **`whatsapp/api/telegram_al.php`** (**Telegram bot köprüsü** — resmî Bot API, risksiz; WhatsApp'tan 'Paylaş→Telegram→bot' ile ya da botun üye olduğu Telegram grubundan otomatik beslenir; `TELEGRAM_BOT_TOKEN` + setWebhook secret_token=`MESAJ_TOKEN`; fotoğraf `telegram_medya_indir()` ile iner, albümler `media_group_id`→`mesaj_medya_ekle()` ile tek mesajda birleşir; grup için BotFather /setprivacy Disable). İkisi de `MESAJ_TOKEN` korumalı, `/api/` yolunda (CSRF muaf). Ortak katman **`whatsapp/_ortak.php`**.
  - Tablolar: `mesaj_kuyrugu` · `saha_olaylari` (tur: arac_giris/arac_cikis/arac/personel_*/yetki/is/diger + `arac_cinsi`) · `saha_evrak` (tur/belge_no/dosya_url/**onaylayan**/onay_user/onay_at/durum). Plaka `saha_plaka_norm()` ile normalize ("34 abc 123"→"34ABC123").
  - **Resmî puantaj/İSG/giriş-çıkış kaydı değildir** (her sayfada uyarı bandı).
  - Raporlar (araç/analiz) varsayılan **yalnız onaylanmış** mesajları sayar ("Bekleyenler dahil" anahtarı var).
  - **Retention**: yalnız REDDEDİLEN mesajlar `MESAJ_SAKLAMA_GUN` (90) sonra görselleriyle silinir
    (`mesaj_temizle`, mesajlar.php'de olasılıksal tetik). Onaylılar arşivdir, silinmez.
  - Meta bağlantısı kurulursa: `WHATSAPP_GRAPH_TOKEN` tanımlanınca gelen fotoğraflar otomatik iner
    (`meta_medya_indir`, media id → uploads/whatsapp/Y/m/).
- **`kullanicilar.php`** (YALNIZ admin; sidebar bağlantısı `can_manage_users()`) — kullanıcı CRUD +
  **Yetki Matrisi**: modal'da satır = modül (gizliler rozetle), sütun = Okuma/Veri Girişi/Değiştirme/Onay/Rapor
  kutucukları + satır "Tümü" + sütun başlığı toplu seçim; JS tutarlılık (oku dışı işlem → oku açılır, oku
  kapanınca satır boşalır); **"Rol şablonunu uygula"** (`yetki_sablon`, yeni kullanıcıda rol değişince
  otomatik dolar) / Hepsi / Temizle; Rol seçiminde açıklama; **Görev/Unvan** serbest alanı. Admin rolünde
  matris kilitli ("her şeye yetkili"). Kayıt: `y[modül][]` → `yetki_normalize` → JSON `users.yetkiler`;
  `modul_erisim` matrisle senkron (tüm modüller ise NULL). Güvenlik: en az bir modülde okuma zorunlu,
  kendi hesabının admin rolü kaldırılamaz / pasife alınamaz / silinemez; değişiklikler `audit_log`.
  **Aktif / pasif yönetimi (2026-09-09)**: liste üstünde **Hepsi · Aktif · Pasif** süzgeci (sayaçlar her zaman TÜM
  kullanıcılardan, süzgeç yalnız görünümü daraltır) + kullanıcı adı / ad soyad / görev / rol üzerinde **arama**
  (`?durum=`, `?q=`); satırda tek tıkla **aktif↔pasif** düğmesi (`action=durum`, onay diyaloğu, `audit_log`'a yazılır,
  süzgeç korunarak geri dönülür), pasif satır soluk gösterilir. Kendi hesabını pasife alma hem düğmede hem POST'ta
  engellidir. Pasif kullanıcı **giriş yapamaz** (login.php "Hesabınız devre dışı bırakılmış" der) ama kaydı,
  yetkileri ve geçmişi silinmez — modal'daki "Hesap aktif" anahtarı da bunu açıklar.
  Listede kullanıcı başına modül rozeti + O·G·D·N·R harfleri (yetkisiz harf üstü çizili), unvan, "siz" rozeti;
  matrisi olmayan kullanıcı **"Eski rol düzeni"** rozetiyle işaretlenir (üstte sayısı verilir) — düzenleme
  modalında matris rol şablonu + eski modül listesinden ÖNERİLİR, kaydedince devreye girer.
  ⚠ Modal'da başlık/gövde/alt bilgi `<form>` içinde olduğundan Bootstrap `modal-dialog-scrollable` İŞLEMEZ (flex
  zinciri form'da kopar, Kaydet düğmesi ekran dışına taşıyordu) — sayfa CSS'i flex sütununu FORM'a uygular
  (`.modal-content > form`), gövde kendi içinde kayar, matris başlığı sticky; küçük ekranda `modal-fullscreen-md-down`.
- **`moduller.php`** (admin, Araçlar → "Modüller (ad / gizle)") — **Modül Yönetimi**: her modülün
  **görünen adı** değiştirilir (boş = varsayılan), **gizlenir** (menülerde hiç görünmez, adresi elle
  yazılsa da 403 — yalnız admin girebilir; veri SİLİNMEZ) ve **sırası** verilir (küçük önce, 0 = doğal).
  Ayarlar `modul_ayarlar` tablosunda tutulur ve tüm sistemde geçerlidir (üst şerit, sidebar, yetki
  ekranı, 403). Satırda modülün kaç kullanıcıya özel izinli olduğu gösterilir. Güvenlik: **tüm modüller
  gizlenemez** (en az biri açık kalmalı) + tek tıkla **Varsayılana Dön** (özel adlar silinir, gizliler
  açılır; `users.modul_erisim` izinleri etkilenmez). Değişiklikler `audit_log`'a yazılır.
- Diğer: `ai_ayarlar.php`, `yedek.php`, `import.php`, `kurulum.php` (seed).

---

## 5. Demir Modülü (`demir/`)

Sidebar: Dashboard · Sevkiyatlar · Siparişler · **Sipariş Talepleri** · **Talep Mutabakatı** · **Fatura Takibi** · Tutanaklar · **Tutanak Takip** · **İade Tutanakları** ·
**Taşeron Bakiye** · **Sözleşmeler** · İcmal · Raporlar · Proje Dışı İşler · Tanımlar(Projeler/Çaplar/Tedarikçiler/Taşeronlar).

| Dosya | Amaç |
|---|---|
| `index.php` | **Genel Bakış dashboard**: KPI kartları (gelen/kantar farkı/sevkiyat/kalan sipariş/tutanak/iade/taşeron net) + **IFS talep şeridi** (talep adedi/sipariş/teslim/kalan + mutabakat uyumsuz çap rozeti; talep tablosu boşsa gizli) + çap(bar)/proje(doughnut)/aylık(line) grafikleri + son sevkiyatlar + **firma bazlı teslim matrisi** (firma seçici, Proje × Çap, `_firma_teslim.php`) + **sözleşme bazlı çap dağılımı** (sözleşme seçici; çap başına Sipariş/Teslim/Kalan, sozlesme_id bağından). Opsiyonel tablolar try/catch. |
| `_firma_teslim.php` | Ortak: `firma_teslim_matrisi()` — uygulama tutanakları + Tutanak Takip defteri birleşik (tutanak_no dedup, iade netten düşer) → firma→proje→çap matrisi; `ftm_tablo_html()` render. |
| `sozlesmeler.php` | **Sözleşme No paneli**: taşeron sözleşmeleri CRUD (no+taşeron+proje+konu, mükerrer engel) + **ıslak imzalı sözleşme dosyası** (PDF/DOCX/DOC/görsel, sürükle-bırak, tıkla-aç; dosya `uploads/demir_sozlesme/{id}/`, DB'de yalnız URL) + sözleşme bazında teslim toplamı, **bağlı tutanak listesi (imzalı evrak linkleriyle)** ve çap kırılımı. `demir_tutanaklar.sozlesme_id` bağı; `tutanak_form.php`'de Sözleşme No **zorunlu**; tutanak listesi/detay/PDF'te gösterilir. |
| `sevkiyatlar.php` | Sevkiyat listesi; çap toplamı + **kantar farkı** (renkli); filtre; Excel dışa/içe aktar. |
| `sevkiyat_form.php` | Çap bazında İrsaliye+Kantar (canlı fark). **Karekod+AI paneli** (QR→başlık, AI→çap/miktar). |
| `siparisler.php` | Sipariş + **bakiye** (sipariş/gelen/kalan + % ilerleme). |
| `siparis_form.php` | IFS Sipariş No **zorunlu + mükerrer engelli** (eşleşme buna dayanır; benzersiz olmalı yoksa bakiye çift sayar) + **Sözleşme No zorunlu** (`demir_siparisler.sozlesme_id`) + çap bazında sipariş miktarı. `siparisler.php` mevcut mükerrer IFS no'ları uyarı bandında gösterir; `kurulum_demir.php` güvenliyse `ifs_siparis_no` UNIQUE + sevkiyatta düz indeks ekler. |
| `siparis_detay.php` | Çap bazında bakiye + eşleşen sevkiyatlar. |
| `talepler.php` | **IFS Sipariş Talepleri** ("Demir Siparişleri Takip Tablosu" Excel'i): her sayfa bir talep (Talep No; birleşik "111779-112123" desteklenir, çift Miktar kolonu varsa "Toplam" okunur). Çap **Malzeme Açıklamasından** çıkar ("Nervürlü 26 Mm"→Ø26, "Q188/188"→hasır) ve `demir_caplar`'a bağlanır; firma KALEM düzeyinde (bir talepte PRP+OSMAN CAMCI olabilir). Tarih sayfa adından. İçe aktarma tam yenileme; ayrıca **Excel özet satırı sağlaması** (sayfadaki Sipariş/Teslim alınan/Fark hücreleri `excel_*_kg` kolonlarına okunur; kalem toplamıyla uyuşmazsa listede sarı "özet farklı" rozeti — birleşik talep 111779-112123 böyle yakalandı, Excel formülü bayattı), **Kalan kolonu** (`kalan_kg`) ve **Parsel sonrası serbest not hücreleri** (`notlar` — "25460 OSMAN CAMCI VERİLDİ" gibi devirler; kalem açılımında rozet, talep satırında 📝). Liste + çap özet matrisi + firma/proje filtre + collapse kalem kırılımı. ⚠ Talep No (110307…) ≠ IFS Sipariş No (706589…) — sipariş bakiyesine karışmaz, Excel birebir yansır. Tablolar `demir_talepler`+`demir_talep_kalemleri` (runtime + kurulum). **İçe aktarma çekirdeği `demir/_talep_import.php`** (`tlp_import` transaction'lı tam yenileme, `tlp_dosya_turu` sayfa adı/başlıktan dosya türü: talep/siparis/sevkiyat, `tlp_dosya_hedef`). **Dosya türü otomatik yönlendirme**: `import.php` (İNŞAAT DEMİRİ TAKİP) ve `import_siparis.php` (Sipariş Takip) beklenen sayfayı bulamazsa dosyayı tanır — talep dosyasıysa doğrudan talepler'e aktarıp yönlendirir, başka türse doğru ekrana uyarıyla yönlendirir; talepler.php de yanlış dosyada doğru ekranın linkini verir ("'Sipariş Takip' sayfası bulunamadı" hatası böyle çözüldü). Dosya 2026-08'den itibaren 15 sayfa (yeni talep 115001, sayfa adı "PRP-24.08.2026 D U031"). |
| `mutabakat.php` | **Talep ↔ Saha Mutabakatı**: IFS taleplerindeki "Teslim Alınan" (kg) ile sevkiyatların irsaliye/kantar miktarı (ton) **çap bazında** karşılaştırılır; tolerans 0,5 t veya %1. Rozetler: Uyumlu / Saha eksik (talep fazla, kırmızı) / Saha fazla (mavi — talep dosyası 110307 öncesini kapsamadığından beklenen durum) / Yalnız talepte-sahada. Proje filtresi (talep tarafı LIKE, saha tarafı proje_id). **Tarih filtresi bilerek yok** (talep tarihi=sipariş günü ≠ sevkiyat tarihi=geliş günü). |
| `faturalar.php` | **Demir Fatura Takibi**: demir e-faturası (İDİS, ör. Çakıroğlu) yüklenir/yapıştırılır → irsaliye no **BAŞLIKTAN** okunur ("İrsaliye No: CKI2026...", gövdedeki rulo kodları CA02177... bilerek TARANMAZ), miktar kg→ton, çap malzeme açıklamasından (10MM NERV→Ø10; Q<100 kangal, Q≥100 hasır; iki satıra bölünen unvan birleştirilir). İrsaliye no normalize eşleşmesiyle `demir_sevkiyatlar` bulunur, fatura tonajı kantar/irsaliye tonuyla karşılaştırılır (fark rozeti). Mükerrer: no VEYA ETTN → UPDATE. **Kalemler** (çap+kg+irsaliye eşlemesi) 3 düzenden çözülür ve `demir_faturalar.kalemler` JSON'a yazılır; kalem toplamı fatura toplamıyla sağlanır (binlik/ölçek düzeltmeli). **Eksikten taslak sevkiyat** (isteğe bağlı kutu): bulunamayan irsaliyeler `[FATURADAN]` etiketiyle taslak sevkiyat olarak açılır (kalemleriyle, faturaya bağlı; tedarikçi yoksa unvandan get-or-create); `demir/import.php` taslağı normalize eşleşmeyle SİLMEDEN GÜNCELLER (temizle-yükle'de taslaklar silinmez, diğer kayıtların fatura bağları snapshot+geri bağlama ile korunur) → mükerrer imkânsız. Kayıtlı listede: **fatura arama** (`fatura_ara`: no/ETTN/tedarikçi/sipariş no/irsaliye no — normalize + rakam-parçası eşleşmeli), **sıra no + toplam rozeti** (adet/ton/₺), irsaliye kodları bağlı sevkiyata tıklanır, **"içerik" açılımı** (fatura kalemleri + bağlı sevkiyatların çap kırılımı + IFS sipariş linki + TASLAK rozeti), boş kg/tutar için **elle giriş kutuları** (`alan_duzelt`). Tablolar: `demir_faturalar` + `demir_sevkiyatlar.fatura_id` (runtime `dfat_semasi_kur`). Dosya `uploads/demir_fatura/Y/m/`. Tedarikçi normalize unvanla önerilir. |
| `tutanaklar.php` | Teslim tutanağı listesi (tonaj/bağ/evrak durumu). |
| `tutanak_form.php` | **Otomatik no** `{PROJE}-{TASKOD}-NNN` + dinamik kalem satırları. |
| `tutanak_detay.php` | Görüntüleme + **imzalı evrak yükleme** (`uploads/demir_tutanak/{id}/`). |
| `tutanak_pdf.php` | A4 yazdırılabilir tutanak (tarayıcı PDF kaydet). **ERN Taahhüt** logolu/adına (Taahhüt = Holding'in inşaat kolu; iade/hurda/icmal PDF'leri de öyle). |
| `tutanak_takip.php` | **Tutanak Takip defteri** (Excel "TUTANAK TAKİP" sayfası): firma bazında çap satırı hareket (teslim/iade). İçe aktar (tam yenileme, **evrak korunur**) + filtre + **satır ekle/düzenle/sil (modal)** + **satır bazında imzalı evrak yükleme** (`uploads/demir_tutanak_takip/{id}/`). Tablo `demir_tutanak_takip` (runtime, `evrak_url` kolonu). |
| `iade_tutanaklar.php` | **İade tutanağı** listesi (iade eden/teslim alan, tonaj, evrak). |
| `iade_form.php` | İade eden **zorunlu**, teslim alan **opsiyonel** (boş=depoya iade). Otomatik no `{PROJE}-{IADEEDEN_KOD}-IADE-NNN`. |
| `iade_detay.php` / `iade_pdf.php` | Görüntüleme + imzalı evrak (`uploads/demir_iade/{id}/`) / A4 PDF. |
| `taseron_bakiye.php` | **Net Elinde = Teslim Alınan + Devraldığı − İade Ettiği − Hurda Satışı** (çap bazında açılır; hurda **çaptan bağımsız** düşülür). Teslim/iade kaynakları: uygulama tutanakları **+ Tutanak Takip defteri** (firma adı eşleşir; aynı tutanak_no uygulamada varsa çift sayılmaz). Defterde irsaliye alanı başka firma adıyla başlayan teslimler (ör. YILDIZLAR ← "DENER U030") **Devraldığı** sayılır. Hurda CRUD (modal, otomatik no `{TASKOD}-HRD-NNN`) + kayıt listesi + **imza tutanağı** (`hurda_pdf.php`). Tablo `demir_hurda` (runtime + kurulum). |
| `_iade_ortak.php` | İade ortak: şema garantisi (`iade_semasi_kur`) + `iade_num` + `iade_no_uret`. |
| `icmal.php` / `icmal_pdf.php` | Gelen demir mutabakatı (çap+tedarikçi) + Excel/PDF. **Çap değerine tıklayınca popup** (AJAX `?cap_detay=`): o çaptaki sevkiyatlar→irsaliyeye (`sevkiyat_form.php?id=`), siparişler→(`siparis_detay.php?id=`) **o çap için gelen/kalan bakiye ile**, teslim tutanakları→(`tutanak_detay.php?id=`). |
| `zayiat.php` | **Demir Zayiat Takibi**: teorik metraj (proje×çap, elle, modal) vs teslim edilen (tutanaklar + Tutanak Takip defteri, tutanak_no dedup); zayiat/oran/limit % (varsayılan 3), LİMİT AŞIMI kırmızı. Tablo `demir_metraj` (runtime). |
| `raporlar.php` | Chart.js + **ExcelJS** (Özet/Aylık/Tedarikçi/**Proje**/Detay) + PDF (yazdırma penceresi). **Proje kırılımı**: proje bazlı doughnut + **Proje × Çap matris tablosu** (gelen ton, çap kolonları sıra no'ya göre) + Excel "Proje" sayfası. |
| `projeler.php` | Proje CRUD (kod+ad), **mükerrer önleme (kod VEYA ad)**. |
| `proje_detay.php` | Proje detayı: çap bazında gelen demir + siparişler+bakiye + sevkiyatlar. |
| `proje_disi.php` | Proje Dışı İşler (A.2 kantar farkı, A.3 transfer, A.4 laboratuvar). Excel içe aktar (tam yenileme). |
| `import.php` | "İNŞAAT DEMİRİ TAKİP" sayfasından sevkiyat içe aktarma. |
| `import_siparis.php` | "Sipariş Takip" sayfasından sipariş + sevkiyat bağlama. |
| `caplar/tedarikciler/taseronlar.php` | Tanım CRUD'ları. |
| `kurulum_demir.php` | Şema+seed + DB durum rozeti. |
| `_yakinda.php` | Ortak "Yakında" şablonu. |

**API**: `api/demir_okut.php` (AI OCR → kalemler), `api/demir_scan_kaydet.php`
(`uploads/demir/gorseller/`), `api/demir_pdf_kaydet.php` (`uploads/demir/belgeler/`).

---

## 6. Veritabanları

> **Canlıda 7 AYRI veritabanı vardır** (2026-08 ayrıştırması + 2026-09 CRM + IT). Her modül kendi DB'sinde:
> `takbulut_beton` (beton) · `takbulut_demir` · `takbulut_seramik` · `takbulut_depo` ·
> `takbulut_akaryakit` · `takbulut_crm` (**CRM + Prekast birlikte** — `prekast_` önekli tablolar
> aynı DB'de durur, ayırmak istenirse `PREKAST_DB_NAME`) · **`takbulut_it`** (IT Envanter, `it_` önekli).
> Tümü `config.php`'deki `DEMIR_DB_NAME`/`SERAMIK_DB_NAME`/`DEPO_DB_NAME`/`AKARYAKIT_DB_NAME`/`CRM_DB_NAME`/
> **`IT_DB_NAME`** sabitleriyle etkinleştirilir; tek DB kullanıcısı hepsine yetkili.
> ⚠️ Bu sabitlerden biri **tanımsız kalırsa** ilgili modül sessizce **ana DB'ye** düşer ve veriler
> "kaybolmuş" görünür (tablolar önekli olduğu için çakışma olmaz, ama modül boş açılır).
> Aktif DB'yi ilgili modülün `kurulum_*.php` sayfasındaki **rozetten** görebilirsin
> (yeşil = ayrı DB, sarı = ana DB). Yedekleme artık **7 DB'yi birden** kapsamalıdır.

### Beton (`kurulum.php`)
Tanım tabloları (id/ad/aktif): beton_siniflari, katki_listesi, pompa_turleri, firmalar,
kivam_siniflari, parseller. Hiyerarşik: imalat_gruplari→ana_is_kalemleri, bloklar→kotlar.
`tedarikciler`(vkn), `projeler`(kod UNIQUE), `users`(role VARCHAR(40) [ROLLER] + **`modul_erisim`** [virgüllü modül listesi,
boş=tümü; runtime ALTER `modul_erisim_semasi()` + kurulum] + **`yetkiler`** [modül × işlem JSON matrisi, NULL = rol bazlı eski
davranış] + `unvan`; runtime `yetki_semasi()` + kurulum), **`irsaliyeler`** (~40 kolon:
tip/durum ENUM, kantar_net_*/kantar_farki, tüm tanım FK'leri, onay alanları, scan_image_url runtime),
`modul_ayarlar` (anahtar PK, ad, gizli, sira — modül adlandırma/gizleme; runtime `modul_ayar_semasi()` + kurulum),
`irsaliye_fotolar` (+ runtime `tur`/`okunan` — `blg_semasi_kur`), `audit_log` (JSON diff), **`faturalar`** (fatura_no UNIQUE, tarih, tedarikci_id, tutar, miktar_m3, ettn, irsaliye_adet, eksik_adet, dosya_url) + `irsaliyeler.fatura_id` bağı (runtime `fat_semasi_kur`). Seed admin: `tayyar_akbulut`/`admin`.

### Demir (`demir/kurulum_demir.php`) — `demir_` önekli, ayrı DB
- `demir_caplar` (ad, tip ENUM duz/kangal/hasir/spiral, **birim_agirlik kg/m**, **bag_kg**, sira)
- `demir_tedarikciler`, `demir_taseronlar` (**kod** = tutanak öneki), `demir_projeler` (kod UNIQUE)
- `demir_siparisler` (**ifs_siparis_no**, taseron_id, proje_id, **sozlesme_id**, durum) + `demir_siparis_kalemleri` (cap_id, miktar_ton)
- `demir_sevkiyatlar` (**ifs_siparis_no** [eşleşme anahtarı], scan_image_url, proje/taseron/tedarikci FK)
  + `demir_sevkiyat_kalemleri` (cap_id, **irsaliye_miktar**, **kantar_miktar**)
- `demir_tutanaklar` (tutanak_no, evrak_url) + `demir_tutanak_kalemleri` (irsaliye_no, cap_id, miktar_ton, bag_adeti)
- `demir_iade_tutanaklar` (iade_no, **iade_eden_id**, **teslim_alan_id** [NULL=depoya iade], proje_id, evrak_url) + `demir_iade_kalemleri` (cap_id, miktar_ton, bag_adeti). Runtime `iade_semasi_kur()` ile de oluşur (kurulum'a da eklendi).
- `demir_hurda` (taseron_id FK, tarih, **miktar_ton çaptan bağımsız**, aciklama): iş sonu hurda satışı; taşeron bakiyesinden düşülür. Runtime (`taseron_bakiye.php`) + kurulum.
- `demir_sozlesmeler` (sozlesme_no, taseron_id FK, proje_id, tarih, konu, aktif) + `demir_tutanaklar.sozlesme_id` bağı. Runtime (`sozlesmeler.php`) + kurulum.
- `demir_tutanak_takip` (runtime, `tutanak_takip.php` içinde CREATE IF NOT EXISTS): firma, sira, proje, tip (teslim/iade), tarih, irsaliye_no, tutanak_no, cap_label, miktar_ton (iade negatif), bag. Excel "TUTANAK TAKİP" sayfasından tam yenileme.
- `demir_proje_disi` (runtime, `proje_disi.php` içinde CREATE IF NOT EXISTS): tip A.2/A.3/A.4, proje/hedef_proje, firma/hedef_firma, cap_mm, adet, boy, metraj_ton, kantar_farki

Çap seed (teorik kg/m ≈ 0.006165×d²): Ø8..Ø32 (duz), Q10/12/14 Kangal, Spiral Ø10/12, Çelik Hasır Q188/Q257.

> **Not**: `demir_sevkiyatlar.siparis_id` FK var ama kullanılmıyor — sipariş↔sevkiyat eşleşmesi
> **`ifs_siparis_no` string alanı** üzerinden yapılır.

---

## 7. Önemli İş Akışları

### Beton tarama motoru (`hizli_tarama.php`)
jsQR 1.4 (QR) + @zxing/library 0.21.3 (DataMatrix/E1, Safari/Firefox) + pdfjs-dist 3.11 (PDF render)
+ tesseract.js 5 (tur OCR) + native BarcodeDetector (Chrome/Edge tercih). Akış: **Karekod → OCR →
AI (3 kademe)**. Sağdaki QR = GİB JSON (no/tarih/plaka/vkntckn/ettn); soldaki DataMatrix = E1 reçete.
VKN ile tedarikçi eşleşir; eksik alanlar `api/ai_okut.php` ile tamamlanır; düzenlenebilir tabloya
doldurulup `api/hizli_kaydet.php` ile toplu kaydedilir. Düşük-güven satırlarına ⚠/AI rozeti.

### Demir sevkiyat girişi (3 yöntem)
1. Manuel (çap bazında irsaliye+kantar, fark otomatik).
2. Excel içe aktarma (`import.php`): "İNŞAAT DEMİRİ TAKİP" sayfası; dinamik kolon algılama; çap çiftleri
   (irsaliye col, kantar col+1); tedarikçi/proje/taşeron get-or-create; **mükerrer irsaliye no atlanır**.
3. Karekod+AI: demir irsaliyesi = **beton ile aynı GİB QR** (tek QR, DataMatrix YOK). QR başlığı doldurur,
   `api/demir_okut.php` (AI) çap/miktar/sipariş çıkarır, görüntü `uploads/demir/`'e kaydedilir.

### Sipariş ↔ Sevkiyat bakiye
Eşleşme `sevkiyat.ifs_siparis_no == siparis.ifs_siparis_no`. Gelen = eşleşen sevkiyat kalemleri toplamı;
kalan = sipariş − gelen. `import_siparis.php` teslimat satırlarındaki irsaliye no ile sevkiyatların boş
`ifs_siparis_no`'sunu doldurarak bağlar.

### Çap eşleştirme normalizasyonu (ÖNEMLİ)
- `norm_cap()` / `capMatch()` / `capId()`: label'dan `(\d+)` sayı + tip çıkarır.
- **Türkçe İ/I/ı/i → tek 'I'** (mb_strtoupper "Çelik"→"ÇELIK" ama Excel "ÇELİK" → normalize ile eşleşir).
- Tip: `KANGAL`→kangal, `/` içeriyorsa (Q188/188)→hasir, `SPIRAL`→spiral, aksi→duz.
- Önce (sayı+tip), bulamazsa yalnız sayı ile eşleştir.

### Tutanak numaralandırma
`{PROJE_KOD}-{TASERON_KOD}-{NNN}` (ör. U030-DNR-005). Önekteki max sıra +1, 3 hane. Düzenlemede sabit.
İade tutanağı: `{PROJE_KOD}-{IADEEDEN_KOD}-IADE-{NNN}` (ör. U030-OSM-IADE-001).

### İade tutanağı & taşeron bakiye & hurda
Bir taşeronun iş sonunda elinde kalan demiri iade etmesi (ör. Osman Camcı 5 t iade → Dener'e). **İade eden**
zorunlu, **teslim alan** opsiyonel (boş=depoya/şirkete iade). Ayrıca teslim edilen demirin bir kısmı iş sonunda
**hurda olarak satılabilir** (`demir_hurda`): tonaj bazında, **çaptan bağımsız** düşüm. `taseron_bakiye.php`:
**Net Elinde = Σ teslim tutanağı (taseron_id) + Σ devraldığı (teslim_alan_id) − Σ iade ettiği (iade_eden_id) − Σ hurda**.
Çap kırılımı teslim/devir/iade için verilir; hurda çap detayında ayrı satır olarak (sarı) gösterilir.

### uploads klasör yapısı
- Beton: `uploads/images/` (scan), `uploads/pdf/`, `uploads/irsaliye_fotolar/`, `uploads/irsaliye_{id}/`, `uploads/faturalar/{Y}/{m}/`, `uploads/belge_bekleyen/` (eşleşmeyen belgeler, 7 gün).
- **Demir (ayrı)**: `uploads/demir/gorseller/`, `uploads/demir/belgeler/`, `uploads/demir_tutanak/{id}/`, `uploads/demir_iade/{id}/`, `uploads/demir_tutanak_takip/{tutanak_no}/`, `uploads/demir_hurda/{id}/`, `uploads/demir_sozlesme/{id}/`.
- Görseller **dosya olarak** tutulur; DB'ye yalnızca göreli URL yazılır (DB boyutu şişmez).
- **CRM**: `uploads/crm_ariza/{ariza_id}/` (arıza başına çoklu belge/fotoğraf; kayıtlar `crm_ariza_belgeler`).
- **Akaryakıt**: `uploads/akaryakit_cikis/{id}/` (imzalı çıkış fişi) · `uploads/akaryakit_giris/{id}/` (mazot giriş irsaliyesi/faturası).
- **IT Envanter**: `uploads/it_envanter/{cihaz_id}/` (cihaz fotoğrafı, fatura, garanti belgesi, imzalı zimmet tutanağı; kayıtlar `it_belgeler`, `tur='zimmet'` = imzalı tutanak — bir dosya birden çok cihaza bağlı olabilir, diskten yalnız SON bağ koptuğunda silinir).
- `uploads/.htaccess` PHP çalıştırmayı engeller (alt klasörlere de uygulanır).

---

## 8. Konvansiyonlar & Dikkat Edilecekler

- **Güvenlik**: her sorgu prepared statement; her çıktı `h()`; ORDER BY için **whitelist** (asla ham input).
  Rol kontrolü `require_auth([...])` + `can_*()`. AI SQL (`ai_asistan.php`) yalnız SELECT + kelime filtresi.
  **CSRF**: tek küresel token (`csrf_token()`/`csrf_ok()` functions.php); `header.php` çıktı tamponu
  (`csrf_ob_inject`) tüm `<form method=post>`'a otomatik `<input name=csrf>` ekler; `auth.php` giriş yapmış
  kullanıcının POST'unu merkezi doğrular (419). `/api/` yolları muaf (SameSite=Lax + require_auth). login.php muaf.
- **Mükerrer önleme**: tanımlarda UPPER karşılaştırma; kullanımda ise silme engeli. Projeler: kod VEYA ad.
- **Demir sayfası şablonu**: başta `$rootPath='../'` + `require ../includes/...` + `require_auth(...)` +
  `require ../includes/db_demir.php`; sonunda `require ../includes/footer.php`.
- **Türkçe sayı**: virgül ondalık (`str_replace(',','.')` parse; `number_format(...,',','.')` göster).
- **Excel başlık/sayfa eşleme (depo deseni)**: sayfa adı **içerir** mantığıyla bulunur ("KARTAL-BATIYAKASI SARF
  MALZEME" → SARF MALZEME) ve bir sayfa **yalnız bir türe** atanır ("TAŞERON MALZEME GİRİŞ", "MALZEME GİRİŞ"i de
  içerdiğinden çift aktarım olurdu). Sütunlar sabit indeksle DEĞİL başlık metninden bulunur (`dpHarita`), bir sütun
  tek alana bağlanır. `dpNorm()` Türkçe harfleri ASCII'ye katlar (İIış→I, Ş→S, Ğ→G, Ü→U, Ö→O, Ç→C) — aksi halde
  'DEMİRBAŞLAR' ile 'DEMIRBAS' eşleşmez ve arama sessizce boş döner.
- **Excel içe aktarma**: `SimpleXLSX::parse` + `rows($sheetIndex, $limit)` (bellek için limit ver;
  formüllü sayfalar 1M satır bildirebilir). Sayfayı isimle bul, başlık satırını içerikle tespit et.
- **Deploy sonrası**: kod değişikliği canlıya gitmesi için deploy2.php URL'si çalıştırılmalı.
  Yeni tablo gerekiyorsa ilgili `kurulum_*.php` bir kez açılmalı (veya sayfa runtime CREATE eder).

---

## 9. Bu Oturumda Yapılanlar (Süreç Geçmişi)

**Beton/altyapı iyileştirmeleri:**
- Oturum süresi sabiti (`SESSION_LIFETIME`) + idle timeout + topbar geri sayım sayacı.
- İrsaliye listesinde sütun başlığına tıklayarak whitelist sıralama.
- Login ekranı yeniden tasarım (iki beyaz ERN logo `uploads/`'tan, dalga animasyonu, dev kredisi).
- Alt klasör (demir/) login yönlendirmesi 404 bug'ı düzeltildi (`$rootPath` kullanımı).
- Hızlı taramaya 3 kademeli okuma (Karekod→OCR→AI) + cross-browser DataMatrix (zxing) + satır doğrulama.

**Demir modülü (sıfırdan kuruldu):**
- Ayrı DB (`db_demir.php`) + şema (`kurulum_demir.php`) + modül banner/menü.
- Faz 1: Tanımlar (çap/tedarikçi/taşeron) + Sevkiyat + kantar farkı + İcmal.
- Faz 2: Excel içe aktarma + Sipariş+bakiye + Tutanak+PDF+imzalı evrak + Excel/PDF dışa aktarma +
  Karekod+AI ile sevkiyat.
- Raporlar (ExcelJS+PDF), Projeler tanımı + proje detay, Sipariş Takip içe aktarma,
  Proje Dışı İşler (A.2/A.3/A.4), demir görsellerini ayrı klasöre alma.

---

## 10. Yapılacaklar / Backlog (Sonraki Adımlar)

> Önce mevcut **web projesi bitirilecek**; aşağıdakiler sıraya alınmıştır.

### 0) WhatsApp grup bağlantısı (BEKLEMEDE — kullanıcı haber verecek)
- **Telegram köprüsü KURULDU** (`whatsapp/api/telegram_al.php`): risksiz ara çözüm — mesajlar WhatsApp'tan
  'Paylaş→Telegram→bot' ile iki dokunuşta iletilir, görseller tam kalite düşer. Aktifleştirme: BotFather'dan
  bot + `TELEGRAM_BOT_TOKEN` + setWebhook (uç dosyanın başındaki yorumda adım adım).
- **Karar verildi:** Baileys ile gruba bağlanılacak (WhatsApp Web protokolü, VPS'te Node dinleyici).
  Kullanıcının **data hattı** aday numara — SMS doğrulaması alabiliyorsa kullanılacak; uygun zamanda
  test edip haber verecek. O güne dek **elle yapıştırma** akışı kullanılıyor (çalışıyor, AI doğruluğu
  gerçek kantar fişiyle %98+ doğrulandı).
- **Hazır bekleyen altyapı:** `whatsapp/api/mesaj_al.php` (MESAJ_TOKEN'lı webhook, Meta formatı dahil),
  görsel işleme + `meta_medya_indir()`. Yazılacak tek parça: Baileys Node servisi
  (yalnız DİNLER, mesaj göndermez; oturum diske; QR ile eşleşme; ayrı SIM şart — ban riski).
- Kurulum sırası: SIM'de WhatsApp aktive → numara gruba eklenir → Node servisi yazılır/başlatılır →
  QR taratılır. Dinleyen telefon arada Wi-Fi'a bağlanmalı (14 gün kuralı).

### A) Mobil Uygulama — iOS & Android (ERTELENDİ, web bitince)
- **Yaklaşım:** Mevcut siteyi (ernsaha.com.tr/beton/) **Capacitor WebView wrapper** ile native kabuğa sarmak
  (kod yeniden yazılmaz, içerik sunucudan gelir → her deploy otomatik yansır; yalnız ikon/izin/isim
  değişince mağaza güncellemesi gerekir). Alternatifler: PWA "ana ekrana ekle" (en ucuz, mağazasız) /
  React Native-Flutter (en pahalı, API gerektirir — şu an gereksiz).
- **Kritik özellik:** Hızlı Tarama (QR/DataMatrix/OCR kamera) → iOS WKWebView (iOS 14.3+) ve Android WebView
  `getUserMedia` destekler; native kamera izinleri ayarlanacak (`NSCameraUsageDescription` vb.).
- **Kullanıcının cihazları (test/build tam kapalı):** MacBook Air **M2** (Xcode build/imzalama), **iPhone 11**
  (iOS test), **Realme 11 Pro+ 5G** + **Lenovo Tab 11** (Android telefon/tablet test).
- **Maliyet:** Google Play tek sefer **$25**, Apple Developer yıllık **$99**. Mağazasız ücretsiz test mümkün
  (Xcode ile iPhone'a, APK ile Android'e doğrudan kurulum). Öneri: önce ücretsiz test → sonra Play → sonra App Store.
- **Ek (opsiyonel):** Push bildirim (FCM + APNs) — "onay bekleyen irsaliye" vb.; temel offline önbellek.

### B) Beton — açık işler
- **PRP Bina Üstyapı canlı zayiat:** `prp_ustyapi.php`'de SAHADA DÖKÜLEN'i gerçek irsaliyelerden (blok/kot/imalat)
  otomatik çekip ZAİYAT ORANI'nı canlı hesaplamak; %5 (fore kazık %15) limit aşımını kırmızı göstermek.
- **İcmal düzenlenebilir (opsiyonel):** `icmal_beton.php`'yi elle satır ekle/düzenle yapmak (kullanıcı "kullanıcı tanımlı"
  ile bunu kastettiyse).

### C) Demir — açık işler
- **Demir şablonu karşılaştırması:** Demir Takip Excel şablonundaki sekmeleri sistemle karşılaştırıp eksik olanları
  (varsa) beton'daki `metraj_takip.php`/`storeImalatSheets()` deseniyle eşitlemek.

---

*Bu dosya proje büyüdükçe güncellenmeli. Yeni modül/sayfa eklerken ilgili bölüme işle.*
