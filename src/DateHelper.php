<?php

namespace rolka;
use DateInterval;
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

    public static function toDB(?DateTimeInterface $dt)
    {
        return $dt ? $dt->format("Y-m-d H:i:s") : null;
    }

    public static function totalMinutes(DateInterval $d)
    {
        return $d->i
             + $d->h * 60
             + $d->days * 60 * 24;
    }
}
