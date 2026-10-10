<?php

namespace App\DTOs\Ticket;

use App\Enums\TicketPriority;

/**
 * A partial update: only the fields that were sent are non-null.
 */
class UpdateTicketDTO
{
    public function __construct(
        public readonly ?string $subject = null,
        public readonly ?string $description = null,
        public readonly ?TicketPriority $priority = null,
        public readonly ?int $categoryId = null,
    ) {}

    /**
     * Column => new value, for the fields that were sent.
     *
     * @return array<string, string|int>
     */
    public function changes(): array
    {
        return array_filter([
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority?->value,
            'category_id' => $this->categoryId,
        ], fn ($value) => $value !== null);
    }
}
