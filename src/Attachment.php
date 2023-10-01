<?php

namespace rolka;

class Attachment
{
    public function __construct(
        public int $id,
        public string $type,
        public string $url,
    ) {
    }

    public function __toString()
    {
        return "[Attachment: {$this->type}]";
    }
}
