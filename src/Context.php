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
                assets.url,
                assets.thumb_url
            FROM authors a
            LEFT JOIN assets
            ON a.avatar_asset = assets.id");

        $authors = [];

        while ($row = $q->fetch()) {
            $author = new Author(
                $row['id'],
                $row['display_name'],
                (new Asset(-1, 'image', $row['url'], $row['thumb_url']))->thumb()
            );
            $authors[$author->id] = $author;
        }

        $this->authors = $authors;
        return $authors;
    }

    private function fetchAuthor(int $id): Author
    {
        $q = $this->db->prepare(
           "SELECT
                a.id,
                a.display_name,
                assets.url,
                assets.thumb_url
            FROM authors a
            LEFT JOIN assets
            ON a.avatar_asset = assets.id
            WHERE a.id = ?");
        $q->execute([$id]);

        $row = $q->fetch();
        $author = new Author(
            $row['id'],
            $row['display_name'],
            (new Asset(-1, 'image', $row['url'], $row['thumb_url']))->thumb()
        );

        return $author;
    }

    public function getAuthor(int $id, bool $force_fetch = false): Author
    {
        if ($force_fetch) {
            return $this->authors[$id] = $this->fetchAuthor($id);
        }
        return $this->authors[$id] ?? $this->authors[$id] = $this->fetchAuthor($id);
    }

    public function getChannel(int $id): Channel
    {
        return new Channel($this, $this->db, $id);
    }
}
