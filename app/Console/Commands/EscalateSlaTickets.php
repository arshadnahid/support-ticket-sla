<?php

namespace App\Console\Commands;

use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use App\Services\Sla\SlaEscalationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finds active tickets that are approaching or past their SLA deadline and escalates them.
 * Runs every minute (routes/console.php). A ticket is escalated once per level, so running
 * it more often, or twice at once, does not send duplicate notifications.
 */
#[Signature('tickets:escalate-sla {--limit= : Maximum number of tickets to check (default: sla.escalation.batch)}')]
#[Description('Escalate active tickets that are approaching or have passed their SLA deadline')]
class EscalateSlaTickets extends Command
{
    public function handle(TicketInterface $tickets, SlaEscalationService $escalation): int
    {
        $limit = (int) ($this->option('limit') ?: config('sla.escalation.batch'));

        $warned = $breached = $failed = 0;

        foreach ($tickets->slaEscalationCandidateIds($limit) as $id) {
            try {
                match ($escalation->escalate($id)?->value) {
                    'approaching' => $warned++,
                    'breached' => $breached++,
                    default => null,
                };
            } catch (Throwable $e) {
                // One bad ticket must not stop the others. It is retried on the next run.
                report($e);
                $failed++;
            }
        }

        $this->components->info("Escalated: {$warned} approaching, {$breached} breached.".($failed ? " {$failed} failed (see the log)." : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
