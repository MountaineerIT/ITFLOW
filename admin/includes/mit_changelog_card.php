<?php
/*
 * Mountaineer IT - our release notes (MIT_CHANGELOG.md) under Admin > Update (functions/mit_common.php mitChangelog).
 * Required in admin/update.php under the version row.
 */

$mit_changelog = function_exists('mitChangelog') ? mitChangelog() : [];
if ($mit_changelog) { ?>
    <div class="border rounded p-3 mb-3">
        <h6 class="mb-2"><i class="fas fa-fw fa-list-ul me-2"></i>Release notes <small class="text-body-secondary fw-normal ms-1">Mountaineer IT build - newest first</small></h6>
        <div style="max-height: 18rem; overflow-y: auto;">
            <?php foreach ($mit_changelog as $mit_i => $mit_release) { ?>
                <div class="<?= $mit_i ? 'border-top pt-3 mt-3' : '' ?>" id="mit-release-<?= escapeHtml($mit_release['version']) ?>">
                    <h5 class="mb-1"><?= escapeHtml($mit_release['version']) ?>
                        <?php if ($mit_release['version'] === mitVersion()) { ?><span class="badge text-bg-success ms-1">Running</span><?php } ?>
                        <small class="text-body-secondary fw-normal ms-2"><?= escapeHtml($mit_release['date']) ?></small>
                    </h5>
                    <ul class="mb-0">
                        <?php foreach ($mit_release['items'] as $mit_item) { ?>
                            <li><?= escapeHtml($mit_item) ?></li>
                        <?php } ?>
                    </ul>
                </div>
            <?php } ?>
        </div>
    </div>
<?php }
