# ResolveIT – IT Ticket Management System (CTS 4911)

ResolveIT is an IT help-desk ticketing system built for the CTS 4911 capstone. Employees submit tickets,
technicians work them through a fixed workflow, and administrators manage users, settings, and reports.

It is plain **PHP 8.3** on **Microsoft SQL Server**, served by **Apache 2.4**. There is no framework and no
Composer/npm step: every page in `public/` is a PHP file that outputs HTML.

| Looking for… | Read |
|---|---|
| Deployment, test procedures, frozen scope and change log | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| How the pieces fit together, language by language | [docs/TECH-STACK.md](docs/TECH-STACK.md) |
| Security design and threat model | [docs/THREAT-MODEL.md](docs/THREAT-MODEL.md) |
| QA approach and results | [docs/QA-TESTING.md](docs/QA-TESTING.md) |
| Manual test scripts (one per role/flow) | [tests/manual-test-cases.md](tests/manual-test-cases.md) |
| Walkthrough for a live demo | [docs/DEMO-SCRIPT.md](docs/DEMO-SCRIPT.md) |
| Design decisions and lessons learned | [docs/ENGINEERING-NOTES.md](docs/ENGINEERING-NOTES.md), [docs/UI-REDESIGN.md](docs/UI-REDESIGN.md) |

---

## Features

**By role**

- **Employees** sign in, create tickets, reply on them, and see only their own tickets.
- **Technicians** see all tickets, assign them, change priority and status, add technical notes, and record
  a resolution.
- **Administrators** have every technician permission, plus:
  - **Manage Users**: create, edit, deactivate/reactivate, and reset passwords. Users are deactivated,
    never deleted, so ticket history stays intact.
  - **Settings**: categories, priorities and their SLA targets, the status workflow, and an audit log of
    every admin action.
  - **Reports**: 8 reports over a date range (Last 7/30/90 days, This month, or custom), SLA compliance per
    priority, unresolved tickets ordered by due time, and CSV export of every report.

**Help-desk tools**

- Dashboard with SLA tiles; ticket list with live SLA countdowns, sortable columns, filters, auto-refresh,
  a split-screen view, bulk updates, and CSV export.
- Ticket page with a conversation thread, workflow progress bar, and full change history.
- *Waiting on requester* and *Past due* views, due dates, first-response and close times.
- Organizations, advanced search, and a searchable knowledge base.
- SLA targets per priority (defaults: Critical 4 h, High 8 h, Medium 24 h, Low 72 h) with an **Overdue**
  flag on the ticket list.

**Ticket workflow** – enforced on the server in [app/workflow.php](app/workflow.php), so editing the form
cannot skip a step:

```text
Open → Assigned → In Progress → Resolved → Closed
                       ▲            │
                       └─ reopen ───┘          (Closed is final)
```

**Security**

- Password hashes only (`password_hash` / `password_verify`); prepared statements for every query.
- CSRF token on every POST, output escaping through `e()`, server-side validation, role checks on every page.
- Sign-in lockout: 5 wrong passwords for one email, or 20 from one IP address, within 15 minutes.
- The session's user is re-checked against the database on every request, so a role change, deactivation,
  or password reset takes effect immediately.

---

## Project layout

```text
ticket-system/
├── app/                 Shared PHP logic (config, DB, auth, workflow, reports, UI helpers) – never web-reachable
├── database/
│   ├── schema.sql       Full schema for a new database (12 tables, reference data)
│   ├── migrations/      001–006: upgrades for databases built from an older schema.sql
│   └── team-*.sql       Read-only walkthrough queries for team demos in SSMS
├── deploy/
│   ├── apache/          Apache site config and installer for this PC
│   ├── docker/          Two-container stack (site + SQL Server 2022)
│   └── tunnel/          Cloudflare quick tunnel for sharing the local site
├── docs/                Project documentation (see the table above)
├── public/              Apache document root: every page, partials/, and assets/ (style.css, app.js)
├── runtime/php.ini      PHP settings that load the SQL Server driver
├── scripts/             CLI helpers: create_user.php, Docker and Azure database setup
├── tests/               Automated checks (check-local.ps1) and manual test cases
├── phpcs.xml            PSR-12 lint rules
└── start-site.ps1       Starts the local site
```

Apache's document root points at `public/` only. Everything else stays outside it.

---

## Quick start (this PC)

**Requirements:** PHP 8.3 with Microsoft's `pdo_sqlsrv` driver (installed under
`C:\Users\draft\AppData\Local\CodexTicketSystem\`), SQL Server instance `WORKSTATION\JLB282` with a
`TicketManagement` database, and `sqlcmd` for migrations.

1. **Configure.** Copy `app/config.example.php` to `app/config.php`. It is git-ignored and uses Windows
   Authentication, so it holds no password.
2. **Create or upgrade the database.**
   - New database: run `database/schema.sql` in SSMS or `sqlcmd`.
   - Database built from an older `schema.sql`: run the migrations in order. Each is safe to run again.

     ```powershell
     foreach ($f in Get-ChildItem database\migrations\*.sql | Sort-Object Name) {
         sqlcmd -S "WORKSTATION\JLB282" -E -C -i $f.FullName
     }
     ```

3. **Create the first administrator.** This is CLI-only by design; there is no web sign-up page.
   Passwords must be at least 12 characters. `-c runtime\php.ini` is required, or PHP reports
   `could not find driver`.

   ```powershell
   C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe -c runtime\php.ini scripts\create_user.php `
       admin@example.edu '<password, 12+ chars>' administrator "System Admin"
   ```

4. **Start the site** and open <http://127.0.0.1:8080>:

   ```powershell
   .\start-site.ps1          # Apache
   .\start-site.ps1 -Dev     # PHP's built-in server instead
   ```

5. Sign in, then create technician and employee accounts from **Manage Users**.

---

## Environments

`APP_ENV` selects the configuration in [app/bootstrap.php](app/bootstrap.php):

| `APP_ENV` | Where | Config | Sign-in | Database login |
|---|---|---|---|---|
| *(unset)* | This PC, Apache on `127.0.0.1:8080` | `app/config.php` | Email + password | Windows Authentication |
| `docker` | Docker Desktop, `127.0.0.1:8081` | `app/config.azure.php` + env vars | Email + password | `resolveit_app` (least privilege) |
| `azure` | Azure App Service (prepared, **not deployed**) | `app/config.azure.php` + env vars | Microsoft sign-in (`app/azure-auth.php`) | From env vars |

### Apache on this PC

- Apache 2.4 (Apache Lounge Win64) with PHP 8.3 as a module, in
  `C:\Users\draft\AppData\Local\CodexTicketSystem\Apache24`. It runs as your Windows user, not as a
  service, so PHP connects to SQL Server with Windows Authentication exactly as SSMS does. No admin rights
  needed.
- Settings are in [deploy/apache/cts4911.conf](deploy/apache/cts4911.conf): only `public/` is served,
  dotfiles and directory listings are blocked, PHP errors are logged instead of displayed, and security
  headers are sent.
- It listens on `127.0.0.1:8080` (this PC only). To open it to your network, change that to `Listen 8080`
  and allow the port in Windows Firewall.
- New PC: extract the Apache Lounge zip, update the two paths at the top of `cts4911.conf`, then run
  `deploy\apache\install-apache.ps1`.
- Logs: `Apache24\logs\cts4911_error.log`, `cts4911_access.log`, `php_errors.log`.

### Sharing with the team (Cloudflare tunnel)

1. Start the site with `.\start-site.ps1`.
2. In a second window run `deploy\tunnel\start-tunnel.ps1`. It prints a public
   `https://<random-words>.trycloudflare.com` link to send to the team.
3. Create each teammate's account in **Manage Users** and give them their temporary password privately.

The link works only while this PC is on and both windows are open, and it changes on every restart. No
Cloudflare account, router, or firewall change is needed: `cloudflared` connects out to Cloudflare, which
forwards HTTPS visitors to Apache on 127.0.0.1.

### Docker

[deploy/docker/docker-compose.yml](deploy/docker/docker-compose.yml) runs the site (Apache + PHP 8.3) and
SQL Server 2022 Developer Edition. It has its own database, separate from the Windows install.

```powershell
powershell -ExecutionPolicy Bypass -File deploy\docker\start-docker.ps1         # start (first run downloads ~1.6 GB)
powershell -ExecutionPolicy Bypass -File deploy\docker\start-docker.ps1 -Stop   # stop; the database is kept
```

- The image bakes in `app/` and `public/` at build time. After any code change, the start script rebuilds
  the image (`docker compose up -d --build`); otherwise port 8081 keeps serving the old code.
- The first run creates `deploy\docker\.env` with random passwords and writes the admin sign-in to
  `deploy\docker\DOCKER_LOGIN.txt`. Both are git-ignored.
- On every start, [scripts/docker-init.php](scripts/docker-init.php) creates the database and the
  `resolveit_app` login (the site never uses `sa`), applies the schema and upgrades, and creates the admin
  if it is missing.
- The SQL Server container publishes no port; only the web container can reach it.
- Logs: `docker compose -f deploy\docker\docker-compose.yml logs web`. To wipe the Docker database, add `-v`
  to `docker compose ... down`.

---

## Testing and code quality

**Automated checks** run against the local site while it is running (Windows PowerShell 5.1 or 7):

```powershell
.\tests\check-local.ps1 -AdminPassword '<admin password>'    # add -KeepData to keep the QA fixtures
```

The script creates its own disposable QA accounts and tickets and removes them afterwards.

**Manual tests**: one script per role and flow in [tests/manual-test-cases.md](tests/manual-test-cases.md).

**Lint (PSR-12, kept at 0 warnings)** – run from the repo root; rules are in [phpcs.xml](phpcs.xml):

```powershell
C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe C:\Users\draft\AppData\Local\CodexTicketSystem\phpcs\phpcs.phar
```

`phpcbf.phar` in the same folder fixes most formatting automatically. Lines stay under 120 characters.
Pages in `public/` use one-line alternative syntax (`<?php if (...) : ?>`) for readable templates, so two
brace-placement sniffs are excluded there.

**Syntax check one file:** `php.exe -l path\to\file.php`

---

## Working on the code

- **Request flow.** Every page starts with `require __DIR__ . '/../app/bootstrap.php'`, which loads config,
  then `http.php` → `db.php` → `validation.php` → `auth.php` → `workflow.php` → `audit.php` →
  `reports.php` → `tickets.php` → `ui.php`, starts the session, and re-checks the user.
- **Reuse before writing.** Shared table, badge, and icon builders live in [app/ui.php](app/ui.php);
  escaping, redirects, flash messages, and CSRF are in [app/http.php](app/http.php).
- **Styling** is one token-driven file, [public/assets/style.css](public/assets/style.css). Colors, radii,
  and shadows are CSS custom properties in `:root`, so a site-wide change is usually a one-line edit there.
  Asset links are cache-busted by file time; if a change doesn't show, refresh the browser tab first.
- **New accounts for testing:** use `scripts/create_user.php`, never real credentials.

### Learning path

1. `database/schema.sql`: find the primary keys, foreign keys, and fixed reference tables.
2. `app/db.php`: PDO connects to SQL Server with real prepared statements and throws on errors.
3. `app/auth.php`: follow sign-in, session rotation, lockout, role checks, and ownership checks.
4. Sign in as each role and work through `tests/manual-test-cases.md`.
5. In SSMS, watch `ticket_history` change as you assign tickets and move them through the workflow.

---

## Known limits

This is a classroom project, not a production service. Before real public use it would still need:

- Always-on hosting with a real HTTPS certificate (the Azure container is built but not deployed; the
  Cloudflare tunnel is interim).
- Self-service password reset and email verification (administrators reset passwords today).
- Email notifications, centralized logging, and a documented backup/restore schedule.
