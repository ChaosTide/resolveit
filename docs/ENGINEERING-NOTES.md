# ResolveIT — Engineering Notes: PSR-12 Cleanup & Rename

This covers one piece of work: bringing all PHP up to the PSR-12 coding
standard and renaming the project from "CTS Service Desk" to "ResolveIT."
Commits: [`641b47a`](../../commit/641b47a), [`cafceba`](../../commit/cafceba), [`6cf21f2`](../../commit/6cf21f2).

## 1. The stack (where this code sits)

```
Browser
  |
  v
Apache 2.4 (Apache Lounge) + PHP 8.3 module        <-- 127.0.0.1:8080 (local)
  |  or: PHP 8.3-apache container                  <-- 127.0.0.1:8081 (Docker)
  v
public/*.php  (one file per page: tickets.php, ticket.php, settings.php...)
  |  every page starts with:  require app/bootstrap.php
  v
app/bootstrap.php
  |-- app/http.php        e(), redirect(), flash(), csrf_token(), verify_csrf()
  |-- app/db.php           PDO connection (pdo_sqlsrv)
  |-- app/validation.php   clean_text(), valid_id(), unique_error()...
  |-- app/auth.php         login_user(), require_login(), require_role()...
  |-- app/workflow.php     allowed_status_ids(), workflow_error()
  |-- app/audit.php        audit_log()
  |-- app/reports.php      ticket_sla_base(), run_report(), report_result()
  |-- app/tickets.php      ticket_query_sql(), ticket_sort_options(), assignee_filter()
  `-- app/ui.php           status_badge(), ticket_table(), select_options(), icon()...
  v
Microsoft SQL Server (WORKSTATION\JLB282, Windows Auth — local)
  or SQL Server 2022 container (resolveit_app login — Docker)
```

Full breakdown of every language used (HTML/CSS/JS/PHP/SQL) is in
[TECH-STACK.md](TECH-STACK.md). This doc is just the "what changed and why" for
the cleanup pass.

## 2. The plan I followed

1. **Baseline.** Installed PHP_CodeSniffer, wrote `phpcs.xml` (PSR-12, 120-char
   soft line limit), ran it cold: found PSR-1 violations (files mixing
   function declarations with executable code) and 367 line-length warnings.
2. **Fix PSR-1 first.** Three files declared helper functions *and* ran code
   in the same file. Moved `kpi_tile()` and `render_report()` out of
   `dashboard.php`/`reports.php` into `app/ui.php`; moved `unique_error()` out
   of `settings.php` into `app/validation.php`. Side-effect files stayed
   side-effect-only; declaration files stayed declaration-only.
3. **Auto-fix formatting.** Ran `phpcbf` (PSR-12 auto-fixer) — one statement
   per line, braces on every `if`, consistent operator spacing. It broke the
   one-line `<?php if (...) : ?>` template syntax in `public/*.php`, so I
   restored those files from git and excluded two brace-placement sniffs for
   `public/*` in `phpcs.xml` (documented there — template readability beats
   the sniff).
4. **Line-length pass, `app/` and `scripts/` first.** These are pure logic —
   no HTML — so every long line got restructured properly rather than just
   wrapped: multi-line SQL strings, arrays written one key per line, ternaries
   split across lines.
5. **Line-length pass, `public/*.php`.** Each page mixes PHP logic with HTML
   templates. Rule I used: reformat the PHP (conditionals, queries, arrays)
   properly; let template markup wrap naturally. Where the *same* markup
   pattern repeated across pages (dropdown `<option>` loops, hidden
   CSRF/action fields, the assignee-filter parsing), I pulled it into a
   named helper instead of just wrapping lines — that was the actual
   simplification, not just cosmetic wrapping.
6. **Rename.** "CTS Service Desk" → "ResolveIT" in titles, nav, CSV export
   filenames, docs. Left the session cookie name and the Docker entrypoint
   name (`cts4911-entrypoint`) alone — renaming those has no user-visible
   benefit and risks breaking existing sessions/deploys.
7. **Verify.** `phpcs` (0 errors/warnings), `php -l` on every file, full
   137-check suite (`tests/check-local.ps1`) against the local Apache site,
   rebuilt and smoke-tested the Docker container.
8. **Commit.** Code in [`cafceba`](../../commit/cafceba), docs in
   [`6cf21f2`](../../commit/6cf21f2).

## 3. The actual new code (the four reusable helpers)

These are the pieces that replaced repeated markup/logic across multiple
pages — this is the real engineering, not the reformatting.

**[`select_options()`](../app/ui.php)** — every `<select>` in the app built its
`<option>` list with a hand-rolled `foreach` + ternary for `selected`. Now one
call:

```php
function select_options(array $options, int|string|null $selected): string
{
    $html = '';
    foreach ($options as $value => $label) {
        $isSelected = $selected !== null && (string) $selected === (string) $value;
        $html .= '<option value="' . e((string) $value) . '"' . ($isSelected ? ' selected' : '') . '>'
            . e((string) $label) . '</option>';
    }
    return $html;
}
```
Used in `tickets.php`, `search.php`, `ticket-edit.php`, `kb.php`,
`kb-edit.php`, `ticket-create.php`, `user-edit.php`, `users.php`.

**[`action_fields()`](../app/ui.php)** — every admin POST form repeated the
same CSRF token + action name + optional id hidden inputs. Now:
```php
function action_fields(string $action, ?int $id = null): string
```
Used throughout `settings.php` and `users.php`.

**[`assignee_filter()`](../app/tickets.php)** — the "`?assignee=`" parsing
(`'me'` / `'none'` / a numeric id, staff-only) was duplicated in
`tickets.php` and `search.php`. Now one function both pages call.

**[`report_result()` / `count_report()`](../app/reports.php)** — `run_report()`
had the same `$meta + ['columns' => ..., 'rows' => ..., 'bar' => ..., 'num' => ...]`
shape repeated eight times inline (some lines 240+ characters). Pulled into a
builder function; the four simple "name + ticket count" reports (status,
priority, category, daily) now share `count_report()`.

Everything else in the diff is the same logic, reformatted: multi-line SQL,
one array entry per line, `if { } else { }` instead of packed one-liners. See
[`cafceba`](../../commit/cafceba) for the full diff (34 files, +2085/-709) or
open any file below directly.

## 4. Files touched

| Area | Files |
|---|---|
| Shared logic | [app/ui.php](../app/ui.php), [app/tickets.php](../app/tickets.php), [app/reports.php](../app/reports.php), [app/auth.php](../app/auth.php), [app/azure-auth.php](../app/azure-auth.php), [app/bootstrap.php](../app/bootstrap.php), [app/validation.php](../app/validation.php), [app/workflow.php](../app/workflow.php) |
| Pages | everything in [public/](../public/) — 17 files |
| Scripts | [scripts/azure-migrate.php](../scripts/azure-migrate.php), [scripts/docker-init.php](../scripts/docker-init.php) |
| Tests | [tests/check-local.ps1](../tests/check-local.ps1) — 6 regexes loosened to allow line breaks between tags (no behavior change, just matching reformatted markup) |
| Style rules | [phpcs.xml](../phpcs.xml) |

## 5. Verification results

| Check | Result |
|---|---|
| `phpcs` (PSR-12) | 0 errors, 0 warnings (was 367 warnings) |
| `php -l` (lint) | clean on all 36 files |
| `tests/check-local.ps1` | 137 / 137 pass |
| PHP error log | no entries during test run |
| Docker rebuild (8081) | sign-in + all 10 main pages load, no PHP warnings |
