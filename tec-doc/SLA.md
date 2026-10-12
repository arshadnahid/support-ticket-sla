# SLA, Business Hours and Escalation

How the three requirements (8. SLA Management, 9. Business Hours, 10. SLA Escalation) are built, why, and where to change them.

## 1. Overview

```
 config/sla.php ──► SlaService ──► BusinessHours        (deadline maths)
                        │
   ticket created ──────┤  stores due_at + sla_warning_at on the ticket
   priority changed ────┘

 scheduler (every minute) ─► tickets:escalate-sla ─► SlaEscalationService
                                                        ├─ tickets.escalation_level / escalated_at
                                                        ├─ ticket_activities row (history)
                                                        └─ TicketSlaEscalated event
                                                              └─ NotifySlaEscalation listener
                                                                    └─ mail + database notification (queued)
```

Key decision: **the deadline is calculated once and stored.** Reading the SLA state later is a plain comparison with "now", so no business-hours maths runs on read, and "all breached tickets" is an ordinary indexed query.

| Piece | File |
|---|---|
| All rules | `config/sla.php` |
| Deadlines and states | `app/Services/Sla/SlaService.php` |
| Working-time calendar | `app/Services/Sla/BusinessHours.php` |
| State enum | `app/Enums/SlaState.php` |
| Escalation of one ticket | `app/Services/Sla/SlaEscalationService.php` |
| Scheduled sweep | `app/Console/Commands/EscalateSlaTickets.php`, `routes/console.php` |
| Recalculate after rule change | `app/Console/Commands/RecalculateSlaDeadlines.php` |
| Who is notified | `app/Listeners/Ticket/NotifySlaEscalation.php` |
| Email and in-app message | `app/Notifications/TicketSlaEscalatedNotification.php` |
| History entry | `app/Models/TicketActivity.php` |

## 2. SLA management (requirement 8)

### Rules by priority

`config/sla.php` -> `resolution_hours` (working hours, decimals allowed, each overridable by env):

| Priority | Resolution time | Env |
|---|---|---|
| low | 48 h | `SLA_HOURS_LOW` |
| medium | 24 h | `SLA_HOURS_MEDIUM` |
| high | 8 h | `SLA_HOURS_HIGH` |
| critical | 2 h | `SLA_HOURS_CRITICAL` |

The hours are **business hours**, not clock hours (see section 3).

### Changing the rules without touching code

Nothing in the application hard-codes an SLA number. Controllers, resources and queries only ask `SlaService`. To change a rule edit `config/sla.php` (or the `.env`), then run:

```
php artisan tickets:recalculate-sla
```

That re-applies the rules to tickets that are still active, counted from each ticket's creation time. Finished tickets keep the deadline they had. A new priority only needs a new entry in `resolution_hours`.

### The three states

`App\Enums\SlaState`: `within`, `approaching`, `breached`.

| State | Rule |
|---|---|
| Within SLA | now is before `sla_warning_at` |
| Approaching breach | now is at or after `sla_warning_at` and not after `due_at` |
| SLA breached | now is after `due_at` |

`warning_percent` (default 80, `SLA_WARNING_PERCENT`) decides `sla_warning_at`: it is the moment that percentage of the SLA time has been used. A Critical ticket (2 working hours) becomes "approaching" after 1h36m of working time.

Two stored columns on `tickets` drive everything:

- `due_at`: the resolution deadline.
- `sla_warning_at`: when it starts to be approaching.

They are set when a ticket is created (`TicketService::create`), and recalculated from the creation time when its **priority changes** (`TicketService::update`).

Finished tickets are judged differently: a resolved or closed ticket is `breached` if it was finished after `due_at`, otherwise `within`. A ticket with no deadline (created before SLAs existed) has no state (`null`) until `tickets:recalculate-sla` fills it in.

### Where it shows in the API

- Ticket responses include a `sla` block for **staff only** (customers never see it): state, `warning_at`, remaining working minutes, `escalation_level`, `escalated_at`.
- List endpoints accept `?sla=within|approaching|breached` (active tickets) and `?sort=sla_deadline` (earliest deadline first, tickets without a deadline last). `Ticket::inSlaState()` mirrors `SlaService::stateOf()` so the filter and the displayed state agree.

## 3. Business hours (requirement 9)

### Configuration

`config/sla.php` -> `business_hours`:

| Key | Default | Env |
|---|---|---|
| `timezone` | `Asia/Dhaka` | `SLA_TIMEZONE` |
| `days` (0 = Sunday ... 6 = Saturday) | `0,1,2,3,4` (Sunday to Thursday) | `SLA_BUSINESS_DAYS` |
| `start` / `end` | `09:00` / `18:00` | `SLA_BUSINESS_START`, `SLA_BUSINESS_END` |
| `holidays` | none | `SLA_HOLIDAYS` (comma separated `YYYY-MM-DD`) |

Dates are always stored and returned in UTC. The timezone only decides where the working day starts and ends.

### The approach

Adding clock hours is wrong because time outside working hours must not count. `BusinessHours::addMinutes($from, $minutes)` instead walks the calendar:

1. Move to the next opening: `$from` itself if the office is open, otherwise the next time it opens (skipping non-working days and holidays).
2. Take the time left until closing that day.
3. If the remaining SLA fits, the deadline is `at + remaining`. If not, subtract what fits, jump to the next opening and repeat.

Work is done in seconds so fractions of a minute are kept. The loop looks ahead at most 400 days before giving up with an exception (an invalid calendar cannot loop forever). The constructor rejects an empty working-day list and hours where opening is not before closing.

`minutesBetween($from, $to)` does the reverse (working minutes between two instants) and powers the "minutes remaining" value.

### Worked example (the case in the brief)

Sunday to Thursday, 09:00 to 18:00. A **Critical** ticket (2 working hours) is created **Thursday 17:00**:

- Thursday has 1 working hour left (17:00 to 18:00).
- Friday and Saturday are not working days.
- The second hour is spent on **Sunday 09:00 to 10:00**.
- Deadline: **Sunday 10:00**. Adding two clock hours (Thursday 19:00) would have been wrong.

A ticket created outside hours (say Friday 12:00) starts counting at the next opening (Sunday 09:00), so a Critical deadline is Sunday 11:00.

### Deliberately not covered

Several shifts per day, lunch breaks, different hours per weekday, per-customer calendars. The calendar class is the one place to extend if these are needed; nothing else depends on how the maths is done.

## 4. Escalation (requirement 10)

### Mechanism

`php artisan tickets:escalate-sla` is scheduled **every minute** (`routes/console.php`, `withoutOverlapping`, `onOneServer`). It needs the Laravel scheduler running, and a queue worker for the notifications.

1. `TicketRepository::slaEscalationCandidateIds($limit)` picks active tickets that are past `due_at` with `escalation_level < 2`, or past `sla_warning_at` with `escalation_level < 1`, earliest deadline first, at most `escalation.batch` (default 200) per run.
2. For each, `SlaEscalationService::escalate($id)`, inside a transaction with the ticket row **locked** and its state re-read:
   - skips tickets that are no longer active (resolved or closed work is not escalated);
   - skips if the level was already escalated (a ticket is escalated **once per level**);
   - **marks the ticket as escalated**: `escalation_level` (1 = approaching, 2 = breached) and `escalated_at`;
   - **creates a history entry** in `ticket_activities` (`type` `sla_warning` or `sla_breached`, `user_id` null = the system, with state, `due_at`, priority and assignee in `properties`);
   - dispatches `TicketSlaEscalated`.
3. One failing ticket is reported and retried on the next run; it does not stop the others.

Because of the lock and the level check, running the command twice, or two servers at once, never produces duplicate escalations or emails.

### In plain words: the command is a watchman

`tickets:escalate-sla` (`EscalateSlaTickets`) walks through the active tickets once a minute. Every ticket has a deadline (`due_at`) and a warning time (`sla_warning_at`), but nothing checks them by itself. The command does, and when a ticket is running late it raises the alarm and tells the right people.

**Example.** A Critical ticket (2 working hours) is created Sunday 09:00 and assigned to agent Rahim. Its deadline is Sunday 11:00 and its warning time is Sunday 10:36 (80% of the time used).

| Time | What the command finds | What happens |
|---|---|---|
| 09:30 | Still within SLA | Nothing. |
| 10:36 | Warning time passed | Escalation level 1 ("approaching"): `escalation_level = 1` is saved, a history entry "SLA warning" is written, Rahim gets an email and an in-app notification. |
| 10:37, 10:38... | Already at level 1 | Nothing, so Rahim is not emailed every minute. |
| 11:01 | Deadline passed | Escalation level 2 ("breached"): history entry "SLA breached", Rahim and all admins are notified. |
| afterwards | Already at level 2 | Nothing more. |

If Rahim resolves the ticket at 10:50, the command skips it from then on, because finished work is not escalated.

**One run, step by step:**

1. Look: find active tickets past their warning time or deadline that have not been escalated for that level yet.
2. Escalate each one: save the level, write the history entry, send the notifications.
3. Report: print a summary such as `Escalated: 1 approaching, 1 breached.`

**Why it is safe to run repeatedly:** each level is escalated once per ticket (the stored `escalation_level` stops duplicates), and one failing ticket is logged and retried next minute without stopping the others. The scheduler must be running for the every-minute run, and a queue worker for the emails to go out. You can also run it by hand: `php artisan tickets:escalate-sla`.

### Who is notified

`NotifySlaEscalation` listener (customers are never notified):

| Level | Recipients |
|---|---|
| approaching | the assigned agent; all active admins instead if nobody has the ticket |
| breached | the assigned agent **and** all active admins |

`TicketSlaEscalatedNotification` is queued and goes by two channels:

- **Email**: subject `SLA warning: TKT-...` or `SLA breached: TKT-...`, with the subject, priority, deadline (UTC) and assignee.
- **Database**: a row in `notifications` (`type` `sla_warning` or `sla_breached`, ticket number, message, `due_at`) for an in-app inbox.

### Interaction with other changes

- **Priority change**: deadlines are recomputed from creation time. The escalation level never goes up from this, but goes **down** if the new deadline is further away, so the ticket can be escalated properly again later.
- **Rule change**: `tickets:recalculate-sla` applies the same logic to every active ticket.

## 5. Operating it

```
php artisan schedule:work          # or a cron entry running schedule:run every minute
php artisan queue:work             # sends the notification emails
php artisan tickets:escalate-sla   # run the sweep by hand
php artisan tickets:recalculate-sla
```

## 6. Tests

- `tests/Unit/BusinessHoursTest.php`: the calendar maths (Thursday 17:00 case, closed days, holidays, fractions).
- `tests/Feature/Ticket/TicketSlaTest.php`: deadlines per priority, states, recalculation, list filter and sort.
- `tests/Feature/Ticket/TicketSlaEscalationTest.php`: escalation levels, once-only behaviour, history entry, recipients.
