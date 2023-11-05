# Setting up an instance

## Prerequisites

Rolka expects a reasonably new version of PHP (8.x should suffice) and a MySQL-compatible database. Composer is used to manage dependencies. Nginx is expected for asset redirects (via `public/assets.php`) to work.

On Arch Linux:
```sh
sudo pacman -S php php-fpm composer mariadb nginx
```

Conversion script (located in `conv-scripts/`) requires Python and Pipenv, but is not required for the website to function.

```sh
sudo pacman -S python python-pipenv
```

Asset conversion relies on several external utilities, most of which are available in Arch repository with the exception of [mozjpeg](https://github.com/mozilla/mozjpeg), which needs to be compiled from source.

```sh
sudo pacman -S ffmpeg oxipng pngquant gifsicle
```

Clone the repository and set up dependencies:

```sh
git clone htps://github.com/wermipls/rolka
cd rolka
composer install
```

## Database setup

See [ArchWiki](https://wiki.archlinux.org/title/MariaDB) on how to set up and configure MariaDB.

At the current moment, necessary tables need to be created manually as database structure is not fully set in stone. Table create statements are provided below.

Once per database:

```sql
CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` varchar(1024) NOT NULL,
  `og_name` varchar(1024) DEFAULT NULL,
  `type` enum('image','video','audio','file') DEFAULT NULL,
  `size` int(11) NOT NULL,
  `hash` varchar(64) NOT NULL COMMENT 'xxh128 seed=0 hexadecimal',
  `thumb_url` varchar(1024) DEFAULT NULL,
  `thumb_hash` varchar(64) DEFAULT NULL COMMENT 'xxh128 seed=0 hexadecimal',
  `optimized` tinyint(1) DEFAULT 0,
  `og_url` varchar(1024) DEFAULT NULL,
  `og_hash` varchar(64) DEFAULT NULL COMMENT 'xxh128 seed=0 hexadecimal',
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `attachments_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`),
  CONSTRAINT `attachments_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `attachment_groups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attachment_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `embeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `url` text DEFAULT NULL,
  `type` enum('link','video','image','gifv') NOT NULL,
  `color` varchar(128) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `footer` text DEFAULT NULL,
  `footer_url` text DEFAULT NULL,
  `provider` text DEFAULT NULL,
  `provider_url` text DEFAULT NULL,
  `author` text DEFAULT NULL,
  `author_url` text DEFAULT NULL,
  `title` text DEFAULT NULL,
  `title_url` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `embed_url` text DEFAULT NULL,
  `asset_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `embeds_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `embed_groups` (`id`),
  CONSTRAINT `embeds_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `embed_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authors` (
  `id` bigint(20) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `discriminator` varchar(4) DEFAULT NULL,
  `display_name` varchar(100) NOT NULL,
  `avatar_asset` int(11) DEFAULT NULL,
  `type` enum('user','bot','webhook') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `avatar_asset` (`avatar_asset`),
  CONSTRAINT `authors_ibfk_1` FOREIGN KEY (`avatar_asset`) REFERENCES `assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` text NOT NULL,
  `viewable` tinyint(1) NOT NULL,
  `sync_channel_id` bigint(20) DEFAULT NULL,
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Once per channel (edit the infix and `channels` row values):

```sql
SET @channel_infix = 'mychannel';

SET @table = CONCAT('ch_', @channel_infix, '_messages');
SET @query = CONCAT(
'CREATE TABLE ', @table, ' (
`id` bigint(20) NOT NULL,
`author_id` bigint(20) NOT NULL,
`sent` datetime NOT NULL,
`modified` datetime DEFAULT NULL,
`replies_to` bigint(20) DEFAULT NULL,
`content` text DEFAULT NULL,
`sticker` bigint(20) DEFAULT NULL,
`attachment_group` int(11) DEFAULT NULL,
`embed_group` int(11) DEFAULT NULL,
`deleted` tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY (`id`),
KEY `fk_author` (`author_id`),
KEY `attachment_group` (`attachment_group`),
KEY `embed_group` (`embed_group`),
CONSTRAINT ', 'ch_', @channel_infix, '_messages_ibfk_1', ' FOREIGN KEY (`attachment_group`) REFERENCES `attachment_groups` (`id`),
CONSTRAINT ', 'ch_', @channel_infix, '_messages_ibfk_2', ' FOREIGN KEY (`embed_group`) REFERENCES `embed_groups` (`id`),
CONSTRAINT ', 'ch_', @channel_infix, '_messages_ibfk_3', ' FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
');
PREPARE query FROM @query;
EXECUTE query;
DEALLOCATE PREPARE query;

INSERT INTO `channels` (
  `table_name`,
  `name`,
  `description`,
  `viewable`,
  `sync_channel_id`
) VALUES (
  @channel_infix,
  'my channel name',
  'a place for cool people to hang out :)',
  1,
  1234567891234567890 -- discord channel ID or NULL if not synced by bot
);
```

## Configuration file

Create `config.php` in the root:

```php
<?php

return array(
    // database credentials
    'db_host' => 'localhost',
    'db_user' => 'user',
    'db_pass' => 'password',
    'db_name' => 'my_database',

    // bot token
    'discord_token' => `token here`),

    // signing key for asset URLs
    'asset_key' => 'deadbeef',
    // absolute path of what would be /assets/ on the www server
    'asset_path' => '/srv/assets/',

    // required for asset conversion/optimization
    'mozjpeg_cjpeg' =>    '/path/to/binary/cjpeg',
    'mozjpeg_jpegtran' => '/path/to/binary/jpegtran',
);
```

Example config above may not be exhaustive, check the source code (e.g. try `grep -P "config\[(.*?)\]" public/* src/* ./*.php`) for any other possible config entries.

## Gathering data

### Discord bot (recommended)

Make sure the bot is present in respective server and a correct `sync_channel_id` is provided in the `channels` table. The bot will fetch message history on startup (will likely take a while with a huge channel).

```php
php discord_bot.php
```

### Conversion script

A Python conversion script is provided, which operates on DiscordChatExporter full HTML dumps (i.e. including asset data). The script was tested on exports generated with a decently old version (from around April 2023) and may or may not function correctly on newer dumps. The script is *little* hacky and is only meant to be ran once per file to avoid polluting the database with unused rows etc.

This is not a recommended method to set up channel data, due to various discrepancies, unhandled edge cases and data loss resulting from the conversion (in retrospect, it probably would have been a better idea to use JSON export as a base instead). It exists as a last resort for channels that aren't online anymore. It's suggested to let the bot fetch the message history instead, if possible.

```sh
cd conv-scripts
pipenv install
pipenv shell

python ./deco.py input.html -host db_host -u db_user -p db_pass -db db_name -t ch_yourchannelname_messages -assetdir /srv/assets/
```

## Web server

Some PHP extensions may need to be enabled, in particular `pdo_mysql`. This can be done by editing `php.ini`. At this point the website should be possible to access by simply running the built-in development server (assets will not work without e.g. an appropriate symlink in `public`):

```sh
php -S localhost:8080 -t public/
```

For something slightly more production-ready, nginx is suggested. More detailed information on setting up a basic www server with PHP-FPM and nginx can be found on e.g. [ArchWiki](https://wiki.archlinux.org/title/Nginx#FastCGI).

Example nginx server configuration, handling asset redirects:

```nginx
server {
    listen 80;

    root /srv/rolka/public;

    # handle fancy asset url
    rewrite ^/assets/(.*)$ /assets.php?id=$1 last;

    location / {
        index index.php;
    }

    location ~ \.php$ {
        # default fastcgi_params
        include fastcgi_params;

        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO       $fastcgi_path_info;

        # fastcgi settings
        fastcgi_pass			unix:/run/php-fpm/php-fpm.sock;
        fastcgi_index			index.php;
        fastcgi_buffers			8 16k;
        fastcgi_buffer_size		32k;
    }

    location /_assets/ {
        alias /srv/assets/;
        internal;
    }
}
```
