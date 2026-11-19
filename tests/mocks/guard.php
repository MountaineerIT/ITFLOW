<?php
const MOCK_ENABLED_MARKER = '.mit-test-stand-in';
/*
 * Mountaineer IT - test stand-ins only. Every stand-in in tests/mocks/ requires this first: it answers the server itself
 * (127.0.0.1 / ::1), and only where tests/mocks/install.php (which refuses anything but a *.test install) or ms/start.sh put a
 * '.mit-test-stand-in' marker beside it - so the copy in production's checkout (it keeps tests/) is a 404 to everyone.
 */
// Two locks for a web request: the installer's marker beside this file (tests/mocks/ itself never has one, so the copy in a real
// install's checkout never answers - behind a reverse proxy on the same server every visitor can look like 127.0.0.1), and loopback
$mit_mock_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (PHP_SAPI !== 'cli' && (!is_file(__DIR__ . '/' . MOCK_ENABLED_MARKER) || !in_array($mit_mock_ip, ['127.0.0.1', '::1'], true))) {
    http_response_code(404);
    exit;
}
unset($mit_mock_ip);

/** The path after index.php ("/v1/embeddings"), without the query. */
function mockPath() {
    $path = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $pos = strpos($uri, 'index.php');
        $path = $pos === false ? $uri : substr($uri, $pos + 9);
    }
    return '/' . ltrim($path, '/');
}

/** A JSON answer and stop. */
function mockJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** The request body as JSON (or form fields), [] when none. */
function mockBody() {
    $raw = (string) file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    parse_str($raw, $form);
    return $form ?: $_POST;
}

/** State kept between requests in a JSON file, one per stand-in - a fixed folder, so the web server and a scenario run
 * from the command line (root) read the same file whatever their temp folders are. */
const MOCK_STATE_DIR = '/var/tmp/itflow_mocks';
function mockStateFile($name) {
    if (!is_dir(MOCK_STATE_DIR)) {
        @mkdir(MOCK_STATE_DIR, 0777, true);
        @chmod(MOCK_STATE_DIR, 0777);
    }
    return MOCK_STATE_DIR . "/$name.json";
}
function mockLoad($name, array $default = []) {
    $data = json_decode((string) @file_get_contents(mockStateFile($name)), true);
    return is_array($data) ? $data : $default;
}
function mockSave($name, array $state) {
    $file = mockStateFile($name);
    file_put_contents($file, json_encode($state), LOCK_EX);
    @chmod($file, 0666);
}
