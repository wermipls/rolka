<?php

namespace rolka;
use Parsedown;

class Markdown extends Parsedown
{
    private Context $ctx;

    protected $safeLinksWhitelist = array(
        'http://',
        'https://',
    );

    public function __construct(Context $context)
    {
        $this->ctx = $context;

        $this->InlineTypes['<'] = [
            'Mention',
            'Channel',
            'Role',
            'Emoticon',
            'Timestamp',
            'NoEmbedLink',
            'SpecialCharacter',
        ];
        $this->InlineTypes['|'] = ['Spoiler'];
        $this->InlineTypes['#'] = ['Header'];
        $this->inlineMarkerList .= '|#';
    }

    protected function inlineHeader($excerpt)
    {
        if (preg_match('/^(##?#?) (.+)\n?/m', $excerpt['context'], $matches, PREG_OFFSET_CAPTURE)) {
            $text = $matches[2][0];
            return array(
                'extent' => strlen($matches[0][0]),
                'offset' => $matches[0][1],
                'element' => array(
                    'handler' => 'line',
                    'name' => match ($matches[1][0]) {
                        '#'   => 'h1',
                        '##'  => 'h2',
                        '###' => 'h3',
                    },
                    'text' => $text,
                ),
            );
        }
    }

    protected function inlineMention($excerpt)
    {
        if (preg_match('/^<@(\d+)>/', $excerpt['text'], $matches)) {
            $name = $this->ctx->getAuthor($matches[1])->name ?? 'Unknown User';
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

    protected function inlineChannel($excerpt)
    {
        if (preg_match('/^<#(\d+)>/', $excerpt['text'], $matches)) {
            $name = 'unknown-channel';
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'span',
                    'text' => "#" . $name,
                    'attributes' => array(
                        'class' => 'msg_ping',
                    ),
                ),
            );
        }
    }

    protected function inlineRole($excerpt)
    {
        if (preg_match('/^<@&(\d+)>/', $excerpt['text'], $matches)) {
            $name = 'Unknown Role';
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

    protected function inlineNoEmbedLink($excerpt)
    {
        if (preg_match('/^<.+?(:.+?)>/', $excerpt['text'], $matches)) {
            $excerpt['text'] = $matches[1];
            $inline = $this->inlineUrl($excerpt);
            if ($inline) {
                $inline['extent'] += 2;
                $inline['position'] -= 1;
                return $inline;
            }
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
