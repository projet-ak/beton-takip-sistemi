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
- Geliştirici: **Tayyar Akbulut**. Sürüm: v3.0. Canlı: `https://takbulut.com/beton/`.

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
- **`deploy2.php`** (tercih edilen): tarayıcıdan `deploy2.php?token=...&ghpat=...` açılır;
  GitHub'dan deploy branch zip'ini çekip `__DIR__`'e açar. `config.php`, `deploy*.php`,
  `setup.php`, `backups/` **korumalıdır** (üzerine yazılmaz). Token dosya içinde (`DEPLOY_TOKEN`).
  ⚠️ Token/PAT'ı **buraya (CLAUDE.md) yazma** — güvenlik.
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

- **`index.php`** — Dashboard; admin girişinde günlük otomatik yedek (`backups/`, gzip, 30 gün).
- **`irsaliyeler.php`** — Alış/İade/Tüm liste. Filtreler, toplu saha/teknik onay, **whitelist sıralama**
  (sütun başlığına tıkla), CSV/XLSX export.
- **`irsaliye_form.php` / `irsaliye_detay.php`** — ekle/düzenle (durum bazlı yetki) / detay + foto yükleme.
- **`hizli_tarama.php`** (~2900 satır) — **QR+DataMatrix+OCR+AI tarama motoru** (§7). Toplu irsaliye tarama.
- **`raporlar.php`** — Chart.js + ExcelJS (formatlı xlsx) + jsPDF/AutoTable (PDF). `can_view_reports()`.
- **`login.php`** — iki beyaz ERN logolu koyu yeşil panel + dalga animasyonu + geliştirici kredisi.
  QR/AI beton = GİB e-İrsaliye QR (JSON) + KGS/THBB DataMatrix (E1) + tesseract + AI.
- **Tanım sayfaları** (`can_manage_definitions()`): beton_siniflari, katki_listesi, pompa_turleri,
  kivam_siniflari, parseller→bloklar→kotlar, imalat_gruplari→ana_is_kalemleri, firmalar, tedarikciler.
  Desen: CRUD + mükerrer engel (UPPER) + kullanımda ise silme engeli + `tanim_modal.php`.
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
| `siparis_form.php` | IFS Sipariş No **zorunlu** (eşleşme buna dayanır) + **Sözleşme No zorunlu** (`demir_siparisler.sozlesme_id`) + çap bazında sipariş miktarı. Sözleşme no liste+detayda gösterilir. |
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
| `raporlar.php` | Chart.js + **ExcelJS** (Özet/Aylık/Tedarikçi/Detay) + PDF (yazdırma penceresi). |
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

*Bu dosya proje büyüdükçe güncellenmeli. Yeni modül/sayfa eklerken ilgili bölüme işle.*
