/* ════════════════════════════════════════════════════════
   ERN Holding — Beton Takip Sistemi | app.js v3
   ════════════════════════════════════════════════════════ */

(function () {
    'use strict';

    const sidebar   = document.getElementById('ernSidebar');
    const overlay   = document.getElementById('sidebarOverlay');
    const toggleBtn = document.getElementById('sidebarToggleBtn');

    function openSidebar()  { sidebar?.classList.add('open'); overlay?.classList.add('active'); document.body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar?.classList.remove('open'); overlay?.classList.remove('active'); document.body.style.overflow = ''; }

    toggleBtn?.addEventListener('click', () => sidebar?.classList.contains('open') ? closeSidebar() : openSidebar());
    overlay?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeSidebar(); closeUserPopup(); }
    });

    // ── Kamera Fullscreen (Mobil) ────────────────────────────────────
    window.toggleCamFullscreen = function() {
        var card = document.querySelector('.cam-viewport')?.closest('.card');
        if (!card) return;
        var active = card.classList.toggle('cam-fullscreen-active');
        var icon = document.getElementById('fsIcon');
        if (icon) icon.className = active ? 'bi bi-fullscreen-exit' : 'bi bi-fullscreen';
        document.body.style.overflow = active ? 'hidden' : '';
    };
    // ESC ile fullscreen kapat
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var card = document.querySelector('.cam-fullscreen-active');
            if (card) { card.classList.remove('cam-fullscreen-active'); document.body.style.overflow = ''; }
        }
    });

    // ── Kullanıcı Popup (tıkla aç/kapat) ────────────────────────────
    var userArea  = document.querySelector('.sidebar-user');
    var userPopup = document.querySelector('.sidebar-user-popup');

    function openUserPopup() {
        if (!userPopup) return;
        // Sidebar daraltılmış mı kontrol et
        var collapsed = sidebar && sidebar.classList.contains('collapsed');
        userPopup.style.left = collapsed ? '74px' : (getComputedStyle(document.documentElement).getPropertyValue('--sidebar-w').trim() || '260px');
        userPopup.classList.add('open');
        userArea && userArea.classList.add('popup-open');
    }
    function closeUserPopup() {
        userPopup && userPopup.classList.remove('open');
        userArea  && userArea.classList.remove('popup-open');
    }

    userArea?.addEventListener('click', function(e) {
        // Çıkış linkine tıklanırsa popup kapatma — doğrudan git
        if (e.target.closest('a[href*="logout"]')) return;
        e.stopPropagation();
        userPopup?.classList.contains('open') ? closeUserPopup() : openUserPopup();
    });

    // Popup içindeki linklere tıklanınca kapat
    userPopup?.addEventListener('click', function(e) { e.stopPropagation(); });

    // Dışarı tıklanınca kapat
    document.addEventListener('click', closeUserPopup);

    // ── Desktop Sidebar Collapse ─────────────────────────────────────
    const collapseBtn  = document.getElementById('sidebarCollapseBtn');
    const collapseIcon = document.getElementById('sidebarCollapseIcon');
    const mainEl       = document.getElementById('ernMain');
    const COLLAPSED_KEY = 'ern_sidebar_collapsed';

    function setSidebarCollapsed(collapsed) {
        if (collapsed) {
            sidebar?.classList.add('collapsed');
            mainEl?.classList.add('sidebar-collapsed');
            if (collapseIcon) collapseIcon.className = 'bi bi-layout-sidebar';
            if (collapseBtn)  collapseBtn.title = 'Menüyü genişlet';
        } else {
            sidebar?.classList.remove('collapsed');
            mainEl?.classList.remove('sidebar-collapsed');
            if (collapseIcon) collapseIcon.className = 'bi bi-layout-sidebar-reverse';
            if (collapseBtn)  collapseBtn.title = 'Menüyü daralt';
        }
        localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0');
    }

    // Sayfa yükünde kaydedilmiş durumu uygula
    if (localStorage.getItem(COLLAPSED_KEY) === '1') {
        setSidebarCollapsed(true);
    }

    collapseBtn?.addEventListener('click', function(e) {
        e.stopPropagation();
        var willCollapse = !sidebar?.classList.contains('collapsed');
        setSidebarCollapsed(willCollapse);
        if (!willCollapse) hideFlyout();
        closeUserPopup();
    });

    // ── Flyout Menu (collapsed sidebar hover) ────────────────────────
    var flyout = document.createElement('div');
    flyout.id = 'sidebarFlyout';
    flyout.className = 'sidebar-flyout';
    document.body.appendChild(flyout);

    var flyoutTimer = null;

    function showFlyout(navItem, triggerEl) {
        if (!sidebar || !sidebar.classList.contains('collapsed')) return;

        var label    = triggerEl.dataset.label || '';
        var subMenu  = navItem.querySelector('.collapse');
        var icon     = triggerEl.querySelector('i');
        var iconCls  = icon ? icon.className : '';
        var href     = triggerEl.getAttribute('href');
        var isActive = triggerEl.classList.contains('active');
        var html     = '';

        if (subMenu) {
            html += '<div class="flyout-header">' + label + '</div>';
            subMenu.querySelectorAll('.sidebar-sub-link').forEach(function(link) {
                var ac = link.classList.contains('active') ? ' active' : '';
                html += '<a href="' + link.getAttribute('href') + '" class="flyout-link' + ac + '">' + link.textContent.trim() + '</a>';
            });
        } else {
            html += '<a href="' + (href || '#') + '" class="flyout-direct' + (isActive ? ' active' : '') + '">'
                  + (iconCls ? '<i class="' + iconCls + '"></i>' : '') + label + '</a>';
        }

        flyout.innerHTML = html;
        var rect = triggerEl.getBoundingClientRect();
        var fh   = flyout.scrollHeight || 80;
        var top  = rect.top;
        if (top + fh > window.innerHeight - 16) top = window.innerHeight - fh - 16;
        flyout.style.top = Math.max(8, top) + 'px';
        flyout.classList.add('visible');
    }

    function hideFlyout() { flyout.classList.remove('visible'); }

    document.querySelectorAll('.sidebar-nav-item').forEach(function(navItem) {
        var trigger = navItem.querySelector('.sidebar-nav-link');
        if (!trigger) return;
        navItem.addEventListener('mouseenter', function() {
            clearTimeout(flyoutTimer);
            flyoutTimer = setTimeout(function() { showFlyout(navItem, trigger); }, 60);
        });
        navItem.addEventListener('mouseleave', function() {
            clearTimeout(flyoutTimer);
            flyoutTimer = setTimeout(hideFlyout, 200);
        });
    });

    flyout.addEventListener('mouseenter', function() { clearTimeout(flyoutTimer); });
    flyout.addEventListener('mouseleave', function() {
        clearTimeout(flyoutTimer);
        flyoutTimer = setTimeout(hideFlyout, 200);
    });

    /* ── Tema seçici: görünüm + renk paleti + yüksek kontrast ────────────────
       Ayarlar cihaza özeldir (localStorage). Sayfa açılışında header.php'deki
       boot script zaten uygulamıştır; burası yalnız menüyü işaretler ve
       değişikliği anında yansıtır (yeniden yükleme yok). */
    const kok      = document.documentElement;
    const temaIkon = document.getElementById('temaIkon');
    const temaMenu = document.getElementById('temaMenu');
    const kontrastChk = document.getElementById('kontrastChk');
    const depo = { get(k){ try { return localStorage.getItem(k); } catch(e) { return null; } },
                   set(k,v){ try { localStorage.setItem(k,v); } catch(e) {} } };

    function temaTercihi() {
        var t = depo.get('ern_tema');
        if (t) return t;
        var eski = depo.get('beton_dark');
        return eski === '1' ? 'koyu' : (eski === '0' ? 'aydinlik' : 'sistem');
    }
    function koyuMu(tercih) {
        return tercih === 'koyu' ||
               (tercih === 'sistem' && window.matchMedia('(prefers-color-scheme:dark)').matches);
    }
    function temaUygula(tercih) {
        depo.set('ern_tema', tercih);
        depo.set('beton_dark', koyuMu(tercih) ? '1' : '0');   // eski anahtar: geri uyum
        kok.setAttribute('data-dark', koyuMu(tercih) ? '1' : '0');
        menuyuIsaretle();
    }
    function renkUygula(renk) {
        depo.set('ern_renk', renk);
        kok.setAttribute('data-renk', renk);
        menuyuIsaretle();
    }
    function kontrastUygula(acik) {
        depo.set('ern_kontrast', acik ? '1' : '0');
        kok.setAttribute('data-kontrast', acik ? '1' : '0');
        menuyuIsaretle();
    }
    function menuyuIsaretle() {
        var tercih = temaTercihi();
        if (temaIkon) {
            // Simge o an EKRANDA ne olduğunu anlatır: kontrast açıksa onu, değilse görünümü
            temaIkon.className = kok.getAttribute('data-kontrast') === '1' ? 'bi bi-circle-half'
                               : (kok.getAttribute('data-dark') === '1' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill');
        }
        if (!temaMenu) return;
        temaMenu.querySelectorAll('[data-grup="tema"] button').forEach(function(b) {
            b.classList.toggle('aktif', b.dataset.deger === tercih);
        });
        var renk = kok.getAttribute('data-renk') || 'ern';
        temaMenu.querySelectorAll('[data-grup="renk"] button').forEach(function(b) {
            b.classList.toggle('aktif', b.dataset.deger === renk);
        });
        if (kontrastChk) kontrastChk.checked = kok.getAttribute('data-kontrast') === '1';
    }

    temaMenu?.querySelectorAll('[data-grup="tema"] button').forEach(function(b) {
        b.addEventListener('click', function() { temaUygula(b.dataset.deger); });
    });
    temaMenu?.querySelectorAll('[data-grup="renk"] button').forEach(function(b) {
        b.addEventListener('click', function() { renkUygula(b.dataset.deger); });
    });
    kontrastChk?.addEventListener('change', function() { kontrastUygula(kontrastChk.checked); });
    // "Sistem" seçiliyken işletim sistemi teması değişirse anında uy
    window.matchMedia('(prefers-color-scheme:dark)').addEventListener('change', function() {
        if (temaTercihi() === 'sistem') temaUygula('sistem');
    });
    menuyuIsaretle();

    // Sayaç animasyonu: .stat-num (kpi_kart) + eski düz .stat-value/.stat-count.
    // Yalnız SAF sayı metni sayılır ("1.234,5", "+12"); içinde birim/öğe olan değer
    // ellenmez — eskiden "1.234 t" gibi metinlerde birim silinirdi.
    var sayiRe = /^([+\-\u2212]?)(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d+))?$/;
    var azHareket = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function animateCount(el, target, decimals, onEk) {
        var start = performance.now(), dur = 1100;
        (function step(now) {
            var p = Math.min((now - start) / dur, 1);
            // ÖNEMLİ: son karede (p>=1) değeri TAM hedefe eşitle. Aksi halde expo
            // easing (1 - 2^-10) p=1'de 0,999023 kalır ve sayı hedefe hiç ulaşmaz
            // (ör. 5253 yerine 5247,9; 590 yerine 589 gösterir).
            var val = p >= 1 ? target : target * (1 - Math.pow(2, -10 * p));
            el.textContent = onEk + (decimals > 0
                ? val.toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                : Math.round(val).toLocaleString('tr-TR'));
            if (p < 1) requestAnimationFrame(step);
        })(start);
    }

    var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (!e.isIntersecting) return;
            var el = e.target;
            animateCount(el, parseFloat(el.dataset.count), parseInt(el.dataset.decimals || '0', 10), el.dataset.onek || '');
            io.unobserve(el);
        });
    }, { threshold: 0.3 }) : null;

    // KPI değeri kutuya sığmazsa yazı küçülür (en az 13 px). Kart genişliği ekran/sütun düzenine
    // göre değiştiğinden sabit eşik yetmiyordu ("14.664.438 TL" 1440 px'te 6'lı dizilimde kesiliyordu).
    function kpiSigdir() {
        document.querySelectorAll('.stat-kpi .stat-value').forEach(function(v) {
            v.style.fontSize = '';
            var fs = parseFloat(getComputedStyle(v).fontSize), n = 0;
            while (v.scrollWidth > v.clientWidth + 1 && fs > 13 && n++ < 20) { fs -= 1; v.style.fontSize = fs + 'px'; }
        });
    }
    kpiSigdir();
    window.ERN_KPI_SIGDIR = kpiSigdir;   // içerik sonradan değişirse (AJAX) yeniden sığdırmak için
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(kpiSigdir);
    var kpiZam;
    window.addEventListener('resize', function() { clearTimeout(kpiZam); kpiZam = setTimeout(kpiSigdir, 150); });

    if (io && !azHareket) document.querySelectorAll('.stat-num, .stat-value, .stat-count').forEach(function(el) {
        if (el.children.length) return;                     // içinde öğe var (kpi_kart) → iç .stat-num sayılır
        var m = el.textContent.trim().match(sayiRe);
        if (!m) return;
        var num = parseFloat(m[2].replace(/\./g, '') + (m[3] ? '.' + m[3] : ''));
        if (!(num > 0)) return;
        var neg = m[1] === '-' || m[1] === '\u2212';
        el.dataset.count    = String(neg ? -num : num);
        el.dataset.decimals = m[3] ? String(m[3].length) : '0';
        el.dataset.onek     = m[1] === '+' ? '+' : '';
        io.observe(el);
    });

    if (typeof Chart !== 'undefined') {
        // Grafik metni/ızgarası tema değişkenlerinden okunur — sabit hex olsaydı
        // renk paleti ve yüksek kontrast modunda grafikler eski renklerde kalırdı.
        var css = function(ad) { return getComputedStyle(document.documentElement).getPropertyValue(ad).trim(); };
        var grafikRenk = function() {
            Chart.defaults.color = css('--bt-text-muted') || '#4E7068';
            Chart.defaults.borderColor = css('--bt-border-soft') || 'rgba(0,0,0,.1)';
        };
        Chart.defaults.font.family = "'Outfit', system-ui, sans-serif";
        Chart.defaults.font.size   = 12;
        grafikRenk();
        new MutationObserver(function() {
            grafikRenk();
            // Açık duran grafikler yeni renkleri alsın
            Object.values(Chart.instances || {}).forEach(function(c) { try { c.update('none'); } catch (e) {} });
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-dark','data-renk','data-kontrast'] });
    }

    document.querySelectorAll('table').forEach(function(tbl) {
        var ths = tbl.querySelectorAll('th.sortable');
        if (!ths.length) return;
        var tbody = tbl.tBodies[0];
        ths.forEach(function(th) { th.addEventListener('click', function() {
            var col = +th.dataset.col, type = th.dataset.type || 'text';
            var asc = !th.classList.contains('asc');
            ths.forEach(function(x) { x.classList.remove('asc','desc'); });
            th.classList.add(asc ? 'asc' : 'desc');
            Array.from(tbody.querySelectorAll('tr')).sort(function(a, b) {
                var av = ((a.cells[col] && (a.cells[col].dataset.sort || a.cells[col].innerText)) || '').trim();
                var bv = ((b.cells[col] && (b.cells[col].dataset.sort || b.cells[col].innerText)) || '').trim();
                var cmp = type === 'num'  ? (parseFloat(av.replace(',','.')) - parseFloat(bv.replace(',','.')))
                        : type === 'date' ? (new Date(av) - new Date(bv))
                        : av.localeCompare(bv, 'tr');
                return asc ? cmp : -cmp;
            }).forEach(function(r) { tbody.appendChild(r); });
        }); });
    });

    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('[data-bs-toggle="tooltip"]')
            .forEach(function(el) { new bootstrap.Tooltip(el, { trigger: 'hover' }); });
    }

})();