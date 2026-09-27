# CTS 4911 IT Ticket Management System

This beginner-friendly PHP/Microsoft SQL Server prototype implements the capstone workflow described in the project source document. SQL Server is used because Jordan requested the local SSMS installation for hands-on testing.

## 1. What the system does

- Employees can sign in, create tickets, and view only their own tickets.
- Technicians can view all tickets, assign them, change priority/status, add technical notes, and record a resolution.
- Administrators have technician permissions and can create, edit, deactivate, and reset passwords for users.
- Administrators also get **Reports** (7 reports on ticket volume, workload, and resolution time) and **Settings** (categories, priorities, the status workflow, and an audit log of every admin action).
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
4. Run `start-site.ps1`, then open `http://127.0.0.1:8080`.
5. In a terminal, create the first administrator:

   ```powershell
   C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe -c runtime\php.ini scripts\create_user.php admin@example.edu Administrator123! administrator "System Admin"
   ```

6. Open `http://127.0.0.1:8080` and sign in.
7. Create technician and employee users from **Manage Users**.

8. Existing databases created before the audit log was added need one migration (safe to run twice):

   ```powershell
   sqlcmd -S "WORKSTATION\JLB282" -E -C -i database\migrations\001_admin_audit_log.sql
   ```

9. With the site running, run the automated checks (works in Windows PowerShell 5.1 and PowerShell 7):

   ```powershell
   .\tests\check-local.ps1 -AdminPassword '<admin password>'
   ```

The included PHP server is for local classroom testing. Apache or IIS can be configured later for a deployment demonstration.

## 4. Learning path for Jordan

1. Read `database/schema.sql`: identify primary keys, foreign keys, and fixed reference tables.
2. Read `app/db.php`: note that PDO connects to SQL Server using Windows Authentication, uses real prepared statements, and throws database errors.
3. Read `app/auth.php`: follow sign-in, session rotation, role checks, and ownership checks.
4. Sign in as each role and complete the tests in `tests/manual-test-cases.md`.
5. Inspect `ticket_history` in SSMS after assignment and status changes.

## 5. Important prototype limits

This is a classroom prototype. Before public deployment, add HTTPS, a least-privilege SQL Server service login, rate limiting, password reset/email verification, centralized logging, automated tests, and a documented backup/restore schedule.
