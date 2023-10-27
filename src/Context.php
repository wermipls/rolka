<?php

namespace rolka;

use PDO;

class Context
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function fetchAuthorNames(): Array
    {
        $q = $this->db->query(
            "SELECT a.id, a.display_name, assets.url FROM authors a
             LEFT JOIN assets
             ON a.avatar_asset = assets.url");
        $f = $q->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE);

        return $f;
    }

    public function getChannel(int $id): Channel
    {
        return new Channel($this->db, $id);
    }
}
