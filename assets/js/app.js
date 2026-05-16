/**
 * app.js — Beton Takip Sistemi genel JS
 */

// Onay gerektiren butonlar (.btn-confirm data-msg ile)
document.querySelectorAll('.btn-confirm, .btn-delete').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        var msg = this.dataset.msg || 'Bu kaydı silmek istediğinize emin misiniz?';
        if (!confirm(msg)) {
            e.preventDefault();
        }
    });
});

// Tüm .text-uppercase inputlarda otomatik büyük harf
document.querySelectorAll('input.text-uppercase').forEach(function (inp) {
    inp.addEventListener('input', function () {
        var pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });
});

// Bootstrap tooltip'leri başlat
var tooltipTriggers = document.querySelectorAll('[data-bs-toggle="tooltip"]');
if (typeof bootstrap !== 'undefined' && tooltipTriggers.length) {
    tooltipTriggers.forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
}

// Alert otomatik kapat (3.5 sn sonra)
setTimeout(function () {
    document.querySelectorAll('.alert.fade.show').forEach(function (el) {
        var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        if (bsAlert) bsAlert.close();
    });
}, 3500);
