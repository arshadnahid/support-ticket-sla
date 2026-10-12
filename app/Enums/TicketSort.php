<?php

namespace App\Enums;

/**
 * Orderings a client can ask for with `?sort=`. Each overrides the list's default order.
 */
enum TicketSort: string
{
    case SlaDeadline = 'sla_deadline';
    case CreatedAsc = 'created_asc';
    case CreatedDesc = 'created_desc';
}
