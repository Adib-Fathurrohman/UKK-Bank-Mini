<?php

namespace App\Enums;

enum UserRoles: string
{
    //
    case ADMIN = 'admin';
    case SUPERVISOR = 'supervisor';
    case TELLER = 'teller'; 
}
