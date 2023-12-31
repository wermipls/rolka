<?php

include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\User;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Discord\Parts\Channel\Channel;
use rolka\AssetManager;
use rolka\Author;
use rolka\AuthorType;
use rolka\Context;
use React\Async;
use rolka\MediaConverter;

class Bot
{
    private const AUTHOR_UPDATE_TIMEOUT = 60;
    private Array $channels = [];
    private int $authors_last_update = 0;
    private Array $authors_pending_update = [];

    public function __construct(
        private Context $ctx,
        private Discord $d,
        private MediaConverter $conv,
        private AssetManager $am
    ) {
        $this->fetchSyncedChannels();

        $d->on(Event::MESSAGE_CREATE, function ($msg, $d) {
            $this->mapInsertMessage($msg);
            $this->updatePendingAuthors();
        });

        $d->on(Event::MESSAGE_UPDATE, function (object $msg, Discord $d, ?Message $msg_old) {
            if ($msg instanceof Message) {
                $this->mapInsertMessage($msg);
            } else {
                $this->updateMessagePartial($msg);
            }
        });

        $d->on(Event::MESSAGE_DELETE, function (object $msg) {
            $this->markDeleted($msg);
        });

        $d->on(Event::MESSAGE_DELETE_BULK, function (Collection $messages) {
            foreach ($messages as $msg) {
                $this->markDeleted($msg);
            } 
        });

        $d->on('init', function (Discord $d) {
            error_log("Hello World!");

            React\Async\async(function () {
                foreach ($this->channels as $id => $channel) {
                    $this->fetchWholeHistory($id);
                }

                $this->updatePendingAuthors();
            })();
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

    private function updateAuthor(User $author, bool $is_webhook = false): ?Author
    {
        $author = new Author(
            $author->id,
            $author->global_name ?? $author->username,
            $this->am->downloadAsset($author->avatar),
            match (true) {
                $is_webhook     => AuthorType::Webhook,
                $author->bot    => AuthorType::Bot,
                default         => AuthorType::User
            }
        );
        return $this->ctx->insertUpdateAuthor($author);
    }

    private function updatePendingAuthors()
    {
        $time = time();
        $delta = $time - $this->authors_last_update;
        if ($delta < static::AUTHOR_UPDATE_TIMEOUT) {
            return;
        }
        $this->authors_last_update = $time;

        error_log("Updating authors...");

        foreach ($this->authors_pending_update as $user) {
            if ($user) {
                $this->updateAuthor($user);
                error_log($user->username);
                unset($this->authors_pending_update[$user->id]);
            }
        }
    }

    private function getUrlHeader(string $url): Array
    {
        $c = curl_init();
        curl_setopt_array($c, [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 1,
            CURLOPT_NOBODY => 1,
            CURLOPT_RETURNTRANSFER => 1
        ]);

        $header = [];

        $result = explode("\n", trim(curl_exec($c)));
        foreach ($result as $line) {
            if (preg_match("/^([\w-]+): (.*)/", $line, $matches)) {
                $header[strtolower($matches[1])] = $matches[2];
            }
        }

        return $header;
    }

    private function isVideo(string $url): ?bool
    {
        $header = $this->getUrlHeader($url);
        if (isset($header['content-type'])) {
            if (str_starts_with($header['content-type'], 'video')) {
                return true;
            } else {
                return false;
            }
        }
        return null;
    }

    private function mapEmbed(object $e): rolka\Embed
    {
        // some very interesting stuff going on, observed experimentally:
        //   'image', 'article' -> thumbnail is the image (image is empty?)
        //   'link', 'rich' -> thumbnail is an icon in top-right
        //   'gifv', 'video' -> thumbnail is a thumbnail
        // not sure if this is a discord api thing,
        // or just some fuckery in the library i'm using.
        // this requires some special handling

        // "video" url can be either an actual video or an embed iframe,
        // which we need to determine somehow. i attempt to crawl the url
        // to determine the mime type
        $embed_url = $e->video?->url ?? null;
        if ($embed_url) {
            $is_video = $this->isVideo($embed_url);

            if ($is_video) {
                $asset_url = $embed_url;
                $embed_url = null;
            }
        }

        $asset_url = $asset_url ?? ($e->image?->url ?? null);
        if ($e->type == 'image'
         || $e->type == 'article'
         || $e->type == 'video') {
            $asset_url = $asset_url ?? ($e->thumbnail?->url ?? null);
        }

        if ($e->type == 'link' || $e->type == 'rich') {
            $icon_url = $e->thumbnail?->url ?? null;
        } else {
            $icon_url = null;
        }

        if ($asset_url) {
            $asset = $this->am->downloadAsset($asset_url);
        }
        if (isset($asset)) {
            $this->am->generateThumbnail($asset->id);
        }

        if ($icon_url) {
            $icon = $this->am->downloadAsset($icon_url);
        }
        if (isset($icon)) {
            $this->am->generateThumbnail($icon->id);
        }

        $timestamp = $e->timestamp ?? null;

        if (!($timestamp instanceof DateTimeInterface)) {
            $timestamp = new DateTime($timestamp);
        }

        // 🤮🤮🤮
        $embed = new rolka\Embed(
            -1,
            $e->url ?? null,
            $e->type == 'rich' ? 'link' : $e->type,
            ($e->color ?? null) ? sprintf("#%06x", $e->color) : null,
            $timestamp,
            $e->provider?->name ?? null,
            $e->provider?->url ?? null,
            $e->footer?->text ?? null,
            null, // no such thing as a "footer url" lol
            $e->author?->name ?? null,
            $e->author?->url ?? null,
            $e->title ?? null,
            $e->url ?? null,
            $e->description ?? null,
            $asset ?? null,
            $embed_url,
            $icon ?? null
        );

        return $embed;
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
            array_push($embeds, $this->mapEmbed($e));
        }

        $is_webhook = $msg->webhook_id !== null;

        if (!($author = $this->ctx->getAuthor($msg->author->id))) {
            $author = $this->updateAuthor($msg->author, $is_webhook);
        } else if (!$is_webhook) {
            $this->authors_pending_update[$msg->author->id] = $msg->author;
        }

        $m = new rolka\Message(
            $ch,
            $msg->id,
            $author,
            $msg->timestamp,
            $msg->content,
            $msg->message_reference?->message_id,
            $msg->sticker_items?->first()?->id,
            $this->ctx->insertAttachmentGroup($attachments),
            $this->ctx->insertEmbedGroup($embeds),
            $is_webhook ? $msg->author->username : null,
            $is_webhook ? $this->am->downloadAsset($msg->author->avatar) : null,
            $msg->edited_timestamp,
            false,
        );

        // hack... should put stuff on a queue
        foreach ($attachments as $a) {
            $this->am->generateThumbnail($a->id);
        }

        if (!$m->isEmpty()) {
            $ch->insertMessage($m);
        }
    }

    private function updateMessagePartial(object $msg)
    {
        $ch = $this->channels[$msg->channel_id];
        if (!$ch) {
            return;
        }

        $embeds = [];
        if (isset($msg->embeds)) {
            foreach ($msg->embeds as $e) {
                array_push($embeds, $this->mapEmbed($e));
            }
        }

        $ch->updateMessageEmbed(
            $msg->id,
            $this->ctx->insertEmbedGroup($embeds),
        );
    }

    private function markDeleted(object $msg)
    {
        $ch = $this->channels[$msg->channel_id];
        if (!$ch) {
            return;
        }

        $ch->markDeleted((int)$msg->id);
    }

    private function findMarkDeletedMessages(
        Collection $messages,
        int $channel_id,
        ?int $last_id
    ) {
        $message_ids = [];
        if ($last_id) {
            array_push($message_ids, $last_id);
        }
        foreach ($messages as $m) {
            array_push($message_ids, (int)$m->id);
        }
        $max = max($message_ids);
        $min = min($message_ids);

        $ch = $this->channels[$channel_id];
        foreach ($ch->fetchMessages($min, before: $max) as $m) {
            $id = $m->id;
            if (!in_array($id, $message_ids, true)) {
                $ch->markDeleted($m);
            }
        }
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

            $this->findMarkDeletedMessages($messages, $channel_id, $last_id);

            $last_id = $messages->last()?->id;
        } while ($last_id);
    }
}

$config = include(__DIR__.'/config.php');

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
];
$db = new pine3ree\PDO\Reconnecting\PDO(
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
