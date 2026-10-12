# Database and Performance

How the database is laid out so the application keeps working as tickets, replies, attachments, activity records and notifications grow, and what is deliberately left for later.

The design is reasoned and checked at small scale. It has not been load-tested with millions of rows.

## 1. Structure and relationships

```
users 1──* tickets (created_by)            restrict on delete
users 1──* tickets (assigned_to, nullable)  null on delete
categories 1──* tickets                     restrict on delete
tickets 1──* ticket_replies                 cascade
tickets 1──* ticket_attachments             cascade
ticket_replies 1──* ticket_attachments      cascade (reply_id nullable: file on the ticket itself)
tickets 1──* ticket_activities              cascade
users 1──* ticket_replies / ticket_attachments (uploaded_by) / ticket_activities (nullable = the system)
notifications  -> any notifiable (here: users), Laravel's standard table
ticket_sequences  one row per year, the counter behind TKT-2026-000001
```

Choices worth knowing:

- **Real foreign keys everywhere.** Users and categories cannot be deleted while tickets point at them (restrict); deleting a ticket removes its replies, files and history (cascade); deleting an agent just unassigns their tickets (null on delete).
- **Status and priority are short strings, not database enums.** They are validated by PHP enums (`TicketStatus`, `TicketPriority`), so adding a value never needs a table alteration.
- **SLA state is stored, not computed.** `due_at` and `sla_warning_at` are written once, so "all breached tickets" is a plain indexed range query and reading a ticket never runs business-hours maths (see `docs/SLA.md`).
- **Ticket numbers come from `ticket_sequences`**, one locked row per year, so numbers are unique and gap-free without scanning `tickets` for the highest value.
- **`ticket_activities` is a separate append-only table** with a JSON `properties` column, so the history can grow without widening `tickets`.
- **Attachments store only metadata** (random path, sanitised original name, detected mime type, size). The files live on a private disk, never in the database.

## 2. Indexes and the query each one serves

| Table | Index | Serves |
|---|---|---|
| tickets | `number` (unique) | opening a ticket by number (the route key), the `?number=` filter, uniqueness |
| tickets | `(created_by, status)` | a customer's tickets filtered by status, customer dashboard counts |
| tickets | `(created_by, created_at)` | a customer's list, newest first, already in order (no sort step) |
| tickets | `(assigned_to, status)` | an agent's queue, workload for automatic assignment, the unassigned queue (`assigned_to IS NULL`) |
| tickets | `(status, due_at)` | active list by deadline, `sla=breached` filter, the escalation scan |
| tickets | `(status, sla_warning_at)` | `sla=approaching` filter, the escalation scan |
| tickets | `(status, priority)` | tickets by priority on the dashboard |
| tickets | `created_at` | newest-first list and date filters |
| tickets | foreign keys: `category_id`, `created_by`, `assigned_to` | joins, category filter |
| ticket_replies | `(ticket_id, is_internal, id)` | a ticket's conversation, with or without internal notes, already in id order |
| ticket_attachments | `(ticket_id, reply_id)`, `uuid` (unique) | files of a ticket or of one reply, download by uuid |
| ticket_activities | `(ticket_id, id)` | a ticket's timeline in order |
| notifications | `(notifiable_type, notifiable_id)` | a user's notifications (Laravel's default) |
| users | `email` (unique), `(role, is_active, is_available)` | login, and picking available agents for assignment |

Column order follows the rule "equality columns first, then the sort or range column", so one index can both filter and order.

## 3. Query efficiency

- **Aggregates in the database.** The dashboard counts everything in one `SUM(CASE ...)` query plus one query each for priority and agent, instead of loading tickets into PHP.
- **Selected columns.** Related users and categories are loaded with only the columns shown (`creator:id,name,email`).
- **Row locks where races matter.** Status changes, edits, assignment and escalation re-read the ticket with `SELECT ... FOR UPDATE` inside a transaction, so two simultaneous requests cannot both win.
- **Batches.** The scheduled sweeps (escalation, auto-assignment, recalculation) work in bounded batches (`sla.escalation.batch`, `tickets.assignment.batch`, `chunkById`), so one run never loads the whole table.
- **Visibility in SQL.** What a user may see is a query scope (`Ticket::visibleTo`), so permission filtering happens in the `WHERE` clause and never by loading everything and filtering in PHP.
- **Keyword search escapes `%` and `_`**, so a user cannot turn a search into a slow wildcard pattern.

## 4. Pagination

- Every list endpoint is paginated: default 15 per page, hard maximum 100 (`config/tickets.php`).
- Replies are paginated too. Ordering always ends with `id`, so pages are stable when several rows share a timestamp.
- Page links keep the active filters (`withQueryString`).

## 5. N+1 handling

- Lists eager-load the relations the response needs, in one extra query each (`category`, `creator`, `assignee`; replies load `author` and `attachments`; attachments load `uploader`).
- API resources use `whenLoaded`, so a relation that was not loaded is left out instead of triggering a query.
- **Measured:** the query count of the list, show, replies and dashboard endpoints stays the same with 3 tickets or 30 (5, 4, 5 and 3 queries).
- **Guard:** `Model::preventLazyLoading()` is on outside production (`AppServiceProvider`), so a missing `->with()` that would cause an N+1 throws during development and in tests. It is off in production, where a miss would only cost queries and must never break a request.

## 6. Eloquent usage

- Query logic lives in model scopes (`visibleTo`, `active`, `finished`, `inSlaState`) and repositories, not in controllers. The SLA state filter mirrors `SlaService::stateOf()` so the list and the single-ticket view agree.
- `toBase()` is used for aggregate queries so no model objects are built for numbers.
- Casts (enums, dates) and `#[Fillable]` keep mass assignment explicit.

## 7. Known limits and what to do about them

These are not needed at the current size and each trades something away, so they are documented rather than built.

| Limit | Why it exists | If it becomes a problem |
|---|---|---|
| Active list sorted by deadline sorts all active tickets (`status IN (...)` across several statuses cannot be served in index order, and `due_at IS NULL` ordering is an expression) | Active means three statuses | A stored generated column holding the deadline for active tickets only (null otherwise), with an index on it |
| Keyword search is `LIKE '%word%'` on number, subject and description, so it scans | Substring matching, simple and predictable | A FULLTEXT index on subject and description (matches whole words instead of substrings) and a prefix match on `number` |
| `paginate()` runs a `COUNT(*)` per page | Gives total and page count | `simplePaginate` or cursor pagination (drops totals from the response) |
| Dashboard sums scan all tickets visible to the user (all of them for an admin) | Always exact | Cache the result for 30 to 60 seconds |
| `notifications` has no index on read state | Nothing in the API reads notifications yet | Add `(notifiable_type, notifiable_id, read_at)` when an inbox endpoint is built |
| No retention for `ticket_activities` and `notifications` | History is kept in full | A scheduled prune, or partition by month |
| The attachment list of one ticket is not paginated | Bounded by the per-upload file limit | Paginate it like replies |
