<?php

namespace rolka;
use DateTimeInterface;

class DateHelper
{
    public static function isDifferentDay(
        DateTimeInterface $old,
        DateTimeInterface $new
    ): bool {
        return $old->format('dMY') != $new->format('dMY');
    }
}
