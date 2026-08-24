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
  yapılan/taşeron], teslim_alan, onay, lokasyon, aciklama, elle [günlük kayıt], kalem_id [stok bağı], hurda [hurdaya ayırma çıkışı — Hurdalar filtresi/rozeti/KPI]) — `depo_kalemler` stoğun FOTOĞRAFI, `depo_hareketler`
  o stoğu oluşturan TEK TEK HAREKETLER. Sayfalar: index(dashboard: kategori kartları + mali değer KPI +
  tükenen liste + **hareket özeti/son hareketler/en çok hareket gören firmalar**) · kalemler(kategori bazlı liste,
  ?k=demirbas|sarf|el_aleti, arama, stok/tutar) · kalem_form(ekle/düzenle) · **hareketler**(giriş/çıkış defteri:
  tür/kaynak/firma/tarih aralığı/serbest metin filtreleri + KPI + sayfalama + Excel; elle kayıtlarda düzenle/sil) · **hareket_form**(günlük elle giriş/çıkış: `elle=1` işaretli — Excel tam yenilemesinde KORUNUR [import `elle=0` siler]; opsiyonel "stok kalemine işle" `kalem_id` → depo_kalemler GELEN/GİDEN güncellenir, silme/düzenlemede `dp_stok_islet()` geri alır; malzeme datalist'ten seçilince kalem otomatik eşleşir) · **hareket_tutanak**(hareket başına A4 tutanak: giriş=TESLİM ALMA, çıkış=TESLİM, hurda=HURDAYA AYIRMA; **ERN Taahhüt** logolu, kayıt sonrası bannerdan ve listeden yazdırılır) · import(7 sayfa: DEMİRBAŞLAR/
  SARF MALZEME/EL ALETLERİ → stok, MALZEME GİRİŞ-ÇIKIŞ + TAŞERON MALZEME GİRİŞ-TESLİMAT → hareket; her biri kendi
  türünde tam yenileme, dosyada olmayan sayfaya dokunulmaz) · raporlar (Chart.js + Excel: kategori/disiplin mali değer, en değerli kalemler) · kurulum_depo. El aletleri: fiyat/disiplin yok, **Seri No + Zimmetli Kişi** var. Demirbaş/
  sarf: birim fiyat → **mali değer** (STOK × B.Fiyat). `depo/_ortak.php` (dp_sayi, DP_KATEGORI, dp_ozet).
- **Akaryakıt modülü** = `akaryakit/` alt klasörü. Şantiye mazot (dizel) stok + araç/makine bazında
  aylık tüketim takibi. **Ayrı veritabanı** (`takbulut_akaryakit`, `AKARYAKIT_DB_NAME`).
  `includes/db_akaryakit.php` → `$pdoAkaryakit`. Tablolar: `akaryakit_araclar` (araç/makine kaydı,
  anahtar = **Şoför + Cinsi** normalize, get-or-create), `akaryakit_donemler` (ay bazlı stok:
  **Devir + Gelen = Toplam; Toplam − Kullanılan = Kalan**; her ayın Kalan'ı sonraki ayın Devir'i =
  zincir), `akaryakit_tuketim` (dönem×araç: aylık tüketim/çalışma/ortalama/okumalar + **günlük 31
  günün Mazot/Km detayı JSON** `gunluk`), `akaryakit_tutanak` (aylık imzalı tüketim raporu satırları).
  Sayfalar: index(dashboard: stok KPI + aylık tüketim/kalan grafik + firma doughnut + en çok tüketen) ·
  aylik(dönem seçmeli araç tüketim tablosu + günlük detay modal) · stok(dönem zinciri, uyuşmayan geçiş
  kırmızı, elle düzelt) · **cikislar**(günlük mazot çıkışı: araç seçimi otomatik doldurur, ay filtresi + KPI;
  tablo `akaryakit_cikislar` runtime+kurulum; stok zincirine KARIŞMAZ — Excel esastır, ay sonunda Excel'e işlenir) ·
  **cikis_tutanak**(AKY-C-00001 no'lu A4 mazot teslim tutanağı, ERN Taahhüt logolu, kayıt sonrası bannerdan yazdırılır) ·
  araclar/arac_form(CRUD) · tutanaklar + tutanak_pdf(A4) · raporlar (Chart.js + Excel: aylık tüketim, firma/araç bazlı) · import · kurulum_akaryakit.
  Import: aylık sayfalar (OCAK 2026…) + TUTANAK sayfaları **dönem bazlı tam yenileme**; sayfa altındaki **imza bloğu** (DEPO ŞEFİ…/MALİ İŞLER ŞEFİ:/İMZA:/TARİH:) `ak_imza_satiri()` ile atlanır — aksi halde 'İMZA:' adlı araçlar oluşuyordu; `ak_imza_temizle()` eski çöp araç/tüketim/tutanak kayıtlarını import sonunda siler; **Excel'in
  TOPLAM hücreleri bayat olduğundan stok hesaplanır** (devir+gelen, −kullanılan). Gün d → Mazot col
  `7+2d`, Km col `8+2d` (gün 1=col9…31=col69); özet col 71 aylık/72 çalışma/73 ortalama/74-76 okuma.
  `akaryakit/_ortak.php` (ak_sayi, ak_norm, ak_donemSira TR ay→sıra, ak_aracId, ak_donemler).
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
- Excel: `Shuchkin\SimpleXLSX` (composer, okuma) + `includes/XlsxWriter.php` (yazma; **varsayılan ERN Taahhüt logolu** — kurucu 2. parametre false ile kapatılır, başlık 4. satıra kayar) + client-side **ExcelJS** (formatlı rapor).
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
- **`auth.php`** — kimlik/yetki + **oturum idle timeout**:
  - `SESSION_LIFETIME` (varsayılan **3600 sn**), config'de override. `gc_maxlifetime` = +300.
  - Her istekte `last_activity` yenilenir; aşılırsa oturum temizlenir + login'e yönlendirir.
  - **`require_auth()` login yönlendirmesi `$rootPath` kullanır** (alt klasör `demir/`'den doğru
    `login.php`'ye gider — bunu bozma; yoksa 404 olur).
  - **Roller ENUM**: `admin`, `teknik_ofis_admin`, `teknik_ofis`, `saha_sefi`, `depo`.
  - Yetki fonksiyonları: `can_edit()`, `can_edit_irsaliye($row)` (durum bazlı), `can_approve_saha()`,
    `can_approve_teknik()`, `can_view_reports()`, `can_manage_definitions()` (admin+teknik_ofis_admin),
    `can_manage_users()` (admin), `has_role(...)`, `is_admin()`.
- **`functions.php`** — `h()` (XSS), `flash()/get_flash()`, `format_date/number()`, `role_label()`,
  `redirect()`, `audit_log()`, `current_user_id()`.
- **`header.php`** — layout + **modül algılama** (`$__module` = PHP_SELF `/demir/` içeriyorsa 'demir').
  Topbar'da **Beton/Demir geçiş banner'ı**; modüle göre sidebar menüsü. **Oturum sayacı** (topbar,
  geri sayım, fetch/XHR'de sıfırlanır, 0'da logout). Dark mode (`localStorage beton_dark`).
  Demir sayfaları `$rootPath='../'` set etmeli (linkler ve login yönlendirmesi buna dayanır).
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
- **`hizli_tarama.php`** (~2900 satır) — **QR+DataMatrix+OCR+AI tarama motoru** (§7). Toplu irsaliye tarama.
- **`raporlar.php`** — Chart.js + ExcelJS (formatlı xlsx) + jsPDF/AutoTable (PDF). `can_view_reports()`.
  **Proje bazlı özet** (U030/U031/U039): etkin proje = `COALESCE(i.proje_id, par.proje_id)` (parsel→proje bağı ile geçmiş kayıtları da kapsar); doughnut grafik + tablo + Excel "Tedarikçi & Beton" sayfasında PROJE bölümü.
- **`zayiat_takip.php`** — **Beton Zayiat Takibi** (`can_view_reports()`): Zayiat = Dökülen (alış−iade, reddedilen hariç) − Teorik Metraj. Teorik metraj **3 seviyede** girilir (kot/blok/imalat kalemi; modal, kademeli seçim), satır bazında **limit %** (varsayılan 5, fore kazık 15 — kalem adında FORE geçerse otomatik önerilir). Sekmeli görünüm + KPI + teorik-vs-dökülen bar grafik + satıra tıklayınca irsaliye popup (irsaliyeye geçişli). **Proje bazında zayiat özeti** (tabloların üstünde): parsel→proje bağıyla tanımlı metraj satırları projeye toplanır (teorik/dökülen/zayiat/oran/limit aşımı) + proje bazlı teorik-vs-dökülen grafik. Tablo `beton_metraj` (runtime + kurulum). Durum: LİMİT AŞIMI (kırmızı) / Yaklaşıyor (>%80 limit) / Devam ediyor (dökülen<teorik) / Normal. |
- **`belge_dagit.php`** (`admin`/`teknik_ofis_admin`/`teknik_ofis`/`saha_sefi`, "Belge Oku & Dağıt" menüsü) — **toplu belge okuma + otomatik irsaliye bağlama**: kantar fişi/fatura/irsaliye görselleri çoklu yüklenir → `includes/belge.php` `blg_ai_oku()` ile AI okur (fiş no, irsaliye no, plaka, tarih, tartımlar, net kg, saatler) → `blg_irsaliye_bul()` **irsaliye no (normalize) → plaka+tarih → plaka** sırasıyla ilgili irsaliyeyi bulur → belge o irsaliyenin klasörüne taşınıp `irsaliye_fotolar`'a `tur='kantar'` olarak eklenir. Kantar değerleri **yalnız boş alanlara** yazılır (`blg_kantar_uygula`, elle girilen veri ezilmez; kantar_farki yeniden hesaplanır). **Mükerrer önleme** `blg_mukerrer()`: (1) belge kimliği — fiş no/irsaliye no/fatura no, biçim farkı normalize edilir, (2) dosya içeriği md5 (aynı baytlar aynı belgedir; önce boyut karşılaştırılır), (3) kimlik taşıyan türlerde dosya adı — fotoğraflarda ad karşılaştırması YAPILMAZ (telefonlar farklı fotoğraflara IMG_0001.jpg der). Eşleşmeyen belge kaybolmaz: `uploads/belge_bekleyen/` altında **süresiz** bekler (yanında `.json` not dosyası: ad/tür/okunan), sayfadaki **Bekleyen Belgeler** listesinden aday irsaliyeye bağlanır / AI ile yeniden okutulur / elle silinir. Dashboard fatura kartındaki eksik sayısı `fatura_eslestir.php?eksik=1`e götürür — `faturalar.eksik_liste` (JSON, runtime kolon) eksik irsaliye NUMARALARINI saklar ve panelde rozet olarak listelenir.
- **`fatura_eslestir.php`** (`can_view_reports()`, "Fatura Eşleştirme" menüsü) — **Fatura ↔ İrsaliye mutabakatı**: tedarikçi e-Faturası (PDF/JPG) yüklenir veya metni yapıştırılır → `includes/fatura.php` fatura no/tarih/ETTN/brüt+ödenecek tutar/m³ ve **irsaliye listesini** çıkarır, numaraları **normalize edip** (`ANM2026-4710` ↔ `ANM2026000004710` — düz karşılaştırma HİÇ eşleşme bulmaz) sistemdeki irsaliyelerle eşleştirir. Eşleşen/eksik listesi + m³ farkı KPI'ı. **Mükerrer önleme**: `fat_mevcut()` faturayı **no VEYA ETTN** ile arar (ETTN faturanın değişmez kimliği — no farklı yazılsa da yakalar) ve "zaten işlenmiş" uyarısında **bağlı irsaliye listesini** tablo olarak gösterir (`fat_bagli_irsaliyeler`; her satır 'şimdiki faturada ✓ var / ✗ yok — bağ kopacak' işaretli, m³ toplamı ile) + "<n> yeni bağ / <n> zaten var" ya da hiç değişiklik yoksa "tekrar kaydetmene gerek yok"; kaydetme UPDATE olur, yeni kayıt açılmaz. Kayıtlı Faturalar listesinde bağlı sayısına tıklayınca irsaliyeler açılır (fatura m³ ile karşılaştırmalı); `fat_baskaya_bagli()` **başka bir faturaya bağlı irsaliyeleri** kırmızı satır + rozetle gösterir (çift faturalandırma uyarısı, onay diyaloğu). Onayda `faturalar` kaydı + `irsaliyeler.fatura_id` bağı (`fatura_no` taramada ETTN ile dolabildiğinden **yalnız boşsa** doldurulur). **Karekod (GİB e-Fatura QR)**: faturanın altındaki karekod tarayıcıda okunur (jsQR + pdf.js, `BarcodeDetector` varsa o) ve `fat_qr_coz()` ile çözülür — fatura no/tarih/ETTN/**satıcı VKN**/ödenecek tutar buradan **birebir** gelir, metinle çelişirse karekod esas alınır ve fark uyarı olarak listelenir (`fat_qr_birlestir`). VKN ile tedarikçi otomatik seçilir (`fat_tedarikci_bul`); VKN kayıtlı değilse uyarır. ⚠ Karekottaki `vergidahil` tevkifat **düşülmüş** tutardır (kağıttaki brütten farklı), bu yüzden brüt tutar karekottan değil metinden alınır. Karekod içeriği metin kutusuna yapıştırılırsa da otomatik tanınır. PDF okuma sırası: `pdftotext -layout` (varsa) → **AI belge okuma** (`ai_call`, Claude/Gemini; OpenRouter belge desteklemez) → metin yapıştırma. Dosya `uploads/faturalar/Y/m/`. Tablo `faturalar` (runtime `fat_semasi_kur` + kurulum). `irsaliyeler.php?fatura_id=` ile faturanın irsaliyeleri listelenir.
- **`aktivite.php`** (admin, Araçlar menüsü) — **Kullanıcı Aktivite Raporu**, 2 sekme: **Özet** (kullanıcı başına oturum sayısı/toplam süre/ort. oturum/sayfa görüntüleme/son görülme/en çok modül + KPI + retention temizlik) ve **Detay** (kullanıcı+tarih+tür filtreli zaman çizelgesi: sayfa gezinme `kullanici_aktivite` + kayıt değişiklikleri `audit_log` UNION). Her iki sekme **Excel'e aktarılır** (`XlsxWriter`, filtrelere saygılı; süreler dk). İzleme `header.php`→`aktivite_izle()` (functions.php): her render'da `kullanici_oturum` upsert (giriş/son_aktivite/sayfa_sayisi) + `kullanici_aktivite` insert **yalnız sayfa/modül değişince** (yenileme şişirmez); ana DB bağlantısı istek başına **static önbellekli** (`aktivite_pdo`); **olasılıksal otomatik retention** (`aktivite_temizle`, ~%0.25 istek, `AKTIVITE_SAKLAMA_GUN` varsayılan 90). Tablo yoksa runtime oluşur (+ kurulum.php).
- **`veri_kontrol.php`** (admin+teknik_ofis_admin, Araçlar menüsü) — **Veri Kontrol & Mutabakat**: DB özetleri, **mükerrer irsaliye grupları** (UPPER/TRIM normalize) + tümünü/grup bazlı temizlik (en eski kayıt korunur, audit_log), no'suz şüpheli tekrarlar (yalnız liste), **Excel mutabakatı** (toplam farkı + DB'de fazla / Excel'de eksik no listeleri). Dashboard toplamları `durum<>'reddedildi'` filtreli.
- **`import.php` (beton)** — **tüm sayfalar otomatik taranır** (sayfa seçimi yok): başlığı algılanan sayfalar veri sayılır, adında İADE geçen sayfa `tip='iade'`; VERİ/KOT/Kaşe atlanır. Mükerrer kontrolü normalize (UPPER/TRIM). Admin için **"tam yenileme"** kutusu: önce TÜM irsaliyeleri siler (transaction + audit_log), sonra aktarır — Excel ile birebir eşitler. **Fotoğraf koruma**: tam yenilemede `irsaliye_fotolar` kayıtları silmeden önce `irsaliye_no` ile snapshot alınır, import sonrası aynı no'lu yeni kayda yeniden bağlanır (dosyalar diskte kalır; eşleşmeyen foto sayısı raporlanır).
- **`kotlar.php`** — **blok → kot akordeon görünümü** (her blok bir akordeon başlığı: parsel + kot adedi + toplam dökülen m³/irsaliye; açınca o bloğun kot listesi sıra no ile). Kot **detay/kat etiketi** (`kotlar.aciklama`, runtime ALTER + kurulum) + **VERİ sekmesinden kat etiketi doldurma** (kot→KAT haritası; yalnız boş detaylar) + **KOT sekmesinden blok+kot içe aktarma** (`action=kot_yukle`: her sütun=blok, altındaki değerler=kot; hedef parsel seç/yeni ad; blok+kot get-or-create UPPER-normalize, idempotent, sıra Excel'den) + kot başına **döküm özeti** (m³/irsaliye/imalat kalemleri), m³'e tıklayınca o kottaki irsaliyeler popup — "hangi blok hangi kot ne yapılmış".
- **`login.php`** — **Şantiye İş Takip Sistemi / Batı Yakası Projesi** markası; iki beyaz ERN logolu koyu yeşil panel + Batı Yakası proje rozeti (dış SVG, onerror fallback) + 5 modül tanıtım pill'i (beton/demir/seramik/depo/akaryakıt) + dalga animasyonu + geliştirici kredisi.
  QR/AI beton = GİB e-İrsaliye QR (JSON) + KGS/THBB DataMatrix (E1) + tesseract + AI.
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
- Diğer: `kullanicilar.php` (admin), `ai_ayarlar.php`, `yedek.php`, `import.php`, `kurulum.php` (seed).

---

## 5. Demir Modülü (`demir/`)

Sidebar: Dashboard · Sevkiyatlar · Siparişler · **Sipariş Talepleri** · **Talep Mutabakatı** · Tutanaklar · **Tutanak Takip** · **İade Tutanakları** ·
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
| `talepler.php` | **IFS Sipariş Talepleri** ("Demir Siparişleri Takip Tablosu" Excel'i): her sayfa bir talep (Talep No; birleşik "111779-112123" desteklenir, çift Miktar kolonu varsa "Toplam" okunur). Çap **Malzeme Açıklamasından** çıkar ("Nervürlü 26 Mm"→Ø26, "Q188/188"→hasır) ve `demir_caplar`'a bağlanır; firma KALEM düzeyinde (bir talepte PRP+OSMAN CAMCI olabilir). Tarih sayfa adından. İçe aktarma tam yenileme; liste + çap özet matrisi + firma/proje filtre + collapse kalem kırılımı. ⚠ Talep No (110307…) ≠ IFS Sipariş No (706589…) — sipariş bakiyesine karışmaz, Excel birebir yansır. Tablolar `demir_talepler`+`demir_talep_kalemleri` (runtime + kurulum). |
| `mutabakat.php` | **Talep ↔ Saha Mutabakatı**: IFS taleplerindeki "Teslim Alınan" (kg) ile sevkiyatların irsaliye/kantar miktarı (ton) **çap bazında** karşılaştırılır; tolerans 0,5 t veya %1. Rozetler: Uyumlu / Saha eksik (talep fazla, kırmızı) / Saha fazla (mavi — talep dosyası 110307 öncesini kapsamadığından beklenen durum) / Yalnız talepte-sahada. Proje filtresi (talep tarafı LIKE, saha tarafı proje_id). **Tarih filtresi bilerek yok** (talep tarihi=sipariş günü ≠ sevkiyat tarihi=geliş günü). |
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

> **Canlıda 5 AYRI veritabanı vardır** (2026-08 ayrıştırması). Her modül kendi DB'sinde:
> `takbulut_beton` (beton) · `takbulut_demir` · `takbulut_seramik` · `takbulut_depo` ·
> `takbulut_akaryakit`. Tümü `config.php`'deki `DEMIR_DB_NAME`/`SERAMIK_DB_NAME`/`DEPO_DB_NAME`/
> `AKARYAKIT_DB_NAME` sabitleriyle etkinleştirilmiştir; tek DB kullanıcısı hepsine yetkili.
> ⚠️ Bu sabitlerden biri **tanımsız kalırsa** ilgili modül sessizce **ana DB'ye** düşer ve veriler
> "kaybolmuş" görünür (tablolar önekli olduğu için çakışma olmaz, ama modül boş açılır).
> Aktif DB'yi ilgili modülün `kurulum_*.php` sayfasındaki **rozetten** görebilirsin
> (yeşil = ayrı DB, sarı = ana DB). Yedekleme artık **5 DB'yi birden** kapsamalıdır.

### Beton (`kurulum.php`)
Tanım tabloları (id/ad/aktif): beton_siniflari, katki_listesi, pompa_turleri, firmalar,
kivam_siniflari, parseller. Hiyerarşik: imalat_gruplari→ana_is_kalemleri, bloklar→kotlar.
`tedarikciler`(vkn), `projeler`(kod UNIQUE), `users`(role ENUM), **`irsaliyeler`** (~40 kolon:
tip/durum ENUM, kantar_net_*/kantar_farki, tüm tanım FK'leri, onay alanları, scan_image_url runtime),
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
