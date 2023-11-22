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
        public ?int $id = null
    ) {
        if ($id !== null) {
            $q = $db->prepare("SELECT * FROM channels WHERE id = ?");
            $q->execute([$id]);
        } else {
            $q = $db->query("SELECT * FROM `channels` LIMIT 1");
        }
        $ch = $q->fetch();

        if (!$ch) {
            throw new \Exception("unable to fetch channel id {$id}");
        }

        $this->id = $ch['id'];
        $this->channel = static::$table_prefix . $ch['table_name'] . '_messages';
    }

    public function fetchAttachments(Message $msg): \Generator
    {
        if ($msg->attachment) {
            $att_query = $this->db->prepare(
                "SELECT `att`.`asset_id`, `type`, `url`, `thumb_url`, `size`
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
                    $aq['thumb_url'],
                );
                $a->size = $aq['size'];
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
                    `a`.`thumb_url` AS `asset_thumb`,
                    `a2`.`url` AS `icon_url`,
                    `a2`.`type` AS `icon_type`,
                    `a2`.`thumb_url` AS `icon_thumb`
                 FROM `embeds` `e`
                 LEFT JOIN `assets` `a`
                 ON `a`.`id` = `e`.`asset_id`
                 LEFT JOIN `assets` `a2`
                 ON `a2`.`id` = `e`.`icon_asset`
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
                    $eq['embed_url'],
                    $eq['icon_asset'] ? new Asset($eq['icon_asset'],
                                                  $eq['icon_type'],
                                                  $eq['icon_url'],
                                                  $eq['icon_thumb'])
                                      : null
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
            $row['avatar_asset']
                ? new Asset(
                    $row['avatar_asset'],
                    'image',
                    $row['url'],
                    $row['thumb_url'])
                : null,
            $row['author_type']
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
            $row['embed_group'],
            $row['webhook_name'],
            $row['webhook_avatar']
                ? new Asset(
                    $row['webhook_avatar'],
                    'image',
                    $row['wh_av_url'],
                    $row['wh_av_thumb_url'])
                : null,
            DateHelper::fromDB($row['modified']),
        );
    }

    public function fetchMessage(int $id): ?Message
    {
        $s = $this->db->prepare(
            "SELECT
                ch.*,
                a.*,
                a.type as author_type,
                av.*,
                wh_av.url as wh_av_url,
                wh_av.thumb_url as wh_av_thumb_url
             FROM `{$this->channel}` ch
             INNER JOIN authors a
             ON ch.author_id = a.id
             LEFT JOIN assets av
             ON a.avatar_asset = av.id
             LEFT JOIN assets wh_av
             ON ch.webhook_avatar = wh_av.id
             WHERE ch.id = :id");
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        $row = $s->fetch(PDO::FETCH_NAMED);
        if (!$row) {
            return null;
        }

        return $this->mapMessage($row);
    }

    public function fetchMessages(int $last_id, int $limit = 0, int $page = 0): \Generator
    {
        $select = $this->db->prepare(
            "SELECT
                ch.*,
                a.*,
                a.type as author_type,
                av.*,
                wh_av.url as wh_av_url,
                wh_av.thumb_url as wh_av_thumb_url
             FROM `{$this->channel}` ch
             INNER JOIN authors a
             ON ch.author_id = a.id
             LEFT JOIN assets av
             ON a.avatar_asset = av.id
             LEFT JOIN assets wh_av
             ON ch.webhook_avatar = wh_av.id
             WHERE ch.id > :last_id
             ORDER BY ch.id ASC "
             . ($limit ? 'LIMIT :offset, :limit' : '')
            );
        $select->bindParam(':last_id', $last_id, PDO::PARAM_INT);
        if ($limit) {
            $select->bindParam(':limit', $limit, PDO::PARAM_INT);
            $select->bindValue(':offset', $page * $limit, PDO::PARAM_INT);
        }
        $select->execute();

        while ($row = $select->fetch(PDO::FETCH_NAMED)) {
            yield $this->mapMessage($row);
        }
    }

    public function getMessageCount(): int
    {
        $q = $this->db->query("SELECT COUNT(*) FROM `{$this->channel}`");
        $fetch = $q->fetch();
        return $fetch[0] ?? 0;
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
                modified,
                replies_to,
                content,
                sticker,
                attachment_group,
                embed_group,
                webhook_name,
                webhook_avatar
            )
            VALUES
            (
                :id,
                :author_id,
                :sent,
                :modified,
                :replies_to,
                :content,
                :sticker,
                :attachment_group,
                :embed_group,
                :webhook_name,
                :webhook_avatar
            )
            ON DUPLICATE KEY UPDATE
                modified = :modified,
                replies_to = :replies_to,
                content = :content,
                attachment_group = :attachment_group,
                embed_group = :embed_group
            ");
        $s->bindValue(':id', $m->id);
        $s->bindValue(':author_id', $m->author);
        $s->bindValue(':sent', DateHelper::toDB($m->date));
        $s->bindValue(':modified', DateHelper::toDB($m->modified));
        $s->bindValue(':replies_to', $m->replies_to);
        $s->bindValue(':content', $m->content);
        $s->bindValue(':sticker', $m->sticker);
        $s->bindValue(':attachment_group', $m->attachment);
        $s->bindValue(':embed_group', $m->embed);
        $s->bindValue(':webhook_name', $m->webhook_name);
        $s->bindValue(':webhook_avatar', $m->webhook_avatar?->id);

        $s->execute();
    }

    public function updateMessageEmbedAttachments(
        int $msg, ?int $embed_group, ?int $attachment_group
    ) {
        $s = $this->db->prepare("
            UPDATE `{$this->channel}` SET
                attachment_group = :attachment_group,
                embed_group = :embed_group
            WHERE id = :id
            ");
        $s->bindValue(':id', $msg);
        $s->bindValue(':attachment_group', $attachment_group);
        $s->bindValue(':embed_group', $embed_group);

        $s->execute();
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
