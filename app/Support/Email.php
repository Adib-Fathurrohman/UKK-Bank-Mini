<?php

namespace App\Support;

final class Email
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
