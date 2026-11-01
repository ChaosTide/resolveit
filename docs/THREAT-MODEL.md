# ResolveIT — Threat Model & Security Review

A security pass over the whole app: what the attack surface actually is, what
I tested against it (static reading of every security-relevant file, then
live attacks against the running server), what was already solid, and the
one real bug found and fixed. Fix commit: [`1f56f6f`](../../commit/1f56f6f).

## 1. Threat model

**Assets worth protecting:** user credentials (password hashes), ticket
contents (can include sensitive internal IT/HR detail), session identity,
the SQL Server database itself.

**Actors / trust boundaries:**

```
Anonymous visitor  --(no trust)-->  login.php only
Employee           --(low trust)--> own tickets only
Technician         --(medium)-----> all tickets, assignment
Administrator      --(high)------->  users, settings, audit log
Admin scripts (CLI)--(operator)--->  direct DB access, run by the developer only
```

The important boundary crossing is **anonymous -> employee**: everything
past login.php assumes the session is trustworthy. That makes
authentication and session handling the highest-value target, which is
exactly where the one real bug turned up.

**Attack surface:** every `public/*.php` file is reachable directly over
HTTP. `app/*.php` and `scripts/*.php` are not web-reachable at all (outside
`public/`, see `deploy/apache/cts4911.conf`) — they're only a risk if code
*inside* `public/` passes attacker data into them unsafely, which is what
the review below actually checks for, rather than treating the directory
boundary itself as the defense.

## 2. Method

1. **Static review** of every file that touches auth, sessions, SQL, or
   output: `app/http.php`, `app/auth.php`, `app/db.php`, `app/bootstrap.php`,
   `app/validation.php`, `app/azure-auth.php`, `app/audit.php`,
   `app/workflow.php`, plus a grep sweep of all of `public/` and `app/` for
   the classic bug patterns (string-built SQL, un-escaped output, dangerous
   functions like `eval`/`exec`/`unserialize`, open redirects).
2. **Live testing** against the running server at `127.0.0.1:8080`:
   security headers, cookie flags, CSRF enforcement, SQL injection and XSS
   payloads on reachable fields, path traversal, login rate-limiting.
3. **Authenticated testing** using two throwaway accounts created and then
   deleted for this review (`pentest-a-*`/`pentest-b-*`, same disposable
   pattern as `tests/check-local.ps1`'s `qa-*` fixtures) to test IDOR
   (can one employee reach another's ticket?), role-boundary enforcement,
   and stored XSS end-to-end, not just in theory from reading the code.

## 3. Finding: login CSRF (fixed)

**The bug**, in pseudocode:

```
function verify_csrf(request):
    session_token = session.csrf_token  OR  ""      # "" if no session yet
    submitted_token = request.post.csrf_token OR ""  # "" if the field is missing
    if submitted_token != session_token:
        reject with 403
    # BUG: "" != "" is false, so an empty token silently matches an empty token
```

`csrf_token()` only ever creates a token the *first* time a form is
rendered in a session. A visitor who has never loaded any page yet has no
session token at all. If an attacker's forged request also omits the
`csrf_token` field, both sides of the comparison default to `""`, and
`hash_equals("", "")` is `true` by definition — two identical strings
match, even empty ones. The check that's supposed to say "this request
must carry a real, unguessable, session-bound secret" instead says
"these two blanks are equal," which defeats the whole point.

**Why it mattered specifically on login.php:** every other POST endpoint
sits behind `require_login()`/`require_role()`, so by the time a real user
can submit one of those forms, `csrf_token()` has already run at least once
and `session.csrf_token` is a real 64-character random value — the "both
blank" condition can't occur there. `login.php` is the one POST endpoint an
anonymous visitor (no session yet) can reach directly, so it's the one
place the gap was live.

**Exploit, in pseudocode** (the classic "login CSRF" pattern — tricking a
victim into unknowingly using the *attacker's* account, not stealing the
victim's):

```
# attacker's page, visited by the victim:
<form action="https://resolveit.example/login.php" method="post" id="f">
    <input name="email" value="attacker@evil.example">
    <input name="password" value="attackers-own-password">
    <!-- no csrf_token field at all -->
</form>
<script>document.getElementById("f").submit()</script>

# victim's browser, with no prior ResolveIT session:
#   session.csrf_token = ""   (nothing set yet)
#   submitted csrf_token = "" (field doesn't exist)
#   "" == ""  ->  verify_csrf() passes
#   login_user() runs with the attacker's own credentials and succeeds
#   session_regenerate_id() + $_SESSION['user'] = attacker's identity
#   victim's browser now holds a session logged in as the ATTACKER
```

Confirmed live (not just reasoned about): a scripted POST with the
`csrf_token` field stripped, against a cookie-less session, returned
`200` and processed normally instead of the expected `403`. A forged
request with a *present but wrong* token was correctly rejected with
`403` — only the empty/missing case was broken.

**The fix** ([`app/http.php`](../app/http.php)):

```
function verify_csrf(request):
    session_token = session.csrf_token OR ""
    submitted_token = request.post.csrf_token OR ""
    if session_token == "" OR submitted_token != session_token:
        reject with 403                      # empty session token -> automatic reject
```

Re-tested the same exploit after the fix: `403`. Re-tested a normal sign-in
with a real token: still `200` and works exactly as before — the fix only
closes the empty-equals-empty gap, it doesn't change behavior for any
legitimate request.

## 4. Everything else checked (confirmed already solid)

| Area | What was tested | Result |
|---|---|---|
| SQL injection | Every query in `app/` and `public/` grepped for string-built SQL; `' OR '1'='1'`, `admin'--`, `'; DROP TABLE users--`, UNION payloads sent live at the login form | All queries use PDO prepared statements with real placeholders (`PDO::ATTR_EMULATE_PREPARES => false`), including the one dynamic `IN (...)` clause in `tickets-bulk.php`, which builds the right number of `?` placeholders rather than interpolating values. No injection, no DB errors leaked. |
| Stored XSS | `<script>`/`<img onerror>` payloads submitted as a live ticket title and description by an authenticated test account, then viewed back | `e()` (`htmlspecialchars` with `ENT_QUOTES`) wraps every dynamic string output; confirmed the payload came back escaped, not executable. Dynamic CSS class names go through `css_token()`, an allowlist sanitizer, not through `e()` alone. |
| IDOR (broken object-level access) | Employee A created a ticket; employee B tried to view it by ID (GET) and reply to it (POST) | Both blocked with `403` via `can_view_ticket()`, which checks `requester_id` against the session user for the `employee` role. |
| Role-boundary enforcement | Employee account hitting `users.php` and `settings.php` directly | `403` on both, via `require_role('administrator')`. |
| CSV export formula injection | Reviewed `send_csv()` | Already defended: any field starting with `=`, `+`, `-`, `@`, tab, or CR gets a leading `'` before being written, which stops spreadsheet formula execution on open. |
| Open redirect | Every `redirect()` call site in `public/` | All targets are hardcoded paths or paths built from validated integers (`valid_id()`); the one user-influenced case (`tickets-bulk.php`'s "return to list" URL) rebuilds `tickets.php?...` from parsed query params via `http_build_query()` rather than ever echoing a raw URL back. |
| Session fixation | `login_user()` / `azure_team_login()` | Both call `session_regenerate_id(true)` on privilege change. |
| Password storage | `users.php`, `user-edit.php`, `docker-init.php` | `password_hash()`/`password_verify()` throughout; nothing stores or logs plaintext. |
| Brute-force / credential stuffing | 6 rapid failed logins against one address, live | Locked out exactly at the 6th attempt, matching `LOGIN_MAX_FAILURES_PER_EMAIL = 5`. Per-IP cap also present. |
| Session cookie flags | `Set-Cookie` header on a live response | `HttpOnly; SameSite=Lax` present; `Secure` is correctly conditional on HTTPS (absent on plain local HTTP, which is correct, not a bug — `bootstrap.php` sets it when `APP_ENV=azure` or `$_SERVER['HTTPS']` is on, which the Apache config arranges behind the Cloudflare tunnel). |
| Security headers | Live response headers | `Content-Security-Policy: default-src 'self'`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` all present. |
| Path traversal / source disclosure | `GET /../app/config.php`, `GET /assets/` | `404` and `403` — only `public/` is servable (`deploy/apache/cts4911.conf`), directory listing is off (`Options -Indexes`). |
| Secrets in git | `app/config.php` (real DB settings) | Gitignored, never committed; local DB auth is Windows Authentication (no password in the file to begin with). |
| Dangerous functions | `eval`, `exec`, `system`, `passthru`, `unserialize`, `extract`, variable variables, repo-wide | None in the web-reachable code. The one `passthru()` (in `docker-init.php`) runs a fixed, `escapeshellarg()`-wrapped path to a local migration script during developer-run setup, not from any HTTP request. |
| Azure Easy Auth trust | `azure-auth.php`'s reliance on the `X-MS-CLIENT-PRINCIPAL` header | Correct for its deployment: App Service's Easy Auth proxy is the only thing that can set that header before PHP sees it, and the code still re-checks tenant ID, `auth_typ`, and an explicit email allowlist rather than trusting the header blindly. |

## 5. Verification

| Check | Result |
|---|---|
| `php -l` on the fixed file | No syntax errors |
| `phpcs` (PSR-12) | 0 errors, 0 warnings |
| Exploit re-test after fix | `403` (was `200`) |
| Legitimate sign-in after fix | Unchanged — still `200` with a real token |
| Test fixtures (2 accounts, 1 ticket, login-attempt rows) | Created for this review, then deleted; nothing left behind |

## 6. Follow-up review: public exposure via the Cloudflare tunnel

Triggered by actually using `deploy/tunnel/start-tunnel.ps1` for the first time
this session, which puts a real public `https://*.trycloudflare.com` URL in
front of the local site. New attack surface — the app, not the SLA/reporting
work — so it got the same live-request treatment as section 3, this time
hitting the public tunnel URL instead of `127.0.0.1`.

| Area checked | How | Result |
|---|---|---|
| Security headers over the tunnel | Live response headers from the public URL | CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` all present and unchanged from local. `Server: cloudflare` masks Apache's own header (a side benefit of the tunnel, not something this app configured). |
| Session cookie flags | `Set-Cookie` header over the tunnel | `secure; HttpOnly; SameSite=Lax` — confirms the `SetEnvIf X-Forwarded-Proto` → `HTTPS=on` trick in `cts4911.conf` actually works end-to-end through the real tunnel, not just in theory. |
| CSRF | `POST /login.php` through the tunnel with no `csrf_token` | `403`, same as local. |
| Auth gating | `GET /ticket.php?id=abc` through the tunnel, unauthenticated | `302` to `login.php` before the ticket-ID check ever runs — same as local, no bypass via the tunnel path. |
| **Per-IP login lockout vs. the tunnel** | One failed login through the tunnel, then read `login_attempts.ip` directly | **This was the real question.** Every tunnel visitor reaches Apache as `127.0.0.1` (Cloudflare connects locally) — if the app trusted `REMOTE_ADDR` naively, the whole internet would share one IP bucket, and a single attacker could lock out every legitimate user through the tunnel. It doesn't: `client_ip()` in `app/auth.php` already falls back to the `CF-Connecting-IP` header (and only when the direct connection is loopback, i.e. only in exactly this scenario). Confirmed live — the attempt recorded a real external IP, not `127.0.0.1`. This logic predates this session; it was a relief to find it already correct rather than something to fix under time pressure. |
| SQL Server exposure | N/A — tunnel config only forwards `http://127.0.0.1:8080` | SQL Server is not tunneled or reachable externally; the tunnel can only ever reach the one port it's pointed at. |
| `Strict-Transport-Security` | Compared to `deploy/docker/apache-cts4911.conf`, which already sets it (that config is always HTTPS, via Azure) | **Gap found**: the native `deploy/apache/cts4911.conf` had no HSTS header, presumably because local access is normally plain HTTP. Added it anyway — browsers only honor `Strict-Transport-Security` on a response actually served over HTTPS, so it's inert over local HTTP and only takes effect on the tunnel's real HTTPS path. Required an Apache restart to pick up (no graceful-reload path for this non-service console install; stopped and restarted `httpd.exe`). Verified present over both local HTTP and the tunnel afterward. |

No new vulnerability found beyond the one header gap. The thing most worth
recording here isn't a bug — it's that the IP-trust logic for exactly this
scenario (tunneled traffic that would otherwise look like one shared IP) was
already built correctly before there was any reason to exercise it.

| Check | Result |
|---|---|
| `tests/check-local.ps1` | 137 / 137 pass (after the Apache restart) |
| Test data (one `login_attempts` row for a non-existent test email) | Deleted after the check |
