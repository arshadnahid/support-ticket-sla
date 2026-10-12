<?php

namespace App\Enums;

/**
 * Where a ticket stands against its resolution deadline.
 *
 * rank() doubles as the escalation level stored on the ticket: 0 = nothing escalated yet,
 * 1 = escalated as "approaching", 2 = escalated as "breached".
 */
enum SlaState: string
{
    case Within = 'within';
    case Approaching = 'approaching';
    case Breached = 'breached';

    public function label(): string
    {
        return match ($this) {
            self::Within => 'Within SLA',
            self::Approaching => 'Approaching SLA breach',
            self::Breached => 'SLA breached',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Within => 0,
            self::Approaching => 1,
            self::Breached => 2,
        };
    }
}
