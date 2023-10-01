<?php

namespace rolka;
use Astrotomic\Twemoji;

class EmojiText extends Twemoji\EmojiText
{
    public function toTag(): string
    {
        return $this->replace(
            "<img loading='lazy' class='msg_emote' src='%{src}' alt='%{alt}'>"
        );
    }
}
