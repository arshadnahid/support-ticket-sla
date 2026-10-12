<?php

namespace App\Enums;

/**
 * How a ticket got its assignee. Recorded on the TicketAssigned event so the
 * activity history can say "assigned by an admin" or "picked up by the agent".
 */
enum AssignmentMethod: string
{
    case Manual = 'manual';     // an admin assigned or reassigned it
    case Accepted = 'accepted'; // an agent took it from the unassigned queue
    case Auto = 'auto';         // the automatic assignment job
}
