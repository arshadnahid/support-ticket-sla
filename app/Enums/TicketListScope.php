<?php

namespace App\Enums;

enum TicketListScope: string
{
    case All = 'all';
    case Active = 'active';
    case Finished = 'finished';
}
