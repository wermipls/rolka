<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --color-bg: #000000;
    --color-fg: #ffffff;
    --color-bg-secondary: #202020;
    --color-fg-secondary: #808080;
    --color-accent: #ff80d0;
}

@media (prefers-color-scheme: light) {
  :root {
    --color-bg: #ffffff;
    --color-fg: #000000;
    --color-bg-secondary: #f2f2f2;
    --color-fg-secondary: #606060;
    --color-accent: #e000a0;
  }
}

* {
    box-sizing: border-box;
}

body {
    max-width: 800px;
    padding: 12px;
    margin: auto;
    align-items: center;
    font-family: Helvetica, sans-serif;
    font-size: 1em;
    background-color: var(--color-bg);
    color: var(--color-fg);
    line-height: 1.2;
}


a, a:hover, a:visited, a:active {
  color: var(--color-accent);
}

.msg_avi {
    object-fit: cover;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    overflow: hidden;
    display: block;
    margin-left: auto;
    margin-top: 12px;
    margin-bottom: 12px;
    background-color: var(--color-bg-secondary);
}

.msg_header {
    grid-column: 2;
    min-width: 0;
    padding-top: 12px;
    margin-top: auto;
    margin-bottom: 12px;
}

.msg_side {
    grid-column: 1;
    width: 40px;
    margin-right: 12px;
}

.msg_user {
    font-weight: bold;
    font-size: 1.2em;
}

.msg_primary {
    grid-column: 2;
}

.msg_content {
    overflow-wrap: anywhere; 
    min-width: 0;
}

.msg_big {
    font-size: 2em;
}

.msg_element {
    margin-bottom: 5px;
}

.msg_date {
    display: inline-block;
    margin-left: auto;
    color: var(--color-fg-secondary);
}

.msg_time {
    text-align: right;
    color: var(--color-fg-secondary);
    margin: auto;
}

.msg_sticker {
    height: 192px;
    max-width: 192px;
    object-fit: contain;
    border-radius: 8px;
    background-color: var(--color-bg-secondary);
}

.msg_attachment {
    display: inline-block;
    max-height: 350px;
    max-width: 100%;
    border-radius: 8px;
    background-color: var(--color-bg-secondary);
    margin-right: 8px;
    margin-bottom: 8px;
}

.msg_reply {
    color: var(--color-fg-secondary);
}

.msg_reply_user {
    font-weight: bold;
    color: var(--color-fg);
}

.msg_reply_ref {
    color: inherit;
    text-decoration: none;
}

.msg_reply_content {
    display: inline-block;
    margin-top: 12px;
    margin-right: auto;
    padding: 0px 12px;
    min-width: 0;
    overflow-wrap: anywhere;
    color: color-mix(in lch, var(--color-bg) 25%, var(--color-fg));
    font-style: italic;
}

.msg {
    display: grid;
    grid-template-columns: auto 1fr;
    direction: ltr;
}

.msg_ping {
    display: inline-block;
    background-color: var(--color-bg-secondary);
    border-radius: 5px;
    padding: 0 2px;
    font-weight: bold;
    color: var(--color-accent);
}

.msg_emote {
    height: 1.5em;
    width: 1.5em;
    object-fit: contain;
    vertical-align: top;
}

.msg_date_header {
    overflow: hidden;
    text-align: center;
    margin-top: 24px;
    margin-bottom: 12px;
    color: color-mix(in lch, var(--color-bg) 25%, var(--color-fg));
}

.msg_date_header:before,
.msg_date_header:after {
    background-color: var(--color-bg-secondary);
    content: "";
    display: inline-block;
    height: 3px;
    position: relative;
    vertical-align: middle;
    width: 50%;
}

.msg_date_header:before {
    right: 1em;
    margin-left: -50%;
}

.msg_date_header:after {
    left: 1em;
    margin-right: -50%;
}

a.msg_file {
    display: inline-block;
    padding: 12px;
    font-weight: bold;
}

p {
    display: block;
    margin: 0;
}
</style>
</head>
<body>
<?php
mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");

$start = microtime(true);

require __DIR__ . '/../vendor/autoload.php';

use rolka\ {
    Message,
    MessageParser,
    MessageRenderer,
    Author,
    Attachment
};

function fetch_authors(PDO $db)
{
    $q = $db->query("SELECT uid, name FROM tp_authors");
    $f = $q->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE);
    return $f;
}

function channel_table_from_id($config, $id): string
{
    return $config['channel_tables'][$id] ?? current($config['channel_tables']);
}

$config = include("../config.php");

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$authors_by_id = fetch_authors($db);

$parser = new MessageParser($authors_by_id);
$renderer = new MessageRenderer($parser);

$channel_table = channel_table_from_id(
    $config,
    filter_input(INPUT_GET, 'c', FILTER_VALIDATE_INT));

$select = $db->prepare(
    "SELECT ch.uid, author_id, date_sent, replies_to, content, sticker, attachment, tp_authors.name, tp_authors.avatar_url
     FROM `{$channel_table}` ch
     INNER JOIN tp_authors
     ON ch.author_id = tp_authors.uid
     ORDER BY ch.uid ASC;");
$select->execute();

while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
    $attachments = [];

    if ($row['attachment']) {
        $att_query = $db->prepare(
            "SELECT type, url FROM tp_attachments WHERE id = :id");
        $att_query->bindParam(':id', $row['attachment'], PDO::PARAM_INT);
        $att_query->execute();
        while ($aq = $att_query->fetch()) {
            $a = new Attachment(
                $row['attachment'],
                $aq['type'],
                $aq['url']
            );
            array_push($attachments, $a);
        }
    }
    $reply = null;
    if ($row['replies_to']) {
        $reply_to = $db->prepare(
            "SELECT ch.content, a.name FROM `{$channel_table}` ch
             INNER JOIN tp_authors a
             ON ch.author_id = a.uid
             WHERE ch.uid = :reply_id");
        $reply_to->bindParam(':reply_id', $row['replies_to'], PDO::PARAM_INT);
        $reply_to->execute();
        $reply_to = $reply_to->fetch();
        $reply_author = new Author(0, $reply_to['name'], '');
        $reply = new Message(
            $row['replies_to'],
            $reply_author,
            new DateTimeImmutable(), 
            $reply_to['content'],
            null,
            null,
            null
        );
    }
    $author = new Author(
        $row['author_id'],
        $row['name'],
        $row['avatar_url']);
    $msg = new Message(
        $row['uid'],
        $author,
        new DateTimeImmutable($row['date_sent']),
        $row['content'],
        $reply,
        $row['sticker'],
        $attachments
    );

    $renderer->draw($msg);
}
?>
</body>
</html>