<?php

namespace App\Services\Ticket;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Builds unique, gap-free ticket numbers such as TKT-2026-000001.
 *
 * How uniqueness is guaranteed:
 *  1. One counter row per year lives in `ticket_sequences`.
 *  2. The row is incremented with an UPDATE, which locks it, so a second request
 *     that arrives at the same moment waits until the first one commits.
 *  3. When called inside the ticket-creating transaction (as TicketService does),
 *     a failed insert also rolls the counter back, so no numbers are skipped.
 *  4. `tickets.number` has a UNIQUE index as a last line of defence.
 *
 * Trade-off: ticket creation is serialised for the length of that transaction
 * (a few milliseconds). That is fine for a support desk; a much higher write rate
 * would call for pre-allocated number blocks.
 */
class TicketNumberGenerator
{
    public function next(?CarbonInterface $at = null): string
    {
        $year = ($at ?? now())->year;

        $number = DB::transaction(function () use ($year): int {
            $row = fn () => DB::table('ticket_sequences')->where('year', $year);

            // Increment FIRST. The UPDATE takes the row's write lock straight away, so
            // parallel requests simply queue up. (Doing "insert if missing" or a plain read
            // first takes a shared lock that must then be upgraded, and two requests doing
            // that deadlock each other: seen under 8 parallel workers on MySQL.)
            if ($row()->increment('last_number') === 0) {
                // First ticket of the year: create the row, ignoring a concurrent creator.
                DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
                $row()->increment('last_number');
            }

            // We hold the write lock, so this reads our own, final value.
            return (int) $row()->lockForUpdate()->value('last_number');
        }, attempts: 3); // when this is the outermost transaction, a deadlock is retried

        return sprintf(
            '%s-%d-%s',
            config('tickets.number_prefix'),
            $year,
            str_pad((string) $number, (int) config('tickets.number_padding'), '0', STR_PAD_LEFT),
        );
    }
}
