---
name: run-beton-takip-sistemi
description: Run, start, screenshot or visually check the ERN Beton Takip Sistemi PHP app (all modules — beton, demir, depo, IT, kamp…) locally with MariaDB + php -S + Playwright; take notebook/tablet/phone screenshots, check layout overflow, measure DOM.
---

# ERN Beton Takip Sistemi — çalıştırma ve ekran görüntüsü

Saf PHP + MySQL uygulaması. Bu beceri uygulamayı konteynerde **gerçek MariaDB + `php -S`** ile ayağa
kaldırır, **oturum açmış yönetici** olarak sayfaları Playwright/Chromium ile açar ve notebook · tablet ·
telefon genişliğinde ekran görüntüsü + yatay taşma raporu üretir. Yollar depo köküne göredir.

Dosyalar (`.claude/skills/run-beton-takip-sistemi/`):
- `up.sh <komut…>` — MariaDB + php -S başlatır, ilk seferde 10 veritabanını kurar (kurulum sayfaları) ve
  `seed.sql` örnek verisini yükler, komutu `ERN_URL` ile çalıştırır, çıkışta HER ŞEYİ kapatır ve geri alır.
- `router.php` — php -S yönlendiricisi: her isteğe yönetici oturumu + sabit CSRF `tok` verir
  (`X-Rol: saha_sefi` başlığıyla rol değişir). Giriş ekranı/şifre gerekmez.
- `shot.mjs` — Playwright sürücüsü (ekran görüntüsü, taşma denetimi, JS hatası, DOM ölçümü).
- `seed.sql` — 3 tedarikçi, 7 fatura (beton/pompa/fiyat farkı), 60 irsaliye. Gerçek veri değildir.

## Ön koşullar

Konteynerde hazır: `mariadbd`/`mariadb-install-db`, `php` (pdo_mysql), `node`, genel kurulu Playwright +
Chromium (`/opt/pw-browsers`), `npm` (CDN paketlerini indirmek için). Kurulum gerekmedi.

## Çalıştır (ajan yolu)

```bash
# Tek komut: 3 genişlikte (1366 notebook · 955 tablet · 390 iPhone 13) tam sayfa görüntü
.claude/skills/run-beton-takip-sistemi/up.sh node .claude/skills/run-beton-takip-sistemi/shot.mjs index.php fatura_eslestir.php
```

Çıktı satır başına `✓` ya da `⚠ yatay taşma …px: <öğe> →<sağ kenar>` / `⚠ JS: …` / `⚠ HTTP 500`.
Görüntüler `$ERN_RUN_DIR/shots/<sayfa>-<genişlik>.png` (varsayılan `/tmp/ern-run/shots/`) — **Read ile
bakın**, rapor yalnız sayfa dışına taşmayı yakalar (`.table-responsive` içindeki kaydırmayı değil).

Ortam değişkenleri:

```bash
# Genişlik listesi + yalnız ilk ekran (sabit alt menü / AI düğmesi gerçek yerinde görünür)
ERN_GENISLIK=1920,1440,1366,1280,955,390 ERN_TAM=0 .claude/skills/run-beton-takip-sistemi/up.sh node .claude/skills/run-beton-takip-sistemi/shot.mjs index.php

# Sayfada JS çalıştır, sonucu yazdır (ölçüm / kaydırma). Kaydırmada behavior:"instant" kullanın.
ERN_GENISLIK=1366 ERN_TAM=0 ERN_EVAL='document.getElementById("kayitli-faturalar").scrollIntoView({behavior:"instant"});const t=document.querySelector("#kayitli-faturalar table");return t.offsetWidth+" / "+t.parentElement.clientWidth' \
  .claude/skills/run-beton-takip-sistemi/up.sh node .claude/skills/run-beton-takip-sistemi/shot.mjs fatura_eslestir.php

# Birçok modülde taşma taraması (yalnız sorunlu satırları göster)
.claude/skills/run-beton-takip-sistemi/up.sh node .claude/skills/run-beton-takip-sistemi/shot.mjs index.php irsaliyeler.php raporlar.php fatura_eslestir.php toplu_yukleme.php demir/index.php demir/faturalar.php it/index.php it/cihazlar.php kamp/index.php depo/index.php crm/index.php prekast/index.php | grep -v '  ✓$'

# curl ile sayfa / POST (CSRF alanı her zaman 'tok')
.claude/skills/run-beton-takip-sistemi/up.sh sh -c 'curl -s -o /dev/null -w "%{http_code}\n" $ERN_URL/fatura_eslestir.php'
```

Sıfırdan başlamak (şema + örnek veri yeniden): `rm -rf /tmp/ern-run` (ya da kendi `ERN_RUN_DIR`'iniz).
Portlar `ERN_DB_PORT` (3399) / `ERN_PORT` (8099).

## Gotchas

- **Arka plan süreçleri komutlar arasında ölür** — mysqld, php -S ve test AYNI `up.sh` çağrısında koşmalı;
  `up.sh` bu yüzden "başlat → komut → kapat" biçimindedir. Ayrı bir `up.sh &` işe yaramaz.
- `up.sh` depo köküne **`config.php` yazar** (git-ignored) ve çıkışta siler; önceden bir config varsa yedekleyip
  geri koyar. Elle Ctrl-C ile kesilirse `trap` yine çalışır — yine de `git status`'ta `config.php` kalmadığını görün.
- **Yönetici dashboard'u açınca uygulama `backups/` klasörüne 10 adet `auto_*.sql.gz` yazar** (günlük otomatik
  yedek). `up.sh` bu çalıştırmada oluşanları çıkışta siler; elle php -S kurarsanız siz silin, ASLA commit etmeyin.
- **Tarayıcı CDN'e çıkamaz** (jsdelivr/Google Fonts). `shot.mjs` jsdelivr isteklerini yakalayıp `npm pack` ile
  `$ERN_RUN_DIR/npm/`'e indirdiği paketlerden verir; Google Fonts iptal edilir (yazı tipi farkı önemsiz).
  `chart.js@4.4.4` npm paketinde `chart.umd.min.js` YOK — sürücü `.min` olmayan dosyaya düşer.
- Playwright genel kuruludur: ESM `import 'playwright'` → `ERR_MODULE_NOT_FOUND`; sürücü `createRequire` kullanır.
- Tam sayfa görüntüde `position:fixed` öğeler (telefon alt menüsü, AI düğmesi) sayfanın ORTASINA basılır —
  yanıltıcıdır; gerçek konum için `ERN_TAM=0`.
- `scroll-behavior:smooth` var: `ERN_EVAL` içinde kaydırırken `{behavior:"instant"}` verin (sürücü ayrıca 600 ms bekler).
- Taşma raporu `.table-responsive`, dropdown, modal, offcanvas içini saymaz — tablo sığıyor mu sorusu için
  `ERN_EVAL` ile `table.offsetWidth` ↔ `parentElement.clientWidth` ölçün.
- `.module-switch` (üst modül şeridi) kendi içinde kayar; sığma ölçümü `scrollWidth/clientWidth`.

- ⚠ **`router.php` her isteğe yönetici oturumu verir** — canlıya çıkmamalı: `deploy2.php` `.claude/`'u sunucuya
  yazmaz (varsa siler) ve router yalnız `php -S` (cli-server) altında çalışır. Bu iki kilidi kaldırmayın.

## Sorun giderme

- `ERR_MODULE_NOT_FOUND: playwright` → `createRequire` kullanılmayan bir betik yazdınız (bkz. shot.mjs başı).
- `ENOENT …/npm/<paket>/…min.js` → paket o dosyayı içermiyor; sürücü `.min`siz dosyaya düşer, yoksa isteği iptal eder.
- `page.evaluate: TypeError … null` → `ERN_EVAL` seçicisi o sayfada yok; sürücü hatayı `HATA:` diye yazar, durmaz.
- Kurulum satırlarında 200 dışı kod → `$ERN_RUN_DIR/php.log`; MariaDB açılmadıysa `$ERN_RUN_DIR/mdb.log`.
