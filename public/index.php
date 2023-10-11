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
    MessageParser,
    MessageRenderer,
    Channel
};

function fetch_authors(PDO $db)
{
    $q = $db->query(
        "SELECT a.id, a.display_name, assets.url FROM authors a
         LEFT JOIN assets
         ON a.avatar_asset = assets.url");
    $f = $q->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE);
    return $f;
}

$config = include("../config.php");

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$authors_by_id = fetch_authors($db);

$channel_id = filter_input(INPUT_GET, 'c', FILTER_VALIDATE_INT) ?? 0;

$origin_id = filter_input(INPUT_GET, 'from', FILTER_VALIDATE_INT);
if (!$origin_id) {
    $origin_id = 0;
}

$channel = new Channel($db, $channel_id);
$parser = new MessageParser($authors_by_id);
$renderer = new MessageRenderer($parser, $channel, $config['asset_key']);

foreach ($channel->fetchMessages($origin_id, 250) as $msg) {
    $renderer->draw($msg);
}
$renderer->finish();
?>
</body>
</html>