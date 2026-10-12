<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SLA escalation: flags tickets that are approaching or past their deadline, writes the history
// entry and notifies staff (email + database). Each level is escalated once per ticket.
Schedule::command('tickets:escalate-sla')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Safety net for automatic assignment: re-queue tickets that are still waiting for an agent
// (everyone was unavailable, or a job failed). Needs the scheduler AND a queue worker running.
// `withoutOverlapping` + `onOneServer` keep it to a single run even with several servers.
Schedule::command('tickets:assign-unassigned')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
