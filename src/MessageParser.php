<?php

namespace rolka;

class MessageParser
{
    private Markdown $markdown;

    public function __construct(Context $context)
    {
        $this->markdown = new Markdown($context);
        $this->markdown->setSafeMode(true);
        $this->markdown->setBreaksEnabled(false);
    }

    public function parse(string $content): string
    {
        $content = $this->markdown->text($content);
        $content = (new EmojiText($content))->toTag();
        return $content;
    }

    public function parseEmoji(string $content): string
    {
        $content = htmlspecialchars($content);
        $content = (new EmojiText($content))->toTag();
        return $content;
    }
}
