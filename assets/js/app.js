// confirm silme
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', e => {
        if (!confirm('Bu kaydı silmek istediğinize emin misiniz?')) {
            e.preventDefault();
        }
    });
});
