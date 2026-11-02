<?php

namespace App\Support;

final class Email
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * "budi.santoso@gmail.com" → "b***@gmail.com": enough for the owner to
     * recognise their address without showing it to whoever sees the screen.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
