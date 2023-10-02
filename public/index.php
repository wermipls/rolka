<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
<?php include __DIR__ . '/../resources/style.css' ?>
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