<?php

mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");

require __DIR__ . '/vendor/autoload.php';

$config = include(__DIR__ . "/config.php");

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

$conv = new rolka\MediaConverter(
    $config['mozjpeg_cjpeg'],
    $config['mozjpeg_jpegtran']
);
$am = new rolka\AssetManager($db, $config['asset_path'], $conv);

$am->optimizeAssets();
$am->generateThumbnails();

