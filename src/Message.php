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
        public ?int $replies_to,
        public ?string $sticker,
        public ?int $attachment,
        public ?int $embed,
    ) {
    }
}
