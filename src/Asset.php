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
    public ?string $origin_url = null;

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

    public function naturalSize(): string
    {
        $s = $this->size;
        if ($s > 1000*1000) {
            return number_format($s/1000000, 1, thousands_separator: '') . "MB";
        } else if ($s > 1000) {
            return number_format($s/1000, 1, thousands_separator: '') . "KB";
        } else {
            return $s . "B";
        }
    }

    public function __toString()
    {
        return "[Attachment: {$this->type}]";
    }
}
