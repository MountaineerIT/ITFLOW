<?php
/*
 * Mountaineer IT - a warning next to the bell when cron needs attention, for admins only
 * (mitCronNavHtml in functions/mit_cron_health.php). Nothing at all when all is well.
 */
if (function_exists('mitCronNavHtml')) {
    echo mitCronNavHtml();
}
