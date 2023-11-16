<?php

namespace rolka;

class Embed
{
    public function __construct(
        public int $id,
        public ?string $url,
        public string $type,
        public ?string $color,
        public ?\DateTimeImmutable $timestamp,
        public ?string $provider,
        public ?string $provider_url,
        public ?string $footer,
        public ?string $footer_url,
        public ?string $author,
        public ?string $author_url,
        public ?string $title,
        public ?string $title_url,
        public ?string $description,
        public ?Asset $asset,
        public ?string $embed_url,
    ) {
    }

    public function hasRichContent(): bool
    {
        if ($this->timestamp
         || $this->footer
         || $this->footer_url
         || $this->author
         || $this->author_url
         || $this->title
         || $this->description) {
            return true;
        }
        return false;
    }
}