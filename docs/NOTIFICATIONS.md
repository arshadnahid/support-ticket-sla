# Notifications

Important ticket events notify the right people by **email** and by an **in-app notification** (the `notifications` table, ready for an inbox). Everything is queued, so a slow mail server never slows an API request.

## How it works

```
service action ─► event (after commit) ─► listener picks recipients ─► notification (queued: mail + database)
```

- The service that does the work (create, assign, reply, change status, SLA sweep) only dispatches an event. It knows nothing about email.
- A small listener per event decides who is told. Laravel finds the listeners in `app/Listeners` by their type-hint, so nothing is registered by hand.
- Events are dispatched **after the database commit**, so nobody is notified about something that was rolled back.
- Notifications share a base class (`TicketNotification`): queued, `mail` + `database`, three tries. A subclass only provides its type key, a subject and a sentence.
- Inactive users are never notified, and nobody is notified about their own action.

## Who is told about what

| Event | Listener | Recipients | Notification |
|---|---|---|---|
| Ticket created | `NotifyTicketCreated` | the customer (confirmation), all active admins | `TicketCreatedNotification` |
| Ticket assigned (automatic, by an admin, or an agent accepting) | `NotifyTicketAssigned` | the assigned agent (not if they did it themselves), the customer (who handles it) | `TicketAssignedNotification` |
| Agent replied | `NotifyTicketReplied` | the customer | `TicketReplyNotification` |
| Customer replied | `NotifyTicketReplied` | the assigned agent, or all admins if nobody has the ticket | `TicketReplyNotification` |
| Internal note | `NotifyTicketReplied` | nobody. Notes must never reach the customer | none |
| Ticket resolved | `NotifyTicketResolved` | the customer. Other status changes are not announced | `TicketResolvedNotification` |
| SLA approaching | `NotifySlaEscalation` | the assigned agent, or admins if unassigned | `TicketSlaEscalatedNotification` |
| SLA breached | `NotifySlaEscalation` | the assigned agent and all admins | `TicketSlaEscalatedNotification` |

SLA notifications are covered in `docs/SLA.md`. Customers are never told about SLA state.

## Running it

- A **queue worker** must run to deliver anything: `php artisan queue:work` (the queue driver is `database`).
- **Mail** goes to the log by default (`MAIL_MAILER=log`), so you can read the emails in `storage/logs/laravel.log` without an SMTP server. Set the real `MAIL_*` values to send for real.
- The stored copies are in the `notifications` table (`type`, `ticket_number`, `message`). There is no API endpoint to read them yet.

## Adding a notification

1. Add a class extending `TicketNotification` with `type()`, `subject()` and `message()`.
2. Add a listener for the event that decides the recipients.

No service code changes.

## Tests

`tests/Feature/Ticket/TicketNotificationsTest.php` covers each row of the table above, including internal notes, self-actions and inactive users.
