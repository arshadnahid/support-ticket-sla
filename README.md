# Support Ticket & SLA Management System

A Laravel REST API for support tickets with roles (Administrator, Support Agent, Customer),
JWT authentication and SLA tracking. Built for the PHP team assessment (`doc/Assessment for PHP team.pdf`).

**Status:** built module by module; each module is documented step by step in [`tec-doc/`](tec-doc/).

| Module | Assessment sections | Status | Doc |
|---|---|---|---|
| Auth and roles | (3, foundation) | Done | [`1.auth.md`](tec-doc/1.auth.md) |
| Tickets, ticket IDs, permissions | 1, 2, 3 | Done | [`2.tickets.md`](tec-doc/2.tickets.md) |
| Assignment, conversation, attachments, SLA, business hours, escalation, notifications, search | 4 to 12 | Not started | |

---

## 1. Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.3 or newer** (developed on 8.3.18) | Extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `json`, `tokenizer`, `xml`, `curl` |
| Composer | **2.x** (developed on 2.8.4) | |
| MySQL | **8.0 or newer** (developed on 8.4.3) | MariaDB 10.6+ should also work |
| Bruno | Latest | API client for the ready-made requests, see section 5 |
| Node.js | 20+ (optional) | Only needed for `npm run build`; the API itself doesn't need it |

Laravel 13 and `tymon/jwt-auth` are installed by Composer.

Check what you have:

```bash
php -v
composer -V
mysql --version
```

> **Laragon (Windows):** this project uses Laragon. Start Apache and MySQL in Laragon. A project
> folder named `support-ticket-sla` is served automatically at `http://support-ticket-sla.test`.

---

## 2. Setup

```bash
# 1. Install PHP packages
composer install

# 2. Create your environment file
cp .env.example .env        # Windows PowerShell: copy .env.example .env

# 3. Generate the app key and the JWT secret
php artisan key:generate
php artisan jwt:secret

# 4. Create the database (see section 3), then create tables and demo users
php artisan migrate --seed

# 5. Generate the API documentation
php artisan l5-swagger:generate
```

## 3. Environment (`.env`)

Edit these values. **Never commit real secrets.**

```dotenv
APP_URL=http://support-ticket-sla.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=support_ticket_sla_db
DB_USERNAME=root
DB_PASSWORD=

AUTH_GUARD=api
JWT_SECRET=            # filled in by: php artisan jwt:secret
```

Create the database once (MySQL client, phpMyAdmin or HeidiSQL):

```sql
CREATE DATABASE support_ticket_sla_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run the app: with Laragon it is already at `APP_URL`. Without Laragon, run `php artisan serve`
and set `APP_URL=http://localhost:8000`.

## 4. Demo accounts

Created by `php artisan migrate --seed`. Password for all of them: `12345678` (local use only).

| Role | Email |
|---|---|
| Admin | `admin@example.com`, `nahid@gmail.com` |
| Agent | `agent1@example.com`, `agent2@example.com`, `agent3@example.com` (marked unavailable) |
| Customer | `customer1@example.com`, `customer2@example.com` |

Reset everything: `php artisan migrate:fresh --seed`

---

## 5. Testing the API

### Option A: Swagger UI (in the browser)

Open **http://support-ticket-sla.test/api/documentation**

1. Open `POST /api/auth/login`, click **Try it out**, use a demo account, click **Execute**.
2. Copy `data.access_token` from the response.
3. Click **Authorize** at the top, paste the token (Swagger adds `Bearer ` for you), click **Authorize**.
4. Now try `GET /api/auth/me` and `POST /api/auth/logout`.

After changing any `#[OA\...]` attribute in the code, run `php artisan l5-swagger:generate`.

### Option B: Bruno (desktop API client)

[Bruno](https://www.usebruno.com) is a free, open-source API client. The requests are stored as
plain files in this repository (`bruno/support-ticket-sla/`), so they are versioned with the code.

**Install:** download it from <https://www.usebruno.com/downloads> (or `winget install Bruno.Bruno`).

**Open the collection**

1. Start Bruno, click **Open Collection**.
2. Choose the folder `bruno/support-ticket-sla` in this project.
3. In the top-right environment dropdown, select **localhost**.
   It sets `baseUrl = http://support-ticket-sla.test/api`. If your URL differs (for example
   `http://localhost:8000/api`), edit the environment: **Environments → localhost → baseUrl**.

**Use it**

1. Open **Auth → login** and press **Send** (`Ctrl+Enter`). The body uses `nahid@gmail.com` / `12345678`;
   change it to try other roles.
2. On success a script **saves the token automatically** into the `accessToken` environment variable.
3. Open **Auth → me** and press **Send**. It works because the **Auth** folder sends
   `Authorization: Bearer {{accessToken}}` on every request (`auth: inherit`).
4. **Auth → refresh** returns a new token and saves it. **Auth → logout** invalidates the token and clears the variable.
5. **Auth → register** creates a new customer with a random email and saves its token.

**Try the ticket flow** (folder **Ticket**)

1. Log in as a customer: in **Auth → login** change the email to `customer1@example.com`, then **Send**.
2. **Ticket → categories** to see the category ids, then **Ticket → create ticket**. It saves the new
   number (for example `TKT-2026-000031`) into the `ticketNumber` variable.
3. **show ticket**, **update ticket** and the list requests now use that number automatically.
4. Log in as `admin@example.com` (**Auth → login** again) and run **change status**, **resolve ticket** or
   **close ticket** on the same number. Log in as another customer and **show ticket** to see the `403`.

**Auth requests**

| Request | Method | URL | Needs token |
|---|---|---|---|
| register | POST | `{{baseUrl}}/auth/register` | No |
| login | POST | `{{baseUrl}}/auth/login` | No |
| refresh | POST | `{{baseUrl}}/auth/refresh` | Yes (may be expired, within the refresh window) |
| me | GET | `{{baseUrl}}/auth/me` | Yes |
| logout | POST | `{{baseUrl}}/auth/logout` | Yes |

**Ticket requests** (all need a token)

| Request | Method | URL |
|---|---|---|
| categories | GET | `{{baseUrl}}/categories` |
| create ticket | POST | `{{baseUrl}}/tickets` |
| list tickets | GET | `{{baseUrl}}/tickets?page=1&per_page=15` |
| list active tickets | GET | `{{baseUrl}}/tickets/active` |
| list resolved-closed tickets | GET | `{{baseUrl}}/tickets/resolved` |
| show ticket | GET | `{{baseUrl}}/tickets/{{ticketNumber}}` |
| update ticket | PATCH | `{{baseUrl}}/tickets/{{ticketNumber}}` |
| change status | POST | `{{baseUrl}}/tickets/{{ticketNumber}}/status` |
| resolve ticket | POST | `{{baseUrl}}/tickets/{{ticketNumber}}/resolve` |
| close ticket | POST | `{{baseUrl}}/tickets/{{ticketNumber}}/close` |

**Tips**
- Always send the `Accept: application/json` header (the requests already do).
- A `401` on `me` means you haven't logged in yet, or the token expired. Run **login** again.
- A `429` means too many login attempts (limit: 5 per minute per email and IP). Wait a minute.
- A `403` on a ticket means your role may not do that (for example a customer viewing someone
  else's ticket). A `409` means the ticket can't move to that status (see `tec-doc/2.tickets.md`, Step 10).
- An empty ticket list returns `404` with `success: false` (existing response helper; see `tec-doc/2.tickets.md`, Step 15).
- To see which variable holds the token: **Environments → localhost → accessToken**.
- New requests you add are saved as files in the collection folder. Commit them with your code.

---

## 6. Run the tests

```bash
php artisan test
```

Tests run on in-memory SQLite (see `phpunit.xml`), so they never touch your MySQL data.

## 7. Queue and scheduler

Not required for the Auth module. Later modules (email notifications, SLA escalation, CSV import)
will need them, and this section will list the commands (`php artisan queue:work`,
`php artisan schedule:work`) when they are added.

## 8. Project structure

```
app/
  Enums/                                 UserRole, TicketPriority, TicketStatus, TicketListScope
  Models/                                User, Ticket, Category
  Policies/TicketPolicy.php              who may view / update / change status of a ticket
  Http/Controllers/Api/v1/Auth/          AuthController
  Http/Controllers/Api/v1/Ticket/        TicketController, CategoryController (HTTP only)
  Http/Middleware/                       EnsureUserIsActive, EnsureUserHasRole
  Http/Requests/Api/{Auth,Ticket}/       validation
  Http/Resources/{Auth,Ticket}/          JSON output
  Services/Auth/                         AuthService
  Services/Ticket/                       TicketService, TicketNumberGenerator
  Repositories/{Auth,Ticket,Category}/   Interface + implementation
  DTOs/{Auth,Ticket}/                    data passed between layers
  Events/Ticket/                         TicketCreated, TicketUpdated, TicketStatusChanged
  Exceptions/ApiExceptionRenderer.php    all API errors in one JSON format
  OpenApi/                               Swagger definitions
config/tickets.php                       ticket number prefix/padding, pagination
bruno/support-ticket-sla/                Bruno collection (Auth, Ticket)
tec-doc/                                 step-by-step technical docs
```

## 9. API response format

Every response, success or error, has the same shape:

```json
{ "data": {}, "message": "Login successful", "errors": [], "success": true, "status": "success" }
```

| Status | Meaning |
|---|---|
| 401 | Missing/invalid token, or wrong email or password |
| 403 | Account deactivated, or role not allowed |
| 404 | Ticket or route not found |
| 409 | The ticket can't move to that status (for example reopening a closed ticket) |
| 422 | Validation failed (details in `errors`) |
| 429 | Too many requests (see the `Retry-After` header) |

## 10. Architecture decisions

The reasons behind each decision are in `tec-doc/`:

- [`1.auth.md`](tec-doc/1.auth.md): roles as an enum, JWT, layering, middleware, rate limiting, error handling.
- [`2.tickets.md`](tec-doc/2.tickets.md): ticket number generation and its concurrency test, the permission matrix,
  status flow, indexes, events, and the list of assumptions and known limits.
