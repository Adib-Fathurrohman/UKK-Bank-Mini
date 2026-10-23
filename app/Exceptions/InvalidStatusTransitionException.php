<?php

namespace App\Exceptions;

/**
 * An approval workflow step was attempted from the wrong status,
 * e.g. approving a report that was already approved.
 */
class InvalidStatusTransitionException extends BankingException
{
}
