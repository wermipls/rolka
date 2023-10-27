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

require __DIR__ . '/../vendor/autoload.php';

use rolka\ {
    MessageParser,
    MessageRenderer,
    Context
};

$config = include("../config.php");

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$ctx = new Context($db);

$authors_by_id = $ctx->fetchAuthorNames();

$channel_id = filter_input(INPUT_GET, 'c', FILTER_VALIDATE_INT) ?? 0;

$origin_id = filter_input(INPUT_GET, 'from', FILTER_VALIDATE_INT);
if (!$origin_id) {
    $origin_id = 0;
}

$channel = $ctx->getChannel($channel_id);
$parser = new MessageParser($authors_by_id);
$renderer = new MessageRenderer($parser, $channel, $config['asset_key']);

foreach ($channel->fetchMessages($origin_id, 250) as $msg) {
    $renderer->draw($msg);
}
$renderer->finish();
?>
</body>
</html>