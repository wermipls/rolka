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

    public function toTag(bool $lazy = true): string
    {
        $lazy = $lazy ? "loading='lazy'" : '';
        return $this->replace(
            "<img $lazy class='msg_emote' src='%{src}' alt='%{alt}'>"
        );
    }
}
