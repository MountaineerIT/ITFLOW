<?php

/*
 * Mountaineer IT - Admin > Integrations > AI helper (functions/mit_ai_helper.php; handlers admin/post/mit_ai_helper.php): on / off,
 * what goes in the library, the embeddings model for meaning-based search, the library's state with Rebuild, a test search, and the
 * tickets / documents that had passwords or keys taken out (to move them into the client's password vault).
 */

require_once "includes/inc_all_admin.php";

if (!mitAiHelperReady()) {
    echo '<div class="alert alert-warning">The AI helper needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitAiHelperSettings();
$stats = mitAiIndexStats();
$csrf = $_SESSION['csrf_token'];
$providers = mysqli_fetch_all(mysqli_query($mysqli, "SELECT ai_provider_id, ai_provider_name, ai_provider_kind FROM ai_providers ORDER BY ai_provider_name"), MYSQLI_ASSOC);
$ai_ready = function_exists('aiReady') && aiReady('Tickets');
$test_query = trim((string) ($_GET['q'] ?? ''));
$test_results = $test_query !== '' && $settings['enabled'] ? mitAiSearch($test_query, ['limit' => 10]) : [];
$scrubbed = mitAiScrubbedSources(50);
$explain = isset($_GET['explain']) ? mitAiExplainSource(intval($_GET['explain'])) : null;
$rule_names = ['labelled' => 'Labelled as a password or key', 'sentence' => 'A sentence giving a password', 'word' => 'A password-like word'];

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-robot me-2"></i>AI helper</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small" style="max-width: 52rem">A library of your resolved tickets and documents that the AI helper searches to answer the
            team's questions, with links to where each answer came from. Agents only ever get passages from clients they can open, and only with their
            role's Use AI permission. <strong>Passwords stay out:</strong> the helper never reads the password vault, and passwords, keys and the
            sentences around them are taken out of tickets and documents before anything is kept or sent.</p>
        <?php if (!$ai_ready) { ?>
            <div class="alert alert-info small" data-mit-ai-no-model>No AI model is set up for Tickets or General yet (<a href="ai_providers.php">AI Providers</a>) - the
                library can be built and searched here, and answers need a model.</div>
        <?php } ?>

        <form action="post.php" method="post" class="row g-3" style="max-width: 52rem" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="ai_helper_enabled" value="1" id="mitAiEnabled" <?= $settings['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitAiEnabled">AI helper on</label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="ai_helper_internal" value="1" id="mitAiInternal" <?= $settings['internal'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitAiInternal">Learn from internal notes</label>
                    <div class="form-text">Usually where the fix is written down. Only agents ever see answers.</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="ai_helper_documents" value="1" id="mitAiDocuments" <?= $settings['documents'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitAiDocuments">Learn from documents</label>
                </div>
            </div>
            <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-project-diagram me-2"></i>Meaning-based search (optional)</h6>
                <div class="form-text">Finds "Outlook keeps asking for a password" when the old ticket said "sign-in prompt loop" too. Needs a provider with an
                    embeddings model - OpenAI (text-embedding-3-small), Gemini (text-embedding-004), Mistral (mistral-embed) or a local Ollama (nomic-embed-text).
                    Anthropic has none. Without it, keyword search is used.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Provider</label>
                <select class="form-select" name="ai_helper_embed_provider">
                    <option value="0">- Keyword search only -</option>
                    <?php foreach ($providers as $provider) { ?>
                        <option value="<?= intval($provider['ai_provider_id']) ?>" <?= intval($provider['ai_provider_id']) === $settings['embed_provider'] ? 'selected' : '' ?>><?= escapeHtml($provider['ai_provider_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Embeddings model</label>
                <input type="text" class="form-control" name="ai_helper_embed_model" maxlength="200" value="<?= escapeHtml($settings['embed_model']) ?>" placeholder="text-embedding-3-small">
            </div>
            <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-comments me-2"></i>Ask AI (the panel in the top bar)</h6>
                <div class="form-text">Answers use ITFlow's AI model for Tickets (or General) - <a href="ai_models.php">AI Models</a>. Each question sends the closest
                    passages and, on a ticket, that ticket - roughly 3,000-4,000 tokens.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Monthly token limit</label>
                <input type="number" min="0" step="10000" class="form-control" name="ai_helper_monthly_tokens" value="<?= intval(mitAiTokenLimit()) ?>" data-mit-ai-limit>
                <div class="form-text">Answers and meaning search together; 0 = no limit. When reached, the helper says so until the 1st.</div>
            </div>
            <div class="col-md-6 small" data-mit-ai-usage>
                <div class="text-muted mt-md-4">This month: <strong><?= number_format(mitAiTokensThisMonth()) ?></strong> tokens<?= mitAiTokenLimit() ? ' of ' . number_format(mitAiTokenLimit()) : '' ?>.
                    <?php $mit_ai_stats = mitAiAskStats(30); if ($mit_ai_stats) { ?>
                        Last 30 days: <?= intval($mit_ai_stats['questions']) ?> question(s), <i class="fas fa-thumbs-up"></i> <?= intval($mit_ai_stats['up']) ?>
                        <i class="fas fa-thumbs-down ms-1"></i> <?= intval($mit_ai_stats['down']) ?>.
                    <?php } ?>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" name="mit_ai_helper_save" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
                <?php if ($settings['embed_provider'] && $settings['embed_model'] !== '') { ?>
                    <button type="submit" name="mit_ai_helper_embed_test" class="btn btn-outline-secondary"><i class="fas fa-vial me-2"></i>Test embeddings</button>
                <?php } ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-book me-2"></i>Library</h3>
        <form action="post.php" method="post" onsubmit="return confirm('Throw the library away and build it again? Searches find less until it has caught up.')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" name="mit_ai_helper_rebuild" class="btn btn-light btn-sm"><i class="fas fa-redo me-2"></i>Rebuild</button>
        </form>
    </div>
    <div class="card-body" data-mit-ai-stats>
        <div class="row g-3 text-center mb-2">
            <div class="col-6 col-md"><div class="h4 mb-0" data-mit-ai-stat="tickets"><?= number_format($stats['tickets'] ?? 0) ?></div><div class="small text-muted">Tickets</div></div>
            <div class="col-6 col-md"><div class="h4 mb-0" data-mit-ai-stat="documents"><?= number_format($stats['documents'] ?? 0) ?></div><div class="small text-muted">Documents<?= $settings['documents'] ? ' <span data-mit-ai-stat="documents_in_itflow">of ' . number_format($stats['documents_in_itflow'] ?? 0) . '</span>' : ' (off)' ?></div></div>
            <div class="col-6 col-md"><div class="h4 mb-0" data-mit-ai-stat="chunks"><?= number_format($stats['chunks'] ?? 0) ?></div><div class="small text-muted">Passages</div></div>
            <div class="col-6 col-md"><div class="h4 mb-0" data-mit-ai-stat="embedded"><?= number_format($stats['embedded'] ?? 0) ?></div><div class="small text-muted">With meaning search</div></div>
            <div class="col-6 col-md"><div class="h4 mb-0" data-mit-ai-stat="scrubbed"><?= number_format($stats['scrubbed_sources'] ?? 0) ?></div><div class="small text-muted">Had passwords taken out</div></div>
        </div>
        <div class="small text-muted">
            <?php if (!$settings['enabled']) { ?>
                The library is built while the AI helper is on (every 5 minutes, cron job AI Helper Library).
            <?php } elseif ($stats['last_run'] === '') { ?>
                Waiting for the first run of the AI Helper Library cron job.
            <?php } else { ?>
                Last updated <?= escapeHtml($stats['last_run']) ?><?= $stats['catching_up'] ? ' - still catching up with older tickets' : '' ?>.
            <?php } ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-search me-2"></i>Try a search</h3>
    </div>
    <div class="card-body">
        <form method="get" class="d-flex gap-2 mb-3" style="max-width: 52rem">
            <input type="search" class="form-control" name="q" value="<?= escapeHtml($test_query) ?>" placeholder="e.g. Outlook keeps asking to sign in" <?= $settings['enabled'] ? '' : 'disabled' ?>>
            <button type="submit" class="btn btn-outline-primary" <?= $settings['enabled'] ? '' : 'disabled' ?>><i class="fas fa-search"></i></button>
        </form>
        <?php if ($test_query !== '') { ?>
            <?php if (!$test_results) { ?>
                <p class="text-muted" data-mit-ai-results="0">Nothing found.</p>
            <?php } else { ?>
                <div class="list-group" data-mit-ai-results="<?= count($test_results) ?>">
                    <?php foreach ($test_results as $hit) { ?>
                        <div class="list-group-item" data-mit-ai-hit="<?= escapeHtml($hit['kind']) ?>">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                <i class="fas fa-fw <?= $hit['kind'] === 'ticket' ? 'fa-life-ring' : 'fa-file-alt' ?> text-muted"></i>
                                <a href="<?= escapeHtml($hit['url']) ?>" class="fw-semibold"><?= escapeHtml($hit['title']) ?></a>
                                <?php if ($hit['client_name'] !== '') { ?><span class="badge text-bg-light"><?= escapeHtml($hit['client_name']) ?></span><?php } ?>
                                <span class="badge text-bg-secondary ms-auto"><?= escapeHtml($hit['how']) ?></span>
                            </div>
                            <div class="small text-muted" style="white-space: pre-line"><?= escapeHtml(mb_strimwidth($hit['text'], 0, 600, '...')) ?></div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-secret me-2"></i>Passwords taken out</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small" style="max-width: 52rem">These tickets and documents had passwords, keys or sentences giving them in their text ("What?" shows what was taken and why). The AI helper never
            sees those parts. They are still in ITFlow as written, though - worth moving each into the client's password vault and taking it out of the note.</p>
        <?php if ($explain) { ?>
            <div class="alert alert-light border small" id="explain" data-mit-ai-explain>
                <div class="fw-semibold mb-2">Taken out of <a href="<?= escapeHtml($explain[2]) ?>"><?= escapeHtml($explain[0]) ?></a>
                    <span class="text-muted fw-normal">- shown only here, to check it was a real password; never sent to the AI</span></div>
                <?php if (!$explain[1]) { ?>
                    <div class="text-muted">Nothing now - the filter has changed since; the next library run updates the count.</div>
                <?php } ?>
                <?php foreach ($explain[1] as $item) { ?>
                    <div class="d-flex gap-2 mb-1"><span class="badge text-bg-secondary flex-shrink-0" data-mit-ai-rule="<?= escapeHtml($item['rule']) ?>"><?= escapeHtml($rule_names[$item['rule']] ?? $item['rule']) ?></span>
                        <code class="text-break"><?= escapeHtml(mb_strimwidth($item['text'], 0, 300, '...')) ?></code></div>
                <?php } ?>
            </div>
        <?php } ?>
        <?php if (!$scrubbed) { ?>
            <p class="text-muted mb-0" data-mit-ai-scrubbed="0">None so far.</p>
        <?php } else { ?>
            <table class="table table-sm mb-0" data-mit-ai-scrubbed="<?= count($scrubbed) ?>">
                <thead><tr><th>Where</th><th>Client</th><th class="text-end">Taken out</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($scrubbed as $source) { ?>
                    <tr>
                        <td><i class="fas fa-fw <?= $source['ticket_id'] ? 'fa-life-ring' : 'fa-file-alt' ?> text-muted me-1"></i><a href="<?= escapeHtml($source['url']) ?>"><?= escapeHtml($source['title']) ?></a></td>
                        <td><?= escapeHtml((string) $source['client_name']) ?></td>
                        <td class="text-end"><?= intval($source['scrubbed']) ?></td>
                        <td class="text-end"><a href="?explain=<?= intval($source['source_id']) ?>#explain" class="small" data-mit-ai-explain-link>What?</a></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
