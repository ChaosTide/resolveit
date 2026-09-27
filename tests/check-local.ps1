param([Parameter(Mandatory=$true)][string]$AdminPassword)
$ErrorActionPreference='Stop'
$base='http://127.0.0.1:8080/'
function Request($session,$path,$body=$null) {
    if($null -eq $body){return Invoke-WebRequest ($base+$path) -WebSession $session -SkipHttpErrorCheck}
    return Invoke-WebRequest ($base+$path) -WebSession $session -Method Post -Body $body -SkipHttpErrorCheck
}
function Token($response){return [regex]::Match($response.Content,'name="csrf_token" value="([^"]+)"').Groups[1].Value}
function Check($label,$pass){ Write-Output "$(if($pass){'PASS'}else{'FAIL'}): $label" }
function SignIn($email,$password){
    $s=New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $p=Request $s 'login.php'
    $r=Request $s 'login.php' @{csrf_token=(Token $p);email=$email;password=$password}
    return $s
}
$admin=SignIn 'jordan@cts4911.local' $AdminPassword
Check 'Administrator login' ((Request $admin 'tickets.php').Content.Contains('Jordan Black'))
$anon=New-Object Microsoft.PowerShell.Commands.WebRequestSession
Check 'Anonymous protected page redirects to login' ((Request $anon 'tickets.php').Content.Contains('<h1>Sign in</h1>'))
$p=Request $anon 'login.php'
$r=Request $anon 'login.php' @{csrf_token=(Token $p);email='jordan@cts4911.local';password='incorrect-password'}
Check 'Wrong password rejected' ($r.Content.Contains('incorrect'))
$tag=Get-Date -Format yyyyMMddHHmmss
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
Check 'Employee blocked from users' ((Request $employee 'users.php').StatusCode -eq 403)
Check 'Technician blocked from users' ((Request $tech 'users.php').StatusCode -eq 403)
$p=Request $employee 'ticket-create.php'
$r=Request $employee 'ticket-create.php' @{csrf_token='invalid';title='QA';description='A testing description';category_id=1}
Check 'CSRF rejection' ($r.StatusCode -eq 419)
$r=Request $employee 'ticket-create.php' @{csrf_token=(Token $p);title='';description='short';category_id=1}
Check 'Invalid ticket rejected' ($r.Content.Contains('Title is required'))
$r=Request $employee 'ticket-create.php' @{csrf_token=(Token $p);title="QA workflow $tag";description='QA test description <script>alert(1)</script>';category_id=1}
$id=[regex]::Match($r.Content,'Ticket #(\d+):').Groups[1].Value
Check 'Create and view ticket' ($id -ne '')
Check 'Script text escaped' ($r.Content.Contains('&lt;script&gt;'))
if(-not $id){ Write-Output $r.Content; exit 1 }
Check 'Employee cannot manage ticket' ((Request $employee "ticket-edit.php?id=$id").StatusCode -eq 403)
$p=Request $tech "ticket-edit.php?id=$id"
$assignee=[regex]::Match($p.Content,'<option value="(\d+)"[^>]*>QA technician').Groups[1].Value
foreach($status in 2,3,4,5){
    $p=Request $tech "ticket-edit.php?id=$id"
    $r=Request $tech "ticket-edit.php?id=$id" @{csrf_token=(Token $p);status_id=$status;priority_id=2;assigned_to=$assignee;note="QA transition $status";resolution='QA issue resolved for testing.'}
    $expected=@{2='Assigned';3='In Progress';4='Resolved';5='Closed'}[$status]
    Check "Workflow $expected" ($r.Content.Contains("<br>$expected</div>"))
}
$p=Request $admin 'ticket-create.php'
$r=Request $admin 'ticket-create.php' @{csrf_token=(Token $p);title="QA access $tag";description='Administrator-owned isolation test.';category_id=1}
$other=[regex]::Match($r.Content,'Ticket #(\d+):').Groups[1].Value
Check 'Employee denied another owner ticket' ((Request $employee "ticket.php?id=$other").StatusCode -eq 403)
$p=Request $tech "ticket-edit.php?id=$other"
$r=Request $tech "ticket-edit.php?id=$other" @{csrf_token=(Token $p);status_id=5;priority_id=2;assigned_to=$assignee;resolution='QA invalid jump test resolution.';note='QA attempts Open directly to Closed'}
Check 'Disallow Open directly to Closed' (-not $r.Content.Contains('<br>Closed</div>'))
$null=Request $employee 'logout.php'
Check 'Logout denies subsequent access' ((Request $employee 'tickets.php').Content.Contains('<h1>Sign in</h1>'))
Write-Output "QA fixture suffix: $tag. Tickets: $id, $other. Test accounts and tickets retained as evidence."
