<?php

namespace rolka;

class MediaConverter
{
    private ExecWrapper $ffmpeg;
    private ExecWrapper $ffprobe;
    private ExecWrapper $magick;
    private ExecWrapper $cjpeg;

    public function __construct(string $cjpeg_path)
    {
        $this->ffmpeg  = new ExecWrapper('ffmpeg');
        $this->ffprobe = new ExecWrapper('ffprobe');
        $this->magick  = new ExecWrapper('magick');
        $this->cjpeg   = new ExecWrapper($cjpeg_path);
    }

    private function probeStreams(array $extra_args = []): ?object
    {
        $args = [
            '-print_format', 'json',
            '-show_streams',
        ];

        $ret = $this->ffprobe->run([...$args, ...$extra_args], $stdout);

        if ($ret === 0) {
            return json_decode($stdout);
        } else {
            return null;
        }
    }

    private function probeStream(
        array $extra_args = [], string $type = 'video'): ?object
    {
        $probe = $this->probeStreams($extra_args);

        foreach ($probe->streams as $s) {
            if ($s->codec_type == $type) {
                return $s;
            }
        }
        return null;
    }

    private function makeJpeg(
        string $input,
        string $output,
        ?int $width = null,
        ?int $height = null,
        int $quality = 4): bool
    {
        $rescale = $width || $height;

        $width  = $width  ?? -1;
        $height = $height ?? -1;

        $base_args = [
            '-frames:v', '1',
        ];

        $args = ['-i', $input, ...$base_args];
        
        if ($rescale) {
            $args = [
                ...$args,
                '-vf',
                "scale={$width}:{$height}"
                . ":force_original_aspect_ratio=decrease:flags=bilinear",
            ];
        }

        $args = [...$args, '-y', '/tmp/tmp.bmp']; // HACK

        $ret = $this->ffmpeg->run($args);
        if ($ret !== 0) {
            error_log(__FUNCTION__.": failed to run ffmpeg! exit code: {$ret}");
            return false;
        }

        $ret = $this->cjpeg->run(['-quality', $quality, '/tmp/tmp.bmp'], $stdout);
        if ($ret !== 0) {
            error_log(__FUNCTION__.": failed to mozjpeg {$output}... exit code {$ret}");
        }

        file_put_contents($output, $stdout);

        return true;
    }

    public function makeThumbnail(
        string $input,
        string $output,
        int $width,
        int $height,
        int $max_size_bytes = 50000,
        bool $force = false
    ): bool
    {
        $stream = $this->probeStream([$input], 'video');
        if (!$stream) {
            error_log(__FUNCTION__.": failed to probe video stream in '{$input}'");
            return false;
        }

        $is_big_res = ($stream->width > $width) || ($stream->height > $height);
        $og_size = filesize($input);
        $is_big_fsize = $og_size > $max_size_bytes;
        if (!$force && !$is_big_res && !$is_big_fsize) {
            error_log(__FUNCTION__.": '{$input}' smaller than threshold, skipping");
            return false;
        }

        $width  = $is_big_res ? $width  : null;
        $height = $is_big_res ? $height : null;

        $max_q = 75;
        $min_q = 40;
        $best_q = 75;
        $prev_q = 75;
        $q_size = [];
        for ($i = 10; $i > 0; $i--) {
            $ok = $this->makeJpeg($input, $output, $width, $height, $best_q);
            if (!$ok) {
                return false;
            }

            clearstatcache();
            $size = filesize($output);

            error_log("$output $i: $size (target q: {$best_q})");

            if ($prev_q > $best_q && $q_size[$prev_q] < $size) {
                error_log("previous q=$prev_q had smaller size...");
                $best_q = $prev_q;
                $i = 2;
                continue;
            }

            $q_size[$best_q] = $size;
            $prev_q = $best_q;

            if ($size > $max_size_bytes) {
                $max_q = $best_q;
                $target_q = ceil(($min_q + $best_q) / 2.0);
            } else {
                $min_q = $best_q;
                $target_q = ceil(($max_q + $best_q) / 2.0);
            }

            if ($target_q == $best_q) {
                break;
            }
            $best_q = $target_q;
        }

        if (!$force && $og_size < $size) {
            error_log(__FUNCTION__.": new thumb bigger than original, removing...");
            unlink($output);
            return false;
        }

        return true;
    }
}
