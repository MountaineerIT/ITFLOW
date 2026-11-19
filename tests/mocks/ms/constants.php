<?php
// Microsoft 365 / Graph stand-in: its own server at 127.0.0.1:8765 (start.sh). functions/mit_m365.php signs in and calls Graph there;
// ITFlow's own mail settings page / mail queue use MICROSOFT_OAUTH_BASE_URL for their token requests (with the trailing slash).
return [
    'MIT_M365_LOGIN' => 'http://127.0.0.1:8765',
    'MIT_M365_GRAPH' => 'http://127.0.0.1:8765/v1.0',
    'MICROSOFT_OAUTH_BASE_URL' => 'http://127.0.0.1:8765/',
];
