<?php

namespace rolka;

enum ArgType
{
    case StoreTrue;
    case String;
    case Int;
    case Bool;
}

class Argument
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $short_name,
        public readonly ArgType $type,
        public readonly ?string $default,
        public readonly bool $optional,
        public readonly bool $positional,
        public $callback
    ) {
    }

    public function expectsParameter(): bool
    {
        return match ($this->type) {
            ArgType::StoreTrue => false,
            default => true
        };
    }

    public function __toString(): string
    {
        $name = $this->short_name ?? $this->name;

        if ($this->positional) {
            return $name;
        } else if ($this->expectsParameter()) {
            return "[{$name} value]";
        } else {
            return "[{$name}]";
        }
    }
}

class ArgParseException extends \Exception { }

class ArgParser
{
    private Array $args = [];
    private Array $args_positional = [];
    private Array $parsed = [];
    private int $required_positional = 0;

    function __construct(
        private ?string $name = null,
        private ?string $description = null
    ) {
        $this->addArg('-h', ArgType::StoreTrue, callback: function () {
            $this->printUsage();
            exit(0);
        });
    }

    public function addArg(
        string $name,
        ArgType $type = ArgType::String,
        ?string $default = null,
        ?bool $optional = null,
        ?string $short_name = null,
        $callback = null
    ) {
        if ($callback && !is_callable($callback)) {
            throw new \InvalidArgumentException("Callback is not callable");
        }

        $positional = $name[0] != '-';

        if (!$positional && $short_name && $short_name[0] != '-') {
            throw new \InvalidArgumentException(
                "Short name expected to begin with a hyphen, got '{$short_name}'");
        }

        $arg_names = $short_name ? [$name, $short_name] : [$name];

        foreach ($arg_names as $n) {
            $alphanumeric = preg_match(
                "/\G[A-z_\d]+$/", $name, offset: $positional ? 0 : 1);

            if (!$alphanumeric) {
                throw new \InvalidArgumentException(
                    "Argument name expected to be alphanumeric, got '{$name}'");
            }

            if (array_key_exists($n, $this->args)) {
                throw new \InvalidArgumentException(
                    "Argument '{$name}' already exists");
            }
        }

        $optional = $optional ?? ($positional ? false : true);

        $arg = new Argument(
            $name,
            $short_name,
            $type,
            $default,
            $optional,
            $positional,
            $callback
        );

        foreach ($arg_names as $n) {
            $this->args[$n] = $arg;
        }

        if ($positional) {
            $last_positional = end($this->args_positional);

            if (!$optional && $last_positional && $last_positional->optional) {
                throw new \InvalidArgumentException(
                    "Non-optional positional argument '{$name}' placed after "
                   ."optional positional argument {$last_positional->name}");
            }
            array_push($this->args_positional, $arg);
            if (!$optional) {
                $this->required_positional += 1;
            }
        }
    }

    private function parseArg(Argument $arg, ?string $param = null)
    {
        $val = match (true) {
            $arg->type == ArgType::String => $param,
            $arg->type == ArgType::Int && is_numeric($param) => (int)$param,
            $arg->type == ArgType::StoreTrue => true,
            $arg->type == ArgType::Bool => match ($param) {
                'true', 'yes', 'y', '1' => true,
                'false', 'no', 'n', '0' => false,
                default =>
                    throw new ArgParseException(
                        "Argument '{$arg->name}' parameter '{$param}' "
                       ."is not of valid type, expected {$arg->type->name}"
                    )
            },
            default =>
                throw new ArgParseException(
                    "Argument '{$arg->name}' parameter '{$param}' "
                   ."is not of valid type, expected {$arg->type->name}"
                ),
        };

        if ($arg->callback) {
            ($arg->callback)($val);
        }

        if (isset($this->parsed[$arg->name])) {
            throw new ArgParseException(
                "Duplicate argument '{$arg->name}'"
            );
        }

        $this->parsed[$arg->name] = $val;
    }

    private function parseInternal(Array $argv)
    {
        if ($this->name === null) {
            $this->name = $argv[0];
        }
        unset($argv[0]);

        $arg = null;
        $expecting_param = false;
        $positional_count = 0;

        foreach ($argv as $v) {
            if ($arg && $expecting_param) {
                $this->parseArg($arg, $v);
                $arg = null;
                continue;
            }

            $arg = $this->args[$v] ?? null;
            if ($arg && !$arg->positional) {
                $expecting_param = $arg->expectsParameter();
                if (!$expecting_param) {
                    $this->parseArg($arg);
                    $arg = null;
                }
                continue;
            }

            $arg = $this->args_positional[$positional_count];
            $positional_count++;
            if (!$arg) {
                throw new ArgParseException(
                    "Excess positional argument '{$v}'");
            }

            $this->parseArg($arg, $v);
            $arg = null;
        }

        if ($arg && $expecting_param) {
            throw new ArgParseException(
                "Argument '{$arg->name}' is missing parameter");
        }

        if ($positional_count < $this->required_positional) {
            throw new ArgParseException(
                "Required {$this->required_positional} "
               ."positional arguments, got {$positional_count}"
            );
        }

        foreach ($this->args as $a) {
            if (isset($this->parsed[$a->name])) {
                continue;
            }

            if ($a->default) {
                $this->parseArg($a, $a->default);
            } else {
                $this->parsed[$a->name] = null;
            }
        }
    }

    private function printUsage()
    {
        error_log(
            "usage: {$this->name} " . implode(' ', $this->args)
        );
    }

    public function parse(Array $argv): Array
    {
        try {
            $this->parseInternal($argv);
            return $this->parsed;
        } catch (ArgParseException $e) {
            $this->printUsage();
            error_log("\nerror: {$e->getMessage()}");
            exit(1);
        }
    }
}
