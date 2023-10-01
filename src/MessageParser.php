<?php

namespace rolka;

class MessageParser
{
    private Markdown $markdown;

    public function __construct(Array $authors_by_id)
    {
        $this->markdown = new Markdown($authors_by_id);
        $this->markdown->setSafeMode(true);
        $this->markdown->setBreaksEnabled(true);
    }

    public function parse(string $content): string
    {
        $content = $this->markdown->line($content);
        $content = (new EmojiText($content))->toTag();
        return $content;
    }
}
