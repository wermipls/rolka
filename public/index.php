<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
:root {
    --color-bg: #000000;
    --color-fg: #ffffff;
    --color-bg-secondary: #202020;
    --color-fg-secondary: #808080;
    --color-accent: #ff80d0;
}

@media (prefers-color-scheme: light) {
  :root {
    --color-bg: #ffffff;
    --color-fg: #000000;
    --color-bg-secondary: #f2f2f2;
    --color-fg-secondary: #606060;
    --color-accent: #e000a0;
  }
}

* {
    box-sizing: border-box;
}

body {
    max-width: 800px;
    padding: 12px;
    margin: auto;
    align-items: center;
    font-family: Helvetica, sans-serif;
    font-size: 1em;
    background-color: var(--color-bg);
    color: var(--color-fg);
    line-height: 1.2;
}


a, a:hover, a:visited, a:active {
  color: var(--color-accent);
}

.msg_avi {
    object-fit: cover;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    overflow: hidden;
    display: block;
    margin-left: auto;
    margin-top: 12px;
    margin-bottom: 12px;
    background-color: var(--color-bg-secondary);
}

.msg_header {
    grid-column: 2;
    min-width: 0;
    padding-top: 12px;
    margin-top: auto;
    margin-bottom: 12px;
}

.msg_side {
    grid-column: 1;
    width: 40px;
    margin-right: 12px;
}

.msg_user {
    font-weight: bold;
    font-size: 1.2em;
}

.msg_primary {
    grid-column: 2;
}

.msg_content {
    overflow-wrap: anywhere; 
    min-width: 0;
}

.msg_element {
    margin-bottom: 5px;
}

.msg_date {
    display: inline-block;
    margin-left: auto;
    color: var(--color-fg-secondary);
}

.msg_time {
    text-align: right;
    color: var(--color-fg-secondary);
    margin: auto;
}

.msg_sticker {
    height: 192px;
    max-width: 192px;
    object-fit: contain;
    border-radius: 8px;
    background-color: var(--color-bg-secondary);
}

.msg_attachment {
    display: inline-block;
    max-height: 350px;
    max-width: 100%;
    border-radius: 8px;
    background-color: var(--color-bg-secondary);
    margin-right: 8px;
    margin-bottom: 8px;
}

.msg_reply {
    color: var(--color-fg-secondary);
}

.msg_reply_user {
    font-weight: bold;
    color: var(--color-fg);
}

.msg_reply_ref {
    color: inherit;
    text-decoration: none;
}

.msg_reply_content {
    display: inline-block;
    margin-top: 12px;
    margin-right: auto;
    padding: 0px 12px;
    min-width: 0;
    overflow-wrap: anywhere;
    color: color-mix(in lch, var(--color-bg) 25%, var(--color-fg));
    font-style: italic;
}

.msg {
    display: grid;
    grid-template-columns: auto 1fr;
    direction: ltr;
}

.msg_ping {
    display: inline-block;
    background-color: var(--color-bg-secondary);
    border-radius: 5px;
    padding: 0 2px;
    font-weight: bold;
    color: var(--color-accent);
}

.msg_emote {
    height: 1.5rem;
    width: 1.5rem;
    object-fit: contain;
    vertical-align: top;
}

.msg_date_header {
    overflow: hidden;
    text-align: center;
    margin-top: 24px;
    margin-bottom: 12px;
    color: color-mix(in lch, var(--color-bg) 25%, var(--color-fg));
}

.msg_date_header:before,
.msg_date_header:after {
    background-color: var(--color-bg-secondary);
    content: "";
    display: inline-block;
    height: 3px;
    position: relative;
    vertical-align: middle;
    width: 50%;
}

.msg_date_header:before {
    right: 1em;
    margin-left: -50%;
}

.msg_date_header:after {
    left: 1em;
    margin-right: -50%;
}

a.msg_file {
    display: inline-block;
    padding: 12px;
    font-weight: bold;
}

p {
    display: block;
    margin: 0;
}
</style>
</head>
<body>
<?php
mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");

$start = microtime(true);

require __DIR__ . '/../vendor/autoload.php';

class DiscordParser extends Parsedown
{
    private $author_names;

    function __construct($author_names)
    {
        $this->author_names = $author_names;

        $this->InlineTypes['<'] = ['Mention', 'Emoticon', 'SpecialCharacter'];

        $this->inlineMarkerList .= '<';
    }

    protected function inlineMention($excerpt)
    {
        if (preg_match('/^<@(\d+)>/', $excerpt['text'], $matches)) {
            $name = $this->author_names[$matches[1]] ?? 'Unknown User';
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'span',
                    'text' => "@" . $name,
                    'attributes' => array(
                        'class' => 'msg_ping',
                    ),
                ),
            );
        }
    }

    protected function inlineEmoticon($excerpt)
    {
        if (preg_match('/^<:([\w]+):([\w ]+)>/', $excerpt['text'], $matches)) {
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'img',
                    'attributes' => array(
                        'loading' => 'lazy',
                        'alt' => $matches[1],
                        'title' => $matches[1],
                        'src' => 'https://cdn.discordapp.com/emojis/' . $matches[2],
                        'class' => 'msg_emote',
                    ),
                ),
            );
        }
    }

    protected $safeLinksWhitelist = array(
        'http://',
        'https://',
    );

    protected function inlineEmailTag($Excerpt) { return; }
    protected function inlineImage($Excerpt) { return; }
    protected function inlineUrlTag($Excerpt) { return; }
}

function fetch_authors(PDO $db)
{
    $q = $db->query("SELECT uid, name FROM tp_authors");
    $f = $q->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_UNIQUE);
    return $f;
}

$config = include("../config.php");

$db = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$authors_by_id = fetch_authors($db);

$parsedown = new DiscordParser($authors_by_id);
$parsedown->setSafeMode(true);
$parsedown->setBreaksEnabled(true);

$select = $db->prepare(
    "SELECT ch.uid, author_id, date_sent, replies_to, content, sticker, attachment, tp_authors.name, tp_authors.avatar_url
     FROM {$config['channel_table']} ch
     INNER JOIN tp_authors
     ON ch.author_id = tp_authors.uid
     ORDER BY ch.uid ASC;");
$select->execute();

$last_uid = 0;
$last_date = NULL;

while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
    $msg_id = $row['uid'];
    $user = $row['name'];
    $content = $row['content'];
    $sticker = $row['sticker'];
    $avi_url = $row['avatar_url'];
    $attach_id = $row['attachment'];
    $reply_id = $row['replies_to'];
    $datetime = new DateTimeImmutable($row['date_sent']);
    if ($last_date === NULL) {
        $last_date = $datetime;
    }
    $date_delta = $datetime->diff($last_date);
    $print_date_header = ($datetime->setTime(0, 0)->diff($last_date->setTime(0, 0))->d > 0);
    $last_date = $datetime;

    $show_author = false;
    if ($last_uid != $row['author_id']) {
        $last_uid = $row['author_id'];
        $show_author = true;
    }
    if ($reply_id) {
        $show_author = true;
    }

    if ($date_delta->i > 10) {
        $show_author = true;
    }

    if ($print_date_header) {
        $show_author = true;
        $dh = $datetime->format('l, j F Y');
        echo "<div class='msg_date_header'>$dh</div>";
    }

    echo "<div class='msg' id='$msg_id'>";
    if ($show_author) {
        echo "<div class='msg_side'><img class='msg_avi' src='$avi_url'></img></div>";
        echo "<div class='msg_header'><span class='msg_user'>$user </span>";
        $dts = "<span class='msg_date'>on {$datetime->format('d.m.Y')}</span>";

        if ($reply_id) {
            $reply_to = $db->query(
                "SELECT ch.content, a.name FROM {$config['channel_table']} ch
                 INNER JOIN tp_authors a
                 ON ch.author_id = a.uid
                 WHERE ch.uid = $reply_id");
            $reply_to = $reply_to->fetch();
            $reply_content = $reply_to['content'];
            $reply_max = 120;
            if (mb_strlen($reply_content) > $reply_max) {
                $reply_content = mb_substr($reply_content, 0, $reply_max) . "...";
            }
            echo "<span class='msg_reply'> in response to </span><a class='msg_reply_ref' href='#$reply_id'><span class='msg_reply_user'>{$reply_to['name']} </span>";
            echo $dts;
            echo "<br><span class='msg_reply_content'>$reply_content</span></a>";
        } else {
            echo $dts;
        }
        echo "</div>";
    }
    $time = $datetime->format('H:i');
    echo "<div class='msg_side'><div class='msg_time'>$time</div></div>";

    echo "<div class='msg_primary'>";
    if ($content) {
        $content = $parsedown->line($content);
        echo "<div class='msg_content msg_element'>$content</div>";
    }
    if ($attach_id) {
        foreach ($db->query(
            "SELECT type, url FROM tp_attachments WHERE id = $attach_id")
            as $att) {
                $url = htmlspecialchars($att['url']);
                switch ($att['type']) {
                case 'image':
                    echo "<a href='$url' target='_blank'><img loading='lazy' class='msg_attachment msg_element' src='$url'></img></a>";
                    break;
                case 'video':
                    echo "<video class='msg_attachment msg_element' src='$url' controls></video>";
                    break;
                case 'audio':
                    echo "<audio class='msg_attachment msg_element' src='$url' controls></audio>";
                    break;
                case 'file':
                    preg_match('/^(?:.*\/)?(.+)$/', $url, $matches);
                    $fname = $matches[1];
                    echo "<div class='msg_attachment msg_element'><a class='msg_file' href='$url'>File: $fname</a></div>";
                    break;
                }
            }
    }
    if ($sticker) {
        echo "<img loading='lazy' class='msg_sticker msg_element' src='https://media.discordapp.net/stickers/{$sticker}?size=256'></img>";
    }
    echo "</div>";
    echo "</div>";
}
?>
</body>
</html>