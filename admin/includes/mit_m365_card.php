<?php
/*
 * Mountaineer IT - the Microsoft 365 app card at the top of Admin > Settings > Mail
 * (functions/mit_m365.php, handlers in admin/post/mit_m365.php).
 */

$mit_m365_app = mitM365App();
$mit_m365_csrf = $_SESSION['csrf_token'];
$mit_m365_mailbox_connected = $mit_m365_app && !empty($config_mail_oauth_refresh_token)
    && ($config_mail_oauth_client_id ?? '') === $mit_m365_app['client_id'];
$mit_m365_mail_uses_ms = ($config_smtp_provider ?? '') === 'microsoft_oauth' || ($config_imap_provider ?? '') === 'microsoft_oauth';

// Secrets still under the old derived key are moved to config.php's key (cheap when there is nothing to do)
mitUpgradeSecrets();

// Is mail going through Microsoft Graph? Decided from the mailbox's last token (cached) - opening this page
// never calls Microsoft; Test IMAP / Test OAuth below do the live checks.
$mit_m365_graph_scopes = mitM365MailGraphScopes();
$mit_m365_graph_mail = $mit_m365_mailbox_connected && in_array('Mail.Send', $mit_m365_graph_scopes, true) && in_array('Mail.ReadWrite', $mit_m365_graph_scopes, true);
$mit_m365_graph_unknown = $mit_m365_mailbox_connected && !$mit_m365_graph_scopes;
$mit_m365_needs_update = $mit_m365_app && intval($mit_m365_app['permissions_version']) < MIT_M365_PERMISSIONS_VERSION;
$mit_m365_cert_expired = $mit_m365_app && $mit_m365_app['cert_expires'] !== '' && strtotime($mit_m365_app['cert_expires']) < time();
?>

<div class="card card-dark mb-3" id="mitM365">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title mb-0"><i class="fab fa-fw fa-microsoft me-2"></i>Microsoft 365</h3>
        <?php if ($mit_m365_app) { ?>
            <span class="badge rounded-pill <?= $mit_m365_mailbox_connected ? 'text-bg-success' : 'text-bg-warning' ?> ms-auto"><?= $mit_m365_mailbox_connected ? 'Ready' : 'Mailbox not connected' ?></span>
        <?php } ?>
    </div>
    <div class="card-body">

        <?php if (mitSecretKey() === '') { ?>
            <div class="alert alert-danger">
                <strong>ITFlow cannot keep its own secret key</strong> - <code>config.php</code> cannot be written by the web server, so the
                certificate and Outlook sign-ins would be locked with a key anyone with the installation id could work out. Add a line
                <code>$config_mit_secret_key = '&lt;48 random letters and digits&gt;';</code> to <code>config.php</code> (or let the web server write it once).
            </div>
        <?php } ?>

        <?php if ($mit_m365_app) {
            $mit_m365_expires = $mit_m365_app['cert_expires'] ? strtotime($mit_m365_app['cert_expires']) : 0; ?>

            <div class="border rounded p-3 d-flex flex-wrap gap-3 align-items-start mb-3">
                <i class="fab fa-microsoft fa-lg text-primary mt-1"></i>
                <div class="flex-grow-1" style="min-width: 16rem;">
                    ITFlow's own app <strong><?= escapeHtml($mit_m365_app['name']) ?></strong> in your Microsoft 365,
                    set up <?= escapeHtml(date('M j, Y', strtotime($mit_m365_app['created_at']))) ?><?= $mit_m365_app['created_by'] !== '' ? ' by ' . escapeHtml($mit_m365_app['created_by']) : '' ?><?= $mit_m365_app['setup'] === 'manual' ? ' (by hand)' : '' ?>.
                    It signs in as the mailbox you connect below, and staff can connect their own Outlook calendars to it.
                    <div class="small text-body-secondary mt-1">
                        Certificate expires <?= $mit_m365_expires ? escapeHtml(date('M j, Y', $mit_m365_expires)) : 'unknown' ?> and is replaced automatically <?= MIT_M365_RENEW_DAYS ?> days before.
                        Application (client) ID <code><?= escapeHtml($mit_m365_app['client_id']) ?></code>
                    </div>
                    <?php if ($mit_m365_app['renew_error'] !== '') { ?>
                        <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle me-1"></i>Last automatic renewal failed: <?= escapeHtml($mit_m365_app['renew_error']) ?></div>
                    <?php } ?>
                </div>
                <div class="d-flex gap-2">
                    <form action="post.php" method="post" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= $mit_m365_csrf ?>">
                        <button type="submit" name="mit_m365_renew" class="btn btn-sm btn-outline-secondary">Replace certificate</button>
                    </form>
                    <a href="post.php?mit_m365_remove=1&csrf_token=<?= $mit_m365_csrf ?>" class="btn btn-sm btn-outline-danger confirm-link"
                        data-confirm-title="Stop using this Microsoft 365 app?" data-confirm-button="Remove"
                        data-confirm-text="ITFlow stops sending and receiving through it, and Outlook calendars stop syncing. The app stays in your Microsoft 365 until you delete it in the Entra admin center.">Remove</a>
                </div>
            </div>

            <?php if (mitM365KeyPem() === '') { ?>
                <div class="alert alert-danger">
                    <strong>This server cannot read the app's certificate</strong>, so it cannot sign in to Microsoft 365 - mail through it
                    has stopped. That happens when ITFlow is restored onto a new server: the certificate is encrypted with a key kept in
                    <code>config.php</code>. Either copy <code>$config_mit_secret_key</code> from the old server's <code>config.php</code> into this
                    one's, or click <strong>Remove</strong> and set it up again (then connect the mailbox, and staff reconnect Outlook).
                </div>
            <?php } elseif ($mit_m365_cert_expired) { ?>
                <div class="alert alert-danger">
                    <strong>The app's certificate has expired</strong>, so ITFlow can no longer sign in to Microsoft 365 or replace it by itself.
                    Click <strong>Remove</strong> and set the app up again (then connect the mailbox, and staff reconnect Outlook).
                </div>
            <?php } ?>

            <?php if ($mit_m365_needs_update) { ?>
                <div class="alert alert-warning">
                    <strong>Update the app's permissions</strong> so ITFlow sends and receives mail through Microsoft Graph -
                    Microsoft's current way, with no SMTP or IMAP to switch on for the mailbox.
                    Sign in once as a <strong>Global Administrator</strong>:
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-primary js-mit-m365-start" data-purpose="update"><i class="fab fa-microsoft me-2"></i>Update permissions</button>
                    </div>
                    <div class="small mt-2">
                        Or add them by hand in the Entra admin center (app <em><?= escapeHtml($mit_m365_app['name']) ?></em> &gt; API permissions):
                        Microsoft Graph, Delegated, <code>Mail.ReadWrite, Mail.ReadWrite.Shared, Mail.Send, Mail.Send.Shared</code>, then
                        <strong>Grant admin consent</strong>. ITFlow picks them up within the hour.
                    </div>
                </div>
            <?php } ?>

            <?php if (mitM365CalendarApp()) { ?>
                <div class="small text-body-secondary mb-2"><i class="fas fa-calendar-check me-2 text-success"></i>Staff Outlook calendars connect through their own
                    calendar-only app (<code><?= escapeHtml(mitM365CalendarApp()['client_id']) ?></code>) - their sign-ins cannot reach their mail.</div>
                <?php if (mitM365CalendarApp()['permissions_version'] < MIT_M365_CAL_PERMISSIONS_VERSION) { ?>
                    <div class="alert alert-warning">
                        <strong>Update the calendar app's permissions</strong> - it was made without the sign-in permissions
                        (<code>offline_access, openid, profile, email</code>), so Microsoft may ask staff to approve them when they connect Outlook.
                        Sign in once as a <strong>Global Administrator</strong>; Outlook connections keep working.
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-primary js-mit-m365-start" data-purpose="calendar_update"><i class="fab fa-microsoft me-2"></i>Update permissions</button>
                        </div>
                    </div>
                <?php } ?>
            <?php } elseif ($mit_m365_app['setup'] === 'automatic') { ?>
                <div class="alert alert-warning">
                    <strong>Give Outlook calendars their own app.</strong> Staff Outlook sign-ins go through this app, which also has the Mail
                    permissions for ITFlow's mailbox - so a stolen copy of this server could read their mail. A calendar-only app (Calendars and
                    User.Read, nothing else) fixes that. Sign in once as a <strong>Global Administrator</strong>; anyone who connected Outlook
                    reconnects once afterwards.
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-primary js-mit-m365-start" data-purpose="calendar"><i class="fab fa-microsoft me-2"></i>Make the calendar app</button>
                    </div>
                </div>
            <?php } ?>

            <?php if ($mit_m365_mailbox_connected) { ?>
                <?php if ($mit_m365_graph_unknown) { ?>
                    <div class="text-success"><i class="fas fa-check-circle me-2"></i>Mailbox connected - ITFlow checks which way mail goes the next time it sends or receives
                        (or use <strong>Test IMAP</strong> on the <a href="?tab=tests">Tests</a> tab now).</div>
                <?php } elseif ($mit_m365_graph_mail) { ?>
                    <div class="text-success"><i class="fas fa-check-circle me-2"></i>Mailbox connected - mail is sent and received through Microsoft Graph.
                        To use a different mailbox, click Connect Microsoft 365 on the <a href="?tab=oauth">OAuth</a> tab again.</div>
                <?php } else { ?>
                    <div class="text-success"><i class="fas fa-check-circle me-2"></i>Mailbox connected - mail still goes through SMTP and IMAP, because the app does not have the Mail permissions
                        (<strong>Update permissions</strong> when it is offered above, or add them to the app in Entra).</div>
                <?php } ?>
            <?php } else { ?>
                <div class="alert alert-info mb-0">
                    <strong>Next: connect the mailbox ITFlow should use.</strong>
                    <?php if (!$mit_m365_mail_uses_ms) { ?>
                        On the <a href="?tab=sending">Sending</a> and/or <a href="?tab=receiving">Receiving</a> tab, set the provider to <em>Microsoft 365 (OAuth)</em> and save. Then
                    <?php } ?>
                    click <strong>Connect Microsoft 365</strong> on the <a href="?tab=oauth">OAuth</a> tab and sign in as that mailbox (for example service@).
                </div>
            <?php } ?>

        <?php } else { ?>

            <p class="mb-3">
                ITFlow can create its own app in your Microsoft 365 for email and Outlook calendars - with the permissions it needs, approved
                for your organization, and a certificate it renews itself. You sign in once as a Global Administrator; nothing else to copy or paste.
            </p>
            <button type="button" class="btn btn-primary js-mit-m365-start" data-purpose="create"><i class="fab fa-microsoft me-2"></i>Set up automatically</button>
            <button type="button" class="btn btn-link" data-bs-toggle="collapse" data-bs-target="#mitM365Manual">Set up by hand instead</button>

            <div class="collapse mt-3" id="mitM365Manual">
                <div class="border rounded p-3">
                    <p class="mb-2">If your organization blocks the code sign-in, create the app by hand in the
                        <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Entra admin center</a>:</p>
                    <ol class="mb-3">
                        <li><strong>App registrations &gt; New registration</strong>: name it <em><?= escapeHtml(MIT_M365_APP_NAME) ?></em>, single tenant,
                            Web redirect URI <code><?= escapeHtml(mitM365RedirectUris()[0]) ?></code>. Then under <strong>Authentication</strong> add a second Web redirect URI
                            <code><?= escapeHtml(mitM365RedirectUris()[1]) ?></code>.</li>
                        <li><strong>API permissions &gt; Add a permission</strong>, all <em>Delegated</em>:
                            Microsoft Graph <code><?= escapeHtml(implode(', ', MIT_M365_GRAPH_SCOPES)) ?></code>;
                            Office 365 Exchange Online (APIs my organization uses) <code><?= escapeHtml(implode(', ', MIT_M365_EXCHANGE_SCOPES)) ?></code>.
                            Then <strong>Grant admin consent</strong>.</li>
                        <li><strong>Certificates &amp; secrets &gt; Certificates &gt; Upload certificate</strong>:
                            <a href="post.php?mit_m365_certificate=1&csrf_token=<?= $mit_m365_csrf ?>">download ITFlow's certificate</a> and upload it.</li>
                        <li>Copy the <strong>Directory (tenant) ID</strong>, <strong>Application (client) ID</strong> and <strong>Object ID</strong> from the app's Overview here:</li>
                    </ol>
                    <form action="post.php" method="post" autocomplete="off" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= $mit_m365_csrf ?>">
                        <div class="col-md-4"><input type="text" class="form-control" name="tenant_id" placeholder="Directory (tenant) ID" required></div>
                        <div class="col-md-4"><input type="text" class="form-control" name="client_id" placeholder="Application (client) ID" required></div>
                        <div class="col-md-4"><input type="text" class="form-control" name="object_id" placeholder="Object ID" required></div>
                        <div class="col-12"><button type="submit" name="mit_m365_manual" class="btn btn-primary">Use this app</button></div>
                    </form>
                </div>
            </div>


        <?php } ?>

            <div class="border rounded p-3 mt-3" id="mitM365Flow" hidden>
                <div class="js-step-code" hidden>
                    <div class="mb-2">
                        1. Go to <a class="js-verify fw-semibold" href="https://microsoft.com/devicelogin" target="_blank" rel="noopener">microsoft.com/devicelogin</a>
                        and enter this code:
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <code class="fs-3 fw-bold js-code"></code>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-copy"><i class="far fa-copy me-1"></i>Copy</button>
                    </div>
                    <div class="mb-2">2. Sign in as a <strong>Global Administrator</strong> and accept the permissions ("Microsoft Graph Command Line Tools").</div>
                    <div class="text-body-secondary"><span class="spinner-border spinner-border-sm me-2"></span><span class="js-status">Waiting for you to sign in...</span></div>
                </div>
                <div class="text-danger js-error" hidden></div>
            </div>


            <script>
            (function () {
                const flow = document.getElementById('mitM365Flow');
                const buttons = document.querySelectorAll('.js-mit-m365-start');
                if (!flow || !buttons.length) {
                    return;
                }
                const csrf = <?= json_encode($mit_m365_csrf) ?>;
                const step = flow.querySelector('.js-step-code');
                const errorBox = flow.querySelector('.js-error');
                const statusText = flow.querySelector('.js-status');

                let start = buttons[0];
                let purpose = 'create';

                function post(action) {
                    const body = new URLSearchParams({csrf_token: csrf, purpose: purpose});
                    body.set(action, '1');
                    return fetch('post.php', {method: 'POST', credentials: 'same-origin', body: body})
                        .then(function (res) { return res.json(); });
                }
                function fail(message, blocked) {
                    step.hidden = true;
                    errorBox.hidden = false;
                    const manual = document.getElementById('mitM365Manual');
                    errorBox.textContent = message + (blocked ? (manual ? ' Your organization blocks this kind of sign-in - use "Set up by hand instead" below.'
                        : ' Your organization blocks this kind of sign-in - add the permissions by hand as described above.') : '');
                    start.disabled = false;
                    if (blocked && manual) {
                        manual.classList.add('show');
                    }
                }

                buttons.forEach(function (button) { button.addEventListener('click', function () {
                    start = button;
                    purpose = button.dataset.purpose || 'create';
                    start.disabled = true;
                    flow.hidden = false;
                    errorBox.hidden = true;
                    post('mit_m365_start').then(function (json) {
                        if (!json.user_code) {
                            fail(json.error || 'Could not start the Microsoft sign-in.');
                            return;
                        }
                        step.hidden = false;
                        flow.querySelector('.js-code').textContent = json.user_code;
                        flow.querySelector('.js-verify').href = json.verification_uri;
                        flow.querySelector('.js-copy').onclick = function () {
                            navigator.clipboard && navigator.clipboard.writeText(json.user_code);
                        };
                        // Ask until Microsoft answers, the code expires, or the server stops answering in JSON (session gone)
                        let interval = json.interval * 1000;
                        let misses = 0;
                        const deadline = Date.now() + (json.expires_in || 900) * 1000;
                        const poll = function () {
                            if (Date.now() > deadline) {
                                fail('The sign-in code has expired - start again.');
                                return;
                            }
                            post('mit_m365_poll').then(function (result) {
                                misses = 0;
                                if (result.pending) {
                                    if (result.slow_down) {
                                        interval += 5000;  // Microsoft asked us to ask less often
                                    }
                                    setTimeout(poll, interval);
                                } else if (result.ok) {
                                    statusText.textContent = 'Done - reloading...';
                                    window.location.href = 'settings_mail.php?tab=oauth';
                                } else {
                                    fail(result.error || 'That did not work.', result.blocked);
                                }
                            }).catch(function () {
                                if (++misses >= 3) {
                                    fail('The server stopped answering - reload the page and try again.');
                                } else {
                                    setTimeout(poll, interval);
                                }
                            });
                        };
                        setTimeout(function () {
                            statusText.textContent = 'Waiting for you to sign in... (once you have, this takes up to a minute)';
                            poll();
                        }, json.interval * 1000);
                    }).catch(function () {
                        fail('Could not reach the server - check the connection and try again.');
                    });
                }); });
            })();
            </script>
    </div>
</div>
