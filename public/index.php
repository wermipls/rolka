<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
<?php include __DIR__ . '/../resources/style.css' ?>
</style>
<script>
    function load_iframe(element) {
        iframe = element.firstElementChild;
        iframe.attributes.src.value = iframe.attributes.src_pre.value;
        element.attributes.onclick.value = null;
        element.classList.remove('ifwrap');
    }
</script>
</head>
<body>
<?php
mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");

require __DIR__ . '/../vendor/autoload.php';

use rolka\ {
    MessageParser,
    MessageRenderer,
    Context,
    EmojiText
};
function exception_handler(Throwable $exception)
{
    ob_end_clean();
    ob_start();

    $e = (new EmojiText("😢"))->toTag(lazy: false);
?>
<div style="position:absolute;top:0;left:0;display:table;width:100%;height:100%;">
  <div style="display:table-cell;vertical-align:middle;">
    <div style="margin-left:auto;margin-right:auto;text-align: center;">
        <div style="font-size:10em"><?php echo $e ?></div>
        <div style="padding:16px"><i>[failed to render the page]</i></div>
    </div>
  </div>
</div>
</body>
</html>
<?php
    ob_end_flush();
    // rethrow so the error still gets logged
    throw $exception;
}

ob_start();

set_exception_handler('exception_handler');

$config = include(__DIR__ . "/../config.php");

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$ctx = new Context($db);

$ctx->getAuthors();

$channel_id = filter_input(INPUT_GET, 'c', FILTER_VALIDATE_INT);

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
if (!$page) {
    $page = 0;
}

$channel = $ctx->getChannel($channel_id);
$parser = new MessageParser($ctx);
$renderer = new MessageRenderer($parser, $channel, $config['asset_key']);

$limit = 250;
$msg_count = $channel->getMessageCount();

if ($page * $limit > $msg_count) {
    $page = 0;
}

foreach ($channel->fetchMessages(0, $limit, $page) as $msg) {
    $renderer->draw($msg);
}

$renderer->drawPageControls($msg_count, $page, $limit);

ob_end_flush();
?>
</body>
</html>