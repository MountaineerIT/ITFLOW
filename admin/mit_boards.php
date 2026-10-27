<?php

/*
 * Mountaineer IT - Admin > Settings > Boards (functions/mit_boards.php): ?tab= boards (the boards, their members and who
 * is told about new and moved tickets) and routing (where new tickets land: the address an email was sent to, the
 * client, the category - else the default board). Handlers in admin/post/mit_boards.php.
 */

require_once "includes/inc_all_admin.php";

if (!function_exists('mitBoardsReady') || !mitBoardsReady()) {
    echo '<div class="alert alert-warning">Boards need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$tab = ($_GET['tab'] ?? '') === 'routing' ? 'routing' : 'boards';
$boards = mitBoards(true);
$csrf = $_SESSION['csrf_token'];

// Open tickets per board, and members per board
$open = [];
$sql = mysqli_query($mysqli, "SELECT " . mitBoardSql() . " AS b, COUNT(*) FROM tickets WHERE " . TICKET_OPEN_SQL . " GROUP BY b");
while ($row = mysqli_fetch_row($sql)) {
    $open[intval($row[0])] = intval($row[1]);
}
$members = [];
$sql = mysqli_query($mysqli, "SELECT m.board_id, m.member_notify, " . (mitBoardsPart2Ready() ? 'm.member_rotation' : '1 AS member_rotation') . ", u.user_name FROM mit_board_members m JOIN users u ON u.user_id = m.user_id
    WHERE u.user_archived_at IS NULL ORDER BY u.user_name");
while ($row = mysqli_fetch_assoc($sql)) {
    $members[intval($row['board_id'])][] = $row;
}

echo mitBoardStyle();

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-columns me-2"></i>Boards</h3>
        <?php if ($tab === 'boards') { ?>
            <div class="card-tools">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/mit_boards/board.php"><i class="fas fa-plus me-2"></i>New board</button>
            </div>
        <?php } ?>
    </div>
    <div class="card-body pb-0">
        <p class="text-body-secondary">Boards say who works a ticket (Service Desk, Tier 2, Sysadmin, Sales ...); its category still says what it is about.
            Boards only sort and route - who can see a ticket still goes by client access.</p>
        <ul class="nav nav-tabs">
            <li class="nav-item"><a class="nav-link <?= $tab === 'boards' ? 'active' : '' ?>" href="?tab=boards"><i class="fas fa-columns me-2"></i>Boards</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'routing' ? 'active' : '' ?>" href="?tab=routing"><i class="fas fa-random me-2"></i>Routing</a></li>
        </ul>
    </div>

    <?php if ($tab === 'boards') { ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Board</th><th>Members</th><th class="text-end">Open tickets</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($boards as $id => $board) { $archived = $board['board_archived_at'] !== null; ?>
                        <tr class="<?= $archived ? 'text-body-secondary' : '' ?>">
                            <td>
                                <?= mitBoardBadge($id) ?>
                                <?php if (intval($board['board_is_default'])) { ?><span class="badge text-bg-primary ms-1" title="New tickets that match no rule land here">Default</span><?php } ?>
                                <?php if ($archived) { ?><span class="badge text-bg-secondary ms-1">Archived</span><?php } ?>
                                <?php if ($board['board_description'] !== '') { ?><div class="small text-body-secondary mt-1"><?= escapeHtml($board['board_description']) ?></div><?php } ?>
                                <?php if (!$archived && ($seen_by = mitBoardSeenBy($id)) !== '') { ?><div class="small text-body-secondary mt-1" data-mit-seen-by title="Set in Admin > Roles > Edit"><i class="fas fa-eye me-1"></i>Seen by: <?= escapeHtml($seen_by) ?></div><?php } ?>
                            </td>
                            <td class="small">
                                <?php if (empty($members[$id])) { ?>
                                    <span class="text-body-secondary">Nobody yet</span>
                                <?php } else { foreach ($members[$id] as $i => $member) { ?>
                                    <?= $i ? ', ' : '' ?><?= escapeHtml($member['user_name']) ?><?php if (intval($member['member_notify'])) { ?> <i class="fas fa-bell text-body-secondary" title="Told about new and moved tickets"></i><?php } ?><?php if (($board['board_assign'] ?? 'off') === 'round_robin' && !intval($member['member_rotation'])) { ?> <span class="text-body-secondary" title="Left out of the turn">(not handed tickets)</span><?php } ?>
                                <?php } } ?>
                                <?php if (($board['board_assign'] ?? 'off') === 'round_robin') { ?>
                                    <div class="mt-1"><span class="badge text-bg-info" title="New tickets are handed to the members in turn"><i class="fas fa-sync-alt me-1"></i>Round-robin<?= $board['board_assign_max'] !== null ? ' - up to ' . intval($board['board_assign_max']) . ' each' : '' ?><?= intval($board['board_assign_hours']) ? ', working hours' : '' ?></span></div>
                                <?php } ?>
                            </td>
                            <td class="text-end">
                                <?php if (!$archived) { ?><a href="/agent/tickets.php?board=<?= $id ?>"><?= number_format($open[$id] ?? 0) ?></a><?php } ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <div class="dropdown dropstart">
                                    <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" title="More actions"><i class="fas fa-ellipsis-v"></i></button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/mit_boards/board.php?board_id=<?= $id ?>"><i class="fas fa-fw fa-edit me-2"></i>Edit</a>
                                        <?php if (!$archived && !intval($board['board_is_default'])) { ?>
                                            <a class="dropdown-item" href="post.php?mit_board_default=<?= $id ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-fw fa-inbox me-2"></i>Make the default</a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="post.php?mit_board_archive=<?= $id ?>&csrf_token=<?= $csrf ?>"
                                                title="Its tickets go to the default board and its routing rules are removed"><i class="fas fa-fw fa-archive me-2"></i>Archive</a>
                                        <?php } elseif ($archived) { ?>
                                            <a class="dropdown-item" href="post.php?mit_board_restore=<?= $id ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-fw fa-redo me-2"></i>Restore</a>
                                        <?php } ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="card-body small text-body-secondary">
            <i class="fas fa-bell me-1"></i> = told (bell notification and email) when a ticket lands on or is moved to the board. Tickets from before boards came in sit on the default board.
        </div>

    <?php } else {
        $rules = ['mailbox' => [], 'client' => [], 'category' => []];
        $sql = mysqli_query($mysqli, "SELECT r.rule_id, r.rule_kind, r.rule_match, r.board_id, c.client_name, cat.category_name FROM mit_board_rules r
            LEFT JOIN clients c ON r.rule_kind = 'client' AND c.client_id = r.rule_match
            LEFT JOIN categories cat ON r.rule_kind = 'category' AND cat.category_id = r.rule_match
            ORDER BY r.rule_kind, COALESCE(c.client_name, cat.category_name, r.rule_match)");
        while ($row = mysqli_fetch_assoc($sql)) {
            $rules[$row['rule_kind']][] = $row;
        }
        $active_boards = mitBoards();
        $board_select = function () use ($active_boards) {
            $html = '<select class="form-select" name="board_id" required>';
            foreach ($active_boards as $id => $board) {
                $html .= '<option value="' . $id . '">' . escapeHtml($board['board_name']) . '</option>';
            }
            return $html . '</select>';
        };
        $clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
        $categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name");
        $sections = [
            'mailbox' => ['Sent to', 'fa-at', 'An email sent (or forwarded) to this address - e.g. sales@ - goes to the board. Checked first.'],
            'client' => ['Client', 'fa-user-friends', "All of a client's new tickets go to the board. Checked second."],
            'category' => ['Category', 'fa-layer-group', 'A new ticket with this category goes to the board. Checked third.'],
        ];
        $default = mitBoards()[mitBoardDefaultId()]['board_name'] ?? 'the default board';
        ?>
        <div class="card-body">
            <p>A new ticket lands on the board of the first rule that matches - <strong>sent to</strong>, then <strong>client</strong>, then <strong>category</strong> -
                else on <strong><?= escapeHtml($default) ?></strong>. Tickets from email are routed as they arrive, New Ticket can pick a board, and tickets from
                the portal, the API, recurring tickets and projects are routed within a minute.</p>
            <div class="row g-4">
                <?php foreach ($sections as $kind => [$title, $icon, $help]) { ?>
                    <div class="col-xl-4">
                        <div class="card h-100 mb-0">
                            <div class="card-header"><h3 class="card-title"><i class="fas <?= $icon ?> me-2"></i><?= $title ?></h3></div>
                            <div class="card-body">
                                <p class="small text-body-secondary"><?= escapeHtml($help) ?></p>
                                <?php if ($rules[$kind]) { ?>
                                    <ul class="list-group mb-3">
                                        <?php foreach ($rules[$kind] as $rule) {
                                            $what = $kind === 'client' ? ($rule['client_name'] ?? 'A removed client') : ($kind === 'category' ? ($rule['category_name'] ?? 'A removed category') : $rule['rule_match']); ?>
                                            <li class="list-group-item d-flex align-items-center gap-2">
                                                <span class="text-truncate me-auto"><?= escapeHtml($what) ?></span>
                                                <i class="fas fa-arrow-right text-body-secondary small"></i><?= mitBoardBadge($rule['board_id'], true) ?>
                                                <a class="btn btn-sm btn-link text-danger confirm-link" title="Remove this rule"
                                                    href="post.php?mit_board_rule_delete=<?= intval($rule['rule_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-times"></i></a>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                <?php } ?>
                                <form action="post.php" method="post" autocomplete="off" class="vstack gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="rule_kind" value="<?= $kind ?>">
                                    <?php if ($kind === 'mailbox') { ?>
                                        <input type="email" class="form-control" name="rule_match" placeholder="sales@yourcompany.com" required maxlength="255">
                                    <?php } elseif ($kind === 'client') { ?>
                                        <select class="form-select select2" name="rule_match" required>
                                            <option value="">- Client -</option>
                                            <?php while ($client = mysqli_fetch_assoc($clients)) { ?>
                                                <option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option>
                                            <?php } ?>
                                        </select>
                                    <?php } else { ?>
                                        <select class="form-select select2" name="rule_match" required>
                                            <option value="">- Category -</option>
                                            <?php while ($category = mysqli_fetch_assoc($categories)) { ?>
                                                <option value="<?= intval($category['category_id']) ?>"><?= escapeHtml($category['category_name']) ?></option>
                                            <?php } ?>
                                        </select>
                                    <?php } ?>
                                    <div class="input-group">
                                        <span class="input-group-text">Board</span>
                                        <?= $board_select() ?>
                                        <button type="submit" name="mit_board_rule_add" class="btn btn-primary"><i class="fas fa-plus"></i></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <div class="alert alert-light border mt-4 mb-0 d-flex flex-wrap align-items-center gap-3">
                <div class="me-auto"><strong>Place open tickets by category</strong><br>
                    <span class="small text-body-secondary">Open tickets still on <?= escapeHtml($default) ?> that nobody has moved go to their category's board. Nobody is notified.</span></div>
                <a class="btn btn-outline-primary confirm-link" href="post.php?mit_board_place_category=1&csrf_token=<?= $csrf ?>"><i class="fas fa-random me-2"></i>Place them now</a>
            </div>
        </div>
    <?php } ?>
</div>

<?php
require_once "../includes/footer.php";
