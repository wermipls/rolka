<?php

namespace rolka;

class SignedStamp
{
    public string $hash;

    private function __construct(
        public int $timestamp,
        public string $msg,
    ) {
    }

    public static function withHash(
        int $timestamp, string $msg, string $hash): SignedStamp
    {
        $s = new SignedStamp($timestamp, $msg);
        $s->hash = $hash;
        return $s;
    }

    public static function withKey(
        int $timestamp, string $msg, string $key): SignedStamp
    {
        $s = new SignedStamp($timestamp, $msg);
        $s->hash = $s->hash($key);
        return $s;
    }

    private static function getData($msg, $ts): string
    {
        return $msg . sprintf('%32d', $ts);
    }

    private function hash(string $key): string
    {
        return hash_hmac(
            "sha3-256",
            $this->getData($this->msg, $this->timestamp),
            $key);
    }

    private function isTimestampValid()
    {
        $start = $this->timestamp;
        $end = $start + 60*60*24; // 24h
        $t = time();
        return $t >= $start && $t < $end;
    }

    public function validate(string $key): bool
    {
        if ($this->isTimestampValid()) {
            return hash_equals($this->hash, $this->hash($key));
        }
        return false;
    }
}
