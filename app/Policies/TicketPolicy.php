<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;

/**
 * Who may do what with a ticket. Role decides the kind of access; ownership or
 * assignment decides whether it applies to this particular ticket.
 *
 * | Action        | Admin | Agent                    | Customer                         |
 * |---------------|-------|--------------------------|----------------------------------|
 * | view          | all   | assigned to them or free | own tickets                      |
 * | create        | yes   | yes                      | yes                              |
 * | update        | any*  | assigned to them*        | own, only while status is Open*  |
 * | changeStatus  | any   | assigned to them         | own, and only to Closed          |
 *
 * * never on a Closed ticket.
 *
 * Keep view() in sync with the Ticket::visibleTo() query scope used by lists.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Agent => $ticket->assigned_to === null || $ticket->assigned_to === $user->id,
            UserRole::Customer => $ticket->created_by === $user->id,
        };
    }

    public function update(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::Closed) {
            return false;
        }

        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Agent => $ticket->assigned_to === $user->id,
            UserRole::Customer => $ticket->created_by === $user->id
                && $ticket->status === TicketStatus::Open,
        };
    }

    /**
     * Whether the user may move the ticket to $to. Whether the move itself is
     * valid (e.g. Closed is final) is checked by TicketService.
     */
    public function changeStatus(User $user, Ticket $ticket, TicketStatus $to): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Agent => $ticket->assigned_to === $user->id,
            UserRole::Customer => $ticket->created_by === $user->id && $to === TicketStatus::Closed,
        };
    }
}
