# ResolveIT – How the system is built

> Press **Ctrl+Shift+V** in VS Code for the formatted view. Every link opens the file it names.

## The big picture

When someone opens a page, this is what happens:

```
 Browser (Chrome / Edge)                Your PC (the server)
 ───────────────────────                ─────────────────────────────────────────
  HTML  = page structure      ◄──────   Apache web server  (receives the request)
  CSS   = how it looks                     │
  JavaScript = live behaviour              ▼
                                         PHP  (builds the page, checks login/roles)
                                           │   SQL queries
                                           ▼
                                         Microsoft SQL Server  (stores everything)
```

| Language / tool | Job in ResolveIT | Where | Size |
|---|---|---|---|
| **HTML** | The structure of every page (forms, tables, buttons) | Written inside the PHP files in [public/](../public/) | – |
| **CSS** | Colours, layout, badges, phone layout | [public/assets/style.css](../public/assets/style.css) | 1 file, ~310 lines |
| **JavaScript** | Live countdowns, auto-refresh, split view, bulk select | [public/assets/app.js](../public/assets/app.js) | 1 file, ~280 lines |
| **PHP** | All the logic: login, roles, tickets, workflow, reports | [app/](../app/) and [public/](../public/) | 35 files, ~4,200 lines (PSR-12 formatted) |
| **SQL** | The database tables and queries | [database/](../database/) | 9 files, ~370 lines |
| **Apache** | The web server | [deploy/apache/cts4911.conf](../deploy/apache/cts4911.conf) | – |
| **SQL Server** | The database server | `WORKSTATION\JLB282`, database `TicketManagement` | 12 tables |
| **PowerShell** | Start scripts and the automated tests | [start-site.ps1](../start-site.ps1), [tests/check-local.ps1](../tests/check-local.ps1) | 4 files |

> **"Java" vs "JavaScript":** this project uses **JavaScript**, the language that runs inside web browsers. **Java** is a different language and is **not** used here. Say "JavaScript" in the write-up.

---

## 1. HTML – the structure

HTML describes *what* is on a page: headings, forms, tables, buttons. ResolveIT has no separate `.html` files; each page is a **PHP file that outputs HTML**, so the page can include live data from the database.

- **Shared layout:** [public/partials/header.php](../public/partials/header.php) (sidebar, top bar, search box) and [public/partials/footer.php](../public/partials/footer.php) are included by every page, so the navigation is written once.
- **A form:** [public/ticket-create.php](../public/ticket-create.php) – title, category dropdown, description, Submit button.
- **A table:** the ticket list is built by `ticket_table()` in [app/ui.php](../app/ui.php).
- **Accessibility:** labels on every input, `aria-` attributes, a "Skip to content" link, and the site works with the keyboard.

Example of HTML produced by PHP (simplified from `ticket-create.php`):
```php
<label for="title">Short title</label>
<input id="title" name="title" maxlength="150" value="<?= e($data['title']) ?>" required>
```
`e()` escapes the text so nobody can inject their own HTML or scripts (XSS protection).

---

## 2. CSS – the look

CSS controls *how* the page looks. Everything is in **one file**: [public/assets/style.css](../public/assets/style.css). No outside framework (no Bootstrap) – it is hand-written so the site loads nothing from other websites.

What it covers, top to bottom:

| Section | What it styles |
|---|---|
| **Colour tokens** (`:root { --primary … }`) | One place to change the colour scheme for the whole site |
| **App shell** | Dark sidebar, sticky top bar, main content area |
| **Cards & KPI tiles** | The white boxes and the dashboard number tiles |
| **Forms & buttons** | Inputs, dropdowns, primary/secondary/danger buttons |
| **Tables** | Ticket lists, hover highlight, sortable headers |
| **Badges** | Status colours (Open = blue, In Progress = amber, Resolved = green…), priority dots, SLA pills (red overdue, amber due soon, green met) |
| **Ticket page** | Details panel, conversation, history timeline, workflow progress |
| **Split view & search** | List-plus-pane layout, Advanced Search grid |
| **Responsive** (`@media`) | Phone layout: sidebar becomes a slide-out menu, columns hide |
| **Print** | Hides menus when printing a ticket |

Example – SLA badge colours:
```css
.sla-overdue  { background: var(--danger-soft);  color: var(--danger); }
.sla-due-soon { background: var(--warning-soft); color: var(--warning); }
.sla-met      { background: var(--success-soft); color: var(--success); }
```

---

## 3. JavaScript – live behaviour in the browser

JavaScript makes pages react **without reloading**. All of it is in [public/assets/app.js](../public/assets/app.js). Important design rule: **every page still works with JavaScript turned off** – JavaScript only makes it nicer.

| Feature | What it does |
|---|---|
| **SLA countdowns** | Updates "3h 12m left" every 30 seconds and changes the colour as the deadline nears |
| **Auto-refresh** | Every 30 s asks the server "did anything change?" (`?format=stamp`) and refreshes only the ticket list if it did |
| **Split view** | Clicking a row loads that ticket into the right-hand pane |
| **Bulk select** | "Select all" checkbox and "3 selected" counter |
| **Instant filters** | Changing a dropdown applies the filter immediately |
| **Confirmations** | "Deactivate this user?" pop-up before risky actions |
| **Character counters** | "120 / 5000" on long text boxes |
| **Mobile menu** | Opens/closes the sidebar on phones |

---

## 4. PHP – the brains (runs on the server)

PHP runs **on the server**, never in the browser. It checks who you are, reads/writes the database, enforces the rules, and sends back finished HTML.

### `app/` – shared logic (not reachable from the web)

| File | Purpose |
|---|---|
| [bootstrap.php](../app/bootstrap.php) | Loaded first by every page: settings, database, session, CSRF tokens |
| [config.php](../app/config.php) / [db.php](../app/db.php) | Database location and connection |
| [auth.php](../app/auth.php) | Login, lockout, roles (`require_role`), who can see which ticket |
| [validation.php](../app/validation.php) | Checks form input |
| [workflow.php](../app/workflow.php) | Open → Assigned → In Progress → Resolved → Closed rules |
| [tickets.php](../app/tickets.php) | Ticket search, sorting, auto-refresh check, CSV rows |
| [reports.php](../app/reports.php) | SLA calculations and the 8 reports |
| [audit.php](../app/audit.php) | Records every admin action |
| [ui.php](../app/ui.php) | Badges, ticket table, icons, dates |

### `public/` – the pages people visit (19 files)

| Who | Pages |
|---|---|
| Everyone | `login.php`, `logout.php`, `dashboard.php`, `tickets.php`, `ticket.php`, `ticket-create.php`, `search.php`, `kb.php`, `kb-article.php` |
| Technicians & admins | `ticket-edit.php` (Manage Ticket), `tickets-bulk.php`, `kb-edit.php` |
| Admins only | `users.php`, `user-edit.php`, `reports.php`, `settings.php` |

### Security built into the PHP

| Protection | How |
|---|---|
| Passwords | Stored only as one-way hashes (`password_hash` / `password_verify`) |
| SQL injection | Every query uses prepared statements with `?` placeholders |
| Cross-site scripting | All output goes through `e()` (HTML escaping) |
| Fake form submissions (CSRF) | Hidden token on every form, checked by `verify_csrf()` |
| Role checks | `require_login()` / `require_role()` on every page → 403 if not allowed |
| Password guessing | Lockout after 5 wrong passwords |
| Workflow | Server refuses to skip status steps, even if someone edits the form |

---

## 5. SQL & the database

**Microsoft SQL Server** (instance `WORKSTATION\JLB282`, database `TicketManagement`). Open it in **SSMS** to show the team.

- Full design: [database/schema.sql](../database/schema.sql) – **12 tables, 14 foreign keys**
- Changes added over time: [database/migrations/](../database/migrations/) `001`–`006`

| Table | Holds |
|---|---|
| `roles`, `users`, `organizations` | Accounts, their role, and their organization |
| `tickets` | Every ticket (title, description, status, priority, assignee, dates, SLA) |
| `statuses`, `priorities`, `categories` | The fixed lists (and SLA hours per priority) |
| `ticket_notes`, `ticket_history` | Conversation replies and the change log |
| `admin_audit_log`, `login_attempts` | Admin actions and sign-in attempts |
| `kb_articles` | Knowledge Base |

Handy queries for a demo:
```sql
SELECT email, password_hash FROM users;                         -- passwords are hashes
SELECT * FROM ticket_history ORDER BY id DESC;                   -- every status change
SELECT name, sla_hours FROM priorities ORDER BY sort_order;      -- SLA targets
```

---

## 6. Servers – what runs where

| Server | What it is | Where / how to start |
|---|---|---|
| **Apache 2.4** | The web server that answers browser requests and runs PHP | `C:\Users\draft\AppData\Local\CodexTicketSystem\Apache24` · settings in [deploy/apache/cts4911.conf](../deploy/apache/cts4911.conf) · start with [start-site.ps1](../start-site.ps1) |
| **PHP 8.3** | Runs the PHP code inside Apache, plus Microsoft's SQL Server driver | `C:\Users\draft\AppData\Local\CodexTicketSystem\php83` |
| **Microsoft SQL Server** | The database | Windows service `MSSQL$JLB282` (runs automatically) |
| **Cloudflare tunnel** | Gives teammates a public https link to this PC | [deploy/tunnel/start-tunnel.ps1](../deploy/tunnel/start-tunnel.ps1) – the link changes each time it starts |
| **Docker / Azure** *(prepared, not live)* | The same app packaged as an Apache container for an always-on cloud server | [deploy/docker/](../deploy/docker/) |

Site address on this PC: **http://127.0.0.1:8080**

---

## 7. Note for the team write-up

The team task list says **MySQL** and **Bootstrap**. What was actually built uses **Microsoft SQL Server**, a **hand-written stylesheet**, and **JavaScript** (not Java). The design-choices section should describe the real stack above.
