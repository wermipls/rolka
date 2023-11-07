<?php

namespace rolka;

use PDO;

class Context
{
    private ?Array $authors = [];

    public function __construct(
        public readonly PDO $db
    ) {
    }

    public function getAuthors(bool $force_fetch = false): Array
    {
        if ($this->authors && !$force_fetch) {
            return $this->authors;
        }

        $q = $this->db->query(
           "SELECT
                a.id,
                a.display_name,
                a.avatar_asset,
                assets.url,
                assets.thumb_url
            FROM authors a
            LEFT JOIN assets
            ON a.avatar_asset = assets.id");

        $authors = [];

        while ($row = $q->fetch(PDO::FETCH_NAMED)) {
            $author = new Author(
                $row['id'],
                $row['display_name'],
                new Asset(
                    $row['avatar_asset'],
                    'image',
                    $row['url'],
                    $row['thumb_url'])
            );
            $authors[$author->id] = $author;
        }

        $this->authors = $authors;
        return $authors;
    }

    private function fetchAuthor(int $id): ?Author
    {
        $q = $this->db->prepare(
           "SELECT
                a.id,
                a.display_name,
                a.avatar_asset,
                assets.url,
                assets.thumb_url
            FROM authors a
            LEFT JOIN assets
            ON a.avatar_asset = assets.id
            WHERE a.id = ?");
        $q->execute([$id]);

        $row = $q->fetch(PDO::FETCH_NAMED);
        if (!$row) {
            return null;
        }

        $author = new Author(
            $row['id'],
            $row['display_name'],
            new Asset(
                $row['avatar_asset'],
                'image',
                $row['url'],
                $row['thumb_url'])
        );

        return $author;
    }

    public function getAuthor(int $id, bool $force_fetch = false): ?Author
    {
        if ($force_fetch) {
            return $this->authors[$id] = $this->fetchAuthor($id);
        }
        return $this->authors[$id] ?? $this->authors[$id] = $this->fetchAuthor($id);
    }

    public function insertAuthor(Author $author): ?Author
    {
        $q = $this->db->prepare(
           "INSERT INTO authors
            (
                id,
                display_name,
                avatar_asset
            )
            VALUES
            (
                :id,
                :display_name,
                :avatar_asset
            )");
        $q->bindValue('id', $author->id);
        $q->bindValue('display_name', $author->name);
        $q->bindValue('avatar_asset', $author->avatar_id);

        if (!$q->execute()) {
            return null;
        };
        $this->authors[$author->id] = $author;
        return $author;
    }

    public function getChannel(?int $id): Channel
    {
        return new Channel($this, $this->db, $id);
    }

    public function insertAttachmentGroup(Array $assets): ?int
    {
        $q = $this->db->query("INSERT INTO attachment_groups (id) VALUES (NULL)");

        if (!$q) {
            return null;
        }

        $group_id = $this->db->lastInsertId();

        foreach ($assets as $a) {
            $q = $this->db->prepare(
               "INSERT INTO attachments
                (group_id, asset_id)
                VALUES
                (?, ?)");
            if (!$q->execute([$group_id, $a->id])) {
                return null;
            }
        }

        return $group_id;
    }

    public function insertEmbedGroup(Array $embeds): ?int
    {
        if (!isset($embeds)) {
            return null;
        }
        $q = $this->db->query("INSERT INTO embed_groups (id) VALUES (NULL)");

        if (!$q) {
            return null;
        }

        $group_id = $this->db->lastInsertId();

        foreach ($embeds as $e) {
            $q = $this->db->prepare(
               "INSERT INTO embeds
                (
                    `group_id`,
                    `url`,
                    `type`,
                    `color`,
                    `timestamp`,
                    `footer`,
                    `footer_url`,
                    `provider`,
                    `provider_url`,
                    `author`,
                    `author_url`,
                    `title`,
                    `title_url`,
                    `description`,
                    `embed_url`,
                    `asset_id`
                )
                VALUES
                (
                    :group_id,
                    :url,
                    :type,
                    :color,
                    :timestamp,
                    :footer,
                    :footer_url,
                    :provider,
                    :provider_url,
                    :author,
                    :author_url,
                    :title,
                    :title_url,
                    :description,
                    :embed_url,
                    :asset_id
                )");

            $q->bindValue('group_id', $group_id);
            $q->bindValue('url', $e->url);
            $q->bindValue('type', 'link'); // FIXME
            $q->bindValue('color', $e->color);
            $q->bindValue('timestamp', $e->timestamp ? $e->timestamp->format("Y-m-d H:i:s") : null);
            $q->bindValue('footer', $e->footer);
            $q->bindValue('footer_url', $e->footer_url); 
            $q->bindValue('provider', $e->provider);
            $q->bindValue('provider_url', $e->provider_url);
            $q->bindValue('author', $e->author);
            $q->bindValue('author_url', $e->author_url);
            $q->bindValue('title', $e->title);
            $q->bindValue('title_url', $e->title_url);
            $q->bindValue('description', $e->description);
            $q->bindValue('embed_url', $e->embed_url);
            $q->bindValue('asset_id', $e->asset ? $e->asset->id : null);

            if (!$q->execute()) {
                return null;
            }
        }

        return $group_id;
    }
}
