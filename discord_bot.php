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
use rolka\MediaConverter;

class Bot
{
    private Array $channels = [];

    public function __construct(
        private Context $ctx,
        private Discord $d,
        private MediaConverter $conv,
        private AssetManager $am
    ) {
        $this->fetchSyncedChannels();

        $d->on(Event::MESSAGE_CREATE, function ($msg, $d) {
            $this->mapInsertMessage($msg);
        });

        $d->on('init', function (Discord $d) {
            error_log("Hello World!");

            foreach ($this->channels as $id => $channel) {
                $this->fetchWholeHistory($id);
            }
        });
    }

    public function run()
    {
        $this->d->run();
    }

    private function fetchSyncedChannels()
    {
        $q = $this->ctx->db->query("SELECT * FROM channels");
        $chn = $q->fetchAll();

        foreach ($chn as $c) {
            if ($sync_id = $c['sync_channel_id']) {
                $this->channels[$sync_id] = $this->ctx->getChannel($c['id']);
            }
        }
    }

    private function mapInsertMessage(Message $msg)
    {
        $ch = $this->channels[$msg->channel_id];
        if (!$ch) {
            return;
        }

        $attachments = [];
        if ($msg->attachments) {
            foreach ($msg->attachments as $a) {
                $asset = $this->am->downloadAsset($a->url);
                array_push($attachments, $asset);
            }
        }

        $embeds = [];

        foreach ($msg->embeds as $e) {
            $embed_url = $e->video->url;
            $asset_url = $embed_url ? null : $e->thumbnail->url;
            if ($asset_url) {
                $asset = $this->am->downloadAsset($asset_url);
            }

            $embed = new rolka\Embed(
                -1,
                $e->url,
                $e->type == 'rich' ? 'link' : $e->type,
                $e->color ? sprintf("#%06x", $e->color) : null,
                $e->timestamp ? DateTimeImmutable::createFromMutable($e->timestamp) : null,
                $e?->provider?->name,
                $e?->provider?->url,
                $e->footer?->text,
                $e->footer?->icon_url,
                $e->author?->name,
                $e->author?->url,
                $e->title,
                $e->url,
                $e->description,
                $asset_url ? $this->am->downloadAsset($asset_url) : null,
                $embed_url
            );
            array_push($embeds, $embed);
        }

        if (!($author = $this->ctx->getAuthor($msg->author->id))) {
            $author = new rolka\Author(
                $msg->author->id,
                $msg->author->global_name ?? $msg->author->username,
                $this->am->downloadAsset($msg->author->avatar)
            );
            $this->ctx->insertAuthor($author);
        }

        $m = new rolka\Message(
            $ch,
            $msg->id,
            $author,
            DateTimeImmutable::createFromMutable($msg->timestamp),
            $msg->content,
            $msg->referenced_message?->id,
            $msg->sticker_items?->first()?->id,
            $this->ctx->insertAttachmentGroup($attachments),
            $this->ctx->insertEmbedGroup($embeds),
        );

        // hack... should put stuff on a queue
        foreach ($attachments as $a) {
            $this->am->generateThumbnail($a->id);
        }

        $ch->insertMessage($m);
    }

    private function fetchMessages(Channel $channel, ?int $before): mixed
    {
        error_log(__FUNCTION__ .": last id $before");
        $opts = ['limit' => 100];
        if ($before) {
            $opts['before'] = (string)$before;
        }

        return Async\await($channel->getMessageHistory($opts));
    }

    private function fetchWholeHistory(int $channel_id)
    {
        $ch = $this->d->getChannel($channel_id);

        $last_id = null;

        do {
            $messages = $this->fetchMessages($ch, $last_id);

            if (!($messages instanceof Collection)) {
                break;
            }

            foreach ($messages as $msg) {
                $this->mapInsertMessage($msg);
            }

            $last_id = $messages->last()?->id;
        } while ($last_id);
    }
}

$config = include(__DIR__.'/config.php');

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
];
$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$ctx = new Context($db);

$discord_logger = new \Monolog\Logger('discord-php');
$discord_logger->pushHandler(
    new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO)
);

$d = new Discord([
    'token' => $config['discord_token'],
    'intents' => Intents::getDefaultIntents() | Intents::MESSAGE_CONTENT,
    'logger' => $discord_logger
]);

$conv = new MediaConverter(
    $config['mozjpeg_cjpeg'],
    $config['mozjpeg_jpegtran']
);
$am = new AssetManager($ctx->db, $config['asset_path'], $conv);

$bot = new Bot($ctx, $d, $conv, $am);

$bot->run();
