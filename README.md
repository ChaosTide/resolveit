# ResolveIT – IT Ticket Management System (CTS 4911)

This beginner-friendly PHP/Microsoft SQL Server prototype implements the capstone workflow described in the project source document. SQL Server is used because Jordan requested the local SSMS installation for hands-on testing.

**Deploying or checking scope?** See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md): the deployment guide, test procedures, and the frozen scope with its change log.

## 1. What the system does

- A modern help desk interface: dashboard with SLA tiles, searchable and filterable ticket list with live SLA countdowns, and a ticket page with a conversation thread, workflow progress, and history.
- Help desk features like a commercial help desk: Waiting on requester and Past due views, due dates, first-response and close times, organizations, a searchable knowledge base, bulk updates, sortable columns, automatic list refresh, a split-screen view, advanced search, and CSV export.
- Employees can sign in, create tickets, reply on them, and view only their own tickets.
- Technicians can view all tickets, assign them, change priority/status, add technical notes, and record a resolution.
- Administrators have technician permissions and can create, edit, deactivate, and reset passwords for users.
- Administrators also get **Reports** and **Settings** (categories, priorities and their SLA targets, the status workflow, and an audit log of every admin action).
- **Reports (Phase 4 analytics):** 8 reports filtered by a date range (with Last 7 days / Last 30 days / This month / Last 90 days shortcuts), SLA compliance per priority, a list of unresolved tickets ordered by due time, and CSV export of every report plus one row per ticket. Each priority has an SLA target in hours (defaults: Critical 4, High 8, Medium 24, Low 72). Technicians see an **Overdue** flag on the ticket list and the due time on each ticket.
- Ticket status follows: Open -> Assigned -> In Progress -> Resolved -> Closed. This is enforced on the server (`app/workflow.php`): a ticket moves one step at a time, a Resolved ticket may be reopened to In Progress, and Closed is final.
- Security controls include password hashing, prepared statements, sessions, CSRF tokens, server-side validation, output escaping, and role checks.

## 2. Folder map

```text
ticket-system/
|-- app/                 PHP configuration, database, authentication, and validation
|-- database/            Database schema and fixed reference records
|-- public/              Apache document root and browser-facing pages
|   `-- assets/          CSS
|-- scripts/             Command-line helper for creating the first administrator
`-- tests/               Manual security and workflow test cases
```

Keep `app`, `database`, `scripts`, and `tests` outside Apache's public document root. Point Apache at the `public` folder.

## 3. Local setup with XAMPP and SQL Server

1. PHP 8.3 and Microsoft's SQL Server driver are installed locally under your Windows profile.
2. SQL Server runs as `WORKSTATION\JLB282`; the database is named `TicketManagement`.
3. `app/config.php` already points to that instance using Windows Authentication.
4. Run `start-site.ps1`, then open `http://127.0.0.1:8080`. It serves the site with Apache (see below); `start-site.ps1 -Dev` uses PHP's built-in test server instead.
5. In a terminal, create the first administrator:

   ```powershell
   C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe -c runtime\php.ini scripts\create_user.php admin@example.edu Administrator123! administrator "System Admin"
   ```

6. Open `http://127.0.0.1:8080` and sign in.
7. Create technician and employee users from **Manage Users**.

8. Databases created from an older `schema.sql` need the migrations, in order (each is safe to run twice):

   ```powershell
   sqlcmd -S "WORKSTATION\JLB282" -E -C -i database\migrations\001_admin_audit_log.sql
   sqlcmd -S "WORKSTATION\JLB282" -E -C -i database\migrations\002_priority_sla.sql
   ```

9. With the site running, run the automated checks (works in Windows PowerShell 5.1 and PowerShell 7):

   ```powershell
   .\tests\check-local.ps1 -AdminPassword '<admin password>'
   ```

### Code style check (PSR-12)

All PHP follows the PSR-12 coding standard; the rules are in `phpcs.xml`. Check it with PHP_CodeSniffer:

```powershell
C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe C:\Users\draft\AppData\Local\CodexTicketSystem\phpcs\phpcs.phar
```

`phpcbf.phar` in the same folder fixes most formatting problems automatically. Lines are kept under 120 characters.

### Apache deployment

The site is deployed on Apache 2.4 (Apache Lounge Win64 build) with PHP 8.3 loaded as an Apache module.

- Apache lives in `C:\Users\draft\AppData\Local\CodexTicketSystem\Apache24` and runs as your Windows user, not as a service, so PHP signs in to SQL Server with Windows Authentication exactly as SSMS does. No admin rights are needed.
- All site settings are in `deploy/apache/cts4911.conf`: only `public/` is served, dotfiles and directory listings are blocked, PHP errors are logged instead of displayed, and security headers are sent.
- It listens on `127.0.0.1:8080`, so only this PC can reach it. To share it on your network, change that line to `Listen 8080` and allow the port in Windows Firewall.
- To set up Apache on another PC: extract the Apache Lounge zip, update the two paths at the top of `cts4911.conf`, then run `deploy\apache\install-apache.ps1`.
- Logs: `Apache24\logs\cts4911_error.log`, `cts4911_access.log`, and `php_errors.log`.

### Sharing the Apache site with the team (Cloudflare tunnel)

1. Run `start-site.ps1` (Apache).
2. In a second window run `deploy\tunnel\start-tunnel.ps1`. It prints a public `https://<random-words>.trycloudflare.com` link; send that to the team.
3. Create each teammate's account in **Manage Users** and give them their temporary password privately.

The link only works while this PC is on and both windows are open, and it changes every time the tunnel restarts. No Cloudflare account, router, or firewall change is needed: `cloudflared` connects out to Cloudflare, which forwards HTTPS visitors to Apache on 127.0.0.1. Five wrong passwords lock an account for 15 minutes.

For an always-on server, `deploy/docker` builds the same site as an Apache container for Azure App Service.

### Running the whole system in Docker

`deploy/docker/docker-compose.yml` runs two containers: the ResolveIT site (Apache + PHP 8.3, `APP_ENV=docker`) and Microsoft SQL Server 2022 Developer Edition. It is separate from the Windows install above and has its own database.

```powershell
powershell -ExecutionPolicy Bypass -File deploy\docker\start-docker.ps1         # start (first run downloads ~1.6 GB)
powershell -ExecutionPolicy Bypass -File deploy\docker\start-docker.ps1 -Stop   # stop; the database is kept
```

- Site: `http://127.0.0.1:8081` (this PC only). The SQL Server container publishes no port, so only the web container can reach it.
- First run creates `deploy\docker\.env` with random passwords and writes the admin sign-in to `deploy\docker\DOCKER_LOGIN.txt`; both are git-ignored.
- On every start `scripts/docker-init.php` creates the database and a least-privilege `resolveit_app` login (the site never uses the SQL `sa` account), applies the schema and upgrades, and creates the admin if missing.
- Logs: `docker compose -f deploy\docker\docker-compose.yml logs web`. To wipe the Docker database and start fresh, add `-v` to `docker compose ... down`.

## 4. Learning path for Jordan

1. Read `database/schema.sql`: identify primary keys, foreign keys, and fixed reference tables.
2. Read `app/db.php`: note that PDO connects to SQL Server using Windows Authentication, uses real prepared statements, and throws database errors.
3. Read `app/auth.php`: follow sign-in, session rotation, role checks, and ownership checks.
4. Sign in as each role and complete the tests in `tests/manual-test-cases.md`.
5. Inspect `ticket_history` in SSMS after assignment and status changes.

## 5. Important prototype limits

This is a classroom prototype. Before public deployment, add HTTPS, a least-privilege SQL Server service login, rate limiting, password reset/email verification, centralized logging, automated tests, and a documented backup/restore schedule.
