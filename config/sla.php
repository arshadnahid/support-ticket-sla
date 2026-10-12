<?php

/*
|--------------------------------------------------------------------------
| SLA rules
|--------------------------------------------------------------------------
|
| Everything that decides a ticket's deadline lives in this file, so changing a rule never
| means touching code. Ticket deadlines are calculated ONCE (when a ticket is created or its
| priority changes) and stored; after editing this file run
|
|     php artisan tickets:recalculate-sla
|
| to apply the new rules to tickets that are still open.
|
*/

return [

    /*
    | Resolution time per priority, in BUSINESS hours (see "business_hours" below).
    | Decimals are fine (0.5 = 30 minutes). A new priority needs an entry here.
    */
    'resolution_hours' => [
        'low' => (float) env('SLA_HOURS_LOW', 48),
        'medium' => (float) env('SLA_HOURS_MEDIUM', 24),
        'high' => (float) env('SLA_HOURS_HIGH', 8),
        'critical' => (float) env('SLA_HOURS_CRITICAL', 2),
    ],

    /*
    | A ticket is "approaching breach" once this percentage of its SLA time has been used.
    | 80 means: a Critical ticket (2 business hours) turns "approaching" after 1h36m.
    */
    'warning_percent' => (int) env('SLA_WARNING_PERCENT', 80),

    /*
    | The working week. SLA time only runs inside these hours.
    |
    |  timezone  the clock the hours below are written in (stored dates stay UTC)
    |  days      working days, 0 = Sunday ... 6 = Saturday (default: Sunday to Thursday)
    |  start/end opening and closing time, 24h "HH:MM"
    |  holidays  whole days off, "YYYY-MM-DD"
    */
    'business_hours' => [
        'timezone' => env('SLA_TIMEZONE', 'Asia/Dhaka'),
        'days' => array_map('intval', array_filter(explode(',', (string) env('SLA_BUSINESS_DAYS', '0,1,2,3,4')), 'strlen')),
        'start' => env('SLA_BUSINESS_START', '09:00'),
        'end' => env('SLA_BUSINESS_END', '18:00'),
        'holidays' => array_filter(explode(',', (string) env('SLA_HOLIDAYS', ''))),
    ],

    /*
    | Escalation sweep (php artisan tickets:escalate-sla, scheduled every minute):
    | the most tickets escalated in one run.
    */
    'escalation' => [
        'batch' => (int) env('SLA_ESCALATION_BATCH', 200),
    ],

];
