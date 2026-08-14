<?php
/**
 * @var array $documents
 * @var array $suggestedTypes
 * @var string $lang
 * @var array $availableLangs
 * @var string|null $editType
 * @var array|null $current
 * @var array $errors
 */
$base = rtrim(SITE_URL, '/') . '/admin/legal-documents';
?>
<div class="page-header"><h1><?= e(__('admin.legal_documents')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.legal_documents.existing')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.legal_documents.type')) ?></th><th><?= e(__('nav.language')) ?></th><th><?= e(__('admin.legal_documents.title_col')) ?></th><th><?= e(__('admin.legal_documents.source')) ?></th><th><?= e(__('common.date')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($documents as $d): ?>
        <tr>
          <td><?= e($d['type']) ?></td>
          <td><?= e(strtoupper($d['language'])) ?></td>
          <td><?= e($d['title']) ?></td>
          <td><span class="badge badge-<?= $d['source_mode'] === 'uploaded' ? 'processing' : 'completed' ?>"><?= e(ucfirst($d['source_mode'])) ?></span></td>
          <td><?= e(formatLocalDate($d['updated_at'])) ?></td>
          <td>
            <a class="btn btn-sm btn-secondary" href="<?= e($base) ?>?type=<?= urlencode($d['type']) ?>&amp;lang=<?= e($d['language']) ?>"><?= e(__('common.edit')) ?></a>
            <form method="post" action="<?= e($base) ?>/delete" style="display:inline;" data-confirm="<?= e(__('admin.legal_documents.confirm_delete')) ?>">
              <?= csrfField() ?>
              <input type="hidden" name="delete_id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?><tr><td colspan="6"><?= e(__('admin.legal_documents.none_yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= $editType ? e(__('admin.legal_documents.edit_heading')) : e(__('admin.legal_documents.add_heading')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.legal_documents.hint')) ?></p>

  <form method="post" action="<?= e($base) ?>" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group">
        <label for="type"><?= e(__('admin.legal_documents.type')) ?></label>
        <input type="text" id="type" name="type" list="suggestedTypes" value="<?= e($current['type'] ?? $editType ?? '') ?>" required>
        <datalist id="suggestedTypes">
          <?php foreach ($suggestedTypes as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?>
        </datalist>
        <small style="color:var(--color-muted);"><?= e(__('admin.legal_documents.type_hint')) ?></small>
      </div>
      <div class="form-group">
        <label for="language"><?= e(__('nav.language')) ?></label>
        <select id="language" name="language">
          <?php foreach ($availableLangs as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $lang === $code ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="title"><?= e(__('admin.legal_documents.title_col')) ?></label>
        <input type="text" id="title" name="title" value="<?= e($current['title'] ?? '') ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label for="generated_text"><?= e(__('admin.legal_documents.generate_from_text')) ?></label>
      <textarea id="generated_text" name="generated_text" rows="8"><?= e($current['generated_text'] ?? '') ?></textarea>
      <small style="color:var(--color-muted);"><?= e(__('admin.legal_documents.generate_hint')) ?></small>
    </div>
    <button class="btn" type="submit" name="generate_pdf" value="1"><?= e(__('admin.legal_documents.generate_pdf')) ?></button>

    <hr style="margin:20px 0;">

    <div class="form-group">
      <label for="document"><?= e(__('admin.legal_documents.or_upload')) ?></label>
      <input type="file" id="document" name="document" accept="application/pdf">
      <?php if (!empty($current['source_mode']) && $current['source_mode'] === 'uploaded'): ?>
        <small style="color:var(--color-muted);"><?= e(__('admin.legal_documents.currently_uploaded')) ?></small>
      <?php endif; ?>
    </div>
    <button class="btn btn-secondary" type="submit"><?= e(__('admin.legal_documents.upload_pdf')) ?></button>
  </form>
</div>
