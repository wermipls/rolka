<?php

namespace rolka;
use DateTimeImmutable;

class DateHelper
{
    public static function isDifferentDay(
        DateTimeImmutable $old,
        DateTimeImmutable $new
    ): bool {
        return ($new->setTime(0, 0)->diff($old->setTime(0, 0))->d > 0);
    }
}
