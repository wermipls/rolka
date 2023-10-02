<?php

namespace rolka;
use PDO;

class Channel
{
    private static string $table_prefix = 'tp_channel_';
    private string $channel;

    public function __construct(
        private PDO $db,
        string $channel
    ) {
        $this->channel = static::$table_prefix . $channel;
    }

    public function fetchAttachments(Message $msg): \Generator
    {
        if ($msg->attachment) {
            $att_query = $this->db->prepare(
                "SELECT type, url FROM tp_attachments WHERE id = :id");
            $att_query->bindParam(':id', $msg->attachment, PDO::PARAM_INT);
            $att_query->execute();
            while ($aq = $att_query->fetch()) {
                $a = new Attachment(
                    $msg->attachment,
                    $aq['type'],
                    $aq['url']
                );
                yield $a;
            }
        }
    }

    private function mapMessage(Array $row): Message
    {
        $author = new Author(
            $row['author_id'],
            $row['name'],
            $row['avatar_url']
        );

        return new Message(
            $row['uid'],
            $author,
            new \DateTimeImmutable($row['date_sent']),
            $row['content'],
            $row['replies_to'],
            $row['sticker'],
            $row['attachment']
        );
    }

    public function fetchMessage(int $id): ?Message
    {
        $s = $this->db->prepare(
            "SELECT * FROM `{$this->channel}` ch
             INNER JOIN tp_authors a
             ON ch.author_id = a.uid
             WHERE ch.uid = :id");
        $s->bindParam(':id', $id, PDO::PARAM_INT);
        $s->execute();
        $row = $s->fetch();
        if (!$row) {
            return null;
        }

        return $this->mapMessage($row);
    }

    public function fetchMessages(int $last_id, int $limit = 0): \Generator
    {
        $select = $this->db->prepare(
            "SELECT *
             FROM `{$this->channel}` ch
             INNER JOIN tp_authors
             ON ch.author_id = tp_authors.uid
             WHERE ch.uid > :last_id
             ORDER BY ch.uid ASC "
             . ($limit ? 'LIMIT :limit' : ''));
        $select->bindParam(':last_id', $last_id, PDO::PARAM_INT);
        if ($limit) {
            $select->bindParam(':limit', $limit, PDO::PARAM_INT);
        }
        $select->execute();

        while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
            yield $this->mapMessage($row);
        }
    }
}
