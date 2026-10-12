<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ticket number format
    |--------------------------------------------------------------------------
    |
    | Numbers look like "TKT-2026-000001": prefix, year, zero-padded counter.
    | The counter restarts every year. Numbers longer than "number_padding"
    | digits are not truncated, so the format keeps working past 999,999
    | tickets a year.
    |
    */

    'number_prefix' => env('TICKET_NUMBER_PREFIX', 'TKT'),

    'number_padding' => (int) env('TICKET_NUMBER_PADDING', 6),

    /*
    |--------------------------------------------------------------------------
    | Automatic assignment
    |--------------------------------------------------------------------------
    |
    | When enabled, every new ticket is queued for automatic assignment, and the
    | scheduled "tickets:assign-unassigned" command retries tickets that are still
    | waiting (for example because no agent was available). Both need a queue worker.
    | Set TICKET_AUTO_ASSIGN=false to assign tickets by hand only.
    |
    | "batch" is how many waiting tickets one run of the command queues.
    |
    */

    'assignment' => [
        'auto_assign' => (bool) env('TICKET_AUTO_ASSIGN', true),
        'batch' => (int) env('TICKET_AUTO_ASSIGN_BATCH', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 15,

    'max_per_page' => 100,

];
