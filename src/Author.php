<?php

namespace rolka;

class Author
{
    private ?Asset $avatar = null;
    public readonly ?int $avatar_id;
    public function __construct(
        public int $id,
        public string $name,
        null|int|Asset $avatar
    ) {
        if ($avatar != null && $avatar instanceof Asset) {
            $this->avatar = $avatar;
            $this->avatar_id = $avatar->id;
        } else {
            $this->avatar_id = $avatar;
        }
    }

    public function avatarUrl(): ?string
    {
        if (!$this->avatar) {
            return null;
        }
        return $this->avatar->thumb();
    }
}
