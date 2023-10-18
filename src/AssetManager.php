<?php

namespace rolka;

class AssetManager
{
    private int $base_dir_len;
    private int $base_url_len;
    private string $base_url = 'assets/';
    public string $thumb_dir = 'thumb/';

    public function __construct(
        private \PDO $pdo,
        private string $base_dir,
        private MediaConverter $conv
    ) {
        $this->base_dir_len = mb_strlen($base_dir);
        $this->base_url_len = mb_strlen($this->base_url);
    }

    private function toUrl(string $path): ?string
    {
        if (str_starts_with($path, $this->base_dir)) {
            return $this->base_url . mb_substr($path, $this->base_dir_len);
        }
        return null;
    }

    private function toPath(string $url): ?string
    {
        if (str_starts_with($url, $this->base_url)) {
            return $this->base_dir . mb_substr($url, $this->base_url_len);
        }
        return null;
    }

    private function mapAsset(array $row): Asset
    {
        $a = new Asset($row['id'], $row['type'], $row['url']);

        [
            'thumb_url'  => $a->thumb_url,
            'id'         => $a->id,
            'thumb_hash' => $a->thumb_hash,
            'hash'       => $a->hash,
            'size'       => $a->size,
        ] = $row;

        return $a;
    }

    public function fetchAsset(int $id): ?Asset
    {
        $q = $this->pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $q->execute([$id]);

        $a = $q->fetch();
        if ($a === false) {
            error_log(__FUNCTION__.": asset $id does not exist");
            return null;
        }

        return $this->mapAsset($a);
    }

    public function deleteThumbnail(int $asset_id)
    {
        $a = $this->fetchAsset($asset_id);
        if (!$a->thumb_url) {
            return true;
        }

        $q = $this->pdo->prepare(
            "UPDATE assets
             SET thumb_url = NULL, thumb_hash = NULL
             WHERE id = ?");
        $q->execute([
            $asset_id
        ]);

        unlink($this->toPath($a->thumb_url));
    }

    public function generateThumbnail(int $asset_id)
    {
        $a = $this->fetchAsset($asset_id);
        if (!$a) return;

        if ($a->thumb_url) {
            error_log("Thumb already exists for asset {$a->id}, skipping");
            return;
        }

        if ($a->type != 'video' && $a->type != 'image') {
            error_log(__FUNCTION__.": asset {$a->id} is not an image/video, skipping");
            return;
        }

        $path = $this->toPath($a->url);

        $is_avi = str_starts_with($a->url, 'assets/avi'); // hack
        $w = $is_avi ? 256 : 800;
        $h = $is_avi ? 256 : 600;
        
        $thumb_path = $this->base_dir . $this->thumb_dir . $asset_id;

        $thumb = $this->conv->makeThumbnail(
            $path, $thumb_path, $w, $h, 25*1024, $a->type != 'image');

        if ($thumb === null) {
            return;
        }

        $q = $this->pdo->prepare(
            "UPDATE assets
             SET thumb_url = ?
             WHERE id = ?");
        $q->execute([
            $this->toUrl($thumb),
            $asset_id
        ]);
    }

    public function generateThumbnails()
    {
        $q = $this->pdo->query('SELECT id FROM assets');

        foreach ($q->fetchAll() as $a) {
            $this->deleteThumbnail($a['id']);
            $this->generateThumbnail($a['id']);
        }
    }

    public function optimizeAsset(int $asset_id)
    {
        $a = $this->fetchAsset($asset_id);

        $this->conv->optimize($this->toPath($a->url));
    }

    public function optimizeAssets()
    {
        $q = $this->pdo->query('SELECT id FROM assets');

        foreach ($q->fetchAll() as $a) {
            $this->optimizeAsset($a['id']);
        }
    }
}
