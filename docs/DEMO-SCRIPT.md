# Live demo script — Tasks 5 & 6 (Jordan Black)

Run through once before presenting. Needs: this repo open in a terminal, the site running
(`start-site.ps1`), SSMS connected to `WORKSTATION\JLB282` / `TicketManagement`, and two
browser windows (one regular + one incognito works) so you can be signed in as two
different roles at once.

**Total time: ~5 minutes.** If you're short on time, cut section 3 first (SSMS) — it's the
least essential.

---

## 0. Before you start (30 sec, while talking)

- [ ] Site is running: `powershell -ExecutionPolicy Bypass -File start-site.ps1`
- [ ] An admin account and an employee account are ready, both signed **out** — sign in
      live during the demo, don't pre-log-in
- [ ] SSMS open, connected to `WORKSTATION\JLB282`, `TicketManagement` selected

> Say: "Tasks 5 and 6 are the foundation everything else sits on — the running app, and who's
> allowed to do what inside it. I'll show both live, not just slides."

---

## 1. Task 5 — the app starting up (60 sec)

1. Show the terminal: run (or point at the already-running) `start-site.ps1`.
   > "One command. It checks if the site's already running, then starts real Apache — not
   > PHP's test server — serving only the `public/` folder."
2. Open `http://127.0.0.1:8080` in the browser — the sign-in page loads.
3. Switch to the file tree, open `deploy/apache/cts4911.conf` for a couple seconds.
   > "This is what makes `app/`, `database/`, and `scripts/` unreachable from the browser
   > even though they're sitting right next to `public/` on disk — only `public/` is the
   > document root, and dotfiles are blocked too."

**Fallback if the site's already running when you walk up:** skip straight to step 2,
mention the one-command start verbally instead of re-running it.

---

## 2. Task 6 — signing in as two roles (2 min)

1. **Admin window:** sign in as an administrator.
   > "Notice the sidebar: Manage Users, Reports, Settings — all visible."
2. **Employee window (incognito):** sign in as an employee.
   > "Same app, same code — but those three links are gone."
3. In the employee window, type `/users.php` directly into the address bar.
   > "Even typing the URL by hand doesn't get you in — **403 Forbidden**. The check runs on
   > the server, on every single page, not just in what the menu shows you."
4. Back in the admin window: open **Manage Users**, deactivate the employee account (or
   change its role) live.
5. Switch to the employee window, click any link.
   > "They're logged out immediately — not at their next sign-in. The session re-checks the
   > database on every click, so a role change or deactivation takes effect on that
   > person's very next action."

**Fallback if you don't want to actually deactivate a real account live:** narrate steps
4–5 instead of performing them, pointing at `refresh_session_user()` in
[TASKS-5-6-JORDAN.md](TASKS-5-6-JORDAN.md).

---

## 3. Task 6 — the password story (60 sec)

1. In SSMS: `SELECT email, password_hash FROM users;`
   > "Passwords are never stored as text — only this one-way hash. Even with full database
   > access, nobody can read anyone's actual password, including me."
2. In the sign-in form, enter a wrong password 5 times in a row for one account.
   > "Fifth wrong attempt locks that account for 15 minutes — stops someone from just
   > guessing passwords over and over. It's also capped per network address, so one person
   > can't work around it by trying a list of different emails."

---

## 4. Close (30 sec)

> "That's the whole foundation: one command to stand the app up, and a login system where
> the server — not the menu, not the browser — decides what you're allowed to see. Full
> pseudocode and flow diagrams for both are in
> [TASKS-5-6-JORDAN.md](TASKS-5-6-JORDAN.md) if anyone wants the detail."

---

## If something breaks live

| Problem | Say this, then... |
|---|---|
| Site won't load | "Let me restart it" — run `start-site.ps1` again, it's safe to re-run. |
| SQL Server not reachable | Skip section 3's `SELECT`, describe it from the pseudocode doc instead. |
| Locked yourself out testing lockout earlier | Use a different test account, or just wait — it's only 15 minutes. |
| Someone asks "is this really SQL Server, not MySQL?" | Yes — see the note at the bottom of TASKS-5-6-JORDAN.md; the team task list said MySQL/Bootstrap, the build actually uses SQL Server and a custom stylesheet. |
