/* assets/js/secici.js — ARANABİLİR AÇILIR MENÜ (ERN_SECICI)
 *
 * Uzun `<select>` listelerinde (marka · model · işlemci · kategori · lokasyon…) doğru kaydı
 * bulmak kaydırarak aramak demekti; 180 satırlık listede "Intel Core i7" bulmak için menüyü
 * elle taramak gerekiyordu (2026-10-13, kullanıcı: "açılır menülere arama kutusu ekleyelim,
 * hızlıca arar ve ekleriz, yoksa artı butonuna basarız").
 *
 * Kullanım:  <select data-ara …>  → sayfa yüklenince kendiliğinden dönüşür.
 *            ERN_SECICI.kur(sel)  → sonradan eklenen select'i elle dönüştürür.
 *
 * TASARIM KURALLARI
 *  • **Native `<select>` KALIR** ve tek doğru kaynaktır: form gönderimi, `sel.value` okuyan
 *    mevcut JS (kategori'ye göre alan gizleme, `.dolu` imi, "+" ile tanım ekleme) ve `required`
 *    doğrulaması olduğu gibi çalışır. Panelden seçim `sel.value`yu yazar ve `change` yayar.
 *  • ⚠ Select `display:none` YAPILMAZ — `required` bir alanda tarayıcı "odaklanamıyorum" deyip
 *    formu kilitler (cihaz_detay'daki `personel_id` tuzağının aynısı). Görünmezlik `opacity:0`
 *    + `pointer-events:none` ile sağlanır, alan odaklanabilir kalır ve doğrulama balonu
 *    düğmenin üstünde çıkar.
 *  • **JS kapalıysa hiçbir şey olmaz**: select olduğu gibi görünür (dönüşüm yalnız burada olur).
 *  • Seçenekler panel HER AÇILDIĞINDA select'ten yeniden okunur — "+" ile eklenen yeni tanım
 *    listede anında görünür, ayrıca haber vermeye gerek yok.
 *  • Süzme **SQL/LIKE değil** burada, Türkçe harf duyarsız (`ara()`): "ismail" → "İSMAİL",
 *    "ampul" → "Ampül"; **kelime sırası serbest** ("i7 intel" → "Intel Core i7").
 *    Aynı gerekçe ve desen: depo `dp_kalem_ara`, IT `it_personel_ara`.
 *  • Renkler Bootstrap değişkenlerinden okunur → açık/koyu/yüksek kontrast ve renk paleti
 *    kendiliğinden izlenir (sabit hex yazma).
 */
(function () {
    'use strict';

    var ESIK_VARSAYILAN = 8;   // bu sayıdan az seçenekte arama kutusu çizilmez (3 satırda arama anlamsız)

    /* ── Türkçe harf katlama: 'İ'.toLowerCase() JS'te 'i̇' (i + birleşen nokta) verir,
          bu yüzden harfler ÖNCE elle eşlenir. */
    function norm(s) {
        s = String(s == null ? '' : s);
        s = s.replace(/[İIıi]/g, 'i').replace(/[Şş]/g, 's').replace(/[Ğğ]/g, 'g')
             .replace(/[Üü]/g, 'u').replace(/[Öö]/g, 'o').replace(/[Çç]/g, 'c');
        s = s.toLowerCase();
        try { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { /* eski tarayıcı */ }
        return s.replace(/\s+/g, ' ').trim();
    }

    /* Her kelime metinde geçmeli (sıra serbest); puan: ad başı > kelime başı > ad içi. */
    function puan(metin, kelimeler) {
        var n = norm(metin), p = 0;
        for (var i = 0; i < kelimeler.length; i++) {
            var ix = n.indexOf(kelimeler[i]);
            if (ix < 0) return -1;
            p += ix === 0 ? 3 : (n.charAt(ix - 1) === ' ' ? 2 : 1);
        }
        return p;
    }

    var stilVar = false;
    function stilKur() {
        if (stilVar || document.getElementById('ern-sec-stil')) { stilVar = true; return; }
        stilVar = true;
        var s = document.createElement('style');
        s.id = 'ern-sec-stil';
        s.textContent = [
            '.ern-sec{position:relative}',
            /* input-group içinde "+" düğmesiyle bitişik dursun (Bootstrap kural'ı .form-select'e
               bakar, sarmalayıcımıza değil — köşe yarıçapları burada tekrarlanır) */
            '.input-group>.ern-sec{flex:1 1 auto;width:1%;min-width:0}',
            '.input-group>.ern-sec:not(:last-child)>.ern-sec-dugme{border-top-right-radius:0;border-bottom-right-radius:0}',
            '.input-group>.ern-sec:not(:first-child)>.ern-sec-dugme{border-top-left-radius:0;border-bottom-left-radius:0;margin-left:-1px}',
            '.ern-sec-gizli{position:absolute;left:0;top:0;width:100%;height:100%;opacity:0;pointer-events:none}',
            '.ern-sec-dugme{width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.ern-sec-dugme.bos{color:var(--bs-secondary-color,#6c757d)}',
            '.ern-sec-panel{position:absolute;z-index:1056;top:calc(100% + 2px);left:0;min-width:100%;',
            'max-width:32rem;background:var(--bs-body-bg,#fff);border:1px solid var(--bs-border-color,#dee2e6);',
            'border-radius:.5rem;box-shadow:0 .5rem 1.25rem rgba(0,0,0,.18);padding:.4rem;display:none}',
            '.ern-sec-panel.acik{display:block}',
            '.ern-sec-panel.ust{top:auto;bottom:calc(100% + 2px)}',
            '.ern-sec-ara{margin-bottom:.35rem}',
            '.ern-sec-liste{max-height:16rem;overflow-y:auto}',
            '.ern-sec-oge{display:flex;gap:.5rem;align-items:center;justify-content:space-between;',
            'padding:.3rem .5rem;border-radius:.35rem;cursor:pointer;font-size:.9rem}',
            '.ern-sec-oge:hover,.ern-sec-oge.etkin{background:rgba(var(--bs-primary-rgb,13,110,253),.14)}',
            '.ern-sec-oge.secili{font-weight:600}',
            '.ern-sec-oge .ad{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.ern-sec-oge .grup{flex:0 0 auto;font-size:.72rem;opacity:.65}',
            '.ern-sec-bos{padding:.45rem .5rem;font-size:.85rem;color:var(--bs-secondary-color,#6c757d)}',
            '@media (max-width:575.98px){.ern-sec-panel{max-width:none}}'
        ].join('');
        document.head.appendChild(s);
    }

    var acikOlan = null;                       // aynı anda tek panel açık kalır

    function kur(sel) {
        if (!sel || sel.tagName !== 'SELECT' || sel.__ernSec) return null;
        stilKur();
        sel.__ernSec = true;

        var sarmal = document.createElement('div');
        sarmal.className = 'ern-sec';
        sel.parentNode.insertBefore(sarmal, sel);
        sarmal.appendChild(sel);
        sel.classList.add('ern-sec-gizli');
        sel.setAttribute('tabindex', '-1');

        var dugme = document.createElement('button');
        dugme.type = 'button';
        dugme.className = 'form-select text-start ern-sec-dugme';
        dugme.setAttribute('aria-haspopup', 'listbox');
        dugme.setAttribute('aria-expanded', 'false');
        if (sel.disabled) dugme.disabled = true;
        sarmal.appendChild(dugme);

        var panel = document.createElement('div');
        panel.className = 'ern-sec-panel';
        panel.setAttribute('role', 'listbox');
        var araKutu = document.createElement('div');
        araKutu.className = 'ern-sec-ara';
        var ara = document.createElement('input');
        ara.type = 'text';
        ara.className = 'form-control form-control-sm';
        ara.placeholder = sel.getAttribute('data-ara-ipucu') || 'Ara…';
        ara.setAttribute('autocomplete', 'off');
        ara.setAttribute('aria-label', 'Listede ara');
        araKutu.appendChild(ara);
        var liste = document.createElement('div');
        liste.className = 'ern-sec-liste';
        panel.appendChild(araKutu); panel.appendChild(liste);
        sarmal.appendChild(panel);

        var ogeler = [], gorunen = [], etkin = -1;

        function etiketYenile() {
            var o = sel.options[sel.selectedIndex] || null;
            var t = o ? o.text : '';
            dugme.textContent = t || '— seçilmedi —';
            dugme.classList.toggle('bos', !sel.value);
            dugme.title = t;
        }

        function oku() {
            ogeler = [];
            for (var i = 0; i < sel.options.length; i++) {
                var o = sel.options[i], ebeveyn = o.parentNode;
                ogeler.push({
                    deger: o.value, metin: o.text, ix: i,
                    grup: (ebeveyn && ebeveyn.tagName === 'OPTGROUP') ? (ebeveyn.label || '') : ''
                });
            }
        }

        function ciz(q) {
            var kelimeler = norm(q).split(' ').filter(Boolean);
            var bulunan = [];
            for (var i = 0; i < ogeler.length; i++) {
                var o = ogeler[i];
                // "— seçilmedi —" yalnız arama boşken durur (seçimi temizlemek için)
                if (o.deger === '' && kelimeler.length) continue;
                var p = kelimeler.length ? puan(o.metin + ' ' + o.grup, kelimeler) : 0;
                if (p < 0) continue;
                bulunan.push({ o: o, p: p });
            }
            bulunan.sort(function (a, b) { return (b.p - a.p) || (a.o.ix - b.o.ix); });
            gorunen = bulunan.map(function (x) { return x.o; });

            liste.textContent = '';
            if (!gorunen.length) {
                var bos = document.createElement('div');
                bos.className = 'ern-sec-bos';
                bos.textContent = 'Eşleşen kayıt yok.';
                liste.appendChild(bos);
                etkin = -1;
                return;
            }
            for (var j = 0; j < gorunen.length; j++) {
                var g = gorunen[j];
                var d = document.createElement('div');
                d.className = 'ern-sec-oge' + (g.deger === sel.value ? ' secili' : '');
                d.setAttribute('role', 'option');
                d.setAttribute('aria-selected', g.deger === sel.value ? 'true' : 'false');
                d.dataset.ix = String(j);
                var ad = document.createElement('span');
                ad.className = 'ad';
                ad.textContent = g.metin;
                d.appendChild(ad);
                if (g.grup) {
                    var gr = document.createElement('span');
                    gr.className = 'grup';
                    gr.textContent = g.grup;
                    d.appendChild(gr);
                }
                liste.appendChild(d);
            }
            // Seçili kayıt varsa onunla, yoksa ilk satırla başla
            etkin = 0;
            for (var k = 0; k < gorunen.length; k++) if (gorunen[k].deger === sel.value) { etkin = k; break; }
            etkinYaz(false);
        }

        function etkinYaz(kaydir) {
            var satirlar = liste.querySelectorAll('.ern-sec-oge');
            for (var i = 0; i < satirlar.length; i++) satirlar[i].classList.toggle('etkin', i === etkin);
            if (kaydir !== false && etkin >= 0 && satirlar[etkin] && satirlar[etkin].scrollIntoView)
                satirlar[etkin].scrollIntoView({ block: 'nearest' });
        }

        function ac(ilkHarf) {
            if (dugme.disabled) return;
            if (acikOlan && acikOlan !== kapat) acikOlan();
            acikOlan = kapat;
            oku();
            // Arama kutusu kısa listede gereksiz yer kaplar
            var esik = parseInt(sel.getAttribute('data-ara-esik') || ESIK_VARSAYILAN, 10);
            araKutu.style.display = ogeler.length >= esik ? '' : 'none';
            ara.value = ilkHarf || '';
            panel.classList.add('acik');
            dugme.setAttribute('aria-expanded', 'true');
            // Alt kenara sığmıyorsa yukarı aç
            var kutu = dugme.getBoundingClientRect();
            panel.classList.toggle('ust', kutu.bottom + 300 > (window.innerHeight || 0) && kutu.top > 300);
            ciz(ara.value);
            if (araKutu.style.display !== 'none') ara.focus();
        }

        function kapat() {
            if (!panel.classList.contains('acik')) return;
            panel.classList.remove('acik');
            dugme.setAttribute('aria-expanded', 'false');
            if (acikOlan === kapat) acikOlan = null;
        }

        function sec(i) {
            if (i < 0 || i >= gorunen.length) return;
            sel.value = gorunen[i].deger;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            kapat();
            dugme.focus();
        }

        dugme.addEventListener('click', function () {
            panel.classList.contains('acik') ? kapat() : ac('');
        });
        dugme.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault(); ac('');
            } else if (e.key && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault(); ac(e.key);   // yazmaya başlayınca panel açılır ve harf aramaya düşer
            }
        });
        ara.addEventListener('input', function () { ciz(ara.value); });
        ara.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (etkin < gorunen.length - 1) { etkin++; etkinYaz(); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (etkin > 0) { etkin--; etkinYaz(); } }
            else if (e.key === 'Home') { e.preventDefault(); etkin = 0; etkinYaz(); }
            else if (e.key === 'End') { e.preventDefault(); etkin = gorunen.length - 1; etkinYaz(); }
            else if (e.key === 'Enter') { e.preventDefault(); sec(etkin); }
            else if (e.key === 'Escape') { e.preventDefault(); kapat(); dugme.focus(); }
            else if (e.key === 'Tab') { kapat(); }
        });
        liste.addEventListener('click', function (e) {
            var d = e.target.closest ? e.target.closest('.ern-sec-oge') : null;
            if (d) sec(parseInt(d.dataset.ix, 10));
        });
        liste.addEventListener('mousemove', function (e) {
            var d = e.target.closest ? e.target.closest('.ern-sec-oge') : null;
            if (d) { etkin = parseInt(d.dataset.ix, 10); etkinYaz(false); }
        });
        // Dışarıdan gelen değişiklik de etikete yansır ("+" ile tanım ekleme `change` yayar)
        sel.addEventListener('change', etiketYenile);

        // Etiketin kendisi de paneli açar (label[for] gizli select'e odaklanır, görünür bir şey olmazdı).
        // ⚠ Olay işaretlenir: aşağıdaki "dışarı tıklayınca kapat" dinleyicisi aynı tıklamada
        // paneli hemen kapatıyordu (etiket `.ern-sec` dışında kalıyor).
        var etiket = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
        if (etiket) etiket.addEventListener('click', function (e) { e.preventDefault(); e.__ernSec = true; ac(''); });

        etiketYenile();
        sel.__ernSecAc = ac;
        return { ac: ac, kapat: kapat, yenile: etiketYenile };
    }

    document.addEventListener('click', function (e) {
        if (!acikOlan || e.__ernSec) return;
        if (e.target.closest && e.target.closest('.ern-sec')) return;
        acikOlan();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && acikOlan) acikOlan();
    });

    function kurHepsi(kok) {
        var n = (kok || document).querySelectorAll('select[data-ara]');
        for (var i = 0; i < n.length; i++) kur(n[i]);
        return n.length;
    }

    window.ERN_SECICI = { kur: kur, kurHepsi: kurHepsi, norm: norm, puan: puan };

    if (document.readyState === 'loading')
        document.addEventListener('DOMContentLoaded', function () { kurHepsi(); });
    else kurHepsi();
})();
