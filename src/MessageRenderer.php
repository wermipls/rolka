<?php

namespace rolka;

use DateTimeInterface;

class MessageRenderer
{
    private ?Message $prev_msg = null;

    public function __construct(
        private MessageParser $parser,
        private Channel $channel
    ) {
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
    <img class='msg_avi' src='<?php echo $msg->author->avatar_url ?>'></img>
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

    private function drawAttachment(Attachment $att)
    {
        $url = htmlspecialchars($att->url);
        switch ($att->type) {
        case 'image':
            echo "<a href='$url' target='_blank'><img loading='lazy' class='msg_attachment msg_element' src='$url'></img></a>";
            break;
        case 'video':
            echo "<video class='msg_attachment msg_element' src='$url' controls></video>";
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

    private function drawAttachments(Message $msg)
    {
        foreach ($this->channel->fetchAttachments($msg) as $a) {
            $this->drawAttachment($a);
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
        echo "<a class='nav_pagebtn' href='/?from={$id}'>Next page</a>";
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
