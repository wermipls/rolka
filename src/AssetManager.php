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
            'thumb_hash' => $a->thumb_hash,
            'id'         => $a->id,
            'hash'       => $a->hash,
            'size'       => $a->size,
            'optimized'  => $a->is_optimized,
            'og_url'     => $a->original_url,
            'og_hash'    => $a->original_hash,
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

    public function generateThumbnails(bool $force = false)
    {
        $q = $this->pdo->query('SELECT id FROM assets');

        foreach ($q->fetchAll() as $a) {
            if ($force) {
                $this->deleteThumbnail($a['id']);
            }
            $this->generateThumbnail($a['id']);
        }
    }

    public function optimizeAsset(int $asset_id)
    {
        $a = $this->fetchAsset($asset_id);

        if ($a->is_optimized) {
            error_log(__FUNCTION__.": asset {$asset_id} already optimized");
            return;
        }

        $path = $this->toPath($a->url);
        $path_og = $path . '.bak';

        if (file_exists($path_og)) {
            error_log(
                __FUNCTION__.": backup '{$path_og}' already exists... aborting");
            return;
        }

        $ok = copy($path, $path_og);
        if (!$ok) {
            error_log(__FUNCTION__.": failed to back up file '{$path}'");
            return;
        }
            
        $ok = $this->conv->optimize($path);
        if (!$ok) {
            error_log(__FUNCTION__.": failed to optimize '{$path}'");
            rename($path_og, $path);
            return;
        }

        $new_hash = hash_file('xxh128', $path);
        $new_size = filesize($path);

        $q = $this->pdo->prepare(
            "UPDATE assets
             SET
                optimized = TRUE,
                size = :size,
                hash = :hash,
                og_url = :og_url,
                og_hash = :og_hash
             WHERE id = :id"
        );

        $q->bindValue('og_url', $this->toUrl($path_og));
        $q->bindParam('og_hash', $a->hash);
        $q->bindParam('hash', $new_hash);
        $q->bindValue('size', $new_size, \PDO::PARAM_INT);
        $q->bindValue('id', $asset_id, \PDO::PARAM_INT);

        $ok = $q->execute();
        if (!$ok) {
            error_log(
                __FUNCTION__.": failed to update asset {$asset_id} in database...");
            rename($path_og, $path);
        }
    }

    public function optimizeAssets()
    {
        $q = $this->pdo->query('SELECT id FROM assets');

        foreach ($q->fetchAll() as $a) {
            $this->optimizeAsset($a['id']);
        }
    }

    public function unoptimizeAsset(int $asset_id)
    {
        $a = $this->fetchAsset($asset_id);

        if (!$a->is_optimized) {
            error_log(
                __FUNCTION__.": asset {$asset_id} already unoptimized...");
            return;
        }

        $path_og = $this->toPath($a->original_url);
        $path    = $this->toPath($a->url);

        if (!file_exists($path_og)) {
            error_log(
                __FUNCTION__.": backup '{$path_og}' does not exist...");
            return;
        }

        if (!rename($path_og, $path)) {
            error_log(
                __FUNCTION__.": failed to restore backup '{$path_og}'...");
            return;
        }
        
        clearstatcache();
        $size = filesize($path);

        $q = $this->pdo->prepare(
            "UPDATE assets
             SET
                optimized = FALSE,
                size = :size,
                hash = :hash,
                og_url = NULL,
                og_hash = NULL
             WHERE id = :id"
        );

        $q->bindParam('hash', $a->original_hash);
        $q->bindValue('size', $size, \PDO::PARAM_INT);
        $q->bindValue('id', $asset_id, \PDO::PARAM_INT);

        $ok = $q->execute();
        if (!$ok) {
            error_log(
                __FUNCTION__.": failed to update asset {$asset_id} in database...");
            rename($path_og, $path);
        }
    }

    public function unoptimizeAssets()
    {
        $q = $this->pdo->query('SELECT id FROM assets');

        foreach ($q->fetchAll() as $a) {
            $this->unoptimizeAsset($a['id']);
        }
    }

    public function getAssetByHash(string $hash): ?Asset
    {
        $q = $this->pdo->prepare(
           "SELECT * FROM assets
            WHERE
                hash = :hash
                OR thumb_hash = :hash
                OR og_hash = :hash");

        $q->bindValue('hash', $hash);
        $q->execute();

        foreach ($q->fetchAll() as $row) {
            return $this->mapAsset($row);
        }

        return null;
    }

    private function insertAsset(Asset $a): ?Asset
    {
        $q = $this->pdo->prepare(
           "INSERT INTO assets
            (
                url,
                og_name,
                type,
                size,
                hash,
                thumb_url,
                thumb_hash,
                optimized,
                og_url,
                og_hash
            )
            VALUES
            (
                :url,
                :og_name,
                :type,
                :size,
                :hash,
                :thumb_url,
                :thumb_hash,
                :optimized,
                :og_url,
                :og_hash
            )");

        $q->bindValue('url',        $a->url);
        $q->bindValue('thumb_url',  $a->thumb_url);
        $q->bindValue('og_url',     $a->original_url);
        $q->bindValue('hash',       $a->hash);
        $q->bindValue('thumb_hash', $a->thumb_hash);
        $q->bindValue('og_hash',    $a->original_hash);
        $q->bindValue('type',       $a->type);
        $q->bindValue('optimized',  (int)$a->is_optimized);
        $q->bindValue('size',       $a->size);
        $q->bindValue('og_name',    $a->name);

        if (!$q->execute()) {
            return null;
        }

        $a->id = $this->pdo->lastInsertId();
        return $a;
    }

    public function downloadAsset(string $url): ?Asset
    {
        if (!preg_match("/^[Hh][Tt][Tt][Pp][Ss]?:\/\//", $url)) {
            return null;
        }
        $f = fopen($url, "rb");
        if (!$f) {
            return null;
        }

        $dir = $this->base_dir . dechex(time()) . hash('xxh128', $url) . '/';
        mkdir($dir, recursive: true);

        $match = preg_match("/^.*\/(.+?)(?:[\?\&].*)?$/", $url, $matches);
        $name = $match ? $matches[1] : 'file';

        $fp = $dir . $name;

        if (file_exists($fp)) {
            fclose($f);
            return null;
        }
        file_put_contents($fp, $f);

        fclose($f);

        $hash = hash_file('xxh128', $fp);
        $asset = $this->getAssetByHash($hash);
        if ($asset) {
            unlink($fp);
            return $asset;
        }

        $asset = new Asset(-1, 'image', $this->toUrl($fp), null);

        $asset->hash = $hash;
        $asset->size = filesize($fp);
        $asset->name = $name;

        return $this->insertAsset($asset);
    }
}
