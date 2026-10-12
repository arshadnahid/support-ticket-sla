<?php

namespace App\DTOs\Ticket;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketSort;
use App\Enums\TicketStatus;
use Carbon\CarbonInterface;

/**
 * Everything a client can narrow or reorder a ticket list by. Every field is optional; the
 * filters combine with AND. Who may see which tickets is decided separately (Ticket::visibleTo),
 * so a filter can only ever narrow what the user is already allowed to see.
 */
class TicketListFilters
{
    public function __construct(
        public readonly ?string $keyword = null,
        public readonly ?string $number = null,
        public readonly ?TicketStatus $status = null,
        public readonly ?TicketPriority $priority = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $assignedTo = null,
        public readonly bool $unassigned = false,
        public readonly ?int $customerId = null,
        public readonly ?CarbonInterface $createdFrom = null,
        public readonly ?CarbonInterface $createdTo = null,
        public readonly ?SlaState $sla = null,
        public readonly ?TicketSort $sort = null,
    ) {}
}
