<?php
/*
 * Mountaineer IT - a diagram file's earlier versions (functions/mit_diagrams.php): each one's picture, who saved it and when it was replaced, and
 * "Put this back" (agent/js/mit_diagrams.js posts restore to agent/mit_diagrams_ajax.php - what is there now is kept as a version too). ?id=<file_id>
 */

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$file = function_exists('mitDiagramFileRow') ? mitDiagramFileRow(intval($_GET['id'] ?? 0)) : null;
if (!$file || mitDiagramFileKind($file) === '') {
    exit(json_encode(['error' => 'That diagram no longer exists.']));
}
$client_id = intval($file['file_client_id']);
enforceClientAccess();
$versions = mitDiagramVersions($file['file_id']);
$png = mitDiagramFileKind($file) === 'png';

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-history me-2"></i>Earlier versions <span class="fw-normal text-white-50 ms-1"><?= escapeHtml($file['file_name']) ?></span></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-mit-diagram-versions data-file-id="<?= intval($file['file_id']) ?>">
    <?php if (!$versions) { ?>
        <p class="text-body-secondary mb-0">No earlier versions yet - each save keeps the one before it here.</p>
    <?php } ?>
    <?php foreach ($versions as $v) { ?>
        <div class="d-flex gap-3 align-items-start border-bottom py-3" data-mit-diagram-version="<?= intval($v['version_id']) ?>">
            <?php if ($png) { ?>
                <a href="/agent/mit_diagrams_ajax.php?version=<?= intval($v['version_id']) ?>" target="_blank" rel="noopener" class="flex-shrink-0">
                    <img src="/agent/mit_diagrams_ajax.php?version=<?= intval($v['version_id']) ?>" alt="Version of <?= escapeHtml(date('M j, Y g:i A', strtotime($v['replaced_at']))) ?>" loading="lazy" style="width: 180px; max-height: 120px; object-fit: contain; background: #fff;" class="border rounded">
                </a>
            <?php } ?>
            <div class="flex-grow-1 small">
                <div class="fw-semibold">Saved <?= escapeHtml(date('M j, Y g:i A', strtotime($v['saved_at']))) ?><?= $v['saved_name'] ? ' by ' . escapeHtml($v['saved_name']) : '' ?></div>
                <div class="text-body-secondary">Replaced <?= escapeHtml(date('M j, Y g:i A', strtotime($v['replaced_at']))) ?><?= $v['replaced_name'] ? ' by ' . escapeHtml($v['replaced_name']) : '' ?> &middot; <?= escapeHtml(number_format(intval($v['file_size']) / 1024, 0)) ?> KB</div>
                <?php if (!$png) { ?>
                    <a href="/agent/mit_diagrams_ajax.php?version=<?= intval($v['version_id']) ?>"><i class="fas fa-download me-1"></i>Download</a>
                <?php } ?>
            </div>
            <?php if (!$file['file_archived_at']) { ?>
                <button type="button" class="btn btn-sm btn-outline-primary" data-mit-diagram-restore="<?= intval($v['version_id']) ?>"><i class="fas fa-undo me-1"></i>Put this back</button>
            <?php } ?>
        </div>
    <?php } ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
</div>

<?php
require_once '../../../includes/modal_footer.php';
