<?php

/*
 * Mountaineer IT - put lines of one of ITFlow's purchase orders on a project, or take them off (functions/mit_procurement_extras.php
 * mitPoSetProject). Opened from "Link to a Project" in the PO page's menu, or a line's project link (&line_id= ticks only that one).
 * Handler mit_po_set_project in agent/post/mit_procurement_parts.php.
 */

require_once '../../../includes/modal_header.php';

poRequireLevel(2);

$po = poRow(intval($_GET['po_id'] ?? 0));
if (!$po || !poAccessible($po['po_id']) || !mitProcReady()) {
    exit(json_encode(['error' => 'That purchase order does not exist, or it is for a client you cannot see.']));
}
$po_id = intval($po['po_id']);
$reference = escapeHtml(poNumber($po));
$lines = mitPoLines($po_id);
$choices = mitPoProjectChoices();
$current = array_values(array_unique(array_map('intval', array_column($lines, 'project_id'))));
$picked = count($current) === 1 ? $current[0] : 0;
$only = intval($_GET['line_id'] ?? 0);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-project-diagram me-2"></i>Link <?= $reference ?> to a project</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="po_id" value="<?= $po_id ?>">
    <div class="modal-body">
        <div class="mb-3" data-mit-po-project>
            <label class="form-label">Project</label>
            <select class="form-select select2" name="project_id">
                <option value="0">- No project (take them off) -</option>
                <?php foreach ($choices as $id => $choice) { ?>
                    <option value="<?= $id ?>" <?= $id === $picked ? 'selected' : '' ?>><?= escapeHtml($choice['label'] . ' · ' . ($choice['client_name'] !== '' ? $choice['client_name'] : 'no client')) ?></option>
                <?php } ?>
            </select>
            <div class="form-text">Open projects only. A line for another client can't go on a client's project.</div>
        </div>
        <label class="form-label">Lines</label>
        <div class="border rounded p-2" data-mit-po-project-lines>
            <?php foreach ($lines as $line) {
                $line_id = intval($line['po_item_id']); ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="line_ids[]" value="<?= $line_id ?>" id="mitPoProjLine<?= $line_id ?>" <?= !$only || $only === $line_id ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitPoProjLine<?= $line_id ?>">
                        <?= escapeHtml($line['po_item_name']) ?> <span class="small text-muted">× <?= mitQty($line['po_item_quantity']) ?><?= $line['client_name'] ? ' · ' . escapeHtml($line['client_name']) : '' ?><?= $line['project_name'] ? ' · now on ' . escapeHtml($line['project_prefix'] . $line['project_number']) : '' ?></span>
                    </label>
                </div>
            <?php } ?>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_po_set_project" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
