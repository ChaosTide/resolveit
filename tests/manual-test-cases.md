# Manual Test Cases for Jordan

Record the date, tester, expected result, actual result, and Pass/Fail for each test. Use three separate browser profiles or sign out between roles.

| ID | Role | Test steps | Expected result |
|---|---|---|---|
| AUTH-01 | Any | Sign in with a correct active account. | Dashboard opens and shows the correct role. |
| AUTH-02 | Any | Sign in with a wrong password. | Generic error appears; no account details are revealed. |
| AUTH-03 | Any | Sign out, then use Back and refresh. | Protected ticket data is no longer available. |
| RBAC-01 | Employee | Manually open `users.php`. | Server returns 403 Forbidden. |
| RBAC-02 | Employee | Change the ID in `ticket.php?id=1` to another employee's ticket. | Server returns 403 Forbidden. |
| RBAC-03 | Technician | Manually open `users.php`. | Server returns 403 Forbidden. |
| TKT-01 | Employee | Create a ticket with a category, title, and 10+ character description. | Ticket is Open, Medium priority, unassigned, and visible in My Tickets. |
| TKT-02 | Employee | Submit a blank/short ticket or a title over 150 characters. | Validation rejects it and preserves safe form values. |
| TKT-03 | Technician | Assign an Open ticket to a technician and set Assigned. | Assignee/status update and history entries are visible. |
| TKT-04 | Technician | Set In Progress and add a technical note. | Note shows its author/time; history shows status change. |
| TKT-05 | Technician | Set Resolved without a resolution. | Validation rejects the update. |
| TKT-06 | Technician | Add a resolution of 10+ characters and set Resolved. | Resolution and resolved time are stored. |
| TKT-07 | Technician | Set Closed. | Status is Closed and closed time is stored. |
| SEC-01 | Any | Put `<script>alert(1)</script>` in a title or description. | Text displays literally; no script runs. |
| SEC-02 | Any | Put `' OR 1=1 --` in login or ticket fields. | It is handled as data; authentication and queries are not bypassed. |
| SEC-03 | Signed in | Remove/change the hidden CSRF token before form submission. | Request is rejected with status 419. |
| ADMIN-01 | Administrator | Create employee and technician accounts. | Passwords appear only as hashes in SQL Server; both users can sign in. |
| ADMIN-02 | Administrator | Deactivate an account and try to sign in as it. | Sign-in is rejected. |

## Database checks

Run these read-only queries in SSMS after testing:

```sql
SELECT u.email, r.name AS role, u.active, u.password_hash
FROM users u JOIN roles r ON r.id = u.role_id;

SELECT t.id, s.name AS status, p.name AS priority,
       requester.email AS requester, assignee.email AS assignee
FROM tickets t
JOIN statuses s ON s.id = t.status_id
JOIN priorities p ON p.id = t.priority_id
JOIN users requester ON requester.id = t.requester_id
LEFT JOIN users assignee ON assignee.id = t.assigned_to;

SELECT ticket_id, action, old_value, new_value, created_at
FROM ticket_history ORDER BY id;
```

Confirm that `password_hash` is unreadable hash text, ownership is correct, and every important workflow change has a history row.
