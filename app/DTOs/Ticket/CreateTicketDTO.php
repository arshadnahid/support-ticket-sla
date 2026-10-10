<?php

namespace App\DTOs\Ticket;

use App\Enums\TicketPriority;

class CreateTicketDTO
{
    public function __construct(
        public readonly string $subject,
        public readonly string $description,
        public readonly TicketPriority $priority,
        public readonly int $categoryId,
    ) {}
}
