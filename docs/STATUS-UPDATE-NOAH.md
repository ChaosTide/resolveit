# Status update for Noah — Tasks 5 & 6 (Jordan)

> Written so Jordan can paste this straight into a message to Noah. Nobody sent this
> automatically — it's a draft living in the repo, same as the rest of the writeup.

Hey Noah — quick status on my two tasks (5: set up the application, 6: login and user
access), where things stand and what's left.

## Where we are right now

Both tasks are **done, tested, and documented**:

- The app runs on real Apache (not PHP's test server), one command (`start-site.ps1`),
  SQL Server backing it with Windows Authentication — 12 tables, 14 foreign keys.
- Login, role-based access control (employee / technician / administrator), lockout
  after 5 bad passwords, and a live session re-check on every click (a deactivation or
  role change takes effect on someone's *next click*, not their next login) — all built
  and working.
- `tests/check-local.ps1` — **137/137 automated checks passing** — covers auth, RBAC,
  CSRF, the ticket workflow, and more. It's also evidence for your Task 10 testing work,
  if that's useful.
- `phpcs` — 0 errors, 0 warnings (PSR-12).
- A full security review (static read-through + live attack testing: SQLi/XSS, CSRF
  bypass, IDOR, rate limits, headers) found one real bug — a login-CSRF gap — which is
  fixed (commit `1f56f6f`). Everything else came back solid.
- Full writeup with pseudocode and flow diagrams: [`docs/TASKS-5-6-JORDAN.md`](TASKS-5-6-JORDAN.md).
  Live-demo script for presenting it to the team: [`docs/DEMO-SCRIPT.md`](DEMO-SCRIPT.md).
- Team can reach the running app right now via a Cloudflare tunnel (public HTTPS link to
  my PC) — ask me for the current link, it changes each time I restart it.

## What's next for Tasks 5 & 6

Honestly — not much. Per the team's own change log
([`docs/DEPLOYMENT.md`](DEPLOYMENT.md), section 1.2), both tasks are **frozen**: no
further additions without a change request.

The one open item is **CR-05, always-on cloud hosting** — and since you asked in Discord
(Nov 9) whether you could pay to make this a live website: yes, that's exactly what
would solve it.

- The code for it already exists and is tested — an Apache container for Azure App
  Service (`deploy/docker`), environment-variable-sourced config
  (`app/config.azure.php`), and Microsoft sign-in (`app/azure-auth.php`). It was built
  for Azure App Service specifically, not a generic host.
- It's not deployed because UWF Azure sign-in is hitting an MFA error, and the personal
  account has no Azure subscription. That's an access problem, not a code problem — if
  a paid Azure subscription exists, this can likely go live without writing new code.
- Before you spend anything: let's agree on who owns the subscription and billing, and
  confirm Azure App Service specifically — a different provider (a generic VPS, Render,
  etc.) would need new deployment work this config doesn't cover.
- Until that's decided, the Cloudflare tunnel is the interim way the team reaches the
  live site, and it works fine for demos and day-to-day access.

If anyone on the team needs something from Tasks 5/6 changed, the process is the same
as any other change: a one-paragraph change request (what, why, what it replaces), the
team signs off, then it's not "frozen" anymore. Otherwise, from my side, 5 and 6 are
ready to present and ready to grade.

— Jordan
