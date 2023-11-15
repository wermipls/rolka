<?php

namespace rolka;

use DateTimeInterface;

class MessageRenderer
{
    private ?Message $prev_msg = null;
    private int $timestamp;
    private Array $asset_urls = [];

    public function __construct(
        private MessageParser $parser,
        private Channel $channel,
        private string $asset_key
    ) {
        $this->timestamp = 0;
    }

    private function assetUrl(?string $url): string
    {
        if (!$url) {
            return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        }

        if (isset($this->asset_urls[$url])) {
            return $this->asset_urls[$url];
        }
        $s = SignedStamp::withKey(
            $this->timestamp,
            $url,
            $this->asset_key);

        $url_signed = "{$url}?k={$s->hash}";
        $this->asset_urls[$url] = $url_signed;

        return $url_signed;
    }

    private function parse(Message $msg): string
    {
        return $msg->content ? $this->parser->parse($msg->content) : '';
    }

    private function parseReply(Message $msg): string
    {
        return strip_tags(
            $this->parse($msg->repliesTo()),
            "<img><br>"
        );
    }

    private function drawDateHeader(DateTimeInterface $date)
    {
        echo "<div class='msg_date_header'>{$date->format('l, j F Y')}</div>";
    }

    private function drawPlaque(Author $author)
    {
        if ($author->type != AuthorType::User) {
            echo "<span class='plaque'>{$author->type->name()}</span>";
        }
    }

    private function drawHeader(Message $msg)
    {
        $dts = "<span class='msg_date'>on {$msg->date->format('d.m.Y')}</span>";

?>
<div class='msg_side'>
    <img class='msg_avi' src='<?php echo $this->assetUrl($msg->avatar()) ?>'></img>
</div>
<div class='msg_header'>
    <span class='msg_user'><?php echo $this->parser->parseEmoji($msg->authorName()) ?> </span>
    <?php $this->drawPlaque($msg->author()) ?>
    <?php if ($msg->replies_to): ?>
        <?php $replies_to = $this->channel->fetchMessage($msg->replies_to); ?>
        <span class='msg_reply'> in response to </span>
        <a class='msg_reply_ref' href='#<?php echo $replies_to->id ?>'>
            <span class='msg_reply_user'> <?php echo $this->parser->parseEmoji($replies_to->authorName()) ?> </span>
            <?php echo $dts ?>
            <br>
            <span class='msg_reply_content'><?php echo $this->parseReply($msg) ?></span>
        </a>
    <?php else: ?>
        <?php echo $dts ?>
    <?php endif; ?>
</div>
<?php

    }

    private function drawAttachment(Asset $att)
    {
        $type = $att->type;
        if ($type == 'image' && $att->size > 1024*1024*1 && !$att->thumb_url) {
            $type = 'file';
        }
        $url   = $this->assetUrl($att->url);
        $thumb = $this->assetUrl($att->thumb());
        switch ($type) {
        case 'image':
            echo "<a href='$url' target='_blank'><img loading='lazy' class='msg_attachment msg_element' src='$thumb'></img></a>";
            break;
        case 'video':
            $poster = $att->thumb_url ? "poster='{$thumb}'" : '';
            echo "<video class='msg_attachment msg_element' src='$url' $poster preload='none' controls></video>";
            break;
        case 'audio':
            echo "<audio class='msg_attachment msg_element' src='$url' controls></audio>";
            break;
        case 'file':
            preg_match('/^(?:.*\/)?(.+)$/', $att->url, $matches);
            $fname = $att->name ?? $matches[1];
            $size = $att->naturalSize();
            echo "<div class='msg_attachment'><a class='msg_file' href='$url'>File: $fname ($size)</a></div>";
            break;
        }
    }

    private function drawEmbedAsset(Asset $att)
    {
        $type = $att->type;
        if ($type == 'image' && $att->size > 1024*1024*1 && !$att->thumb_url) {
            $type = 'file';
        }
        $url   = $this->assetUrl($att->url);
        $thumb = $this->assetUrl($att->thumb());
        switch ($type) {
        case 'image':
            echo "<a href='$url' target='_blank'><img loading='lazy' class='msg_embed_asset' src='$thumb'></img></a>";
            break;
        case 'video':
            $poster = $att->thumb_url ? "poster='{$thumb}'" : '';
            echo "<video class='msg_embed_asset' src='$url' $poster preload='none' controls></video>";
            break;
        case 'audio':
            echo "<audio class='msg_embed_asset' src='$url' controls></audio>";
            break;
        case 'file':
            preg_match('/^(?:.*\/)?(.+)$/', $att->url, $matches);
            $fname = $att->name ?? $matches[1];
            $size = $att->naturalSize();
            echo "<div class='msg_embed_asset'><a class='msg_file' href='$url'>File: $fname ($size)</a></div>";
            break;
        }
    }

    private function drawEmbed(Embed $e)
    {
        $has_rich_box = $e->type == 'link';
        $color = $e->color ?? 'var(--color-fg-secondary)';
 
        if ($has_rich_box) echo "<div class='msg_embed' style='border-left: 4px solid {$color}'>";
        if ($e->author) {
            if ($e->author_url) {
                echo "<div class='msg_embed_author'>"
                    ."<a href='" . htmlspecialchars($e->author_url) . "'>"
                    .$this->parser->parseEmoji($e->author)
                    ."</a>"
                    ."</div>";
            } else {
                echo "<div class='msg_embed_author'>"
                    .$this->parser->parseEmoji($e->author)
                    ."</div>";
            }
        }
        if ($e->title) {
            if ($e->title_url) {
                echo "<div class='msg_embed_title'>"
                    ."<a href='" . htmlspecialchars($e->title_url) . "'>"
                    .$this->parser->parseEmoji($e->title)
                    ."</a>"
                    ."</div>";
            } else {
                echo "<div class='msg_embed_title'>"
                    .$this->parser->parseEmoji($e->title)
                    ."</div>";
            }
        }
        if ($e->description) {
            $c = $this->parser->parse($e->description);
            echo "<div class='msg_embed_desc'>{$c}</div>";
        }
        if ($e->embed_url) {
            echo "<div class='container_16_9 ifwrap' onclick='load_iframe(this)'>"
                ."<iframe class='embed_if' loading='lazy' src='' src_pre='{$e->embed_url}'></iframe>"
                ."</div>";
        }
        if ($e->asset) {
            if ($has_rich_box) {
                $this->drawEmbedAsset($e->asset);
            } else {
                $this->drawAttachment($e->asset);
            }
        }
        if ($e->footer) {
            if ($e->footer_url) {
                echo "<div class='msg_embed_footer'>"
                    ."<a href='" . htmlspecialchars($e->footer_url) . "'>"
                    .$this->parser->parseEmoji($e->footer)
                    ."</a>"
                    ."</div>";
            } else {
                echo "<div class='msg_embed_footer'>"
                    .$this->parser->parseEmoji($e->footer)
                    ."</div>";
            }
        }
        if ($has_rich_box) echo "</div>";
    }

    private function drawAttachments(Message $msg)
    {
        foreach ($this->channel->fetchAttachments($msg) as $a) {
            $this->drawAttachment($a);
            echo "<br>";
        }
    }

    private function drawEmbeds(Message $msg)
    {
        foreach ($this->channel->fetchEmbeds($msg) as $e) {
            $this->drawEmbed($e);
        }
    }

    private function drawSticker(Message $msg)
    {
        if ($msg->sticker) {
            echo "<img loading='lazy' class='msg_sticker msg_element' src='https://media.discordapp.net/stickers/{$msg->sticker}?size=256'></img>";
        }
    }

    private function isEmojiOnly(string $parsed_content): bool
    {
        if (trim(strip_tags($parsed_content)) === '') {
            return true;
        }
        return false;
    }

    private function drawMessage(Message $msg)
    {
        $parsed = $this->parse($msg);
        $class_big = $this->isEmojiOnly($parsed) ? 'msg_big' : '';

?>
<div class='msg_side'>
    <div class='msg_time'><?php echo $msg->date->format('H:i') ?></div>
</div>
<div class='msg_primary'>
    <?php if ($msg->content): ?>
        <div class='msg_content msg_element <?php echo $class_big ?>'>
            <?php echo $parsed ?>
        </div>
    <?php endif; ?>
    <?php $this->drawAttachments($msg) ?>
    <?php $this->drawEmbeds($msg) ?>
    <?php $this->drawSticker($msg) ?>
</div>
<?php

    }

    public function draw(Message $msg)
    {
        $show_author = false;
        if ($this->prev_msg) {
            if (DateHelper::isDifferentDay($this->prev_msg->date, $msg->date)) {
                $this->drawDateHeader($msg->date);
                $show_author = true;
            }
            
            $delta = $msg->date->diff($this->prev_msg->date);
            if (DateHelper::totalMinutes($delta) > 10) {
                $show_author = true;
            }

            if ($this->prev_msg->author != $msg->author) {
                $show_author = true;
            }

            if ($msg->webhook_name != $this->prev_msg->webhook_name) {
                $show_author = true;
            }
        } else {
            $show_author = true;
        }

        if ($msg->replies_to) {
            $show_author = true;
        }

?>
<div class='msg' id='<?php echo $msg->id ?>'>
    <?php if ($show_author): ?>
        <?php $this->drawHeader($msg) ?>
    <?php endif; ?>
    <?php $this->drawMessage($msg) ?>
</div>
<?php

    $this->prev_msg = $msg;
    }

    private function drawNextButton(int $id)
    {
        echo "<a class='nav_pagebtn' href='/?c={$this->channel->id}&from={$id}'>Next page</a>";
    }
    public function finish()
    {
        if (!$this->prev_msg) {
            return; // nothing to finalize
        }
        if ($this->channel->isLastMessage($this->prev_msg->id)) {
            return;
        }

        $this->drawNextButton($this->prev_msg->id);
    }
}
