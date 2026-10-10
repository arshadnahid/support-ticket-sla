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
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 15,

    'max_per_page' => 100,

];
