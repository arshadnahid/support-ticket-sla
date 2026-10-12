<?php

namespace App\Exceptions;

use Exception;

/**
 * The ticket cannot take replies or files right now. Rendered as 409 Conflict by ApiExceptionRenderer.
 */
class TicketConversationException extends Exception
{
    public static function closed(): self
    {
        return new self('A closed ticket cannot receive replies or attachments.');
    }
}
