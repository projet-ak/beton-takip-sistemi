<?php
/**
 * tanitim.php — Şantiye İş Takip Sistemi tanıtım / vitrin sayfası (herkese açık)
 *
 * Login markasıyla aynı dil: koyu yeşil ERN paleti, Holding + Taahhüt beyaz
 * logoları, Batı Yakası rozeti, dalga animasyonu, Outfit yazı tipi.
 * Canlı sayaçlar DB'den yuvarlanarak çekilir (DB yoksa etkileyici varsayılanlar).
 */
require_once __DIR__ . '/includes/functions.php';

// ── Canlı sayaçlar (yaklaşık, aşağı yuvarlı — detay sızdırmaz) ───────────────
$say = ['irsaliye' => 1900, 'm3' => 18000, 'demir_ton' => 3400, 'hareket' => 4400, 'fatura' => 35];
try {
    if (!file_exists(__DIR__ . '/config.php')) throw new RuntimeException('kurulumsuz');   // db.php redirect+exit yapar, try yakalayamaz
    require_once __DIR__ . '/includes/db.php';
    $say['irsaliye'] = (int)(floor($pdo->query("SELECT COUNT(*) FROM irsaliyeler")->fetchColumn() / 100) * 100);
    $say['m3']       = (int)(floor($pdo->query("SELECT COALESCE(SUM(miktar),0) FROM irsaliyeler WHERE tip='alis' AND durum<>'reddedildi'")->fetchColumn() / 500) * 500);
    try { $say['fatura'] = (int)$pdo->query("SELECT COUNT(*) FROM faturalar")->fetchColumn(); } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/includes/db_demir.php';
        $say['demir_ton'] = (int)(floor($pdoDemir->query("SELECT COALESCE(SUM(irsaliye_miktar),0) FROM demir_sevkiyat_kalemleri")->fetchColumn() / 100) * 100);
    } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/includes/db_depo.php';
        $say['hareket'] = (int)(floor($pdoDepo->query("SELECT COUNT(*) FROM depo_hareketler")->fetchColumn() / 100) * 100);
    } catch (Throwable $e) {}
} catch (Throwable $e) { /* kurulumsuz ortamda varsayılanlar kalır */ }

$LOGO_TAAHHUT = 'uploads/logo/' . rawurlencode('ERN Taahhut_Logo_Beyaz.png');
$LOGO_HOLDING = 'uploads/logo/' . rawurlencode('ERN Holding_Logo_Beyaz.png');
$fmt0 = fn($n) => number_format((float)$n, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Şantiye İş Takip Sistemi — Batı Yakası Projesi | ERN Taahhüt</title>
<meta name="description" content="ERN Taahhüt Batı Yakası Projesi dijital şantiye yönetimi: beton, demir, seramik, depo, akaryakıt ve saha takibi tek sistemde. QR + yapay zekâ destekli irsaliye okuma, fatura mutabakatı, canlı stok.">
<link rel="icon" type="image/png" href="https://ern.com.tr/favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap">
<style>
:root {
    --ern:       #00584E;
    --ern-dark:  #003D35;
    --ern-light: #007A6A;
    --ern-teal:  #00C9B1;
    --ern-gold:  #C9A84C;
    --ink:       #0D2E28;
}
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { font-family:'Outfit',system-ui,sans-serif; margin:0; color:#e8f4f1; background:var(--ern-dark); }

/* ── HERO ─────────────────────────────────────────────── */
.hero {
    position:relative; overflow:hidden; min-height:92vh;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    text-align:center; padding:48px 20px 120px;
    background:linear-gradient(150deg, var(--ern-dark) 0%, var(--ern) 55%, var(--ern-light) 100%);
}
.hero::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(ellipse at 70% 20%, rgba(0,201,177,.16), transparent 55%),
               radial-gradient(ellipse at 15% 85%, rgba(201,168,76,.12), transparent 50%);
    animation:nefes 9s ease-in-out infinite alternate;
}
@keyframes nefes { from{opacity:.65} to{opacity:1} }
.hero > * { position:relative; }
.logolar { display:flex; align-items:center; gap:34px; margin-bottom:34px; flex-wrap:wrap; justify-content:center; animation:inis .9s ease both; }
.logolar img { height:64px; filter:drop-shadow(0 4px 18px rgba(0,0,0,.35)); }
.logolar .ayrac { width:1px; height:52px; background:rgba(255,255,255,.28); }
.proje-rozet {
    display:inline-flex; align-items:center; gap:10px;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18);
    border-radius:999px; padding:8px 22px; margin-bottom:26px;
    font-weight:700; letter-spacing:.16em; text-transform:uppercase; font-size:.8rem;
    color:var(--ern-teal); animation:inis .9s .15s ease both;
}
.proje-rozet i { color:var(--ern-gold); }
h1.baslik {
    margin:0 0 14px; font-size:clamp(2.1rem, 5.4vw, 4rem); font-weight:900; line-height:1.08;
    background:linear-gradient(90deg, #fff 20%, var(--ern-teal) 80%);
    -webkit-background-clip:text; background-clip:text; color:transparent;
    animation:inis .9s .25s ease both;
}
.alt-baslik { margin:0 auto 38px; max-width:640px; font-size:clamp(1rem,2.2vw,1.25rem); font-weight:300; color:rgba(232,244,241,.85); animation:inis .9s .35s ease both; }
.alt-baslik strong { color:var(--ern-gold); font-weight:600; }
.cta { display:flex; gap:14px; flex-wrap:wrap; justify-content:center; animation:inis .9s .45s ease both; }
.btn-ana, .btn-ikincil {
    display:inline-flex; align-items:center; gap:9px; text-decoration:none;
    padding:14px 34px; border-radius:14px; font-weight:700; font-size:1.02rem; transition:.25s;
}
.btn-ana { background:linear-gradient(135deg, var(--ern-teal), #58e0cd); color:var(--ink); box-shadow:0 8px 30px rgba(0,201,177,.35); }
.btn-ana:hover { transform:translateY(-3px); box-shadow:0 14px 40px rgba(0,201,177,.5); }
.btn-ikincil { background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.22); }
.btn-ikincil:hover { background:rgba(255,255,255,.16); }
@keyframes inis { from{opacity:0; transform:translateY(22px)} to{opacity:1; transform:none} }

/* dalga */
.dalga { position:absolute; bottom:-2px; left:0; right:0; height:110px; }
.dalga svg { width:200%; height:100%; animation:dalgalan 14s linear infinite; }
@keyframes dalgalan { from{transform:translateX(0)} to{transform:translateX(-50%)} }

/* ── SAYAÇLAR ─────────────────────────────────────────── */
.sayaclar { background:#fff; color:var(--ink); padding:52px 20px; }
.sayac-kutu { max-width:1080px; margin:-130px auto 0; position:relative; z-index:2;
    background:#fff; border-radius:22px; box-shadow:0 24px 70px rgba(0,40,35,.28);
    display:grid; grid-template-columns:repeat(auto-fit, minmax(170px,1fr));
    gap:8px; padding:34px 26px; text-align:center; }
.sayac .deger { font-size:clamp(1.7rem,3.4vw,2.5rem); font-weight:900; color:var(--ern); }
.sayac .deger small { font-size:.55em; font-weight:700; color:var(--ern-light); }
.sayac .etiket { font-size:.82rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:#5b7570; margin-top:2px; }

/* ── BÖLÜMLER ─────────────────────────────────────────── */
.bolum { padding:76px 20px; }
.bolum.acik { background:#f4faf8; color:var(--ink); }
.bolum.koyu { background:linear-gradient(160deg, var(--ern-dark), var(--ern)); }
.icerik { max-width:1080px; margin:0 auto; }
.bolum-baslik { text-align:center; margin-bottom:46px; }
.bolum-baslik .ust { display:inline-block; font-size:.78rem; font-weight:800; letter-spacing:.2em; text-transform:uppercase; color:var(--ern-teal); margin-bottom:10px; }
.bolum-baslik h2 { margin:0; font-size:clamp(1.6rem,3.4vw,2.4rem); font-weight:800; }
.bolum.acik .bolum-baslik h2 { color:var(--ern-dark); }

/* modül kartları */
.moduller { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px,1fr)); gap:20px; }
.modul {
    background:#fff; border-radius:18px; padding:26px;
    border:1px solid #e2efe9; box-shadow:0 6px 24px rgba(0,60,50,.06);
    transition:.3s; position:relative; overflow:hidden;
}
.modul:hover { transform:translateY(-6px); box-shadow:0 18px 44px rgba(0,60,50,.14); }
.modul::after { content:''; position:absolute; top:0; left:0; right:0; height:4px;
    background:linear-gradient(90deg, var(--ern), var(--ern-teal)); }
.modul .mi { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center;
    font-size:1.5rem; color:#fff; background:linear-gradient(135deg, var(--ern), var(--ern-light)); margin-bottom:16px; }
.modul h3 { margin:0 0 8px; font-size:1.15rem; font-weight:800; color:var(--ern-dark); }
.modul p { margin:0; font-size:.92rem; line-height:1.55; color:#4b625d; }
.modul .etiketler { margin-top:14px; display:flex; flex-wrap:wrap; gap:6px; }
.modul .etiketler span { font-size:.7rem; font-weight:700; padding:4px 10px; border-radius:999px;
    background:#e7f6f2; color:var(--ern); }

/* özellikler (koyu bölüm) */
.ozellikler { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px,1fr)); gap:18px; }
.ozellik { background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
    border-radius:16px; padding:22px; transition:.3s; }
.ozellik:hover { background:rgba(255,255,255,.11); transform:translateY(-4px); }
.ozellik i { font-size:1.6rem; color:var(--ern-teal); }
.ozellik.altin i { color:var(--ern-gold); }
.ozellik h4 { margin:12px 0 6px; font-size:1.02rem; font-weight:700; color:#fff; }
.ozellik p { margin:0; font-size:.86rem; line-height:1.5; color:rgba(232,244,241,.72); }

/* akış */
.akis { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:18px; counter-reset:adim; }
.adim { background:#fff; border-radius:16px; padding:26px 22px; text-align:center; border:1px solid #e2efe9; }
.adim::before { counter-increment:adim; content:counter(adim);
    display:inline-flex; width:44px; height:44px; border-radius:50%; align-items:center; justify-content:center;
    font-weight:900; font-size:1.2rem; color:#fff; background:linear-gradient(135deg, var(--ern-gold), #dcc27a); margin-bottom:14px; }
.adim h4 { margin:0 0 6px; font-weight:800; color:var(--ern-dark); }
.adim p { margin:0; font-size:.88rem; color:#4b625d; }

/* alt CTA + footer */
.son-cta { text-align:center; padding:80px 20px; background:linear-gradient(150deg, var(--ern), var(--ern-light)); }
.son-cta h2 { margin:0 0 12px; font-size:clamp(1.5rem,3.2vw,2.2rem); font-weight:900; }
.son-cta p { margin:0 0 30px; color:rgba(232,244,241,.8); }
footer { background:var(--ern-dark); text-align:center; padding:30px 20px; font-size:.82rem; color:rgba(232,244,241,.55); }
footer img { height:30px; opacity:.85; margin:0 10px 12px; }
footer .gelistirici { margin-top:8px; color:rgba(232,244,241,.4); }
</style>
</head>
<body>

<!-- HERO -->
<section class="hero">
    <div class="logolar">
        <img src="<?= h($LOGO_HOLDING) ?>" alt="ERN Holding" onerror="this.style.display='none'">
        <div class="ayrac"></div>
        <img src="<?= h($LOGO_TAAHHUT) ?>" alt="ERN Taahhüt" onerror="this.style.display='none'">
    </div>
    <div class="proje-rozet"><i class="bi bi-geo-alt-fill"></i> Batı Yakası Projesi — Kartal / İstanbul</div>
    <h1 class="baslik">Şantiye İş Takip Sistemi</h1>
    <p class="alt-baslik">
        Beton dökümünden demir sevkiyatına, depo stoklarından akaryakıta —
        <strong>tüm şantiye tek ekranda</strong>. Karekod + yapay zekâ destekli belge okuma,
        canlı stok ve otomatik mutabakat ile kağıt devri kapandı.
    </p>
    <div class="cta">
        <a class="btn-ana" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Sisteme Giriş</a>
        <a class="btn-ikincil" href="#moduller"><i class="bi bi-grid-1x2"></i> Modülleri Keşfet</a>
    </div>
    <div class="dalga">
        <svg viewBox="0 0 1440 110" preserveAspectRatio="none">
            <path d="M0,60 C240,110 480,10 720,55 C960,100 1200,20 1440,60 L1440,110 L0,110 Z" fill="#ffffff"/>
            <path d="M1440,60 C1680,110 1920,10 2160,55 C2400,100 2640,20 2880,60 L2880,110 L1440,110 Z" fill="#ffffff"/>
        </svg>
    </div>
</section>

<!-- CANLI SAYAÇLAR -->
<section class="sayaclar">
    <div class="sayac-kutu">
        <div class="sayac"><div class="deger" data-hedef="<?= (int)$say['irsaliye'] ?>">0</div><div class="etiket">İşlenen İrsaliye</div></div>
        <div class="sayac"><div class="deger" data-hedef="<?= (int)$say['m3'] ?>" data-ek=" m³">0</div><div class="etiket">Dökülen Beton</div></div>
        <div class="sayac"><div class="deger" data-hedef="<?= (int)$say['demir_ton'] ?>" data-ek=" t">0</div><div class="etiket">Takip Edilen Demir</div></div>
        <div class="sayac"><div class="deger" data-hedef="<?= (int)$say['hareket'] ?>">0</div><div class="etiket">Depo Hareketi</div></div>
        <div class="sayac"><div class="deger" data-hedef="6">0</div><div class="etiket">Entegre Modül</div></div>
    </div>
</section>

<!-- MODÜLLER -->
<section class="bolum acik" id="moduller">
    <div class="icerik">
        <div class="bolum-baslik">
            <span class="ust">Tek Sistem · Altı Modül</span>
            <h2>Şantiyenin Her Kalemi Kayıt Altında</h2>
        </div>
        <div class="moduller">
            <div class="modul"><div class="mi"><i class="bi bi-building"></i></div>
                <h3>Beton Takip</h3>
                <p>Hazır beton irsaliyeleri karekod + yapay zekâ ile saniyeler içinde okunur; iki aşamalı saha/teknik onayından geçer, zayiat limitleri canlı izlenir.</p>
                <div class="etiketler"><span>QR + AI Tarama</span><span>Fatura Mutabakatı</span><span>Zayiat Takibi</span></div></div>
            <div class="modul"><div class="mi"><i class="bi bi-bricks"></i></div>
                <h3>Demir Takip</h3>
                <p>Sipariş → sevkiyat → kantar → taşerona teslim zinciri çap bazında; IFS talepleriyle saha girişleri otomatik mutabakatlanır, taşeron bakiyesi net görünür.</p>
                <div class="etiketler"><span>IFS Mutabakatı</span><span>Taşeron Bakiye</span><span>İmzalı Tutanak</span></div></div>
            <div class="modul"><div class="mi"><i class="bi bi-grid-1x2"></i></div>
                <h3>Seramik Takip</h3>
                <p>Ambar giriş/çıkışları m² bazında; malzeme bazlı canlı stok, palet kayıtları ve teorik metraja karşı fire (zayiat) kontrolü.</p>
                <div class="etiketler"><span>Canlı Stok</span><span>Fire Kontrolü</span></div></div>
            <div class="modul"><div class="mi"><i class="bi bi-box-seam"></i></div>
                <h3>Depo Takip</h3>
                <p>Demirbaş, sarf ve el aletleri tek çatıda: mali değer, zimmet, günlük giriş/çıkış defteri, hurdaya ayırma tutanakları ve tükenen stok uyarıları.</p>
                <div class="etiketler"><span>Zimmet</span><span>Hurda Tutanağı</span><span>Mali Değer</span></div></div>
            <div class="modul"><div class="mi"><i class="bi bi-fuel-pump"></i></div>
                <h3>Akaryakıt Takip</h3>
                <p>Mazot stoğu dönem zinciriyle litre litre izlenir; araç/makine bazında tüketim, günlük çıkış fişleri ve imzalı evrak arşivi.</p>
                <div class="etiketler"><span>Stok Zinciri</span><span>Çıkış Fişi</span></div></div>
            <div class="modul"><div class="mi"><i class="bi bi-chat-dots"></i></div>
                <h3>Saha Takip</h3>
                <p>Saha grubundan gelen mesajlar yapay zekâ ile çözümlenir: araç giriş/çıkışları, sahada kalış süreleri ve paylaşılan evraklar onay süzgecinden geçerek arşivlenir.</p>
                <div class="etiketler"><span>AI Çözümleme</span><span>Araç Takibi</span></div></div>
        </div>
    </div>
</section>

<!-- ÖZELLİKLER -->
<section class="bolum koyu">
    <div class="icerik">
        <div class="bolum-baslik">
            <span class="ust">Neden Bu Sistem?</span>
            <h2>Kağıdın Yapamadıklarını Yapar</h2>
        </div>
        <div class="ozellikler">
            <div class="ozellik"><i class="bi bi-qr-code-scan"></i><h4>Karekodla Saniyede Kayıt</h4>
                <p>GİB e-İrsaliye ve e-Fatura karekodları kamerayla okunur; numara, tarih, tutar ve tedarikçi kendiliğinden dolar.</p></div>
            <div class="ozellik"><i class="bi bi-stars"></i><h4>Yapay Zekâ Belge Okuma</h4>
                <p>Kantar fişi ve fatura görselleri AI ile çözümlenir, ilgili irsaliyeye otomatik bağlanır — elle veri girişi en aza iner.</p></div>
            <div class="ozellik altin"><i class="bi bi-receipt-cutoff"></i><h4>Fatura Mutabakatı</h4>
                <p>Tedarikçi faturasındaki irsaliye listesi sistemle karşılaştırılır; eksik ve mükerrer kayıtlar anında yakalanır.</p></div>
            <div class="ozellik"><i class="bi bi-graph-down-arrow"></i><h4>Zayiat Kontrolü</h4>
                <p>Beton, demir ve seramikte teorik metraj ile fiili tüketim karşılaştırılır; limit aşımı kırmızı alarma döner.</p></div>
            <div class="ozellik"><i class="bi bi-file-earmark-check"></i><h4>İmzalı Evrak Arşivi</h4>
                <p>Her teslim ERN Taahhüt logolu tutanakla belgelenir; ıslak imzalı taramalar kaydın üzerinde saklanır.</p></div>
            <div class="ozellik altin"><i class="bi bi-shield-check"></i><h4>Kurumsal Güvence</h4>
                <p>Rol bazlı yetki, iki aşamalı onay, denetim izi ve beş ayrı veritabanında günlük otomatik yedekleme.</p></div>
            <div class="ozellik"><i class="bi bi-bar-chart-line"></i><h4>Tek Tık Raporlama</h4>
                <p>Her modülde ERN Taahhüt logolu Excel ve PDF çıktıları; grafikli yönetim panelleri her an güncel.</p></div>
            <div class="ozellik"><i class="bi bi-phone"></i><h4>Sahada, Cepte</h4>
                <p>Mobil uyumlu arayüz ve PWA desteğiyle sistem şantiyede telefondan, ofiste ekrandan aynı hızda çalışır.</p></div>
        </div>
    </div>
</section>

<!-- AKIŞ -->
<section class="bolum acik">
    <div class="icerik">
        <div class="bolum-baslik">
            <span class="ust">Nasıl Çalışır?</span>
            <h2>Üç Adımda Kayıttan Rapora</h2>
        </div>
        <div class="akis">
            <div class="adim"><h4>Tara ya da Gir</h4><p>İrsaliye/fiş karekodu kameraya gösterilir veya belge fotoğrafı yüklenir — yapay zekâ alanları doldurur.</p></div>
            <div class="adim"><h4>Onayla</h4><p>Saha şefi ve teknik ofis iki aşamada onaylar; her değişiklik denetim izinde saklanır.</p></div>
            <div class="adim"><h4>Raporla</h4><p>Stok, zayiat, mutabakat ve mali değer raporları tek tıkla ERN Taahhüt logolu Excel/PDF olarak alınır.</p></div>
        </div>
    </div>
</section>

<!-- SON CTA -->
<section class="son-cta">
    <h2>Batı Yakası sahada böyle yönetiliyor.</h2>
    <p>ERN Holding güvencesi, ERN Taahhüt mühendisliği — şantiyenin dijital hafızası.</p>
    <a class="btn-ana" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Sisteme Giriş Yap</a>
</section>

<footer>
    <div>
        <img src="<?= h($LOGO_HOLDING) ?>" alt="ERN Holding" onerror="this.style.display='none'">
        <img src="<?= h($LOGO_TAAHHUT) ?>" alt="ERN Taahhüt" onerror="this.style.display='none'">
    </div>
    <div>© <?= date('Y') ?> ERN Holding — ERN Taahhüt · Batı Yakası Projesi Şantiye İş Takip Sistemi</div>
    <div class="gelistirici">Tasarım &amp; Geliştirme: Tayyar Akbulut</div>
</footer>

<script>
// Sayaç animasyonu: görünür olunca 0'dan hedefe
const say = document.querySelectorAll('.sayac .deger');
const oyna = el => {
    const hedef = parseInt(el.dataset.hedef || '0', 10);
    const ek = el.dataset.ek || '';
    const sure = 1600, t0 = performance.now();
    const adim = t => {
        const k = Math.min((t - t0) / sure, 1);
        const v = Math.round(hedef * (1 - Math.pow(1 - k, 3)));   // easeOutCubic
        el.innerHTML = v.toLocaleString('tr-TR') + (ek ? '<small>' + ek + '</small>' : '');
        if (k < 1) requestAnimationFrame(adim);
    };
    requestAnimationFrame(adim);
};
const io = new IntersectionObserver(girisler => {
    girisler.forEach(g => { if (g.isIntersecting) { oyna(g.target); io.unobserve(g.target); } });
}, { threshold: .4 });
say.forEach(el => io.observe(el));
</script>
</body>
</html>
