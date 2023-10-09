<?php

require __DIR__ . '/../vendor/autoload.php';

use rolka\SignedStamp;

$path = $_GET['id'] ?? '';
$ts = filter_input(INPUT_GET, 'ts', FILTER_VALIDATE_INT) ?? 0;
$sig = $_GET['k'] ?? '';

$ts = SignedStamp::withHash($ts, 'assets/'.$path, $sig);

$cfg = include(__DIR__ . '/../config.php');

if ($ts->validate($cfg['asset_key'])) {
    header("X-Accel-Redirect: /_assets/" . $path);
    header('Content-type:');
} else {
    http_response_code(418);
}
