<?php
// QuickBooks Online stand-in: functions/mit_qbo.php calls these instead of Intuit's sign-in, token, revoke and API addresses
return [
    'MIT_QBO_AUTH_URL' => '{MOCK}/connect/oauth2',
    'MIT_QBO_TOKEN_URL' => '{MOCK}/oauth2/v1/tokens/bearer',
    'MIT_QBO_REVOKE_URL' => '{MOCK}/oauth2/v1/tokens/revoke',
    'MIT_QBO_API_BASE' => '{MOCK}',
];
