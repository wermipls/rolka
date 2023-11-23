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
        $this->inlineMarkerList .= '|';

        $this->BlockTypes = array(
            '#' => array('Header'),
            '`' => array('FencedCode'),
        );

        $this->EmRegex['*'] = '/^[*]\b((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s';
    }

    protected function lines(array $lines)
    {
        $cur_blk = null;

        foreach ($lines as $line) {
            if (strpos($line, "\t") !== false) {
                $parts = explode("\t", $line);

                $line = $parts[0];

                unset($parts[0]);

                foreach ($parts as $part) {
                    $shortage = 4 - mb_strlen($line, 'utf-8') % 4;

                    $line .= str_repeat(' ', $shortage);
                    $line .= $part;
                }
            }

            $indent = 0;

            while (isset($line[$indent]) and $line[$indent] === ' ') {
                $indent++;
            }

            $text = $line;

            $Line = array('body' => $line, 'indent' => $indent, 'text' => $text);

            if (isset($cur_blk['continuable'])) {
                $block = $this->{'block' . $cur_blk['type'] . 'Continue'}($Line, $cur_blk);

                if (isset($block)) {
                    $cur_blk = $block;

                    continue;
                } else {
                    if ($this->isBlockCompletable($cur_blk['type'])) {
                        $cur_blk = $this->{'block' . $cur_blk['type'] . 'Complete'}($cur_blk);
                    }
                }
            }

            $marker = $text[0];

            $blk_types = $this->unmarkedBlockTypes;

            if (isset($this->BlockTypes[$marker])) {
                foreach ($this->BlockTypes[$marker] as $type) {
                    $blk_types[] = $type;
                }
            }

            foreach ($blk_types as $type) {
                $block = $this->{'block' . $type}($Line, $cur_blk);

                if (isset($block)) {
                    $block['type'] = $type;

                    if (!isset($block['identified'])) {
                        $blocks[] = $cur_blk;

                        $block['identified'] = true;
                    }

                    if ($this->isBlockContinuable($type)) {
                        $block['continuable'] = true;
                    }

                    $cur_blk = $block;

                    continue 2;
                }
            }

            if (isset($cur_blk)
                and !isset($cur_blk['type'])
                and !isset($cur_blk['interrupted'])
            ) {
                $cur_blk['element']['text'] .= "\n" . $text;
            } else {
                $blocks[] = $cur_blk;

                $cur_blk = $this->paragraph($Line);

                $cur_blk['identified'] = true;
            }
        }

        if (isset($cur_blk['continuable'])
            and $this->isBlockCompletable($cur_blk['type'])
        ) {
            $cur_blk = $this->{'block' . $cur_blk['type'] . 'Complete'}($cur_blk);
        }

        $blocks[] = $cur_blk;

        unset($blocks[0]);

        $markup = '';

        foreach ($blocks as $block) {
            if (isset($block['hidden'])) {
                continue;
            }

            $markup .= "\n";
            $markup .= isset($block['markup']) ? $block['markup']
                                               : $this->element($block['element']);
        }

        $markup .= "\n";

        return $markup;
    }

    protected function unmarkedText($text)
    {
        if ($this->breaksEnabled) {
            $text = preg_replace('/[ ]*\n/', "<br />\n", $text);
        } else {
            $text = str_replace(" \n", "\n", $text);
        }

        return $text;
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
