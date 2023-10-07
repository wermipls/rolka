<?php

namespace rolka;

class Embed
{
    public function __construct(
        public int $id,
        public ?string $author,
        public ?string $author_url,
        public ?string $title,
        public ?string $title_url,
        public ?string $description,
        public ?string $url,
        public ?int $asset_id,
    ) {
    }
}