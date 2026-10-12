<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\Sla\SlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Applies the current rules (config/sla.php) to tickets that are still open, counting from the
 * time each ticket was created. Run it after changing SLA hours, business hours or holidays,
 * and once after upgrading to fill in deadlines for tickets created before SLAs existed.
 * Finished tickets keep the deadline they had.
 */
#[Signature('tickets:recalculate-sla')]
#[Description('Recalculate SLA deadlines of all active tickets from the current SLA rules')]
class RecalculateSlaDeadlines extends Command
{
    public function handle(SlaService $sla): int
    {
        $count = 0;

        Ticket::query()->active()->chunkById(200, function ($tickets) use ($sla, &$count) {
            foreach ($tickets as $ticket) {
                $ticket->update($sla->recalculation($ticket));
                $count++;
            }
        });

        $this->components->info("{$count} active ticket(s) recalculated.");

        return self::SUCCESS;
    }
}
