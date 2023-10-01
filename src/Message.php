<?php

namespace rolka;
use DateTimeImmutable;

class Message
{
    public function __construct(
        public int $id,
        public Author $author,
        public DateTimeImmutable $date,
        public ?string $content,
        public ?Message $replies_to,
        public ?string $sticker,
        public ?Array $attachments,
    ) {
    }
}
