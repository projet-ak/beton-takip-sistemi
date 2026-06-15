<?php
/**
 * includes/tanim_modal.php
 * Paylaşılan irsaliye listesi modali — tanım sayfalarında include edilir.
 * .btn-tanim-modal class'ına data-tip, data-id, data-ad attribute'ları gerekir.
 */
?>
<div class="modal fade" id="irsaliyeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    <span id="modalBaslik">İrsaliyeler</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="modalYukleniyor" class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                    <div class="mt-2 text-muted small">Yükleniyor…</div>
                </div>
                <div id="modalIcerik" class="d-none">
                    <div id="modalHata" class="d-none alert alert-danger m-3"></div>
                    <div id="modalTablo" class="d-none">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>İrsaliye No</th>
                                    <th>Tarih</th>
                                    <th>Proje</th>
                                    <th>Tedarikçi</th>
                                    <th>Beton Sınıfı</th>
                                    <th>Araç Plaka</th>
                                    <th class="text-end">Miktar (m³)</th>
                                    <th class="text-center">Tür</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="modalTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <small class="text-muted me-auto" id="modalSayi"></small>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    let modalInstance = null;

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-tanim-modal');
        if (!btn) return;
        e.preventDefault();

        const tip = btn.dataset.tip;
        const id  = btn.dataset.id;
        const ad  = btn.dataset.ad;

        // Sıfırla
        document.getElementById('modalBaslik').textContent = ad + ' — İrsaliyeler';
        document.getElementById('modalYukleniyor').classList.remove('d-none');
        document.getElementById('modalIcerik').classList.add('d-none');
        document.getElementById('modalHata').classList.add('d-none');
        document.getElementById('modalTablo').classList.add('d-none');
        document.getElementById('modalSayi').textContent = '';
        document.getElementById('modalTbody').innerHTML = '';

        if (!modalInstance) {
            modalInstance = new bootstrap.Modal(document.getElementById('irsaliyeModal'));
        }
        modalInstance.show();

        fetch('api/tanim_irsaliyeler.php?tip=' + encodeURIComponent(tip) + '&id=' + encodeURIComponent(id))
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                document.getElementById('modalYukleniyor').classList.add('d-none');
                document.getElementById('modalIcerik').classList.remove('d-none');

                if (!data.ok) {
                    document.getElementById('modalHata').textContent = data.msg || 'Bilinmeyen hata';
                    document.getElementById('modalHata').classList.remove('d-none');
                    return;
                }

                document.getElementById('modalSayi').textContent = 'Toplam ' + data.sayi + ' irsaliye';

                const tbody = document.getElementById('modalTbody');
                if (data.liste.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>';
                } else {
                    data.liste.forEach(function (r) {
                        const tipBadge = r.tip === 'alis'
                            ? '<span class="badge bg-success-subtle text-success">Alış</span>'
                            : '<span class="badge bg-danger-subtle text-danger">İade</span>';
                        const tarih = r.tarih
                            ? r.tarih.substring(0, 10).split('-').reverse().join('.')
                            : '—';
                        const miktar = r.miktar
                            ? parseFloat(r.miktar).toLocaleString('tr-TR', { minimumFractionDigits: 2 })
                            : '—';
                        tbody.insertAdjacentHTML('beforeend',
                            '<tr>' +
                            '<td class="font-monospace small">' + esc(r.irsaliye_no) + '</td>' +
                            '<td class="small">' + tarih + '</td>' +
                            '<td class="small">' + esc(r.proje_adi) + '</td>' +
                            '<td class="small">' + esc(r.tedarikci_adi) + '</td>' +
                            '<td class="small">' + esc(r.beton_sinifi_adi) + '</td>' +
                            '<td class="small">' + esc(r.arac_plaka) + '</td>' +
                            '<td class="text-end small">' + miktar + '</td>' +
                            '<td class="text-center">' + tipBadge + '</td>' +
                            '<td><a href="irsaliye_goruntule.php?id=' + r.id + '" target="_blank" class="btn btn-xs btn-outline-secondary"><i class="bi bi-eye"></i></a></td>' +
                            '</tr>'
                        );
                    });
                }
                document.getElementById('modalTablo').classList.remove('d-none');
            })
            .catch(function (err) {
                document.getElementById('modalYukleniyor').classList.add('d-none');
                document.getElementById('modalIcerik').classList.remove('d-none');
                document.getElementById('modalHata').textContent = 'Bağlantı hatası: ' + err.message;
                document.getElementById('modalHata').classList.remove('d-none');
            });
    });

    function esc(v) {
        if (v == null) return '—';
        return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
})();
</script>
