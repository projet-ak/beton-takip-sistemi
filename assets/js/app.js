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

    const darkBtn  = document.getElementById('darkToggleBtn');
    const darkIcon = document.getElementById('darkToggleIcon');

    function applyDark(isDark) {
        document.documentElement.setAttribute('data-dark', isDark ? '1' : '0');
        localStorage.setItem('beton_dark', isDark ? '1' : '0');
        if (darkIcon) darkIcon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }
    if (darkIcon) darkIcon.className = document.documentElement.getAttribute('data-dark') === '1' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    darkBtn?.addEventListener('click', () => applyDark(document.documentElement.getAttribute('data-dark') !== '1'));

    function animateCount(el, target, decimals) {
        var start = performance.now(), dur = 1100;
        (function step(now) {
            var p = Math.min((now - start) / dur, 1);
            // ÖNEMLİ: son karede (p>=1) değeri TAM hedefe eşitle. Aksi halde expo
            // easing (1 - 2^-10) p=1'de 0,999023 kalır ve sayı hedefe hiç ulaşmaz
            // (ör. 5253 yerine 5247,9; 590 yerine 589 gösterir).
            var val;
            if (p >= 1) {
                val = target;
            } else {
                var ease = 1 - Math.pow(2, -10 * p);
                val = target * ease;
            }
            el.textContent = decimals > 0
                ? val.toLocaleString('tr-TR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
                : Math.round(val).toLocaleString('tr-TR');
            if (p < 1) requestAnimationFrame(step);
        })(start);
    }

    var io = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (!e.isIntersecting) return;
            var el  = e.target;
            var raw = parseFloat((el.dataset.count || el.textContent).replace(/\./g,'').replace(',','.'));
            if (isNaN(raw) || raw === 0) return;
            var dec = parseInt(el.dataset.decimals || '0', 10);
            animateCount(el, raw, dec);
            io.unobserve(el);
        });
    }, { threshold: 0.4 });

    document.querySelectorAll('.stat-value, .stat-count').forEach(function(el) {
        var txt = el.textContent.trim();
        var num = parseFloat(txt.replace(/\./g,'').replace(',','.'));
        if (!isNaN(num) && num > 0) {
            el.dataset.count    = String(num);
            el.dataset.decimals = txt.indexOf(',') >= 0 ? (txt.split(',')[1] ? txt.split(',')[1].length : 1) : 0;
            io.observe(el);
        }
    });

    if (typeof Chart !== 'undefined') {
        var dk = function() { return document.documentElement.getAttribute('data-dark') === '1'; };
        Chart.defaults.font.family = "'Outfit', system-ui, sans-serif";
        Chart.defaults.font.size   = 12;
        Chart.defaults.color       = dk() ? '#6EA89E' : '#4E7068';
        new MutationObserver(function() { Chart.defaults.color = dk() ? '#6EA89E' : '#4E7068'; })
            .observe(document.documentElement, { attributes: true, attributeFilter: ['data-dark'] });
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