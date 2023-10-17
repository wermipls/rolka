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
        $this->timestamp = time();
    }

    private function assetUrl(string $url): string
    {
        if (isset($this->asset_urls[$url])) {
            return $this->asset_urls[$url];
        }
        $s = SignedStamp::withKey(
            $this->timestamp,
            $url,
            $this->asset_key);

        $url_signed = "{$url}?k={$s->hash}&ts={$this->timestamp}";
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
            $this->parse($this->channel->fetchMessage($msg->replies_to)),
            "<img><br>"
        );
    }

    private function drawDateHeader(DateTimeInterface $date)
    {
        echo "<div class='msg_date_header'>{$date->format('l, j F Y')}</div>";
    }

    private function drawHeader(Message $msg)
    {
        $dts = "<span class='msg_date'>on {$msg->date->format('d.m.Y')}</span>";

?>
<div class='msg_side'>
    <img class='msg_avi' src='<?php echo $this->assetUrl($msg->author->avatar_url) ?>'></img>
</div>
<div class='msg_header'>
    <span class='msg_user'><?php echo $msg->author->name ?> </span>
    <?php if ($msg->replies_to): ?>
        <?php $replies_to = $this->channel->fetchMessage($msg->replies_to); ?>
        <span class='msg_reply'> in response to </span>
        <a class='msg_reply_ref' href='#<?php echo $replies_to->id ?>'>
            <span class='msg_reply_user'> <?php echo $replies_to->author->name ?> </span>
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
        $url   = $this->assetUrl($att->url);
        $thumb = $this->assetUrl($att->thumb());
        switch ($att->type) {
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
            preg_match('/^(?:.*\/)?(.+)$/', $url, $matches);
            $fname = $matches[1];
            echo "<div class='msg_attachment msg_element'><a class='msg_file' href='$url'>File: $fname</a></div>";
            break;
        }
    }

    private function drawEmbedAsset(Asset $att)
    {
        $url   = $this->assetUrl($att->url);
        $thumb = $this->assetUrl($att->thumb());
        switch ($att->type) {
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
            preg_match('/^(?:.*\/)?(.+)$/', $url, $matches);
            $fname = $matches[1];
            echo "<div class='msg_embed_asset'><a class='msg_file' href='$url'>File: $fname</a></div>";
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
                    .htmlspecialchars($e->author)
                    ."</a>"
                    ."</div>";
            } else {
                echo "<div class='msg_embed_author'>"
                    .htmlspecialchars($e->author)
                    ."</div>";
            }
        }
        if ($e->title) {
            if ($e->title_url) {
                echo "<div class='msg_embed_title'>"
                    ."<a href='" . htmlspecialchars($e->title_url) . "'>"
                    .htmlspecialchars($e->title)
                    ."</a>"
                    ."</div>";
            } else {
                echo "<div class='msg_embed_title'>"
                    .htmlspecialchars($e->title)
                    ."</div>";
            }
        }
        if ($e->description) {
            $c = $this->parser->parse($e->description);
            echo "<div class='msg_embed_desc'>{$c}</div>";
        }
        if ($e->embed_url) {
            echo "<div class='container_16_9'>"
                ."<iframe class='embed_if' loading='lazy' src='{$e->embed_url}'></iframe>"
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
                    .htmlspecialchars($e->footer)
                    ."</a>"
                    ."</div>";
            } else {
                echo "<div class='msg_embed_footer'>"
                    .htmlspecialchars($e->footer)
                    ."</div>";
            }
        }
        if ($has_rich_box) echo "</div>";
    }

    private function drawAttachments(Message $msg)
    {
        foreach ($this->channel->fetchAttachments($msg) as $a) {
            $this->drawAttachment($a);
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
            if ($delta->i > 10) {
                $show_author = true;
            }

            if ($this->prev_msg->author != $msg->author) {
                $show_author = true;
            }
        } else {
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
