<?php

namespace rolka;
use DateTimeInterface;
use DateTimeImmutable;
use DateTimeZone;

class DateHelper
{
    public static function isDifferentDay(
        DateTimeInterface $old,
        DateTimeInterface $new
    ): bool {
        return $old->format('dMY') != $new->format('dMY');
    }

    public static function fromDB(?string $t): ?DateTimeImmutable
    {
        if (!$t) {
            return null;
        }
        return new DateTimeImmutable($t, new DateTimeZone("UTC"));
    }
}
