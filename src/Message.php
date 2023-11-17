<?php

namespace rolka;
use DateTimeImmutable;
use DateTimeInterface;

class Message
{
    public readonly int $author;
    public readonly ?int $replies_to;
    private ?Author $author_obj = null;
    private ?Message $replies_to_obj = null;
    public DateTimeImmutable $date;

    public function __construct(
        public Channel $channel,
        public int $id,
        Author|int $author,
        DateTimeInterface $date,
        public ?string $content,
        int|Message|null $replies_to,
        public ?string $sticker,
        public ?int $attachment,
        public ?int $embed,
        public ?string $webhook_name,
        public ?Asset $webhook_avatar,
    ) {
        $this->date = DateTimeImmutable::createFromInterface($date);

        if ($author instanceof Author) {
            $this->author_obj = $author;
            $this->author = $author->id;
        } else {
            $this->author = $author;
        }

        if ($replies_to instanceof Message) {
            $this->replies_to_obj = $replies_to;
            $this->replies_to = $replies_to->id;
        } else {
            $this->replies_to = $replies_to;
        }
    }

    public function author(): Author
    {
        if (!$this->author_obj) {
            $this->author_obj = $this->channel->ctx->getAuthor($this->author);
        }
        return $this->author_obj;
    }

    public function repliesTo(): ?Message
    {
        if ($this->replies_to && !$this->replies_to_obj) {
            $this->replies_to_obj = $this->channel->fetchMessage($this->replies_to);
        }

        return $this->replies_to_obj;
    }

    public function authorName(): string
    {
        return $this->webhook_name ?? $this->author()->name;
    }

    public function avatar(): ?string
    {
        return $this->webhook_avatar?->thumb() ?? $this->author()->avatarUrl();
    }
}
