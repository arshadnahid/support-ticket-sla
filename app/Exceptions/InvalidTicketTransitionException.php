<?php

namespace App\Exceptions;

use App\Enums\TicketStatus;
use Exception;

/**
 * Thrown when a ticket is asked to move to a status it cannot reach from its
 * current one. Rendered as 409 Conflict by ApiExceptionRenderer.
 */
class InvalidTicketTransitionException extends Exception
{
    public function __construct(public readonly TicketStatus $from, public readonly TicketStatus $to)
    {
        parent::__construct(sprintf(
            'A ticket cannot move from "%s" to "%s".',
            $from->label(),
            $to->label(),
        ));
    }
}
