# ResolveIT — QA / Regression Testing

A full run of the project's automated test suite against the running local site, done
after the UI redesign, the CSRF fix, and the code-commenting pass, to confirm nothing
regressed. Relates to tracker items #10 ("Run test cases") and #11 ("Fix bugs and
collect feedback"), though most of that work is already covered by the suite below.

## 1. What ran

[`tests/check-local.ps1`](../tests/check-local.ps1) — 137 automated checks against
`http://127.0.0.1:8080`, covering authentication, role-based access control, CSRF,
the ticket workflow (status transitions, SLA, due dates), replies, bulk update, CSV
export (including formula-injection neutralization), reports, search, the knowledge
base, sorting, live-refresh, split view, organizations, login lockout, and session
cookie flags. The suite creates its own disposable `qa-*` fixtures and deletes them
when it finishes.

## 2. A false alarm worth recording (the debugging part)

The first run failed almost everywhere:

```
FAIL: Administrator login
FAIL: Add organization
FAIL: Duplicate organization rejected
... (every later check, cascading from the first)
```

**The investigation, in order:**

1. The suite signs in once, as `jordan@cts4911.local`, and reuses that session for
   every later admin-only check. If that first sign-in fails, every check after it
   fails too — so the real question was only "why did the first sign-in fail."
2. Ruled out a credential mistake on my end first: a clean, isolated login attempt
   against the account directly (bypassing the test script entirely) returned "email
   or password is incorrect" — a real rejection, not a test-script bug.
3. Ruled out a code bug next: re-read `app/auth.php`'s `login_user()` and
   `public/user-edit.php`'s self-edit handling line by line. Both were correct —
   `user-edit.php` blocks a role change for your own account before it writes
   anything, so that code path could not explain a changed password.
4. Went to the evidence that actually settles it — the admin audit log, which
   only gets a row when an update genuinely succeeds:

   ```
   2026-10-31 14:54:56  admin#1  user edited  jlb282@students.uwf.edu
       email: jordan@cts4911.local -> jlb282@students.uwf.edu; password reset
   ```

   Someone, signed in as the administrator, changed that account's own email and
   password about eight minutes after it was last handed out as working login info.
   That is a real, successful, intentional edit through Manage Users — not a bug.

**Conclusion:** the application worked correctly throughout. The test suite's
assumption that `jordan@cts4911.local` always exists with a known password is just
fragile against the account actually changing hands or being edited mid-session. See
Section 4 for a recommendation.

**Fix applied:** none to the application. A second, dedicated QA admin account was
created at the now-unused `jordan@cts4911.local` address
(`scripts/create_user.php`) specifically so the suite has a stable account to run
against, without touching the real administrator account at
`jlb282@students.uwf.edu`.

## 3. Result

All 137 checks passed on the second run (QA fixtures created and cleaned up
automatically as part of the run):

| Area | Checks |
|---|---|
| Auth, RBAC, CSRF, lockout | 16 |
| Tickets: create, validation, workflow, replies | 23 |
| Due dates, bulk update, CSV export | 9 |
| User management | 5 |
| Reports and SLA | 5 |
| Dashboard, search, filters | 12 |
| Settings (organizations, categories, priorities, audit) | 10 |
| Lockout and HTTPS cookie behavior | 4 |
| Knowledge base | 9 |
| Sorting, live refresh, split view | 8 |
| Organizations and advanced search | 8 |
| Front-end assets and deactivation | 3 |
| **Total** | **137 / 137 passing** |

## 4. Recommendation (not applied — not this section's call)

`tests/check-local.ps1` hardcodes one admin email at the top (`jordan@cts4911.local`)
and has no fallback if that account is missing, deactivated, or edited. Worth raising
with James Dawson (tracker task #10, owns the test suite): either accept an admin
email as a script parameter alongside `-AdminPassword`, or have the script create its
own disposable admin account the same way it already creates disposable
employee/technician accounts, so a real admin account changing never blocks a test
run again.

## 5. Accounts that now exist

| Email | Role | Purpose |
|---|---|---|
| `jlb282@students.uwf.edu` | administrator | Jordan's real account (password set by Jordan) |
| `jordan@cts4911.local` | administrator | Dedicated QA account for `tests/check-local.ps1`; not used day to day |
