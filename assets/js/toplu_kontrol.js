/*
 * Toplu Fatura Kontrolü — ortak gönderici (beton fatura_eslestir.php · demir/faturalar.php)
 *
 * Neden var: eski kod dosyaları 15'erli partilerle gönderiyor, bir parti HATA verirse
 * (sunucu boyut sınırı, zaman aşımı, 502) o partideki dosyaları sessizce düşürüyordu.
 * 232 dosya seçilip raporda 202 dosya görünmesi böyleydi (2 parti × 15 dosya kayıp).
 *
 * Şimdi:
 *  1) Partiler hem ADET hem BOYUT sınırına göre kurulur (sunucunun post_max_size /
 *     upload_max_filesize / max_file_uploads değerleri sayfadan gelir).
 *  2) Bir parti başarısız olursa dosyaları TEK TEK yeniden gönderilir.
 *  3) Tek başına da gönderilemeyen dosya SEBEBİYLE rapora yazılır (kontrol_hata) —
 *     rapordaki dosya sayısı her zaman seçilen dosya sayısına eşittir.
 *  4) Başlarken oturumdaki eski yarım rapor temizlenir (kontrol_basla).
 *
 * Kullanım: ERN_TOPLU_KONTROL.kur({form, url, sinir:{adet, parti, dosya}})
 */
(function () {
    'use strict';

    function mb(b) { return (b / 1048576).toLocaleString('tr-TR', {maximumFractionDigits: 1}) + ' MB'; }

    function sebep(durum, metin) {
        if (durum === 413) return 'sunucu dosya boyutu sınırı aşıldı (HTTP 413)';
        if (durum === 419) return 'istek reddedildi (HTTP 419) — dosya PHP post_max_size sınırını aşıyor olabilir ya da oturum zaman aşımına uğradı';
        if (durum === 403) return 'yetki reddedildi (HTTP 403)';
        if (durum === 502 || durum === 503 || durum === 504) return 'sunucu zamanında cevap vermedi (HTTP ' + durum + ')';
        if (durum === 0) return 'bağlantı kurulamadı (' + (metin || 'ağ hatası') + ')';
        if (durum >= 500) return 'sunucu hatası (HTTP ' + durum + ')';
        return 'beklenmeyen cevap (HTTP ' + durum + ')';
    }

    async function gonder(url, fd) {
        var r;
        try { r = await fetch(url, {method: 'POST', body: fd, credentials: 'same-origin'}); }
        catch (e) { return {ok: false, sebep: sebep(0, e && e.message)}; }
        var t = '';
        try { t = await r.text(); } catch (e) {}
        if (!r.ok) return {ok: false, sebep: sebep(r.status)};
        try { return {ok: true, json: JSON.parse(t)}; }
        catch (e) { return {ok: false, sebep: 'sunucu JSON yerine sayfa döndürdü (işlem yarıda kesilmiş olabilir)'}; }
    }

    function temelFd(form, action) {
        var fd = new FormData();
        fd.append('action', action); fd.append('ajax', '1');
        var csrf = form.querySelector('input[name=csrf]'); if (csrf) fd.append('csrf', csrf.value);
        return fd;
    }

    function kur(o) {
        var form = o.form; if (!form) return;
        var sinir = o.sinir || {};
        var ADET  = Math.max(1, Math.min(15, sinir.adet || 15));
        var PARTI = Math.max(1048576, sinir.parti || 6291456);   // bir isteğin azami gövdesi (bayt)
        var DOSYA = sinir.dosya || PARTI;                          // tek dosyanın azami boyutu

        form.addEventListener('submit', async function (e) {
            var files = Array.from(form.querySelector('input[type=file]').files || []);
            if (!files.length) return;
            e.preventDefault();
            var btn = form.querySelector('[id=kontrolBtn]'); if (btn) btn.disabled = true;
            var kutu = form.querySelector('[id=kntKutu]'); if (kutu) kutu.classList.remove('d-none');
            var bar = form.querySelector('[id=kntBar]'), durum = form.querySelector('[id=kntDurum]');
            var toplam = files.length, biten = 0, oz = {islenmis: 0, islenmemis: 0, diger: 0}, hatalar = [];

            function ilerle() {
                var y = Math.round(100 * biten / toplam);
                if (bar) { bar.style.width = y + '%'; bar.textContent = y + '%'; }
                if (durum) durum.textContent = biten + '/' + toplam + ' kontrol edildi — işlenmiş ' + oz.islenmis
                    + ', işlenmemiş ' + oz.islenmemis + (oz.diger ? ', okunamadı ' + oz.diger : '')
                    + (hatalar.length ? ', gönderilemedi ' + hatalar.length : '');
            }

            // Eski yarım raporu temizle, seçilen dosya sayısını bildir
            var fb = temelFd(form, 'kontrol_basla'); fb.append('toplam', String(toplam));
            await gonder(o.url, fb);

            // Partiler: adet + boyut sınırı; sınırı tek başına aşan dosya hiç gönderilmez
            var partiler = [], cur = [], curB = 0;
            files.forEach(function (f) {
                if (f.size > DOSYA) {
                    hatalar.push({ad: f.name, mesaj: 'dosya ' + mb(f.size) + ' — sunucunun tek dosya sınırı ' + mb(DOSYA)});
                    return;
                }
                if (cur.length && (cur.length >= ADET || curB + f.size > PARTI)) { partiler.push(cur); cur = []; curB = 0; }
                cur.push(f); curB += f.size;
            });
            if (cur.length) partiler.push(cur);
            biten = hatalar.length; ilerle();

            async function partiGonder(liste) {
                var fd = temelFd(form, 'fatura_kontrol');
                liste.forEach(function (f) { fd.append('dosyalar[]', f); });
                var c = await gonder(o.url, fd);
                if (!c.ok) return c.sebep;
                var j = c.json || {}, n = typeof j.islenen === 'number' ? j.islenen : 0;
                oz.islenmis += j.islenmis || 0; oz.islenmemis += j.islenmemis || 0; oz.diger += j.diger || 0;
                // Sunucu daha az dosya aldıysa (max_file_uploads) PHP sondakileri düşürür — yeniden göndermek
                // işlenenleri ikiletirdi, eksikler adıyla rapora yazılır.
                for (var x = n; x < liste.length; x++) hatalar.push({ad: liste[x].name, mesaj: 'sunucuya ulaşmadı (tek istekte dosya sayısı sınırı)'});
                return null;
            }

            for (var p = 0; p < partiler.length; p++) {
                var liste = partiler[p];
                var hata = await partiGonder(liste);
                if (hata && liste.length > 1) {
                    // Parti düştü: dosyaları tek tek dene, sorunlu dosya diğerlerini götürmesin
                    for (var k = 0; k < liste.length; k++) {
                        var h1 = await partiGonder([liste[k]]);
                        if (h1) hatalar.push({ad: liste[k].name, mesaj: h1});
                        biten++; ilerle();
                    }
                    continue;
                }
                if (hata) hatalar.push({ad: liste[0].name, mesaj: hata});
                biten += liste.length; ilerle();
            }

            if (hatalar.length) {
                var fh = temelFd(form, 'kontrol_hata');
                fh.append('hatalar', JSON.stringify(hatalar));
                await gonder(o.url, fh);
            }
            location.href = o.url + '?kontrol=1';
        });
    }

    window.ERN_TOPLU_KONTROL = {kur: kur};
})();
