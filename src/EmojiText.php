<?php

namespace rolka;
use Astrotomic\Twemoji;

class EmojiText extends Twemoji\EmojiText
{
    public function __construct(string $text)
    {
        $this->text = $text;
        $this->base('https://cdn.jsdelivr.net/gh/jdecked/twemoji@latest/assets');
    }

    public function toTag(): string
    {
        return $this->replace(
            "<img loading='lazy' class='msg_emote' src='%{src}' alt='%{alt}'>"
        );
    }
}
