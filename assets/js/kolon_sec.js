/**
 * assets/js/kolon_sec.js — Tablo sütunlarını gizle/göster (kullanıcı tercihi).
 *
 * Kullanım:
 *   <table id="t"><thead><tr><th data-kol="kod" data-kol-ad="Cihaz Kodu">…</th>…</tr></thead>…</table>
 *   <div class="dropdown-menu" id="m"></div>
 *   ERN_KOLON.kur({ tablo:'#t', menu:'#m', anahtar:'it_cihazlar', varsayilan:[] });
 *
 * - Yalnız `data-kol` taşıyan başlıklar listelenir; işaretsiz sütun `d-none` alır.
 * - Hücreler BAŞLIK SIRASIYLA eşleşir (td'lere ayrıca işaret koymaya gerek yok);
 *   başlık sayısı tutmayan satırlar (colspan'lı "Kayıt yok" satırı) atlanır.
 * - Tercih CİHAZA ÖZELDİR: localStorage 'ern_kol_<anahtar>' altında GİZLİ sütun
 *   anahtarları dizisi olarak tutulur; depolama kapalıysa (gizli sekme) sessizce
 *   varsayılana düşer.
 * - Son görünür sütun kapatılamaz (boş tablo kalmasın).
 */
(function (w, d) {
  'use strict';

  function oku(anahtar) {
    try {
      var ham = localStorage.getItem(anahtar);
      if (ham === null) return null;
      var v = JSON.parse(ham);
      return Array.isArray(v) ? v : null;
    } catch (e) { return null; }
  }

  function yaz(anahtar, deger) {
    try { localStorage.setItem(anahtar, JSON.stringify(deger)); } catch (e) { /* depolama kapalı */ }
  }

  function kur(o) {
    o = o || {};
    var tablo = typeof o.tablo === 'string' ? d.querySelector(o.tablo) : o.tablo;
    var menu  = typeof o.menu  === 'string' ? d.querySelector(o.menu)  : o.menu;
    if (!tablo || !menu) return null;

    var anahtar   = 'ern_kol_' + (o.anahtar || 'tablo');
    var basliklar = Array.prototype.slice.call(tablo.querySelectorAll('thead > tr > th'));
    var kolonlar  = [];
    basliklar.forEach(function (th, i) {
      var k = th.getAttribute('data-kol');
      if (!k) return;
      kolonlar.push({ k: k, i: i, ad: th.getAttribute('data-kol-ad') || (th.textContent || '').trim() || k });
    });
    if (!kolonlar.length) return null;

    var varsayilan = (o.varsayilan || []).filter(function (k) {
      return kolonlar.some(function (c) { return c.k === k; });
    });
    var kayitli = oku(anahtar);
    var gizli = (kayitli || varsayilan).filter(function (k) {
      return kolonlar.some(function (c) { return c.k === k; });
    });

    function hucreler(i) {
      var out = [];
      Array.prototype.forEach.call(tablo.rows, function (tr) {
        if (tr.cells.length !== basliklar.length) return;   // colspan'lı satırı bozma
        out.push(tr.cells[i]);
      });
      return out;
    }

    var rozet = o.dugme ? (typeof o.dugme === 'string' ? d.querySelector(o.dugme) : o.dugme) : null;

    function uygula() {
      kolonlar.forEach(function (c) {
        var g = gizli.indexOf(c.k) >= 0;
        hucreler(c.i).forEach(function (el) { el.classList.toggle('d-none', g); });
        var kutu = menu.querySelector('input[data-k="' + c.k + '"]');
        if (kutu) kutu.checked = !g;
      });
      if (rozet) {
        rozet.textContent = gizli.length ? String(gizli.length) : '';
        rozet.classList.toggle('d-none', !gizli.length);
      }
    }

    function ayarla(k, goster) {
      var yeni = gizli.filter(function (x) { return x !== k; });
      if (!goster) yeni.push(k);
      if (yeni.length >= kolonlar.length) return false;      // hepsi kapanmasın
      gizli = yeni;
      yaz(anahtar, gizli);
      uygula();
      return true;
    }

    // ── Menü ────────────────────────────────────────────────────────────────
    var h = '<div class="px-3 pt-2 pb-1 small text-muted text-uppercase fw-semibold">Görünecek sütunlar</div>';
    kolonlar.forEach(function (c) {
      h += '<label class="dropdown-item d-flex align-items-center gap-2 mb-0" style="cursor:pointer">'
         + '<input type="checkbox" class="form-check-input mt-0" data-k="' + c.k + '">'
         + '<span>' + c.ad.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span></label>';
    });
    h += '<div class="dropdown-divider my-1"></div>'
       + '<div class="px-3 pb-2 d-flex gap-2">'
       + '<button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-kol-islem="tumu">Tümü</button>'
       + '<button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-kol-islem="varsayilan">Varsayılan</button>'
       + '</div>';
    menu.innerHTML = h;

    menu.addEventListener('click', function (e) {
      var b = e.target.closest('[data-kol-islem]');
      if (!b) return;
      gizli = b.getAttribute('data-kol-islem') === 'tumu' ? [] : varsayilan.slice();
      yaz(anahtar, gizli);
      uygula();
    });
    menu.addEventListener('change', function (e) {
      var kutu = e.target.closest('input[data-k]');
      if (!kutu) return;
      if (!ayarla(kutu.getAttribute('data-k'), kutu.checked)) kutu.checked = true;  // son sütun kapatılamaz
    });

    uygula();
    return { uygula: uygula };
  }

  w.ERN_KOLON = { kur: kur };
})(window, document);
