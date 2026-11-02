param(
    [Parameter(Mandatory=$true)][string]$AdminPassword,
    [string]$BaseUrl='http://127.0.0.1:8080/',
    [string]$SqlServer='WORKSTATION\JLB282',
    # Keep the QA accounts, tickets, and category this run creates instead of deleting them at the end.
    [switch]$KeepData
)
# Works in Windows PowerShell 5.1 and PowerShell 7.
$ErrorActionPreference='Stop'
$base=$BaseUrl.TrimEnd('/')+'/'
$script:failures=0
function Request($session,$path,$body=$null) {
    $params=@{Uri=$base+$path; WebSession=$session; UseBasicParsing=$true}
    if($null -ne $body){ $params.Method='Post'; $params.Body=$body }
    try {
        $r=Invoke-WebRequest @params
        $content=if($r.Content -is [byte[]]){ [Text.Encoding]::UTF8.GetString($r.Content) } else { [string]$r.Content }
        return [pscustomobject]@{StatusCode=[int]$r.StatusCode; Content=$content; ContentType=[string]$r.Headers['Content-Type']}
    } catch {
        if(-not $_.Exception.Response){ throw }
        $content=if($_.ErrorDetails){ $_.ErrorDetails.Message } else { '' }
        return [pscustomobject]@{StatusCode=[int]$_.Exception.Response.StatusCode; Content=[string]$content}
    }
}
function Token($response){return [regex]::Match($response.Content,'name="csrf_token" value="([^"]+)"').Groups[1].Value}
function Check($label,$pass){ if(-not $pass){ $script:failures++ }; Write-Output "$(if($pass){'PASS'}else{'FAIL'}): $label" }
function SignIn($email,$password){
    $s=New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $p=Request $s 'login.php'
    $null=Request $s 'login.php' @{csrf_token=(Token $p);email=$email;password=$password}
    return $s
}
function SetStatus($session,$ticketId,$statusId,$assignee){
    $p=Request $session "ticket-edit.php?id=$ticketId"
    return Request $session "ticket-edit.php?id=$ticketId" @{csrf_token=(Token $p);status_id=$statusId;priority_id=2;assigned_to=$assignee;note="QA transition to $statusId";resolution='QA issue resolved for testing.'}
}
function StatusOf($response){ return [regex]::Match($response.Content,'data-field="status"><span class="badge[^"]*">([^<]+)<').Groups[1].Value }
# Removes only rows carrying this run's timestamp tag. Admin audit log rows are kept on purpose.
function RemoveTestData($tag){
    if($KeepData){ Write-Output "Kept QA data tagged $tag."; return }
    $sql="SET NOCOUNT ON; SET XACT_ABORT ON; BEGIN TRAN;
DELETE FROM tickets WHERE title LIKE N'QA % $tag' OR requester_id IN (SELECT id FROM users WHERE email LIKE N'qa-%-$tag@example.test');
DELETE FROM kb_articles WHERE title LIKE N'QA KB % $tag';
DELETE FROM users WHERE email LIKE N'qa-%-$tag@example.test';
DELETE FROM organizations WHERE name = N'QA Org $tag';
DELETE FROM categories WHERE name = N'QA category $tag' AND id NOT IN (SELECT category_id FROM tickets);
UPDATE priorities SET name = N'Low' WHERE name = N'Low QA';
DELETE FROM login_attempts WHERE email LIKE N'qa-%-$tag@example.test';
IF NOT EXISTS (SELECT 1 FROM tickets) DBCC CHECKIDENT ('tickets', RESEED, 0) WITH NO_INFOMSGS;
COMMIT;"
    sqlcmd -S $SqlServer -E -C -b -d TicketManagement -Q $sql
    Write-Output "$(if($LASTEXITCODE -eq 0){"Removed QA data tagged $tag."}else{"WARNING: could not remove QA data tagged $tag."})"
}

# --- Authentication ---
$admin=SignIn 'jordan@cts4911.local' $AdminPassword
Check 'Administrator login' ((Request $admin 'tickets.php').Content.Contains('Jordan Black'))
$anon=New-Object Microsoft.PowerShell.Commands.WebRequestSession
Check 'Anonymous protected page redirects to login' ((Request $anon 'tickets.php').Content.Contains('<h1>Sign in</h1>'))
$tag=Get-Date -Format yyyyMMddHHmmss
$p=Request $anon 'login.php'
$r=Request $anon 'login.php' @{csrf_token=(Token $p);email="qa-nobody-$tag@example.test";password='incorrect-password'}
Check 'Wrong password rejected' ($r.Content.Contains('incorrect'))

# --- Accounts and access control ---
$password=[guid]::NewGuid().ToString()+'!Aa9'
$accounts=@{}
$p=Request $admin 'settings.php?tab=organizations'
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='org_add';name="QA Org $tag"}
$orgId=[regex]::Match($r.Content,"name=`"id`" value=`"(\d+)`">\s*<input name=`"name`" maxlength=`"100`" value=`"QA Org $tag`"").Groups[1].Value
Check 'Add organization' ($orgId -ne '')
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='org_add';name="QA Org $tag"}
Check 'Duplicate organization rejected' ($r.Content.Contains('An organization with that name already exists'))
foreach($role in @('employee','technician')){
    $email="qa-$role-$tag@example.test"
    $p=Request $admin 'users.php'
    $r=Request $admin 'users.php' @{csrf_token=(Token $p);action='create';name="QA $role $tag";email=$email;password=$password;role=$role;organization_id=$orgId}
    Check "Create $role account in an organization" ($r.Content.Contains($email) -and $r.Content.Contains("QA Org $tag"))
    $accounts[$role]=SignIn $email $password
}
$employee=$accounts.employee; $tech=$accounts.technician
foreach($page in 'users.php','user-edit.php?id=1','reports.php','settings.php'){
    Check "Employee blocked from $page" ((Request $employee $page).StatusCode -eq 403)
    Check "Technician blocked from $page" ((Request $tech $page).StatusCode -eq 403)
}

# --- Tickets ---
$p=Request $employee 'ticket-create.php'
$r=Request $employee 'ticket-create.php' @{csrf_token='invalid';title='QA';description='A testing description';category_id=1}
Check 'CSRF rejection' ($r.StatusCode -eq 403 -and $r.Content.Contains('The form expired or was invalid'))
$r=Request $employee 'ticket-create.php' @{csrf_token=(Token $p);title='';description='short';category_id=1}
Check 'Invalid ticket rejected' ($r.Content.Contains('Title is required'))
$r=Request $employee 'ticket-create.php' @{csrf_token=(Token $p);title="QA workflow $tag";description='QA test description <script>alert(1)</script>';category_id=1}
$id=[regex]::Match($r.Content,'Ticket #(\d+):').Groups[1].Value
Check 'Create and view ticket' ($id -ne '')
Check 'New ticket starts Open' ((StatusOf $r) -eq 'Open')
Check 'Script text escaped' ($r.Content.Contains('&lt;script&gt;'))
if(-not $id){ Write-Output $r.Content; RemoveTestData $tag; exit 1 }
Check 'Employee cannot manage ticket' ((Request $employee "ticket-edit.php?id=$id").StatusCode -eq 403)

# --- Conversation replies ---
$p=Request $employee "ticket.php?id=$id"
$r=Request $employee "ticket.php?id=$id" @{csrf_token=(Token $p);id=$id;note="Employee reply $tag"}
Check 'Requester can reply to own ticket' ($r.Content.Contains('Reply added') -and $r.Content.Contains("Employee reply $tag"))
$p=Request $tech "ticket.php?id=$id"
$r=Request $tech "ticket.php?id=$id" @{csrf_token=(Token $p);id=$id;note="Technician reply $tag"}
Check 'Staff reply is labeled IT Staff' ([regex]::IsMatch($r.Content,'IT Staff</span>.*?Technician reply','Singleline'))
Check 'Staff reply records first response time' (-not $r.Content.Contains('Waiting for IT') -and $r.Content.Contains('data-field="response"'))
Check 'Ticket waits on requester after IT replies' ((Request $tech 'tickets.php?view=waiting').Content.Contains("id=$id`">#$id</a>"))
Check 'Requester sees it under Awaiting my reply' ((Request $employee 'tickets.php?view=waiting').Content.Contains("id=$id`">#$id</a>"))
$p=Request $employee "ticket.php?id=$id"
$r=Request $employee "ticket.php?id=$id" @{csrf_token=(Token $p);id=$id;note='x'}
Check 'Too-short reply rejected' ($r.Content.Contains('A reply must be between'))
$p=Request $tech "ticket-edit.php?id=$id"
$assignee=[regex]::Match($p.Content,'<option value="(\d+)"[^>]*>QA technician').Groups[1].Value
Check 'Open ticket form hides Closed option' (-not $p.Content.Contains('>Closed</option>'))

# --- Phase 2 workflow enforcement ---
$r=SetStatus $tech $id 5 $assignee
Check 'Block Open -> Closed' ($r.Content.Contains('Status cannot move from Open to Closed') -and (StatusOf (Request $tech "ticket.php?id=$id")) -eq 'Open')
$r=SetStatus $tech $id 3 $assignee
Check 'Block Open -> In Progress' ($r.Content.Contains('Status cannot move from Open to In Progress'))
$r=SetStatus $tech $id 2 ''
Check 'Block Assigned without an assignee' ($r.Content.Contains('Only an Open ticket may remain unassigned'))
foreach($status in 2,3,4){
    $expected=@{2='Assigned';3='In Progress';4='Resolved'}[$status]
    Check "Workflow step to $expected" ((StatusOf (SetStatus $tech $id $status $assignee)) -eq $expected)
}
Check 'Reopen Resolved -> In Progress' ((StatusOf (SetStatus $tech $id 3 $assignee)) -eq 'In Progress')
Check 'Block In Progress -> Closed' ((SetStatus $tech $id 5 $assignee).Content.Contains('Status cannot move from In Progress to Closed'))
Check 'Resolve again' ((StatusOf (SetStatus $tech $id 4 $assignee)) -eq 'Resolved')
Check 'Close resolved ticket' ((StatusOf (SetStatus $tech $id 5 $assignee)) -eq 'Closed')
Check 'Block Closed -> In Progress' ((SetStatus $tech $id 3 $assignee).Content.Contains('Closed is final'))
$r=Request $tech "ticket.php?id=$id"
Check 'History records status names' ($r.Content.Contains('status changed: Resolved &rarr; Closed'))
# A closed ticket has no reply form, so take the session's form token from another page.
$p=Request $employee 'ticket-create.php'
$r=Request $employee "ticket.php?id=$id" @{csrf_token=(Token $p);id=$id;note='Is this still broken?'}
Check 'Closed ticket rejects replies' ($r.Content.Contains('This ticket is closed. Create a new ticket') -and -not $r.Content.Contains('Is this still broken?</p>'))

$p=Request $admin 'ticket-create.php'
$r=Request $admin 'ticket-create.php' @{csrf_token=(Token $p);title="QA access $tag";description='Administrator-owned isolation test.';category_id=1}
$other=[regex]::Match($r.Content,'Ticket #(\d+):').Groups[1].Value
Check 'Employee denied another owner ticket' ((Request $employee "ticket.php?id=$other").StatusCode -eq 403)
$r=SetStatus $admin $other 5 $assignee
Check 'Administrator also blocked from Open -> Closed' ($r.Content.Contains('Status cannot move from Open to Closed'))

# --- Due dates, past due, bulk update, list export (CR-10) ---
$yesterday=(Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
$p=Request $admin "ticket-edit.php?id=$other"
$openId=[regex]::Match($p.Content,'<option value="(\d+)" data-name="Open"').Groups[1].Value
$r=Request $admin "ticket-edit.php?id=$other" @{csrf_token=(Token $p);status_id=$openId;priority_id=2;assigned_to='';due_date='not-a-date';note='';resolution=''}
Check 'Invalid due date rejected' ($r.Content.Contains('Enter the due date as a valid date'))
$r=Request $admin "ticket-edit.php?id=$other" @{csrf_token=(Token $p);status_id=$openId;priority_id=2;assigned_to='';due_date=$yesterday;note='';resolution=''}
Check 'Due date saved and shown as past due' ([regex]::IsMatch($r.Content,'data-field="due-date">[^<]*<span class="sla sla-overdue">Past due'))
Check 'Staff edit records first response' (-not $r.Content.Contains('Waiting for IT'))
Check 'Past due view lists the ticket' ((Request $admin 'tickets.php?view=pastdue').Content.Contains("id=$other`">#$other</a>"))
Check 'History records the due date change' ($r.Content.Contains('due date changed: None &rarr; ' + $yesterday))
$p=Request $admin 'tickets.php'
Check 'Ticket list offers bulk update to staff' ($p.Content.Contains('id="bulk-form"') -and $p.Content.Contains('name="ids[]" value="' + $other + '"'))
Check 'Employees get no bulk update' (-not (Request $employee 'tickets.php').Content.Contains('bulk-form'))
$r=Request $admin 'tickets-bulk.php' @{csrf_token=(Token $p);'ids[]'=$other;assigned_to=$assignee;priority_id=3;return=''}
$t=Request $admin "ticket.php?id=$other"
Check 'Bulk assign and priority applied' ($r.Content.Contains('Updated 1 ticket') -and $t.Content.Contains("QA technician $tag") -and [regex]::IsMatch($t.Content,'data-field="priority">[^
]*High'))
$r=Invoke-WebRequest ($base+'tickets-bulk.php') -WebSession $admin -UseBasicParsing -Method Post -Body ("csrf_token=" + [uri]::EscapeDataString((Token $p)) + "&ids%5B%5D=$id&ids%5B%5D=$other&assigned_to=none&priority_id=&return=")
Check 'Bulk unassign skips tickets that are not Open' ($r.Content.Contains('Updated 1 ticket') -and $r.Content.Contains("Skipped #$id"))
Check 'Bulk update blocked for employees' ((Request $employee 'tickets-bulk.php' @{csrf_token='x';'ids[]'=$id;priority_id=1}).StatusCode -eq 403)
$r=Request $admin 'tickets.php?export=csv'
Check 'Ticket list exports to CSV' ($r.ContentType -like 'text/csv*' -and $r.Content.Contains('Response time') -and $r.Content.Contains('Close time') -and $r.Content -match "(?m)^$other,")
Check 'Employee CSV export only has own tickets' (-not ((Request $employee 'tickets.php?export=csv').Content -match "(?m)^$other,"))

# --- User editing ---
$usersPage=(Request $admin 'users.php').Content
$employeeId=[regex]::Match($usersPage,"qa-employee-$tag@example\.test</td>.*?user-edit\.php\?id=(\d+)",'Singleline').Groups[1].Value
Check 'Users page links to Edit' ($employeeId -ne '')
$p=Request $admin "user-edit.php?id=$employeeId"
$r=Request $admin "user-edit.php?id=$employeeId" @{csrf_token=(Token $p);id=$employeeId;name="QA employee renamed $tag";email="qa-employee-$tag@example.test";role='employee';organization_id=$orgId;password=''}
Check 'Edit user name' ($r.Content.Contains("QA employee renamed $tag"))
$newPassword=[guid]::NewGuid().ToString()+'!Bb8'
$p=Request $admin "user-edit.php?id=$employeeId"
$null=Request $admin "user-edit.php?id=$employeeId" @{csrf_token=(Token $p);id=$employeeId;name="QA employee renamed $tag";email="qa-employee-$tag@example.test";role='employee';organization_id=$orgId;password=$newPassword}
Check 'Password reset signs out existing sessions' ((Request $employee 'tickets.php').Content.Contains('<h1>Sign in</h1>'))
$employee=SignIn "qa-employee-$tag@example.test" $newPassword
Check 'Reset user password' ((Request $employee 'tickets.php').Content.Contains("QA employee renamed $tag"))
$adminId=[regex]::Match($usersPage,"jordan@cts4911\.local</td>.*?user-edit\.php\?id=(\d+)",'Singleline').Groups[1].Value
$p=Request $admin "user-edit.php?id=$adminId"
$r=Request $admin "user-edit.php?id=$adminId" @{csrf_token=(Token $p);id=$adminId;name='Jordan Black';email='jordan@cts4911.local';role='employee';password=''}
Check 'Administrator cannot demote self' ($r.Content.Contains('You cannot change your own role'))

# --- Reports and Phase 4 analytics ---
$r=Request $admin 'reports.php'
Check 'Reports page loads with all 8 reports' ($r.StatusCode -eq 200 -and (1..8 | ForEach-Object {
    $r.Content.Contains("<span class=`"report-number`">$_</span>") }) -notcontains $false)
# Backdate the open admin ticket past its 24h Medium target so it becomes overdue.
sqlcmd -S $SqlServer -E -C -b -d TicketManagement -Q "UPDATE tickets SET created_at = DATEADD(hour, -30, SYSDATETIME()) WHERE id = $other" | Out-Null
$r=Request $admin 'reports.php'
Check 'Overdue ticket listed by due time' ([regex]::IsMatch($r.Content,"ticket\.php\?id=$other`">#$other</a>.*?sla-overdue",'Singleline'))
Check 'Technician ticket list flags overdue' ([regex]::IsMatch((Request $tech 'tickets.php').Content,"id=$other`">#$other</a>(?:(?!</tr>).)*sla-overdue",'Singleline'))
Check 'Ticket page shows SLA due' ((Request $tech "ticket.php?id=$other").Content.Contains('data-field="sla"'))
Check 'Employee view hides SLA details' (-not (Request $employee "ticket.php?id=$id").Content.Contains('data-field="sla"'))

# --- Dashboard, search, and filters ---
$r=Request $tech 'dashboard.php'
Check 'Staff dashboard shows SLA tiles and queue' ($r.Content.Contains('Overdue') -and $r.Content.Contains('My queue') -and [regex]::IsMatch($r.Content,"id=$other`">#$other</a>(?:(?!</tr>).)*sla-overdue",'Singleline'))
Check 'Employee dashboard shows own tickets' ((Request $employee 'dashboard.php').Content.Contains("QA workflow $tag"))
Check 'Home page opens the dashboard' ((Request $tech 'index.php').Content.Contains('Welcome back'))
$r=Request $tech ("tickets.php?q=" + [uri]::EscapeDataString("QA workflow $tag"))
Check 'Search by title' ($r.Content.Contains("id=$id`">#$id</a>") -and -not $r.Content.Contains("id=$other`">#$other</a>"))
Check 'Search by ticket number' ((Request $tech "tickets.php?q=%23$other").Content.Contains("id=$other`">#$other</a>"))
Check 'Search with no match' ((Request $tech 'tickets.php?q=zz-no-such-ticket-zz').Content.Contains('0 tickets match'))
Check 'Search treats % literally' ((Request $tech 'tickets.php?q=%25%25%25').Content.Contains('0 tickets match'))
$r=Request $tech 'tickets.php?view=overdue'
Check 'Overdue view' ($r.Content.Contains("id=$other`">#$other</a>") -and -not $r.Content.Contains("id=$id`">#$id</a>"))
$r=Request $tech 'tickets.php?view=closed'
Check 'Closed view' ($r.Content.Contains("id=$id`">#$id</a>") -and -not $r.Content.Contains("id=$other`">#$other</a>"))
Check 'Unassigned view' ((Request $tech 'tickets.php?view=unassigned').Content.Contains("id=$other`">#$other</a>"))
Check 'Employee search stays within own tickets' (-not (Request $employee ("tickets.php?q=" + [uri]::EscapeDataString("QA access $tag"))).Content.Contains("id=$other`">#$other</a>"))
Check 'Bad filter values are ignored' ((Request $tech "tickets.php?status=abc&priority=-1&page=999&sort=drop").StatusCode -eq 200)
$r=Request $admin 'reports.php?from=2020-01-01&to=2020-01-31'
Check 'Date range filter applied' ($r.Content.Contains('Jan 1, 2020') -and $r.Content.Contains('<strong>0</strong><span>Created</span>'))
Check 'Invalid date falls back safely' ((Request $admin 'reports.php?from=not-a-date').Content.Contains('Dates must look like'))
$range='from='+(Get-Date).AddDays(-2).ToString('yyyy-MM-dd')+'&to='+(Get-Date -Format yyyy-MM-dd)
$r=Request $admin "reports.php?$range&export=tickets"
Check 'Ticket CSV export downloads' ($r.ContentType -like 'text/csv*' -and $r.Content.Contains('SLA target (h)'))
Check 'CSV shows SLA results' ($r.Content -match "(?m)^$id,.*,`"?On time`"?\r?$" -and $r.Content -match "(?m)^$other,.*,Overdue\r?$")
$p=Request $employee 'ticket-create.php'
$null=Request $employee 'ticket-create.php' @{csrf_token=(Token $p);title="=HYPERLINK(`"http://example.test`") $tag";description='CSV formula injection test.';category_id=1}
$r=Request $admin "reports.php?$range&export=tickets"
Check 'CSV neutralizes spreadsheet formulas' ($r.Content.Contains("'=HYPERLINK") -and -not ($r.Content -match '(?m),"?=HYPERLINK'))
Check 'Report CSV export (SLA)' ((Request $admin "reports.php?$range&export=sla").Content.Contains('Met SLA'))
Check 'Unknown export rejected' ((Request $admin 'reports.php?export=nope').StatusCode -eq 404)
Check 'Employee blocked from CSV export' ((Request $employee 'reports.php?export=tickets').StatusCode -eq 403)
Check 'Technician blocked from CSV export' ((Request $tech 'reports.php?export=tickets').StatusCode -eq 403)

# --- Settings ---
$category="QA category $tag"
$p=Request $admin 'settings.php'
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='category_add';name=$category}
Check 'Add category' ($r.Content.Contains($category))
Check 'New category offered on ticket form' ((Request $employee 'ticket-create.php').Content.Contains($category))
$categoryId=[regex]::Match($r.Content,"name=`"id`" value=`"(\d+)`">\s*<input name=`"name`" maxlength=`"80`" value=`"$category`"").Groups[1].Value
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='category_add';name=$category}
Check 'Duplicate category rejected' ($r.Content.Contains('already exists'))
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='category_toggle';id=$categoryId}
Check 'Deactivated category hidden from ticket form' ($categoryId -ne '' -and -not (Request $employee 'ticket-create.php').Content.Contains($category))
$p=Request $admin 'settings.php?tab=priorities'
$lowMatch=[regex]::Match($p.Content,'name="id" value="(\d+)">\s*<input name="name" maxlength="20" value="Low"[^>]*>\s*<input name="sla_hours"[^>]*value="(\d+)"')
$lowId=$lowMatch.Groups[1].Value; $lowSla=$lowMatch.Groups[2].Value
$null=Request $admin 'settings.php' @{csrf_token=(Token $p);action='priority_update';id=$lowId;name="Low QA";sla_hours=48}
$r=Request $admin 'settings.php?tab=priorities'
Check 'Rename priority and change SLA target' ($r.Content.Contains('value="Low QA"') -and [regex]::IsMatch($r.Content,'value="Low QA"[^>]*>\s*<input name="sla_hours"[^>]*value="48"'))
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='priority_update';id=$lowId;name='Low QA';sla_hours=0}
Check 'Invalid SLA target rejected' ($r.Content.Contains('SLA target must be a whole number'))
$null=Request $admin 'settings.php' @{csrf_token=(Token $p);action='priority_update';id=$lowId;name='Low';sla_hours=$lowSla}
Check 'Priority name and SLA restored' ([regex]::IsMatch((Request $admin 'settings.php?tab=priorities').Content,"value=`"Low`"[^>]*>\s*<input name=`"sla_hours`"[^>]*value=`"$lowSla`""))
Check 'Statuses tab shows workflow' ((Request $admin 'settings.php?tab=statuses').Content.Contains('Assigned'))
$r=Request $admin 'settings.php?tab=audit'
Check 'Audit log records admin actions' ($r.Content.Contains('user created') -and $r.Content.Contains('user edited') -and $r.Content.Contains('category added') -and $r.Content.Contains('priority renamed') -and $r.Content.Contains('priority SLA changed'))
Check 'Audit log never shows passwords' (-not $r.Content.Contains($newPassword) -and -not $r.Content.Contains($password))

# --- Sign-in lockout and tunnel HTTPS ---
$techEmail="qa-technician-$tag@example.test"
foreach($n in 1..5){
    $s=New-Object Microsoft.PowerShell.Commands.WebRequestSession; $p=Request $s 'login.php'
    $null=Request $s 'login.php' @{csrf_token=(Token $p);email=$techEmail;password="wrong-password-$n"}
}
$s=New-Object Microsoft.PowerShell.Commands.WebRequestSession; $p=Request $s 'login.php'
$r=Request $s 'login.php' @{csrf_token=(Token $p);email=$techEmail;password=$password}
Check 'Account locked after 5 wrong passwords' ($r.Content.Contains('Too many failed sign-in attempts') -and -not (Request $s 'tickets.php').Content.Contains('All Tickets'))
Check 'Lockout does not block other accounts' ((Request (SignIn "qa-employee-$tag@example.test" $newPassword) 'tickets.php').Content.Contains('My Tickets'))
$cookie=(Invoke-WebRequest ($base+'login.php') -UseBasicParsing -Headers @{'X-Forwarded-Proto'='https'}).Headers['Set-Cookie']
Check 'Session cookie is Secure behind the HTTPS tunnel' ($cookie -match '(?i);\s*secure')
$cookie=(Invoke-WebRequest ($base+'login.php') -UseBasicParsing).Headers['Set-Cookie']
Check 'Session cookie still works over local HTTP' ($cookie -and $cookie -notmatch '(?i);\s*secure')

# --- Knowledge base (CR-10) ---
$p=Request $tech 'kb-edit.php'
$r=Request $tech 'kb-edit.php' @{csrf_token=(Token $p);title="QA KB reset Wi-Fi $tag";body="Step 1: forget the network. Step 2: reconnect with your campus login.";category_id=1;published=1}
$kbId=[regex]::Match($r.Content,'kb-edit\.php\?id=(\d+)').Groups[1].Value
Check 'Technician publishes an article' ($r.Content.Contains('Article published') -and $kbId -ne '')
$p=Request $tech 'kb-edit.php'
$r=Request $tech 'kb-edit.php' @{csrf_token=(Token $p);title="QA KB draft $tag";body="Internal notes that are not ready for requesters yet."}
$draftId=[regex]::Match($r.Content,'kb-edit\.php\?id=(\d+)').Groups[1].Value
Check 'Unticked article is saved as a draft' ($r.Content.Contains('Draft saved'))
$r=Request $tech 'kb-edit.php' @{csrf_token=(Token $p);title='';body='short'}
Check 'Invalid article rejected' ($r.Content.Contains('A title is required') -and $r.Content.Contains('between 20 and 20,000'))
$r=Request $employee ('kb.php?q=' + [uri]::EscapeDataString("QA KB reset Wi-Fi $tag"))
Check 'Employee finds published article by search' ($r.Content.Contains("kb-article.php?id=$kbId"))
Check 'Employee cannot see drafts' (-not (Request $employee ('kb.php?q=' + [uri]::EscapeDataString("QA KB draft $tag"))).Content.Contains("kb-article.php?id=$draftId") -and (Request $employee "kb-article.php?id=$draftId").StatusCode -eq 404)
Check 'Staff can see drafts' ((Request $tech "kb-article.php?id=$draftId").StatusCode -eq 200)
Check 'Employee cannot write articles' ((Request $employee 'kb-edit.php').StatusCode -eq 403)
$r=Request $employee "kb-article.php?id=$kbId"
Check 'Article view counts and escapes' ($r.StatusCode -eq 200 -and $r.Content.Contains('views'))
Check 'Create ticket from article pre-fills what was tried' ((Request $employee "ticket-create.php?kb=$kbId").Content.Contains("QA KB reset Wi-Fi $tag"))

# --- CR-11: sorting, live refresh, split view, organizations, advanced search ---
$r=Request $admin 'tickets.php?sort=title&dir=asc'
$posAccess=$r.Content.IndexOf("id=$other`">#$other</a>"); $posWorkflow=$r.Content.IndexOf("id=$id`">#$id</a>")
Check 'Sort by subject A-Z' ($posAccess -ge 0 -and $posWorkflow -gt $posAccess -and $r.Content.Contains('aria-sort="ascending"'))
$r=Request $admin 'tickets.php?sort=title&dir=desc'
Check 'Sort by subject Z-A' ($r.Content.IndexOf("id=$id`">#$id</a>") -lt $r.Content.IndexOf("id=$other`">#$other</a>") -and $r.Content.Contains('aria-sort="descending"'))
Check 'Column headers link to sorting' ($r.Content.Contains('data-sort-key="status"') -and $r.Content.Contains('data-sort-key="due"'))
Check 'Employees cannot sort by staff-only columns' (-not (Request $employee 'tickets.php').Content.Contains('data-sort-key="requester"'))
Check 'Unknown sort direction ignored' ((Request $admin 'tickets.php?sort=title&dir=sideways').StatusCode -eq 200)

$s1=(Request $admin 'tickets.php?format=stamp').Content | ConvertFrom-Json
Check 'Auto-refresh stamp endpoint returns JSON' ($s1.stamp -match '^[0-9a-f]{32}$')
$p=Request $admin "ticket.php?id=$other"
$null=Request $admin "ticket.php?id=$other" @{csrf_token=(Token $p);id=$other;note="Stamp change $tag"}
$s2=(Request $admin 'tickets.php?format=stamp').Content | ConvertFrom-Json
Check 'Stamp changes when a ticket changes' ($s2.stamp -ne $s1.stamp)
Check 'Refresh button and auto-refresh toggle shown' ((Request $admin 'tickets.php').Content.Contains('data-refresh') -and (Request $admin 'tickets.php').Content.Contains('data-auto-refresh'))

$r=Request $admin 'tickets.php?layout=split'
Check 'Split view renders the ticket pane' ($r.Content.Contains('data-split-pane') -and $r.Content.Contains('List view'))
$r=Request $admin "ticket.php?id=$id&partial=1"
Check 'Ticket preview is a fragment without the page layout' ($r.StatusCode -eq 200 -and $r.Content.Contains('Open full page') -and -not $r.Content.Contains('class="sidebar"') -and -not $r.Content.Contains('<html'))
Check 'Preview still enforces ticket access' ((Request $employee "ticket.php?id=$other&partial=1").StatusCode -eq 403)
$p=Request $admin "ticket.php?id=$other"
$r=Request $admin "ticket.php?id=$other" @{csrf_token=(Token $p);id=$other;note="Split reply $tag";back="tickets.php?layout=split&selected=$other"}
Check 'Reply from split view returns to the list' ($r.Content.Contains('All Tickets') -and $r.Content.Contains('data-split-pane'))
$r=Request $admin "ticket.php?id=$other" @{csrf_token=(Token $p);id=$other;note="Evil back $tag";back='https://evil.example/phish'}
Check 'Reply ignores off-site return links' ($r.Content.Contains("Ticket #${other}:"))

$r=Request $admin "tickets.php?organization=$orgId"
Check 'Filter tickets by organization' ($r.Content.Contains("id=$id`">#$id</a>") -and -not $r.Content.Contains("id=$other`">#$other</a>"))
Check 'Organization shown on the ticket' ((Request $admin "ticket.php?id=$id").Content.Contains("QA Org $tag"))
Check 'Organization tab counts members' ([regex]::IsMatch((Request $admin 'settings.php?tab=organizations').Content,"value=`"QA Org $tag`".*?<td class=`"num`">2</td>",'Singleline'))

$r=Request $admin ('search.php?q=' + [uri]::EscapeDataString("Technician reply $tag"))
Check 'Advanced search skips notes unless asked' (-not $r.Content.Contains("id=$id`">#$id</a>"))
$r=Request $admin ('search.php?in_notes=1&q=' + [uri]::EscapeDataString("Technician reply $tag"))
Check 'Advanced search finds text in replies' ($r.Content.Contains("id=$id`">#$id</a>") -and $r.Content.Contains('1 ticket match'))
$closedId=[regex]::Match($r.Content,'name="status\[\]" value="(\d+)"\s*> Closed').Groups[1].Value
$r=Request $admin ("search.php?status%5B%5D=$closedId&organization=$orgId")
Check 'Search by status and organization' ($r.Content.Contains("id=$id`">#$id</a>") -and -not $r.Content.Contains("id=$other`">#$other</a>"))
$today=Get-Date -Format yyyy-MM-dd
Check 'Search by created date range' ((Request $admin "search.php?created_from=$today&created_to=$today").Content.Contains("id=$id`">#$id</a>") -and (Request $admin 'search.php?created_from=2020-01-01&created_to=2020-01-31').Content.Contains('0 tickets match'))
Check 'Search by due date and SLA' ((Request $admin 'search.php?has_due=1&sla=Overdue').Content.Contains("id=$other`">#$other</a>"))
Check 'Invalid search dates are ignored' ((Request $admin 'search.php?created_from=2026-02-30&updated_to=nope').StatusCode -eq 200)
Check 'Employee search is limited to own tickets' (-not (Request $employee ('search.php?q=' + [uri]::EscapeDataString("QA access $tag"))).Content.Contains("id=$other`">#$other</a>"))
$r=Request $admin "search.php?organization=$orgId&export=csv"
Check 'Search results export to CSV with organization' ($r.ContentType -like 'text/csv*' -and $r.Content.Contains("QA Org $tag"))

# --- Front end assets and deactivation ---
$r=Request $anon 'assets/app.js'
Check 'JavaScript is served' ($r.StatusCode -eq 200 -and $r.Content.Contains('updateSlaBadges'))
Check 'Deactivate asks for confirmation' ((Request $admin 'users.php').Content.Contains('data-confirm="Deactivate'))
$techId=[regex]::Match((Request $admin 'users.php').Content,"qa-technician-$tag@example\.test</td>.*?name=`"user_id`" value=`"(\d+)`"",'Singleline').Groups[1].Value
$p=Request $admin 'users.php'
$null=Request $admin 'users.php' @{csrf_token=(Token $p);action='toggle';user_id=$techId}
Check 'Deactivation signs the user out immediately' ((Request $tech 'tickets.php').Content.Contains('<h1>Sign in</h1>'))

# --- Sign out ---
$null=Request $employee 'logout.php'
Check 'Logout denies subsequent access' ((Request $employee 'tickets.php').Content.Contains('<h1>Sign in</h1>'))
Write-Output "QA fixture suffix: $tag. Tickets: $id, $other."
RemoveTestData $tag
Write-Output "$(if($script:failures){"$($script:failures) check(s) FAILED"}else{'All checks passed'})"
exit $script:failures
