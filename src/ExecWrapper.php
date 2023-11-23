<?php

namespace rolka;

class ExecWrapper
{
    private array $args = [];

    public function __construct(
        private string $program
    ) {
    }

    public function addArgs(array $args)
    {
        $this->args = [...$this->args, ...$args];
    }

    /**
     * Returns the exit code or `false` on error.
     */
    public function run(
        array $extra_args = [], &$stdout = null, &$stderr = null, &$stdin = null): int|false
    {
        $cmd = escapeshellarg($this->program);
        foreach ([...$this->args, ...$extra_args] as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }

        $cmd .= '; echo $? >&3'; 

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => ['pipe', 'w']
        ], $pipes);

        if ($proc === false) {
            return false;
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = stream_get_contents($pipes[3]);
        fclose($pipes[3]);

        if (proc_close($proc) === -1) {
            return false;
        }

        return (int)$exit_code;
    }
}
