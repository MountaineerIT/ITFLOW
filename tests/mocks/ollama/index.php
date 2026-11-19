<?php
/*
 * Mountaineer IT - stand-in for Ollama on the ITFlow server (served as ollama-mock; MIT_OLLAMA_BASE in a test install's config.php
 * points functions/mit_ai_local.php at it).
 *
 *   GET  /api/version            {"version": ...}            - 503 while "not running"
 *   GET  /api/tags               the models pulled
 *   POST /api/pull               {"model": ...} -> {"status": "success"}, the model is there from then on
 *   POST /v1/embeddings          OpenAI-style vectors (the same synonym groups as ai-mock - ../ai-mock/embed.php); 404 for a model
 *                                not pulled
 *   POST /v1/chat/completions    a plain "MOCK ANSWER"
 *   GET  /_state?running=0|1&model=0|1&reset=1   set / show the state (reset = running, no model, no calls; then running / model)
 *   GET  /_calls, /_clear        the requests received (path + body) / forget them
 */
require __DIR__ . '/guard.php';
require __DIR__ . '/../ai-mock/embed.php'; // install.php puts every stand-in into the same install, ai-mock beside this one

const MOCK_NAME = 'ollama';
const OLLAMA_MOCK_VERSION = '0.40.11';
$fresh = ['running' => true, 'models' => [], 'calls' => []];
$path = mockPath();

if ($path === '/_state') {
    $state = mockUpdate(MOCK_NAME, function ($s) use ($fresh) {
        $s = !empty($_GET['reset']) ? $fresh : $s + $fresh;
        if (isset($_GET['running'])) {
            $s['running'] = $_GET['running'] === '1';
        }
        if (isset($_GET['model'])) {
            $s['models'] = $_GET['model'] === '1' ? ['nomic-embed-text:latest'] : [];
        }
        return $s;
    });
    unset($state['calls']);
    mockJson($state);
}
if ($path === '/_calls') {
    mockCallsOut(MOCK_NAME);
}
if ($path === '/_clear') {
    mockUpdate(MOCK_NAME, fn($s) => ['calls' => []] + $s + $fresh);
    mockJson(['ok' => true]);
}

$state = mockLoad(MOCK_NAME, []) + $fresh;
$body = mockBody();
mockRecord(MOCK_NAME, $path, $body);
if (empty($state['running'])) {
    mockJson(['error' => 'ollama stand-in: not running'], 503); // (the real one refuses the connection)
}
$has = function ($model) use ($state) {
    $model = (string) $model;
    return in_array($model, $state['models'], true) || in_array("$model:latest", $state['models'], true);
};

if ($path === '/api/version') {
    mockJson(['version' => OLLAMA_MOCK_VERSION]);
}
if ($path === '/api/tags') {
    mockJson(['models' => array_map(fn($name) => ['name' => $name, 'model' => $name, 'size' => 274302450, 'details' => ['family' => 'nomic-bert']], $state['models'])]);
}
if ($path === '/api/pull') {
    $model = trim((string) ($body['model'] ?? $body['name'] ?? ''));
    if ($model === '') {
        mockJson(['error' => 'model is required'], 400);
    }
    $name = strpos($model, ':') === false ? "$model:latest" : $model;
    mockUpdate(MOCK_NAME, function ($s) use ($name, $fresh) {
        $s += $fresh;
        $s['models'] = array_values(array_unique(array_merge($s['models'], [$name])));
        return $s;
    });
    mockJson(['status' => 'success']);
}
if ($path === '/v1/embeddings' || $path === '/api/embed') {
    if (!$has($body['model'] ?? '')) {
        mockJson(['error' => ['message' => 'model "' . ($body['model'] ?? '') . '" not found, try pulling it first', 'type' => 'api_error']], 404);
    }
    mockJson(mockEmbeddingsAnswer($body));
}
if ($path === '/v1/chat/completions') {
    mockJson(['object' => 'chat.completion', 'model' => (string) ($body['model'] ?? ''),
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'MOCK ANSWER'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]]);
}
mockJson(['error' => "No such address in the Ollama stand-in: $path"], 404);
