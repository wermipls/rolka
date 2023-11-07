<?php

namespace rolka;

class MediaConverter
{
    private ExecWrapper $ffmpeg;
    private ExecWrapper $ffprobe;
    private ExecWrapper $magick;
    private ExecWrapper $cjpeg;
    private ExecWrapper $pngquant;
    private ExecWrapper $oxipng;
    private ExecWrapper $jpegtran;
    private ExecWrapper $file;

    public function __construct(string $cjpeg_path, string $jpegtran_path)
    {
        $this->ffmpeg   = new ExecWrapper('ffmpeg');
        $this->ffprobe  = new ExecWrapper('ffprobe');
        $this->magick   = new ExecWrapper('magick');
        $this->pngquant = new ExecWrapper('pngquant');
        $this->oxipng   = new ExecWrapper('oxipng');
        $this->cjpeg    = new ExecWrapper($cjpeg_path);
        $this->jpegtran = new ExecWrapper($jpegtran_path);

        $this->file     = new ExecWrapper('file');
        $this->file->addArgs(['--mime-type', '-b']);
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

    private function probeFormat(array $extra_args = []): ?object
    {
        $args = [
            '-print_format', 'json',
            '-show_format',
        ];

        $ret = $this->ffprobe->run([...$args, ...$extra_args], $stdout);

        if ($ret === 0) {
            return (json_decode($stdout))->format;
        } else {
            return null;
        }
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

    private function makePng(
        string $input,
        string $output,
        ?int $width = null,
        ?int $height = null,
        bool $lossy = false): bool
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

        $args = [...$args, '-y', $output];

        $ret = $this->ffmpeg->run($args);
        if ($ret !== 0) {
            error_log(__FUNCTION__.": failed to run ffmpeg! exit code: {$ret}");
            return false;
        }

        if ($lossy === true) {
            $pq_args = [
                '--skip-if-larger',
                '--speed', '1',
                '--force',
                '-o', $output,
                $output
            ];
            $ret = $this->pngquant->run($pq_args);
            if ($ret !== 0) {
                error_log(__FUNCTION__.": failed to run pngquant! exit code: {$ret}");
            }
        }

        $oxi_args = [
            $output,
            '--strip', 'safe',
            '--alpha',
            '--opt', 'max',
            '--interlace', '1',
            '--force',
            '--out', $output
        ];

        $ret = $this->oxipng->run($oxi_args);
        if ($ret !== 0) {
            error_log(__FUNCTION__.": failed to run oxipng! exit code: {$ret}");
            unlink($output);
            return false;
        }

        return true;
    }

    public function makeThumbnail(
        string $input,
        string $output,
        int $width,
        int $height,
        int $max_size_bytes = 50000,
        bool $force = false
    ): ?string
    {
        $stream = $this->probeStream([$input], 'video');
        if (!$stream) {
            error_log(__FUNCTION__.": failed to probe video stream in '{$input}'");
            return null;
        }

        $is_big_res = ($stream->width > $width) || ($stream->height > $height);
        $og_size = filesize($input);
        $is_big_fsize = $og_size > $max_size_bytes;
        if (!$force && !$is_big_res && !$is_big_fsize) {
            error_log(__FUNCTION__.": '{$input}' smaller than threshold, skipping");
            return null;
        }

        $width  = $is_big_res ? $width  : null;
        $height = $is_big_res ? $height : null;

        $out_png = $output . '.png';
        $output  = $output . '.jpg';

        $ok = $this->makePng($input, $out_png, $width, $height, true);

        $max_q = 75;
        $min_q = 40;
        $best_q = 75;
        $prev_q = 75;
        $q_size = [];
        for ($i = 10; $i > 0; $i--) {
            $ok = $this->makeJpeg($input, $output, $width, $height, $best_q);
            if (!$ok) {
                return null;
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

        $size_png = filesize($out_png);
        if ($size_png < $size) {
            error_log(__FUNCTION__.": png smaller than jpg");
            unlink($output);
            $output = $out_png;
            $size = $size_png;
        } else {
            unlink($out_png);
        }

        if (!$force && $og_size < $size) {
            error_log(__FUNCTION__.": new thumb bigger than original, removing...");
            unlink($output);
            return null;
        }

        return $output;
    }

    private function optimizePng(string $input): bool
    {
        $oxi_args = [
            $input,
            '--strip', 'safe',
            '--alpha',
            '--opt', 'max',
            '--interlace', '0',
            '--out', $input
        ];

        $ret = $this->oxipng->run($oxi_args);
        if ($ret !== 0) {
            error_log(__FUNCTION__.": failed to run oxipng! exit code: {$ret}");
            return false;
        }

        return true;
    }

    private function optimizeJpeg(string $input): bool
    {
        $args = [
            '-outfile', $input,
            $input,
        ];

        $ret = $this->jpegtran->run($args);
        if ($ret !== 0) {
            error_log(__FUNCTION__.": failed to run jpegtran! exit code: {$ret}");
            return false;
        }

        return true;
    }

    private function detectFormat(string $input): ?string
    {
        $format = $this->probeFormat([$input]);
        if (!$format) {
            error_log(__FUNCTION__.": failed to probe format in '{$input}'");
            return null;
        }

        $vs = $this->probeStream([$input], 'video');
        if (!$format) {
            error_log(__FUNCTION__.": failed to probe video stream in '{$input}'");
        }

        switch ($format->format_name)
        {
        case 'png_pipe':
            return 'png';
        case 'gif':
            return 'gif';
        case 'webp_pipe':
            return 'webp';
        case 'mov,mp4,m4a,3gp,3g2,mj2':
            return 'mp4';
        case 'matroska,webm':
            return 'mkv/webm';
        case 'image2':
            if ($vs->codec_name == 'mjpeg') {
                return 'jpeg';
            }
        default:
            return 'unknown';
        }
    }

    public function getMime(string $path): ?string
    {
        $ret = $this->file->run([$path], $out);
        if ($ret === 0) {
            return $out;
        }
        return null;
    }

    public function optimize(string $input, bool $lossy = false): bool
    {
        $format = $this->detectFormat($input);

        $size_old = filesize($input);

        switch ($format)
        {
        case 'png':
            $ok = $this->optimizePng($input);
            if (!$ok) {
                error_log(__FUNCTION__.": failed to optimize png '{$input}'");
                return false;
            }
            break;
        case 'jpeg':
            $ok = $this->optimizeJpeg($input);
            if (!$ok) {
                error_log(__FUNCTION__.": failed to optimize png '{$input}'");
                return false;
            }
            break;
        default:
            error_log(__FUNCTION__.": unhandled format '{$format}' for '{$input}'");
            return false;
        }

        clearstatcache();
        $size = filesize($input);

        error_log("optimized '$input', size delta: " . $size - $size_old);

        return true;
    }
}
