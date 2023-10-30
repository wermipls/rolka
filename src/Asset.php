<?php

namespace rolka;

class Asset
{
    public ?string $hash = null;
    public ?string $thumb_hash = null;
    public ?string $name = null;
    public ?int $size = null;
    public bool $is_optimized = false;
    public ?string $original_url = null;
    public ?string $original_hash = null;

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
