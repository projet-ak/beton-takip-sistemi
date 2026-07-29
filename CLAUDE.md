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
  Sayfalar: index(dashboard) · girisler/giris_form · cikislar/cikis_form · stok · paletler ·
  import (Giriş/Çıkış/Mevcut/Palet tam yenileme) · malzemeler/firmalar/taseronlar · kurulum_seramik. raporlar (Chart.js + Excel: tür/malzeme stok, aylık giriş/çıkış).
  Malzeme eşleşmesi `sr_norm()` (I/İ katlama, *→X). `seramik/_ortak.php` ortak yardımcılar.
- **Depo modülü** = `depo/` alt klasörü. Sarf malzeme + demirbaş + el aletleri stok/zimmet takibi.
  **Ayrı veritabanı** (`takbulut_depo`, `DEPO_DB_NAME`). Tek tablo `depo_kalemler` (kategori ENUM
  `demirbas`/`sarf`/`el_aleti`). **Stok = SAYIM + GELEN − GİDEN** (kalem defteri modeli; ayrı log yok).
  `includes/db_depo.php` → `$pdoDepo`. Sayfalar: index(dashboard: kategori kartları + mali değer KPI +
  tükenen liste) · kalemler(kategori bazlı liste, ?k=demirbas|sarf|el_aleti, arama, stok/tutar) ·
  kalem_form(ekle/düzenle) · import(DEMİRBAŞLAR/SARF MALZEME/EL ALETLERİ sayfaları, kategori bazlı tam
  yenileme) · raporlar (Chart.js + Excel: kategori/disiplin mali değer, en değerli kalemler) · kurulum_depo. El aletleri: fiyat/disiplin yok, **Seri No + Zimmetli Kişi** var. Demirbaş/
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
  kırmızı, elle düzelt) · araclar/arac_form(CRUD) · tutanaklar + tutanak_pdf(A4) · raporlar (Chart.js + Excel: aylık tüketim, firma/araç bazlı) · import · kurulum_akaryakit.
  Import: aylık sayfalar (OCAK 2026…) + TUTANAK sayfaları **dönem bazlı tam yenileme**; **Excel'in
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
- Excel: `Shuchkin\SimpleXLSX` (composer, okuma) + `includes/XlsxWriter.php` (yazma) + client-side **ExcelJS** (formatlı rapor).
- AI: Claude (Haiku 4.5) / Gemini / OpenRouter — `AI_PROVIDER` ile seçilir (`includes/ai_call.php`).

---

## 2. Git Branch Stratejisi & Dağıtım (ÖNEMLİ)

- **`claude/blissful-heisenberg-j6jhyk`** = geliştirme branch'i (buraya commit/push).
- **`claude/organize-control-panel-hUe4z`** = **deploy branch'i**. Buraya push → canlıya gider.
- **Akış**: değişikliği blissful'a commit + push → deploy branch'ine ff-merge + push.
  Her iki branch'i senkron tut (bazen kullanıcı GitHub web'den deploy branch'ine commit atar;
  push reddedilirse `git fetch` + merge/senkronla).

### Deploy yöntemi
- **`deploy2.php`** (tercih edilen): tarayıcıdan `deploy2.php?token=...` açılır;
  GitHub'dan deploy branch zip'ini çekip `__DIR__`'e açar. Yalnız **`config.php` + `backups/`**
  korumalıdır (deploy dosyaları artık sır içermediğinden normal güncellenir). **`DEPLOY_TOKEN`
  ve `GITHUB_PAT` `config.php`'de** (git-ignored) tanımlanır — koda/git'e sır girmez. `setup.php`
  kaldırıldı. ⚠️ Token/PAT'ı **buraya (CLAUDE.md) yazma** — güvenlik.
- **GitHub Actions** (`.github/workflows/deploy.yml`): deploy branch push'unda FTP ile cPanel'e.
  `secrets.FTP_PASSWORD` gerekli (yoksa başarısız). `.htaccess`/`config.php`/`backups/` hariç.
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

- **`index.php`** — Dashboard; admin girişinde günlük otomatik yedek (`backups/`, gzip, 30 gün). **Proje → Parsel → Blok → Kot hiyerarşi akordeonu** (dökülen m³, etkin proje `COALESCE(i.proje_id, par.proje_id)`; proje filtresine saygılı).
- **`irsaliyeler.php`** — Alış/İade/Tüm liste. Filtreler, toplu saha/teknik onay, **toplu güncelleme** (`toplu_islem=guncelle`: Proje→Parsel→Blok→Kot kademeli modal + Açıklama; yalnız doldurulan alanlar değişir, `can_edit()`), **whitelist sıralama**
  (sütun başlığına tıkla), CSV/XLSX export.
- **`irsaliye_form.php` / `irsaliye_detay.php`** — ekle/düzenle (durum bazlı yetki) / detay + foto yükleme.
- **`hizli_tarama.php`** (~2900 satır) — **QR+DataMatrix+OCR+AI tarama motoru** (§7). Toplu irsaliye tarama.
- **`raporlar.php`** — Chart.js + ExcelJS (formatlı xlsx) + jsPDF/AutoTable (PDF). `can_view_reports()`.
  **Proje bazlı özet** (U030/U031/U039): etkin proje = `COALESCE(i.proje_id, par.proje_id)` (parsel→proje bağı ile geçmiş kayıtları da kapsar); doughnut grafik + tablo + Excel "Tedarikçi & Beton" sayfasında PROJE bölümü.
- **`zayiat_takip.php`** — **Beton Zayiat Takibi** (`can_view_reports()`): Zayiat = Dökülen (alış−iade, reddedilen hariç) − Teorik Metraj. Teorik metraj **3 seviyede** girilir (kot/blok/imalat kalemi; modal, kademeli seçim), satır bazında **limit %** (varsayılan 5, fore kazık 15 — kalem adında FORE geçerse otomatik önerilir). Sekmeli görünüm + KPI + teorik-vs-dökülen bar grafik + satıra tıklayınca irsaliye popup (irsaliyeye geçişli). **Proje bazında zayiat özeti** (tabloların üstünde): parsel→proje bağıyla tanımlı metraj satırları projeye toplanır (teorik/dökülen/zayiat/oran/limit aşımı) + proje bazlı teorik-vs-dökülen grafik. Tablo `beton_metraj` (runtime + kurulum). Durum: LİMİT AŞIMI (kırmızı) / Yaklaşıyor (>%80 limit) / Devam ediyor (dökülen<teorik) / Normal. |
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
- Diğer: `kullanicilar.php` (admin), `ai_ayarlar.php`, `yedek.php`, `import.php`, `kurulum.php` (seed).

---

## 5. Demir Modülü (`demir/`)

Sidebar: Dashboard · Sevkiyatlar · Siparişler · Tutanaklar · **Tutanak Takip** · **İade Tutanakları** ·
**Taşeron Bakiye** · **Sözleşmeler** · İcmal · Raporlar · Proje Dışı İşler · Tanımlar(Projeler/Çaplar/Tedarikçiler/Taşeronlar).

| Dosya | Amaç |
|---|---|
| `index.php` | **Genel Bakış dashboard**: KPI kartları (gelen/kantar farkı/sevkiyat/kalan sipariş/tutanak/iade/taşeron net) + çap(bar)/proje(doughnut)/aylık(line) grafikleri + son sevkiyatlar + **firma bazlı teslim matrisi** (firma seçici, Proje × Çap, `_firma_teslim.php`) + **sözleşme bazlı çap dağılımı** (sözleşme seçici; çap başına Sipariş/Teslim/Kalan, sozlesme_id bağından). Opsiyonel tablolar try/catch. |
| `_firma_teslim.php` | Ortak: `firma_teslim_matrisi()` — uygulama tutanakları + Tutanak Takip defteri birleşik (tutanak_no dedup, iade netten düşer) → firma→proje→çap matrisi; `ftm_tablo_html()` render. |
| `sozlesmeler.php` | **Sözleşme No paneli**: taşeron sözleşmeleri CRUD (no+taşeron+proje+konu, mükerrer engel) + **ıslak imzalı sözleşme dosyası** (PDF/DOCX/DOC/görsel, sürükle-bırak, tıkla-aç; dosya `uploads/demir_sozlesme/{id}/`, DB'de yalnız URL) + sözleşme bazında teslim toplamı, **bağlı tutanak listesi (imzalı evrak linkleriyle)** ve çap kırılımı. `demir_tutanaklar.sozlesme_id` bağı; `tutanak_form.php`'de Sözleşme No **zorunlu**; tutanak listesi/detay/PDF'te gösterilir. |
| `sevkiyatlar.php` | Sevkiyat listesi; çap toplamı + **kantar farkı** (renkli); filtre; Excel dışa/içe aktar. |
| `sevkiyat_form.php` | Çap bazında İrsaliye+Kantar (canlı fark). **Karekod+AI paneli** (QR→başlık, AI→çap/miktar). |
| `siparisler.php` | Sipariş + **bakiye** (sipariş/gelen/kalan + % ilerleme). |
| `siparis_form.php` | IFS Sipariş No **zorunlu + mükerrer engelli** (eşleşme buna dayanır; benzersiz olmalı yoksa bakiye çift sayar) + **Sözleşme No zorunlu** (`demir_siparisler.sozlesme_id`) + çap bazında sipariş miktarı. `siparisler.php` mevcut mükerrer IFS no'ları uyarı bandında gösterir; `kurulum_demir.php` güvenliyse `ifs_siparis_no` UNIQUE + sevkiyatta düz indeks ekler. |
| `siparis_detay.php` | Çap bazında bakiye + eşleşen sevkiyatlar. |
| `tutanaklar.php` | Teslim tutanağı listesi (tonaj/bağ/evrak durumu). |
| `tutanak_form.php` | **Otomatik no** `{PROJE}-{TASKOD}-NNN` + dinamik kalem satırları. |
| `tutanak_detay.php` | Görüntüleme + **imzalı evrak yükleme** (`uploads/demir_tutanak/{id}/`). |
| `tutanak_pdf.php` | A4 yazdırılabilir tutanak (tarayıcı PDF kaydet). |
| `tutanak_takip.php` | **Tutanak Takip defteri** (Excel "TUTANAK TAKİP" sayfası): firma bazında çap satırı hareket (teslim/iade). İçe aktar (tam yenileme, **evrak korunur**) + filtre + **satır ekle/düzenle/sil (modal)** + **satır bazında imzalı evrak yükleme** (`uploads/demir_tutanak_takip/{id}/`). Tablo `demir_tutanak_takip` (runtime, `evrak_url` kolonu). |
| `iade_tutanaklar.php` | **İade tutanağı** listesi (iade eden/teslim alan, tonaj, evrak). |
| `iade_form.php` | İade eden **zorunlu**, teslim alan **opsiyonel** (boş=depoya iade). Otomatik no `{PROJE}-{IADEEDEN_KOD}-IADE-NNN`. |
| `iade_detay.php` / `iade_pdf.php` | Görüntüleme + imzalı evrak (`uploads/demir_iade/{id}/`) / A4 PDF. |
| `taseron_bakiye.php` | **Net Elinde = Teslim Alınan + Devraldığı − İade Ettiği − Hurda Satışı** (çap bazında açılır; hurda **çaptan bağımsız** düşülür). Teslim/iade kaynakları: uygulama tutanakları **+ Tutanak Takip defteri** (firma adı eşleşir; aynı tutanak_no uygulamada varsa çift sayılmaz). Defterde irsaliye alanı başka firma adıyla başlayan teslimler (ör. YILDIZLAR ← "DENER U030") **Devraldığı** sayılır. Hurda CRUD (modal, otomatik no `{TASKOD}-HRD-NNN`) + kayıt listesi + **imza tutanağı** (`hurda_pdf.php`). Tablo `demir_hurda` (runtime + kurulum). |
| `_iade_ortak.php` | İade ortak: şema garantisi (`iade_semasi_kur`) + `iade_num` + `iade_no_uret`. |
| `icmal.php` / `icmal_pdf.php` | Gelen demir mutabakatı (çap+tedarikçi) + Excel/PDF. **Çap değerine tıklayınca popup** (AJAX `?cap_detay=`): o çaptaki sevkiyatlar→irsaliyeye (`sevkiyat_form.php?id=`), siparişler→(`siparis_detay.php?id=`) **o çap için gelen/kalan bakiye ile**, teslim tutanakları→(`tutanak_detay.php?id=`). |
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

### Beton (`kurulum.php`)
Tanım tabloları (id/ad/aktif): beton_siniflari, katki_listesi, pompa_turleri, firmalar,
kivam_siniflari, parseller. Hiyerarşik: imalat_gruplari→ana_is_kalemleri, bloklar→kotlar.
`tedarikciler`(vkn), `projeler`(kod UNIQUE), `users`(role ENUM), **`irsaliyeler`** (~40 kolon:
tip/durum ENUM, kantar_net_*/kantar_farki, tüm tanım FK'leri, onay alanları, scan_image_url runtime),
`irsaliye_fotolar`, `audit_log` (JSON diff). Seed admin: `tayyar_akbulut`/`admin`.

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
- Beton: `uploads/images/` (scan), `uploads/pdf/`, `uploads/irsaliye_fotolar/`, `uploads/irsaliye_{id}/`.
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
