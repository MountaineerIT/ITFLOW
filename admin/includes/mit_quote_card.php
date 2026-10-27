<?php
/*
 * Mountaineer IT - the Quote pricing card under Admin > Settings > Quotes
 * (functions/mit_quotes.php, handler in admin/post/mit_quote_settings.php).
 */

$mit_quote_settings = mitQuoteSettings();
$mit_quote_pct = function ($n) {
    return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
};
?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-percent me-2"></i>Quote pricing</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <p class="text-body-secondary">A new quote line or product starts with these - each line can still be changed. Our cost and the pricing are only shown to staff.</p>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Price by</label>
                        <select class="form-select" name="mit_quote_price_method">
                            <option value="markup" <?= $mit_quote_settings['method'] === 'markup' ? 'selected' : '' ?>>Markup - % added on top of our cost</option>
                            <option value="margin" <?= $mit_quote_settings['method'] === 'margin' ? 'selected' : '' ?>>Margin - % of the customer price that is profit</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Default %</label>
                        <div class="input-group">
                            <input type="text" inputmode="decimal" class="form-control" name="mit_quote_price_percent" value="<?= escapeHtml($mit_quote_pct($mit_quote_settings['percent'])) ?>" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Quick % buttons</label>
                        <input type="text" class="form-control" name="mit_quote_price_presets" value="<?= escapeHtml(implode(', ', array_map($mit_quote_pct, $mit_quote_settings['presets']))) ?>" placeholder="15, 20, 25, 35">
                        <div class="form-text">Separated by commas.</div>
                    </div>
                </div>

                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" name="mit_quote_show_images" value="1" id="mitQuoteShowImages" <?= $mit_quote_settings['show_images'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitQuoteShowImages">Item images on quotes (the quote page, the customer's link and the PDF)</label>
                </div>

                <hr>

                <button type="submit" name="mit_edit_quote_pricing" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>
