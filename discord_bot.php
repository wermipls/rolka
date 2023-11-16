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

        $d->on('init', function (Discord $d) {
            error_log("Hello World!");

            foreach ($this->channels as $id => $channel) {
                $this->fetchWholeHistory($id);
            }

            $this->updatePendingAuthors();
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

    private function mapEmbed(object $e): rolka\Embed
    {
        // some very interesting stuff going on, observed experimentally:
        //   'image', 'article' -> thumbnail is the image (image is empty?)
        //   'link', 'rich' -> thumbnail is an icon in top-right
        //   'gifv', 'video' -> thumbnail is a thumbnail
        // not sure if this is a discord api thing,
        // or just some fuckery in the library i'm using.
        // this requires some special handling

        $embed_url = $e->video?->url ?? null;

        $asset_url = $e->image?->url ?? null;
        if ($e->type == 'image' || $e->type == 'article') {
            $asset_url = $asset_url ?? ($e->thumbnail?->url ?? null);
        }

        // there's no actual thumbnail handling yet, so we discard it
        if ($embed_url) {
            $asset_url == null;
        }

        if ($asset_url) {
            $asset = $this->am->downloadAsset($asset_url);
        }

        // 🤮🤮🤮
        $embed = new rolka\Embed(
            -1,
            $e->url ?? null,
            $e->type == 'rich' ? 'link' : $e->type,
            ($e->color ?? null) ? sprintf("#%06x", $e->color) : null,
            ($e->timestamp ?? null) ? DateTimeImmutable::createFromMutable($e->timestamp) : null,
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
            $embed_url
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
        } else if (!$is_webhook)  {
            $this->authors_pending_update[$msg->author->id] = $msg->author;
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
            $is_webhook ? $msg->author->username : null,
            $is_webhook ? $this->am->downloadAsset($msg->author->avatar) : null
        );

        // hack... should put stuff on a queue
        foreach ($attachments as $a) {
            $this->am->generateThumbnail($a->id);
        }

        $ch->insertMessage($m);
    }

    private function updateMessagePartial(object $msg)
    {
        $ch = $this->channels[$msg->channel_id];
        if (!$ch) {
            return;
        }

        $attachments = [];
        if (isset($msg->attachments)) {
            foreach ($msg->attachments as $a) {
                $asset = $this->am->downloadAsset($a->url);
                array_push($attachments, $asset);
            }
        }

        $embeds = [];
        if (isset($msg->embeds)) {
            foreach ($msg->embeds as $e) {
                array_push($embeds, $this->mapEmbed($e));
            }
        }

        $ch->updateMessageEmbedAttachments(
            $msg->id,
            $this->ctx->insertEmbedGroup($embeds),
            $this->ctx->insertAttachmentGroup($attachments)
        );
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
