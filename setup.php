<?php

require __DIR__ . '/vendor/autoload.php';

use rolka\ArgParseException;
use rolka\ArgParser;
use rolka\ArgType;

function check_subcommands($sc, $param)
{
    if ($param == 'help') {
        error_log("Available subcommands:\n");
        foreach ($sc as $command => $description) {
            error_log("* {$command} - {$description}");
        }
        exit(0);
    }
    if (!isset($sc[$param])) {
        throw new ArgParseException(
            "'{$param}' is not a valid subcommand. Possible options: "
            . implode(', ', array_keys($sc))
        );
    }
}

function is_config_valid(Array $config): bool
{
    $mandatory_fields = [
        'db_host',
        'db_user',
        'db_pass',
        'db_name',
        'discord_token',
        'asset_key',
        'asset_path',
        'mozjpeg_cjpeg',
        'mozjpeg_jpegtran'
    ];

    $valid = true;

    foreach ($mandatory_fields as $f) {
        if (!isset($config[$f])) {
            error_log("missing config field: '{$f}'");
            $valid = false;
        }
    }

    return $valid;
}

function setup_db(PDO $db, bool $ignore_exists = true)
{
    $tables = [
        'assets',
        'attachments',
        'attachment_groups',
        'embeds',
        'embed_groups',
        'channels'
    ];
    $if_not_exists = $ignore_exists ? 'IF NOT EXISTS' : '';
    foreach ($tables as $table) {
        $db->query(
           "CREATE TABLE {$if_not_exists} `{$table}`
            (`id` bigint(20) NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    $db->query(
       "ALTER TABLE `assets`
            ADD IF NOT EXISTS `url` varchar(1024) NOT NULL,
            ADD IF NOT EXISTS `og_name` varchar(1024) DEFAULT NULL,
            ADD IF NOT EXISTS `type` enum('image','video','audio','file') DEFAULT NULL,
            ADD IF NOT EXISTS `size` bigint(20) NOT NULL,
            ADD IF NOT EXISTS `hash` varchar(64) NOT NULL,
            ADD IF NOT EXISTS `thumb_url` varchar(1024) DEFAULT NULL,
            ADD IF NOT EXISTS `thumb_hash` varchar(64) DEFAULT NULL,
            ADD IF NOT EXISTS `optimized` tinyint(1) DEFAULT 0,
            ADD IF NOT EXISTS `og_url` varchar(1024) DEFAULT NULL,
            ADD IF NOT EXISTS `og_hash` varchar(64) DEFAULT NULL,
            ADD IF NOT EXISTS `origin_url` text DEFAULT NULL,
            ADD PRIMARY KEY IF NOT EXISTS (`id`)"
    );

    $db->query(
       "ALTER TABLE `attachments`
            ADD IF NOT EXISTS `group_id` bigint(20) NOT NULL,
            ADD IF NOT EXISTS `asset_id` bigint(20) NOT NULL,
            ADD PRIMARY KEY IF NOT EXISTS (`id`),
            ADD KEY IF NOT EXISTS `asset_id` (`asset_id`),
            ADD KEY IF NOT EXISTS `group_id` (`group_id`),
            ADD FOREIGN KEY IF NOT EXISTS (`asset_id`) REFERENCES `assets` (`id`),
            ADD FOREIGN KEY IF NOT EXISTS (`group_id`) REFERENCES `attachment_groups` (`id`)"
    );

    $db->query(
       "ALTER TABLE `embeds`
            ADD IF NOT EXISTS `group_id` bigint(20) NOT NULL,
            ADD IF NOT EXISTS `url` text DEFAULT NULL,
            ADD IF NOT EXISTS `type` enum('link','video','image','gifv') NOT NULL,
            ADD IF NOT EXISTS `color` varchar(128) DEFAULT NULL,
            ADD IF NOT EXISTS `timestamp` datetime DEFAULT NULL,
            ADD IF NOT EXISTS `footer` text DEFAULT NULL,
            ADD IF NOT EXISTS `footer_url` text DEFAULT NULL,
            ADD IF NOT EXISTS `provider` text DEFAULT NULL,
            ADD IF NOT EXISTS `provider_url` text DEFAULT NULL,
            ADD IF NOT EXISTS `author` text DEFAULT NULL,
            ADD IF NOT EXISTS `author_url` text DEFAULT NULL,
            ADD IF NOT EXISTS `title` text DEFAULT NULL,
            ADD IF NOT EXISTS `title_url` text DEFAULT NULL,
            ADD IF NOT EXISTS `description` text DEFAULT NULL,
            ADD IF NOT EXISTS `embed_url` text DEFAULT NULL,
            ADD IF NOT EXISTS `asset_id` bigint(20) DEFAULT NULL,
            ADD PRIMARY KEY IF NOT EXISTS (`id`),
            ADD KEY IF NOT EXISTS `asset_id` (`asset_id`),
            ADD KEY IF NOT EXISTS `group_id` (`group_id`),
            ADD FOREIGN KEY IF NOT EXISTS (`group_id`) REFERENCES `embed_groups` (`id`),
            ADD FOREIGN KEY IF NOT EXISTS (`asset_id`) REFERENCES `assets` (`id`)"
    );

    $db->query(
       "ALTER TABLE `attachment_groups`
            ADD PRIMARY KEY IF NOT EXISTS (`id`)"
    );

    $db->query(
       "ALTER TABLE `embed_groups`
             ADD PRIMARY KEY IF NOT EXISTS (`id`)"
     );

    $db->query(
        "ALTER TABLE `channels`
            ADD IF NOT EXISTS `table_name` varchar(64) NOT NULL,
            ADD IF NOT EXISTS `name` varchar(128) NOT NULL,
            ADD IF NOT EXISTS `description` text NULL,
            ADD IF NOT EXISTS `viewable` tinyint(1) NOT NULL,
            ADD IF NOT EXISTS `sync_channel_id` bigint(20) DEFAULT NULL,
            ADD PRIMARY KEY IF NOT EXISTS (`id`),
            ADD UNIQUE KEY IF NOT EXISTS (`table_name`),
            MODIFY COLUMN IF EXISTS `description` text NULL"
    );

    $db->query(
       "CREATE TABLE {$if_not_exists} `authors` (`id` bigint(20) NOT NULL)
        ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->query(
       "ALTER TABLE `authors`
            ADD IF NOT EXISTS `username` varchar(100) DEFAULT NULL,
            ADD IF NOT EXISTS `discriminator` varchar(4) DEFAULT NULL,
            ADD IF NOT EXISTS `display_name` varchar(100) NOT NULL,
            ADD IF NOT EXISTS `avatar_asset` bigint(20) DEFAULT NULL,
            ADD IF NOT EXISTS `type` enum('user','bot','webhook') NOT NULL,
            ADD PRIMARY KEY IF NOT EXISTS (`id`),
            ADD KEY IF NOT EXISTS  `avatar_asset` (`avatar_asset`),
            ADD FOREIGN KEY IF NOT EXISTS (`avatar_asset`) REFERENCES `assets` (`id`)"
     );
}

function channel_add_upgrade(
    PDO $db,
    ?string $infix,
    ?string $name = null,
    ?string $description = null,
    ?bool $viewable = true,
    ?int $sync_channel_id = null,
    bool $upgrade_existing = false
) {
    if (!$name && !$upgrade_existing) {
        error_log("error: missing channel name");
        exit(1);
    }
    if (!$infix) {
        error_log("error: missing channel infix");
        exit(1);
    }
    $alphanumeric = preg_match("/^[A-z\d]+$/", $infix);
    if (!$alphanumeric) {
        error_log("error: infix is not alphanumeric");
        exit(1);
    }

    $viewable = $viewable ? 1 : 0;

    if (!$upgrade_existing) {
        $q = $db->prepare(
           "INSERT INTO `channels`
            (
                table_name,
                name,
                description,
                viewable,
                sync_channel_id
            )
            VALUES
            (
                :table_name,
                :name,
                :description,
                :viewable,
                :sync_channel_id
            )");

        $q->bindValue('table_name',      $infix);
        $q->bindValue('name',            $name);
        $q->bindValue('description',     $description);
        $q->bindValue('viewable',        $viewable);
        $q->bindValue('sync_channel_id', $sync_channel_id);

        $q->execute();

        $db->query(
           "CREATE TABLE `ch_{$infix}_messages` (`id` bigint(20) NOT NULL)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }


    $db->query(
       "ALTER TABLE `ch_{$infix}_messages`
            ADD IF NOT EXISTS `author_id` bigint(20) NOT NULL,
            ADD IF NOT EXISTS `sent` datetime NOT NULL,
            ADD IF NOT EXISTS `modified` datetime DEFAULT NULL,
            ADD IF NOT EXISTS `replies_to` bigint(20) DEFAULT NULL,
            ADD IF NOT EXISTS `content` text DEFAULT NULL,
            ADD IF NOT EXISTS `sticker` bigint(20) DEFAULT NULL,
            ADD IF NOT EXISTS `attachment_group` bigint(20) DEFAULT NULL,
            ADD IF NOT EXISTS `embed_group` bigint(20) DEFAULT NULL,
            ADD IF NOT EXISTS `deleted` tinyint(1) NOT NULL DEFAULT 0,
            ADD IF NOT EXISTS `webhook_name` tinytext DEFAULT NULL,
            ADD IF NOT EXISTS `webhook_avatar` bigint(20) DEFAULT NULL,
            ADD PRIMARY KEY IF NOT EXISTS (`id`),
            ADD KEY IF NOT EXISTS `author_id` (`author_id`),
            ADD KEY IF NOT EXISTS `attachment_group` (`attachment_group`),
            ADD KEY IF NOT EXISTS `embed_group` (`embed_group`),
            ADD KEY IF NOT EXISTS `webhook_avatar` (`webhook_avatar`),
            ADD FOREIGN KEY IF NOT EXISTS (`attachment_group`) REFERENCES `attachment_groups` (`id`),
            ADD FOREIGN KEY IF NOT EXISTS (`embed_group`) REFERENCES `embed_groups` (`id`),
            ADD FOREIGN KEY IF NOT EXISTS (`author_id`) REFERENCES `authors` (`id`),
            ADD FOREIGN KEY IF NOT EXISTS (`webhook_avatar`) REFERENCES `assets` (`id`)"
    );
}

function upgrade_db(PDO $db)
{
    setup_db($db, true);

    $channels = $db->query("SELECT * FROM `channels`");
    foreach ($channels as $c) {
        channel_add_upgrade($db, $c['table_name'], upgrade_existing: true);
    }
}

$subcommands = [
    'help' => 'displays this help page',
    'init' => 'initialize the database schema',
    'upgrade' => 'upgrade the database schema',
    'channel' => 'manage channels'
];

$channel_ops = [
    'help' => 'displays this help page',
    'add' => 'adds a channel. ID will be assigned automatically',
];

$parser = new ArgParser();
$parser->addArg(
    'subcommand',
    default: 'help',
    optional: true,
    callback: function ($param) use ($subcommands) {
        check_subcommands($subcommands, $param);
    }
);

$p_channel = new ArgParser();
$p_channel->addArg(
    'subcommand',
    default: 'help',
    optional: true,
    callback: function ($param) use ($channel_ops) {
        check_subcommands($channel_ops, $param);
    }
);
$p_channel->addArg('-id', ArgType::Int);
$p_channel->addArg('-infix');
$p_channel->addArg('-name');
$p_channel->addArg('-description');
$p_channel->addArg('-viewable', ArgType::Bool, 'true');
$p_channel->addArg('-sync_channel', ArgType::Int);

$main_args = $parser->parse(array_slice($argv, 0, 2));

$config = include(__DIR__ . "/config.php");
if (!is_config_valid($config)) {
    exit(2);
}

$db_opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

$db = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'], $db_opts);

switch ($main_args['subcommand'])
{
case 'init':
    setup_db($db, false);
    break;
case 'upgrade':
    upgrade_db($db);
    break;
case 'channel':
    $ch_args = $p_channel->parse(array_slice($argv, 1));
    match ($ch_args['subcommand']) {
        'add' =>
            channel_add_upgrade(
                $db,
                $ch_args['-infix'],
                $ch_args['-name'],
                $ch_args['-description'],
                $ch_args['-viewable'],
                $ch_args['-sync_channel'],
            ),
    };
}
