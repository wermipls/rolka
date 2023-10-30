<?php

namespace rolka;
use PDO;

class Channel
{
    private static string $table_prefix = 'ch_';
    private string $channel;

    public function __construct(
        public readonly Context $ctx,
        private PDO $db,
        public int $id
    ) {
        $q = $db->prepare("SELECT * FROM channels WHERE id = ?");
        $q->execute([$id]);
        $ch = $q->fetch();

        $this->channel = static::$table_prefix . $ch['table_name'] . '_messages';
    }

    public function fetchAttachments(Message $msg): \Generator
    {
        if ($msg->attachment) {
            $att_query = $this->db->prepare(
                "SELECT `att`.`asset_id`, `type`, `url`, `thumb_url`
                 FROM `attachments` `att`
                 INNER JOIN `assets` `a`
                 ON `a`.`id` = `att`.`asset_id`
                 WHERE `att`.`group_id` = :id");
            $att_query->bindParam(':id', $msg->attachment, PDO::PARAM_INT);
            $att_query->execute();
            while ($aq = $att_query->fetch()) {
                $a = new Asset(
                    $aq['asset_id'],
                    $aq['type'],
                    $aq['url'],
                    $aq['thumb_url']
                );
                yield $a;
            }
        }
    }

    public function fetchEmbeds(Message $msg): \Generator
    {
        if ($msg->embed) {
            $embed_query = $this->db->prepare(
                "SELECT 
                    `e`.*,
                    `a`.`url` AS `asset_url`,
                    `a`.`type` AS `asset_type`,
                    `a`.`thumb_url` AS `asset_thumb`
                 FROM `embeds` `e`
                 LEFT JOIN `assets` `a`
                 ON `a`.`id` = `e`.`asset_id`
                 WHERE `e`.`group_id` = :id");
            $embed_query->bindParam(':id', $msg->embed, PDO::PARAM_INT);
            $embed_query->execute();
            while ($eq = $embed_query->fetch(PDO::FETCH_NAMED)) {
                $e = new Embed(
                    $eq['id'],
                    $eq['url'],
                    $eq['type'],
                    $eq['color'],
                    DateHelper::fromDB($eq['timestamp']),
                    $eq['provider'],
                    $eq['provider_url'],
                    $eq['footer'],
                    $eq['footer_url'],
                    $eq['author'],
                    $eq['author_url'],
                    $eq['title'],
                    $eq['title_url'],
                    $eq['description'],
                    $eq['asset_id'] ? new Asset($eq['asset_id'],
                                                $eq['asset_type'],
                                                $eq['asset_url'],
                                                $eq['asset_thumb'])
                                    : null,
                    $eq['embed_url']
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
            new Asset(
                $row['avatar_asset'],
                'image',
                $row['url'],
                $row['thumb_url'])
        );

        return new Message(
            $this,
            $row['id'][0],
            $author,
            DateHelper::fromDB($row['sent']),
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

    public function insertMessage(Message $m, bool $ignore_exists = true)
    {
        $ignore = $ignore_exists ? 'IGNORE' : '';
        $s = $this->db->prepare("
            INSERT $ignore INTO `{$this->channel}`
            (
                id,
                author_id,
                sent,
                replies_to,
                content,
                sticker,
                attachment_group,
                embed_group
            )
            VALUES
            (
                :id,
                :author_id,
                :sent,
                :replies_to,
                :content,
                :sticker,
                :attachment_group,
                :embed_group
            )
            ");
        $s->bindValue(':id', $m->id);
        $s->bindValue(':author_id', $m->author);
        $s->bindValue(':sent', $m->date->format("Y-m-d H:i:s"));
        $s->bindValue(':replies_to', $m->replies_to);
        $s->bindValue(':content', $m->content);
        $s->bindValue(':sticker', $m->sticker);
        $s->bindValue(':attachment_group', $m->attachment);
        $s->bindValue(':embed_group', $m->embed);

        $s->execute();
    }

    public function fetchMessages(int $last_id, int $limit = 0): \Generator
    {
        $select = $this->db->prepare(
            "SELECT ch.*, a.*, assets.*
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
