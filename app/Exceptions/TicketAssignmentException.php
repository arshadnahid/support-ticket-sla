<?php

namespace App\Exceptions;

use App\Enums\TicketStatus;
use Exception;

/**
 * The ticket cannot be assigned right now. Rendered as 409 Conflict by ApiExceptionRenderer.
 *
 * This is the "you lost the race" answer when two agents accept the same ticket.
 */
class TicketAssignmentException extends Exception
{
    public static function alreadyAssigned(): self
    {
        // Deliberately does not say who has it.
        return new self('This ticket has already been assigned to another agent.');
    }

    public static function notAnAgent(): self
    {
        return new self('The selected user is not an active support agent.');
    }

    public static function notAssignable(TicketStatus $status): self
    {
        return new self(sprintf('A %s ticket cannot be assigned.', strtolower($status->label())));
    }
}
