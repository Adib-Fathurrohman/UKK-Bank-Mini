<?php

namespace App\Exceptions;

/**
 * A correction transaction was refused (wrong shift, already corrected, ...).
 */
class CorrectionNotAllowedException extends BankingException
{
}
