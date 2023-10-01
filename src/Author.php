<?php

namespace rolka;

class Author
{
    public function __construct(
        public int $id,
        public string $name,
        public string $avatar_url
    ) {
    }
}
