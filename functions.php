<?php

// Role check failed wording
DEFINE("WORDING_ROLECHECK_FAILED", "You are not permitted to do that!");

// Ceiling on what the mail queue will attach to a single message. Uploads are
// allowed up to 500 MB, which no mail server will accept - anything over this is
// left on the ticket for the recipient to download instead of being emailed.
DEFINE("MAX_EMAIL_ATTACHMENT_BYTES", 10 * 1024 * 1024);

// functions.php is now a loader. Helper functions live in topical files
// under functions/ - see each file's header comment for scope.

require_once __DIR__ . '/functions/security.php';
require_once __DIR__ . '/functions/sanitize.php';
require_once __DIR__ . '/functions/format.php';
require_once __DIR__ . '/functions/request.php';
require_once __DIR__ . '/functions/files.php';
require_once __DIR__ . '/functions/domain.php';
require_once __DIR__ . '/functions/certificate.php';
require_once __DIR__ . '/functions/network.php';
require_once __DIR__ . '/functions/user.php';
require_once __DIR__ . '/functions/asset_interface.php';
require_once __DIR__ . '/functions/rack.php';
require_once __DIR__ . '/functions/asset.php';
require_once __DIR__ . '/functions/contact.php';
require_once __DIR__ . '/functions/software.php';
require_once __DIR__ . '/functions/location.php';
require_once __DIR__ . '/functions/vendor.php';
require_once __DIR__ . '/functions/credential.php';
require_once __DIR__ . '/functions/links.php';
require_once __DIR__ . '/functions/auth.php';
require_once __DIR__ . '/functions/logging.php';
require_once __DIR__ . '/functions/app.php';
require_once __DIR__ . '/functions/payments.php';
require_once __DIR__ . '/functions/sla.php';
require_once __DIR__ . '/functions/ai.php';
require_once __DIR__ . '/functions/export.php';
require_once __DIR__ . '/functions/calendar.php';
require_once __DIR__ . '/functions/backup.php';
require_once __DIR__ . '/functions/search.php';
require_once __DIR__ . '/functions/notifications.php';
require_once __DIR__ . '/functions/dashboard.php';
require_once __DIR__ . '/functions/reports.php';
require_once __DIR__ . '/functions/client_overview.php';
require_once __DIR__ . '/functions/tags.php';
require_once __DIR__ . '/functions/ticket_fields.php';
require_once __DIR__ . '/functions/ticket.php';
require_once __DIR__ . '/functions/project.php';
require_once __DIR__ . '/functions/service.php';

// Mountaineer IT additions
require_once __DIR__ . '/functions/mit_common.php';
require_once __DIR__ . '/functions/mit_calendar.php';
require_once __DIR__ . '/functions/mit_m365.php';
require_once __DIR__ . '/functions/mit_qbo.php';
require_once __DIR__ . '/functions/mit_usage.php';
require_once __DIR__ . '/functions/mit_quotes.php';
require_once __DIR__ . '/functions/mit_ticket_layout.php';
require_once __DIR__ . '/functions/mit_procurement.php';
require_once __DIR__ . '/functions/mit_timesheets.php';
require_once __DIR__ . '/functions/mit_dispatch.php';
require_once __DIR__ . '/functions/mit_list_views.php';
require_once __DIR__ . '/functions/mit_cron_health.php';
require_once __DIR__ . '/functions/mit_ninjaone.php';
require_once __DIR__ . '/functions/mit_projects.php';
require_once __DIR__ . '/functions/mit_reply_recipients.php';
require_once __DIR__ . '/functions/mit_theme.php';
require_once __DIR__ . '/functions/mit_boards.php';
require_once __DIR__ . '/functions/mit_ct.php';
require_once __DIR__ . '/functions/mit_no_response.php';
require_once __DIR__ . '/functions/mit_notifications.php';
require_once __DIR__ . '/functions/mit_align.php';
