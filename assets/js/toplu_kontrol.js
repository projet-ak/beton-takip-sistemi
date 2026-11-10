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

    // ── Çok sayfalı / taranmış PDF hazırlığı ────────────────────────────────────────
    // Muhasebeden gelen "imzalı fatura" dosyaları çoğu zaman TARANMIŞTIR (metin yok) ve bir dosyada
    // birden çok fatura vardır (her sayfa ayrı fatura). Sunucu bir dosyayı TEK fatura sayar ve metinsiz
    // PDF'te numara bulamaz. Bu yüzden tarayıcıda: (1) çok sayfalı PDF sayfa sayfa ayrı dosyaya bölünür
    // (pdf-lib), (2) metin katmanı olmayan sayfanın GİB e-fatura KAREKODU okunur (pdf.js + jsQR) ve
    // sunucuya ipucu (ipucu[]) olarak gider — fatura no/ETTN/tutar karekottan kesin gelir.
    var LIB = {
        pdfjs:  'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js',
        worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
        jsqr:   'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
        pdflib: 'https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js'
    };
    function yukle(src, var_) {
        if (window[var_]) return Promise.resolve(true);
        return new Promise(function (ok) {
            var sc = document.createElement('script'); sc.src = src;
            sc.onload = function () { ok(!!window[var_]); }; sc.onerror = function () { ok(false); };
            document.head.appendChild(sc);
        });
    }
    var libHazir = null;
    function kutuphaneler() {
        if (!libHazir) libHazir = Promise.all([yukle(LIB.pdfjs, 'pdfjsLib'), yukle(LIB.jsqr, 'jsQR'), yukle(LIB.pdflib, 'PDFLib')])
            .then(function (r) {
                if (window.pdfjsLib && !pdfjsLib.GlobalWorkerOptions.workerSrc) pdfjsLib.GlobalWorkerOptions.workerSrc = LIB.worker;
                return {pdfjs: r[0], jsqr: r[1], pdflib: r[2]};
            });
        return libHazir;
    }
    async function sayfaKarekod(page) {
        if (!window.jsQR) return '';
        // e-fatura karekodu sayfanın sağ üstündedir; önce orası büyük ölçekte, sonra tüm sayfa denenir
        var olcekler = [3, 2];
        for (var o = 0; o < olcekler.length; o++) {
            var vp = page.getViewport({scale: olcekler[o]});
            var c = document.createElement('canvas'); c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height);
            var ctx = c.getContext('2d', {willReadFrequently: true});
            try { await page.render({canvasContext: ctx, viewport: vp}).promise; } catch (e) { return ''; }
            var W = c.width, H = c.height;
            var bolgeler = [[W * 0.5, 0, W * 0.5, H * 0.4], [0, 0, W, H * 0.5], [0, 0, W, H]];
            for (var b = 0; b < bolgeler.length; b++) {
                var x = bolgeler[b][0] | 0, y = bolgeler[b][1] | 0, w = bolgeler[b][2] | 0, h = bolgeler[b][3] | 0;
                var d = ctx.getImageData(x, y, w, h);
                var q = jsQR(d.data, d.width, d.height, {inversionAttempts: 'attemptBoth'});
                if (q && q.data && /ettn|"no"|\bno\s*:/i.test(q.data)) { c.width = c.height = 0; return q.data; }
            }
            c.width = c.height = 0;
        }
        return '';
    }
    // Sayfanın kimliği: metin katmanında "Fatura No: …", yoksa (taranmış sayfa) karekod
    async function sayfaKimlik(page) {
        var metin = '';
        try { var t = await page.getTextContent(); metin = t.items.map(function (x) { return x.str || ''; }).join(' '); } catch (e) {}
        if (metin.replace(/\s+/g, '').length > 20) {
            var m = metin.match(/Fatura\s*No\s*:?\s*([A-Z][A-Z0-9]{2}\d{13})/i);
            return {no: m ? m[1].toUpperCase() : null, qr: '', metinli: true};
        }
        var q = await sayfaKarekod(page);
        var mq = q ? q.match(/"no"\s*:\s*"([^"]+)"/i) || q.match(/\bno\s*:\s*(\S+)/i) : null;
        return {no: mq ? mq[1].toUpperCase() : null, qr: q, metinli: false};
    }
    // files → hazır liste. Bir PDF'te BİRDEN ÇOK FARKLI fatura varsa (muhasebeden gelen taranmış toplu
    // dosya) her fatura ayrı dosyaya bölünür. ⚠ Tek fatura birden çok sayfa sürebilir (uzun irsaliye
    // listesi): numarası olmayan ya da aynı numarayı taşıyan sayfa ÖNCEKİ faturanın devamı sayılır —
    // yalnız FARKLI numaralı sayfada yeni dosya başlar. f._qr = karekod içeriği (sunucuya ipucu).
    async function hazirla(files, bildir) {
        var cikti = [], lib = null, bolunen = 0, karekod = 0;
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            bildir(i, files.length, bolunen, karekod);
            if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { cikti.push(f); continue; }
            lib = lib || await kutuphaneler();
            if (!lib.pdfjs) { cikti.push(f); continue; }
            var buf, doc;
            try { buf = await f.arrayBuffer(); doc = await pdfjsLib.getDocument({data: new Uint8Array(buf.slice(0))}).promise; }
            catch (e) { cikti.push(f); continue; }
            var n = doc.numPages, gruplar = [];
            for (var s = 1; s <= n; s++) {
                var k = {no: null, qr: '', metinli: true};
                try { k = await sayfaKimlik(await doc.getPage(s)); } catch (e) {}
                if (k.qr) karekod++;
                var son = gruplar[gruplar.length - 1];
                if (!son || (k.no && son.no && k.no !== son.no)) gruplar.push({no: k.no, qr: k.qr, sayfalar: [s - 1]});
                else { son.sayfalar.push(s - 1); if (!son.no && k.no) son.no = k.no; if (!son.qr && k.qr) son.qr = k.qr; }
            }
            try { doc.destroy(); } catch (e) {}
            if (gruplar.length <= 1 || !lib.pdflib) {
                if (gruplar[0] && gruplar[0].qr) f._qr = gruplar[0].qr;
                cikti.push(f); continue;
            }
            try {
                var kaynak = await PDFLib.PDFDocument.load(buf, {ignoreEncryption: true});
                var kok = f.name.replace(/\.pdf$/i, ''), parcalar = [];
                for (var g = 0; g < gruplar.length; g++) {
                    var yeni = await PDFLib.PDFDocument.create();
                    var kp = await yeni.copyPages(kaynak, gruplar[g].sayfalar);
                    kp.forEach(function (pg) { yeni.addPage(pg); });
                    var sy = gruplar[g].sayfalar, aralik = (sy[0] + 1) + (sy.length > 1 ? '-' + (sy[sy.length - 1] + 1) : '');
                    var yf = new File([await yeni.save()], kok + ' (sayfa ' + aralik + ' - ' + n + ' sayfadan).pdf', {type: 'application/pdf'});
                    if (gruplar[g].qr) yf._qr = gruplar[g].qr;
                    parcalar.push(yf);
                }
                bolunen++;
                parcalar.forEach(function (x) { cikti.push(x); });
            } catch (e) { cikti.push(f); }
        }
        bildir(files.length, files.length, bolunen, karekod);
        return {liste: cikti, bolunen: bolunen, karekod: karekod};
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
            var secilen = files.length;
            var hz = await hazirla(files, function (i, n, bol, kk) {
                var y = Math.round(100 * i / n);
                if (bar) { bar.style.width = y + '%'; bar.textContent = y + '%'; }
                if (durum) durum.textContent = 'PDF\'ler hazırlanıyor ' + i + '/' + n
                    + (bol ? ' — ' + bol + ' çok sayfalı dosya sayfalara bölündü' : '') + (kk ? ', ' + kk + ' sayfada karekod okundu' : '');
            });
            files = hz.liste;
            var onEk = (files.length !== secilen ? secilen + ' dosya → ' + files.length + ' sayfa/fatura · ' : '');
            var toplam = files.length, biten = 0, oz = {islenmis: 0, islenmemis: 0, diger: 0}, hatalar = [];

            function ilerle() {
                var y = Math.round(100 * biten / toplam);
                if (bar) { bar.style.width = y + '%'; bar.textContent = y + '%'; }
                if (durum) durum.textContent = onEk + biten + '/' + toplam + ' kontrol edildi — işlenmiş ' + oz.islenmis
                    + ', işlenmemiş ' + oz.islenmemis + (oz.diger ? ', okunamadı ' + oz.diger : '')
                    + (hatalar.length ? ', gönderilemedi ' + hatalar.length : '');
            }

            // Eski yarım raporu temizle, seçilen dosya sayısını bildir
            var fb = temelFd(form, 'kontrol_basla'); fb.append('toplam', String(toplam)); fb.append('dosya_adet', String(secilen));
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
                liste.forEach(function (f) { fd.append('dosyalar[]', f); fd.append('ipucu[]', f._qr || ''); });
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
