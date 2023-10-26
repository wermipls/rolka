<?php

namespace rolka;
use Parsedown;

class Markdown extends Parsedown
{
    private $author_names;

    protected $safeLinksWhitelist = array(
        'http://',
        'https://',
    );

    public function __construct($author_names)
    {
        $this->author_names = $author_names;

        $this->InlineTypes['<'] = ['Mention', 'Emoticon', 'Timestamp', 'SpecialCharacter'];
        $this->InlineTypes['|'] = ['Spoiler'];
        $this->inlineMarkerList .= '|';
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
        if (preg_match('/^<a?:([\w]+):([\w ]+)>/', $excerpt['text'], $matches)) {
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

    protected function inlineTimestamp($excerpt)
    {
        if (preg_match('/^<t:([\d]+)(:[\w])?>/', $excerpt['text'], $matches)) {
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'span',
                    'text' => (new \DateTimeImmutable('@' . $matches[1]))
                        ->format('Y-m-d H:i'),
                    'attributes' => array(
                        'class' => 'msg_timestamp'
                    ),
                ),
            );
        }
    }

    protected function inlineSpoiler($excerpt)
    {
        if (preg_match('/^\|\|(.+?)\|\|/', $excerpt['text'], $matches)) {
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'span',
                    'handler' => 'element',
                    'text' => array(
                        'handler' => 'line',
                        'name' => 'span',
                        'text' => $matches[1],
                        'attributes' => array(
                            'class' => 'msg_spoiler_content'
                        ),
                    ),
                    'attributes' => array(
                        'class' => 'msg_spoiler'
                    ),
                ),
            );
        }
    }

    protected function inlineEmailTag($Excerpt)
    {
        return;
    }

    protected function inlineImage($Excerpt)
    {
        return;
    }

    protected function inlineUrlTag($Excerpt)
    {
        return;
    }
}
