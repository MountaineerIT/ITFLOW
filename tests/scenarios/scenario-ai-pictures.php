<?php
/*
 * Pictures with an Ask AI question (functions/mit_ai_ask.php mitAiImageData / mitAiAsk $images, 26.11.8.5 - the user's choice: sent to the
 * provider as they are, with a warning, never kept). A throw-away stand-in provider (a PHP file put in the install for this run and removed
 * after) records what reaches it: the picture goes as an image_url part, re-drawn (no metadata), no bigger than 1600 a side; the question's
 * text part is still cleaned (a password typed into it never leaves); nothing of the picture is stored; a follow-up does not send it
 * again; an empty question with a picture gets the default question; a provider that refuses pictures says to pick a model that reads them;
 * what is not a picture is refused.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiImageData') || !function_exists('mitAiAskReady') || !mitAiAskReady()) {
    echo "  skip - pictures or M045 not here\n";
    exit(0);
}
$root = getcwd();
$echo_file = "$root/zz_scenario_ai_echo.php";
$record = sys_get_temp_dir() . '/zz_scenario_ai_echo_' . getmypid() . '.json';
$url = 'http://127.0.0.1/zz_scenario_ai_echo.php';
file_put_contents($echo_file, '<?php
$body = file_get_contents("php://input");
file_put_contents(' . var_export($record, true) . ', $body);
header("Content-Type: application/json");
if (strpos($body, "image_url") !== false && file_exists(' . var_export($record . '.fail', true) . ')) { http_response_code(400); echo json_encode(["error" => ["message" => "this model does not take images"]]); exit; }
echo json_encode(["choices" => [["message" => ["content" => "Stand-in answer: the screen shows error 0x80070005 - access denied."]]], "usage" => ["prompt_tokens" => 10, "completion_tokens" => 5]]);
');
@chmod($record, 0666);
$sent = function () use ($record) {
    return json_decode((string) @file_get_contents($record), true) ?: [];
};
$saved = [];
foreach (['ai_helper_enabled', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_helper_monthly_tokens'] as $name) {
    $saved[$name] = mitSetting($name);
}
$png = function ($w, $h) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 200));
    imagestring($im, 5, 10, 10, 'Error 0x80070005', imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagepng($im);
    imagedestroy($im);
    return ob_get_clean();
};
$provider_id = 0;
try {
    // The picture itself
    $data = mitAiImageData($png(400, 200));
    scenarioCheck('a PNG becomes a PNG data: address', strpos($data, 'data:image/png;base64,') === 0, true);
    $big = mitAiImageData($png(3200, 1000));
    $size = getimagesizefromstring(base64_decode(substr($big, strpos($big, ',') + 1)));
    scenarioCheck('a big screenshot is drawn at 1600 a side', [$size[0], $size[1]], [1600, 500]);
    // Text hidden after the image data is dropped by the re-draw
    $hidden = mitAiImageData($png(100, 100) . 'HIDDEN-CANARY-BYTES');
    scenarioCheck('bytes after the image are not sent', strpos(base64_decode(substr($hidden, strpos($hidden, ',') + 1)), 'HIDDEN-CANARY') === false, true);
    foreach (['not a picture' => 'just some text', 'an SVG' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'] as $label => $bytes) {
        try {
            mitAiImageData($bytes);
            scenarioCheck("$label is refused", 'accepted', 'refused');
        } catch (RuntimeException $e) {
            scenarioCheck("$label is refused", 'refused', 'refused');
        }
    }

    // A question with a picture, through the stand-in
    mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ pictures stand-in%'");
    mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ pictures stand-in', ai_provider_kind = 'custom', ai_provider_api_url = '$url', ai_provider_api_key = 'x', ai_provider_redact = 1");
    $provider_id = mysqli_insert_id($mysqli);
    $old_models = scenarioColumn("SELECT ai_model_id FROM ai_models WHERE ai_model_use_case = 'Tickets'");
    mysqli_query($mysqli, "UPDATE ai_models SET ai_model_use_case = 'zz-Tickets' WHERE ai_model_use_case = 'Tickets'");
    mysqli_query($mysqli, "INSERT INTO ai_models SET ai_model_name = 'zz-vision', ai_model_use_case = 'Tickets', ai_model_ai_provider_id = $provider_id");
    mitSetSetting('ai_helper_enabled', '1');
    mitSetSetting('ai_helper_embed_provider', '');
    mitSetSetting('ai_helper_monthly_tokens', '0');
    scenarioCheck('Ask AI is open', mitAiAskCan(), true);

    [$ok, $conversation_id, $out] = mitAiAsk(0, 'Outlook shows this. The admin password is CanaryPicQ9x!', 0, [$data]);
    scenarioCheck('answered', $ok, true);
    $body = $sent();
    $last = end($body['messages']);
    $parts = is_array($last['content'] ?? null) ? $last['content'] : [];
    $image = array_values(array_filter($parts, fn($p) => ($p['type'] ?? '') === 'image_url'));
    $text = implode(' ', array_column(array_filter($parts, fn($p) => ($p['type'] ?? '') === 'text'), 'text'));
    scenarioCheck('the picture went as an image_url part', ($image[0]['image_url']['url'] ?? '') === $data, true);
    scenarioCheck('the question went with it', strpos($text, 'Outlook shows this') !== false, true);
    scenarioCheck('a password typed into the question did not', stripos(json_encode($body), 'CanaryPicQ9x') === false, true);
    $stored = implode("\n", scenarioColumn("SELECT content FROM mit_ai_messages WHERE conversation_id = $conversation_id"));
    scenarioCheck('the question is kept as text, saying a picture went with it', strpos($stored, 'A picture was sent with this question - not kept.') !== false, true);
    scenarioCheck('nothing of the picture is stored', strpos($stored, 'base64') === false && strpos(scenarioValue("SELECT GROUP_CONCAT(COALESCE(sources, '')) FROM mit_ai_messages WHERE conversation_id = $conversation_id"), 'data:image') === false, true);
    scenarioCheck('nor the password', stripos($stored, 'CanaryPicQ9x') === false, true);

    // A follow-up: the earlier picture is not sent again
    [$ok] = mitAiAsk($conversation_id, 'And if that does not work?');
    scenarioCheck('a follow-up does not send the picture again', $ok && strpos(json_encode($sent()), 'image_url') === false, true);

    // A picture with no question
    [$ok, $conversation_2] = mitAiAsk(0, '   ', 0, [$data]);
    scenarioCheck('a picture alone asks the default question', $ok && strpos((string) scenarioValue("SELECT content FROM mit_ai_messages WHERE conversation_id = $conversation_2 AND role = 'user'"), MIT_AI_IMAGE_QUESTION) === 0, true);

    // A model that does not take pictures
    touch($record . '.fail');
    [$ok, , $error] = mitAiAsk(0, 'What is this?', 0, [$data]);
    scenarioCheck('a provider that refuses pictures: not answered', $ok, false);
    scenarioCheck('... and the agent is told to pick a model that reads them', strpos((string) $error, 'Pictures need a model that can read them') !== false, true);
} finally {
    @unlink($echo_file);
    @unlink($record);
    @unlink($record . '.fail');
    mysqli_query($mysqli, "DELETE FROM mit_ai_conversations WHERE title LIKE 'Outlook shows this%' OR title = '" . escapeSql(MIT_AI_IMAGE_QUESTION) . "' OR title = 'What is this?'");
    if ($provider_id) {
        mysqli_query($mysqli, "DELETE FROM ai_models WHERE ai_model_ai_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_usage WHERE ai_usage_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = $provider_id");
    }
    mysqli_query($mysqli, "UPDATE ai_models SET ai_model_use_case = 'Tickets' WHERE ai_model_use_case = 'zz-Tickets'");
    foreach ($saved as $name => $value) {
        $value === '' || $value === null ? mitDeleteSetting($name) : mitSetSetting($name, $value);
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
