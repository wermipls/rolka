<?php

include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Discord\Parts\Channel\Channel;
use rolka\AssetManager;
use rolka\Context;
use React\Async;

$config = include(__DIR__.'/config.php');

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
];
$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$ctx = new Context($db);

$chn = $db->query("SELECT * FROM channels");
$chn = $chn->fetchAll();
$mapped_ch = [];

foreach ($chn as $c) {
    if ($sync_id = $c['sync_channel_id']) {
        $mapped_ch[$sync_id] = $ctx->getChannel($c['id']);
    }
}

$discord_logger = new \Monolog\Logger('discord-php');
$discord_logger->pushHandler(
    new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO)
);

$d = new Discord([
    'token' => $config['discord_token'],
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
    'logger' => $discord_logger
]);

$conv = new rolka\MediaConverter(
    $config['mozjpeg_cjpeg'],
    $config['mozjpeg_jpegtran']
);
$am = new rolka\AssetManager($ctx->db, $config['asset_path'], $conv);

$mapInsertMessage = function (Message $msg, $mapped_ch) use ($am, $ctx)
{
    $ch = $mapped_ch[$msg->channel_id];

    $attachments = [];

    if ($msg->attachments) {
        foreach ($msg->attachments as $a) {
            $asset = $am->downloadAsset($a->url);
            array_push($attachments, $asset);
        }
    }

    $embeds = [];

    if ($msg->embeds) {
        foreach ($msg->embeds as $e) {
            $provider = $provider_url = null;

            if ($e->provider) {
                $provider = $e->provider->name ?? null;
                $provider_url = $e->provider->url ?? null;
            }

            $embed_url = $e->video->url;
            $asset_url = $embed_url ? null : $e->thumbnail->url;

            $embed = new rolka\Embed(
                -1,
                $e->url,
                $e->type == 'rich' ? 'link' : $e->type,
                $e->color ? sprintf("#%06x", $e->color) : null,
                $e->timestamp ? DateTimeImmutable::createFromMutable($e->timestamp) : null,
                $provider,
                $provider_url,
                $e->footer?->text,
                $e->footer?->icon_url,
                $e->author?->name,
                $e->author?->url,
                $e->title,
                $e->url,
                $e->description,
                $asset_url ? $am->downloadAsset($asset_url) : null,
                $embed_url
            );
            array_push($embeds, $embed);
        }
    }

    if (!($author = $ctx->getAuthor($msg->author->id))) {
        $author = new rolka\Author(
            $msg->author->id,
            $msg->author->displayname,
            $am->downloadAsset($msg->author->avatar)
        );
        $ctx->insertAuthor($author);
    }

    $m = new rolka\Message(
        $ch,
        $msg->id,
        $author,
        DateTimeImmutable::createFromMutable($msg->timestamp),
        $msg->content,
        $msg->referenced_message?->id,
        $msg->sticker_items?->first()?->id,
        $ctx->insertAttachmentGroup($attachments),
        $ctx->insertEmbedGroup($embeds),
    );

    // hack... should put stuff on a queue
    foreach ($attachments as $a) {
        $am->generateThumbnail($a->id);
    }

    $ch->insertMessage($m);
};

$onMsgCreate = function (Message $msg, Discord $d) use ($ctx, $mapped_ch, $mapInsertMessage)
{
    echo "{$msg->author->username}: {$msg->content}", PHP_EOL;

    if (!isset($mapped_ch[$msg->channel_id])) {
        return;
    }

    $mapInsertMessage($msg, $mapped_ch);
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

$fetchWholeHistory = function (Discord $d, int $channel_id, $mapped_ch) use ($mapInsertMessage)
{
    $channel = $d->getChannel($channel_id);

    $last_id = null;

    while (true) {
        $messages = fetchMessages($channel, $mapped_ch, $last_id);

        if ($messages instanceof Collection) {
            foreach ($messages as $msg) {
                $mapInsertMessage($msg, $mapped_ch);
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
};

$d->on('init', function (Discord $d) use ($onMsgCreate, $fetchWholeHistory, $mapped_ch) {
    error_log("Hello World!");

    $d->on(Event::MESSAGE_CREATE, $onMsgCreate);

    foreach ($mapped_ch as $id => $channel) {
        $fetchWholeHistory($d, $id, $mapped_ch);
    }
});

$d->run();
