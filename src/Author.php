<?php

namespace rolka;

class Author
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $avatar_url
    ) {
        if (!$this->avatar_url) {
            $this->avatar_url = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        }
    }
}
