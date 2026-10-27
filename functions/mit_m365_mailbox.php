<?php

/*
 * Mountaineer IT - the mailbox read through Microsoft Graph instead of IMAP, for
 * cron/ticket_email_parser.php (see mitM365GraphMailbox() in functions/mit_m365.php).
 *
 * These stand in for the parts of DirectoryTree\ImapEngine the parser uses - the mailbox, its
 * folders, the unseen-message query and each message - so the parser itself is unchanged. A
 * message is downloaded whole (its MIME source) and read by ImapEngine's own FileMessage; only
 * marking it read/flagged and moving it go to Graph. Needs libs/vendor/autoload.php loaded.
 */

use DirectoryTree\ImapEngine\FileMessage;

class MitGraphMailbox {

    private $token;
    private $base;
    private $folder_ids = [];

    public function __construct($token, $username) {
        $this->token = $token;
        $this->base = $username !== '' ? '/users/' . rawurlencode($username) : '/me';
    }

    /** A Graph call for this mailbox; throws with Microsoft's message unless the status is one of $ok. */
    public function call($method, $path, $body = null, array $ok = [200]) {
        $response = mitGraph($method, $this->base . $path, $this->token, $body);
        if (!in_array($response['status'], $ok, true)) {
            throw new RuntimeException(mitM365Error($response, "Microsoft 365 $method " . strtok($path, '?')));
        }
        return $response;
    }

    /** GET a next-page link from Graph - only links back to Graph itself are followed. */
    public function next($url) {
        if (strpos($url, MIT_M365_GRAPH . '/') !== 0) {
            throw new RuntimeException('Microsoft 365: a next-page link pointed somewhere unexpected');
        }
        $response = mitGraph('GET', substr($url, strlen(MIT_M365_GRAPH)), $this->token);
        if ($response['status'] !== 200) {
            throw new RuntimeException(mitM365Error($response, 'Microsoft 365 GET (next page)'));
        }
        return $response;
    }

    /** The message's MIME source. */
    public function mime($message_id) {
        $response = mitHttp('GET', MIT_M365_GRAPH . $this->base . '/messages/' . rawurlencode($message_id) . '/$value',
            ['Authorization: Bearer ' . $this->token], null, 300);
        if ($response['status'] !== 200) {
            throw new RuntimeException(mitM365Error($response, 'Downloading a message from Microsoft 365'));
        }
        return $response['body'];
    }

    public function connect() {
        $this->call('GET', '/mailFolders/inbox?$select=id');
    }

    public function disconnect() {
    }

    public function inbox() {
        return new MitGraphFolder($this, 'INBOX', 'inbox');
    }

    public function folders() {
        return new MitGraphFolders($this);
    }

    /** IMAP SUBSCRIBE has no Graph equivalent - every folder shows in Outlook. */
    public function connection() {
        return new class {
            public function subscribe($folder) {
            }
        };
    }

    /** A folder's Graph id from its IMAP-style path ("ITFlow", "INBOX/ITFlow"), or null. */
    public function folderId($path) {
        if (array_key_exists($path, $this->folder_ids)) {
            return $this->folder_ids[$path];
        }
        $parent = '/mailFolders';
        $id = null;
        foreach (explode('/', $path) as $i => $name) {
            if ($i === 0 && strcasecmp($name, 'INBOX') === 0) {
                $id = 'inbox';
            } else {
                $filter = rawurlencode("displayName eq '" . str_replace("'", "''", $name) . "'");
                $found = $this->call('GET', "$parent?\$filter=$filter&\$select=id");
                $id = $found['json']['value'][0]['id'] ?? null;
                if ($id === null) {
                    break;
                }
            }
            $parent = '/mailFolders/' . rawurlencode($id) . '/childFolders';
        }
        return $this->folder_ids[$path] = $id;
    }

    public function rememberFolder($path, $id) {
        $this->folder_ids[$path] = $id;
    }
}

class MitGraphFolders {

    private $mailbox;

    public function __construct(MitGraphMailbox $mailbox) {
        $this->mailbox = $mailbox;
    }

    public function find($path) {
        $id = $this->mailbox->folderId($path);
        return $id === null ? null : new MitGraphFolder($this->mailbox, $path, $id);
    }

    public function findOrFail($path) {
        $folder = $this->find($path);
        if (!$folder) {
            throw new RuntimeException("Microsoft 365 folder $path not found.");
        }
        return $folder;
    }

    public function create($path) {
        $parts = explode('/', $path);
        $name = array_pop($parts);
        $parent_id = $parts ? $this->mailbox->folderId(implode('/', $parts)) : null;
        if ($parts && $parent_id === null) {
            throw new RuntimeException("Microsoft 365 folder " . implode('/', $parts) . " not found.");
        }
        $created = $this->mailbox->call('POST', $parent_id === null ? '/mailFolders' : '/mailFolders/' . rawurlencode($parent_id) . '/childFolders',
            ['displayName' => $name], [201]);
        $this->mailbox->rememberFolder($path, $created['json']['id']);
        return new MitGraphFolder($this->mailbox, $path, $created['json']['id']);
    }
}

class MitGraphFolder {

    private $mailbox;
    private $path;
    private $id;

    public function __construct(MitGraphMailbox $mailbox, $path, $id) {
        $this->mailbox = $mailbox;
        $this->path = $path;
        $this->id = $id;
    }

    public function path() {
        return $this->path;
    }

    public function delimiter() {
        return '/';
    }

    public function messages() {
        return new MitGraphMessageQuery($this->mailbox, $this->id);
    }

    /** Graph deletes and moves straight away - nothing to expunge. */
    public function expunge() {
    }
}

/** Unread, unflagged messages in a folder, oldest first - the parser's IMAP query. */
class MitGraphMessageQuery {

    private $mailbox;
    private $folder_id;
    private $limit = 50;

    public function __construct(MitGraphMailbox $mailbox, $folder_id) {
        $this->mailbox = $mailbox;
        $this->folder_id = $folder_id;
    }

    public function limit($limit) {
        $this->limit = max(1, intval($limit));
        return $this;
    }

    /** withHeaders(), withBody(), withFlags(), leaveUnread(), unseen(), unflagged(): always so here. */
    public function __call($name, $arguments) {
        return $this;
    }

    public function get() {
        // Oldest first, as the IMAP path reads them (Graph wants the $orderby property in the $filter, first). Flagged mail -
        // what the parser could not process - is skipped here, so pages are read on (up to 20) until there are enough
        // messages to work on: a pile of flagged mail at the oldest end must not hide newer mail for good.
        $filter = rawurlencode('receivedDateTime ge 1900-01-01T00:00:00Z and isRead eq false');
        $path = '/mailFolders/' . rawurlencode($this->folder_id)
            . "/messages?\$filter=$filter&\$orderby=receivedDateTime%20asc&\$select=id,receivedDateTime,flag&\$top=" . min(1000, $this->limit * 4);
        $rows = [];
        $skipped = 0;
        $next = '';
        for ($page = 0; $page < 20 && ($page === 0 || $next !== '') && count($rows) < $this->limit; $page++) {
            try {
                $list = $page === 0 ? $this->mailbox->call('GET', $path) : $this->mailbox->next($next);
            } catch (Throwable $e) {
                // Throttled or a passing hiccup: no mail this run, the parser tries again next minute. Anything else
                // (permission removed, certificate expired) fails the job, so cron health reports it.
                if (preg_match('/\(HTTP (429|502|503|504)\)/', $e->getMessage())) {
                    logApp("Cron-Email-Parser", "warning", "Microsoft 365: could not list new mail this run - " . $e->getMessage());
                    break;
                }
                throw $e;
            }
            foreach ($list['json']['value'] ?? [] as $row) {
                if (($row['flag']['flagStatus'] ?? '') === 'flagged') {
                    $skipped++;
                } else {
                    $rows[] = $row;
                }
            }
            $next = (string) ($list['json']['@odata.nextLink'] ?? '');
        }
        if (!$rows && $skipped >= $this->limit * 4) {
            logApp("Cron-Email-Parser", "warning", "Microsoft 365: $skipped unread messages in the mailbox are flagged (mail the parser could not process) - review and clear them");
        }
        $messages = [];
        $bytes = 0;
        foreach (array_slice($rows, 0, $this->limit) as $row) {
            // A run holds every message it reads in memory - past 64 MB the rest wait for the next run, so a pile of
            // large mails cannot run PHP out of memory and jam the parser on the same messages every minute
            if ($bytes > 64 * 1024 * 1024) {
                break;
            }
            try {
                $mime = $this->mailbox->mime($row['id']);
                $bytes += strlen($mime);
                $messages[] = new MitGraphMessage($this->mailbox, $row['id'], $mime);
            } catch (Throwable $e) {
                // One message that will not download must not hold up the rest - flag it, as the parser does
                // with mail it cannot process, so it is not tried again every minute
                logApp("Cron-Email-Parser", "warning", "Microsoft 365: skipped a message that could not be downloaded (flagged in the mailbox) - " . $e->getMessage());
                try {
                    $this->mailbox->call('PATCH', '/messages/' . rawurlencode($row['id']), ['flag' => ['flagStatus' => 'flagged']]);
                } catch (Throwable $ignored) {
                }
            }
        }
        return $messages;
    }
}

/** A message as ImapEngine reads it from its MIME source, with read/flag/move done through Graph. */
class MitGraphMessage extends FileMessage {

    private $mailbox;
    private $graph_id;

    public function __construct(MitGraphMailbox $mailbox, $graph_id, $mime) {
        parent::__construct($mime);
        $this->mailbox = $mailbox;
        $this->graph_id = $graph_id;
    }

    /** The raw body (everything after the headers) - the parser's last resort for mail with no text or HTML part. */
    public function body(): string {
        $parts = preg_split("/\r?\n\r?\n/", $this->contents, 2);
        return $parts[1] ?? '';
    }

    /** IMAP UIDs are numbers; Graph ids are not - a stable number for the logs. */
    public function uid(): int {
        return intval(sprintf('%u', crc32($this->graph_id)));
    }

    public function flag(BackedEnum|string $flag, string $operation, bool $expunge = false): void {
        $flag = $flag instanceof BackedEnum ? $flag->value : $flag;
        $on = $operation === '+';
        if ($flag === '\Seen') {
            $this->mailbox->call('PATCH', '/messages/' . rawurlencode($this->graph_id), ['isRead' => $on]);
        } elseif ($flag === '\Flagged') {
            $this->mailbox->call('PATCH', '/messages/' . rawurlencode($this->graph_id), ['flag' => ['flagStatus' => $on ? 'flagged' : 'notFlagged']]);
        }
    }

    public function move(string $folder, bool $expunge = false): ?int {
        $folder_id = $this->mailbox->folderId($folder);
        if ($folder_id === null) {
            throw new RuntimeException("Microsoft 365 folder $folder not found.");
        }
        $moved = $this->mailbox->call('POST', '/messages/' . rawurlencode($this->graph_id) . '/move', ['destinationId' => $folder_id], [200, 201]);
        $this->graph_id = $moved['json']['id'] ?? $this->graph_id;
        return null;
    }
}
