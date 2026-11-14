<?php
/** kamp/_periyot_form.php — periyodik değişim türü formu (periyodik.php içinde $T ile include edilir). */
if (!isset($T) || !is_array($T)) { http_response_code(404); exit; }
$__tur = array_filter(array_map('trim', explode(',', (string)$T['oda_tur'])));
?>
<form method="post" class="row g-2 align-items-end">
  <input type="hidden" name="islem" value="tanim_kaydet"><input type="hidden" name="id" value="<?= (int)$T['id'] ?>">
  <div class="col-md-5"><label class="form-label small mb-0">Ad</label><input name="ad" class="form-control form-control-sm" required maxlength="120" value="<?= h($T['ad']) ?>" placeholder="ör. Yorgan yıkama"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Kapsam</label><select name="kapsam" class="form-select form-select-sm js-kapsam">
    <?php foreach (KAMP_PERIYOT_KAPSAM as $k => $ad): ?><option value="<?= $k ?>"<?= $T['kapsam'] === $k ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Kaç günde bir</label><input type="number" name="gun" min="1" max="3650" class="form-control form-control-sm" required value="<?= (int)$T['gun'] ?>"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Kaç gün kala uyar</label><input type="number" name="uyari_gun" min="0" max="3650" class="form-control form-control-sm" value="<?= (int)$T['uyari_gun'] ?>"></div>
  <div class="col-md-1"><label class="form-label small mb-0">Sıra</label><input type="number" name="sira" class="form-control form-control-sm" value="<?= (int)$T['sira'] ?>"></div>
  <div class="col-md-6"><label class="form-label small mb-0">Açıklama</label><input name="aciklama" class="form-control form-control-sm" maxlength="255" value="<?= h($T['aciklama']) ?>"></div>
  <div class="col-md-6 js-yeni"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="yeni_giriste" value="1" id="yg<?= (int)$T['id'] ?>"<?= $T['yeni_giriste'] ? ' checked' : '' ?>>
    <label class="form-check-label small" for="yg<?= (int)$T['id'] ?>">Yatağa yeni kişi gelince de yenilensin</label></div></div>
  <div class="col-12 js-oda-tur"><span class="small text-muted me-2">Geçerli oda türleri (boş = yatak odaları):</span>
    <?php foreach (KAMP_ODA_TUR as $k => $v): ?><label class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" name="oda_tur[]" value="<?= $k ?>"<?= in_array($k, $__tur, true) ? ' checked' : '' ?>><?= h($v[0]) ?></label><?php endforeach; ?></div>
  <div class="col-12 d-flex align-items-center gap-3">
    <?php if ($T['id']): ?><label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="aktif" value="1"<?= $T['aktif'] ? ' checked' : '' ?>> Aktif</label><?php endif; ?>
    <button class="btn btn-primary btn-sm ms-auto"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
</form>
