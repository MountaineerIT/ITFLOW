<?php
/*
 * Mountaineer IT - Procurement settings, on Admin > Settings > Procurement (admin/mit_procurement_settings.php;
 * functions/mit_procurement.php, handler admin/post/mit_procurement_settings.php).
 */

if (function_exists('mitProcReady') && mitProcReady()) {
    $mit_proc = mitProcSettings(); ?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-file-signature me-2"></i>Purchase orders</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">PO prefix</label>
                        <input type="text" class="form-control" name="mit_po_prefix" maxlength="20" value="<?= escapeHtml($mit_proc['prefix']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Next PO number</label>
                        <input type="number" min="<?= intval(mitPoNextNumber()) ?>" class="form-control" name="mit_po_next" value="<?= intval(mitPoNextNumber()) ?>" required>
                        <div class="form-text">Only up - numbers already used are never given out again.</div>
                    </div>
                    <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-check-double me-2"></i>Approval</h6></div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="mitPoApprovalOn" name="mit_po_approval_on" value="1" <?= $mit_proc['approval_on'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitPoApprovalOn">Purchase orders need approval</label>
                        </div>
                        <div class="form-text">Off: a PO is approved as soon as it is submitted.</div>
                    </div>
                    <div class="col-md-4" data-mit-approval>
                        <label class="form-label">Needs approval when over</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-fw fa-dollar-sign"></i></span>
                            <input type="text" inputmode="decimal" class="form-control" name="mit_po_approval_over" value="<?= number_format($mit_proc['approval_over'], 2, '.', '') ?>" required>
                        </div>
                        <div class="form-text">0 = every PO needs approving. A PO with a line that has no cost always does. Approvers: roles with Full Procurement access (Admin > Roles), and admins.</div>
                    </div>
                    <script>
                        (function () {
                            const on = document.getElementById('mitPoApprovalOn');
                            const show = function () {
                                document.querySelectorAll('[data-mit-approval]').forEach(function (el) { el.classList.toggle('opacity-50', !on.checked); });
                            };
                            on.addEventListener('change', show);
                            show();
                        })();
                    </script>
                    <div class="col-12">
                        <label class="form-label">Ship to (our address on POs)</label>
                        <textarea class="form-control" name="mit_po_ship_to" rows="3"><?= escapeHtml($mit_proc['ship_to']) ?></textarea>
                    </div>
                </div>

                <hr>

                <button type="submit" name="mit_edit_procurement_settings" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>

<?php }
