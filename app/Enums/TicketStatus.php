<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::WaitingForCustomer => 'Waiting for Customer',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * Statuses a ticket may move to from this one. Closed is final.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::WaitingForCustomer, self::Resolved, self::Closed],
            self::InProgress => [self::WaitingForCustomer, self::Resolved, self::Closed],
            self::WaitingForCustomer => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::InProgress, self::Closed], // InProgress = reopen
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * Statuses where the ticket still needs work (the SLA clock applies to these).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Open, self::InProgress, self::WaitingForCustomer];
    }

    /**
     * @return list<self>
     */
    public static function finished(): array
    {
        return [self::Resolved, self::Closed];
    }
}
