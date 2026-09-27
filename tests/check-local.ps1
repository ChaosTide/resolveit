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
function StatusOf($response){ return [regex]::Match($response.Content,'<strong>Status</strong><br>([^<]+)</div>').Groups[1].Value }
# Removes only rows carrying this run's timestamp tag. Admin audit log rows are kept on purpose.
function RemoveTestData($tag){
    if($KeepData){ Write-Output "Kept QA data tagged $tag."; return }
    $sql="SET NOCOUNT ON; SET XACT_ABORT ON; BEGIN TRAN;
DELETE FROM tickets WHERE title LIKE N'QA % $tag' OR requester_id IN (SELECT id FROM users WHERE email LIKE N'qa-%-$tag@example.test');
DELETE FROM users WHERE email LIKE N'qa-%-$tag@example.test';
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
foreach($role in @('employee','technician')){
    $email="qa-$role-$tag@example.test"
    $p=Request $admin 'users.php'
    $r=Request $admin 'users.php' @{csrf_token=(Token $p);action='create';name="QA $role $tag";email=$email;password=$password;role=$role}
    Check "Create $role account" ($r.Content.Contains($email))
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
Check 'History records status names' ($r.Content.Contains('status changed -&gt; Closed') -or $r.Content.Contains('status changed -> Closed'))

$p=Request $admin 'ticket-create.php'
$r=Request $admin 'ticket-create.php' @{csrf_token=(Token $p);title="QA access $tag";description='Administrator-owned isolation test.';category_id=1}
$other=[regex]::Match($r.Content,'Ticket #(\d+):').Groups[1].Value
Check 'Employee denied another owner ticket' ((Request $employee "ticket.php?id=$other").StatusCode -eq 403)
$r=SetStatus $admin $other 5 $assignee
Check 'Administrator also blocked from Open -> Closed' ($r.Content.Contains('Status cannot move from Open to Closed'))

# --- User editing ---
$usersPage=(Request $admin 'users.php').Content
$employeeId=[regex]::Match($usersPage,"QA employee $tag</td>.*?user-edit\.php\?id=(\d+)",'Singleline').Groups[1].Value
Check 'Users page links to Edit' ($employeeId -ne '')
$p=Request $admin "user-edit.php?id=$employeeId"
$r=Request $admin "user-edit.php?id=$employeeId" @{csrf_token=(Token $p);id=$employeeId;name="QA employee renamed $tag";email="qa-employee-$tag@example.test";role='employee';password=''}
Check 'Edit user name' ($r.Content.Contains("QA employee renamed $tag"))
$newPassword=[guid]::NewGuid().ToString()+'!Bb8'
$p=Request $admin "user-edit.php?id=$employeeId"
$null=Request $admin "user-edit.php?id=$employeeId" @{csrf_token=(Token $p);id=$employeeId;name="QA employee renamed $tag";email="qa-employee-$tag@example.test";role='employee';password=$newPassword}
Check 'Reset user password' ((Request (SignIn "qa-employee-$tag@example.test" $newPassword) 'tickets.php').Content.Contains("QA employee renamed $tag"))
$adminId=[regex]::Match($usersPage,"Jordan Black</td>.*?user-edit\.php\?id=(\d+)",'Singleline').Groups[1].Value
$p=Request $admin "user-edit.php?id=$adminId"
$r=Request $admin "user-edit.php?id=$adminId" @{csrf_token=(Token $p);id=$adminId;name='Jordan Black';email='jordan@cts4911.local';role='employee';password=''}
Check 'Administrator cannot demote self' ($r.Content.Contains('You cannot change your own role'))

# --- Reports and Phase 4 analytics ---
$r=Request $admin 'reports.php'
Check 'Reports page loads with all 8 reports' ($r.StatusCode -eq 200 -and (1..8 | ForEach-Object { $r.Content.Contains("<h2>$_.") }) -notcontains $false)
# Backdate the open admin ticket past its 24h Medium target so it becomes overdue.
sqlcmd -S $SqlServer -E -C -b -d TicketManagement -Q "UPDATE tickets SET created_at = DATEADD(hour, -30, SYSDATETIME()) WHERE id = $other" | Out-Null
$r=Request $admin 'reports.php'
Check 'Overdue ticket listed by due time' ([regex]::IsMatch($r.Content,"ticket\.php\?id=$other`">#$other</a>.*?sla-overdue",'Singleline'))
Check 'Technician ticket list flags overdue' ([regex]::IsMatch((Request $tech 'tickets.php').Content,"id=$other`">#$other</a>(?:(?!</tr>).)*sla-overdue",'Singleline'))
Check 'Ticket page shows SLA due' ((Request $tech "ticket.php?id=$other").Content.Contains('<strong>SLA due</strong>'))
Check 'Employee view hides SLA details' (-not (Request $employee "ticket.php?id=$id").Content.Contains('SLA due'))
$r=Request $admin 'reports.php?from=2020-01-01&to=2020-01-31'
Check 'Date range filter applied' ($r.Content.Contains('Jan 1, 2020') -and $r.Content.Contains('Created in period</span><strong>0<'))
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
$categoryId=[regex]::Match($r.Content,"name=`"id`" value=`"(\d+)`"><input name=`"name`" maxlength=`"80`" value=`"$category`"").Groups[1].Value
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='category_add';name=$category}
Check 'Duplicate category rejected' ($r.Content.Contains('already exists'))
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='category_toggle';id=$categoryId}
Check 'Deactivated category hidden from ticket form' ($categoryId -ne '' -and -not (Request $employee 'ticket-create.php').Content.Contains($category))
$p=Request $admin 'settings.php?tab=priorities'
$lowMatch=[regex]::Match($p.Content,'name="id" value="(\d+)"><input name="name" maxlength="20" value="Low"[^>]*><input name="sla_hours"[^>]*value="(\d+)"')
$lowId=$lowMatch.Groups[1].Value; $lowSla=$lowMatch.Groups[2].Value
$null=Request $admin 'settings.php' @{csrf_token=(Token $p);action='priority_update';id=$lowId;name="Low QA";sla_hours=48}
$r=Request $admin 'settings.php?tab=priorities'
Check 'Rename priority and change SLA target' ($r.Content.Contains('value="Low QA"') -and [regex]::IsMatch($r.Content,'value="Low QA"[^>]*><input name="sla_hours"[^>]*value="48"'))
$r=Request $admin 'settings.php' @{csrf_token=(Token $p);action='priority_update';id=$lowId;name='Low QA';sla_hours=0}
Check 'Invalid SLA target rejected' ($r.Content.Contains('SLA target must be a whole number'))
$null=Request $admin 'settings.php' @{csrf_token=(Token $p);action='priority_update';id=$lowId;name='Low';sla_hours=$lowSla}
Check 'Priority name and SLA restored' ([regex]::IsMatch((Request $admin 'settings.php?tab=priorities').Content,"value=`"Low`"[^>]*><input name=`"sla_hours`"[^>]*value=`"$lowSla`""))
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

# --- Sign out ---
$null=Request $employee 'logout.php'
Check 'Logout denies subsequent access' ((Request $employee 'tickets.php').Content.Contains('<h1>Sign in</h1>'))
Write-Output "QA fixture suffix: $tag. Tickets: $id, $other."
RemoveTestData $tag
Write-Output "$(if($script:failures){"$($script:failures) check(s) FAILED"}else{'All checks passed'})"
exit $script:failures
