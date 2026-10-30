# ResolveIT – Tasks 5 & 6 (Jordan Black)

**Task 5 – Set up the application** and **Task 6 – Login and user access**

> Open this file with **Ctrl+Shift+V** in VS Code for the formatted preview. Every link jumps to the code (and line) it describes.
>
> Honesty note for the team: the first version of this system was written on Sept 8 with AI help. In late September / October it was restored, moved onto Apache, and given the security upgrades marked **(added)** below. All changes are tracked in git.

---

## Task 5 – Set up the application

**One-sentence summary:** "I set up a working shared app: the web server, PHP, the SQL Server database with its tables and an admin account, and a script that starts everything with one command."

| Piece | File | What it does |
|---|---|---|
| Start command | [start-site.ps1](../start-site.ps1) | Starts Apache at `http://127.0.0.1:8080`; does nothing if the site is already running. |
| Web server **(added)** | [deploy/apache/cts4911.conf](../deploy/apache/cts4911.conf) | Apache 2.4 + PHP 8.3. Serves only the `public/` folder so code and settings can't be downloaded, hides error details from visitors, adds security headers. |
| Database settings | [app/config.php](../app/config.php) | Points to SQL Server `WORKSTATION\JLB282`, database `TicketManagement`, Windows Authentication (no password in the code). |
| Database connection | [app/db.php](../app/db.php#L4) | Opens the connection with PDO and real prepared statements (blocks SQL injection). |
| Database tables | [database/schema.sql](../database/schema.sql) | 12 tables, 14 foreign keys: users, roles, organizations, tickets, statuses, priorities, notes, history, audit log, sign-in attempts, knowledge base. |
| Database upgrades **(added)** | [database/migrations/](../database/migrations/) | `001`–`006`: each feature's database change, safe to run twice. |
| First admin account | [scripts/create_user.php](../scripts/create_user.php) | Creates the first administrator from the command line; after that the admin creates everyone in **Manage Users**. |
| Shared startup code | [app/bootstrap.php](../app/bootstrap.php) | Every page loads this first: settings, database, session, and security helpers. |
| Team access **(added)** | [deploy/tunnel/start-tunnel.ps1](../deploy/tunnel/start-tunnel.ps1) | Gives teammates a public https link to the site running on this PC. |
| Proof it works **(added)** | [tests/check-local.ps1](../tests/check-local.ps1) | 137 automated checks (also evidence for James's Task 10). |

**Also done:** reinstalled PHP after it was deleted from the PC, moved the site from PHP's built-in test server onto real Apache, and put the project in git so every change is tracked.

**How to start it:**
```powershell
powershell -ExecutionPolicy Bypass -File start-site.ps1
```

---

## Task 6 – Login and user access

**One-sentence summary:** "Login and logout work, passwords are never stored in readable form, and every page checks who you are and what your role allows — the server blocks anything outside your role even if you type the address directly."

### Signing in, step by step

1. **The form** – [public/login.php](../public/login.php) takes email and password. A hidden form token stops fake submissions from other websites (CSRF protection) – see `csrf_token()` in [app/bootstrap.php](../app/bootstrap.php#L65) and `verify_csrf()` at [line 73](../app/bootstrap.php#L73).
2. **Lockout check (added)** – `login_locked_out()` in [app/auth.php](../app/auth.php#L25): 5 wrong passwords lock that account for 15 minutes; 20 failures from one address are also blocked. Stops password guessing.
3. **Password check** – `login_user()` in [app/auth.php](../app/auth.php#L46). Passwords are stored only as one-way hashes (`password_hash`) and checked with `password_verify` ([line 55](../app/auth.php#L55)). The error never says whether the email or the password was wrong.
4. **Fresh session ID** – [line 58](../app/auth.php#L58) replaces the session ID after login, so a stolen pre-login session is useless.
5. **Attempts recorded (added)** – `record_login_attempt()` ([line 40](../app/auth.php#L40)) saves every try in the `login_attempts` table.

### Signing out

- [public/logout.php](../public/logout.php) wipes the session completely.
- Idle for 30 minutes → signed out automatically ([app/bootstrap.php](../app/bootstrap.php#L38)).

### Role permissions (employee / technician / administrator)

| Function | Where | What it protects |
|---|---|---|
| `require_login()` | [app/auth.php](../app/auth.php#L93) | Every page: not signed in → sent to the login page. |
| `has_role()` | [app/auth.php](../app/auth.php#L101) | Shows or hides menu items, e.g. only admins see Manage Users / Reports / Settings. |
| `require_role()` | [app/auth.php](../app/auth.php#L107) | Admin pages such as [public/users.php](../public/users.php#L3) – anyone else gets **403 Forbidden**. |
| `can_view_ticket()` | [app/auth.php](../app/auth.php#L116) | Employees can open only their **own** tickets. |
| `refresh_session_user()` **(added)** | [app/auth.php](../app/auth.php#L73) | Re-checks the account on every click: deactivation, role change, or password reset by an admin takes effect immediately. |

---

## 2-minute demo for the team

1. Sign in as **admin** → the sidebar shows Manage Users, Reports, Settings.
2. Sign in as an **employee** (second browser) → those items are gone; type `/users.php` in the address bar → **403 Forbidden**.
3. Enter a wrong password 5 times → "Too many failed sign-in attempts."
4. In SSMS run `SELECT email, password_hash FROM users;` → passwords are scrambled hashes, not readable text.

---

## Note for Task 4 (design choices)

The team task list says **MySQL** and **Bootstrap**. The project actually uses **Microsoft SQL Server** and a **custom stylesheet** (no outside frameworks). The design-choices section and final document should describe what was really built.
