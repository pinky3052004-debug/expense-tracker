<?php
declare(strict_types=1);
namespace App;

final class Util
{
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function money(float|string $n, ?string $cur = null): string
    {
        $s = rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
        return $cur ? "$s $cur" : $s;
    }
}
