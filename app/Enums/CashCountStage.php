<?php

namespace App\Enums;

enum CashCountStage: string
{
    // Saldo awal counted when the shift opens.
    case OPENING = 'opening';
    // Uang fisik counted when the shift closes.
    case CLOSING = 'closing';
}
