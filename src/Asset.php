<?php

namespace rolka;

class Asset
{
    public ?string $hash;
    public ?string $thumb_hash;
    public ?int $size;
    public bool $is_optimized;
    public ?string $original_url;
    public ?string $original_hash;

    public function __construct(
        public int $id,
        public string $type,
        public string $url,
        public ?string $thumb_url = null
    ) {
    }

    public function thumb(): string
    {
        return $this->thumb_url ? $this->thumb_url : $this->url;
    }

    public function __toString()
    {
        return "[Attachment: {$this->type}]";
    }
}
