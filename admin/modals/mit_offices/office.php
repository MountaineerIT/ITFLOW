<?php

/*
 * Mountaineer IT - add or change an office (Admin > Settings > Company Details, functions/mit_offices.php; handler
 * admin/post/mit_offices.php). ?office_id= to change one.
 */

require_once '../../includes/modal_header.php';

$office_id = intval($_GET['office_id'] ?? 0);
$office = $office_id ? mitOffice($office_id) : null;
$office_id = $office ? $office_id : 0;
$company = billingCompany();
$countries = settingsLocalizationArrays();
$people = mitOfficePeople();
$agents = mysqli_fetch_all(mysqli_query($mysqli, "SELECT user_id, user_name, user_avatar FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name"), MYSQLI_ASSOC);
$offices = mitOfficesAll();
$phone_code = (string) ($office['phone_country_code'] ?? $company['company_phone_country_code'] ?? '');

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-map-marker-alt me-2"></i><?= $office ? 'Edit ' . escapeHtml($office['name']) : 'New office' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off" data-mit-office-form>
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="office_id" value="<?= $office_id ?>">
        <input type="hidden" name="mit_office_people_present" value="1">

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="office_name" maxlength="100" required value="<?= escapeHtml($office['name'] ?? '') ?>" placeholder="e.g. Medford office">
            </div>
            <div class="col-12">
                <label class="form-label">Street address</label>
                <input type="text" class="form-control" name="office_address" maxlength="200" value="<?= escapeHtml($office['address'] ?? '') ?>" autocomplete="street-address">
            </div>
            <div class="col-md-5">
                <label class="form-label">City</label>
                <input type="text" class="form-control" name="office_city" maxlength="200" value="<?= escapeHtml($office['city'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">State / province</label>
                <input type="text" class="form-control" name="office_state" maxlength="200" value="<?= escapeHtml($office['state'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Postal code</label>
                <input type="text" class="form-control" name="office_zip" maxlength="200" value="<?= escapeHtml($office['zip'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Country</label>
                <select class="form-select select2" name="office_country" id="mitOfficeCountry">
                    <?php $office_country = (string) ($office['country'] ?? $company['company_country'] ?? ''); ?>
                    <?php foreach ($countries['countries'] as $country_name) { ?>
                        <option value="<?= escapeHtml($country_name) ?>" data-iso2="<?= escapeHtml($countries['country_iso2'][$country_name] ?? '') ?>" <?= $country_name === $office_country ? 'selected' : '' ?>><?= escapeHtml($country_name) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input type="hidden" name="office_phone_country_code" value="<?= escapeHtml($phone_code) ?>">
                <input type="tel" class="form-control" name="office_phone" maxlength="200" value="<?= escapeHtml($office ? formatPhoneNumber($office['phone'], $office['phone_country_code'], false) : '') ?>" placeholder="Phone number" data-itflow-phone="office_phone_country_code" data-itflow-phone-country-select="office_country">
            </div>
            <div class="col-12">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="office_email" maxlength="200" value="<?= escapeHtml($office['email'] ?? '') ?>" placeholder="Leave empty to show the ticket address">
                <div class="form-text">On this office's ticket emails, beside the phone. Empty = the address tickets are emailed from.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Who works there</label>
                <select class="form-select select2" name="office_people[]" multiple data-placeholder="Agents in this office" data-mit-office-people>
                    <?php foreach ($agents as $agent) {
                        $agent_id = intval($agent['user_id']);
                        $here = $office_id && in_array($agent_id, $people[$office_id] ?? [], true);
                        $elsewhere = '';
                        foreach ($people as $at => $user_ids) {
                            if ($at !== $office_id && $at !== 0 && in_array($agent_id, $user_ids, true)) {
                                $elsewhere = $offices[$at]['name'] ?? '';
                            }
                        } ?>
                        <option value="<?= $agent_id ?>" <?= $here ? 'selected' : '' ?><?= selectOptionUser($agent_id, $agent['user_name'], $agent['user_avatar'] ?? '') ?>><?= escapeHtml($agent['user_name']) ?><?= $elsewhere !== '' ? ' (now ' . escapeHtml($elsewhere) . ')' : '' ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Picking someone moves them here; taking someone off puts them back in the main office.</div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <?php if ($office) { ?>
            <a href="post.php?mit_office_remove=<?= $office_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger me-auto confirm-link" data-mit-office-remove><i class="fas fa-trash-alt me-2"></i>Remove</a>
        <?php } ?>
        <button type="submit" name="mit_office_save" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
