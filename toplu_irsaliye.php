<?php
/**
 * toplu_irsaliye.php — Toplu irsaliye girişi (tek ekranda çok satır)
 *
 * Uzun irsaliye formunu her belge için ayrı doldurmak yerine: ORTAK bilgiler
 * (tarih, tedarikçi, beton sınıfı, proje→parsel→blok→kot, imalat) bir kez
 * seçilir; altta satır satır İrsaliye No + m³ + plaka (+kantar) yazılıp tek
 * tıkla hepsi kaydedilir. Aynı gün gelen 10-20 mikserlik seriler için.
 *
 * Mükerrer koruması import ile aynıdır: numara NORMALİZE karşılaştırılır
 * (SKB2026-12047 ↔ SKB2026000012047); [FATURADAN] taslağına denk gelen satır
 * yeni kayıt açmaz, taslağı gerçek verilerle GÜNCELLER (fatura bağı korunur).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (!file_exists(__DIR__ . '/config.php')) { redirect('install.php'); }
require_auth(['admin','teknik_ofis_admin','teknik_ofis','saha_sefi','depo']);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fatura.php';   // fat_irs_norm

if (!can_edit()) { flash('error', 'Bu işlem için yetkiniz yok.'); redirect('irsaliyeler.php'); }

$pageTitle = 'Toplu İrsaliye Girişi — Beton Takip Sistemi';
$sayi = fn($v) => (float)(fat_sayi((string)$v) ?? 0);   // '12,5' de '12.5' de doğru çözülür

// ── Kaydet ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tarih   = trim((string)($_POST['tarih'] ?? ''));
    $tedId   = (int)($_POST['tedarikci_id'] ?? 0);
    if ($tarih === '' || !$tedId) {
        flash('error', 'Tarih ve tedarikçi zorunludur.');
        redirect('toplu_irsaliye.php');
    }
    $ortak = [
        'beton_sinifi_id' => (int)($_POST['beton_sinifi_id'] ?? 0) ?: null,
        'pompa_id'        => (int)($_POST['pompa_id'] ?? 0) ?: null,
        'kivam_sinifi_id' => (int)($_POST['kivam_sinifi_id'] ?? 0) ?: null,
        'proje_id'        => (int)($_POST['proje_id'] ?? 0) ?: null,
        'parsel_id'       => (int)($_POST['parsel_id'] ?? 0) ?: null,
        'blok_id'         => (int)($_POST['blok_id'] ?? 0) ?: null,
        'kot_id'          => (int)($_POST['kot_id'] ?? 0) ?: null,
        'imalat_grup_id'  => (int)($_POST['imalat_grup_id'] ?? 0) ?: null,
        'ana_is_kalemi_id'=> (int)($_POST['ana_is_kalemi_id'] ?? 0) ?: null,
        'firma_id'        => (int)($_POST['firma_id'] ?? 0) ?: null,
        'aciklama'        => trim((string)($_POST['aciklama'] ?? '')) ?: null,
    ];
    // Proje kodu (proje_no kolonu görünüm/Excel uyumu için de dolduruluyor)
    $projeNo = null;
    if ($ortak['proje_id']) {
        $pq = $pdo->prepare("SELECT kod FROM projeler WHERE id=?"); $pq->execute([$ortak['proje_id']]);
        $projeNo = $pq->fetchColumn() ?: null;
    }
    $durum = has_role('admin','teknik_ofis_admin') ? 'onaylandi' : 'beklemede';
    $uid   = current_user_id();

    // Mevcut numaralar (normalize) — mükerrer/taslak tespiti
    $mevcut = [];   // norm → ['id'=>, 'taslak'=>bool]
    foreach ($pdo->query("SELECT id, irsaliye_no, aciklama FROM irsaliyeler
                          WHERE irsaliye_no IS NOT NULL AND TRIM(irsaliye_no) <> ''") as $mr) {
        $k = fat_irs_norm((string)$mr['irsaliye_no']);
        if ($k !== '' && !isset($mevcut[$k])) $mevcut[$k] = ['id'=>(int)$mr['id'], 'taslak'=>irs_taslak_mi($mr)];
    }

    $nolar  = (array)($_POST['no'] ?? []);
    $m3ler  = (array)($_POST['m3'] ?? []);
    $plakalar = (array)($_POST['plaka'] ?? []);
    $kyler  = (array)($_POST['ky'] ?? []);
    $ktler  = (array)($_POST['kt'] ?? []);

    $eklenen = 0; $guncellenen = 0; $atlanan = []; $hatali = [];
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO irsaliyeler
            (tip, durum, irsaliye_no, arac_plaka, tedarikci_id, tarih, miktar, birim,
             kantar_net_yildizlar, kantar_net_tedarikci, kantar_farki,
             beton_sinifi_id, pompa_id, kivam_sinifi_id, proje_no, proje_id, parsel_id, blok_id, kot_id,
             imalat_grup_id, ana_is_kalemi_id, firma_id, aciklama, created_by)
            VALUES ('alis',?,?,?,?,?,?, 'M3', ?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?)");
        $upd = $pdo->prepare("UPDATE irsaliyeler SET
             durum=?, irsaliye_no=?, arac_plaka=?, tedarikci_id=?, tarih=?, miktar=?,
             kantar_net_yildizlar=?, kantar_net_tedarikci=?, kantar_farki=?,
             beton_sinifi_id=?, pompa_id=?, kivam_sinifi_id=?, proje_no=?, proje_id=?, parsel_id=?, blok_id=?, kot_id=?,
             imalat_grup_id=?, ana_is_kalemi_id=?, firma_id=?, aciklama=?, updated_by=? WHERE id=?");

        foreach ($nolar as $i => $noHam) {
            $no = strtoupper(trim((string)$noHam));
            $m3 = $sayi($m3ler[$i] ?? '');
            if ($no === '' && $m3 == 0.0) continue;                        // boş satır
            if ($no === '') { $hatali[] = 'Satır ' . ($i+1) . ': irsaliye no boş'; continue; }
            if ($m3 <= 0)   { $hatali[] = "$no: m³ girilmedi"; continue; }
            $plaka = strtoupper(trim((string)($plakalar[$i] ?? ''))) ?: null;
            $ky = $sayi($kyler[$i] ?? '') ?: null;
            $kt = $sayi($ktler[$i] ?? '') ?: null;
            $fark = ($ky !== null && $kt !== null) ? $ky - $kt : null;
            $norm = fat_irs_norm($no);

            if ($norm !== '' && isset($mevcut[$norm])) {
                if ($mevcut[$norm]['taslak']) {
                    // [FATURADAN] taslak → gerçek verilerle doldur (fatura bağı/ekler korunur)
                    $upd->execute([$durum, $no, $plaka, $tedId, $tarih, $m3, $ky, $kt, $fark,
                        $ortak['beton_sinifi_id'], $ortak['pompa_id'], $ortak['kivam_sinifi_id'],
                        $projeNo, $ortak['proje_id'], $ortak['parsel_id'], $ortak['blok_id'], $ortak['kot_id'],
                        $ortak['imalat_grup_id'], $ortak['ana_is_kalemi_id'], $ortak['firma_id'],
                        $ortak['aciklama'], $uid, $mevcut[$norm]['id']]);
                    audit_log($pdo, 'irsaliyeler', $mevcut[$norm]['id'], 'UPDATE', null,
                              ['toplu_giris' => true, 'taslak_dolduruldu' => $no], $uid);
                    $mevcut[$norm]['taslak'] = false;
                    $guncellenen++;
                } else {
                    $atlanan[] = $no;
                }
                continue;
            }
            $ins->execute([$durum, $no, $plaka, $tedId, $tarih, $m3, $ky, $kt, $fark,
                $ortak['beton_sinifi_id'], $ortak['pompa_id'], $ortak['kivam_sinifi_id'],
                $projeNo, $ortak['proje_id'], $ortak['parsel_id'], $ortak['blok_id'], $ortak['kot_id'],
                $ortak['imalat_grup_id'], $ortak['ana_is_kalemi_id'], $ortak['firma_id'],
                $ortak['aciklama'], $uid]);
            $yeniId = (int)$pdo->lastInsertId();
            if ($norm !== '') $mevcut[$norm] = ['id'=>$yeniId, 'taslak'=>false];
            audit_log($pdo, 'irsaliyeler', $yeniId, 'INSERT', null, ['toplu_giris' => true, 'irsaliye_no' => $no], $uid);
            $eklenen++;
        }
        $pdo->commit();
        $msg = "$eklenen irsaliye eklendi" . ($durum === 'beklemede' ? ' (onay bekliyor)' : '')
             . ($guncellenen ? ", $guncellenen faturadan açılmış taslak dolduruldu" : '')
             . ($atlanan ? ". Mükerrer atlandı: " . implode(', ', array_slice($atlanan, 0, 15)) : '')
             . ($hatali  ? ". Hatalı: " . implode(' · ', array_slice($hatali, 0, 10)) : '') . '.';
        flash($hatali || $atlanan ? 'warning' : 'success', $msg);
        redirect($eklenen || $guncellenen ? 'irsaliyeler.php?tip=alis' : 'toplu_irsaliye.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Kayıt hatası: ' . $e->getMessage());
        redirect('toplu_irsaliye.php');
    }
}

// ── Tanım verileri ───────────────────────────────────────────────────────────
$tedarikciler = $pdo->query("SELECT id, ad FROM tedarikciler ORDER BY ad")->fetchAll();
$betonlar  = $pdo->query("SELECT id, ad FROM beton_siniflari WHERE aktif=1 ORDER BY ad")->fetchAll();
$pompalar  = $pdo->query("SELECT id, ad FROM pompa_turleri WHERE aktif=1 ORDER BY ad")->fetchAll();
$kivamlar  = $pdo->query("SELECT id, ad FROM kivam_siniflari WHERE aktif=1 ORDER BY ad")->fetchAll();
$firmalar  = $pdo->query("SELECT id, ad FROM firmalar WHERE aktif=1 ORDER BY ad")->fetchAll();
$projeler  = $pdo->query("SELECT id, kod, ad FROM projeler ORDER BY kod")->fetchAll();
$parseller = $pdo->query("SELECT id, ad, proje_id FROM parseller WHERE aktif=1 ORDER BY ad")->fetchAll();
$bloklar   = $pdo->query("SELECT id, ad, parsel_id FROM bloklar ORDER BY ad")->fetchAll();
$kotlar    = $pdo->query("SELECT id, ad, blok_id FROM kotlar ORDER BY id")->fetchAll();
$gruplar   = $pdo->query("SELECT id, ad FROM imalat_gruplari ORDER BY sira, ad")->fetchAll();
$kalemler  = $pdo->query("SELECT id, ad, imalat_grup_id FROM ana_is_kalemleri ORDER BY ad")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex align-items-center gap-3 mb-3">
    <a href="irsaliyeler.php?tip=alis" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <div><h4 class="mb-0"><i class="bi bi-stack text-primary me-2"></i>Toplu İrsaliye Girişi</h4>
    <small class="text-muted">Ortak bilgileri bir kez seç, irsaliyeleri satır satır yaz — hepsi tek tıkla kaydedilir</small></div>
</div>
<?php foreach(['success','error','warning'] as $t): if($msg=get_flash($t)): ?><div class="alert alert-<?= $t==='error'?'danger':$t ?>"><?= h($msg) ?></div><?php endif; endforeach; ?>

<form method="post" id="topluForm">
<div class="card mb-3"><div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1"></i>Ortak Bilgiler <span class="text-muted small">(tüm satırlara uygulanır)</span></div>
<div class="card-body row g-3">
    <div class="col-md-2"><label class="form-label">Tarih *</label>
        <input type="date" name="tarih" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-3"><label class="form-label">Tedarikçi *</label>
        <select name="tedarikci_id" class="form-select" required><option value="">— seçiniz —</option>
        <?php foreach ($tedarikciler as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Beton Sınıfı</label>
        <select name="beton_sinifi_id" class="form-select"><option value="">—</option>
        <?php foreach ($betonlar as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Pompa</label>
        <select name="pompa_id" class="form-select"><option value="">—</option>
        <?php foreach ($pompalar as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Kıvam</label>
        <select name="kivam_sinifi_id" class="form-select"><option value="">—</option>
        <?php foreach ($kivamlar as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>

    <div class="col-md-2"><label class="form-label">Proje</label>
        <select name="proje_id" id="fProje" class="form-select"><option value="">—</option>
        <?php foreach ($projeler as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['kod']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Parsel</label>
        <select name="parsel_id" id="fParsel" class="form-select"><option value="">—</option>
        <?php foreach ($parseller as $o): ?><option value="<?= (int)$o['id'] ?>" data-proje="<?= (int)$o['proje_id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Blok</label>
        <select name="blok_id" id="fBlok" class="form-select"><option value="">—</option>
        <?php foreach ($bloklar as $o): ?><option value="<?= (int)$o['id'] ?>" data-parsel="<?= (int)$o['parsel_id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Kot</label>
        <select name="kot_id" id="fKot" class="form-select"><option value="">—</option>
        <?php foreach ($kotlar as $o): ?><option value="<?= (int)$o['id'] ?>" data-blok="<?= (int)$o['blok_id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">İmalat Grubu</label>
        <select name="imalat_grup_id" id="fGrup" class="form-select"><option value="">—</option>
        <?php foreach ($gruplar as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Ana İş Kalemi</label>
        <select name="ana_is_kalemi_id" id="fKalem" class="form-select"><option value="">—</option>
        <?php foreach ($kalemler as $o): ?><option value="<?= (int)$o['id'] ?>" data-grup="<?= (int)$o['imalat_grup_id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>

    <div class="col-md-3"><label class="form-label">Döküm Yapan Firma</label>
        <select name="firma_id" class="form-select"><option value="">—</option>
        <?php foreach ($firmalar as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['ad']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-9"><label class="form-label">Açıklama</label>
        <input name="aciklama" class="form-control" placeholder="opsiyonel — tüm satırlara yazılır"></div>
</div></div>

<div class="card mb-3"><div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ol me-1"></i>İrsaliyeler <span class="text-muted small">(boş satırlar atlanır)</span></span>
    <span class="badge bg-primary" id="ozet">0 satır · 0,00 m³</span>
</div>
<div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm align-middle mb-0" id="satirTablo">
    <thead class="table-light"><tr>
        <th style="width:36px">#</th><th>İrsaliye No *</th><th style="width:120px">m³ *</th>
        <th style="width:140px">Plaka</th><th style="width:130px">Kantar Yıldızlar</th><th style="width:130px">Kantar Tedarikçi</th><th style="width:40px"></th>
    </tr></thead>
    <tbody id="satirlar"></tbody>
</table>
</div></div>
<div class="card-footer bg-white d-flex gap-2">
    <button type="button" class="btn btn-outline-primary btn-sm" onclick="satirEkle()"><i class="bi bi-plus-lg me-1"></i>Satır Ekle</button>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="for(let i=0;i<5;i++)satirEkle()">+5 Satır</button>
</div></div>

<div class="mb-4 d-flex gap-2">
    <button class="btn btn-success"><i class="bi bi-save me-1"></i>Tümünü Kaydet</button>
    <a href="irsaliyeler.php?tip=alis" class="btn btn-outline-secondary">Vazgeç</a>
</div>
</form>

<script>
// Kademeli filtreler: Proje→Parsel→Blok→Kot ve Grup→Kalem
function filtrele(sel, attr, deger) {
    let n = 0;
    Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        const uygun = !deger || o.dataset[attr] === deger;
        o.hidden = !uygun;
        if (!uygun && sel.value === o.value) sel.value = '';
        if (uygun) n++;
    });
    return n;
}
document.getElementById('fProje').addEventListener('change', function(){ filtrele(document.getElementById('fParsel'),'proje',this.value); document.getElementById('fParsel').dispatchEvent(new Event('change')); });
document.getElementById('fParsel').addEventListener('change', function(){ filtrele(document.getElementById('fBlok'),'parsel',this.value); document.getElementById('fBlok').dispatchEvent(new Event('change')); });
document.getElementById('fBlok').addEventListener('change', function(){ filtrele(document.getElementById('fKot'),'blok',this.value); });
document.getElementById('fGrup').addEventListener('change', function(){ filtrele(document.getElementById('fKalem'),'grup',this.value); });

// Satır yönetimi
let sira = 0;
function satirEkle(odakla) {
    sira++;
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="text-muted text-center sira"></td>' +
        '<td><input name="no[]" class="form-control form-control-sm font-monospace" placeholder="SKB2026000012345" autocomplete="off"></td>' +
        '<td><input name="m3[]" class="form-control form-control-sm text-end" inputmode="decimal" placeholder="12,5"></td>' +
        '<td><input name="plaka[]" class="form-control form-control-sm text-uppercase" placeholder="34ABC123"></td>' +
        '<td><input name="ky[]" class="form-control form-control-sm text-end" inputmode="decimal"></td>' +
        '<td><input name="kt[]" class="form-control form-control-sm text-end" inputmode="decimal"></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="this.closest(\'tr\').remove(); yenile();" tabindex="-1"><i class="bi bi-x"></i></button></td>';
    document.getElementById('satirlar').appendChild(tr);
    tr.querySelectorAll('input').forEach(inp => inp.addEventListener('input', yenile));
    // Son satırın m³ hücresinde Enter/Tab → yeni satır aç
    tr.querySelector('input[name="m3[]"]').addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault();
            if (tr === document.getElementById('satirlar').lastElementChild) satirEkle(true);
            else tr.nextElementSibling?.querySelector('input')?.focus();
        }
    });
    yenile();
    if (odakla) tr.querySelector('input').focus();
    return tr;
}
function yenile() {
    let n = 0, m3 = 0;
    document.querySelectorAll('#satirlar tr').forEach((tr, i) => {
        tr.querySelector('.sira').textContent = i + 1;
        const no = tr.querySelector('input[name="no[]"]').value.trim();
        const v  = parseFloat((tr.querySelector('input[name="m3[]"]').value || '').replace(',', '.')) || 0;
        if (no !== '' || v > 0) { n++; m3 += v; }
    });
    document.getElementById('ozet').textContent = n + ' satır · ' + m3.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' m³';
}
for (let i = 0; i < 5; i++) satirEkle();

// Boş güvenlik: hiç dolu satır yoksa göndermeyi engelle
document.getElementById('topluForm').addEventListener('submit', function(e){
    let dolu = 0;
    document.querySelectorAll('#satirlar input[name="no[]"]').forEach(i => { if (i.value.trim() !== '') dolu++; });
    if (!dolu) { e.preventDefault(); alert('En az bir irsaliye satırı doldurun.'); }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
