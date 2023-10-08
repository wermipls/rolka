<?php

namespace rolka;
use PDO;

class Channel
{
    private static string $table_prefix = 'ch_';
    private string $channel;

    public function __construct(
        private PDO $db,
        string $channel
    ) {
        $this->channel = static::$table_prefix . $channel . '_messages';
    }

    public function fetchAttachments(Message $msg): \Generator
    {
        if ($msg->attachment) {
            $att_query = $this->db->prepare(
                "SELECT `att`.`id`, `type`, `url` FROM `attachments` `att`
                 INNER JOIN `assets` `a`
                 ON `a`.`id` = `att`.`asset_id`
                 WHERE `att`.`group_id` = :id");
            $att_query->bindParam(':id', $msg->attachment, PDO::PARAM_INT);
            $att_query->execute();
            while ($aq = $att_query->fetch()) {
                $a = new Attachment(
                    $aq['id'],
                    $aq['type'],
                    $aq['url']
                );
                yield $a;
            }
        }
    }

    public function fetchEmbeds(Message $msg): \Generator
    {
        if ($msg->embed) {
            $embed_query = $this->db->prepare(
                "SELECT * FROM `embeds` `e`
                 WHERE `e`.`group_id` = :id");
            $embed_query->bindParam(':id', $msg->embed, PDO::PARAM_INT);
            $embed_query->execute();
            while ($eq = $embed_query->fetch()) {
                $e = new Embed(
                    $eq['id'],
                    $eq['author'],
                    $eq['author_url'],
                    $eq['title'],
                    $eq['title_url'],
                    $eq['description'],
                    null,
                    $eq['asset_id']
                );
                yield $e;
            }
        }
    }

    private function mapMessage(Array $row): Message
    {
        $author = new Author(
            $row['author_id'],
            $row['display_name'],
            $row['url']
        );

        return new Message(
            $row['id'][0],
            $author,
            new \DateTimeImmutable($row['sent']),
            $row['content'],
            $row['replies_to'],
            $row['sticker'],
            $row['attachment_group'],
            $row['embed_group']
        );
    }

    public function fetchMessage(int $id): ?Message
    {
        $s = $this->db->prepare(
            "SELECT * FROM `{$this->channel}` ch
             INNER JOIN authors a
             ON ch.author_id = a.id
             LEFT JOIN assets
             ON a.avatar_asset = assets.id
             WHERE ch.id = :id");
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        $row = $s->fetch(PDO::FETCH_NAMED);
        if (!$row) {
            return null;
        }

        return $this->mapMessage($row);
    }

    public function fetchMessages(int $last_id, int $limit = 0): \Generator
    {
        $select = $this->db->prepare(
            "SELECT ch.*, a.*, assets.url
             FROM `{$this->channel}` ch
             INNER JOIN authors a
             ON ch.author_id = a.id
             LEFT JOIN assets
             ON a.avatar_asset = assets.id 
             WHERE ch.id > :last_id
             ORDER BY ch.id ASC "
             . ($limit ? 'LIMIT :limit' : '')
            );
        $select->bindParam(':last_id', $last_id, PDO::PARAM_INT);
        if ($limit) {
            $select->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        $select->execute();

        while ($row = $select->fetch(PDO::FETCH_NAMED)) {
            yield $this->mapMessage($row);
        }
    }

    public function isLastMessage(int $id): bool
    {
        $s = $this->db->query(
            "SELECT id FROM {$this->channel} ORDER BY id DESC LIMIT 1"
        );
        $row = $s->fetch();
        return $row['id'] == $id;
    }
}
