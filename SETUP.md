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
git clone https://github.com/wermipls/rolka
cd rolka
composer install
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
    'discord_token' => 'token here',

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

## Database setup

See [ArchWiki](https://wiki.archlinux.org/title/MariaDB) on how to set up and configure MariaDB, if not done already. Make sure the config file is filled with appropriate data.

To handle database table initialization, a simple script is provided:

```sh
# once per db
php setup.php init
# for each channel
php setup.php channel add \
    -infix mychannel \
    -name "my channel name" \
    -description "a place for cool people to hang out :)" \
    -sync_channel 1234567891234567890
```

To migrate from an older database schema (add missing columns, etc):

```sh
php setup.php upgrade
```

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
