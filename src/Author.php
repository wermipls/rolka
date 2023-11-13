<?php

namespace rolka;

enum AuthorType: string
{
    case User = 'user';
    case Bot = 'bot';
    case Webhook = 'webhook';

    public function name()
    {
        return $this->value;
    }
}

class Author
{
    private ?Asset $avatar = null;
    public readonly ?int $avatar_id;
    public AuthorType $type;

    public function __construct(
        public int $id,
        public string $name,
        null|int|Asset $avatar,
        AuthorType|string $type
    ) {
        if ($avatar != null && $avatar instanceof Asset) {
            $this->avatar = $avatar;
            $this->avatar_id = $avatar->id;
        } else {
            $this->avatar_id = $avatar;
        }

        if ($type instanceof AuthorType) {
            $this->type = $type;
        } else {
            $this->type = AuthorType::tryFrom($type) ?? AuthorType::User;
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
