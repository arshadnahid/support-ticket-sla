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
 * | create        | no    | no                       | yes                              |
 * | update        | any*  | assigned to them*        | own, only while status is Open*  |
 * | changeStatus  | any   | assigned to them         | own, and only to Closed          |
 * | assign        | yes   | no                       | no                               |
 * | accept        | no    | yes (if still unassigned)| no                               |
 * | reply / attach| yes   | assigned to them         | own tickets                      |
 * | internal note | yes   | assigned to them         | no                               |
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

    /**
     * Only customers raise tickets; staff work on the ones customers create.
     */
    public function create(User $user): bool
    {
        return $user->isCustomer();
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
     * Give a ticket to an agent (or move it to another one). Admins only.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /**
     * Take an unassigned ticket. Agents only: admins use assign().
     *
     * Deliberately does NOT check "is it still unassigned?". That answer must come from the
     * locked row inside TicketAssignmentService, so an agent who loses a race gets a clear
     * 409 "already assigned" instead of a confusing 403.
     */
    public function accept(User $user, Ticket $ticket): bool
    {
        return $user->isAgent();
    }

    /**
     * Reply on the ticket or attach files to it. Whether the ticket is still open for
     * conversation (not Closed) is checked by TicketConversationService.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Agent => $ticket->assigned_to === $user->id,
            UserRole::Customer => $ticket->created_by === $user->id,
        };
    }

    /**
     * Internal notes: staff who may reply. A customer asking for one gets 403, not a silent public reply.
     */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() && $this->reply($user, $ticket);
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
