<?php
/*
 * Mountaineer IT - our items in the top bar (one require in includes/top_nav.php): the Ask AI button and panel, Bugs & ideas, and
 * the draw.io editor's script. (The ticket timers that gave this file its name are ITFlow's own since 26.12 - js/ticket_timers.js.)
 */
?>
<?= function_exists('mitAiPanelNav') ? mitAiPanelNav() : '' // the Ask AI button and panel (functions/mit_ai_ask.php) ?>
<?= function_exists('mitFeedbackNav') ? mitFeedbackNav() : '' // the Bugs & ideas button and panel (functions/mit_feedback.php) ?>
<?= function_exists('mitDiagramsNav') ? mitDiagramsNav() : '' // the draw.io editor's script (functions/mit_diagrams.php) ?>
