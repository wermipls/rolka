<?php

include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Discord\Parts\Channel\Channel;
use rolka\Context;
use React\Async;

$config = include(__DIR__.'/config.php');

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];
$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$ctx = new Context($db);

$chn = $db->query("SELECT * FROM channels");
$chn = $chn->fetchAll();
$mapped_ch = [];

foreach ($chn as $c) {
    if ($c['sync_channel_id']) {
        $mapped_ch[$c['sync_channel_id']] = new rolka\Channel($db, $c['id']);
    }
}

$discord_logger = new \Monolog\Logger('discord-php');
$discord_logger->pushHandler(
    new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO)
);

$d = new Discord([
    'token' => $config['discord_token'],
    'intents' => Intents::getDefaultIntents(),
    'logger' => $discord_logger
]);

function mapInsertMessage(Message $msg, $mapped_ch)
{
    $ch = $mapped_ch[$msg->channel_id];

    $m = new rolka\Message(
        $msg->id,
        new rolka\Author($msg->author->id, '', ''), // FIXME: HACK
        DateTimeImmutable::createFromMutable($msg->timestamp),
        $msg->content,
        $msg->referenced_message !== null ? $msg->referenced_message->id : null,
        $msg->sticker_items ? $msg->sticker_items->first()->id : null,
        null,
        null
    );
    
    $ch->insertMessage($m);
}

$onMsgCreate = function (Message $msg, Discord $d) use ($ctx, $mapped_ch)
{
    echo "{$msg->author->username}: {$msg->content}", PHP_EOL;

    if (!isset($mapped_ch[$msg->channel_id])) {
        return;
    }

    mapInsertMessage($msg, $mapped_ch);
};

function fetchMessages(Channel $ch, $mapped_ch, ?int $last_id = null)
{
    error_log(__FUNCTION__ .": last id $last_id");
    $opts = ['limit' => 100];
    if ($last_id) {
        $opts['before'] = (string)$last_id;
    }

    return Async\await($ch->getMessageHistory($opts));
}

function fetchWholeHistory(Discord $d, int $channel_id, $mapped_ch)
{
    $channel = $d->getChannel($channel_id);

    $last_id = null;

    while (true) {
        $messages = fetchMessages($channel, $mapped_ch, $last_id);

        if ($messages instanceof Collection) {
            foreach ($messages as $msg) {
                mapInsertMessage($msg, $mapped_ch);
            }
            $last = $messages->last();
            if (!$last) {
                break;
            }
            $last_id = $last->id;
        } else {
            break;
        }
    }
}

$d->on('ready', function (Discord $d) use ($onMsgCreate, $mapped_ch) {
    error_log("Hello World!");

    $d->on(Event::MESSAGE_CREATE, $onMsgCreate);

    foreach ($mapped_ch as $id => $channel) {
        fetchWholeHistory($d, $id, $mapped_ch);
    }
});

$d->run();
